<?php
// /escape/api/combat_apply_hit.php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/raid_core.php';

$user_key = require_user_key();
$incoming = (int)($_POST['incoming_damage'] ?? 0);
if ($incoming <= 0) json_out(['ok'=>false,'error'=>'invalid_incoming_damage'], 400);

$armor_iid = trim((string)($_POST['armor_instance_id'] ?? ''));

raid_state_require_in_raid($pdo, $user_key);

$final = $incoming;
$armorUsed = null;

if ($armor_iid !== '') {
  $inst = instance_get_owned($pdo, $user_key, $armor_iid);
  if ($inst && (int)$inst['durability'] > 0) {
    $item = item_get($pdo, (string)$inst['item_id']);
    $stats = $item ? item_stats($item) : [];
    $def = (int)($stats['def'] ?? 0);

    $final = max(0, $incoming - $def);

    // 피해가 클수록 내구도 더 깎이게(기본 1~3)
    $delta = 1 + (int)floor($incoming / 15);
    if ($delta > 3) $delta = 3;

    $after = instance_damage($pdo, $user_key, $armor_iid, $delta);

    $armorUsed = [
      'instance_id'=>$armor_iid,
      'item_id'=>(string)$inst['item_id'],
      'def'=>$def,
      'durability_after'=>(int)$after['durability'],
      'durability_max'=>(int)$after['durability_max'],
      'broken'=>((int)$after['durability'] <= 0),
      'def_effective'=>(((int)$after['durability'] <= 0) ? 0 : $def),
      'durability_loss'=>$delta,
    ];
  } else {
    $armorUsed = ['instance_id'=>$armor_iid,'note'=>'not_found_or_broken_no_def'];
  }
}

json_out(['ok'=>true,'incoming_damage'=>$incoming,'final_damage'=>$final,'armor'=>$armorUsed]);
