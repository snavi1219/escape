<?php
// /escape/api/encounter_apply_player_damage.php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/raid_core.php';

$user_key = require_user_key();
$damage = (int)($_POST['damage'] ?? 0);
if ($damage < 0) $damage = 0;

$rs = raid_state_require_in_raid($pdo, $user_key);

$brought = json_decode((string)($rs['brought_json'] ?? '{}'), true);
if (!is_array($brought)) $brought = [];

$enc = $brought['encounter'] ?? null;
if (!is_array($enc) || empty($enc['npc_type'])) {
  json_out(['ok'=>false,'error'=>'no_encounter'], 409);
}

$hp = (int)($enc['hp'] ?? 0);
$hp2 = $hp - $damage;
if ($hp2 < 0) $hp2 = 0;
$enc['hp'] = $hp2;

$killed = ($hp2 <= 0);

$brought['encounter'] = $enc;

// 유지
$bag = bag_decode($rs['inventory_json'] ?? null);
$throw = json_decode((string)($rs['throw_json'] ?? '[]'), true);
if (!is_array($throw)) $throw = [];

raid_state_upsert($pdo, $user_key, 'in_raid', $bag, $throw, $brought);

json_out(['ok'=>true,'encounter'=>$enc,'killed'=>$killed]);
