<?php
// /escape/api/combat_attack.php
//
// 2순위(API 흐름 단일화) 준비용 라우터.
// - 현재: raid_melee_hit.php / raid_throw.php / combat_gun.php 등이 공존
// - 목표: 클라이언트는 이 파일 하나만 호출하고 kind로 분기

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$kind = trim((string)($_POST['kind'] ?? $_GET['kind'] ?? ''));

if ($kind === '') {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'kind_required'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

// Delegate to existing endpoints. They will handle validation and output/exit.
switch ($kind) {
  case 'melee':
    require __DIR__ . '/raid_melee_hit.php';
    break;
  case 'throw':
    require __DIR__ . '/raid_throw.php';
    break;
  case 'gun':
    require __DIR__ . '/combat_gun.php';
    break;
  default:
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'bad_kind','kind'=>$kind], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
