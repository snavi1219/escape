<?php
declare(strict_types=1);

/**
 * api/raid_loot_take.php
 *
 * Rules B:
 * - RAID BAG(inventory_json) is authoritative for gains/losses
 * - STASH not used during raid
 *
 * Standard:
 * - brought_json.encounter.loot = {stacks:{item_id:qty}}
 * - on successful take/skip => encounter removed (recommended)
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/_lib.php';
require_once __DIR__ . '/_combat_core.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$user_key = trim((string)($_POST['user_key'] ?? ''));
$action   = trim((string)($_POST['action'] ?? 'take')); // take|skip

if ($user_key === '') fail('user_key required', 400);
if (!in_array($action, ['take','skip'], true)) fail('bad_action', 400);

try {
  $pdo->beginTransaction();

  $st = $pdo->prepare("SELECT user_key,status,inventory_json,throw_json,brought_json FROM escape_raid_state WHERE user_key=? LIMIT 1 FOR UPDATE");
  $st->execute([$user_key]);
  $rs = $st->fetch(PDO::FETCH_ASSOC);

  if (!$rs) { $pdo->rollBack(); fail('no_raid_state', 404); }
  if ((string)($rs['status'] ?? '') !== 'in_raid') { $pdo->rollBack(); fail('not_in_raid', 409); }

  $bag = _bag_decode(_json_decode_array((string)($rs['inventory_json'] ?? '')));
  $throwMap = _norm_map_qty(_json_decode_array((string)($rs['throw_json'] ?? '')));
  $brought = _json_decode_array((string)($rs['brought_json'] ?? ''));

  if (empty($brought['encounter']) || !is_array($brought['encounter'])) {
    $pdo->rollBack();
    fail('no_encounter', 409);
  }

  $enc = $brought['encounter'];
  $hp = (int)($enc['hp'] ?? 0);
  $dead = (int)($enc['dead'] ?? 0);

  if ($dead === 0 && $hp > 0) {
    $pdo->rollBack();
    fail('encounter_not_dead', 409);
  }

  // loot must be standardized
  $loot = is_array($enc['loot'] ?? null) ? $enc['loot'] : [];
  $lootStacks = _norm_map_qty($loot['stacks'] ?? []);

  $added = [];
  if ($action === 'take' && $lootStacks) {
    foreach ($lootStacks as $iid => $qty) {
      $bag['stacks'][$iid] = ($bag['stacks'][$iid] ?? 0) + (int)$qty;
      $added[] = ['item_id'=>$iid,'qty'=>(int)$qty];
    }
  }

  // encounter cleanup policy (recommended)
  unset($brought['encounter']);

  $pdo->prepare("UPDATE escape_raid_state SET inventory_json=?, throw_json=?, brought_json=? WHERE user_key=? LIMIT 1")
    ->execute([
      _json_encode(_bag_encode($bag)),
      _json_encode($throwMap),
      _json_encode($brought),
      $user_key
    ]);

  $pdo->commit();

  echo json_encode([
    'ok'=>true,
    'action'=>$action,
    'ended'=>1,
    'added'=>$added,
    'inventory'=>_bag_encode($bag)
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'server_error','message'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
