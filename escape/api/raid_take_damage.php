<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/_lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

const DEBUG = 0;

$user_key = trim((string)($_POST['user_key'] ?? ''));
$raw = (int)($_POST['raw_damage'] ?? 0);

if ($user_key === '') fail('user_key required', 400);
if ($raw <= 0) $raw = 1;

try {
  $pdo->beginTransaction();

  $rs = raid_state_lock($pdo, $user_key);
  if (!$rs) { $pdo->rollBack(); fail('no_raid_state', 404); }
  if ((string)$rs['status'] !== 'in_raid') { $pdo->rollBack(); fail('not_in_raid', 409); }

  // NOTE: 확정 스키마에서는 armor 관련 컬럼이 없으므로,
  // 현재는 raw_damage를 그대로 적용하는 단순 경로로 유지한다.
  $final = $raw;
  $armorBroken = false;
  $armorJ = [];
  $armorVal = 0;

  $pdo->commit();
  echo j([
    'ok'=>true,
    'raw_damage'=>$raw,
    'final_damage'=>$final,
    'armor_applied'=>0,
    'armor_broken'=>$armorBroken ? 1 : 0,
    'armor'=> $armorBroken ? null : ( ($armorVal>0) ? $armorJ : null ),
  ]);
  exit;

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  http_response_code(500);
  echo DEBUG ? j(['ok'=>false,'error'=>'server_error','msg'=>$e->getMessage()]) : j(['ok'=>false,'error'=>'server_error']);
  exit;
}
