<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';

$user_key = trim((string)($_POST['user_key'] ?? ''));
if ($user_key === '') { http_response_code(400); echo j(['error'=>'user_key required']); exit; }

$pdo->beginTransaction();

try {
  // 이미 방에 있는지 체크
  $st = $pdo->prepare("SELECT room_id FROM escape_rooms WHERE status='playing' AND (player_a=? OR player_b=?) LIMIT 1");
  $st->execute([$user_key, $user_key]);
  $row = $st->fetch();
  if ($row) {
    $pdo->commit();
    echo j(['ok'=>true, 'status'=>'already_in_room', 'room_id'=>$row['room_id']]);
    exit;
  }

  // 큐 등록(중복 방지)
  $st = $pdo->prepare("INSERT IGNORE INTO escape_queue (user_key) VALUES (?)");
  $st->execute([$user_key]);

  // 상대 한명 가져오기(자기 제외, 오래된 순)
  $st = $pdo->prepare("SELECT user_key FROM escape_queue WHERE user_key<>? ORDER BY queued_at ASC LIMIT 1 FOR UPDATE");
  $st->execute([$user_key]);
  $opp = $st->fetchColumn();

  if (!$opp) {
    $pdo->commit();
    echo j(['ok'=>true, 'status'=>'queued']);
    exit;
  }

  // 큐에서 둘 다 제거
  $st = $pdo->prepare("DELETE FROM escape_queue WHERE user_key IN (?, ?)");
  $st->execute([$user_key, $opp]);

  // 룸 생성
  $rid = room_id();

  // 기본 스탯
  $base = [
    'hpMax'=>60, 'hp'=>60, 'atk'=>10, 'def'=>3, 'crit'=>10,
    'potions'=>2, 'skillCd'=>0, 'guard'=>0
  ];

  // 선공 랜덤
  $turn = (random_int(0,1) === 0) ? $user_key : $opp;

  $st = $pdo->prepare("
    INSERT INTO escape_rooms
      (room_id, status, player_a, player_b, turn_player, turn_no, a_state, b_state, last_event)
    VALUES
      (?, 'playing', ?, ?, ?, 1, ?, ?, ?)
  ");
  $st->execute([
    $rid,
    $user_key,
    $opp,
    $turn,
    j($base),
    j($base),
    "매칭 완료. 선공이 정해졌습니다."
  ]);

  $pdo->commit();
  echo j(['ok'=>true, 'status'=>'matched', 'room_id'=>$rid]);

} catch (Throwable $e) {
  $pdo->rollBack();
  http_response_code(500);
  echo j(['error'=>'server_error']);
}
