<?php
declare(strict_types=1);

/**
 * TRUE SINGLE COMBAT CORE (Schema/Rules B)
 *
 * CONFIRMED TABLES ONLY:
 * - escape_raid_state(user_key,status,inventory_json,throw_json,brought_json)
 * - escape_user_loadout(user_key,primary_item,secondary_item,melee_item)
 * - escape_items(item_id,name,type,rarity,stats_json)
 *
 * JSON STANDARDS:
 * - brought_json.encounter.hp (int)
 * - brought_json.encounter.dead (0/1)
 * - brought_json.encounter.loot_state: none|pending
 * - brought_json.encounter.loot: { stacks: { item_id: qty, ... } }
 * - inventory_json: { stacks:{item_id:qty}, items:[...] } (server may accept legacy)
 * - throw_json: { item_id: qty }
 */

function _json_decode_array(?string $s): array {
  $s = trim((string)$s);
  if ($s === '' || $s === 'null') return [];
  $v = json_decode($s, true);
  return is_array($v) ? $v : [];
}

function _json_encode($v): string {
  $j = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
  return ($j === false) ? '{}' : $j;
}

function _norm_map_qty($v): array {
  // accepts {id:qty} or [{item_id,qty}, ...]
  $out = [];
  if (is_array($v)) {
    $isAssoc = array_keys($v) !== range(0, count($v) - 1);
    if ($isAssoc) {
      foreach ($v as $k => $q) {
        $id = trim((string)$k);
        $qty = (int)$q;
        if ($id === '' || $qty <= 0) continue;
        $out[$id] = ($out[$id] ?? 0) + $qty;
      }
    } else {
      foreach ($v as $row) {
        if (!is_array($row)) continue;
        $id = trim((string)($row['item_id'] ?? $row['id'] ?? ''));
        $qty = (int)($row['qty'] ?? $row['count'] ?? 0);
        if ($id === '' || $qty <= 0) continue;
        $out[$id] = ($out[$id] ?? 0) + $qty;
      }
    }
  }
  return $out;
}

function _bag_decode(array $bag): array {
  // returns normalized {stacks:map, items:list}
  $stacks = [];
  $items  = [];

  if (isset($bag['stacks'])) {
    $stacks = _norm_map_qty($bag['stacks']);
  }
  if (!$stacks && isset($bag['stack'])) {
    $stacks = _norm_map_qty($bag['stack']);
  }
  if (isset($bag['items']) && is_array($bag['items'])) $items = $bag['items'];
  else if (isset($bag['inst']) && is_array($bag['inst'])) $items = $bag['inst'];

  return ['stacks'=>$stacks, 'items'=>$items];
}

function _bag_encode(array $bag): array {
  $stacks = _norm_map_qty($bag['stacks'] ?? []);
  $items  = is_array($bag['items'] ?? null) ? $bag['items'] : [];
  return ['stacks'=>$stacks, 'items'=>$items];
}

