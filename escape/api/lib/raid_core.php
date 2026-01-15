<?php
// /escape/api/lib/raid_core.php
declare(strict_types=1);

/* =========================
 * Basic
 * ========================= */
function json_out(array $data, int $code = 200): void {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}
function require_user_key(): string {
  $uk = trim((string)($_POST['user_key'] ?? $_GET['user_key'] ?? ''));
  if ($uk === '') json_out(['ok'=>false,'error'=>'missing_user_key'], 400);
  return $uk;
}
function uuidv4(): string {
  $data = random_bytes(16);
  $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
  $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
  return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}
function pick_weighted(array $items): ?string {
  $sum = 0;
  foreach ($items as $it) $sum += max(0, (int)($it['w'] ?? 0));
  if ($sum <= 0) return null;
  $r = random_int(1, $sum);
  $acc = 0;
  foreach ($items as $it) {
    $w = max(0, (int)($it['w'] ?? 0));
    if ($w <= 0) continue;
    $acc += $w;
    if ($r <= $acc) return (string)($it['id'] ?? '');
  }
  return null;
}

/* =========================
 * Bag JSON: {stack:[], inst:[]}
 * ========================= */
function bag_default(): array { return ['stack'=>[], 'inst'=>[]]; }

function bag_decode(?string $json): array {
  if (!$json) return bag_default();
  $d = json_decode($json, true);
  if (!is_array($d)) return bag_default();

  // NEW: accept the "stacks" map form (recommended UI standard)
  //   {"stacks": {"item_id": qty, ...}, "items": [...], "inst": [...]}
  // Normalize into internal legacy structure:
  //   {"stack": [{"item_id":...,"qty":...}, ...], "inst": [{"instance_id":...}, ...]}
  if (isset($d['stacks']) && is_array($d['stacks'])) {
    $stackFromMap = [];
    foreach ($d['stacks'] as $iid => $qty) {
      $iid = trim((string)$iid);
      $qty = (int)$qty;
      if ($iid === '' || $qty <= 0) continue;
      $stackFromMap[] = ['item_id' => $iid, 'qty' => $qty];
    }
    // Merge into existing stack(list) if it exists; otherwise create it.
    if (!isset($d['stack']) || !is_array($d['stack'])) $d['stack'] = [];
    $d['stack'] = array_merge($d['stack'], $stackFromMap);
  }

  // legacy: top-level list => stack
  $keys = array_keys($d);
  $isList = ($keys === range(0, count($keys)-1));
  if ($isList) {
    $norm = [];
    foreach ($d as $row) {
      if (!is_array($row)) continue;
      $iid = trim((string)($row['item_id'] ?? ''));
      $qty = (int)($row['qty'] ?? 0);
      if ($iid !== '' && $qty > 0) $norm[] = ['item_id'=>$iid,'qty'=>$qty];
    }
    return ['stack'=>$norm, 'inst'=>[]];
  }

  if (!isset($d['stack']) || !is_array($d['stack'])) $d['stack'] = [];
  if (!isset($d['inst'])  || !is_array($d['inst']))  $d['inst']  = [];

  // Normalize stack list (dedupe by item_id)
  $stackAcc = [];
  foreach ($d['stack'] as $row) {
    if (!is_array($row)) continue;
    $iid = trim((string)($row['item_id'] ?? ''));
    $qty = (int)($row['qty'] ?? 0);
    if ($iid === '' || $qty <= 0) continue;
    $stackAcc[$iid] = ($stackAcc[$iid] ?? 0) + $qty;
  }
  $normStack = [];
  foreach ($stackAcc as $iid => $qty) {
    if ($qty <= 0) continue;
    $normStack[] = ['item_id' => (string)$iid, 'qty' => (int)$qty];
  }

  $normInst = [];
  foreach ($d['inst'] as $row) {
    if (!is_array($row)) continue;
    $instId = trim((string)($row['instance_id'] ?? ''));
    if ($instId !== '') $normInst[] = ['instance_id'=>$instId];
  }

  return ['stack'=>$normStack, 'inst'=>$normInst];
}

