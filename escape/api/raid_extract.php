<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/_lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

const DEBUG = 0;

$user_key = trim((string)($_POST['user_key'] ?? ''));
if ($user_key === '') fail('user_key required', 400);

try {
  $pdo->beginTransaction();

  $rs = raid_state_lock($pdo, $user_key);
  if (!$rs) { $pdo->rollBack(); fail('no_raid_state', 404); }
  if ((string)$rs['status'] !== 'in_raid') { $pdo->rollBack(); fail('not_in_raid', 409); }

  // 스택형 raid bag -> stash
  $inv = raid_inv_get($rs);
  foreach ($inv as $item_id => $qty) {
    $qty = (int)$qty;
    if ($qty <= 0) continue;
    stash_add($pdo, $user_key, (string)$item_id, $qty);
  }

  // 인스턴스 raid -> stash(무기/방어구)
  $pdo->prepare("UPDATE escape_user_item_instances SET location='stash' WHERE user_key=? AND location='raid'")
      ->execute([$user_key]);

  // raid_state 초기화
  $emptyThrow = ['thr_stone'=>0,'thr_ied'=>0,'thr_grenade'=>0];
  $emptyBrought = ['primary'=>null,'secondary'=>null,'melee'=>null,'armor'=>null];

  $pdo->prepare("
    UPDATE escape_raid_state
    SET status='idle',
        inventory_json='{}',
        throw_json=?,
        brought_json=?,
        armor_json='{}'
    WHERE user_key=?
  ")->execute([json_str($emptyThrow), json_str($emptyBrought), $user_key]);

  // armor_instance_id는 유지해도 되지만, 안전하게 NULL
  $pdo->prepare("UPDATE escape_user_loadout SET armor_instance_id=NULL WHERE user_key=?")->execute([$user_key]);

  $pdo->commit();
  echo j(['ok'=>true,'result'=>'extracted']);
  exit;

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  http_response_code(500);
  echo DEBUG ? j(['ok'=>false,'error'=>'server_error','msg'=>$e->getMessage()]) : j(['ok'=>false,'error'=>'server_error']);
  exit;
}
