<?php
declare(strict_types=1);

/**
 * /escape/api/raid_choice.php (전체 교체본 / JSON 강제 + invalid_json 방지)
 * - 토큰: raid_explore.php와 동일 (payloadB64url + "." + HMAC(payloadB64url))
 * - invalid_json 방지: output buffering + fatal/shutdown 핸들러로 500도 JSON
 *
 * IMPORTANT:
 * - 이 파일은 반드시 첫 글자가 "<?php" 여야 합니다. (BOM/공백/문자 금지)
 */

const DEBUG = 1; // 운영이면 0 권장

/* =========================================================
 * JSON-only Guard (require 보다 먼저)
 * ========================================================= */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (!ob_get_level()) ob_start();
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

function safe_json_out(array $out, int $code = 200): void {
  // 어떤 출력이든 싹 지우고 JSON만 반환
  while (ob_get_level() > 0) ob_end_clean();
  http_response_code($code);
  echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

set_error_handler(function(int $severity, string $message, string $file, int $line): bool {
  // warning/notice도 JSON으로 흡수 (필요시 DEBUG에만 노출)
  $msg = DEBUG ? "{$message} @ {$file}:{$line}" : '';
  safe_json_out(['ok'=>false,'error'=>'php_error','msg'=>$msg], 500);
  return true;
});

register_shutdown_function(function() {
  $e = error_get_last();
  if (!$e) return;
  $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
  if (!in_array((int)($e['type'] ?? 0), $fatalTypes, true)) return;

  $msg = DEBUG
    ? (($e['message'] ?? '') . " @ " . ($e['file'] ?? '') . ":" . (string)($e['line'] ?? 0))
    : '';
  safe_json_out(['ok'=>false,'error'=>'php_fatal','msg'=>$msg], 500);
});

/* =========================================================
 * Requires
 * ========================================================= */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/_lib.php';
require_once __DIR__ . '/lib/raid_core.php';

/** @var PDO $pdo */
if (!isset($pdo) || !($pdo instanceof PDO)) {
  safe_json_out(['ok'=>false,'error'=>'pdo_missing','msg'=> DEBUG ? 'db.php must provide $pdo (PDO)' : ''], 500);
}

/* =========================================================
 * Token helpers (explore와 동일)
 * ========================================================= */
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
  // ✅ explore/choice 동일 fallback 이어야 함 (둘이 다르면 bad_token)
  return $s !== '' ? $s : 'CHANGE_ME_SERVER_SECRET';
}
function verify_token(string $token): array {
  $token = trim($token);
  if ($token === '' || strpos($token, '.') === false) return [];
  [$p1, $p2] = explode('.', $token, 2);

  $payloadJson = b64url_decode($p1);
  $sigBin = b64url_decode($p2);
  if ($payloadJson === '' || $sigBin === '') return [];

  $expected = hash_hmac('sha256', $p1, server_secret(), true);
  if (!hash_equals($expected, $sigBin)) return [];

  $p = json_decode($payloadJson, true);
  return is_array($p) ? $p : [];
}

/* =========================================================
 * Helpers
 * ========================================================= */
function as_arr($v): array { return is_array($v) ? $v : []; }

function html_typewriter(string $text): string {
  $safe = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
  return '<div class="typing" data-typing="1" data-text="'.$safe.'"></div>';
}

