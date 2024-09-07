<?php
  $host = 'localhost';
  $user = 'root';
  $password = 'password';
  $dbname = 'dbname';

  $dsn = 'mysql:host='. $host .';dbname='. $dbname;

  $pdo = new PDO($dsn, $user, $password);
  $pdo -> setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_OBJ);
  $pdo -> setAttribute(PDO::ATTR_EMULATE_PREPARES, false);


  # PDO QUERY
  $stmt = $pdo -> query('SELECT * FROM users');

  # Query Input
  $user_status = "verified";
  $is_active = true;
  $row_limit = 10;

  # PREPARED STATEMENTS (prepare & execute)
  $sql = 'SELECT * FROM users WHERE user_status = :user_status && is_active = :is_active LIMIT :row_limit';
  $stmt = $pdo -> prepare($sql);
  $stmt -> execute(['user_status' => $user_status, 'is_active' => $is_active, 'row_limit' => $row_limit]);
  $users = $stmt -> fetchAll();

  foreach($users as $user) {
    echo $user -> username . '<br>';
  }
?>