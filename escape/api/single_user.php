<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

try {
  $user_key = trim((string)($_POST['user_key'] ?? ''));
  $name = trim((string)($_POST['name'] ?? 'Player'));
  if ($name === '') $name = 'Player';
  $name = mb_substr($name, 0, 20);

  // 1) user_key가 있으면 DB에 존재하는지 확인 후 반환/갱신
  if ($user_key !== '') {
    $st = $pdo->prepare("SELECT user_key, name FROM escape_users WHERE user_key=? LIMIT 1");
    $st->execute([$user_key]);
    $row = $st->fetch();

    if ($row) {
      // 이름이 들어왔고 기존 이름이 기본값일 때만 업데이트 (원하면 정책 변경 가능)
      if ($name !== '' && $name !== 'Player' && $row['name'] !== $name) {
        $up = $pdo->prepare("UPDATE escape_users SET name=? WHERE user_key=?");
        $up->execute([$name, $user_key]);
        $row['name'] = $name;
      }

      echo j(['ok' => true, 'user_key' => $row['user_key'], 'name' => $row['name'], 'mode' => 'load']);
      exit;
    }

    // user_key는 있는데 DB에 없으면 신규로 만들어줌(복구 성격)
    $st = $pdo->prepare("INSERT INTO escape_users (user_key, name) VALUES (?, ?)");
    $st->execute([$user_key, $name]);
    echo j(['ok' => true, 'user_key' => $user_key, 'name' => $name, 'mode' => 'recreate']);
    exit;
  }

  // 2) user_key가 없으면 새로 생성
  $new_key = 'u_' . bin2hex(random_bytes(8));
  $st = $pdo->prepare("INSERT INTO escape_users (user_key, name) VALUES (?, ?)");
  $st->execute([$new_key, $name]);

  echo j(['ok' => true, 'user_key' => $new_key, 'name' => $name, 'mode' => 'create']);
  exit;

} catch (Throwable $e) {
  http_response_code(500);
  echo j(['ok' => false, 'error' => 'server_error']);
  exit;
}
