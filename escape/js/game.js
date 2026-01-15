(() => {
  "use strict";

  // ------------------------------------------------------------
  // EFN Root + Bus/Push (새 UI(app.js)와 연결)
  // ------------------------------------------------------------
  window.EFN = window.EFN || {};
  const EFN = window.EFN;

  // Tiny bus
  if (!EFN.bus) {
    const map = new Map();
    EFN.bus = {
      on(evt, fn) {
        if (!map.has(evt)) map.set(evt, new Set());
        map.get(evt).add(fn);
        return () => map.get(evt)?.delete(fn);
      },
      emit(evt, payload) {
        const set = map.get(evt);
        if (!set) return;
        for (const fn of set) { try { fn(payload); } catch (_) {} }
      }
    };
  }

  // Push helper: app.js가 bus를 구독함
  if (!EFN.push) {
    EFN.push = {
      api(connected, text) { EFN.bus.emit("api", { connected, text }); },
      log(text, tone = "m", typing = true) { EFN.bus.emit("log", { text, tone, typing }); },
      choices(choices) { EFN.bus.emit("choices", { choices }); },
      bag(items) { EFN.bus.emit("bag", { items }); },
      status(player, loadout) { EFN.bus.emit("status", { player, loadout }); },
      raid(state) { EFN.bus.emit("raid", { state }); },
    };
  }

  // ------------------------------------------------------------
  // Required deps from EFN (기존 유지)
  // ------------------------------------------------------------
  const { randInt, chance, clamp } = EFN;

  // api는 기존 유지
  const { apiPost, apiGet, apiPostTry, getUserKey, setUserKey } = EFN.api;

  // ------------------------------------------------------------
  // UI Adapter (구 UI 없어도 동작하도록)
  // - 기존 코드의 pushLog/narrate/renderAll/setApiStatus 등을 안전화
  // ------------------------------------------------------------
  const ui = (() => {
    const legacy = EFN.ui || {};

    const pushLog = (tag, msg) => {
      // 구 UI가 있으면 유지 호출
      if (typeof legacy.pushLog === "function") {
        try { legacy.pushLog(tag, msg); } catch (_) {}
      }
      // 새 UI에도 출력
      const tone =
        tag === "SYS" ? "warn" :
        tag === "E"   ? "warn" :
        tag === "P"   ? "info" :
        tag === "EVT" ? "m" :
        "m";
      EFN.push.log(`[${tag}] ${msg}`, tone, false);
    };

    const setApiStatus = (text, kind) => {
      // 구 UI
      if (typeof legacy.setApiStatus === "function") {
        try { legacy.setApiStatus(text, kind); } catch (_) {}
      }
      // 새 UI: connected 여부는 텍스트로 추정
      const up = String(text || "").toUpperCase();
      const connected = (up === "CONNECTED" || up === "READY" || up === "OK");
      EFN.push.api(connected, String(text || ""));
    };

    // narrate: 구 UI 타이핑이 있으면 그대로, 없으면 bus로 줄단위 출력
    const narrate = async (lines, opts = {}) => {
      const arr = Array.isArray(lines) ? lines : [String(lines ?? "")];
      if (typeof legacy.narrate === "function") {
        try {
          await legacy.narrate(arr, opts);
          return;
        } catch (_) { /* fallthrough */ }
      }
      // 새 UI 타이핑 큐로 전달 (app.js가 처리)
      const tag = (opts.tag ? String(opts.tag) : "EVT");
      const tone =
        tag === "SYS" ? "warn" :
        tag === "E"   ? "warn" :
        tag === "P"   ? "info" :
        tag === "EVT" ? "m" :
        "m";

      for (const ln of arr) {
        EFN.push.log(String(ln ?? ""), tone, true);
      }
    };

    const narrBusy = () => {
      // app.js는 자체적으로 typing 중 클릭을 막으므로
      // 여기서는 legacy가 있으면 사용, 없으면 false
      if (typeof legacy.narrBusy === "function") {
        try { return !!legacy.narrBusy(); } catch (_) { return false; }
      }
      return false;
    };

    // renderAll: 새 UI는 bus-driven 이므로, legacy가 있으면 호출만 유지
    const renderAll = () => {
      if (typeof legacy.renderAll === "function") {
        try { legacy.renderAll(); } catch (_) {}
      }
      // 새 UI는 refreshInventory()/push.status()/push.bag()/push.choices()로 갱신
    };

    // availThrow/slotLabel/nm 등은 기존 로직에서 필요하므로:
    const availThrow = legacy.availThrow || ((item_id) => {
      // EFN.RAID.throw 또는 EFN.RAID.inventory에서 체크
      const t = EFN.RAID?.throw || {};
      const inv = EFN.RAID?.inventory || {};
      const a = Number(t[item_id] ?? 0);
      const b = Number(inv[item_id] ?? 0);
      return Math.max(a, b);
    });

    const slotLabel = legacy.slotLabel || ((slot) => {
      if (slot === "primary") return "주무기";
      if (slot === "secondary") return "보조무기";
      if (slot === "melee") return "근접";
      return String(slot);
    });

    const nm = legacy.nm || ((item_id) => {
      return EFN.ITEM_MAP?.[item_id]?.name || item_id || "-";
    });

    return { pushLog, setApiStatus, narrate, narrBusy, renderAll, availThrow, slotLabel, nm };
  })();

  const { pushLog, setApiStatus, narrate, narrBusy, renderAll, availThrow, slotLabel, nm } = ui;

  // ------------------------------------------------------------
  // Helpers (기존 유지)
  // ------------------------------------------------------------
  function getEquippedInstance(slot) {
    const iid = EFN.LOADOUT?.[slot];
    if (!iid) return null;
    const list = (EFN.RAID?.instances || []).filter(x => x.item_id === iid);
    if (!list.length) return null;
    list.sort((a, b) => (b.durability || 0) - (a.durability || 0));
    return list[0];
  }

  function getWeaponForAttack() {
    const pick = EFN.LOADOUT?.primary || EFN.LOADOUT?.secondary || EFN.LOADOUT?.melee || "";
    const it = EFN.ITEM_MAP?.[pick];
    if (!it) return { item_id: "", name: "맨손", type: "melee", stats: { dmg: [1, 3], crit: 1 } };

    let stats = {};
    try { stats = JSON.parse(it.stats_json || "{}") || {}; } catch (e) { stats = {}; }
    const dmg = Array.isArray(stats.dmg) ? stats.dmg : [2, 5];
    const crit = Number(stats.crit ?? 2);
    const ammoPer = Number(stats.ammo_per_shot ?? 1);
    return { item_id: it.item_id, name: it.name, type: it.type, stats: { dmg, crit, ammoPer } };
  }

  function enemySpawn() {
    const t = EFN.state.turn;
    const scale = 1 + Math.min(0.7, (t - 1) * 0.03);

    const r = Math.random();
    let pool = EFN.ENEMIES.filter(e => e.kind === "zombie");
    if (r >= 0.70 && r < 0.90) pool = EFN.ENEMIES.filter(e => e.kind === "scav");
    if (r >= 0.90) pool = EFN.ENEMIES.filter(e => e.kind === "pmc");

    const base = pool[randInt(0, pool.length - 1)];
    const hp = Math.floor(randInt(base.hp[0], base.hp[1]) * scale);
    const atk = Math.floor(randInt(base.atk[0], base.atk[1]) * scale);
    const def = Math.floor(randInt(base.def[0], base.def[1]) + (t > 10 ? 1 : 0));
    return { ...base, hpMax: hp, hp, atk, def };
  }

  function computeDamageFromRange(range, defenderDef, critPct) {
    const base = randInt(range[0], range[1]);
    const isCrit = chance(critPct);
    const raw = isCrit ? Math.floor(base * 1.6) : base;
    const dmg = Math.max(1, raw - defenderDef + randInt(-1, 1));
    return { dmg, isCrit };
  }

  // ------------------------------------------------------------
  // ✅ Choices -> 새 UI(app.js)로만 전달
  // ------------------------------------------------------------
  function setChoicesForUI(choices) {
    // app.js는 {id,label,kind} 형태를 기대
    const list = Array.isArray(choices) ? choices : [];
    EFN.push.choices(list.map(c => ({
      id: c.id,
      label: c.label || c.id,
      kind: c.kind || "",
      // 필요하면 raw를 실어도 됨(현재 app.js는 무시)
      raw: c
    })));
  }

  function setContinueChoice(label) {
    setChoicesForUI([{ id: "explore", label, kind: "primary" }]);
  }

  // ------------------------------------------------------------
  // ✅ 서버 기반 탐색(A안) 연결부 (기존 유지)
  // ------------------------------------------------------------
  async function serverExplore() {
    const user_key = getUserKey();
    const url = `./api/raid_explore.php?user_key=${encodeURIComponent(user_key)}&t=${Date.now()}`;
    return apiGet(url);
  }

  async function serverChoice(token, choice_id) {
    const user_key = getUserKey();
    return apiPostTry("./api/raid_choice.php", { user_key, token, choice_id });
  }

  // ------------------------------------------------------------
  // Combat (기존 유지)
  // ------------------------------------------------------------
  async function startCombat() {
    EFN.state.enemy = enemySpawn();
    EFN.state.inCombat = true;
    EFN.state.location = "교전";

    const cue = [
      "부서진 차량 뒤에서 그림자가 움직입니다.",
      "멀리서 유리 깨지는 소리… 곧바로 발소리가 다가옵니다.",
      "짙은 안개 속, 정체 불명의 실루엣이 달려옵니다.",
      "무전 잡음… 그리고 즉시 교전입니다."
    ];

    await narrate([cue[randInt(0, cue.length - 1)], "적 정보는 확인 불가."], { tag: "EVT", log: true });
    renderAll();
  }

  // ------------------------------------------------------------
  // Inventory sync (기존 유지 + push로 새 UI 갱신)
  // ------------------------------------------------------------
  function toRaidBagList() {
    // EFN.RAID.inventory (item_id->qty) 를 리스트로 변환
    const inv = EFN.RAID?.inventory || {};
    const out = [];
    for (const item_id of Object.keys(inv)) {
      const qty = Number(inv[item_id] || 0);
      if (qty <= 0) continue;
      const it = EFN.ITEM_MAP?.[item_id];
      out.push({
        item_id,
        qty,
        name: it?.name || item_id,
        type: it?.type,
        rarity: it?.rarity,
      });
    }
    // 인스턴스(무기/방어구 내구도 등)도 표시하고 싶으면 여기서 추가 가능
    return out;
  }

  function pushStatusToUI() {
    // 플레이어 상태를 app.js에 공급
    const player = {
      name: localStorage.getItem(EFN.LS_NAME) || "Player",
      hp: EFN.state.player.hp,
      arm: EFN.state.player.arm ?? (EFN.RAID?.armor_state?.durability ?? "-"),
      raid: EFN.RAID?.status === "in_raid" ? "IN_RAID" : "IDLE",
      status: EFN.state.location || "-",
    };
    const loadout = {
      primary: nm(EFN.LOADOUT?.primary),
      secondary: nm(EFN.LOADOUT?.secondary),
      melee: nm(EFN.LOADOUT?.melee),
    };
    EFN.push.status(player, loadout);
    EFN.push.raid(player.raid);
    EFN.push.bag(toRaidBagList());
  }

  async function refreshInventory() {
    const user_key = getUserKey();
    if (!user_key) return;

    try {
      setApiStatus("SYNC", "info");
      const r = await apiGet(`./api/inventory_get.php?user_key=${encodeURIComponent(user_key)}&t=${Date.now()}`);
      if (!r.ok) {
        setApiStatus("ERROR", "bad");
        pushLog("SYS", `인벤 조회 실패: ${r.error || "unknown"} (inventory_get.php)`);
        renderAll();
        return;
      }

      EFN.ITEM_MAP = {};
      for (const it of (r.items || [])) EFN.ITEM_MAP[it.item_id] = it;

      EFN.STASH = (r.stash || []).map(x => ({ item_id: x.item_id, qty: Number(x.qty || 0) }));
      EFN.LOADOUT = r.loadout || { primary: null, secondary: null, melee: null };

      EFN.RAID = r.raid || { status: "idle", inventory: {}, throw: { thr_stone: 0, thr_ied: 0, thr_grenade: 0 }, brought: { primary: null, secondary: null, melee: null } };
      if (!EFN.RAID.inventory) EFN.RAID.inventory = {};
      if (!EFN.RAID.throw) EFN.RAID.throw = { thr_stone: 0, thr_ied: 0, thr_grenade: 0 };
      if (!EFN.RAID.brought) EFN.RAID.brought = { primary: null, secondary: null, melee: null };
      if (!Array.isArray(EFN.RAID.instances)) EFN.RAID.instances = [];
      if (!EFN.RAID.weapon_state) EFN.RAID.weapon_state = {};
      if (!EFN.RAID.armor_state) EFN.RAID.armor_state = null;

      // 구 UI 버튼이 있을 수도 있으니 안전 처리
      try {
        const btn = typeof EFN.el === "function" ? EFN.el("btnGrantStarter") : null;
        if (btn) btn.disabled = false;
      } catch (_) {}

      setApiStatus("CONNECTED", "ok");
      renderAll();

      // ✅ 새 UI로 상태/가방/레이드 상태 푸시
      pushStatusToUI();
    } catch (e) {
      setApiStatus("ERROR", "bad");
      pushLog("SYS", "인벤 조회 실패: non_json_response (inventory_get.php 확인)");
      renderAll();
    }
  }

  async function singleReady() {
    // 구 UI input이 없어졌을 수 있으므로, 로컬 저장값 우선
    let name = (localStorage.getItem(EFN.LS_NAME) || "Player").trim() || "Player";
    try {
      if (typeof EFN.el === "function" && EFN.el("singleName")) {
        name = (EFN.el("singleName").value || name).trim() || "Player";
      }
    } catch (_) {}
    localStorage.setItem(EFN.LS_NAME, name);

    try {
      setApiStatus("CONNECTING", "info");
      const r = await apiPost("./api/hello.php", { name });
      if (!r.ok) throw new Error("hello_failed");
      setUserKey(r.user_key);
      await narrate([`싱글 유저 준비 완료`, `${r.user_key} (${r.name})`], { tag: "SYS", log: true });
      setApiStatus("READY", "ok");
      await refreshInventory();
    } catch (e) {
      setApiStatus("ERROR", "bad");
      pushLog("SYS", "싱글 유저 준비 실패: api/hello.php 확인");
      renderAll();
    }
  }

  async function startRaidIfNeeded() {
    const user_key = getUserKey();
    if (!user_key) { await narrate(["싱글 ID가 없습니다.", "먼저 '싱글 유저 준비'를 하세요."], { tag: "SYS", log: true }); return; }
    if (EFN.RAID?.status === "in_raid") return;

    const r = await apiPost("./api/raid_start.php", { user_key });
    if (!r.ok) {
      if (r.error === "already_in_raid") {
        await narrate(["이미 출격 중입니다.", "상태를 동기화합니다."], { tag: "SYS", log: true });
        await refreshInventory();
        return;
      }
      pushLog("SYS", `자동 출격 시작 실패: ${r.error || "unknown"} (raid_start.php)`);
      renderAll();
      return;
    }
    await narrate(["출격이 시작되었습니다.", "(투척/장비 반입 완료)"], { tag: "SYS", log: true });
    await refreshInventory();
  }

  // ------------------------------------------------------------
  // Killed / enemy turn / death (기존 유지)
  // ------------------------------------------------------------
  async function onEnemyKilled() {
    const e = EFN.state.enemy;
    await narrate([`격파 확인.`, `대상: ${e.name} (${e.kind.toUpperCase()})`], { tag: "E", log: true });

    const drops = [];
    const maxDrop = chance(25) ? 2 : 1;

    for (let i = 0; i < maxDrop; i++) {
      const table = EFN.state.enemy.loot || [];
      let pick = null;
      for (const [item_id, pct] of table) {
        if (chance(pct)) { pick = item_id; break; }
      }
      if (!pick) pick = "thr_stone";
      drops.push(pick);
    }

    for (const item_id of drops) {
      const r = await apiPost("./api/raid_add_loot.php", { user_key: getUserKey(), item_id, qty: 1 });
      if (!r.ok) {
        pushLog("SYS", `루팅 저장 실패: ${r.error || "unknown"} (raid_add_loot.php)`);
      } else {
        const nm2 = EFN.ITEM_MAP[item_id]?.name || item_id;
        await narrate([`전리품 확보: ${nm2} x1`, `(RAID BAG)`], { tag: "EVT", log: true });
        EFN.RAID.inventory[item_id] = (Number(EFN.RAID.inventory[item_id] || 0) + 1);
      }
    }

    EFN.state.inCombat = false;
    EFN.state.enemy = null;
    EFN.state.location = "탐색 중";
    await refreshInventory();
    renderAll();
    pushStatusToUI();
  }

  async function enemyTurn(isPunish = false) {
    const e = EFN.state.enemy;
    if (!e) return;

    const raw = randInt(Math.max(1, e.atk - 2), e.atk + 2);
    const r = await apiPostTry("./api/raid_take_damage.php", { user_key: getUserKey(), raw_damage: raw, enemy_kind: e.kind });
    if (r.ok && r.armor_state) EFN.RAID.armor_state = r.armor_state;

    const finalDmg = r.ok ? Number(r.final_damage ?? raw) : raw;
    const isCrit = chance((e.kind === "pmc" ? 10 : (e.kind === "scav" ? 7 : 5)));

    EFN.state.player.hp = clamp(EFN.state.player.hp - finalDmg, 0, EFN.state.player.hpMax);

    await narrate([`${isPunish ? "선공! " : ""}${isCrit ? "치명타! " : ""}${finalDmg} 피해를 입었습니다.`], { tag: "E", log: true });

    if (EFN.state.player.hp <= 0) {
      await handleDeath("당신은 쓰러졌습니다.");
      return;
    }

    EFN.state.turn += 1;
    renderAll();
    pushStatusToUI();
  }

  async function handleDeath(reason) {
    await narrate([reason], { tag: "SYS", log: true });
    EFN.state.inCombat = false;
    EFN.state.enemy = null;
    EFN.state.location = "사망";

    const r = await apiPost("./api/raid_die.php", { user_key: getUserKey() });
    if (!r.ok) {
      pushLog("SYS", `사망 서버 처리 실패: ${r.error || "unknown"} (raid_die.php)`);
    } else {
      await narrate(["사망 처리 완료.", "(출격 반입 장비/출격 인벤/투척/인스턴스 소실)"], { tag: "SYS", log: true });
      EFN.RAID.status = "idle";
      EFN.RAID.inventory = {};
      EFN.RAID.throw = { thr_stone: 0, thr_ied: 0, thr_grenade: 0 };
      EFN.RAID.brought = { primary: null, secondary: null, melee: null };
      EFN.RAID.instances = [];
      EFN.RAID.weapon_state = {};
      EFN.RAID.armor_state = null;
      EFN.LOADOUT = { primary: null, secondary: null, melee: null };
    }
    renderAll();
    pushStatusToUI();
    // 사망 후에는 app.js에서 STASH 복귀 흐름(버튼/라우팅)을 별도로 구성하면 됨
  }

  // ------------------------------------------------------------
  // Player actions (기존 유지)
  // ------------------------------------------------------------
  async function playerAttack() {
    if (!EFN.state.inCombat || !EFN.state.enemy) return;

    const w = getWeaponForAttack();

    const slot = (EFN.LOADOUT.primary === w.item_id) ? "primary"
      : (EFN.LOADOUT.secondary === w.item_id) ? "secondary"
        : "melee";

    const isGun = (w.type === "rifle" || w.type === "pistol");

    if (isGun) {
      const ws = EFN.RAID.weapon_state?.[slot];
      const loaded = Number(ws?.ammo_loaded ?? 0);
      const need = Number(w.stats.ammoPer ?? 1);

      if (loaded < need) {
        await narrate(["탄약이 없습니다.", "(재장전/탄약 확보 필요)"], { tag: "SYS", log: true });
        renderAll();
        return;
      }

      const r = await apiPostTry("./api/raid_fire.php", { user_key: getUserKey(), slot, shots: 1 });
      if (r.ok && r.weapon_state) EFN.RAID.weapon_state = r.weapon_state;
      else {
        EFN.RAID.weapon_state = EFN.RAID.weapon_state || {};
        EFN.RAID.weapon_state[slot] = EFN.RAID.weapon_state[slot] || { ammo_loaded: loaded, ammo_type: ws?.ammo_type || "-" };
        EFN.RAID.weapon_state[slot].ammo_loaded = Math.max(0, loaded - need);
      }
    } else {
      const inst = getEquippedInstance("melee");
      if (inst && Number(inst.durability || 0) <= 0) {
        await narrate(["근접 무기가 파손되었습니다.", "(맨손으로 전환)"], { tag: "SYS", log: true });
      }
      const rr = await apiPostTry("./api/raid_melee_hit.php", { user_key: getUserKey(), hits: 1 });
      if (rr.ok && Array.isArray(rr.instances)) EFN.RAID.instances = rr.instances;
    }

    const { dmg, isCrit } = computeDamageFromRange(w.stats.dmg, EFN.state.enemy.def, w.stats.crit || 2);
    EFN.state.enemy.hp = clamp(EFN.state.enemy.hp - dmg, 0, EFN.state.enemy.hpMax);

    await narrate([`${w.name} 사용. ${isCrit ? "치명타! " : ""}${dmg} 피해.`], { tag: "P", log: true });

    if (EFN.state.enemy.hp <= 0) {
      await onEnemyKilled();
      EFN.state.turn += 1;
      return;
    }
    await enemyTurn();
  }

  async function applyThrow(item_id) {
    const r = await apiPost("./api/raid_use_throw.php", { user_key: getUserKey(), item_id });
    if (!r.ok) {
      await narrate([`투척 사용 실패: ${r.error || "unknown"}`], { tag: "SYS", log: true });
      renderAll();
      return false;
    }
    if (r.throw) EFN.RAID.throw = r.throw;
    if (r.inventory) EFN.RAID.inventory = r.inventory;
    renderAll();
    pushStatusToUI();
    return true;
  }

  async function doThrowStone() {
    if (!EFN.state.inCombat || !EFN.state.enemy) return;
    if (availThrow("thr_stone") <= 0) return;
    const ok = await applyThrow("thr_stone");
    if (!ok) return;

    const missPct = 30;
    if (chance(missPct)) {
      await narrate([`돌맹이 투척! 빗나갔습니다. (MISS)`], { tag: "P", log: true });
    } else {
      const dmg = randInt(1, 3);
      EFN.state.enemy.hp = clamp(EFN.state.enemy.hp - dmg, 0, EFN.state.enemy.hpMax);
      await narrate([`돌맹이 적중. ${dmg} 피해.`], { tag: "P", log: true });
    }
    if (EFN.state.enemy.hp <= 0) {
      await onEnemyKilled();
      EFN.state.turn += 1;
      return;
    }
    await enemyTurn();
  }

  async function doThrowIed() {
    if (!EFN.state.inCombat || !EFN.state.enemy) return;
    if (availThrow("thr_ied") <= 0) return;
    const ok = await applyThrow("thr_ied");
    if (!ok) return;

    const dmg = randInt(10, 18);
    EFN.state.enemy.hp = clamp(EFN.state.enemy.hp - dmg, 0, EFN.state.enemy.hpMax);
    await narrate([`급조 수류탄 폭발! ${dmg} 피해.`], { tag: "P", log: true });

    const selfPct = 20;
    if (chance(selfPct)) {
      const sd = randInt(6, 12);
      EFN.state.player.hp = clamp(EFN.state.player.hp - sd, 0, EFN.state.player.hpMax);
      await narrate([`파편 역류! 당신도 ${sd} 피해. (자해)`], { tag: "SYS", log: true });
    }
    if (EFN.state.player.hp <= 0) {
      await handleDeath("자해로 사망했습니다.");
      return;
    }
    if (EFN.state.enemy.hp <= 0) {
      await onEnemyKilled();
      EFN.state.turn += 1;
      return;
    }
    await enemyTurn();
  }

  async function doThrowGrenade() {
    if (!EFN.state.inCombat || !EFN.state.enemy) return;
    if (availThrow("thr_grenade") <= 0) return;
    const ok = await applyThrow("thr_grenade");
    if (!ok) return;

    const dmg = randInt(12, 20);
    EFN.state.enemy.hp = clamp(EFN.state.enemy.hp - dmg, 0, EFN.state.enemy.hpMax);
    await narrate([`수류탄 폭발! ${dmg} 피해.`], { tag: "P", log: true });

    if (EFN.state.enemy.hp <= 0) {
      await onEnemyKilled();
      EFN.state.turn += 1;
      return;
    }
    await enemyTurn();
  }

  function playerHeal() {
    if (!EFN.state.inCombat) return;
    const heal = randInt(8, 14);
    const before = EFN.state.player.hp;
    EFN.state.player.hp = clamp(EFN.state.player.hp + heal, 0, EFN.state.player.hpMax);
    narrate([`응급 처치. HP +${EFN.state.player.hp - before}.`], { tag: "P", log: true });
    enemyTurn();
    pushStatusToUI();
  }

  async function playerRun() {
    if (!EFN.state.inCombat) return;
    const pct = 45;
    if (chance(pct)) {
      await narrate([`후퇴 성공. (성공 ${pct}%)`], { tag: "P", log: true });
      EFN.state.inCombat = false;
      EFN.state.enemy = null;
      EFN.state.location = "탐색 중";
      EFN.state.turn += 1;
      renderAll();
      pushStatusToUI();
      return;
    }
    await narrate([`후퇴 실패. (성공 ${pct}%)`], { tag: "P", log: true });
    await enemyTurn(true);
  }

  async function extract() {
    if (EFN.RAID.status !== "in_raid") return;
    if (EFN.state.inCombat) {
      await narrate(["교전 중에는 탈출할 수 없습니다."], { tag: "SYS", log: true });
      renderAll();
      return;
    }
    const r = await apiPost("./api/raid_extract.php", { user_key: getUserKey() });
    if (!r.ok) {
      await narrate([`탈출 실패: ${r.error || "unknown"}`], { tag: "SYS", log: true });
      renderAll();
      return;
    }
    await narrate(["탈출 성공.", "파밍 아이템이 보관함에 저장되었습니다."], { tag: "EVT", log: true });

    EFN.RAID.status = "idle";
    EFN.RAID.inventory = {};
    EFN.RAID.throw = { thr_stone: 0, thr_ied: 0, thr_grenade: 0 };
    EFN.RAID.brought = { primary: null, secondary: null, melee: null };
    EFN.RAID.instances = [];
    EFN.RAID.weapon_state = {};
    EFN.RAID.armor_state = null;
    EFN.LOADOUT = { primary: null, secondary: null, melee: null };
    EFN.state.location = "은신처";
    await refreshInventory();
    pushStatusToUI();
  }

  // ------------------------------------------------------------
  // ✅ 탐색(A안) - 서버 기반으로 통일 (DEMO 제거)
  // - app.js는 EFN.game.explore()만 호출하면 됨
  // - 선택지는 EFN.push.choices로만 제공
  // ------------------------------------------------------------
  async function explore() {
    const user_key = getUserKey();
    if (!user_key) {
      await narrate(["먼저 '싱글 유저 준비'를 실행하세요."], { tag: "SYS", log: true });
      renderAll();
      return;
    }

    if (narrBusy()) return; // legacy가 있으면 유지, 없으면 app.js가 자체 차단

    await startRaidIfNeeded();
    if (EFN.RAID.status !== "in_raid") return;

    if (EFN.state.player.hp <= 0) {
      await narrate(["사망 상태입니다.", "로컬 리셋 후 다시 시작하세요."], { tag: "SYS", log: true });
      renderAll();
      return;
    }

    if (EFN.state.inCombat) {
      await narrate(["교전 중에는 탐색할 수 없습니다."], { tag: "SYS", log: true });
      renderAll();
      return;
    }

    EFN.state.location = "탐색 중";
    renderAll();
    pushStatusToUI();

    // 기본적으로 선택지 비움(새 UI는 비어있으면 explore 버튼 자동 생성 가능하지만, 여기서 명확히 제어)
    EFN.push.choices([]);

    // 1) 서버 탐색
    let r;
    try {
      r = await serverExplore();
    } catch (e) {
      pushLog("SYS", `탐색 실패: non_json_response (raid_explore.php 확인)`);
      await narrate(["탐색 실패.", "raid_explore.php 응답을 확인하세요."], { tag: "SYS", log: true });
      renderAll();
      return;
    }

    if (!r.ok) {
      await narrate([`탐색 실패: ${r.error || "unknown"}`, (r.hint || "")].filter(Boolean), { tag: "SYS", log: true });
      renderAll();
      return;
    }

    // 2) 지문 출력
    if (r.title) pushLog("EVT", `이벤트: ${r.title}`);
    if (r.text_html) {
      await narrate([r.text_html], { tag: "EVT", log: true });
    } else {
      await narrate(["..."], { tag: "EVT", log: true });
    }

    // 3) 선택지
    const token = r.token || "";
    const choices = Array.isArray(r.choices) ? r.choices : [];

    if (!token || !choices.length) {
      // 선택지 없는 이벤트 -> 계속 탐색만 제공
      setContinueChoice("계속 탐색");
      EFN.state.turn += Number(r?.state_patch?.turn_add || 1);
      await refreshInventory();
      renderAll();
      pushStatusToUI();
      return;
    }

    // ✅ app.js가 버튼 클릭 시 EFN.ui.onChoice를 호출할 수 있도록 연결
    // (app.js에 onChoice 위임이 없다면 explore만 동작하지만, 이 연결을 넣으면 serverChoice가 가능)
    EFN.ui = EFN.ui || {};
    EFN.ui.onChoice = async (choice) => {
      if (!choice || !choice.id) return;
      if (narrBusy()) return;

      pushLog("EVT", `선택: ${choice.label || choice.id}`);

      const rr = await serverChoice(token, choice.id);
      if (!rr.ok) {
        await narrate([`선택 처리 실패: ${rr.error || "unknown"}`], { tag: "SYS", log: true });
        renderAll();
        // 실패 시 같은 선택지를 다시 제공
        setChoicesForUI(choices.map(c => ({ id: c.id, label: c.label || c.id })));
        return;
      }

      await onChoiceResult(rr);
    };

    // 실제 선택지를 UI로 표시
    setChoicesForUI(choices.map(c => ({ id: c.id, label: c.label || c.id })));

    async function onChoiceResult(rr) {
      // 결과 출력 전 선택지 제거
      EFN.push.choices([]);

      if (rr.title) pushLog("EVT", `결과: ${rr.title}`);
      if (rr.text_html) await narrate([rr.text_html], { tag: "EVT", log: true });

      if (Array.isArray(rr.log_lines)) {
        for (const ln of rr.log_lines) pushLog("EVT", String(ln));
      }

      // 턴/상태 패치
      const addTurn = Number(rr?.state_patch?.turn_add || 1);
      EFN.state.turn += addTurn;

      if (rr.inventory && typeof rr.inventory === "object") EFN.RAID.inventory = rr.inventory;
      if (Array.isArray(rr.instances)) EFN.RAID.instances = rr.instances;
      if (rr.throw && typeof rr.throw === "object") EFN.RAID.throw = rr.throw;

      await refreshInventory();
      renderAll();
      pushStatusToUI();

      // 결과 후 계속 탐색 제공
      setContinueChoice("계속 탐색");
    }
  }

  // ------------------------------------------------------------
  // Rest / Starter / Equip (기존 유지)
  // ------------------------------------------------------------
  function rest() {
    if (EFN.RAID.status !== "in_raid") {
      narrate(["출격 중에만 정비가 의미가 있습니다."], { tag: "SYS", log: true });
      renderAll();
      return;
    }
    if (EFN.state.inCombat) {
      narrate(["교전 중에는 정비할 수 없습니다."], { tag: "SYS", log: true });
      renderAll();
      return;
    }
    const heal = randInt(6, 12);
    const before = EFN.state.player.hp;
    EFN.state.player.hp = clamp(EFN.state.player.hp + heal, 0, EFN.state.player.hpMax);
    narrate([`은신/정비. HP +${EFN.state.player.hp - before}.`], { tag: "EVT", log: true });
    EFN.state.turn += 1;
    renderAll();
    pushStatusToUI();
  }

  async function grantStarter() {
    const r = await apiPostTry("./api/starter_grant.php", { user_key: getUserKey() });
    if (!r.ok) {
      pushLog("SYS", "기본 지급 실패: starter_grant.php(다음 단계에서 교체본 제공)");
      return;
    }
    await narrate(["기본 지급 완료.", "(랜덤 내구도/기본 장비/돌맹이 포함)"], { tag: "SYS", log: true });
    await refreshInventory();
    pushStatusToUI();
  }

  async function equip(slot, item_id) {
    const user_key = getUserKey();
    if (!user_key) return;

    const before = EFN.LOADOUT[slot] || null;

    const r = await apiPost("./api/inventory_equip.php", { user_key, slot, item_id });
    if (!r.ok) {
      if (r.error === "raid_bag_not_enough") {
        await narrate(["레이드 중에는 RAID BAG(인스턴스)에서만 스왑할 수 있습니다."], { tag: "SYS", log: true });
        renderAll();
        return;
      }
      if (r.error === "stash_not_enough") {
        await narrate(["STASH에 해당 아이템이 없습니다."], { tag: "SYS", log: true });
        renderAll();
        return;
      }
      pushLog("SYS", `장비 변경 실패: ${r.error || "unknown"} (inventory_equip.php)`);
      renderAll();
      return;
    }

    await refreshInventory();

    const after = EFN.LOADOUT[slot] || null;
    if (!after && before) {
      await narrate([`${slotLabel(slot)} 해제: ${nm(before)} → (해제)`], { tag: "EVT", log: true });
      return;
    }
    if (after && before !== after) {
      if (EFN.RAID.status === "in_raid") {
        await narrate([`스왑 성공: ${slotLabel(slot)} ${nm(before)} → ${nm(after)}`, "(이전 장비는 RAID BAG로 이동)"], { tag: "EVT", log: true });
      } else {
        await narrate([`장착 반영: ${slotLabel(slot)} ${nm(before)} → ${nm(after)}`], { tag: "SYS", log: true });
      }
    } else if (after && before === after) {
      await narrate([`이미 장착 중입니다: ${slotLabel(slot)} ${nm(after)}`], { tag: "SYS", log: true });
    }
    pushStatusToUI();
  }

  async function equipInstance(slot, instance_id) {
    const user_key = getUserKey();
    if (!user_key) return;

    const before = EFN.LOADOUT[slot] || null;

    const r = await apiPost("./api/inventory_equip.php", { user_key, slot, instance_id });
    if (!r.ok) {
      if (r.error === "raid_bag_not_enough") {
        await narrate(["레이드 중에는 RAID BAG 인스턴스로만 스왑할 수 있습니다."], { tag: "SYS", log: true });
        return;
      }
      await narrate([`인스턴스 장착 실패: ${r.error || "unknown"}`], { tag: "SYS", log: true });
      return;
    }

    if (r.inventory && typeof r.inventory === "object") EFN.RAID.inventory = r.inventory;
    if (Array.isArray(r.instances)) EFN.RAID.instances = r.instances;
    if (r.weapon_state) EFN.RAID.weapon_state = r.weapon_state;
    if (r.armor_state) EFN.RAID.armor_state = r.armor_state;

    await refreshInventory();

    const after = EFN.LOADOUT[slot] || null;
    await narrate([`스왑 성공: ${slotLabel(slot)} ${nm(before)} → ${nm(after)}`, "(이전 장비는 RAID BAG로 이동)"], { tag: "EVT", log: true });
    renderAll();
    pushStatusToUI();
  }

  // ------------------------------------------------------------
  // Export (explore는 서버 기반으로 통일됨)
  // ------------------------------------------------------------
  EFN.game = EFN.game || {};
  Object.assign(EFN.game, {
    refreshInventory,
    singleReady,
    explore, rest, extract,
    playerAttack, playerHeal, playerRun,
    doThrowStone, doThrowIed, doThrowGrenade,
    grantStarter,
    equip, equipInstance
  });

})();
