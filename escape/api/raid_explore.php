<?php
declare(strict_types=1);

/**
 * api/raid_explore.php
 * 입력: user_key (GET/POST)
 * 출력: { ok, kind, event_id, title, text_html, choices[], token, state_patch? }
 *
 * 요구사항:
 * - 토큰은 b64url(payloadJson) + b64url(binary HMAC)로 통일
 * - choices[].id는 raid_choice.php 분기와 완전 동일
 * - text_html은 typing div 형태(자동 타이핑)
 */

const DEBUG = 1; // 운영이면 0 권장

/* =========================================================
 * JSON-only Error Guard (MUST be before any require/output)
 *  - PHP Warning/Notice/Fatal/Parse/Compile 등을 모두 JSON으로 변환
 * ========================================================= */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

ob_start();

function _json_fail(string $code, string $msg = '', int $http = 500): void {
  while (ob_get_level() > 0) ob_end_clean();
  http_response_code($http);
  echo json_encode([
    'ok'    => false,
    'error' => $code,
    'msg'   => $msg,
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

set_error_handler(function($severity, $message, $file, $line) {
  // WARNING/NOTICE도 원인 추적에 중요하니 JSON으로 강제 전환
  _json_fail('php_error', "{$message} @ {$file}:{$line}", 500);
});

register_shutdown_function(function() {
  $e = error_get_last();
  if (!$e) return;
  $type = $e['type'] ?? 0;
  if (in_array($type, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
    _json_fail('php_fatal', ($e['message'] ?? '') . " @ " . ($e['file'] ?? '') . ":" . ($e['line'] ?? 0), 500);
  }
});

/* =========================================================
 * Requires
 * ========================================================= */
require_once __DIR__ . '/db.php'; // $pdo 필요

if (!isset($pdo) || !($pdo instanceof PDO)) {
  _json_fail('pdo_missing', 'db.php did not provide $pdo(PDO).', 500);
}

/* =========================================================
 * Helpers
 * ========================================================= */
function jexit(array $out, int $code = 200): void {
  while (ob_get_level() > 0) ob_end_clean();
  http_response_code($code);
  echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function get_param(string $k): string {
  $v = $_POST[$k] ?? $_GET[$k] ?? '';
  return trim((string)$v);
}

function b64url_encode(string $bin): string {
  return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}
function b64url_decode(string $s): string {
  $s = strtr($s, '-_', '+/');
  $pad = strlen($s) % 4;
  if ($pad) $s .= str_repeat('=', 4 - $pad);
  $out = base64_decode($s, true);
  return $out === false ? '' : $out;
}

function server_secret(): string {
  $s = (string)($_ENV['ESCAPE_SERVER_SECRET'] ?? getenv('ESCAPE_SERVER_SECRET') ?? '');
  // 운영에서는 반드시 환경변수 설정 권장
  return $s !== '' ? $s : 'CHANGE_ME_SERVER_SECRET';
}

/**
 * token = payloadB64url(payloadJson) . "." . sigB64url(binary_hmac_sha256(payloadB64url))
 * - raid_choice.php의 verify_token()과 동일 규격이어야 함
 */
function sign_token(array $payload): string {
  $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if ($json === false) $json = '{}';

  $p1 = b64url_encode($json);
  $sigBin = hash_hmac('sha256', $p1, server_secret(), true);
  $p2 = b64url_encode($sigBin);
  return $p1 . '.' . $p2;
}

function html_typewriter(string $text): string {
  $safe = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
  return '<div class="typing" data-typing="1" data-text="'.$safe.'"></div>';
}

/* =========================================================
 * Main
 * ========================================================= */
$user_key = get_param('user_key');
if ($user_key === '') jexit(['ok'=>false,'error'=>'user_key_required'], 400);

try {
  // 1) 유저 존재 확인
  $st = $pdo->prepare("SELECT user_key, name FROM escape_users WHERE user_key=? LIMIT 1");
  $st->execute([$user_key]);
  $user = $st->fetch(PDO::FETCH_ASSOC);
  if (!$user) jexit(['ok'=>false,'error'=>'user_not_found'], 404);

  // 2) 레이드 상태 확인
  $st = $pdo->prepare("SELECT user_key, status, brought_json FROM escape_raid_state WHERE user_key=? LIMIT 1");
  $st->execute([$user_key]);
  $raid = $st->fetch(PDO::FETCH_ASSOC);

  if (!$raid) {
    jexit(['ok'=>false,'error'=>'raid_state_missing','hint'=>'create/start raid first'], 409);
  }
  if (($raid['status'] ?? '') !== 'in_raid') {
    jexit(['ok'=>false,'error'=>'not_in_raid','hint'=>'status is not in_raid'], 409);
  }

  // ✅ 사망 조우가 남아 있으면(loot_state=pending이 아닌 경우) 루팅 가능 상태로 보정
  $brought = json_decode((string)($raid['brought_json'] ?? '{}'), true);
  if (!is_array($brought)) $brought = [];
  $changed = false;

  if (!empty($brought['encounter']) && is_array($brought['encounter'])) {
    $e =& $brought['encounter'];
    $hp = (int)($e['hp'] ?? 0);
    $dead = (int)($e['dead'] ?? 0);
    $ls = (string)($e['loot_state'] ?? '');

    if (($dead === 1 || $hp <= 0) && $ls !== 'pending') {
      $e['hp'] = 0;
      $e['dead'] = 1;

      $loot = $e['loot'] ?? null;
      if (!is_array($loot)) $loot = [];
      $stacks = $loot['stacks'] ?? null;
      if (!is_array($stacks)) $stacks = [];

      if (empty($stacks)) {
        $tier = (int)($brought['tier'] ?? 1);
        if ($tier < 1) $tier = 1;
        if ($tier > 5) $tier = 5;

        $stacks['thr_stone'] = random_int(1, 2);
        if (random_int(1, 100) <= (35 + $tier * 8)) {
          $stacks['ammo_9mm_t1'] = random_int(6, 10 + $tier * 3);
        }
        if (random_int(1, 100) <= 15) {
          $stacks['med_bandage_t1'] = 1;
        }
      }

      $loot['stacks'] = $stacks;
      if (empty($loot['stack']) || !is_array($loot['stack'])) {
        $loot['stack'] = [];
        foreach ($stacks as $iid => $q) {
          $loot['stack'][] = ['item_id' => (string)$iid, 'qty' => (int)$q];
        }
      }
      if (!isset($loot['inst']) || !is_array($loot['inst'])) $loot['inst'] = [];

      $e['loot'] = $loot;
      $e['loot_state'] = 'pending';
      $changed = true;
    }
  }

  if ($changed) {
    $st = $pdo->prepare("UPDATE escape_raid_state SET brought_json=? WHERE user_key=? LIMIT 1");
    $st->execute([json_encode($brought, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), $user_key]);
  }


  // 3) 이벤트 풀 (raid_choice.php의 event_id/choice_id 분기와 반드시 일치)
  $events = [
    [
      'id' => 'smoke_signal',
      'kind' => 'event',
      'title' => '연기 신호',
      'text'  => "멀리서 연기 신호가 보인다.\n누군가 살아있을지도 모른다. 하지만 함정일 수도 있다.",
      'choices' => [
        ['id'=>'approach','label'=>'1) 접근한다'],
        ['id'=>'ignore','label'=>'2) 무시한다'],
      ],
    ],
    [
      'id' => 'rustle',
      'kind' => 'event',
      'title' => '수풀 소리',
      'text'  => "가까운 수풀에서 바스락거리는 소리가 난다.\n무언가가 움직인다.",
      'choices' => [
        ['id'=>'investigate','label'=>'1) 확인한다'],
        ['id'=>'ignore','label'=>'2) 무시한다'],
      ],
    ],
    [
      'id' => 'tripwire',
      'kind' => 'trap',
      'title' => '와이어 트랩',
      'text'  => "발끝에 금속 와이어가 스친다.\n한 발 더 가면 작동할 것 같다.",
      'choices' => [
        ['id'=>'disarm','label'=>'1) 해체한다'],
        ['id'=>'avoid','label'=>'2) 우회한다'],
        ['id'=>'charge','label'=>'3) 강행 돌파한다'],
      ],
    ],
    [
      'id' => 'stash_cache',
      'kind' => 'loot',
      'title' => '임시 은닉처',
      'text'  => "벽 틈새에 숨겨진 작은 상자를 발견했다.\n열어볼까?",
      'choices' => [
        ['id'=>'loot','label'=>'1) 연다'],
        ['id'=>'leave','label'=>'2) 무시한다'],
      ],
    ],
  ];

  $ev = $events[random_int(0, count($events)-1)];

  // 4) 토큰 발급(서버 저장 없이 검증)
  $token = sign_token([
    't'   => time(),
    'n'   => bin2hex(random_bytes(8)),
    'uid' => $user_key,
    'e'   => $ev['id'],
  ]);

  jexit([
    'ok' => true,
    'kind' => $ev['kind'],
    'event_id' => $ev['id'],
    'title' => $ev['title'],
    'text_html' => html_typewriter($ev['text']),
    'choices' => $ev['choices'],
    'token' => $token,
    'state_patch' => ['turn_add' => 1],
  ]);

} catch (Throwable $e) {
  // 어떤 경우든 JSON으로 반환
  if (DEBUG) {
    _json_fail('server_error', $e->getMessage(), 500);
  }
  _json_fail('server_error', '', 500);
}