function raid_state_lock_row(PDO $pdo, string $user_key): array {
  $st = $pdo->prepare("
    SELECT status, inventory_json, throw_json, brought_json
    FROM escape_raid_state
    WHERE user_key=?
    LIMIT 1
    FOR UPDATE
  ");
  $st->execute([$user_key]);
  $r = $st->fetch(PDO::FETCH_ASSOC);
  return $r ?: [];
}

function is_encounter_active(array $brought): bool {
  if (empty($brought['encounter']) || !is_array($brought['encounter'])) return false;
  $e = $brought['encounter'];
  $hp   = (int)($e['hp'] ?? 0);
  $dead = (int)($e['dead'] ?? 0);
  return ($dead === 0 && $hp > 0);
}

function spawn_encounter_force(PDO $pdo, array &$brought, int $tier, int $noise, string $reason=''): array {
  if (!empty($brought['encounter']) && is_array($brought['encounter'])) {
    $e = $brought['encounter'];
    $hp = (int)($e['hp'] ?? 0);
    $dead = (int)($e['dead'] ?? 0);
    $ls = (string)($e['loot_state'] ?? '');
    // 살아있는 조우(전투 중)만 유지. 사망했고 pending loot도 없으면 새 조우로 교체 가능하도록 비운다.
    if ($dead === 0 && $hp > 0) return $brought['encounter'];
    if ($dead === 1 && $ls === 'pending') return $brought['encounter'];
    unset($brought['encounter']);
  }

  $tier = max(1, min(5, $tier));

  if (!function_exists('npc_catalog') || !function_exists('npc_get')) {
    // 이게 뜨면 raid_core.php쪽 함수가 없는 상태라 500 원인임
    safe_json_out(['ok'=>false,'error'=>'missing_npc_funcs','msg'=> DEBUG ? 'npc_catalog()/npc_get() not found' : ''], 500);
  }

  $catalog = npc_catalog();
  $pool = [];
  foreach ($catalog as $npc) {
    if (!is_array($npc)) continue;
    if ((int)($npc['tier'] ?? 1) !== $tier) continue;
    $pool[] = $npc;
  }
  if (!$pool) {
    foreach ($catalog as $npc) if (is_array($npc)) $pool[] = $npc;
  }
  if (!$pool) {
    // catalog 자체가 비어있을 때
    $pool[] = ['id'=>'zombie_shambler','tier'=>$tier];
  }

  $pick = $pool[random_int(0, count($pool)-1)];
  $npc_type = (string)($pick['id'] ?? 'zombie_shambler');
  $npc = npc_get($npc_type);

  $hp = (int)($npc['hp'] ?? 20);
  if ($hp <= 0) $hp = 20;

  $encounter = [
    'type' => $npc_type,
    'name' => (string)($npc['name'] ?? 'Unknown'),
    'hp'   => $hp,
    'dead' => 0,
    'loot' => ['stacks'=>(object)[], 'stack'=>[], 'inst'=>[]],
    'loot_state' => 'none',
    'tier' => (int)($npc['tier'] ?? $tier),
    'lootTier' => (int)($npc['lootTier'] ?? $tier),
    'noise' => $noise,
    'reason' => $reason,
    'ts' => time(),
  ];

  $brought['encounter'] = $encounter;
  return $encounter;
}

/* =========================================================
 * Main
 * ========================================================= */

// require_user_key()가 _lib.php에 있어야 합니다.
if (!function_exists('require_user_key')) {
  safe_json_out(['ok'=>false,'error'=>'missing_require_user_key','msg'=> DEBUG ? 'require_user_key() not found in _lib.php' : ''], 500);
}

$user_key  = require_user_key();
$token     = trim((string)($_POST['token'] ?? ''));
$choice_id = trim((string)($_POST['choice_id'] ?? ''));

if ($token === '' || $choice_id === '') {
  safe_json_out(['ok'=>false,'error'=>'bad_request'], 400);
}

$payload = verify_token($token);
if (!$payload) {
  safe_json_out(['ok'=>false,'error'=>'bad_token'], 401);
}

$uid      = (string)($payload['uid'] ?? '');
$event_id = (string)($payload['e'] ?? '');
$t        = (int)($payload['t'] ?? 0);

if ($uid === '' || $event_id === '' || $uid !== $user_key) {
  safe_json_out(['ok'=>false,'error'=>'bad_token'], 401);
}
if ($t > 0 && (time() - $t) > 600) {
  safe_json_out(['ok'=>false,'error'=>'token_expired'], 401);
}

try {
  $pdo->beginTransaction();

  $rs = raid_state_lock_row($pdo, $user_key);
  if (!$rs) { $pdo->rollBack(); safe_json_out(['ok'=>false,'error'=>'no_raid_state'], 404); }
  if ((string)($rs['status'] ?? '') !== 'in_raid') { $pdo->rollBack(); safe_json_out(['ok'=>false,'error'=>'not_in_raid'], 409); }

  // raid_core.php에 bag_decode/raid_state_upsert 있어야 함
  if (!function_exists('bag_decode')) {
    throw new RuntimeException('bag_decode() not found (lib/raid_core.php)');
  }
  if (!function_exists('raid_state_upsert')) {
    throw new RuntimeException('raid_state_upsert() not found (lib/raid_core.php)');
  }

  $bag = bag_decode($rs['inventory_json'] ?? null);

  $throw = json_decode((string)($rs['throw_json'] ?? '{}'), true);
  if (!is_array($throw)) $throw = [];

  $brought = json_decode((string)($rs['brought_json'] ?? '{}'), true);
  if (!is_array($brought)) $brought = [];

  // ✅ 사망 조우가 남아 있으면(loot_state=pending이 아닌 경우) 루팅 가능 상태로 보정
  if (!empty($brought['encounter']) && is_array($brought['encounter'])) {
    $e =& $brought['encounter'];
    $hp = (int)($e['hp'] ?? 0);
    $dead = (int)($e['dead'] ?? 0);
    $ls = (string)($e['loot_state'] ?? '');
    if (($dead === 1 || $hp <= 0) && $ls !== 'pending') {
      $e['hp'] = 0;
      $e['dead'] = 1;
      // 표준 loot.stacks를 항상 채워 UI 루팅 모달이 열리게 한다.
      if (empty($e['loot']) || !is_array($e['loot'])) $e['loot'] = [];
      if (empty($e['loot']['stacks']) || !is_array($e['loot']['stacks'])) {
        $e['loot']['stacks'] = ['thr_stone' => random_int(1,2)];
      }
      // 기존 호환 키도 유지(있으면)
      if (!isset($e['loot']['stack']) || !is_array($e['loot']['stack'])) $e['loot']['stack'] = [];
      if (!isset($e['loot']['inst']) || !is_array($e['loot']['inst'])) $e['loot']['inst'] = [];
      $e['loot_state'] = 'pending';
    }
  }


  // ✅ active encounter면 이벤트 진행 금지(전투 우선)
  if (is_encounter_active($brought)) {
    $pdo->commit();
    safe_json_out([
      'ok'=>true,
      'title'=>'전투 진행 중',
      'text_html'=>html_typewriter("지금은 적 조우 상태입니다.\n전투를 처리한 뒤 다시 상황을 확인하세요."),
      'choices'=>[],
      'log_lines'=>['encounter_blocks_event_choice'],
    ]);
  }

  // 체인 상태
  $chain = as_arr($brought['event_chain'] ?? []);
  $step  = 0;
  $vars  = [];
  if (($chain['id'] ?? '') === $event_id) {
    $step = (int)($chain['step'] ?? 0);
    $vars = as_arr($chain['vars'] ?? []);
  } else {
    $chain = ['id'=>$event_id, 'step'=>0, 'vars'=>[]];
    $step = 0;
    $vars = [];
  }

  $log = [];

  $tier = (int)($brought['tier'] ?? 1);
  if ($tier < 1) $tier = 1;
  if ($tier > 5) $tier = 5;

  $title = '...';
  $text  = '';
  $choices = [];
  $end_chain = false;

  /* =========================
   * EVENT: tripwire
   * ========================= */
  if ($event_id === 'tripwire') {
    if ($step === 0) {
      $title = '와이어 트랩';

      if ($choice_id === 'disarm') {
        $roll = random_int(1,100);
        if ($roll <= 65) {
          $log[] = '트랩 해체 성공';
          $vars['trap_disarmed'] = 1;

          $text = "무릎을 굽히고 숨을 죽였다.\n"
                . "와이어의 장력을 서서히 풀어내자, 금속성 떨림이 멎었다.\n"
                . "하지만… 누군가가 이 길목에 왜 트랩을 걸어뒀을까?\n\n"
                . "주변을 더 조사하면 ‘추가 보상’이 있을 수도 있다.";
          $title = '해체 완료';

          $chain['step'] = 1;
          $chain['vars'] = $vars;

          $choices = [
            ['id'=>'search_more','label'=>'주변을 더 조사한다'],
            ['id'=>'move_on','label'=>'위험하다. 바로 이동한다'],
          ];
        } else {
          $log[] = '트랩 해체 실패';
          $vars['trap_disarmed'] = 0;

          $noise = random_int(45, 85);
          $vars['noise'] = $noise;

          $text = "손끝이 미세하게 떨린 순간,\n"
                . "와이어가 ‘딱’ 하고 튕기며 금속음이 골목을 긁었다.\n"
                . "바로 이어, 어딘가에서 발소리가 바스락거린다.\n\n"
                . "소음 수치: {$noise}\n"
                . "이 소리는 누군가(혹은 무언가)를 끌어들인다.";
          $title = '소음 발생';

          $chain['step'] = 2;
          $chain['vars'] = $vars;

          $choices = [
            ['id'=>'hold','label'=>'숨고 기다린다'],
            ['id'=>'run','label'=>'뛰어서 거리를 벌린다'],
            ['id'=>'set_ambush','label'=>'역으로 매복한다'],
          ];
        }
      }
      else if ($choice_id === 'avoid') {
        $log[] = '트랩 우회';
        $text = "가장 안전한 선택은 늘 지루하다.\n"
              . "너는 트랩을 피해 우회로로 몸을 밀어넣었다.\n"
              . "시간을 잃지만, 피를 잃지는 않는다.";
        $title = '우회 성공';
        $end_chain = true;
      }
      else if ($choice_id === 'charge') {
        $log[] = '강행 돌파';
        $noise = random_int(55, 95);
        $vars['noise'] = $noise;

        if (!function_exists('bag_stack_add')) {
          throw new RuntimeException('bag_stack_add() not found (lib/raid_core.php)');
        }
        bag_stack_add($bag, 'thr_stone', 1);
        $log[] = '루팅: thr_stone +1';

        $text = "너는 선택을 미루지 않았다.\n"
              . "다리를 들어 와이어 위로 넘기는 순간, 발끝이 걸렸다.\n"
              . "금속이 울리고, 너는 넘어지듯 굴러 빠져나왔다.\n\n"
              . "대신 바닥에 떨어진 물건 하나를 낚아챘다.\n"
              . "하지만… 이 소음은 너무 컸다.\n\n"
              . "소음 수치: {$noise}";
        $title = '돌파';

        $chain['step'] = 2;
        $chain['vars'] = $vars;

        $choices = [
          ['id'=>'hold','label'=>'숨고 기다린다'],
          ['id'=>'run','label'=>'뛰어서 거리를 벌린다'],
          ['id'=>'set_ambush','label'=>'역으로 매복한다'],
        ];
      }
      else {
        $title = '와이어 트랩';
        $text = "선택이 유효하지 않다.";
      }
    }
    else if ($step === 1) {
      if ($choice_id === 'search_more') {
        $roll = random_int(1,100);
        if ($roll <= 60) {
          if (random_int(1,100) <= 55) {
            if (!function_exists('bag_stack_add')) throw new RuntimeException('bag_stack_add() not found');
            bag_stack_add($bag, 'ammo_9mm_t1', random_int(6, 14));
            $log[] = '루팅: ammo_9mm_t1';
            $text = "벽면의 벗겨진 포스터 뒤,\n"
                  . "테이프로 감긴 작은 주머니를 찾아냈다.\n"
                  . "손에 잡히는 건 금속의 촉감.\n\n"
                  . "탄약을 챙겼다. 더 깊이 파볼까?";
          } else {
            if (!function_exists('bag_stack_add')) throw new RuntimeException('bag_stack_add() not found');
            bag_stack_add($bag, 'thr_stone', random_int(1,2));
            $log[] = '루팅: thr_stone';
            $text = "쓰레기 더미 사이에 굴러다니던 돌멩이를 주웠다.\n"
                  . "이 세계에선, 이런 것조차 ‘기회’가 된다.\n\n"
                  . "더 깊이 파볼까?";
          }

          $title = '추가 발견';
          $choices = [
            ['id'=>'search_deeper','label'=>'더 깊게 뒤진다(욕심)'],
            ['id'=>'move_on','label'=>'여기까지. 이동한다'],
          ];
          $chain['step'] = 3;
          $chain['vars'] = $vars;
        } else {
          $noise = random_int(35, 80);
          $vars['noise'] = $noise;
          $log[] = '수색 중 소음 발생';

          $title = '불길한 소리';
          $text  = "너는 더 파고들었다.\n"
                 . "그 순간, 유리 조각을 밟아 ‘짤깍’ 소리가 났다.\n"
                 . "주변 공기가 바뀐다.\n\n"
                 . "소음 수치: {$noise}\n"
                 . "무언가가 이쪽으로 온다.";
          $choices = [
            ['id'=>'hold','label'=>'숨고 기다린다'],
            ['id'=>'run','label'=>'뛰어서 거리를 벌린다'],
            ['id'=>'set_ambush','label'=>'역으로 매복한다'],
          ];
          $chain['step'] = 2;
          $chain['vars'] = $vars;
        }
      } else if ($choice_id === 'move_on') {
        $title = '이동';
        $text  = "너는 욕심을 자르듯 숨을 고르고 이동했다.\n"
               . "살아남는 자는 늘 ‘더’가 아니라 ‘지금’을 택한다.";
        $end_chain = true;
      } else {
        $title = '해체 이후';
        $text  = "선택이 유효하지 않다.";
      }
    }
    else if ($step === 2) {
      $noise = (int)($vars['noise'] ?? random_int(40, 80));
      if ($choice_id === 'hold') {
        $log[] = '대처: 은신';
        $noise2 = max(10, $noise - random_int(10, 25));
        $enc = spawn_encounter_force($pdo, $brought, $tier, $noise2, 'noise_hold');
        $title = '숨죽임';
        $text = "너는 벽에 등을 붙이고 호흡을 지웠다.\n"
              . "하지만 이 소음은 이미 충분히 컸다.\n\n"
              . "기척이 가까워진다: {$enc['name']}";
        $end_chain = true;
      }
      else if ($choice_id === 'run') {
        $log[] = '대처: 도주';
        $noise2 = min(100, $noise + random_int(5, 20));
        $enc = spawn_encounter_force($pdo, $brought, $tier, $noise2, 'noise_run');
        $title = '달아남';
        $text = "너는 달렸다.\n"
              . "발소리와 숨소리가 합쳐져 거리 전체가 흔들리는 느낌이다.\n\n"
              . "등 뒤에서 무언가가 따라붙는다: {$enc['name']}";
        $end_chain = true;
      }
      else if ($choice_id === 'set_ambush') {
        $log[] = '대처: 매복';
        $noise2 = min(100, $noise + 5);
        $enc = spawn_encounter_force($pdo, $brought, $tier, $noise2, 'noise_ambush');
        $title = '매복';
        $text = "너는 몸을 낮추고 각을 잡았다.\n"
              . "다가오는 기척의 리듬을 읽는다.\n\n"
              . "적 조우: {$enc['name']}\n"
              . "먼저 치면, 네가 주도권을 가진다.";
        $end_chain = true;
      }
      else {
        $title = '소음 이후';
        $text  = "선택이 유효하지 않다.";
      }
    }
    else if ($step === 3) {
      if ($choice_id === 'search_deeper') {
        $roll = random_int(1,100);
        if ($roll <= 50) {
          if (random_int(1,100) <= 50) {
            if (!function_exists('bag_stack_add')) throw new RuntimeException('bag_stack_add() not found');
            bag_stack_add($bag, 'ammo_9mm_t1', random_int(10, 22));
            $log[] = '루팅: ammo_9mm_t1(large)';
            $text = "손이 더 깊은 곳에서 ‘탄창’ 같은 물체를 걸어냈다.\n"
                  . "이 정도면 도파민이 돌지 않을 수 없다.\n\n"
                  . "하지만, 이 장소는 오래 머물수록 위험해진다.";
          } else {
            if (!function_exists('item_get') || !function_exists('create_instance_from_item') || !function_exists('bag_inst_add')) {
              throw new RuntimeException('item_get/create_instance_from_item/bag_inst_add not found');
            }
            $item = item_get($pdo, 'melee_fragile_stick');
            if ($item) {
              $iid = create_instance_from_item($pdo, $user_key, $item, ['durability_max'=>3]);
              bag_inst_add($bag, $iid);
              $log[] = '루팅: melee_fragile_stick(inst)';
            }
            $text = "쥐가 물어뜯은 천 조각 아래에서,\n"
                  . "부서질 듯한 막대기를 찾아냈다.\n\n"
                  . "완벽하진 않아도, 맨손보단 낫다.";
          }

          $noise = random_int(25, 70);
          $vars['noise'] = $noise;
          $log[] = '욕심의 대가: 주변 기척';

          $title = '기척';
          $choices = [
            ['id'=>'hold','label'=>'숨고 기다린다'],
            ['id'=>'run','label'=>'뛰어서 거리를 벌린다'],
            ['id'=>'set_ambush','label'=>'역으로 매복한다'],
          ];
          $chain['step'] = 2;
          $chain['vars'] = $vars;

          $text .= "\n\n멀리서 ‘철컥’ 하는 소리가 들린다.\n"
                . "누군가가 무기를 확인하는 소리다.\n"
                . "소음 수치: {$noise}";
        } else {
          $noise = random_int(60, 95);
          $vars['noise'] = $noise;
          $log[] = '욕심으로 큰 소음 발생';
          $enc = spawn_encounter_force($pdo, $brought, $tier, $noise, 'greed_noise');

          $title = '들켰다';
          $text  = "너는 더 파고들었다.\n"
                 . "그 순간, 금속이 바닥을 긁으며 크게 울렸다.\n"
                 . "바로 다음, 멀지 않은 곳에서 달려오는 소리.\n\n"
                 . "적 조우: {$enc['name']}";
          $end_chain = true;
        }
      } else if ($choice_id === 'move_on') {
        $title = '이동';
        $text  = "너는 손을 털고 일어났다.\n"
               . "‘더’는 늘 함정이 된다.\n"
               . "지금 가진 것만으로도 충분하다.";
        $end_chain = true;
      } else {
        $title = '추가 수색';
        $text  = "선택이 유효하지 않다.";
      }
    }
  }

  /* =========================
   * EVENT: rustle
   * ========================= */
  else if ($event_id === 'rustle') {
    // (당신 코드 그대로 유지)
    if ($step === 0) {
      $title = '수풀 소리';
      if ($choice_id === 'investigate') {
        $roll = random_int(1,100);
        if ($roll <= 45) {
          $noise = random_int(35, 80);
          $vars['noise'] = $noise;
          $log[] = '함정 경고: 소음 발생';

          $title = '함정 경고';
          $text  = "수풀을 젖히는 순간,\n"
                 . "발밑에서 얇은 철사가 당겨지는 느낌.\n"
                 . "너는 반사적으로 뒤로 물러섰지만,\n"
                 . "이미 늦었다.\n\n"
                 . "소음 수치: {$noise}";
          $choices = [
            ['id'=>'hold','label'=>'숨고 기다린다'],
            ['id'=>'run','label'=>'뛰어서 거리를 벌린다'],
            ['id'=>'set_ambush','label'=>'역으로 매복한다'],
          ];
          $chain['step'] = 1;
          $chain['vars'] = $vars;
        } else {
          if (!function_exists('bag_stack_add')) throw new RuntimeException('bag_stack_add() not found');
          bag_stack_add($bag, 'thr_stone', 1);
          $log[] = '루팅: thr_stone +1';

          $title = '발견';
          $text  = "수풀 아래엔 오래된 흔적.\n"
                 . "너는 돌 하나를 집어 들었다.\n\n"
                 . "더 파면 뭔가 더 나올 수도 있다.";
          $choices = [
            ['id'=>'dig_more','label'=>'더 파본다(욕심)'],
            ['id'=>'leave','label'=>'그만두고 이동한다'],
          ];
          $chain['step'] = 2;
          $chain['vars'] = $vars;
        }
      } else if ($choice_id === 'ignore') {
        $title='무시';
        $text="너는 위험을 피했다.\n하지만 기회도 피했다.";
        $end_chain=true;
      } else {
        $title='수풀 소리';
        $text='선택이 유효하지 않다.';
      }
    }
    else if ($step === 1) {
      $noise = (int)($vars['noise'] ?? 50);
      if (in_array($choice_id, ['hold','run','set_ambush'], true)) {
        $enc = spawn_encounter_force($pdo, $brought, $tier, $noise, 'rustle_noise');
        $title = '조우';
        $text  = "수풀의 흔들림이 멈췄다가,\n"
               . "다시 다른 방향에서 커진다.\n\n"
               . "적 조우: {$enc['name']}";
        $end_chain = true;
        $log[] = '조우 생성';
      } else {
        $title='함정 경고';
        $text='선택이 유효하지 않다.';
      }
    }
    else if ($step === 2) {
      if ($choice_id === 'dig_more') {
        $roll = random_int(1,100);
        if ($roll <= 55) {
          if (!function_exists('bag_stack_add')) throw new RuntimeException('bag_stack_add() not found');
          bag_stack_add($bag, 'ammo_9mm_t1', random_int(6, 12));
          $log[] = '루팅: ammo_9mm_t1';
          $title='추가 발견';
          $text ="손끝에 걸린 건 금속성의 차가움.\n탄약 몇 발을 챙겼다.";
          $choices=[['id'=>'leave','label'=>'이동한다']];
          $chain['step']=3; $chain['vars']=$vars;
        } else {
          $noise=random_int(55,95);
          $vars['noise']=$noise;
          $enc = spawn_encounter_force($pdo, $brought, $tier, $noise, 'dig_noise');
          $log[]='욕심으로 소음 발생';
          $title='들켰다';
          $text="너는 너무 오래 머물렀다.\n\n적 조우: {$enc['name']}";
          $end_chain=true;
        }
      } else if ($choice_id === 'leave') {
        $title='이동';
        $text='너는 조용히 자리를 떴다.';
        $end_chain=true;
      } else {
        $title='수풀';
        $text='선택이 유효하지 않다.';
      }
    } else if ($step === 3) {
      $title='이동';
      $text='너는 숨을 고르고 이동했다.';
      $end_chain=true;
    }
  }

  /* =========================
   * EVENT: smoke_signal
   * ========================= */
  else if ($event_id === 'smoke_signal') {
    // (당신 코드 그대로 유지)
    if ($step === 0) {
      $title='연기 신호';
      if ($choice_id === 'approach') {
        $roll = random_int(1,100);
        if ($roll <= 55) {
          if (!function_exists('item_get') || !function_exists('create_instance_from_item') || !function_exists('bag_inst_add')) {
            throw new RuntimeException('item_get/create_instance_from_item/bag_inst_add not found');
          }
          $item = item_get($pdo, 'melee_pipe_wrench');
          if ($item) {
            $iid = create_instance_from_item($pdo, $user_key, $item, []);
            bag_inst_add($bag, $iid);
            $log[]='루팅: melee_pipe_wrench(inst)';
            $text="연기 근처엔 방치된 더미.\n파이프 렌치를 챙겼다.\n\n하지만 연기는 ‘사람’을 부른다.";
          } else {
            if (!function_exists('bag_stack_add')) throw new RuntimeException('bag_stack_add() not found');
            bag_stack_add($bag, 'thr_stone', 1);
            $log[]='루팅: thr_stone +1';
            $text="연기 근처에서 쓸만한 것 하나를 챙겼다.\n\n하지만 연기는 ‘사람’을 부른다.";
          }

          $noise = random_int(25, 70);
          $vars['noise']=$noise;

          $title='기척';
          $choices=[
            ['id'=>'hide','label'=>'숨는다'],
            ['id'=>'signal_back','label'=>'신호를 흉내낸다(도박)'],
            ['id'=>'leave','label'=>'바로 떠난다'],
          ];
          $chain['step']=1;
          $chain['vars']=$vars;
        } else {
          $noise = random_int(65, 95);
          $enc = spawn_encounter_force($pdo, $brought, $tier, $noise, 'smoke_hot');
          $title='너무 가까웠다';
          $text="연기 너머에서 실루엣이 움직였다.\n\n적 조우: {$enc['name']}";
          $end_chain=true;
        }
      } else if ($choice_id === 'ignore') {
        $title='무시';
        $text="너는 연기를 외면했다.\n살아남는 데 필요한 건 용기보다 절제다.";
        $end_chain=true;
      } else {
        $title='연기 신호';
        $text='선택이 유효하지 않다.';
      }
    }
    else if ($step === 1) {
      $noise = (int)($vars['noise'] ?? 50);

      if ($choice_id === 'hide') {
        $noise2 = max(10, $noise - random_int(10, 25));
        $enc = spawn_encounter_force($pdo, $brought, $tier, $noise2, 'smoke_hide');
        $title='숨죽임';
        $text="숨을 죽였지만, 기척은 사라지지 않는다.\n\n적 조우: {$enc['name']}";
        $end_chain=true;
      }
      else if ($choice_id === 'signal_back') {
        $roll = random_int(1,100);
        if ($roll <= 35) {
          if (!function_exists('bag_stack_add')) throw new RuntimeException('bag_stack_add() not found');
          bag_stack_add($bag, 'ammo_9mm_t1', random_int(8, 18));
          $log[]='보상: ammo_9mm_t1';
          $title='응답';
          $text="잠시 뒤, 답신처럼 연기가 한 번 더 솟았다.\n"
              . "그리고 누군가가 바닥에 무언가를 던지고 사라진다.\n\n"
              . "너는 탄약을 챙겼다.";
          $choices=[['id'=>'leave','label'=>'떠난다']];
          $chain['step']=2;
          $chain['vars']=$vars;
        } else {
          $noise2 = min(100, $noise + random_int(10, 25));
          $enc = spawn_encounter_force($pdo, $brought, $tier, $noise2, 'smoke_signal_fail');
          $title='실수';
          $text="너는 신호를 흉내냈다.\n"
              . "하지만 돌아온 건 ‘답신’이 아니라 ‘확인 사격’ 같은 기척.\n\n"
              . "적 조우: {$enc['name']}";
          $end_chain=true;
        }
      }
      else if ($choice_id === 'leave') {
        $title='이동';
        $text="너는 연기에서 멀어졌다.\n재난의 시대엔, 눈에 띄는 게 죄다.";
        $end_chain=true;
      }
      else {
        $title='기척';
        $text='선택이 유효하지 않다.';
      }
    }
    else if ($step === 2) {
      $title='이동';
      $text="너는 충분히 얻었다.\n더 욕심내면 반드시 대가가 따라온다.";
      $end_chain=true;
    }
  }

  /* =========================
   * EVENT: stash_cache
   * ========================= */
  else if ($event_id === 'stash_cache') {
    if ($step === 0) {
      $title='임시 은닉처';
      if ($choice_id === 'loot') {
        $log[]='은닉처 수색';
        if (!function_exists('bag_stack_add')) throw new RuntimeException('bag_stack_add() not found');

        if (random_int(1,100) <= 60) {
          bag_stack_add($bag, 'ammo_9mm_t1', random_int(6, 16));
          $log[]='루팅: ammo_9mm_t1';
        } else {
          bag_stack_add($bag, 'thr_stone', random_int(1,2));
          $log[]='루팅: thr_stone';
        }

        if (random_int(1,100) <= 35) {
          $noise = random_int(40, 90);
          $enc = spawn_encounter_force($pdo, $brought, $tier, $noise, 'stash_found');
          $title='늦었다';
          $text="은닉처를 닫는 순간,\n등 뒤에서 발소리가 끊기듯 멈췄다.\n\n적 조우: {$enc['name']}";
          $end_chain=true;
        } else {
          $title='획득';
          $text="너는 필요한 걸 챙겼다.\n이 정도면 충분하다.";
          $end_chain=true;
        }
      }
      else if ($choice_id === 'leave') {
        $title='무시';
        $text="너는 은닉처를 지나쳤다.\n가끔은 ‘못 본 척’이 생존 기술이다.";
        $end_chain=true;
      }
      else {
        $title='임시 은닉처';
        $text='선택이 유효하지 않다.';
      }
    }
  }
  else {
    $title = '알 수 없는 상황';
    $text  = "이벤트 처리기가 없습니다: {$event_id}\n(raid_explore.php의 이벤트 id와 raid_choice.php가 일치해야 합니다)";
    $end_chain = true;
  }

  // chain 저장/종료
  if ($end_chain) unset($brought['event_chain']);
  else $brought['event_chain'] = $chain;

  // raid_state 반영
  raid_state_upsert($pdo, $user_key, 'in_raid', $bag, $throw, $brought);

  $pdo->commit();

  safe_json_out([
    'ok'=>true,
    'title'=>$title,
    'text_html'=>html_typewriter($text),
    'choices'=>$choices,
    'log_lines'=>$log,
  ]);

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  safe_json_out(
    DEBUG ? ['ok'=>false,'error'=>'server_error','msg'=>$e->getMessage()] : ['ok'=>false,'error'=>'server_error'],
    500
  );
}
