<?php
declare(strict_types=1);

/**
 * 공통 유틸 (PHP 7+)
 */

if (!function_exists('j')) {
  function j(array $a): string {
    $json = json_encode(
      $a,
      JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );
    if ($json === false) {
      // 최후의 안전망
      return '{"ok":false,"error":"json_encode_failed"}';
    }
    return $json;
  }
}

function fail(string $code, int $http=400, array $extra=[]): void {
  http_response_code($http);
  echo j(array_merge(['ok'=>false,'error'=>$code], $extra));
  exit;
}

function json_arr($s, array $fallback=[]): array {
  $a = json_decode((string)$s, true);
  return is_array($a) ? $a : $fallback;
}
function json_str(array $a): string {
  return json_encode($a, JSON_UNESCAPED_UNICODE);
}

function item_row(PDO $pdo, string $item_id): ?array {
  $st = $pdo->prepare("SELECT item_id, name, type, rarity, stats_json FROM escape_items WHERE item_id=? LIMIT 1");
  $st->execute([$item_id]);
  $r = $st->fetch(PDO::FETCH_ASSOC);
  return $r ?: null;
}
function item_stats(array $itemRow): array {
  $st = json_decode((string)($itemRow['stats_json'] ?? '{}'), true);
  return is_array($st) ? $st : [];
}

function is_stack_item(array $itemRow): bool {
  $st = item_stats($itemRow);
  return !empty($st['stack']);
}
function is_instance_item(array $itemRow): bool {
  $type = (string)($itemRow['type'] ?? '');
  return in_array($type, ['melee','armor','pistol','rifle'], true);
}

/**
 * 티어별 내구도 최소 보장(기본 룰)
 * - 아이템 stats_json에 durability_min/max가 있으면 우선 사용
 * - 없으면 tier 기반 기본값
 */
function tier_durability_floor(int $tier): int {
  $tier = max(1, min(5, $tier));
  $map = [1=>8, 2=>16, 3=>24, 4=>34, 5=>46];
  return $map[$tier];
}
function roll_durability(array $itemRow): array {
  $st = item_stats($itemRow);
  $tier = (int)($st['tier'] ?? 1);
  $min = (int)($st['durability_min'] ?? 0);
  $max = (int)($st['durability_max'] ?? 0);

  $floor = tier_durability_floor($tier);
  if ($min <= 0) $min = $floor;
  if ($max <= 0) $max = $min + (int)max(6, $tier*6);

  if ($max < $min) $max = $min;
  $dur = random_int($min, $max);
  return [$dur, $max, $tier];
}