function bag_encode(array $bag): string {
  if (!isset($bag['stack']) || !is_array($bag['stack'])) $bag['stack'] = [];
  if (!isset($bag['inst'])  || !is_array($bag['inst']))  $bag['inst']  = [];
  return json_encode($bag, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function bag_stack_add(array &$bag, string $item_id, int $qty): void {
  $item_id = trim($item_id);
  if ($item_id === '' || $qty <= 0) return;
  foreach ($bag['stack'] as &$row) {
    if ((string)($row['item_id'] ?? '') === $item_id) {
      $row['qty'] = (int)$row['qty'] + $qty;
      return;
    }
  }
  $bag['stack'][] = ['item_id'=>$item_id,'qty'=>$qty];
}

function bag_stack_get(array $bag, string $item_id): int {
  $item_id = trim($item_id);
  if ($item_id === '') return 0;
  foreach ($bag['stack'] as $row) {
    if ((string)($row['item_id'] ?? '') === $item_id) return (int)($row['qty'] ?? 0);
  }
  return 0;
}

function bag_stack_remove(array &$bag, string $item_id, int $qty): bool {
  $item_id = trim($item_id);
  if ($item_id === '' || $qty <= 0) return true;

  foreach ($bag['stack'] as $i => $row) {
    if ((string)($row['item_id'] ?? '') === $item_id) {
      $have = (int)($row['qty'] ?? 0);
      if ($have < $qty) return false;
      $left = $have - $qty;
      if ($left <= 0) array_splice($bag['stack'], $i, 1);
      else $bag['stack'][$i]['qty'] = $left;
      return true;
    }
  }
  return false;
}

function bag_inst_add(array &$bag, string $instance_id): void {
  $instance_id = trim($instance_id);
  if ($instance_id === '') return;
  foreach ($bag['inst'] as $row) {
    if ((string)($row['instance_id'] ?? '') === $instance_id) return;
  }
  $bag['inst'][] = ['instance_id'=>$instance_id];
}

function bag_inst_remove(array &$bag, string $instance_id): bool {
  $instance_id = trim($instance_id);
  if ($instance_id === '') return false;
  foreach ($bag['inst'] as $i => $row) {
    if ((string)($row['instance_id'] ?? '') === $instance_id) {
      array_splice($bag['inst'], $i, 1);
      return true;
    }
  }
  return false;
}

/* =========================
 * Items
 * ========================= */
function item_get(PDO $pdo, string $item_id): ?array {
  $st = $pdo->prepare("SELECT * FROM escape_items WHERE item_id=? LIMIT 1");
  $st->execute([$item_id]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return $row ?: null;
}

if (!function_exists('item_stats')) {
  function item_stats(array $itemRow): array {
    $st = json_decode((string)($itemRow['stats_json'] ?? '{}'), true);
    return is_array($st) ? $st : [];
  }
}

function item_type(array $item): string {
  return (string)($item['type'] ?? '');
}

/* =========================
 * Raid state
 * ========================= */
function raid_state_get(PDO $pdo, string $user_key): ?array {
  $st = $pdo->prepare("SELECT * FROM escape_raid_state WHERE user_key=? LIMIT 1");
  $st->execute([$user_key]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return $row ?: null;
}
function raid_state_require_in_raid(PDO $pdo, string $user_key): array {
  $rs = raid_state_get($pdo, $user_key);
  if (!$rs) json_out(['ok'=>false,'error'=>'raid_state_not_found'], 404);
  if (($rs['status'] ?? '') !== 'in_raid') json_out(['ok'=>false,'error'=>'not_in_raid'], 409);
  return $rs;
}
function raid_state_upsert(PDO $pdo, string $user_key, string $status, array $bag, array $throw, array $brought): void {
  $inv = bag_encode($bag);
  $thr = json_encode($throw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  $br  = json_encode($brought, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

  $st = $pdo->prepare("
    INSERT INTO escape_raid_state(user_key,status,inventory_json,throw_json,brought_json)
    VALUES(?,?,?,?,?)
    ON DUPLICATE KEY UPDATE
      status=VALUES(status),
      inventory_json=VALUES(inventory_json),
      throw_json=VALUES(throw_json),
      brought_json=VALUES(brought_json)
  ");
  $st->execute([$user_key, $status, $inv, $thr, $br]);
}

/* =========================
 * Instances
 * ========================= */
function instance_create(PDO $pdo, string $user_key, string $item_id, int $durability_max, ?string $ammo_type=null, ?int $mag_size=null, ?int $ammo_in_mag=null): string {
  $iid = uuidv4();
  $durability_max = max(1, $durability_max);
  $durability = $durability_max;

  $st = $pdo->prepare("
    INSERT INTO escape_item_instances(instance_id,item_id,user_key,durability,durability_max,ammo_type,ammo_in_mag,mag_size)
    VALUES(?,?,?,?,?,?,?,?)
  ");
  $st->execute([$iid, $item_id, $user_key, $durability, $durability_max, $ammo_type, $ammo_in_mag, $mag_size]);
  return $iid;
}
function instance_get_owned(PDO $pdo, string $user_key, string $instance_id): ?array {
  $st = $pdo->prepare("SELECT * FROM escape_item_instances WHERE instance_id=? AND user_key=? LIMIT 1");
  $st->execute([$instance_id, $user_key]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return $row ?: null;
}
function instance_set_durability(PDO $pdo, string $user_key, string $instance_id, int $new_dur): void {
  $st = $pdo->prepare("UPDATE escape_item_instances SET durability=? WHERE instance_id=? AND user_key=?");
  $st->execute([$new_dur, $instance_id, $user_key]);
}
function instance_damage(PDO $pdo, string $user_key, string $instance_id, int $delta): array {
  $inst = instance_get_owned($pdo, $user_key, $instance_id);
  if (!$inst) json_out(['ok'=>false,'error'=>'instance_not_found'], 404);

  $cur = (int)$inst['durability'];
  $max = (int)$inst['durability_max'];
  $new = $cur - max(0, $delta);
  if ($new < 0) $new = 0;
  if ($new > $max) $new = $max;

  instance_set_durability($pdo, $user_key, $instance_id, $new);
  $inst['durability'] = $new;
  return $inst;
}
function instance_set_ammo(PDO $pdo, string $user_key, string $instance_id, int $ammo_in_mag): void {
  $st = $pdo->prepare("UPDATE escape_item_instances SET ammo_in_mag=? WHERE instance_id=? AND user_key=?");
  $st->execute([$ammo_in_mag, $instance_id, $user_key]);
}

/* =========================
 * Stash
 * ========================= */
function stash_stack_add(PDO $pdo, string $user_key, string $item_id, int $qty): void {
  $item_id = trim($item_id);
  if ($item_id === '' || $qty <= 0) return;
  $st = $pdo->prepare("
    INSERT INTO escape_user_stash(user_key,item_id,qty)
    VALUES(?,?,?)
    ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty)
  ");
  $st->execute([$user_key, $item_id, $qty]);
}
function stash_instance_add(PDO $pdo, string $user_key, string $instance_id): void {
  $st = $pdo->prepare("INSERT IGNORE INTO escape_user_stash_instances(user_key,instance_id) VALUES(?,?)");
  $st->execute([$user_key, $instance_id]);
}

/* =========================
 * Tier rules
 * ========================= */
function tier_min_durability(int $tier): int {
  $map = [1=>3, 2=>8, 3=>15, 4=>25, 5=>40];
  return $map[$tier] ?? 3;
}

/* =========================
 * Create instance from template item
 * ========================= */
function create_instance_from_item(PDO $pdo, string $user_key, array $item, array $opts = []): string {
  $id = (string)$item['item_id'];
  $t  = (string)$item['type'];
  $st = item_stats($item);

  $durMax = 0;
  if (isset($opts['durability_max'])) $durMax = (int)$opts['durability_max'];
  if ($durMax <= 0) {
    if (isset($st['dur_base'])) $durMax = (int)$st['dur_base'];
    if ($durMax <= 0) {
      $tier = (int)($st['tier'] ?? 1);
      $durMax = tier_min_durability($tier);
    }
  }

  $ammoType = null; $magSize = null; $ammoIn = null;
  if ($t === 'gun') {
    $ammoType = isset($st['ammo_type']) ? (string)$st['ammo_type'] : null;
    $magSize  = isset($st['mag_size']) ? (int)$st['mag_size'] : 30;
    $ammoIn   = isset($opts['ammo_in_mag']) ? (int)$opts['ammo_in_mag'] : 0;
    if ($ammoIn < 0) $ammoIn = 0;
    if ($magSize !== null && $ammoIn > $magSize) $ammoIn = $magSize;
  }

  return instance_create($pdo, $user_key, $id, $durMax, $ammoType, $ammoIn===null?null:$ammoIn, $magSize);
}

/* =========================
 * Starter grant (당신 item_id 고정)
 * ========================= */
function grant_starter_if_needed(PDO $pdo, string $user_key, string $ITEM_STONE, string $ITEM_STICK_BREAKABLE, array $T1_MELEE_ITEM_IDS): array {
  $st = $pdo->prepare("SELECT starter_granted FROM escape_users WHERE user_key=? LIMIT 1");
  $st->execute([$user_key]);
  $u = $st->fetch(PDO::FETCH_ASSOC);
  if (!$u) json_out(['ok'=>false,'error'=>'user_not_found'], 404);
  if ((int)$u['starter_granted'] === 1) return ['granted'=>false];

  $stoneQty = random_int(1, 3);
  stash_stack_add($pdo, $user_key, $ITEM_STONE, $stoneQty);

  $given = [];
  $roll = random_int(1, 100);

  if ($roll <= 40) {
    $iid = instance_create($pdo, $user_key, $ITEM_STICK_BREAKABLE, 3);
    stash_instance_add($pdo, $user_key, $iid);
    $given[] = ['kind'=>'inst','instance_id'=>$iid,'item_id'=>$ITEM_STICK_BREAKABLE,'durability_max'=>3];
  } else {
    $pick = empty($T1_MELEE_ITEM_IDS) ? $ITEM_STICK_BREAKABLE : (string)$T1_MELEE_ITEM_IDS[array_rand($T1_MELEE_ITEM_IDS)];
    $item = item_get($pdo, $pick);
    if (!$item) {
      $iid = instance_create($pdo, $user_key, $ITEM_STICK_BREAKABLE, 3);
      stash_instance_add($pdo, $user_key, $iid);
      $given[] = ['kind'=>'inst','instance_id'=>$iid,'item_id'=>$ITEM_STICK_BREAKABLE,'durability_max'=>3,'note'=>'fallback_missing_item'];
    } else {
      if ($pick === $ITEM_STICK_BREAKABLE) {
        $iid = instance_create($pdo, $user_key, $ITEM_STICK_BREAKABLE, 3);
        stash_instance_add($pdo, $user_key, $iid);
        $given[] = ['kind'=>'inst','instance_id'=>$iid,'item_id'=>$ITEM_STICK_BREAKABLE,'durability_max'=>3];
      } else {
        $stats = item_stats($item);
        $tier = (int)($stats['tier'] ?? 1);
        $durMax = tier_min_durability($tier);
        $iid = instance_create($pdo, $user_key, (string)$item['item_id'], $durMax);
        stash_instance_add($pdo, $user_key, $iid);
        $given[] = ['kind'=>'inst','instance_id'=>$iid,'item_id'=>(string)$item['item_id'],'tier'=>$tier,'durability_max'=>$durMax];
      }
    }
  }

  $st2 = $pdo->prepare("UPDATE escape_users SET starter_granted=1 WHERE user_key=?");
  $st2->execute([$user_key]);

  return ['granted'=>true,'stone_qty'=>$stoneQty,'given'=>$given];
}

/* =========================
 * NPC 20 Types
 * - meta: hp, atk, aim, lootTier
 * - drop profile uses lootTier + faction
 * ========================= */
function npc_catalog(): array {
  // lootTier: 1(low)~5(high)
  return [
    // Zombies (10)
    'zombie_shambler' => ['faction'=>'zombie','name'=>'비틀거리는 시체','hp'=>30,'atk'=>6,'aim'=>0,'lootTier'=>1],
    'zombie_runner'   => ['faction'=>'zombie','name'=>'질주 좀비','hp'=>22,'atk'=>7,'aim'=>0,'lootTier'=>1],
    'zombie_tank'     => ['faction'=>'zombie','name'=>'탱커 좀비','hp'=>55,'atk'=>8,'aim'=>0,'lootTier'=>2],
    'zombie_spitter'  => ['faction'=>'zombie','name'=>'침뱉는 감염자','hp'=>28,'atk'=>7,'aim'=>0,'lootTier'=>2],
    'zombie_screamer' => ['faction'=>'zombie','name'=>'비명 감염자','hp'=>26,'atk'=>6,'aim'=>0,'lootTier'=>2],
    'zombie_biter'    => ['faction'=>'zombie','name'=>'광견 감염자','hp'=>24,'atk'=>8,'aim'=>0,'lootTier'=>2],
    'zombie_armored'  => ['faction'=>'zombie','name'=>'보호구 감염자','hp'=>40,'atk'=>7,'aim'=>0,'lootTier'=>3],
    'zombie_boss'     => ['faction'=>'zombie','name'=>'감염자 우두머리','hp'=>85,'atk'=>10,'aim'=>0,'lootTier'=>3],
    'zombie_crawler'  => ['faction'=>'zombie','name'=>'크롤러','hp'=>18,'atk'=>5,'aim'=>0,'lootTier'=>1],
    'zombie_swarm'    => ['faction'=>'zombie','name'=>'무리 감염자','hp'=>34,'atk'=>7,'aim'=>0,'lootTier'=>2],

    // Scavs (5)
    'scav_rookie'     => ['faction'=>'scav','name'=>'스캐브 신참','hp'=>35,'atk'=>9,'aim'=>25,'lootTier'=>2],
    'scav_raider'     => ['faction'=>'scav','name'=>'스캐브 약탈자','hp'=>40,'atk'=>10,'aim'=>30,'lootTier'=>3],
    'scav_shotgunner' => ['faction'=>'scav','name'=>'스캐브 산탄수','hp'=>38,'atk'=>11,'aim'=>28,'lootTier'=>3],
    'scav_sprinter'   => ['faction'=>'scav','name'=>'스캐브 척후','hp'=>32,'atk'=>9,'aim'=>35,'lootTier'=>3],
    'scav_veteran'    => ['faction'=>'scav','name'=>'스캐브 숙련자','hp'=>45,'atk'=>12,'aim'=>40,'lootTier'=>4],

    // PMC (5)
    'pmc_light'       => ['faction'=>'pmc','name'=>'PMC 경무장','hp'=>50,'atk'=>14,'aim'=>55,'lootTier'=>4],
    'pmc_assault'     => ['faction'=>'pmc','name'=>'PMC 돌격','hp'=>60,'atk'=>16,'aim'=>60,'lootTier'=>4],
    'pmc_marksman'    => ['faction'=>'pmc','name'=>'PMC 지정사수','hp'=>55,'atk'=>18,'aim'=>70,'lootTier'=>5],
    'pmc_heavy'       => ['faction'=>'pmc','name'=>'PMC 중무장','hp'=>75,'atk'=>17,'aim'=>55,'lootTier'=>5],
    'pmc_boss'        => ['faction'=>'pmc','name'=>'PMC 지휘관','hp'=>95,'atk'=>20,'aim'=>75,'lootTier'=>5],
  ];
}

function npc_get(string $npc_type): array {
  $cat = npc_catalog();
  $npc_type = trim($npc_type);
  if ($npc_type === '' || !isset($cat[$npc_type])) {
    // fallback: faction key도 허용
    $fallback = [
      'zombie'=>'zombie_shambler',
      'scav'=>'scav_rookie',
      'pmc'=>'pmc_light',
    ];
    $npc_type = $fallback[strtolower($npc_type)] ?? 'zombie_shambler';
  }
  return ['type'=>$npc_type] + $cat[$npc_type];
}

/* =========================
 * Drop generation by npc profile
 * - Uses your known item_ids + seeded items
 * ========================= */
function drop_generate(PDO $pdo, string $npc_type): array {
  $npc = npc_get($npc_type);
  $faction = (string)$npc['faction'];
  $lootTier = (int)$npc['lootTier'];

  $out = ['stack'=>[], 'inst'=>[], 'npc'=>$npc];

  // throw: 돌맹이 (좀비가 상대적으로 많음)
  $stoneChance = ($faction==='zombie') ? 35 : 18;
  if ($lootTier >= 4) $stoneChance = 10;
  if (random_int(1,100) <= $stoneChance) {
    $out['stack'][] = ['item_id'=>'thr_stone','qty'=>($faction==='zombie'? random_int(1,2) : 1)];
  }

  // ammo (9mm seed)
  $ammoChance = 0;
  if ($faction==='zombie') $ammoChance = 10 + ($lootTier*3);    // 낮음
  if ($faction==='scav')   $ammoChance = 35 + ($lootTier*5);    // 중간
  if ($faction==='pmc')    $ammoChance = 65 + ($lootTier*4);    // 높음
  if ($ammoChance > 92) $ammoChance = 92;

  if (random_int(1,100) <= $ammoChance) {
    $pool = [
      ['id'=>'ammo_9mm_t1','w'=> max(0, 40 - $lootTier*6)],
      ['id'=>'ammo_9mm_t2','w'=> max(0, 28 - $lootTier*3)],
      ['id'=>'ammo_9mm_t3','w'=> max(0, 18 + $lootTier*2)],
      ['id'=>'ammo_9mm_t4','w'=> max(0, 8  + $lootTier*3)],
      ['id'=>'ammo_9mm_t5','w'=> max(0, 2  + $lootTier*2)],
    ];
    // zombie는 상위 탄 가중치 깎기
    if ($faction==='zombie') {
      foreach ($pool as &$p) {
        if (strpos($p['id'],'_t4')!==false || strpos($p['id'],'_t5')!==false) $p['w'] = (int)floor($p['w']*0.35);
      }
      unset($p);
    }
    $ammoPick = pick_weighted($pool);
    if ($ammoPick) {
      $qty = ($faction==='pmc' ? random_int(12,28) : ($faction==='scav' ? random_int(6,16) : random_int(3,10)));
      $out['stack'][] = ['item_id'=>$ammoPick,'qty'=>$qty];
    }
  }

  // melee (your list)
  $meleeChance = ($faction==='zombie') ? 28 : ($faction==='scav' ? 45 : 32);
  if ($lootTier >= 4) $meleeChance -= 8;

  if (random_int(1,100) <= $meleeChance) {
    $meleePool = [
      ['id'=>'melee_fragile_stick','w'=> ($faction==='zombie'? 26 : 4)],
      ['id'=>'melee_rusty_knife','w'=> 14],
      ['id'=>'melee_scrapknife','w'=> 12],
      ['id'=>'melee_prybar','w'=> ($faction!=='zombie'? 10 : 3)],
      ['id'=>'melee_pipewrench','w'=> ($faction!=='zombie'? 8 : 2)],
      ['id'=>'melee_bat','w'=> ($faction==='pmc'? 6 : 2)],
      ['id'=>'melee_machete','w'=> ($faction==='pmc'? 6 : 1)],
      ['id'=>'melee_kukri','w'=> ($faction==='pmc'? 6 : 1)],
      ['id'=>'melee_combat_axe','w'=> ($faction==='pmc'? 4 : 0)],
    ];
    $pick = pick_weighted($meleePool);
    if ($pick) {
      $durMax = ($pick === 'melee_fragile_stick') ? 3 : 0;
      $out['inst'][] = ['item_id'=>$pick,'dur_max'=>$durMax];
    }
  }

  // armor
  $armorChance = ($faction==='pmc') ? (40 + $lootTier*5) : (($faction==='scav') ? (10 + $lootTier*3) : 0);
  if ($armorChance > 75) $armorChance = 75;

  if ($armorChance > 0 && random_int(1,100) <= $armorChance) {
    $armorPool = [
      ['id'=>'armor_t1_vest','w'=> max(0, 26 - $lootTier*4)],
      ['id'=>'armor_t2_vest','w'=> max(0, 18 - $lootTier*2)],
      ['id'=>'armor_t3_vest','w'=> max(0, 10 + $lootTier*2)],
      ['id'=>'armor_t4_plate','w'=> max(0, 6  + $lootTier*2)],
      ['id'=>'armor_t5_plate','w'=> max(0, 3  + $lootTier*2)],
    ];
    // scav는 상위 방어구 급감
    if ($faction==='scav') {
      foreach ($armorPool as &$p) {
        if (strpos($p['id'],'t4')!==false || strpos($p['id'],'t5')!==false) $p['w'] = (int)floor($p['w']*0.25);
      }
      unset($p);
    }
    $pick = pick_weighted($armorPool);
    if ($pick) $out['inst'][] = ['item_id'=>$pick,'dur_max'=>0];
  }

  // gun
  $gunChance = ($faction==='pmc') ? (45 + $lootTier*4) : (($faction==='scav') ? (10 + $lootTier*2) : 0);
  if ($gunChance > 80) $gunChance = 80;

  if ($gunChance > 0 && random_int(1,100) <= $gunChance) {
    $gunPool = [
      ['id'=>'gun_9mm_pistol_t1','w'=> ($faction==='pmc'? 10 : 18)],
      ['id'=>'gun_9mm_smg_t2','w'=> ($faction==='pmc'? 12 : 2)],
    ];
    $pick = pick_weighted($gunPool);
    if ($pick) {
      $ammoIn = ($faction==='pmc' ? random_int(5,15) : random_int(0,7));
      $out['inst'][] = ['item_id'=>$pick,'dur_max'=>0,'ammo_in_mag'=>$ammoIn];
    }
  }

  return $out;
}
