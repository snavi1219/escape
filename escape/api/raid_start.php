<?php
// /escape/api/raid_start.php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/raid_core.php';

$user_key = require_user_key();

$rs = raid_state_get($pdo, $user_key);
if ($rs && ($rs['status'] ?? '') === 'in_raid') json_out(['ok'=>false,'error'=>'already_in_raid'], 409);

$st = $pdo->prepare("SELECT * FROM escape_user_loadout WHERE user_key=? LIMIT 1");
$st->execute([$user_key]);
$loadout = $st->fetch(PDO::FETCH_ASSOC) ?: [];

$brought = [
  'primary_instance'   => $loadout['primary_instance']   ?? null,
  'secondary_instance' => $loadout['secondary_instance'] ?? null,
  'melee_instance'     => $loadout['melee_instance']     ?? null,
  'primary_item'       => (string)($loadout['primary_item'] ?? ''),
  'secondary_item'     => (string)($loadout['secondary_item'] ?? ''),
  'melee_item'         => (string)($loadout['melee_item'] ?? ''),
];

raid_state_upsert($pdo, $user_key, 'in_raid', bag_default(), [], $brought);
json_out(['ok'=>true,'status'=>'in_raid','inventory'=>bag_default(),'brought'=>$brought,'throw'=>[]]);
