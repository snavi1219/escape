<?php
declare(strict_types=1);

/**
 * api/combat_attack.php
 * SINGLE authoritative combat endpoint.
 *
 * POST: user_key, kind(melee|gun|throw), (throw:item_id)
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/_lib.php';
require_once __DIR__ . '/_combat_core.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$user_key = trim((string)($_POST['user_key'] ?? ''));
$kind     = trim((string)($_POST['kind'] ?? ''));

if ($user_key === '') fail('user_key required', 400);
if ($kind === '') fail('kind required', 400);

$kind = strtolower($kind);
if (!in_array($kind, ['melee','gun','throw'], true)) {
  fail('invalid_kind', 400);
}

try {
  $out = combat_execute($pdo, $user_key, $kind, $_POST);
  echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'server_error','message'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