/** STASH(스택형) 증감 */
function stash_add(PDO $pdo, string $user_key, string $item_id, int $n): void {
  if ($n <= 0) return;
  $pdo->prepare("INSERT INTO escape_user_stash(user_key, item_id, qty) VALUES(?,?,?)
                 ON DUPLICATE KEY UPDATE qty=qty+VALUES(qty)")
      ->execute([$user_key, $item_id, $n]);
}
function stash_get(PDO $pdo, string $user_key, string $item_id): int {
  $st = $pdo->prepare("SELECT qty FROM escape_user_stash WHERE user_key=? AND item_id=? LIMIT 1");
  $st->execute([$user_key, $item_id]);
  return (int)($st->fetchColumn() ?: 0);
}
function stash_dec(PDO $pdo, string $user_key, string $item_id, int $n): void {
  if ($n <= 0) return;
  $st = $pdo->prepare("UPDATE escape_user_stash SET qty=qty-? WHERE user_key=? AND item_id=? AND qty>=?");
  $st->execute([$n, $user_key, $item_id, $n]);
  if ($st->rowCount() !== 1) throw new RuntimeException("stash_not_enough: {$item_id} need {$n}");
}

/** 인스턴스(내구도 아이템) 생성/이동/소모 */
function inst_create(PDO $pdo, string $user_key, string $item_id, string $location): int {
  $row = item_row($pdo, $item_id);
  if (!$row) throw new RuntimeException("no_item: {$item_id}");
  [$dur, $durMax] = roll_durability($row);

  $st = $pdo->prepare("INSERT INTO escape_user_item_instances(user_key,item_id,location,durability,durability_max)
                       VALUES(?,?,?,?,?)");
  $st->execute([$user_key, $item_id, $location, $dur, $durMax]);
  return (int)$pdo->lastInsertId();
}

/** location에서 item_id 인스턴스 1개 선택(기본: 내구도 높은 것) */
function inst_pick(PDO $pdo, string $user_key, string $item_id, string $location): ?array {
  $st = $pdo->prepare("
    SELECT id, item_id, durability, durability_max
    FROM escape_user_item_instances
    WHERE user_key=? AND item_id=? AND location=?
    ORDER BY durability DESC, id ASC
    LIMIT 1
  ");
  $st->execute([$user_key, $item_id, $location]);
  $r = $st->fetch(PDO::FETCH_ASSOC);
  return $r ?: null;
}
function inst_move(PDO $pdo, int $id, string $toLocation): void {
  $st = $pdo->prepare("UPDATE escape_user_item_instances SET location=? WHERE id=?");
  $st->execute([$toLocation, $id]);
  if ($st->rowCount() !== 1) throw new RuntimeException("inst_move_failed");
}
function inst_dec_dur(PDO $pdo, int $id, int $n): array {
  $n = max(1, $n);
  $st = $pdo->prepare("SELECT durability, durability_max FROM escape_user_item_instances WHERE id=? LIMIT 1 FOR UPDATE");
  $st->execute([$id]);
  $r = $st->fetch(PDO::FETCH_ASSOC);
  if (!$r) throw new RuntimeException("inst_not_found");

  $dur = (int)$r['durability'];
  $durMax = (int)$r['durability_max'];
  $dur2 = max(0, $dur - $n);

  $pdo->prepare("UPDATE escape_user_item_instances SET durability=? WHERE id=?")->execute([$dur2, $id]);

  $broken = ($dur2 <= 0);
  if ($broken) {
    $pdo->prepare("DELETE FROM escape_user_item_instances WHERE id=?")->execute([$id]);
  }
  return ['durability'=>$dur2, 'durability_max'=>$durMax, 'broken'=>$broken];
}

/** 레이드 state 락 */
function raid_state_lock(PDO $pdo, string $user_key): array {
  $st = $pdo->prepare("SELECT status, inventory_json, throw_json, brought_json, inv_instances_json, armor_json
                       FROM escape_raid_state WHERE user_key=? LIMIT 1 FOR UPDATE");
  $st->execute([$user_key]);
  $rs = $st->fetch(PDO::FETCH_ASSOC);
  return $rs ?: [];
}

/** 레이드 inventory_json은 스택형 qty */
function raid_inv_get(array $rs): array {
  return json_arr($rs['inventory_json'] ?? '{}', []);
}
function raid_inv_set(PDO $pdo, string $user_key, array $inv): void {
  $pdo->prepare("UPDATE escape_raid_state SET inventory_json=? WHERE user_key=?")
      ->execute([json_str($inv), $user_key]);
}

/** 레이드 내 인스턴스 추적(선택 사항, UI용) */
function raid_inst_get(array $rs): array {
  return json_arr($rs['inv_instances_json'] ?? '{}', []);
}
function raid_inst_set(PDO $pdo, string $user_key, array $m): void {
  $pdo->prepare("UPDATE escape_raid_state SET inv_instances_json=? WHERE user_key=?")
      ->execute([json_str($m), $user_key]);
}

/** armor_json (현재 방어구 내구도/티어 표시용) */
function raid_armor_get(array $rs): array {
  return json_arr($rs['armor_json'] ?? '{}', []);
}
function raid_armor_set(PDO $pdo, string $user_key, array $a): void {
  $pdo->prepare("UPDATE escape_raid_state SET armor_json=? WHERE user_key=?")
      ->execute([json_str($a), $user_key]);
}
