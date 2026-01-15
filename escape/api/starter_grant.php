<?php
// /escape/api/starter_grant.php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/raid_core.php';

$user_key = require_user_key();

const ITEM_STONE = 'thr_stone';
const ITEM_STICK_BREAKABLE = 'melee_fragile_stick';

$T1_MELEE_ITEM_IDS = [
  'melee_rusty_knife',
  'melee_scrapknife'
];

$res = grant_starter_if_needed($pdo, $user_key, ITEM_STONE, ITEM_STICK_BREAKABLE, $T1_MELEE_ITEM_IDS);
json_out(['ok'=>true] + $res);
