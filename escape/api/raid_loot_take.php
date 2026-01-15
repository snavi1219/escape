<?php
// /escape/api/raid_loot_take.php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/raid_core.php';

$user_key = require_user_key();
$action   = trim((string)($_POST['action'] ?? 'take')); // take | skip
if (!in_array($action, ['take','skip'], true)) json_out(['ok'=>false,'error'=>'bad_action'], 400);

$rs = raid_state_require_in_raid($pdo, $user_key);

$bag = bag_decode($rs['inventory_json'] ?? null);

// ✅ throw_json은 기본적으로 object 형태가 안전 (기존 프로젝트 흐름과 호환)
$throw = json_decode((string)($rs['throw_json'] ?? '{}'), true);
$brought = json_decode((string)($rs['brought_json'] ?? '{}'), true);

if (!is_array($throw)) $throw = [];
if (!is_array($brought)) $brought = [];

if (empty($brought['encounter']) || !is_array($brought['encounter'])) {
  json_out(['ok'=>false,'error'=>'no_encounter'], 409);
}

$enc = $brought['encounter'];

// ✅ 살아있는 적이면 루팅 불가(데이터 꼬임 방지)
if (empty($enc['dead']) && (int)($enc['hp'] ?? 0) > 0) {
  json_out(['ok'=>false,'error'=>'encounter_not_dead'], 409);
}

$loot = $enc['loot'] ?? null;
if (!is_array($loot)) {
  // loot 없으면 조우 종료만
  unset($brought['encounter']);
  raid_state_upsert($pdo, $user_key, 'in_raid', $bag, $throw, $brought);
  json_out(['ok'=>true,'action'=>$action,'ended'=>1,'note'=>'no_loot']);
}


// ✅ loot 호환: UI 표준 loot.stacks(map) → 기존 loot.stack(list)로 변환
if (
  (!isset($loot['stack']) || !is_array($loot['stack']) || count($loot['stack']) === 0)
  && isset($loot['stacks']) && is_array($loot['stacks'])
) {
  $tmp = [];
  foreach ($loot['stacks'] as $iid => $qq) {
    $iid = trim((string)$iid);
    $qq  = (int)$qq;
    if ($iid === '' || $qq <= 0) continue;
    $tmp[] = ['item_id'=>$iid, 'qty'=>$qq];
  }
  $loot['stack'] = $tmp;
}


$added_stack = [];
$added_inst  = [];

if ($action === 'take') {
  // 1) stack 반영
  $stack = $loot['stack'] ?? [];
  if (is_array($stack)) {
    foreach ($stack as $s) {
      if (!is_array($s)) continue;
      $id = trim((string)($s['item_id'] ?? ''));
      $q  = (int)($s['qty'] ?? 0);
      if ($id === '' || $q <= 0) continue;

      bag_stack_add($bag, $id, $q);
      $added_stack[] = ['item_id'=>$id,'qty'=>$q];
    }
  }

  // 2) inst 반영: (drop_generate 형식이면 inst에 dur_max/ammo_in_mag 등이 들어올 수 있음)
  $inst = $loot['inst'] ?? [];
  $instMade = [];

  if (is_array($inst)) {
    foreach ($inst as $it) {
      if (!is_array($it)) continue;

      $id = trim((string)($it['item_id'] ?? ''));
      if ($id === '') continue;

      $item = item_get($pdo, $id);
      if (!$item) continue;

      $durMax = (int)($it['dur_max'] ?? 0);
      $ammoIn = isset($it['ammo_in_mag']) ? (int)$it['ammo_in_mag'] : 0;

      // fragile stick은 3회 파손 보장 룰 (기존 npc_kill_drop.php 동일)
      if ($id === 'melee_fragile_stick' && $durMax <= 0) $durMax = 3;

      // ✅ instance_id는 응답/가방에 안정적으로 문자열로 유지
      $iid = (string)create_instance_from_item($pdo, $user_key, $item, [
        'durability_max'=>$durMax,
        'ammo_in_mag'=>$ammoIn
      ]);

      bag_inst_add($bag, $iid);
      $instMade[] = ['instance_id'=>$iid,'item_id'=>$id];
    }
  }

  if ($instMade) {
    foreach ($instMade as $m) {
      $added_inst[] = $m;
    }
  }
}

// encounter 종료(loot 포함 제거)
unset($brought['encounter']);
raid_state_upsert($pdo, $user_key, 'in_raid', $bag, $throw, $brought);

json_out([
  'ok'=>true,
  'action'=>$action,
  'ended'=>1,
  'added'=>[
    'stack'=>$added_stack,
    'inst'=>$added_inst
  ],
  'inventory'=>$bag
]);
