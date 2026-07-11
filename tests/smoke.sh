#!/bin/sh
# Usage:  sh tests/smoke.sh [base-url]
# Local:  sh tests/smoke.sh
# Host:   sh tests/smoke.sh https://yoursite.com
set -e

BASE="${1:-http://localhost:8080}"
FAIL=0

check() {
    desc="$1"; expected="$2"; actual="$3"
    if [ "$expected" = "$actual" ]; then
        printf '  \033[32m✓\033[0m %s\n' "$desc"
    else
        printf '  \033[31m✗\033[0m %s (expected %s, got %s)\n' "$desc" "$expected" "$actual"
        FAIL=1
    fi
}

status() { curl -s -o /dev/null -w '%{http_code}' "$@"; }

# A denied file is 403 on real Apache (<FilesMatch>) and 404 locally (the router
# 404s unknown paths). Accept either, or this checklist reports FAILURES on a
# correctly-secured production host.
check_denied() {
    desc="$1"; actual="$2"
    if [ "$actual" = "403" ] || [ "$actual" = "404" ]; then
        printf '  \033[32m✓\033[0m %s\n' "$desc"
    else
        printf '  \033[31m✗\033[0m %s (expected 403 or 404, got %s)\n' "$desc" "$actual"
        FAIL=1
    fi
}

echo "Smoke testing ${BASE}"

check "GET /health is 200 (the rewrite works)" 200 "$(status "${BASE}/health")"
check "unknown route is 404" 404 "$(status "${BASE}/nope")"
check "wrong method is 405" 405 "$(status -X POST "${BASE}/health")"

# The security checklist. On a drag-onto-FTP deploy with no CI, THIS is the test suite.
check_denied ".env is not served" "$(status "${BASE}/.env")"
check_denied "database.sql is not served" "$(status "${BASE}/database.sql")"
check_denied "lib/ source is not served" "$(status "${BASE}/lib/db.php")"

if curl -s "${BASE}/health" | grep -qi 'hash\|password'; then
    printf '  \033[31m✗\033[0m /health leaks a secret-looking field\n'
    FAIL=1
else
    printf '  \033[32m✓\033[0m /health leaks no secret-looking field\n'
fi

# Auth round-trip. Requires a configured DB and an imported schema.
if [ -n "$SMOKE_DB" ]; then
    EMAIL="smoke$(date +%s)@test.co"
    curl -s -X POST "${BASE}/auth/register" -H 'Content-Type: application/json' \
        -d "{\"name\":\"Smoke\",\"email\":\"${EMAIL}\",\"password\":\"password123\"}" > /dev/null

    TOKEN=$(curl -s -X POST "${BASE}/auth/login" -H 'Content-Type: application/json' \
        -d "{\"email\":\"${EMAIL}\",\"password\":\"password123\"}" \
        | sed -n 's/.*"token":"\([^"]*\)".*/\1/p')

    check "login returns a token" "yes" "$([ -n "$TOKEN" ] && echo yes || echo no)"
    check "a garbage token is 401" 401 \
        "$(status -H 'Authorization: Bearer garbage' "${BASE}/auth/me")"
    check "a real token reaches /auth/me (the Authorization header survived)" 200 \
        "$(status -H "Authorization: Bearer ${TOKEN}" "${BASE}/auth/me")"
    check "/users needs auth" 401 "$(status "${BASE}/users")"

    if curl -s -H "Authorization: Bearer ${TOKEN}" "${BASE}/users" | grep -qi 'password\|hash'; then
        printf '  \033[31m✗\033[0m /users LEAKS PASSWORD HASHES\n'
        FAIL=1
    else
        printf '  \033[32m✓\033[0m /users leaks no password hash\n'
    fi
fi

[ "$FAIL" -eq 0 ] && printf '\nsmoke: all passed\n' || { printf '\nsmoke: FAILURES\n'; exit 1; }
