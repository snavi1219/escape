<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/_lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

const DEBUG = 0;

function jfail(string $msg, int $code = 400, array $extra = []): void {
  http_response_code($code);
  echo json_encode(['ok' => false, 'error' => $msg] + $extra, JSON_UNESCAPED_UNICODE);
  exit;
}

function json_decode_assoc(string $s): array {
  $s = trim($s);
  if ($s === '' || $s === 'null') return [];
  $v = json_decode($s, true);
  return is_array($v) ? $v : [];
}

function json_encode_u($v): string {
  return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * stats_json 예시(가정 아님, "있으면 사용"):
 * - {"dmg": 12, "dur": 40} 또는 {"damage":12,"durability":40}
 * - {"min_dmg":8,"max_dmg":14}
 */
function melee_damage_from_stats(array $stats): int {
  // 우선순위: dmg/damage > (min,max) 랜덤 > 기본 6
  $dmg = 0;

  if (isset($stats['dmg']) && is_numeric($stats['dmg'])) $dmg = (int)$stats['dmg'];
  else if (isset($stats['damage']) && is_numeric($stats['damage'])) $dmg = (int)$stats['damage'];
  else {
    $min = null; $max = null;
    if (isset($stats['min_dmg']) && is_numeric($stats['min_dmg'])) $min = (int)$stats['min_dmg'];
    if (isset($stats['max_dmg']) && is_numeric($stats['max_dmg'])) $max = (int)$stats['max_dmg'];
    if ($min !== null && $max !== null && $max >= $min) {
      $dmg = random_int($min, $max);
    }
  }

  if ($dmg <= 0) $dmg = 6; // 최저 보정
  return $dmg;
}


function gen_loot_standard(PDO $pdo, array $enc): array {
  $faction = (string)($enc['faction'] ?? '');
  $tier = (int)($enc['lootTier'] ?? ($enc['tier'] ?? 1));
  if ($tier <= 0) $tier = 1;
  if ($tier > 5) $tier = 5;

  $stack = [];
  $inst  = [];

  $add_stack = function(string $item_id, int $qty) use ($pdo, &$stack) {
    if ($qty <= 0) return;
    $row = item_row($pdo, $item_id);
    if (!$row) return;
    if (!is_stack_item($row)) return;
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

  // faction별 룰(raid_melee_hit와 동일 컨셉)
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

  if (!$stack && !$inst) $add_stack('thr_stone', 1);

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

function ensure_encounter_loot_on_death(PDO $pdo, array &$brought): void {
  if (empty($brought['encounter']) || !is_array($brought['encounter'])) return;
  $e =& $brought['encounter'];

  $hp = (int)($e['hp'] ?? 0);
  $dead = (int)($e['dead'] ?? 0);

  if ($dead === 0 && $hp > 0) return;

  $e['hp'] = 0;
  $e['dead'] = 1;

  $loot_state = (string)($e['loot_state'] ?? '');
  $loot = $e['loot'] ?? null;
  if ($loot_state === 'pending' && is_array($loot) && !empty($loot['stacks']) && is_array($loot['stacks'])) return;

  $e['loot'] = gen_loot_standard($pdo, $e);
  $e['loot_state'] = 'pending';
}

$user_key = trim((string)($_POST['user_key'] ?? ''));
if ($user_key === '') jfail('user_key required', 400);

try {
  $pdo->beginTransaction();

  // 1) 레이드 상태 잠금 (당신 프로젝트에 이미 쓰는 헬퍼 가정)
  $rs = raid_state_lock($pdo, $user_key); // 보통 escape_raid_state FOR UPDATE
  if (!$rs) { $pdo->rollBack(); jfail('no_raid_state', 404); }
  if ((string)$rs['status'] !== 'in_raid') { $pdo->rollBack(); jfail('not_in_raid', 409); }

  // 2) brought_json 로드 + encounter 확인
  $brought = json_decode_assoc((string)($rs['brought_json'] ?? ''));
  if (!isset($brought['encounter']) || !is_array($brought['encounter'])) {
    $pdo->rollBack();
    jfail('no_encounter', 409);
  }

  $enc = $brought['encounter'];

  // 이미 죽은 적이면 중복 타격 방지
  if (!empty($enc['dead'])) {
    $pdo->commit();
    echo json_encode([
      'ok' => true,
      'already_dead' => true,
      'enemy_hp' => (int)($enc['hp'] ?? 0),
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $enemy_hp = (int)($enc['hp'] ?? 0);
  if ($enemy_hp <= 0) {
    // hp가 0 이하인데 dead 플래그가 없던 케이스 정리
    $enc['hp'] = 0;
    $enc['dead'] = 1;
    $brought['encounter'] = $enc;
    ensure_encounter_loot_on_death($pdo, $brought);

    $stUp = $pdo->prepare("UPDATE escape_raid_state SET brought_json=? WHERE user_key=? LIMIT 1");
    $stUp->execute([json_encode_u($brought), $user_key]);

    $pdo->commit();
    echo json_encode(['ok'=>true,'enemy_hp'=>0,'dead'=>true], JSON_UNESCAPED_UNICODE);
    exit;
  }

  // 3) 근접 무기 확인 (loadout 잠금)
  $pdo->prepare("INSERT IGNORE INTO escape_user_loadout(user_key) VALUES (?)")->execute([$user_key]);
  $st = $pdo->prepare("SELECT melee_item FROM escape_user_loadout WHERE user_key=? LIMIT 1 FOR UPDATE");
  $st->execute([$user_key]);
  $loadout = $st->fetch(PDO::FETCH_ASSOC) ?: [];
  $melee_item = (int)($loadout['melee_item'] ?? 0);
  if ($melee_item <= 0) { $pdo->rollBack(); jfail('no_melee_equipped', 409); }

  // 4) 무기 스탯 로드 (escape_items.stats_json)
  $st2 = $pdo->prepare("SELECT item_id, name, stats_json FROM escape_items WHERE item_id=? LIMIT 1");
  $st2->execute([$melee_item]);
  $it = $st2->fetch(PDO::FETCH_ASSOC);
  if (!$it) { $pdo->rollBack(); jfail('melee_item_not_found', 404); }

  $stats = json_decode_assoc((string)($it['stats_json'] ?? ''));
  $baseDmg = melee_damage_from_stats($stats);

  // (선택) encounter 방어력/감쇠가 있으면 적용 (있을 때만)
  $dr = 0;
  if (isset($enc['dr']) && is_numeric($enc['dr'])) $dr = (int)$enc['dr'];
  if (isset($enc['armor']) && is_numeric($enc['armor'])) $dr = max($dr, (int)$enc['armor']);

  $damage = max(1, $baseDmg - $dr);

  // 5) HP 감소 + 죽음 처리
  $new_hp = max(0, $enemy_hp - $damage);
  $enc['hp'] = $new_hp;

  $dead = false;
  if ($new_hp <= 0) {
    $dead = true;
    $enc['dead'] = 1;
    // 후속 로직에서 쓰기 쉬운 플래그들 (있어도 되고 없어도 됨)
    $enc['ended_at'] = time();
  }

  $brought['encounter'] = $enc;
  // 사망 시 loot/pending 보장
  ensure_encounter_loot_on_death($pdo, $brought);

  // 6) 내구도 감소 (기존 코드가 하던 역할을 "brought_json.items" 또는 stats_json 기반으로 안전하게 처리)
  // - 스키마에 내구도 컬럼이 별도로 없다고 가정하지 않고,
  //   "brought_json.bag_items" / "brought_json.items" 등에 저장된 내구도가 있을 때만 감소.
  $dur_before = null;
  $dur_after  = null;

  // 후보1: brought_json.items[melee_item].dur
  if (isset($brought['items']) && is_array($brought['items'])) {
    foreach ($brought['items'] as $k => $v) {
      // items가 [ {item_id:.., dur:..}, ... ] 형태 or [item_id => {...}] 형태 둘 다 대응
      if (is_array($v)) {
        $iid = isset($v['item_id']) ? (int)$v['item_id'] : (is_numeric($k) ? (int)$k : 0);
        if ($iid === $melee_item) {
          if (isset($v['dur']) && is_numeric($v['dur'])) {
            $dur_before = (int)$v['dur'];
            $dur_after = max(0, $dur_before - 1);
            $brought['items'][$k]['dur'] = $dur_after;
          } else if (isset($v['durability']) && is_numeric($v['durability'])) {
            $dur_before = (int)$v['durability'];
            $dur_after = max(0, $dur_before - 1);
            $brought['items'][$k]['durability'] = $dur_after;
          }
          break;
        }
      }
    }
  }

  // 후보2: stats_json 내 durability를 “현재 내구도”로 쓰는 구조라면(권장X지만), 있을 때만 감소
  if ($dur_before === null) {
    if (isset($stats['durability']) && is_numeric($stats['durability'])) {
      $dur_before = (int)$stats['durability'];
      $dur_after = max(0, $dur_before - 1);
      $stats['durability'] = $dur_after;
      // escape_items.stats_json 자체를 갱신하는 방식은 "아이템 마스터" 훼손 위험이 있어 기본은 비활성.
      // 필요하면 '내구도는 brought_json 쪽에서만 관리'로 통일하는 걸 권장.
    } else if (isset($stats['dur']) && is_numeric($stats['dur'])) {
      $dur_before = (int)$stats['dur'];
      $dur_after = max(0, $dur_before - 1);
      $stats['dur'] = $dur_after;
    }
  }

  // 7) brought_json 저장
  $stUp = $pdo->prepare("UPDATE escape_raid_state SET brought_json=? WHERE user_key=? LIMIT 1");
  $stUp->execute([json_encode_u($brought), $user_key]);

  $pdo->commit();

  echo json_encode([
    'ok' => true,
    'damage' => $damage,
    'enemy_hp_before' => $enemy_hp,
    'enemy_hp' => $new_hp,
    'dead' => $dead,
    'melee_item' => $melee_item,
    'melee_name' => (string)($it['name'] ?? ''),
    'dur_before' => $dur_before,
    'dur_after' => $dur_after,
    // 디버그용: encounter 최소 정보만
    'encounter' => [
      'name' => $enc['name'] ?? null,
      'hp' => $new_hp,
      'dead' => !empty($enc['dead']),
    ],
  ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  if (DEBUG) {
    jfail('exception', 500, ['detail' => $e->getMessage()]);
  }
  jfail('server_error', 500);
}
