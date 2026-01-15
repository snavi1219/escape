<?php
// /escape/api/raid_state_get.php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/raid_core.php';

$user_key = require_user_key();
$rs = raid_state_require_in_raid($pdo, $user_key);

$bag = bag_decode($rs['inventory_json'] ?? null);
$throw = json_decode((string)($rs['throw_json'] ?? '{}'), true);
$brought = json_decode((string)($rs['brought_json'] ?? '{}'), true);
if (!is_array($throw)) $throw = [];
if (!is_array($brought)) $brought = [];

/* ---------------------------------------------------------
 * UI 호환 보정
 * - inventory_json(bag) 원본은 {stack:[{item_id,qty}], inst:[{instance_id}]} 구조
 *   app_ui_min.js는 bag.stacks(map) / bag.items(list)를 우선적으로 탐색하므로,
 *   여기서 파생 필드를 추가한다.
 * - brought.encounter가 dead + loot_state=pending인데 loot가 비어있으면
 *   최소 전리품(thr_stone)을 강제 주입하고 DB에도 반영한다.
 * --------------------------------------------------------- */

// 1) bag.stack(list) -> bag.stacks(map) + bag.items(list)
$stacks = [];
$items = [];
if (isset($bag['stack']) && is_array($bag['stack'])) {
  foreach ($bag['stack'] as $row) {
    if (!is_array($row)) continue;
    $iid = trim((string)($row['item_id'] ?? ''));
    $qty = (int)($row['qty'] ?? 0);
    if ($iid === '' || $qty <= 0) continue;
    $stacks[$iid] = ($stacks[$iid] ?? 0) + $qty;

    // 이름/타입 제공(투척 후보 탐지용)
    $it = item_get($pdo, $iid);
    $items[] = [
      'item_id' => $iid,
      'qty' => $qty,
      'name' => $it['name'] ?? null,
      'type' => $it['type'] ?? null,
      'rarity' => $it['rarity'] ?? null,
    ];
  }
}
$bag['stacks'] = $stacks;
$bag['items'] = $items;

$touchedThrow = false;

// 2) throw_json(list/map 혼재) -> map({item_id:qty})로 정규화
// - list: [{item_id,qty}, ...]
// - map: {item_id: qty, ...}
// - 일부 코드가 {list:[...]} 형태로 넣었을 가능성도 허용
$throwMap = [];
$raw = $throw;
if (isset($raw['list']) && is_array($raw['list'])) $raw = $raw['list'];
$keys = array_keys($raw);
$isList = ($keys === range(0, count($keys)-1));
if ($isList) {
  foreach ($raw as $row) {
    if (!is_array($row)) continue;
    $iid = trim((string)($row['item_id'] ?? ''));
    $qq  = (int)($row['qty'] ?? 0);
    if ($iid === '' || $qq <= 0) continue;
    $throwMap[$iid] = ($throwMap[$iid] ?? 0) + $qq;
  }
  $touchedThrow = true;
} else {
  foreach ($raw as $k => $v) {
    if ($k === 'items' || $k === 'list') continue;
    $iid = trim((string)$k);
    $qq  = (int)$v;
    if ($iid === '' || $qq <= 0) continue;
    $throwMap[$iid] = ($throwMap[$iid] ?? 0) + $qq;
  }
  // throw_json이 map이라도 값이 숫자형이 아닌 경우가 있어 정규화 저장
  if ($throwMap !== $throw) $touchedThrow = true;
}

$throw = $throwMap;

// 3) dead + pending인데 loot가 비어있으면 최소 전리품 보장
$touchedBrought = false;
if (isset($brought['encounter']) && is_array($brought['encounter'])) {
  $e =& $brought['encounter'];
  $hp = (int)($e['hp'] ?? 0);
  $dead = (int)($e['dead'] ?? 0);
  $ls = (string)($e['loot_state'] ?? '');

  if (($dead === 1 || $hp <= 0) && $ls === 'pending') {
    if (!isset($e['loot']) || !is_array($e['loot'])) {
      $e['loot'] = [];
      $touchedBrought = true;
    }

    // loot.stacks(map) 형태 보장
    if (!isset($e['loot']['stacks']) || !is_array($e['loot']['stacks']) || count($e['loot']['stacks']) === 0) {
      $e['loot']['stacks'] = ['thr_stone' => random_int(1, 2)];
      $touchedBrought = true;
    }

    // 호환용: loot.stack(list)도 같이 채워둠(loot_take가 stack(list)를 우선 쓰는 환경 대비)
    if (!isset($e['loot']['stack']) || !is_array($e['loot']['stack']) || count($e['loot']['stack']) === 0) {
      $tmp = [];
      foreach ($e['loot']['stacks'] as $iid => $qq) {
        $iid = trim((string)$iid);
        $qq  = (int)$qq;
        if ($iid === '' || $qq <= 0) continue;
        $tmp[] = ['item_id'=>$iid, 'qty'=>$qq];
      }
      $e['loot']['stack'] = $tmp;
      $touchedBrought = true;
    }

    if (!isset($e['loot']['inst']) || !is_array($e['loot']['inst'])) {
      $e['loot']['inst'] = [];
      $touchedBrought = true;
    }
  }
}

if ($touchedBrought || $touchedThrow) {
  $sqlParts = [];
  $params = [];
  if ($touchedBrought) {
    $sqlParts[] = "brought_json=?";
    $params[] = json_encode($brought, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  }
  if ($touchedThrow) {
    $sqlParts[] = "throw_json=?";
    $params[] = json_encode($throw, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  }
  $params[] = $user_key;
  $pdo->prepare("UPDATE escape_raid_state SET ".implode(',', $sqlParts)." WHERE user_key=? LIMIT 1")
    ->execute($params);
}

$instDetails = [];
foreach ($bag['inst'] as $row) {
  $iid = (string)($row['instance_id'] ?? '');
  if ($iid === '') continue;

  $inst = instance_get_owned($pdo, $user_key, $iid);
  if (!$inst) continue;

  $item = item_get($pdo, (string)$inst['item_id']);
  $stats = $item ? item_stats($item) : [];

  $instDetails[] = [
    'instance_id' => $iid,
    'item_id' => (string)$inst['item_id'],
    'name' => $item['name'] ?? null,
    'type' => $item['type'] ?? null,
    'rarity' => $item['rarity'] ?? null,
    'durability' => (int)$inst['durability'],
    'durability_max' => (int)$inst['durability_max'],
    'ammo_type' => $inst['ammo_type'],
    'ammo_in_mag' => $inst['ammo_in_mag'],
    'mag_size' => $inst['mag_size'],
    'tier' => (int)($stats['tier'] ?? 0),
    'def'  => (int)($stats['def'] ?? 0),
    'dmg'  => (int)($stats['dmg'] ?? 0),
  ];
}

json_out([
  'ok'=>true,
  'status'=>$rs['status'],
  'inventory'=>$bag,
  'inventory_instances'=>$instDetails,
  'throw'=>$throw,
  'brought'=>$brought
]);
