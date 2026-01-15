<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/_lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

const DEBUG = 0;

$user_key = trim((string)($_GET['user_key'] ?? ''));
if ($user_key === '') fail('user_key required', 400);

try {
  // 유저 확인
  $st = $pdo->prepare("SELECT user_key, name FROM escape_users WHERE user_key=? LIMIT 1");
  $st->execute([$user_key]);
  $u = $st->fetch(PDO::FETCH_ASSOC);
  if (!$u) { echo j(['ok'=>false,'error'=>'no_user']); exit; }

  // ensure loadout + raid_state
  $pdo->prepare("INSERT IGNORE INTO escape_user_loadout(user_key) VALUES (?)")->execute([$user_key]);

  $emptyInv   = '{}';
  $emptyThrow = '{"thr_stone":0,"thr_ied":0,"thr_grenade":0}';
  $emptyBrought = '{"primary":null,"secondary":null,"melee":null,"armor":null}';
  // escape_raid_state 확정 스키마: (user_key, status, inventory_json, throw_json, brought_json)
  $pdo->prepare("
    INSERT IGNORE INTO escape_raid_state(user_key, status, inventory_json, throw_json, brought_json)
    VALUES (?, 'idle', ?, ?, ?)
  ")->execute([$user_key, $emptyInv, $emptyThrow, $emptyBrought]);

  // items master
  $items = $pdo->query("SELECT item_id, name, type, rarity, stats_json FROM escape_items ORDER BY type, rarity, name")
               ->fetchAll(PDO::FETCH_ASSOC);

  // stash (stack)
  $st = $pdo->prepare("SELECT item_id, qty FROM escape_user_stash WHERE user_key=? AND qty>0 ORDER BY item_id");
  $st->execute([$user_key]);
  $stashStack = $st->fetchAll(PDO::FETCH_ASSOC);

  // instances (stash/raid)
  $st = $pdo->prepare("
    SELECT id, item_id, location, durability, durability_max
    FROM escape_user_item_instances
    WHERE user_key=?
    ORDER BY location, item_id, durability DESC, id ASC
  ");
  $st->execute([$user_key]);
  $instances = $st->fetchAll(PDO::FETCH_ASSOC);

  // instances를 qty로 집계해서 stash에 합산(프론트 호환)
  $instCount = [];
  foreach ($instances as $it) {
    $loc = (string)$it['location'];
    if ($loc !== 'stash') continue;
    $id = (string)$it['item_id'];
    $instCount[$id] = ($instCount[$id] ?? 0) + 1;
  }
  $stashMap = [];
  foreach ($stashStack as $r) $stashMap[(string)$r['item_id']] = (int)$r['qty'];
  foreach ($instCount as $id=>$cnt) $stashMap[$id] = ($stashMap[$id] ?? 0) + $cnt;

  $stash = [];
  foreach ($stashMap as $id=>$qty) {
    if ($qty > 0) $stash[] = ['item_id'=>$id, 'qty'=>$qty];
  }
  usort($stash, function($a,$b){ return strcmp($a['item_id'],$b['item_id']); });

  // loadout
  // escape_user_loadout 확정 스키마: (user_key, primary_item, secondary_item, melee_item)
  $st = $pdo->prepare("SELECT primary_item, secondary_item, melee_item
                       FROM escape_user_loadout WHERE user_key=? LIMIT 1");
  $st->execute([$user_key]);
  $lo = $st->fetch(PDO::FETCH_ASSOC) ?: [
    'primary_item'=>null,'secondary_item'=>null,'melee_item'=>null
  ];

  // raid
  $st = $pdo->prepare("SELECT status, inventory_json, throw_json, brought_json
                       FROM escape_raid_state WHERE user_key=? LIMIT 1");
  $st->execute([$user_key]);
  $rs = $st->fetch(PDO::FETCH_ASSOC) ?: [
    'status'=>'idle',
    'inventory_json'=>'{}',
    'throw_json'=>$emptyThrow,
    'brought_json'=>$emptyBrought
  ];

  $raidInv = json_arr($rs['inventory_json'] ?? '{}', []);
  $throw   = json_arr($rs['throw_json'] ?? $emptyThrow, ['thr_stone'=>0,'thr_ied'=>0,'thr_grenade'=>0]);
  $brought = json_arr($rs['brought_json'] ?? $emptyBrought, ['primary'=>null,'secondary'=>null,'melee'=>null,'armor'=>null]);
  $armorJ  = [];

  echo j([
    'ok'=>true,
    'items'=>$items,
    'stash'=>$stash,
    'instances'=>$instances, // ✅ 다음 UI 단계 대비(내구도 표시 가능)
    'loadout'=>[
      'primary'=>$lo['primary_item'] ?: null,
      'secondary'=>$lo['secondary_item'] ?: null,
      'melee'=>$lo['melee_item'] ?: null,
      'armor'=>null,
    ],
    'raid'=>[
      'status'=>$rs['status'],
      'inventory'=>$raidInv,
      'throw'=>[
        'thr_stone'=>(int)($throw['thr_stone'] ?? 0),
        'thr_ied'=>(int)($throw['thr_ied'] ?? 0),
        'thr_grenade'=>(int)($throw['thr_grenade'] ?? 0),
      ],
      'brought'=>[
        'primary'=>$brought['primary'] ?? null,
        'secondary'=>$brought['secondary'] ?? null,
        'melee'=>$brought['melee'] ?? null,
        'armor'=>$brought['armor'] ?? null,
      ],
      'armor'=>$armorJ,
    ]
  ]);
  exit;

} catch (Throwable $e) {
  http_response_code(500);
  echo DEBUG ? j(['ok'=>false,'error'=>'server_error','msg'=>$e->getMessage()]) : j(['ok'=>false,'error'=>'server_error']);
  exit;
}
