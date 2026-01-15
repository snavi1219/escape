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

  // raid 인스턴스(장비/방어구/총) 전부 삭제
  $pdo->prepare("DELETE FROM escape_user_item_instances WHERE user_key=? AND location='raid'")
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

  // armor_instance_id 초기화
  $pdo->prepare("UPDATE escape_user_loadout SET armor_instance_id=NULL WHERE user_key=?")->execute([$user_key]);

  $pdo->commit();
  echo j(['ok'=>true,'result'=>'died']);
  exit;

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  http_response_code(500);
  echo DEBUG ? j(['ok'=>false,'error'=>'server_error','msg'=>$e->getMessage()]) : j(['ok'=>false,'error'=>'server_error']);
  exit;
}
