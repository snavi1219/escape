<?php
// /escape/api/npc_kill_drop.php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/raid_core.php';

$user_key = require_user_key();

// ✅ npc_type 우선, 없으면 npc_kind도 허용
$npc_type = trim((string)($_POST['npc_type'] ?? ''));
$npc_kind = trim((string)($_POST['npc_kind'] ?? ''));

if ($npc_type === '') $npc_type = $npc_kind;
if ($npc_type === '') $npc_type = 'zombie_shambler';

$rs = raid_state_require_in_raid($pdo, $user_key);

$bag = bag_decode($rs['inventory_json'] ?? null);
$throw = json_decode((string)($rs['throw_json'] ?? '[]'), true);
$brought = json_decode((string)($rs['brought_json'] ?? '{}'), true);
if (!is_array($throw)) $throw = [];
if (!is_array($brought)) $brought = [];

$drop = drop_generate($pdo, $npc_type);

// stack 반영
foreach ($drop['stack'] as $s) {
  $id = trim((string)($s['item_id'] ?? ''));
  $q  = (int)($s['qty'] ?? 0);
  if ($id !== '' && $q > 0) bag_stack_add($bag, $id, $q);
}

// inst 반영(아이템 템플릿 기반 생성)
$instMade = [];
foreach ($drop['inst'] as $it) {
  $id = trim((string)($it['item_id'] ?? ''));
  if ($id === '') continue;

  $item = item_get($pdo, $id);
  if (!$item) continue;

  $durMax = (int)($it['dur_max'] ?? 0);
  $ammoIn = isset($it['ammo_in_mag']) ? (int)$it['ammo_in_mag'] : 0;

  // ✅ fragile stick은 3회 파손 보장
  if ($id === 'melee_fragile_stick' && $durMax <= 0) $durMax = 3;

  $iid = create_instance_from_item($pdo, $user_key, $item, [
    'durability_max'=>$durMax,
    'ammo_in_mag'=>$ammoIn
  ]);

  bag_inst_add($bag, $iid);
  $instMade[] = ['instance_id'=>$iid,'item_id'=>$id];
}

raid_state_upsert($pdo, $user_key, 'in_raid', $bag, $throw, $brought);

json_out([
  'ok'=>true,
  'npc_type'=>$npc_type,
  'npc_profile'=>$drop['npc'],
  'drop'=>['stack'=>$drop['stack'],'inst'=>$drop['inst']],
  'inst_created'=>$instMade,
  'inventory'=>$bag
]);
