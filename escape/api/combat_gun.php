<?php
// /escape/api/combat_gun.php
// 목적: UI(app_ui_min.js)의 "총기 사격" 버튼이 호출하는 단일 엔드포인트
// - 탄약/내구도 차감
// - brought_json.encounter.hp 적용
// - 사망 확정 시 loot_state=pending + loot(표준: stacks) 보장

declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/raid_core.php';

/* ------------------------ helpers ------------------------ */
function ensure_encounter_loot_on_death(PDO $pdo, array &$brought, int $tier = 1): void {
  if (empty($brought['encounter']) || !is_array($brought['encounter'])) return;
  $e =& $brought['encounter'];

  $hp = (int)($e['hp'] ?? 0);
  $dead = (int)($e['dead'] ?? 0);
  if ($dead === 0 && $hp > 0) return;

  $e['hp'] = 0;
  $e['dead'] = 1;

  // 이미 pending + stacks가 있으면 유지
  $ls = (string)($e['loot_state'] ?? '');
  $loot = $e['loot'] ?? null;
  if ($ls === 'pending' && is_array($loot) && !empty($loot['stacks']) && is_array($loot['stacks'])) return;

  $tier = max(1, min(5, (int)$tier));

  $stacks = [];
  $stacks['thr_stone'] = random_int(1, 2);

  if (random_int(1, 100) <= (35 + $tier * 8)) {
    $stacks['ammo_9mm_t1'] = random_int(6, 10 + $tier * 3);
  }
  if (random_int(1, 100) <= 15) {
    $stacks['med_bandage_t1'] = 1;
  }

  $e['loot'] = ['stacks' => $stacks, 'stack' => [], 'inst' => []];
  $e['loot_state'] = 'pending';
}

function lock_raid_state(PDO $pdo, string $user_key): array {
  $st = $pdo->prepare("SELECT * FROM escape_raid_state WHERE user_key=? LIMIT 1 FOR UPDATE");
  $st->execute([$user_key]);
  $rs = $st->fetch(PDO::FETCH_ASSOC);
  if (!$rs) json_out(['ok'=>false,'error'=>'raid_state_not_found'], 404);
  if ((string)($rs['status'] ?? '') !== 'in_raid') json_out(['ok'=>false,'error'=>'not_in_raid'], 409);
  return $rs;
}

function find_gun_instance_id(PDO $pdo, string $user_key, array $bag): ?array {
  // 반환: ['instance'=>row, 'item'=>itemRow]
  $instList = $bag['inst'] ?? [];
  if (!is_array($instList)) $instList = [];

  foreach ($instList as $row) {
    if (!is_array($row)) continue;
    $iid = trim((string)($row['instance_id'] ?? ''));
    if ($iid === '') continue;
    $inst = instance_get_owned($pdo, $user_key, $iid);
    if (!$inst) continue;
    $item = item_get($pdo, (string)($inst['item_id'] ?? ''));
    if (!$item) continue;

    $type = (string)($item['type'] ?? '');
    $stats = item_stats($item);
    $looksGun = in_array($type, ['gun','pistol','rifle','smg'], true) || isset($stats['mag_size']) || isset($stats['ammo_type']) || isset($inst['mag_size']);
    if ($looksGun) return ['instance'=>$inst, 'item'=>$item];
  }
  return null;
}

/* ------------------------ main ------------------------ */
$user_key = require_user_key();
$gun_iid = trim((string)($_POST['gun_instance_id'] ?? '')); // optional (UI minimal은 안 보냄)
$ammo_item_id = trim((string)($_POST['ammo_item_id'] ?? '')); // optional

