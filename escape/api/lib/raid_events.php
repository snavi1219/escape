<?php
// /escape/api/lib/raid_events.php
declare(strict_types=1);

/**
 * 이벤트/노이즈/위협도/하드코어 확률 통합 엔진
 * 상태는 brought_json['meta'], brought_json['event']에 저장.
 *
 * 의존: raid_core.php의 bag_* / item_get / create_instance_from_item / json_out 등
 */

if (!function_exists('re_meta_init')) {
  function re_meta_init(array &$brought): void {
    if (!isset($brought['meta']) || !is_array($brought['meta'])) $brought['meta'] = [];
    $brought['meta']['noise']  = isset($brought['meta']['noise'])  ? (int)$brought['meta']['noise']  : 0;
    $brought['meta']['threat'] = isset($brought['meta']['threat']) ? (int)$brought['meta']['threat'] : 0;
    $brought['meta']['bonus']  = isset($brought['meta']['bonus'])  ? (int)$brought['meta']['bonus']  : 0;

    // 안전 상한
    $brought['meta']['noise']  = max(0, min(10, (int)$brought['meta']['noise']));
    $brought['meta']['threat'] = max(0, min(10, (int)$brought['meta']['threat']));
    $brought['meta']['bonus']  = max(0, min(5,  (int)$brought['meta']['bonus']));
  }
}

if (!function_exists('re_token')) {
  function re_token(string $prefix = 'evt'): string {
    return $prefix . '_' . bin2hex(random_bytes(6));
  }
}

if (!function_exists('re_typing_html')) {
  function re_typing_html(string $text): string {
    $safe = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    return '<div class="typing" data-typing="1" data-text="'.$safe.'"></div>';
  }
}

if (!function_exists('re_roll')) {
  // 0~100
  function re_roll(): int { return random_int(0, 100); }
}

if (!function_exists('re_pick_event_node')) {
  /**
   * threat/noise에 따라 “더 위험한 이벤트” 비중이 증가.
   */
  function re_pick_event_node(int $threat, int $noise): string {
    $pool = [];

    // 기본(안전) 이벤트
    $pool = array_merge($pool, array_fill(0, 20, 'quiet_rustle'));
    $pool = array_merge($pool, array_fill(0, 18, 'abandoned_bag'));
    $pool = array_merge($pool, array_fill(0, 16, 'wire_trap'));

    // 위험 이벤트 가중치(위협/소음 높을수록 증가)
    $danger = 6 + (int)floor(($threat + $noise) * 1.5);
    $pool = array_merge($pool, array_fill(0, $danger, 'smoke_signal'));
    $pool = array_merge($pool, array_fill(0, max(2, (int)floor($danger/2)), 'distant_radio'));

    // 너무 길어지지 않게
    $idx = random_int(0, count($pool) - 1);
    return $pool[$idx];
  }
}

