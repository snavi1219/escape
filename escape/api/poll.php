<?php
// /escape/api/poll.php (전체 교체본 - escape_rooms.last_event 기반 초경량 폴링 + etag)
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/db.php';

function jexit(array $a, int $code=200): void {
  http_response_code($code);
  echo json_encode($a, JSON_UNESCAPED_UNICODE);
  exit;
}

try {
  $user_key = trim((string)($_GET['user_key'] ?? ''));
  if ($user_key === '') jexit(['ok'=>0,'error'=>'bad_request'], 400);

  $since_hash = trim((string)($_GET['since_hash'] ?? ''));

  // user -> room_id
  $st = $pdo->prepare("SELECT room_id FROM escape_users WHERE user_key=? LIMIT 1");
  $st->execute([$user_key]);
  $u = $st->fetch(PDO::FETCH_ASSOC);
  if (!$u) jexit(['ok'=>0,'error'=>'user_not_found'], 404);

  $room_id = (string)($u['room_id'] ?? '');
  if ($room_id === '') {
    jexit([
      'ok'=>1,'status'=>'idle',
      'room_id'=>null,'turn_no'=>null,'turn_player'=>null,
      'events'=>[],'etag'=>null,
    ]);
  }

  // room snapshot (NO LOCK)
  $st = $pdo->prepare("
    SELECT room_id, status, player_a, player_b, turn_player, turn_no, last_event
    FROM escape_rooms
    WHERE room_id=?
    LIMIT 1
  ");
  $st->execute([$room_id]);
  $room = $st->fetch(PDO::FETCH_ASSOC);

  if (!$room) {
    jexit([
      'ok'=>1,'status'=>'idle',
      'room_id'=>null,'turn_no'=>null,'turn_player'=>null,
      'events'=>[],'etag'=>null,
    ]);
  }

  $turn_no = (int)($room['turn_no'] ?? 1);
  $turn_player = (string)($room['turn_player'] ?? '');
  $last_event = (string)($room['last_event'] ?? '');

  // etag: "지금 상태를 대표하는 해시"
  $etag = sha1($room_id . '|' . $turn_no . '|' . $turn_player . '|' . $last_event);

  // 변경 없음
  if ($since_hash !== '' && hash_equals($since_hash, $etag)) {
    jexit([
      'ok'=>1,
      'status'=>$room['status'] ?? 'playing',
      'room_id'=>$room_id,
      'turn_no'=>$turn_no,
      'turn_player'=>$turn_player,
      'events'=>[],
      'etag'=>$etag,
    ]);
  }

  // 변경 있음: last_event 1줄만 내려줌
  $events = [];
  if ($last_event !== '') $events[] = ['text'=>$last_event];

  jexit([
    'ok'=>1,
    'status'=>$room['status'] ?? 'playing',
    'room_id'=>$room_id,
    'turn_no'=>$turn_no,
    'turn_player'=>$turn_player,
    'events'=>$events,
    'etag'=>$etag,
  ]);

} catch (Throwable $e) {
  jexit(['ok'=>0,'error'=>'server_error'], 500);
}
