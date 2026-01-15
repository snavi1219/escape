<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$DB_HOST = "localhost";
$DB_NAME = "afdev";
$DB_USER = "afdev";
$DB_PASS = "dpdlvprxm!@";

try {
  $pdo = new PDO(
    "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4",
    $DB_USER,
    $DB_PASS,
    [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES => false,
    ]
  );
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['error' => 'DB connection failed']);
  exit;
}

function j($v): string {
  return json_encode($v, JSON_UNESCAPED_UNICODE);
}

function room_id(): string {
  return substr(bin2hex(random_bytes(8)), 0, 10); // 10 chars
}
