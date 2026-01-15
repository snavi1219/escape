<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/_lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

const DEBUG = 0;

$user_key = trim((string)($_POST['user_key'] ?? ''));
$item_id  = trim((string)($_POST['item_id'] ?? ''));
$qty      = (int)($_POST['qty'] ?? 1);

if ($user_key === '') fail('user_key required', 400);
if ($item_id === '')  fail('item_id required', 400);
if ($qty <= 0) $qty = 1;

try {
  $pdo->beginTransaction();

  $rs = raid_state_lock($pdo, $user_key);
  if (!$rs) { $pdo->rollBack(); fail('no_raid_state', 404); }
  if ((string)$rs['status'] !== 'in_raid') { $pdo->rollBack(); fail('not_in_raid', 409); }

  $row = item_row($pdo, $item_id);
  if (!$row) { $pdo->rollBack(); fail('no_item', 404); }

  // 스택형이면 raid bag qty+
  if (is_stack_item($row)) {
    $inv = raid_inv_get($rs);
    $inv[$item_id] = (int)($inv[$item_id] ?? 0) + $qty;
    raid_inv_set($pdo, $user_key, $inv);

    $pdo->commit();
    echo j(['ok'=>true, 'mode'=>'stack', 'item_id'=>$item_id, 'qty'=>$qty, 'inventory'=>$inv]);
    exit;
  }

  // 인스턴스형이면 raid 인스턴스 생성 (qty만큼 생성 가능)
  if (!is_instance_item($row)) {
    $pdo->rollBack();
    fail('not_lootable', 400, ['item_id'=>$item_id]);
  }

  $created = [];
  for ($i=0; $i<$qty; $i++) {
    $id = inst_create($pdo, $user_key, $item_id, 'raid');
    $created[] = $id;
  }

  $pdo->commit();
  echo j(['ok'=>true, 'mode'=>'instance', 'item_id'=>$item_id, 'qty'=>$qty, 'instance_ids'=>$created]);
  exit;

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  http_response_code(500);
  echo DEBUG ? j(['ok'=>false,'error'=>'server_error','msg'=>$e->getMessage()]) : j(['ok'=>false,'error'=>'server_error']);
  exit;
}