if (!function_exists('re_event_def')) {
  /**
   * 이벤트 정의 (phase1/phase2 파생 포함)
   * - choices는 choice_id로 분기됨
   */
  function re_event_def(string $node, int $phase): array {
    // 공통: 하드코어 톤(짧지 않게), 결과는 즉시 확정하지 않고 파생 가능
    if ($node === 'wire_trap' && $phase === 1) {
      return [
        'title' => '와이어 트랩',
        'text'  => "발밑에서 철사가 당겨지는 감각.\n짧은 금속음이 공기 중으로 튄다.\n\n움직임을 멈추면, 네 숨소리만 남는다.\n하지만 이미 늦었을 수도 있다.",
        'choices' => [
          ['id'=>'disarm',     'label'=>'천천히 해체한다'],
          ['id'=>'freeze',     'label'=>'그대로 멈춰 기척을 듣는다'],
          ['id'=>'rush_escape','label'=>'강제로 벗어나 빠르게 이동한다'],
        ]
      ];
    }
    if ($node === 'wire_trap' && $phase === 2) {
      return [
        'title' => '트랩 이후',
        'text'  => "해체는 끝났지만, 진짜 문제는 그 다음이다.\n\n멀리서… 아주 멀리서…\n같은 금속음이 한 번 더 들린다.\n\n누군가가, 너를 따라 하고 있다.",
        'choices' => [
          ['id'=>'hide',    'label'=>'엄폐하고 기다린다'],
          ['id'=>'advance', 'label'=>'소리 쪽으로 접근한다'],
          ['id'=>'retreat', 'label'=>'반대 방향으로 우회한다'],
        ]
      ];
    }

    if ($node === 'smoke_signal' && $phase === 1) {
      return [
        'title' => '연기 신호',
        'text'  => "회색 연기가 하늘에 천천히 번진다.\n\n사람이 있을 확률.\n사람이 아니라면… 더 큰 문제.\n\n연기 아래엔 '무언가'가 있다.",
        'choices' => [
          ['id'=>'approach', 'label'=>'가까이 간다'],
          ['id'=>'observe',  'label'=>'멀리서 관찰한다'],
          ['id'=>'detour',   'label'=>'우회한다'],
        ]
      ];
    }
    if ($node === 'smoke_signal' && $phase === 2) {
      return [
        'title' => '연기 아래',
        'text'  => "가까이 갈수록 냄새가 진해진다.\n타는 냄새가 아니라…\n금속과 피가 섞인 냄새.\n\n발자국이 있다.\n그리고 그 발자국은 '돌아오지' 않는다.",
        'choices' => [
          ['id'=>'loot_fast',  'label'=>'빠르게 확인하고 챙긴다'],
          ['id'=>'track',      'label'=>'발자국을 따라간다'],
          ['id'=>'back_off',   'label'=>'물러난다'],
        ]
      ];
    }

    if ($node === 'abandoned_bag' && $phase === 1) {
      return [
        'title' => '버려진 가방',
        'text'  => "낡은 가방 하나.\n지퍼는 반쯤 열려 있고, 안쪽은 젖어 있다.\n\n가방 근처의 흙은 이상하게 눌려 있다.\n누군가가 여기서… 오래 머물렀다.",
        'choices' => [
          ['id'=>'search',   'label'=>'조심히 뒤진다'],
          ['id'=>'kick',     'label'=>'멀리서 발로 건드린다'],
          ['id'=>'ignore',   'label'=>'무시하고 지나간다'],
        ]
      ];
    }
    if ($node === 'abandoned_bag' && $phase === 2) {
      return [
        'title' => '가방 안쪽',
        'text'  => "손끝에 무언가 걸린다.\n플라스틱이 아니라… 차가운 금속.\n\n그 순간, 주변의 벌레 소리마저 멈춘다.\n\n네가 숨을 크게 들이마시는 소리가\n너 자신에게조차 크게 들린다.",
        'choices' => [
          ['id'=>'take',      'label'=>'챙긴다'],
          ['id'=>'drop',      'label'=>'즉시 놓고 물러난다'],
          ['id'=>'listen',    'label'=>'주변을 먼저 확인한다'],
        ]
      ];
    }

    // 기본 fallback
    return [
      'title' => '수풀 소리',
      'text'  => "바람이 아닌 소리.\n\n수풀 깊은 곳에서\n짧고 끊어진 숨.\n\n너는 누군가의 '시선'을 느낀다.",
      'choices' => [
        ['id'=>'investigate', 'label'=>'확인한다'],
        ['id'=>'hold',        'label'=>'멈춰서 기다린다'],
        ['id'=>'leave',       'label'=>'조용히 이동한다'],
      ]
    ];
  }
}

if (!function_exists('re_should_spawn_encounter')) {
  /**
   * noise/threat 기반 조우 확률 (하드코어)
   * - 기본은 낮게
   * - noise가 높거나 threat가 높으면 급격히 상승
   */
  function re_should_spawn_encounter(int $noise, int $threat): bool {
    $base = 8; // 8%
    $p = $base + ($noise * 6) + ($threat * 4);

    // 상한 85%
    $p = max(0, min(85, $p));
    return re_roll() < $p;
  }
}

if (!function_exists('re_pick_enemy_type')) {
  /**
   * threat/noise에 따라 좀비/스캐브/PMC 분포 변화
   */
  function re_pick_enemy_type(int $noise, int $threat): string {
    $pool = [];

    // 좀비가 기본
    $pool = array_merge($pool, array_fill(0, 70, 'zombie'));

    // threat가 오르면 스캐브/PMC 증가
    $sc = 10 + $threat * 4;     // 최대 50 근처
    $pm =  4 + (int)floor($threat * 2.2); // 최대 26 근처
    // noise도 인간계 조우에 가중
    $sc += (int)floor($noise * 1.5);
    $pm += (int)floor($noise * 1.0);

    $pool = array_merge($pool, array_fill(0, max(0, $sc), 'scav'));
    $pool = array_merge($pool, array_fill(0, max(0, $pm), 'pmc'));

    return $pool[random_int(0, count($pool)-1)];
  }
}

