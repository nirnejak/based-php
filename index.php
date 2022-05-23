<?php

if ($_SERVER["REQUEST_METHOD"] === "GET") {
  echo "Cannot GET/";
} else {
  $http_response_header = array(
    "HTTP/1.1 404 Not Found",
    "Content-Type: text/json; charset=UTF-8",
    "Connection: close"
  );

  $user = array(
    "id" => "1",
    "name" => "Jitendra Nirnejak",
    "email" => "jeetnirnejak@gmail.com",
    "password" => "password",
  );

  echo json_encode($user);
}
