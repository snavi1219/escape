<?php
// /escape/api/raid_throw.php
// 목적: UI(app_ui_min.js)의 "투척" 버튼 경로를 단일화
// - raid bag(inventory_json)에서 투척템 차감
// - brought_json.encounter.hp 적용
// - 사망 확정 시 loot_state=pending + loot(표준: stacks) 보장
// - throw_json은 map({item_id:qty})로 저장/유지

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

  $e['loot'] = ['stacks' => $stacks];
  $e['loot_state'] = 'pending';
}

function normalize_throw_map($raw): array {
  if (!is_array($raw)) return [];
  $keys = array_keys($raw);
  $isList = ($keys === range(0, count($keys) - 1));

  $map = [];
  if ($isList) {
    foreach ($raw as $row) {
      if (!is_array($row)) continue;
      $iid = trim((string)($row['item_id'] ?? ''));
      $qq  = (int)($row['qty'] ?? 0);
      if ($iid === '' || $qq <= 0) continue;
      $map[$iid] = ($map[$iid] ?? 0) + $qq;
    }
    return $map;
  }

  foreach ($raw as $k => $v) {
    if ($k === 'items' || $k === 'list') continue;
    $iid = trim((string)$k);
    $qq  = (int)$v;
    if ($iid === '' || $qq <= 0) continue;
    $map[$iid] = ($map[$iid] ?? 0) + $qq;
  }

  return $map;
}

function throw_profile(string $item_id): array {
  // (필요 시 확장)
  switch ($item_id) {
    case 'thr_grenade':
      return ['miss'=>15, 'min'=>10, 'max'=>16, 'noise'=>3];
    case 'thr_ied':
      return ['miss'=>10, 'min'=>18, 'max'=>26, 'noise'=>5];
    case 'thr_stone':
    default:
      return ['miss'=>35, 'min'=>1, 'max'=>3, 'noise'=>1];
  }
}

/* ------------------------ main ------------------------ */
$user_key = require_user_key();
$item_id  = trim((string)($_POST['item_id'] ?? ''));
if ($item_id === '') json_out(['ok'=>false,'error'=>'invalid_item_id'], 400);

try {
  $pdo->beginTransaction();

  // lock raid state
  $st = $pdo->prepare('SELECT * FROM escape_raid_state WHERE user_key=? LIMIT 1 FOR UPDATE');
  $st->execute([$user_key]);
  $rs = $st->fetch(PDO::FETCH_ASSOC);
  if (!$rs) { $pdo->rollBack(); json_out(['ok'=>false,'error'=>'raid_state_not_found'], 404); }
  if ((string)($rs['status'] ?? '') !== 'in_raid') { $pdo->rollBack(); json_out(['ok'=>false,'error'=>'not_in_raid'], 409); }

  $bag = bag_decode($rs['inventory_json'] ?? null);
  $throwRaw = json_decode((string)($rs['throw_json'] ?? '{}'), true);
  $throwMap = normalize_throw_map($throwRaw);

  $brought = json_decode((string)($rs['brought_json'] ?? '{}'), true);
  if (!is_array($brought)) $brought = [];

  if (empty($brought['encounter']) || !is_array($brought['encounter'])) {
    $pdo->rollBack();
    json_out(['ok'=>false,'error'=>'no_encounter'], 409);
  }

  $e = $brought['encounter'];

  // 이미 죽었으면: loot pending 보장
  if (!empty($e['dead']) || (int)($e['hp'] ?? 0) <= 0) {
    $brought['encounter']['hp'] = 0;
    $brought['encounter']['dead'] = 1;

    $tier = (int)($brought['tier'] ?? ($brought['encounter']['tier'] ?? 1));
    ensure_encounter_loot_on_death($pdo, $brought, $tier);

    raid_state_upsert($pdo, $user_key, 'in_raid', $bag, $throwMap, $brought);
    $pdo->commit();

    json_out(['ok'=>true,'log_lines'=>['already_dead','loot_pending'],'encounter'=>$brought['encounter']]);
  }

  // 레이드백에서 투척템 차감
  if (!bag_stack_remove($bag, $item_id, 1)) {
    $pdo->rollBack();
    json_out(['ok'=>false,'error'=>'no_throw_item_in_raid_bag'], 409);
  }

  // throw 누적
  $throwMap[$item_id] = ($throwMap[$item_id] ?? 0) + 1;

  $p = throw_profile($item_id);
  $missPct = (int)$p['miss'];
  $minD = (int)$p['min'];
  $maxD = (int)$p['max'];

  $log = [];
  $log[] = "throw {$item_id}";

  $hp0 = (int)($e['hp'] ?? 0);

  $roll = random_int(1, 100);
  if ($roll <= $missPct) {
    $damage = 0;
    $log[] = 'MISS';
    $hp1 = $hp0;
  } else {
    $damage = random_int(max(0, $minD), max(0, $maxD));
    $hp1 = max(0, $hp0 - $damage);
    $log[] = "HIT dmg={$damage}";
  }

  $brought['encounter']['hp'] = $hp1;
  if ($hp1 <= 0) {
    $brought['encounter']['hp'] = 0;
    $brought['encounter']['dead'] = 1;
    $brought['encounter']['ended_at'] = time();

    $tier = (int)($brought['tier'] ?? ($brought['encounter']['tier'] ?? 1));
    ensure_encounter_loot_on_death($pdo, $brought, $tier);

    $log[] = 'enemy_dead';
    $log[] = 'loot_pending';
  }

  raid_state_upsert($pdo, $user_key, 'in_raid', $bag, $throwMap, $brought);
  $pdo->commit();

  json_out([
    'ok'=>true,
    'log_lines'=>$log,
    'thrown'=>['item_id'=>$item_id,'qty'=>1],
    'damage'=>$damage,
    'enemy_hp_before'=>$hp0,
    'enemy_hp'=>$hp1,
    'dead'=>($hp1<=0)?1:0,
    'inventory'=>$bag,
    'throw'=>$throwMap,
    'encounter'=>$brought['encounter'],
  ]);

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  json_out(['ok'=>false,'error'=>'server_error','msg'=>$e->getMessage()], 500);
}
