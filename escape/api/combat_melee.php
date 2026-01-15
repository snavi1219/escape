<?php
// /escape/api/combat_melee.php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/raid_core.php';

$user_key = require_user_key();
$melee_iid = trim((string)($_POST['melee_instance_id'] ?? ''));

raid_state_require_in_raid($pdo, $user_key);

$damage = 3;
$used = ['kind'=>'barehand'];

if ($melee_iid !== '') {
  $inst = instance_get_owned($pdo, $user_key, $melee_iid);
  if ($inst && (int)$inst['durability'] > 0) {
    $item = item_get($pdo, (string)$inst['item_id']);
    $stats = $item ? item_stats($item) : [];
    $damage = (int)($stats['dmg'] ?? 7);

    $after = instance_damage($pdo, $user_key, $melee_iid, 1);

    $used = [
      'kind'=>'melee',
      'instance_id'=>$melee_iid,
      'item_id'=>(string)$inst['item_id'],
      'durability_after'=>(int)$after['durability'],
      'durability_max'=>(int)$after['durability_max'],
      'broken'=>((int)$after['durability'] <= 0),
    ];
  } else {
    $used = ['kind'=>'melee','instance_id'=>$melee_iid,'note'=>'not_found_or_broken_using_barehand'];
  }
}

json_out(['ok'=>true,'attack'=>'melee','damage'=>$damage,'used'=>$used]);
