<?php
declare(strict_types=1);

/**
 * api/encounter_next.php
 *
 * Encounter creation/retention policy:
 * - If existing encounter is alive => reuse
 * - If dead and loot_state=pending => keep (force player to loot/skip)
 * - If dead and not pending => remove and spawn new
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/_lib.php';
require_once __DIR__ . '/_combat_core.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$user_key = trim((string)($_POST['user_key'] ?? $_GET['user_key'] ?? ''));
if ($user_key === '') fail('user_key required', 400);

try {
  $pdo->beginTransaction();

  $st = $pdo->prepare("SELECT user_key,status,brought_json,inventory_json,throw_json FROM escape_raid_state WHERE user_key=? LIMIT 1 FOR UPDATE");
  $st->execute([$user_key]);
  $rs = $st->fetch(PDO::FETCH_ASSOC);

  if (!$rs) { $pdo->rollBack(); fail('no_raid_state', 404); }
  if ((string)($rs['status'] ?? '') !== 'in_raid') { $pdo->rollBack(); fail('not_in_raid', 409); }

  $brought = _json_decode_array((string)($rs['brought_json'] ?? ''));
  $enc = (isset($brought['encounter']) && is_array($brought['encounter'])) ? $brought['encounter'] : null;

  if ($enc) {
    $hp = (int)($enc['hp'] ?? 0);
    $dead = (int)($enc['dead'] ?? 0);
    $ls = (string)($enc['loot_state'] ?? 'none');

    if (($dead === 0) && ($hp > 0)) {
      $pdo->commit();
      echo json_encode(['ok'=>true,'reused'=>1,'encounter'=>$enc], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
      exit;
    }

    if (($dead === 1 || $hp <= 0) && $ls === 'pending') {
      // keep encounter until loot taken/skipped
      $pdo->commit();
      echo json_encode(['ok'=>true,'reused'=>1,'loot_pending'=>1,'encounter'=>$enc], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
      exit;
    }

    // dead but not pending => cleanup, then spawn new
    unset($brought['encounter']);
  }

  // Spawn a minimal encounter (content/tiers can be expanded later)
  $roll = random_int(1, 100);
  $faction = ($roll <= 70) ? 'zombie' : (($roll <= 92) ? 'scav' : 'pmc');

  $baseHp = ($faction === 'zombie') ? random_int(18, 30) : (($faction === 'scav') ? random_int(24, 38) : random_int(30, 48));
  $lootTier = ($faction === 'pmc') ? 3 : (($faction === 'scav') ? 2 : 1);
  $name = ($faction === 'zombie') ? 'Zombie' : (($faction === 'scav') ? 'Scav' : 'PMC');

  $enc = [
    'name' => $name,
    'faction' => $faction,
    'hp' => $baseHp,
    'hp_max' => $baseHp,
    'lootTier' => $lootTier,
    'dead' => 0,
    'loot_state' => 'none',
    'spawn_ts' => time(),
  ];

  $brought['encounter'] = $enc;

  $pdo->prepare("UPDATE escape_raid_state SET brought_json=? WHERE user_key=? LIMIT 1")
    ->execute([_json_encode($brought), $user_key]);

  $pdo->commit();

  echo json_encode(['ok'=>true,'spawned'=>1,'encounter'=>$enc], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'server_error','message'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
