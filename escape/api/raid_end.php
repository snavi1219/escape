<?php
// /escape/api/raid_end.php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/raid_core.php';

$user_key = require_user_key();
$result = trim((string)($_POST['result'] ?? ''));
if (!in_array($result, ['extract','death'], true)) json_out(['ok'=>false,'error'=>'invalid_result'], 400);

$rs = raid_state_require_in_raid($pdo, $user_key);
$bag = bag_decode($rs['inventory_json'] ?? null);

$brought = json_decode((string)($rs['brought_json'] ?? '{}'), true);
if (!is_array($brought)) $brought = [];

if ($result === 'extract') {
  foreach ($bag['stack'] as $row) {
    $item_id = trim((string)($row['item_id'] ?? ''));
    $qty = (int)($row['qty'] ?? 0);
    if ($item_id !== '' && $qty > 0) stash_stack_add($pdo, $user_key, $item_id, $qty);
  }
  foreach ($bag['inst'] as $row) {
    $iid = trim((string)($row['instance_id'] ?? ''));
    if ($iid === '') continue;
    $inst = instance_get_owned($pdo, $user_key, $iid);
    if ($inst) stash_instance_add($pdo, $user_key, $iid);
  }
}

raid_state_upsert($pdo, $user_key, 'idle', bag_default(), [], $brought);
json_out(['ok'=>true,'ended'=>$result,'status'=>'idle']);