try {
  $pdo->beginTransaction();

  $rs = lock_raid_state($pdo, $user_key);

  $bag = bag_decode($rs['inventory_json'] ?? null);
  $throw = json_decode((string)($rs['throw_json'] ?? '{}'), true);
  if (!is_array($throw)) $throw = [];
  $brought = json_decode((string)($rs['brought_json'] ?? '{}'), true);
  if (!is_array($brought)) $brought = [];

  if (empty($brought['encounter']) || !is_array($brought['encounter'])) {
    $pdo->rollBack();
    json_out(['ok'=>false,'error'=>'no_encounter'], 409);
  }

  $enc =& $brought['encounter'];

  // 이미 죽은 상태면 loot pending만 보장하고 종료
  if (!empty($enc['dead']) || (int)($enc['hp'] ?? 0) <= 0) {
    $tier = (int)($brought['tier'] ?? ($enc['tier'] ?? 1));
    ensure_encounter_loot_on_death($pdo, $brought, $tier);

    raid_state_upsert($pdo, $user_key, 'in_raid', $bag, $throw, $brought);
    $pdo->commit();

    json_out(['ok'=>true,'log_lines'=>['already_dead','loot_pending'], 'encounter'=>$brought['encounter']]);
  }

  $log = [];

  // 총 인스턴스 확보
  $gun = null;
  $gunItem = null;

  if ($gun_iid !== '') {
    $gun = instance_get_owned($pdo, $user_key, $gun_iid);
    if (!$gun) { $pdo->rollBack(); json_out(['ok'=>false,'error'=>'gun_instance_not_found'], 404); }
    $gunItem = item_get($pdo, (string)($gun['item_id'] ?? ''));
    if (!$gunItem) { $pdo->rollBack(); json_out(['ok'=>false,'error'=>'gun_item_not_found'], 404); }
  } else {
    $pick = find_gun_instance_id($pdo, $user_key, $bag);
    if (!$pick) { $pdo->rollBack(); json_out(['ok'=>false,'error'=>'no_gun_in_raid_bag'], 409); }
    $gun = $pick['instance'];
    $gunItem = $pick['item'];
    $gun_iid = (string)($gun['instance_id'] ?? '');
  }

  if ((int)($gun['durability'] ?? 0) <= 0) { $pdo->rollBack(); json_out(['ok'=>false,'error'=>'gun_broken'], 409); }

  $ammoInMag = (int)($gun['ammo_in_mag'] ?? 0);
  $magSize = (int)($gun['mag_size'] ?? 0);
  if ($magSize <= 0) {
    $stats = item_stats($gunItem);
    $magSize = (int)($stats['mag_size'] ?? 30);
    if ($magSize <= 0) $magSize = 30;
  }

  if ($ammoInMag <= 0) { $pdo->rollBack(); json_out(['ok'=>false,'error'=>'no_ammo_in_mag'], 409); }

  // damage 계산
  $gunStats = item_stats($gunItem);
  $baseDamage = (int)($gunStats['dmg'] ?? 18);
  if ($baseDamage <= 0) $baseDamage = 18;

  $mul = 1.0;
  if ($ammo_item_id !== '') {
    $ammoItem = item_get($pdo, $ammo_item_id);
    if ($ammoItem && (string)($ammoItem['type'] ?? '') === 'ammo') {
      $a = item_stats($ammoItem);
      if (isset($a['dmg_mul'])) $mul = (float)$a['dmg_mul'];
    }
  }

  $damage = (int)round($baseDamage * $mul);
  if ($damage < 1) $damage = 1;

  // 발사: 탄 1 감소
  instance_set_ammo($pdo, $user_key, $gun_iid, max(0, $ammoInMag - 1));

  // 내구도 소폭 감소(옵션)
  if (random_int(1,100) <= 15) {
    $after = instance_damage($pdo, $user_key, $gun_iid, 1);
    if ((int)($after['durability'] ?? 0) <= 0) $log[] = 'gun_broken';
  }

  // 적에게 적용
  $hp0 = (int)($enc['hp'] ?? 0);
  $hp1 = max(0, $hp0 - $damage);
  $enc['hp'] = $hp1;
  $log[] = "GUN dmg={$damage}";
  $log[] = "enemy_hp {$hp0} -> {$hp1}";

  if ($hp1 <= 0) {
    $enc['dead'] = 1;
    $enc['ended_at'] = time();

    // 전투 경로 통일: 죽으면 무조건 pending
    $tier = (int)($brought['tier'] ?? ($enc['tier'] ?? 1));
    ensure_encounter_loot_on_death($pdo, $brought, $tier);

    // loot_state가 none으로 남는 케이스 방지
    $brought['encounter']['loot_state'] = 'pending';

    $log[] = 'enemy_dead';
    $log[] = 'loot_pending';
  }

  raid_state_upsert($pdo, $user_key, 'in_raid', $bag, $throw, $brought);
  $pdo->commit();

  json_out([
    'ok'=>true,
    'log_lines'=>$log,
    'attack'=>'gun',
    'damage'=>$damage,
    'ammo_mul'=>$mul,
    'gun'=>[
      'instance_id'=>$gun_iid,
      'item_id'=>(string)($gun['item_id'] ?? ''),
      'ammo_in_mag_before'=>$ammoInMag,
      'ammo_in_mag_after'=>max(0, $ammoInMag-1),
      'mag_size'=>$magSize,
      'ammo_type'=>$gun['ammo_type'] ?? null,
    ],
    'enemy_hp_before'=>$hp0,
    'enemy_hp'=>$hp1,
    'dead'=>($hp1<=0)?1:0,
    'encounter'=>$brought['encounter'],
  ]);

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  json_out(['ok'=>false,'error'=>'server_error','msg'=>$e->getMessage()], 500);
}
