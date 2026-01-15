<?php
// /escape/api/raid_loot_add.php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/raid_core.php';

$user_key = require_user_key();
$item_id = trim((string)($_POST['item_id'] ?? ''));
$qty = (int)($_POST['qty'] ?? 1);

if ($item_id === '') json_out(['ok'=>false,'error'=>'invalid_item_id'], 400);
if ($qty <= 0) $qty = 1;

$rs = raid_state_require_in_raid($pdo, $user_key);

$bag = bag_decode($rs['inventory_json'] ?? null);
$throw = json_decode((string)($rs['throw_json'] ?? '[]'), true);
$brought = json_decode((string)($rs['brought_json'] ?? '{}'), true);
if (!is_array($throw)) $throw = [];
if (!is_array($brought)) $brought = [];

$item = item_get($pdo, $item_id);
if (!$item) json_out(['ok'=>false,'error'=>'item_not_found'], 404);

$t = item_type($item);
$stats = item_stats($item);

if (in_array($t, ['melee','gun','armor'], true)) {
  $durMax = (int)($_POST['durability_max'] ?? 0);
  $ammoIn = isset($_POST['ammo_in_mag']) ? (int)$_POST['ammo_in_mag'] : null;

  $iid = create_instance_from_item($pdo, $user_key, $item, [
    'durability_max'=>$durMax,
    'ammo_in_mag'=>($ammoIn === null ? 0 : $ammoIn),
  ]);

  bag_inst_add($bag, $iid);
  raid_state_upsert($pdo, $user_key, 'in_raid', $bag, $throw, $brought);
  json_out(['ok'=>true,'added'=>['kind'=>'inst','instance_id'=>$iid,'item_id'=>$item_id],'inventory'=>$bag]);
}

bag_stack_add($bag, $item_id, $qty);
raid_state_upsert($pdo, $user_key, 'in_raid', $bag, $throw, $brought);

json_out(['ok'=>true,'added'=>['kind'=>'stack','item_id'=>$item_id,'qty'=>$qty],'inventory'=>$bag]);
