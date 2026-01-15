<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/_lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

const DEBUG = 0;

$name = trim((string)($_POST['name'] ?? 'Player'));
if ($name === '') $name = 'Player';

try {
  $pdo->beginTransaction();

  // 유저 생성(간단)
  $user_key = 'u_' . bin2hex(random_bytes(8));

  // escape_users 구조는 기존대로 있다고 가정(이 부분은 기존 프로젝트에 맞춰 유지)
  $pdo->prepare("INSERT INTO escape_users(user_key, name) VALUES(?, ?)")
      ->execute([$user_key, $name]);

  // loadout ensure
  $pdo->prepare("INSERT IGNORE INTO escape_user_loadout(user_key) VALUES (?)")->execute([$user_key]);

  // raid_state ensure
  $emptyInv   = '{}';
  $emptyThrow = '{"thr_stone":0,"thr_ied":0,"thr_grenade":0}';
  $emptyBrought = '{"primary":null,"secondary":null,"melee":null,"armor":null}';

  $pdo->prepare("
    INSERT IGNORE INTO escape_raid_state(user_key, status, inventory_json, throw_json, brought_json, inv_instances_json, armor_json)
    VALUES (?, 'idle', ?, ?, ?, '{}', '{}')
  ")->execute([$user_key, $emptyInv, $emptyThrow, $emptyBrought]);

  // ===== 기본 지급(첫 생성 시) =====
  // 스택형: 돌맹이
  stash_add($pdo, $user_key, 'thr_stone', random_int(3, 6));

  // 내구도 인스턴스: 부서질듯한 막대기(내구도 3 고정)
  // stats_json에 durability_min/max=3 이므로 inst_create가 3 고정으로 뽑습니다.
  inst_create($pdo, $user_key, 'melee_fragile_stick', 'stash');

  // 최저 티어 근접 무기 랜덤 1개
  $tier1_pool = ['melee_rusty_knife', 'melee_bat'];
  $pick = $tier1_pool[random_int(0, count($tier1_pool)-1)];
  inst_create($pdo, $user_key, $pick, 'stash');

  // 방어구 T1 50% 확률
  if (random_int(1,100) <= 50) {
    inst_create($pdo, $user_key, 'armor_plate_t1', 'stash');
  }

  // 탄약 9mm T1 40% 확률 10~25발
  if (random_int(1,100) <= 40) {
    stash_add($pdo, $user_key, 'ammo_9mm_t1', random_int(10, 25));
  }

  $pdo->commit();

  echo j(['ok'=>true, 'user_key'=>$user_key, 'name'=>$name]);
  exit;

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  http_response_code(500);
  echo DEBUG ? j(['ok'=>false,'error'=>'server_error','msg'=>$e->getMessage()]) : j(['ok'=>false,'error'=>'server_error']);
  exit;
}
