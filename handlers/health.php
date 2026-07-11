<?php

declare(strict_types=1);

defined('BASED') || exit;

function handle_health(array $params): void
{
    Response::json([
        'status' => 'ok',
        // Major.minor only. The exact patch version is a fingerprinting signal on
        // an unauthenticated endpoint, and expose_php=0 (.user.ini) exists to
        // prevent exactly that. major.minor still answers "is PHP 8.x live?".
        'php' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
    ]);
}
