<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/_lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

const DEBUG = 0;

$user_key = trim((string)($_POST['user_key'] ?? ''));
$slot = trim((string)($_POST['slot'] ?? ''));
$item_id = trim((string)($_POST['item_id'] ?? '')); // "" = 해제

if ($user_key === '') fail('user_key required', 400);
if (!in_array($slot, ['primary','secondary','melee','armor'], true)) fail('bad_slot', 400);

try {
  $pdo->beginTransaction();

  $rs = raid_state_lock($pdo, $user_key);
  if (!$rs) { $pdo->rollBack(); fail('no_raid_state', 404); }
  $status = (string)$rs['status'];

  $pdo->prepare("INSERT IGNORE INTO escape_user_loadout(user_key) VALUES (?)")->execute([$user_key]);
  $st = $pdo->prepare("
    SELECT primary_item, secondary_item, melee_item, armor_item, armor_instance_id
    FROM escape_user_loadout WHERE user_key=? LIMIT 1 FOR UPDATE
  ");
  $st->execute([$user_key]);
  $lo = $st->fetch(PDO::FETCH_ASSOC) ?: [
    'primary_item'=>null,'secondary_item'=>null,'melee_item'=>null,'armor_item'=>null,'armor_instance_id'=>null
  ];

  // 해제 처리
  if ($item_id === '') {
    if ($slot === 'armor') {
      // armor 해제: 현재 armor 인스턴스를 raid로 되돌림(레이드 중), idle이면 stash로 되돌림
      $curArmorInst = (int)($lo['armor_instance_id'] ?? 0);
      if ($curArmorInst > 0) {
        inst_move($pdo, $curArmorInst, ($status==='in_raid') ? 'raid' : 'stash');
      }
      $pdo->prepare("UPDATE escape_user_loadout SET armor_item=NULL, armor_instance_id=NULL WHERE user_key=?")
          ->execute([$user_key]);
      if ($status==='in_raid') raid_armor_set($pdo, $user_key, []);
    } else {
      $col = $slot . "_item";
      $pdo->prepare("UPDATE escape_user_loadout SET {$col}=NULL WHERE user_key=?")->execute([$user_key]);
    }
    $pdo->commit();
    echo j(['ok'=>true,'result'=>'unequipped','slot'=>$slot]);
    exit;
  }

  $row = item_row($pdo, $item_id);
  if (!$row) { $pdo->rollBack(); fail('no_item', 404); }

  $type = (string)$row['type'];

  // 슬롯-타입 검증
  $ok = false;
  if ($slot === 'melee' && $type === 'melee') $ok = true;
  if (($slot === 'primary' || $slot === 'secondary') && in_array($type, ['pistol','rifle'], true)) $ok = true;
  if ($slot === 'armor' && $type === 'armor') $ok = true;
  if (!$ok) { $pdo->rollBack(); fail('type_mismatch', 400, ['slot'=>$slot,'type'=>$type]); }

  // ===== status별 검증/차감(핵심) =====
  if ($status === 'in_raid') {
    // ✅ 레이드 중: RAID location 인스턴스만 장착 가능
    $inst = inst_pick($pdo, $user_key, $item_id, 'raid');
    if (!$inst) { $pdo->rollBack(); fail('raid_bag_not_enough', 409, ['item_id'=>$item_id]); }

    // 기존 장착 인스턴스(있으면) -> raid로 이동(스왑)
    if ($slot === 'armor') {
      $curArmorInst = (int)($lo['armor_instance_id'] ?? 0);
      if ($curArmorInst > 0) inst_move($pdo, $curArmorInst, 'raid');

      // 새 armor 장착: 해당 인스턴스를 raid에 그대로 두되 "장착됨"은 loadout에서 참조
      $pdo->prepare("UPDATE escape_user_loadout SET armor_item=?, armor_instance_id=? WHERE user_key=?")
          ->execute([$item_id, (int)$inst['id'], $user_key]);

      // armor_json 갱신(방어력/내구도)
      $stt = item_stats($row);
      $armorVal = (int)($stt['armor'] ?? 0);
      $tier = (int)($stt['tier'] ?? 1);
      raid_armor_set($pdo, $user_key, [
        'armor'=>$item_id,
        'tier'=>$tier,
        'armor_value'=>$armorVal,
        'durability'=>(int)$inst['durability'],
        'durability_max'=>(int)$inst['durability_max'],
        'instance_id'=>(int)$inst['id'],
      ]);
    } else {
      // 무기 슬롯
      $col = $slot . "_item";
      $cur = (string)($lo[$col] ?? '');
      if ($cur !== '') {
        // 현재 장착 아이템 인스턴스 1개를 raid로 되돌림(정확한 추적을 위해 item_id 기준으로 1개 선택)
        $curInst = inst_pick($pdo, $user_key, $cur, 'raid'); // 이미 raid에 있을 가능성 높음
        // 굳이 이동할 필요는 없지만, "장착→해제" 개념상 유지
      }
      $pdo->prepare("UPDATE escape_user_loadout SET {$col}=? WHERE user_key=?")->execute([$item_id, $user_key]);
    }

    $pdo->commit();
    echo j(['ok'=>true,'result'=>'equipped_in_raid','slot'=>$slot,'item_id'=>$item_id]);
    exit;
  }

  // idle: STASH에서 장착 가능
  if ($status !== 'idle') { $pdo->rollBack(); fail('bad_status', 409, ['status'=>$status]); }

  // 인스턴스형이면 stash 인스턴스 필요
  if (is_instance_item($row)) {
    $inst = inst_pick($pdo, $user_key, $item_id, 'stash');
    if (!$inst) { $pdo->rollBack(); fail('stash_not_enough_instance', 409, ['item_id'=>$item_id]); }

    // armor는 armor_instance_id 저장
    if ($slot === 'armor') {
      $pdo->prepare("UPDATE escape_user_loadout SET armor_item=?, armor_instance_id=? WHERE user_key=?")
          ->execute([$item_id, (int)$inst['id'], $user_key]);
    } else {
      $col = $slot . "_item";
      $pdo->prepare("UPDATE escape_user_loadout SET {$col}=? WHERE user_key=?")->execute([$item_id, $user_key]);
    }

    $pdo->commit();
    echo j(['ok'=>true,'result'=>'equipped_idle','slot'=>$slot,'item_id'=>$item_id]);
    exit;
  }

  // 스택형은 “장착” 대상으로 삼지 않음
  $pdo->rollBack();
  fail('not_equipable', 400, ['item_id'=>$item_id]);

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  http_response_code(500);
  echo DEBUG ? j(['ok'=>false,'error'=>'server_error','msg'=>$e->getMessage()]) : j(['ok'=>false,'error'=>'server_error']);
  exit;
}
