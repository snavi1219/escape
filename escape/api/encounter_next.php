<?php
// /escape/api/encounter_next.php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/raid_core.php';

$user_key = require_user_key();
$rs = raid_state_require_in_raid($pdo, $user_key);

$brought = json_decode((string)($rs['brought_json'] ?? '{}'), true);
if (!is_array($brought)) $brought = [];

$npc_type = trim((string)($_POST['npc_type'] ?? ''));
if ($npc_type === '') {
  // 기본 랜덤: 좀비 중심, 가끔 스캐브/PMC
  $roll = random_int(1, 100);
  if ($roll <= 70) $f = 'zombie';
  elseif ($roll <= 92) $f = 'scav';
  else $f = 'pmc';

  $cat = npc_catalog();
  $cands = [];
  foreach ($cat as $type => $meta) {
    if (($meta['faction'] ?? '') === $f) $cands[] = $type;
  }
  if (!$cands) $cands = array_keys($cat);
  $npc_type = $cands[array_rand($cands)];
}

$npc = npc_get($npc_type);
$enc = [
  'npc_type' => $npc['type'],
  'name'     => $npc['name'],
  'faction'  => $npc['faction'],
  'hp'       => (int)$npc['hp'],
  'hp_max'   => (int)$npc['hp'],
  'atk'      => (int)$npc['atk'],
  'aim'      => (int)$npc['aim'],
  'lootTier' => (int)$npc['lootTier'],
  'spawn_ts' => time(),
];

$brought['encounter'] = $enc;

// inventory/throw는 유지
$bag = bag_decode($rs['inventory_json'] ?? null);
$throw = json_decode((string)($rs['throw_json'] ?? '[]'), true);
if (!is_array($throw)) $throw = [];

raid_state_upsert($pdo, $user_key, 'in_raid', $bag, $throw, $brought);

json_out(['ok'=>true,'encounter'=>$enc]);
