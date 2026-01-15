<?php
// /escape/api/raid_melee_hit.php
declare(strict_types=1);

/* ---------------------------------------------------------
 * JSON Fatal Guard (require_once 이전에 있어야 include 단계 Fatal도 JSON으로 반환)
 * --------------------------------------------------------- */
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);
ini_set('log_errors', '1');

register_shutdown_function(function () {
  $e = error_get_last();
  if (!$e) return;

  $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
  if (!in_array($e['type'], $fatalTypes, true)) return;

  if (headers_sent()) return;

  http_response_code(500);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode([
    'ok' => false,
    'error' => 'fatal',
    'type' => $e['type'],
    'message' => $e['message'],
    'file' => basename((string)$e['file']),
    'line' => (int)$e['line'],
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
});

/* --------------------------------------------------------- */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/_lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

const DEBUG = 0;

function udec(string $s): array {
  $s = trim($s);
  if ($s === '' || $s === 'null') return [];
  $v = json_decode($s, true);
  return is_array($v) ? $v : [];
}
function uenc($v): string {
  $json = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
  return ($json === false) ? '{}' : $json;
}

function melee_roll_damage(?array $itemRow): int {
  // 맨손 기본: 2~4
  if (!$itemRow) return random_int(2, 4);

  $st = item_stats($itemRow);
  // 우선순위: dmg > (min_dmg,max_dmg)
  if (isset($st['dmg']) && is_numeric($st['dmg'])) {
    $d = (int)$st['dmg'];
    return max(1, $d);
  }
  $min = null; $max = null;
  if (isset($st['min_dmg']) && is_numeric($st['min_dmg'])) $min = (int)$st['min_dmg'];
  if (isset($st['max_dmg']) && is_numeric($st['max_dmg'])) $max = (int)$st['max_dmg'];
  if ($min !== null && $max !== null && $max >= $min) {
    return max(1, random_int($min, $max));
  }
  return random_int(3, 7); // fallback
}

/**
 * loot 생성 (간단 버전)
 * 결과 형태:
 *   ['stack'=>[['item_id'=>..., 'qty'=>...], ...], 'inst'=>[['item_id'=>..., 'qty'=>...], ...]]
 */
function gen_loot(PDO $pdo, array $enc): array {
  $faction = (string)($enc['faction'] ?? '');
  $tier = (int)($enc['lootTier'] ?? 1);
  if ($tier <= 0) $tier = 1;
  if ($tier > 5) $tier = 5;

  $stack = [];
  $inst  = [];

  $add_stack = function(string $item_id, int $qty) use ($pdo, &$stack) {
    if ($qty <= 0) return;
    if (!item_row($pdo, $item_id)) return;
    $stack[] = ['item_id'=>$item_id, 'qty'=>$qty];
  };
  $add_inst = function(string $item_id, int $qty) use ($pdo, &$inst) {
    if ($qty <= 0) return;
    $row = item_row($pdo, $item_id);
    if (!$row) return;
    if (!is_instance_item($row)) return;
    $inst[] = ['item_id'=>$item_id, 'qty'=>$qty];
  };

  // 공통: 돌(좀비가 더 자주)
  if ($faction === 'zombie') {
    if (random_int(1,100) <= 55) $add_stack('thr_stone', random_int(1,2));
  } else {
    if (random_int(1,100) <= 20) $add_stack('thr_stone', 1);
  }

  // faction별 룰
  if ($faction === 'zombie') {
    if (random_int(1,100) <= (18 + $tier*3)) $add_inst('melee_fragile_stick', 1);
    if (random_int(1,100) <= 10) $add_inst('melee_rusty_knife', 1);
  } else if ($faction === 'scav') {
    if (random_int(1,100) <= (35 + $tier*5)) $add_stack('ammo_9mm_t1', random_int(6,14));
    if (random_int(1,100) <= (12 + $tier*3)) $add_inst('gun_9mm_pistol_t1', 1);
    if (random_int(1,100) <= 25) $add_inst('melee_rusty_knife', 1);
    if (random_int(1,100) <= 6)  $add_stack('thr_grenade', 1);
  } else if ($faction === 'pmc') {
    if (random_int(1,100) <= (55 + $tier*4)) $add_stack('ammo_9mm_t2', random_int(10,22));
    if (random_int(1,100) <= (18 + $tier*4)) $add_inst('gun_9mm_smg_t2', 1);
    if (random_int(1,100) <= (12 + $tier*3)) $add_inst('armor_t2_vest', 1);
    if (random_int(1,100) <= 10) $add_stack('thr_ied', 1);
  }

  // 최소 1개도 없으면 돌 1개 보장(아이템 존재 시)
  if (!$stack && !$inst) {
    $add_stack('thr_stone', 1);
  }

    // UI 표준 호환: stacks(map) + 기존 stack(list)/inst(list)
  $stacks = [];
  foreach ($stack as $s) {
    if (!is_array($s)) continue;
    $id = trim((string)($s['item_id'] ?? ''));
    $q  = (int)($s['qty'] ?? 0);
    if ($id === '' || $q <= 0) continue;
    $stacks[$id] = ($stacks[$id] ?? 0) + $q;
  }
  return ['stacks'=>$stacks, 'stack'=>$stack, 'inst'=>$inst];
}

$user_key = trim((string)($_POST['user_key'] ?? ''));
if ($user_key === '') fail('user_key required', 400);

$log = [];

try {
  $pdo->beginTransaction();

  $rs = raid_state_lock($pdo, $user_key);
  if (!$rs) { $pdo->rollBack(); fail('no_raid_state', 404); }
  if ((string)($rs['status'] ?? '') !== 'in_raid') { $pdo->rollBack(); fail('not_in_raid', 409); }

  $brought = udec((string)($rs['brought_json'] ?? '{}'));

  if (empty($brought['encounter']) || !is_array($brought['encounter'])) {
    $pdo->rollBack();
    fail('no_encounter', 409);
  }

  $enc = $brought['encounter'];

  // 이미 죽었으면: loot 화면으로 보내기만 (+ loot 없으면 여기서 생성)
  if (!empty($enc['dead']) || (int)($enc['hp'] ?? 0) <= 0) {
    $enc['hp'] = 0;
    $enc['dead'] = 1;

    if (empty($enc['loot']) || !is_array($enc['loot'])) {
      // faction/lootTier 기본값 보정
      if (empty($enc['faction'])) $enc['faction'] = 'zombie';
      if (!isset($enc['lootTier']) || (int)$enc['lootTier'] <= 0) $enc['lootTier'] = 1;

      $enc['loot'] = gen_loot($pdo, $enc);
      $enc['loot_state'] = 'pending';
      $log[] = 'already_dead';
      $log[] = 'loot_generated';
    } else {
      $enc['loot_state'] = ((string)($enc['loot_state'] ?? '') === 'pending') ? 'pending' : 'pending';
      $log[] = 'already_dead';
    }

    $brought['encounter'] = $enc;
    $pdo->prepare("UPDATE escape_raid_state SET brought_json=? WHERE user_key=? LIMIT 1")
        ->execute([uenc($brought), $user_key]);

    $pdo->commit();
    echo j(['ok'=>true,'log_lines'=>$log,'msg'=>'already_dead','encounter'=>$enc]);
    exit;
  }

  // loadout lock
  $pdo->prepare("INSERT IGNORE INTO escape_user_loadout(user_key) VALUES (?)")->execute([$user_key]);
  $st = $pdo->prepare("SELECT melee_item FROM escape_user_loadout WHERE user_key=? LIMIT 1 FOR UPDATE");
  $st->execute([$user_key]);
  $lo = $st->fetch(PDO::FETCH_ASSOC) ?: ['melee_item'=>null];

  $melee_item = (string)($lo['melee_item'] ?? '');
  $itemRow = null;

  if ($melee_item !== '') {
    $itemRow = item_row($pdo, $melee_item);
    if (!$itemRow || (string)($itemRow['type'] ?? '') !== 'melee') {
      // 슬롯 오염 방지
      $pdo->prepare("UPDATE escape_user_loadout SET melee_item=NULL WHERE user_key=?")->execute([$user_key]);
      $melee_item = '';
      $itemRow = null;
    }
  }

  // damage
  $dmg = melee_roll_damage($itemRow);

  if ($melee_item === '') {
    $log[] = "UNARMED dmg={$dmg}";
  } else {
    $log[] = "{$melee_item} dmg={$dmg}";

    // 내구도 -1 (raid location에 인스턴스가 있으면)
    $inst = inst_pick($pdo, $user_key, $melee_item, 'raid');
    if ($inst) {
      $after = inst_dec_dur($pdo, (int)$inst['id'], 1);
      if (!empty($after['broken'])) {
        $pdo->prepare("UPDATE escape_user_loadout SET melee_item=NULL WHERE user_key=?")->execute([$user_key]);
        $log[] = "melee_broken";
      }
    } else {
      // 장착은 됐는데 raid 인스턴스가 없으면 언이큅
      $pdo->prepare("UPDATE escape_user_loadout SET melee_item=NULL WHERE user_key=?")->execute([$user_key]);
      $log[] = "no_melee_instance_in_raid -> unequip";
    }
  }

  // apply hit
  $hp0 = (int)($enc['hp'] ?? 0);
  $hp1 = max(0, $hp0 - $dmg);
  $enc['hp'] = $hp1;
  $log[] = "enemy_hit";

  // dead 처리 + loot 생성
  if ($hp1 <= 0) {
    $enc['dead'] = 1;
    $enc['ended_at'] = time();

    if (empty($enc['loot']) || !is_array($enc['loot'])) {
      // faction/lootTier 기본값 보정 + npc_type 기반 추정(옵션)
      $faction = (string)($enc['faction'] ?? '');
      if ($faction === '') {
        $npc_type = (string)($enc['npc_type'] ?? '');
        if (stripos($npc_type, 'pmc') !== false) $faction = 'pmc';
        else if (stripos($npc_type, 'scav') !== false) $faction = 'scav';
        else $faction = 'zombie';
        $enc['faction'] = $faction;
      }
      if (!isset($enc['lootTier']) || (int)$enc['lootTier'] <= 0) $enc['lootTier'] = 1;

      $enc['loot'] = gen_loot($pdo, $enc);
      $enc['loot_state'] = 'pending';
    } else {
      $enc['loot_state'] = ((string)($enc['loot_state'] ?? '') === 'pending') ? 'pending' : 'pending';
    }

    $log[] = "enemy_dead";
    $log[] = "loot_ready";
  }

  $brought['encounter'] = $enc;

  $pdo->prepare("UPDATE escape_raid_state SET brought_json=? WHERE user_key=? LIMIT 1")
      ->execute([uenc($brought), $user_key]);

  $pdo->commit();

  echo j([
    'ok'=>true,
    'log_lines'=>$log,
    'damage'=>$dmg,
    'enemy_hp_before'=>$hp0,
    'enemy_hp'=>$hp1,
    'dead'=>($hp1<=0) ? 1 : 0,
    'encounter'=>$enc
  ]);
  exit;

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  http_response_code(500);
  echo DEBUG
    ? j(['ok'=>false,'error'=>'server_error','msg'=>$e->getMessage()])
    : j(['ok'=>false,'error'=>'server_error']);
  exit;
}
