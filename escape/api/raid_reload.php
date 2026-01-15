<?php
// /escape/api/raid_reload.php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/raid_core.php';

$user_key = require_user_key();
$gun_iid = trim((string)($_POST['gun_instance_id'] ?? ''));
$ammo_item_id = trim((string)($_POST['ammo_item_id'] ?? ''));
$qty = (int)($_POST['qty'] ?? 1);

if ($gun_iid === '' || $ammo_item_id === '') json_out(['ok'=>false,'error'=>'missing_params'], 400);
if ($qty <= 0) $qty = 1;

$rs = raid_state_require_in_raid($pdo, $user_key);

$bag = bag_decode($rs['inventory_json'] ?? null);
$throw = json_decode((string)($rs['throw_json'] ?? '[]'), true);
$brought = json_decode((string)($rs['brought_json'] ?? '{}'), true);
if (!is_array($throw)) $throw = [];
if (!is_array($brought)) $brought = [];

$gun = instance_get_owned($pdo, $user_key, $gun_iid);
if (!$gun) json_out(['ok'=>false,'error'=>'gun_instance_not_found'], 404);

$gunItem = item_get($pdo, (string)$gun['item_id']);
if (!$gunItem || (string)$gunItem['type'] !== 'gun') json_out(['ok'=>false,'error'=>'not_a_gun'], 409);

$ammoItem = item_get($pdo, $ammo_item_id);
if (!$ammoItem || (string)$ammoItem['type'] !== 'ammo') json_out(['ok'=>false,'error'=>'not_ammo_item'], 409);

$ammoStats = item_stats($ammoItem);
$ammoType = (string)($ammoStats['ammo_type'] ?? '');
if ($ammoType === '') json_out(['ok'=>false,'error'=>'ammo_missing_ammo_type'], 409);

$gunAmmoType = (string)($gun['ammo_type'] ?? '');
$magSize = (int)($gun['mag_size'] ?? 0);
if ($magSize <= 0) $magSize = 30;

if ($gunAmmoType === '' || $gunAmmoType !== $ammoType) {
  json_out(['ok'=>false,'error'=>'ammo_type_mismatch','gun_ammo_type'=>$gunAmmoType,'ammo_type'=>$ammoType], 409);
}

$have = bag_stack_get($bag, $ammo_item_id);
if ($have <= 0) json_out(['ok'=>false,'error'=>'no_ammo_in_raid_bag'], 409);

$curAmmo = (int)($gun['ammo_in_mag'] ?? 0);
$space = $magSize - $curAmmo;
if ($space <= 0) json_out(['ok'=>false,'error'=>'mag_full','ammo_in_mag'=>$curAmmo,'mag_size'=>$magSize], 409);

$want = min($qty, $have, $space);
if ($want <= 0) json_out(['ok'=>false,'error'=>'reload_zero'], 409);

if (!bag_stack_remove($bag, $ammo_item_id, $want)) json_out(['ok'=>false,'error'=>'ammo_remove_failed'], 409);

$newAmmo = $curAmmo + $want;
instance_set_ammo($pdo, $user_key, $gun_iid, $newAmmo);

raid_state_upsert($pdo, $user_key, 'in_raid', $bag, $throw, $brought);

json_out([
  'ok'=>true,
  'reloaded'=>[
    'gun_instance_id'=>$gun_iid,
    'ammo_item_id'=>$ammo_item_id,
    'loaded'=>$want,
    'ammo_in_mag_before'=>$curAmmo,
    'ammo_in_mag_after'=>$newAmmo,
    'mag_size'=>$magSize
  ],
  'inventory'=>$bag
]);