if (!function_exists('re_event_loot_roll')) {
  /**
   * 이벤트 보상(하드코어): 낮은 확률, 대신 bonus(보너스 롤) 있으면 1회 추가 기회.
   * 반환: ['stack'=>[['item_id'=>'thr_stone','qty'=>1],...], 'inst'=>[['item_id'=>'melee_fragile_stick','dur_max'=>3],...]]
   */
  function re_event_loot_roll(int $bonus): array {
    $stack = [];
    $inst  = [];

    $tries = 1 + max(0, min(2, $bonus)); // bonus 0~2 -> 1~3회 롤
    for ($i=0; $i<$tries; $i++) {
      $r = re_roll();

      // 매우 흔한 자잘한 것(성공해도 도파민은 “조금”)
      if ($r < 18) {
        $stack[] = ['item_id'=>'thr_stone', 'qty'=>1];
        continue;
      }
      // 탄약 소량
      if ($r >= 18 && $r < 25) {
        $stack[] = ['item_id'=>'ammo_9mm_t1', 'qty'=>random_int(3, 8)];
        continue;
      }
      // 근접 막대(인스턴스) - 낮은 확률
      if ($r >= 25 && $r < 29) {
        $inst[] = ['item_id'=>'melee_fragile_stick', 'dur_max'=>3];
        continue;
      }
      // 녹슨 칼 - 더 낮은 확률
      if ($r >= 29 && $r < 31) {
        $inst[] = ['item_id'=>'melee_rusty_knife', 'dur_max'=>random_int(5, 9)];
        continue;
      }
      // 아무것도 없음(대부분)
    }

    return ['stack'=>$stack, 'inst'=>$inst];
  }
}

if (!function_exists('re_apply_loot_to_bag')) {
  /**
   * bag에 보상 반영 (stack/inst)
   */
  function re_apply_loot_to_bag(PDO $pdo, string $user_key, array &$bag, array $loot): array {
    $added_stack = [];
    $added_inst  = [];

    $stack = $loot['stack'] ?? [];
    if (is_array($stack)) {
      foreach ($stack as $s) {
        if (!is_array($s)) continue;
        $id = trim((string)($s['item_id'] ?? ''));
        $q  = (int)($s['qty'] ?? 0);
        if ($id === '' || $q <= 0) continue;
        bag_stack_add($bag, $id, $q);
        $added_stack[] = ['item_id'=>$id, 'qty'=>$q];
      }
    }

    $inst = $loot['inst'] ?? [];
    if (is_array($inst)) {
      foreach ($inst as $it) {
        if (!is_array($it)) continue;
        $id = trim((string)($it['item_id'] ?? ''));
        if ($id === '') continue;

        $item = item_get($pdo, $id);
        if (!$item) continue;

        $durMax = (int)($it['dur_max'] ?? 0);
        if ($id === 'melee_fragile_stick' && $durMax <= 0) $durMax = 3;

        $iid = create_instance_from_item($pdo, $user_key, $item, [
          'durability_max' => $durMax,
          'ammo_in_mag'    => 0,
        ]);

        bag_inst_add($bag, $iid);
        $added_inst[] = ['item_id'=>$id, 'instance_id'=>$iid];
      }
    }

    return ['stack'=>$added_stack, 'inst'=>$added_inst];
  }
}

if (!function_exists('re_event_begin')) {
  function re_event_begin(array &$brought): array {
    re_meta_init($brought);
    $node = re_pick_event_node((int)$brought['meta']['threat'], (int)$brought['meta']['noise']);
    $token = re_token('evt');
    $brought['event'] = ['token'=>$token, 'node'=>$node, 'phase'=>1];

    $def = re_event_def($node, 1);
    return [
      'token' => $token,
      'title' => $def['title'],
      'text_html' => re_typing_html($def['text']),
      'choices' => $def['choices'],
    ];
  }
}

