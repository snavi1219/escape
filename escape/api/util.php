<?php
declare(strict_types=1);

function j($v): string {
  return json_encode($v, JSON_UNESCAPED_UNICODE);
}

function require_user_key(): string {
  $uk = '';
  if (isset($_GET['user_key'])) $uk = trim((string)$_GET['user_key']);
  if (isset($_POST['user_key'])) $uk = trim((string)$_POST['user_key']);
  if ($uk === '') {
    http_response_code(400);
    echo j(['ok'=>false,'error'=>'user_key required']);
    exit;
  }
  if (!preg_match('/^[A-Za-z0-9_-]{8,64}$/', $uk)) {
    http_response_code(400);
    echo j(['ok'=>false,'error'=>'invalid user_key']);
    exit;
  }
  return $uk;
}

function require_item_id(string $key='item_id'): string {
  $id = '';
  if (isset($_GET[$key])) $id = trim((string)$_GET[$key]);
  if (isset($_POST[$key])) $id = trim((string)$_POST[$key]);
  if ($id === '') {
    http_response_code(400);
    echo j(['ok'=>false,'error'=>"$key required"]);
    exit;
  }
  if (!preg_match('/^[a-z0-9_]{3,32}$/', $id)) {
    http_response_code(400);
    echo j(['ok'=>false,'error'=>'invalid item_id']);
    exit;
  }
  return $id;
}

function int_param(string $key, int $default=0): int {
  $v = $default;
  if (isset($_GET[$key])) $v = (int)$_GET[$key];
  if (isset($_POST[$key])) $v = (int)$_POST[$key];
  return $v;
}

function ensure_user_exists(PDO $pdo, string $user_key, string $fallbackName='Player'): void {
  $st = $pdo->prepare("SELECT 1 FROM escape_users WHERE user_key=? LIMIT 1");
  $st->execute([$user_key]);
  if ($st->fetchColumn()) return;

  // 유저가 없으면 생성(싱글 자동 생성용)
  $name = $fallbackName;
  $st = $pdo->prepare("INSERT INTO escape_users (user_key, name) VALUES (?, ?)");
  $st->execute([$user_key, $name]);
}
