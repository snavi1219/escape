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

  // lock loadout
  $pdo->prepare("INSERT IGNORE INTO escape_user_loadout(user_key) VALUES (?)")->execute([$user_key]);

  $st = $pdo->prepare("SELECT armor_item, armor_instance_id FROM escape_user_loadout WHERE user_key=? LIMIT 1 FOR UPDATE");
  $st->execute([$user_key]);
  $lo = $st->fetch(PDO::FETCH_ASSOC) ?: ['armor_item'=>null,'armor_instance_id'=>null];

  $armorItem = (string)($lo['armor_item'] ?? '');
  $armorInstId = (int)($lo['armor_instance_id'] ?? 0);

  $armorJ = raid_armor_get($rs);
  $armorVal = (int)($armorJ['armor_value'] ?? 0);

  // 기본: 방어구 없으면 그대로
  $final = $raw;
  $armorBroken = false;

  if ($armorItem !== '' && $armorInstId > 0 && $armorVal > 0) {
    // 피해 감쇠: raw - armorVal (최소 1)
    $final = max(1, $raw - $armorVal);

    // 방어구 내구도 감소량: ceil(raw/2) 최소 1
    $dec = (int)ceil($raw / 2);
    $dec = max(1, $dec);

    // armor 인스턴스 내구도 차감
    $after = inst_dec_dur($pdo, $armorInstId, $dec);

    // armor_json 갱신(내구도)
    if (!empty($after['broken'])) {
      $armorBroken = true;
      // loadout에서 해제
      $pdo->prepare("UPDATE escape_user_loadout SET armor_item=NULL, armor_instance_id=NULL WHERE user_key=?")
          ->execute([$user_key]);
      raid_armor_set($pdo, $user_key, []);
    } else {
      $armorJ['durability'] = (int)$after['durability'];
      $armorJ['durability_max'] = (int)$after['durability_max'];
      raid_armor_set($pdo, $user_key, $armorJ);
    }
  }

  $pdo->commit();
  echo j([
    'ok'=>true,
    'raw_damage'=>$raw,
    'final_damage'=>$final,
    'armor_applied'=>($armorVal > 0 && $armorItem !== '' && $armorInstId > 0) ? 1 : 0,
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
