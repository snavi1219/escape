<?php
// /escape/api/turn_action.php (전체 교체본 - room_id varchar(16) 기준 / last_event 업데이트)
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
  $user_key = trim((string)($_POST['user_key'] ?? ''));
  $action   = trim((string)($_POST['action'] ?? ''));
  if ($user_key === '' || $action === '') jexit(['ok'=>0,'error'=>'bad_request'], 400);

  // user -> room_id
  $st = $pdo->prepare("SELECT room_id FROM escape_users WHERE user_key=? LIMIT 1");
  $st->execute([$user_key]);
  $u = $st->fetch(PDO::FETCH_ASSOC);
  if (!$u) jexit(['ok'=>0,'error'=>'user_not_found'], 404);

  $room_id = (string)($u['room_id'] ?? '');
  if ($room_id === '') jexit(['ok'=>0,'error'=>'not_in_room'], 400);

  $pdo->beginTransaction();

  // lock 1 row
  $st = $pdo->prepare("
    SELECT room_id, status, player_a, player_b, turn_player, turn_no, a_state, b_state
    FROM escape_rooms
    WHERE room_id=?
    LIMIT 1
    FOR UPDATE
  ");
  $st->execute([$room_id]);
  $room = $st->fetch(PDO::FETCH_ASSOC);
  if (!$room) { $pdo->rollBack(); jexit(['ok'=>0,'error'=>'room_not_found'], 404); }

  if (($room['status'] ?? 'playing') !== 'playing') {
    $pdo->rollBack();
    jexit(['ok'=>0,'error'=>'room_not_playing'], 400);
  }

  $turn_no = (int)($room['turn_no'] ?? 1);
  $turn_player = (string)($room['turn_player'] ?? '');

  // turn validate
  if ($turn_player !== '' && $turn_player !== $user_key) {
    $pdo->rollBack();
    jexit(['ok'=>0,'error'=>'not_your_turn'], 400);
  }

  // decode states (기존 로직이 있으면 여기서 처리)
  $a_state = [];
  $b_state = [];
  if (!empty($room['a_state'])) $a_state = json_decode((string)$room['a_state'], true) ?: [];
  if (!empty($room['b_state'])) $b_state = json_decode((string)$room['b_state'], true) ?: [];

  // TODO: 실제 전투 처리로직 연결
  $eventText = "[TURN {$turn_no}] {$user_key} action={$action}";

  // next turn (A<->B)
  $player_a = (string)($room['player_a'] ?? '');
  $player_b = (string)($room['player_b'] ?? '');
  $next_turn_player = $turn_player;

  if ($player_a !== '' && $player_b !== '') {
    $next_turn_player = ($user_key === $player_a) ? $player_b : $player_a;
  }

  // one UPDATE
  $st = $pdo->prepare("
    UPDATE escape_rooms
    SET
      a_state=?,
      b_state=?,
      turn_no=?,
      turn_player=?,
      last_event=?,
      updated_at=NOW()
    WHERE room_id=?
    LIMIT 1
  ");
  $st->execute([
    json_encode($a_state, JSON_UNESCAPED_UNICODE),
    json_encode($b_state, JSON_UNESCAPED_UNICODE),
    $turn_no + 1,
    $next_turn_player,
    $eventText,
    $room_id
  ]);

  $pdo->commit();

  jexit([
    'ok'=>1,
    'room_id'=>$room_id,
    'turn_no'=>$turn_no + 1,
    'turn_player'=>$next_turn_player,
    'event'=>$eventText,
  ]);

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  jexit(['ok'=>0,'error'=>'server_error'], 500);
}
