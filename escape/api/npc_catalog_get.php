<?php
// /escape/api/npc_catalog_get.php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/raid_core.php';

// user_key 없어도 열어도 되지만, 통일성을 위해 받게 해둠(검증은 안 함)
$catalog = npc_catalog();
json_out(['ok'=>true,'npc_catalog'=>$catalog]);
