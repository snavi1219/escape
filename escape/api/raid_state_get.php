<?php
declare(strict_types=1);

/**
 * api/raid_state_get.php
 *
 * Returns raid state with UI-stable normalized JSON.
 *
 * - inventory_json: always exposes {stacks:map, items:list}
 * - throw_json: always exposes map {item_id:qty}
 * - brought.encounter: if dead and loot_state pending, loot... must be {stacks:map}
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/_lib.php';
require_once __DIR__ . '/_combat_core.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$user_key = trim((string)($_GET['user_key'] ?? $_POST['user_key'] ?? ''));
if ($user_key === '') fail('user_key required', 400);

try {
  $st = $pdo->prepare("SELECT user_key,status,inventory_json,throw_json,brought_json FROM escape_raid_state WHERE user_key=? LIMIT 1");
  $st->execute([$user_key]);
  $rs = $st->fetch(PDO::FETCH_ASSOC);
  if (!$rs) fail('no_raid_state', 404);
  if ((string)($rs['status'] ?? '') !== 'in_raid') fail('not_in_raid', 409);

  $bag = _bag_decode(_json_decode_array((string)($rs['inventory_json'] ?? '')));
  $throwMap = _norm_map_qty(_json_decode_array((string)($rs['throw_json'] ?? '')));
  $brought = _json_decode_array((string)($rs['brought_json'] ?? ''));

  // --- normalize encounter loot if pending ---
  $touched = false;
  if (isset($brought['encounter']) && is_array($brought['encounter'])) {
    $e =& $brought['encounter'];
    $hp = (int)($e['hp'] ?? 0);
    $dead = (int)($e['dead'] ?? 0);
    $ls = (string)($e['loot_state'] ?? '');

    if (($dead === 1 || $hp <= 0) && $ls === 'pending') {
      $loot = is_array($e['loot'] ?? null) ? $e['loot'] : [];
      $stacks = _norm_map_qty($loot['stacks'] ?? []);
      if (!$stacks) {
        $loot = _gen_loot($pdo, $e);
        $stacks = _norm_map_qty($loot['stacks'] ?? []);
        $touched = true;
      }
      $e['hp'] = 0;
      $e['dead'] = 1;
      $e['loot_state'] = 'pending';
      $e['loot'] = ['stacks'=>$stacks];
    }

    // dead and NOT pending => don't allow sticky dead encounter
    if (($dead === 1 || $hp <= 0) && $ls !== 'pending') {
      unset($brought['encounter']);
      $touched = true;
    }
  }

  if ($touched) {
    $pdo->prepare("UPDATE escape_raid_state SET brought_json=? WHERE user_key=? LIMIT 1")
      ->execute([_json_encode($brought), $user_key]);
  }

  echo json_encode([
    'ok'=>true,
    'status'=>(string)$rs['status'],
    'inventory'=>_bag_encode($bag),
    'throw'=>$throwMap,
    'brought'=>$brought,
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'server_error','message'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