function _item_row(PDO $pdo, string $item_id): ?array {
  $st = $pdo->prepare("SELECT item_id,name,type,rarity,stats_json FROM escape_items WHERE item_id=? LIMIT 1");
  $st->execute([$item_id]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return $row ?: null;
}

function _item_stats(?array $row): array {
  if (!$row) return [];
  $st = _json_decode_array((string)($row['stats_json'] ?? ''));
  return $st;
}

function _roll_damage_from_item(?array $itemRow, int $fallbackMin, int $fallbackMax): int {
  if ($fallbackMax < $fallbackMin) $fallbackMax = $fallbackMin;
  $st = _item_stats($itemRow);

  $candidates = [
    'dmg','damage','atk','power'
  ];
  foreach ($candidates as $k) {
    if (isset($st[$k]) && is_numeric($st[$k])) {
      $d = (int)$st[$k];
      return max(1, $d);
    }
  }

  $pairs = [
    ['min_dmg','max_dmg'],
    ['dmg_min','dmg_max'],
    ['min_damage','max_damage'],
  ];
  foreach ($pairs as [$a,$b]) {
    if (isset($st[$a], $st[$b]) && is_numeric($st[$a]) && is_numeric($st[$b])) {
      $min = (int)$st[$a];
      $max = (int)$st[$b];
      if ($max < $min) $max = $min;
      return random_int(max(1,$min), max(1,$max));
    }
  }

  return random_int($fallbackMin, $fallbackMax);
}

function _gen_loot(PDO $pdo, array $enc): array {
  // DB-driven safe loot: pick up to 3 random items that exist.
  // If your content later adds loot tables, extend here.
  $stacks = [];
  $n = 2;
  $tier = (int)($enc['lootTier'] ?? 1);
  if ($tier <= 0) $tier = 1;
  if ($tier >= 4) $n = 3;

  $st = $pdo->query("SELECT item_id,type FROM escape_items ORDER BY RAND() LIMIT " . (int)$n);
  $rows = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
  foreach ($rows as $r) {
    $id = trim((string)($r['item_id'] ?? ''));
    if ($id === '') continue;
    $qty = 1;
    $type = (string)($r['type'] ?? '');
    // heuristic: ammo/material may drop more
    if (stripos($type, 'ammo') !== false) $qty = random_int(6, 18);
    else if (stripos($type, 'mat') !== false || stripos($type, 'material') !== false) $qty = random_int(1, 3);
    $stacks[$id] = ($stacks[$id] ?? 0) + $qty;
  }

  // absolute fallback: if table empty, keep empty but still valid
  return ['stacks'=>$stacks];
}

function combat_execute(PDO $pdo, string $user_key, string $kind, array $payload): array {
  $log = [];
  $pdo->beginTransaction();

  $st = $pdo->prepare("SELECT user_key,status,inventory_json,throw_json,brought_json FROM escape_raid_state WHERE user_key=? LIMIT 1 FOR UPDATE");
  $st->execute([$user_key]);
  $rs = $st->fetch(PDO::FETCH_ASSOC);

  if (!$rs) { $pdo->rollBack(); return ['ok'=>false,'error'=>'no_raid_state']; }
  if ((string)($rs['status'] ?? '') !== 'in_raid') { $pdo->rollBack(); return ['ok'=>false,'error'=>'not_in_raid']; }

  $bagRaw = _json_decode_array((string)($rs['inventory_json'] ?? ''));
  $bag = _bag_decode($bagRaw);

  $throwRaw = _json_decode_array((string)($rs['throw_json'] ?? ''));
  $throwMap = _norm_map_qty($throwRaw);

  $brought = _json_decode_array((string)($rs['brought_json'] ?? ''));
  if (!isset($brought['encounter']) || !is_array($brought['encounter'])) {
    $pdo->rollBack();
    return ['ok'=>false,'error'=>'no_encounter'];
  }

  $enc = $brought['encounter'];
  $hp = (int)($enc['hp'] ?? 0);
  $dead = (int)($enc['dead'] ?? 0);

  if ($dead === 1 || $hp <= 0) {
    // already dead: make sure loot pending exists and is standardized
    $enc['hp'] = 0;
    $enc['dead'] = 1;
    $enc['loot_state'] = 'pending';
    $loot = is_array($enc['loot'] ?? null) ? $enc['loot'] : [];
    $stacks = _norm_map_qty($loot['stacks'] ?? []);
    if (!$stacks) {
      $loot = _gen_loot($pdo, $enc);
      $stacks = _norm_map_qty($loot['stacks'] ?? []);
      $log[] = 'loot_generated_on_already_dead';
    }
    $enc['loot'] = ['stacks'=>$stacks];
    $brought['encounter'] = $enc;

    $pdo->prepare("UPDATE escape_raid_state SET brought_json=?, inventory_json=?, throw_json=? WHERE user_key=? LIMIT 1")
      ->execute([
        _json_encode($brought),
        _json_encode(_bag_encode($bag)),
        _json_encode($throwMap),
        $user_key
      ]);

    $pdo->commit();
    return ['ok'=>true,'log_lines'=>$log,'msg'=>'already_dead','encounter'=>$enc];
  }

  // loadout
  $pdo->prepare("INSERT IGNORE INTO escape_user_loadout(user_key) VALUES (?)")->execute([$user_key]);
  $stL = $pdo->prepare("SELECT primary_item,secondary_item,melee_item FROM escape_user_loadout WHERE user_key=? LIMIT 1 FOR UPDATE");
  $stL->execute([$user_key]);
  $lo = $stL->fetch(PDO::FETCH_ASSOC) ?: ['primary_item'=>null,'secondary_item'=>null,'melee_item'=>null];

  $dmg = 0;

  if ($kind === 'melee') {
    $melee = trim((string)($lo['melee_item'] ?? ''));
    $row = $melee !== '' ? _item_row($pdo, $melee) : null;
    $dmg = _roll_damage_from_item($row, 3, 7);
    $log[] = ($melee !== '' ? "melee={$melee}" : 'melee=unarmed') . " dmg={$dmg}";
  }
  else if ($kind === 'gun') {
    $gun = trim((string)($lo['primary_item'] ?? ''));
    if ($gun === '') $gun = trim((string)($lo['secondary_item'] ?? ''));
    $row = $gun !== '' ? _item_row($pdo, $gun) : null;
    $dmg = _roll_damage_from_item($row, 10, 18);
    $log[] = ($gun !== '' ? "gun={$gun}" : 'gun=unknown') . " dmg={$dmg}";
    // (Optional future) ammo consumption should be handled here using bag only.
  }
  else if ($kind === 'throw') {
    $item_id = trim((string)($payload['item_id'] ?? ''));
    if ($item_id === '') {
      $pdo->rollBack();
      return ['ok'=>false,'error'=>'item_id_required'];
    }
    $qty = (int)($throwMap[$item_id] ?? 0);
    if ($qty > 0) {
      $throwMap[$item_id] = $qty - 1;
      if ($throwMap[$item_id] <= 0) unset($throwMap[$item_id]);
      $log[] = "throw_from_throw_json {$item_id}";
    } else {
      $bq = (int)($bag['stacks'][$item_id] ?? 0);
      if ($bq <= 0) {
        $pdo->rollBack();
        return ['ok'=>false,'error'=>'no_throw_item'];
      }
      $bag['stacks'][$item_id] = $bq - 1;
      if ($bag['stacks'][$item_id] <= 0) unset($bag['stacks'][$item_id]);
      $log[] = "throw_from_bag {$item_id}";
    }

    // damage based on item stats if present
    $row = _item_row($pdo, $item_id);
    $dmg = _roll_damage_from_item($row, 20, 45);
    $log[] = "throw dmg={$dmg}";
  }
  else {
    $pdo->rollBack();
    return ['ok'=>false,'error'=>'bad_kind'];
  }

  // apply
  $hp1 = max(0, $hp - $dmg);
  $enc['hp'] = $hp1;
  $log[] = "hp {$hp} -> {$hp1}";

  if ($hp1 <= 0) {
    $enc['hp'] = 0;
    $enc['dead'] = 1;
    $enc['loot_state'] = 'pending';
    $loot = _gen_loot($pdo, $enc);
    $enc['loot'] = ['stacks'=>_norm_map_qty($loot['stacks'] ?? [])];
    $log[] = 'dead_confirmed';
    $log[] = 'loot_pending_created';
  } else {
    $enc['dead'] = 0;
    // do not mutate loot_state here
  }

  $brought['encounter'] = $enc;

  $pdo->prepare("UPDATE escape_raid_state SET brought_json=?, inventory_json=?, throw_json=? WHERE user_key=? LIMIT 1")
    ->execute([
      _json_encode($brought),
      _json_encode(_bag_encode($bag)),
      _json_encode($throwMap),
      $user_key
    ]);

  $pdo->commit();
  return ['ok'=>true,'kind'=>$kind,'log_lines'=>$log,'encounter'=>$enc];
}
