<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/_lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

const DEBUG = 0;

$user_key = trim((string)($_POST['user_key'] ?? ''));
$item_id  = trim((string)($_POST['item_id'] ?? ''));

if ($user_key === '') fail('user_key required', 400);
if ($item_id === '')  fail('item_id required', 400);

try {
  $pdo->beginTransaction();

  $rs = raid_state_lock($pdo, $user_key);
  if (!$rs) { $pdo->rollBack(); fail('no_raid_state', 404); }
  if ((string)$rs['status'] !== 'in_raid') { $pdo->rollBack(); fail('not_in_raid', 409); }

  // 레이드 상태 json
  $throw = json_arr($rs['throw_json'] ?? '{}', []); // 키 제한 제거
  // throw_json 정규화: list/map 혼재 -> map({item_id:qty})
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
  } else {
    foreach ($raw as $k=>$v) {
      if ($k === 'items' || $k === 'list') continue;
      $iid = trim((string)$k);
      $qq  = (int)$v;
      if ($iid === '' || $qq <= 0) continue;
      $throwMap[$iid] = ($throwMap[$iid] ?? 0) + $qq;
    }
  }
  $throw = $throwMap;
  $inv   = raid_inv_get($rs);

  // ---- 1) 아이템 메타 기반 "투척 가능" 판정 ----
  // escape_items: (item_id, type, stats_json) 구조를 가정하지 않고, 실제 스키마에 맞춰 최소만 사용
  // 여기서는 item_id 존재 + (type이 throw/throwable 이거나 stats_json.throwable=true)면 허용
  $st = $pdo->prepare("SELECT item_id, type, stats_json FROM escape_items WHERE item_id=? LIMIT 1");
  $st->execute([$item_id]);
  $it = $st->fetch(PDO::FETCH_ASSOC);

  if (!$it) { $pdo->rollBack(); fail('unknown_item', 404, ['item_id'=>$item_id]); }

  $type = (string)($it['type'] ?? '');
  $stats = json_arr($it['stats_json'] ?? '{}', []);
  $is_throwable =
      ($type === 'throw' || $type === 'throwable' || $type === 'grenade')
      || (!empty($stats['throwable']) && (bool)$stats['throwable'] === true);

  if (!$is_throwable) {
    $pdo->rollBack();
    fail('not_throwable', 409, ['item_id'=>$item_id, 'type'=>$type]);
  }

  // ---- 2) throw_json 우선 소모 ----
  $t = (int)($throw[$item_id] ?? 0);
  if ($t > 0) {
    $throw[$item_id] = $t - 1;
    if ($throw[$item_id] <= 0) unset($throw[$item_id]);

    $pdo->prepare("UPDATE escape_raid_state SET throw_json=? WHERE user_key=?")
        ->execute([json_str($throw), $user_key]);

    $pdo->commit();
    echo j(['ok'=>true,'used_from'=>'throw','item_id'=>$item_id,'throw'=>$throw,'inventory'=>$inv]);
    exit;
  }

  // ---- 3) raid bag(inventory_json)에서 소모 ----
  $q = (int)($inv[$item_id] ?? 0);
  if ($q <= 0) { $pdo->rollBack(); fail('no_throw_item', 409, ['item_id'=>$item_id]); }

  $inv[$item_id] = $q - 1;
  if ($inv[$item_id] <= 0) unset($inv[$item_id]);

  raid_inv_set($pdo, $user_key, $inv);

  $pdo->commit();
  echo j(['ok'=>true,'used_from'=>'raid_bag','item_id'=>$item_id,'throw'=>$throw,'inventory'=>$inv]);
  exit;

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  http_response_code(500);
  echo DEBUG ? j(['ok'=>false,'error'=>'server_error','msg'=>$e->getMessage()]) : j(['ok'=>false,'error'=>'server_error']);
  exit;
}