if (!function_exists('re_event_present')) {
  function re_event_present(array &$brought): array {
    re_meta_init($brought);
    $ev = $brought['event'] ?? null;
    if (!is_array($ev)) return re_event_begin($brought);

    $token = (string)($ev['token'] ?? re_token('evt'));
    $node  = (string)($ev['node']  ?? 'quiet_rustle');
    $phase = (int)($ev['phase'] ?? 1);
    $phase = ($phase === 2) ? 2 : 1;

    // 정합성 보정
    $brought['event'] = ['token'=>$token, 'node'=>$node, 'phase'=>$phase];

    $def = re_event_def($node, $phase);
    return [
      'token' => $token,
      'title' => $def['title'],
      'text_html' => re_typing_html($def['text']),
      'choices' => $def['choices'],
    ];
  }
}

if (!function_exists('re_event_choose')) {
  /**
   * choice 처리:
   * - meta(noise/threat/bonus) 반영
   * - phase2 파생 or 종료
   * - (확률) 조우 발생 플래그 반환
   * - (확률) 이벤트 보상 지급
   */
  function re_event_choose(PDO $pdo, string $user_key, array &$bag, array &$brought, string $choice_id): array {
    re_meta_init($brought);

    $ev = $brought['event'] ?? null;
    if (!is_array($ev)) return ['ok'=>false, 'error'=>'no_event'];

    $node  = (string)($ev['node'] ?? '');
    $phase = (int)($ev['phase'] ?? 1);
    $phase = ($phase === 2) ? 2 : 1;

    $log = [];
    $spawn = false;

    // 선택지별 하드코어 영향(노이즈/위협도)
    $noiseDelta = 0;
    $threatDelta = 0;
    $bonusDelta = 0;

    // 기본 룰: phase1에서 “대부분 phase2로 가게” 설계(딥하게)
    $goPhase2 = false;
    $endEvent = false;

    if ($node === 'wire_trap' && $phase === 1) {
      if ($choice_id === 'disarm') {
        $noiseDelta = 0; $threatDelta = -1;
        $goPhase2 = true;
        $log[] = "트랩을 해체했다. 하지만 손끝이 떨린다.";
        // 성공 보너스: 다음 보상 롤 1회
        if (re_roll() < 20) { $bonusDelta = 1; $log[] = "침착함이 유지된다. '보너스 롤' 감각이 든다."; }
      } elseif ($choice_id === 'freeze') {
        $noiseDelta = 0; $threatDelta = 0;
        $goPhase2 = true;
        $log[] = "숨을 죽인다. 소리는 멀어지지 않는다.";
      } else { // rush_escape
        $noiseDelta = 2; $threatDelta = 1;
        $goPhase2 = true;
        $log[] = "강제로 벗어났다. 금속음이 더 크게 울린다.";
      }
    } elseif ($node === 'wire_trap' && $phase === 2) {
      if ($choice_id === 'hide') {
        $noiseDelta = -1; $threatDelta = 0;
        $endEvent = true;
        $log[] = "기다린다. 시간만이 지나간다.";
      } elseif ($choice_id === 'advance') {
        $noiseDelta = 1; $threatDelta = 1;
        $endEvent = true;
        $log[] = "소리 쪽으로 간다. 누군가가 먼저 간 흔적.";
      } else { // retreat
        $noiseDelta = 0; $threatDelta = -1;
        $endEvent = true;
        $log[] = "우회한다. 발걸음을 최대한 지운다.";
      }
    }

    if ($node === 'smoke_signal' && $phase === 1) {
      if ($choice_id === 'approach') {
        $noiseDelta = 1; $threatDelta = 1;
        $goPhase2 = true;
        $log[] = "연기 아래로 들어간다. 냄새가 변한다.";
      } elseif ($choice_id === 'observe') {
        $noiseDelta = 0; $threatDelta = 0;
        $goPhase2 = true;
        $log[] = "관찰한다. 누군가 움직인다… 아닌가?";
      } else { // detour
        $noiseDelta = 0; $threatDelta = -1;
        $endEvent = true;
        $log[] = "우회한다. 그 선택이 옳았는지는 모른다.";
      }
    } elseif ($node === 'smoke_signal' && $phase === 2) {
      if ($choice_id === 'loot_fast') {
        $noiseDelta = 1; $threatDelta = 0;
        $endEvent = true;
        $log[] = "빠르게 확인한다. 손에 무언가 걸린다.";
        if (re_roll() < 30) { $bonusDelta = 1; $log[] = "운이 좋다. 한 번 더 챙길 기회가 있다."; }
      } elseif ($choice_id === 'track') {
        $noiseDelta = 0; $threatDelta = 2;
        $endEvent = true;
        $log[] = "발자국을 따라간다. 이건 안전한 선택이 아니다.";
      } else { // back_off
        $noiseDelta = -1; $threatDelta = -1;
        $endEvent = true;
        $log[] = "물러난다. 살아남는 게 목표다.";
      }
    }

    if ($node === 'abandoned_bag' && $phase === 1) {
      if ($choice_id === 'search') {
        $noiseDelta = 0; $threatDelta = 1;
        $goPhase2 = true;
        $log[] = "조심히 지퍼를 당긴다. 안쪽이 젖어 있다.";
      } elseif ($choice_id === 'kick') {
        $noiseDelta = 2; $threatDelta = 0;
        $goPhase2 = true;
        $log[] = "발로 건드린다. 가방이 굴러가며 소리가 난다.";
      } else { // ignore
        $noiseDelta = 0; $threatDelta = -1;
        $endEvent = true;
        $log[] = "무시한다. 무시하는 게 최고의 스킬이다.";
      }
    } elseif ($node === 'abandoned_bag' && $phase === 2) {
      if ($choice_id === 'take') {
        $noiseDelta = 1; $threatDelta = 0;
        $endEvent = true;
        $log[] = "챙긴다. 손이 차가워진다.";
        if (re_roll() < 25) { $bonusDelta = 1; $log[] = "그리고… 아직 더 있다."; }
      } elseif ($choice_id === 'drop') {
        $noiseDelta = 0; $threatDelta = -1;
        $endEvent = true;
        $log[] = "놓는다. 발걸음을 되돌린다.";
      } else { // listen
        $noiseDelta = 0; $threatDelta = 1;
        $endEvent = true;
        $log[] = "주변을 먼저 본다. 시선이 느껴진다.";
      }
    }

    // 공통 fallback (정의 밖 choice)
    if (!$goPhase2 && !$endEvent && $phase === 1) {
      $goPhase2 = true;
      $log[] = "선택이 명확하지 않다. 상황은 더 깊어진다.";
    } elseif (!$goPhase2 && !$endEvent && $phase === 2) {
      $endEvent = true;
      $log[] = "결정했다. 이제 움직여야 한다.";
    }

    // meta 반영
    $brought['meta']['noise']  = max(0, min(10, (int)$brought['meta']['noise']  + $noiseDelta));
    $brought['meta']['threat'] = max(0, min(10, (int)$brought['meta']['threat'] + $threatDelta));
    $brought['meta']['bonus']  = max(0, min(5,  (int)$brought['meta']['bonus']  + $bonusDelta));

    // 조우 발생 판정 (phase2 끝나거나 endEvent일 때 체감이 좋음)
    if ($endEvent || ($phase === 2 && $endEvent)) {
      if (re_should_spawn_encounter((int)$brought['meta']['noise'], (int)$brought['meta']['threat'])) {
        $spawn = true;
        $log[] = "…소리가 가까워진다.";
      }
    }

    // 이벤트 보상(하드코어): endEvent일 때만, 확률 낮게
    $added = ['stack'=>[], 'inst'=>[]];
    if ($endEvent) {
      // endEvent일 때 보너스 롤까지 포함해서 1회 “기회”
      $loot = re_event_loot_roll((int)$brought['meta']['bonus']);

      // 보너스는 “소모”로 처리(도파민은 쓰는 구조가 좋아서)
      if ((int)$brought['meta']['bonus'] > 0) $brought['meta']['bonus'] = max(0, (int)$brought['meta']['bonus'] - 1);

      // 실제 반영(있으면만)
      $hasLoot = !empty($loot['stack']) || !empty($loot['inst']);
      if ($hasLoot) {
        $added = re_apply_loot_to_bag($pdo, $user_key, $bag, $loot);
        if (!empty($added['stack']) || !empty($added['inst'])) $log[] = "짧은 보상이 손에 들어온다.";
      }
    }

    // phase 전환/종료
    if ($goPhase2 && $phase === 1) {
      $brought['event']['phase'] = 2;
      // token 유지(같은 이벤트의 연속)
    } elseif ($endEvent) {
      unset($brought['event']);
    }

    return [
      'ok' => true,
      'log_lines' => $log,
      'spawn_encounter' => $spawn ? 1 : 0,
      'added' => $added,
      'meta' => $brought['meta'],
    ];
  }
}
