(() => {
  "use strict";

  // ---------- DOM ----------
  const $ = (id) => document.getElementById(id);

  const els = {
    pillConn: $("pillConn"),
    pillRaid: $("pillRaid"),

    inName: $("inName"),
    inKey: $("inKey"),
    btnCreate: $("btnCreate"),
    btnSaveKey: $("btnSaveKey"),

    btnRefresh: $("btnRefresh"),
    btnStart: $("btnStart"),
    btnExplore: $("btnExplore"),
    btnExtract: $("btnExtract"),
    btnDeath: $("btnDeath"),

    btnClearLog: $("btnClearLog"),
    btnAutoScroll: $("btnAutoScroll"),
    log: $("log"),
    preBag: $("preBag"),
    preThrow: $("preThrow"),
    preStash: $("preStash"),

    barStart: $("barStart"),
    barExplore: $("barExplore"),
    barRefresh: $("barRefresh"),

    barMelee: $("barMelee"),
    barGun: $("barGun"),
    barReload: $("barReload"),
    barThrow: $("barThrow"),
    barExtract: $("barExtract"),

    modal: $("modal"),
    modalDim: $("modalDim"),
    mTitle: $("mTitle"),
    mText: $("mText"),
    mChoices: $("mChoices"),
    mClose: $("mClose"),
    mCancel: $("mCancel"),
  };

  // ---------- Storage ----------
  const LS_KEY  = "escape_user_key_v1";
  const LS_NAME = "escape_user_name_v1";

  // ---------- Runtime State ----------
  const S = {
    user_key: localStorage.getItem(LS_KEY) || "",
    name: localStorage.getItem(LS_NAME) || "Player",

    raid_status: "-",
    modalMode: "encounter",

    // encounter context (event modal)
    encounter: null,  // { token, title, text_html, choices[] }
    log: [],
    autoScroll: true,

    stashSnap: null,
    raidSnap: {
      inventory: {},
      throw: {},
      brought: {},
    },

    // throw picker cache
    throwCandidates: [], // [{id,label,qty,type}]
  };

  // ---------- Utils ----------
  const nowTime = () => new Date().toLocaleTimeString("ko-KR", { hour12:false });

  function escapeHtml(s) {
    return String(s)
      .replaceAll("&","&amp;")
      .replaceAll("<","&lt;")
      .replaceAll(">","&gt;")
      .replaceAll('"',"&quot;")
      .replaceAll("'","&#039;");
  }

  function pushLog(tag, msg, cls="") {
    S.log.push({ t: nowTime(), tag, msg, cls });
    renderLog();
  }

  function setConn(text, tone="") {
    els.pillConn.textContent = text;

    const border =
      tone === "ok"   ? "rgba(61,220,151,.45)" :
      tone === "bad"  ? "rgba(255,92,122,.45)" :
      tone === "info" ? "rgba(110,168,255,.45)" :
                        "rgba(255,255,255,.10)";

    const bg =
      tone === "ok"   ? "rgba(61,220,151,.10)" :
      tone === "bad"  ? "rgba(255,92,122,.10)" :
      tone === "info" ? "rgba(110,168,255,.10)" :
                        "rgba(255,255,255,.05)";

    els.pillConn.style.borderColor = border;
    els.pillConn.style.background = bg;
  }

  function setRaidPill(status) {
    els.pillRaid.textContent = `RAID: ${status || "-"}`;
  }

  function requireKey() {
    const uk = (S.user_key || "").trim();
    if (!uk) {
      pushLog("ERR", "user_key가 없습니다. Create/Login 또는 Save Key를 먼저 하세요.", "err");
      return null;
    }
    return uk;
  }

  function requireInRaid() {
    if (S.raid_status !== "in_raid") {
      pushLog("ERR", "이 기능은 in_raid 상태에서만 가능합니다.", "err");
      return false;
    }
    return true;
  }

  function hasEncounter() {
    const b = S.raidSnap?.brought;
    return !!(b && typeof b === "object" && b.encounter && typeof b.encounter === "object");
  }

  function encounterIsDead() {
    const e = S.raidSnap?.brought?.encounter;
    if (!e) return false;

    // dead: 0/1 또는 true/false 모두 방어
    if (e.dead === true) return true;
    if (e.dead === 1 || e.dead === "1") return true;

    const hp = (e.hp != null) ? Number(e.hp) : null;
    if (hp != null && !Number.isNaN(hp) && hp <= 0) return true;
    return false;
  }

  function lootPending() {
    const e = S.raidSnap?.brought?.encounter;
    if (!e) return false;
    if (!e.loot) return false;
    return String(e.loot_state || "") === "pending";
  }

  // ---------- HTTP Helpers ----------
  async function postForm(url, data) {
    const body = new URLSearchParams();
    Object.entries(data || {}).forEach(([k,v]) => body.set(k, String(v ?? "")));

    let res, txt, ctype = "";
    try {
      res = await fetch(url, {
        method: "POST",
        headers: { "Content-Type":"application/x-www-form-urlencoded" },
        body
      });
      ctype = res.headers.get("content-type") || "";
      txt = await res.text();
    } catch (e) {
      return { ok:false, error:"network_error", raw: String(e?.message || e), _url: url };
    }

    let json;
    try { json = JSON.parse(txt); }
    catch {
      json = {
        ok:false,
        error:"invalid_json",
        raw: txt.slice(0, 2000),
        _meta: { status: res?.status, content_type: ctype },
        _url: url
      };
    }

    if (!res.ok && json && typeof json.ok === "undefined") json.ok = false;
    json._meta = json._meta || { status: res?.status, content_type: ctype };
    json._url = url;
    return json;
  }

  async function getJson(url) {
    let res, txt, ctype = "";
    try {
      res = await fetch(url, { method:"GET" });
      ctype = res.headers.get("content-type") || "";
      txt = await res.text();
    } catch (e) {
      return { ok:false, error:"network_error", raw: String(e?.message || e), _url: url };
    }

    let json;
    try { json = JSON.parse(txt); }
    catch {
      json = {
        ok:false,
        error:"invalid_json",
        raw: txt.slice(0, 2000),
        _meta: { status: res?.status, content_type: ctype },
        _url: url
      };
    }

    if (!res.ok && json && typeof json.ok === "undefined") json.ok = false;
    json._meta = json._meta || { status: res?.status, content_type: ctype };
    json._url = url;
    return json;
  }

  // 여러 후보 엔드포인트를 순차 시도(프로젝트 파일명 차이 흡수)
  async function postTry(urls, data) {
    const tried = [];
    let last = null;

    for (const u of urls) {
      tried.push(u);
      const r = await postForm(u, data);
      last = r;

      if (r && r.ok) return { ok:true, res:r, tried };
      // ok:false면 다음 후보 계속
    }

    // 실패 시 마지막 응답도 함께 반환(원인 추적용)
    return { ok:false, tried, last };
  }

  // ---------- Modal ----------
  function openModal(title, html, choices) {
    els.mTitle.textContent = title || "Modal";
    els.mText.innerHTML = html || "";
    els.mChoices.innerHTML = "";

    (choices || []).forEach((c) => {
      const b = document.createElement("button");
      b.className = "btn";
      b.textContent = c.label || c.id || "choice";

      b.onclick = () => {
        const id = c.id;
        if (!id) return;

        if (S.modalMode === "throw") return throwItem(id);

        if (S.modalMode === "combat") {
          if (id === "__melee__")  return meleeHit();
          if (id === "__gun__")    return gunFire();
          if (id === "__reload__") return reloadGun();
          if (id === "__throw__")  return openThrowPicker();
          return;
        }

        if (S.modalMode === "loot") {
          if (id === "__take_all__") return takeLoot("take");
          if (id === "__skip__")     return takeLoot("skip");
          return;
        }

        // 기본: encounter 선택
        S.modalMode = "encounter";
        return onChoose(id);
      };

      els.mChoices.appendChild(b);
    });

    els.modal.setAttribute("aria-hidden", "false");

    // ✅ typing div가 있으면 모달 오픈 후 렌더링 완료 시점에 타이핑 실행(안전)
    try {
      requestAnimationFrame(() => {
        try { runTypewriter(els.mText); } catch (e) {}
      });
    } catch (e) {}
  }

  function closeModal() {
    els.modal.setAttribute("aria-hidden", "true");
    els.mChoices.innerHTML = "";
  }

  // ---------- Rendering ----------
  function renderLog() {
    els.log.innerHTML = "";
    for (const line of S.log.slice(-500)) {
      const div = document.createElement("div");
      div.className = `line ${line.cls || ""}`;
      div.innerHTML =
        `<span class="t">${escapeHtml(line.t)}</span>` +
        `<span class="tag">${escapeHtml(line.tag)}</span>` +
        `<span class="msg">${escapeHtml(line.msg)}</span>`;
      els.log.appendChild(div);
    }
    if (S.autoScroll) els.log.scrollTop = els.log.scrollHeight;
  }

  function renderSnapshots() {
    els.preBag.textContent = JSON.stringify(S.raidSnap.inventory || {}, null, 2);
    els.preThrow.textContent = JSON.stringify({
      throw: S.raidSnap.throw || {},
      brought: S.raidSnap.brought || {}
    }, null, 2);
  }

  function renderButtons() {
    const hasKey = !!(S.user_key && S.user_key.trim());
    const inRaid = S.raid_status === "in_raid";

    els.btnRefresh.disabled = !hasKey;
    els.btnStart.disabled   = !hasKey || inRaid;
    els.btnExplore.disabled = !hasKey || !inRaid;
    els.btnExtract.disabled = !hasKey || !inRaid;
    els.btnDeath.disabled   = !hasKey || !inRaid;

    els.barRefresh.disabled = !hasKey;
    els.barStart.disabled   = !hasKey || inRaid;
    els.barExplore.disabled = !hasKey || !inRaid;

    if (els.barMelee)  els.barMelee.disabled  = !hasKey || !inRaid;
    if (els.barGun)    els.barGun.disabled    = !hasKey || !inRaid;
    if (els.barReload) els.barReload.disabled = !hasKey || !inRaid;
    if (els.barThrow)  els.barThrow.disabled  = !hasKey || !inRaid;

    els.barExtract.disabled = !hasKey || !inRaid;

    if (!hasKey) setConn("DISCONNECTED", "");
    setRaidPill(S.raid_status);
  }

  // ---------- Data Refresh ----------
  async function refreshInventoryGet() {
    const uk = requireKey(); if (!uk) return;

    const r = await getJson(`./api/inventory_get.php?user_key=${encodeURIComponent(uk)}`);
    if (!r.ok) {
      pushLog("ERR", `inventory_get 실패: ${r.error || "unknown"}`, "err");
      if (r._meta) pushLog("ERR", `meta: ${JSON.stringify(r._meta)}`, "err");
      if (r.raw) pushLog("ERR", r.raw, "err");
      return;
    }

    S.stashSnap = r;
    els.preStash.textContent = JSON.stringify(r, null, 2);
    pushLog("SYS", "inventory_get OK", "sys");
  }

  async function refreshRaidState() {
    const uk = requireKey(); if (!uk) return;

    const s = await postForm("./api/raid_status.php", { user_key: uk });
    if (!s.ok) {
      pushLog("ERR", `raid_status 실패: ${s.error || "unknown"}`, "err");
      if (s._meta) pushLog("ERR", `meta: ${JSON.stringify(s._meta)}`, "err");
      if (s.raw) pushLog("ERR", s.raw, "err");
      S.raid_status = "-";
      setRaidPill(S.raid_status);
      return;
    }

    S.raid_status = s.status || "idle";
    setRaidPill(S.raid_status);

    if (S.raid_status === "in_raid") {
      const r = await postForm("./api/raid_state_get.php", { user_key: uk });
      if (!r.ok) {
        pushLog("ERR", `raid_state_get 실패: ${r.error || "unknown"}`, "err");
        if (r._meta) pushLog("ERR", `meta: ${JSON.stringify(r._meta)}`, "err");
        if (r.raw) pushLog("ERR", r.raw, "err");
        return;
      }

      S.raidSnap.inventory = r.inventory || {};
      S.raidSnap.throw = r.throw || {};
      S.raidSnap.brought = r.brought || {};
      renderSnapshots();

      // 조우/루팅 상태 로그(간단)
      const e = S.raidSnap?.brought?.encounter;
      if (e) {
        const nm = e.name || e.type || e.npc_type || "enemy";
        const hp = (e.hp != null) ? String(e.hp) : "?";
        if (encounterIsDead()) {
          pushLog("SYS", `ENCOUNTER DEAD: ${nm} (HP:0)`, "sys");
          if (lootPending() && S.modalMode !== "loot") {
            openLootModal();
          }
        } else {
          pushLog("SYS", `ENCOUNTER ACTIVE: ${nm} (HP:${hp})`, "sys");
        }
      }

      pushLog("SYS", "raid_state_get OK", "sys");
    } else {
      S.raidSnap.inventory = {};
      S.raidSnap.throw = {};
      S.raidSnap.brought = {};
      renderSnapshots();
    }
  }

  async function refreshAll() {
    setConn("OK", "ok");
    await refreshInventoryGet();
    await refreshRaidState();
    renderButtons();
  }

  // ---------- Core Actions ----------
  async function createOrLogin() {
    const name = (els.inName.value || S.name || "Player").trim() || "Player";
    S.name = name;
    localStorage.setItem(LS_NAME, name);

    setConn("CONNECTING", "info");
    pushLog("SYS", `hello.php 요청: name=${name}`, "sys");

    const r = await postForm("./api/hello.php", { name });
    if (!r.ok) {
      setConn("ERROR", "bad");
      pushLog("ERR", `hello 실패: ${r.error || "unknown"}`, "err");
      if (r._meta) pushLog("ERR", `meta: ${JSON.stringify(r._meta)}`, "err");
      if (r.raw) pushLog("ERR", r.raw, "err");
      return;
    }

    const uk = r.user_key || r.userKey || r.uk || "";
    if (!uk) {
      setConn("ERROR", "bad");
      pushLog("ERR", "hello 응답에 user_key가 없습니다.", "err");
      return;
    }

    S.user_key = uk;
    localStorage.setItem(LS_KEY, uk);
    els.inKey.value = uk;

    setConn("OK", "ok");
    pushLog("SYS", `로그인 완료: ${uk}`, "sys");

    await refreshAll();
  }

  async function saveKeyOnly() {
    const k = (els.inKey.value || "").trim();
    if (!k) {
      pushLog("ERR", "빈 user_key는 저장할 수 없습니다.", "err");
      return;
    }
    S.user_key = k;
    localStorage.setItem(LS_KEY, k);
    pushLog("SYS", `user_key 저장: ${k}`, "sys");
    setConn("KEY SAVED", "info");
    await refreshAll();
  }

  async function startRaid() {
    const uk = requireKey(); if (!uk) return;

    pushLog("SYS", "raid_start 요청", "sys");
    const r = await postForm("./api/raid_start.php", { user_key: uk });

    if (!r.ok) {
      pushLog("ERR", `raid_start 실패: ${r.error || "unknown"}`, "err");
      if (r._meta) pushLog("ERR", `meta: ${JSON.stringify(r._meta)}`, "err");
      if (r.raw) pushLog("ERR", r.raw, "err");
      return;
    }

    pushLog("SYS", "출격이 시작되었습니다.", "sys");
    await refreshRaidState();
    renderButtons();
  }

  async function explore() {
    const uk = requireKey(); if (!uk) return;
    if (!requireInRaid()) return;

    // 조우 중이면 상황 탐색 대신 전투 UI
    if (hasEncounter() && !encounterIsDead()) {
      pushLog("SYS", "조우 중 → Explore 대신 전투 화면 오픈", "sys");
      openCombatModal();
      return;
    }

    // 조우가 죽고 루팅 pending이면 루팅 우선
    if (hasEncounter() && encounterIsDead() && lootPending()) {
      openLootModal();
      return;
    }

    pushLog("SYS", "raid_explore 요청", "sys");
    const r = await postForm("./api/raid_explore.php", { user_key: uk });

    if (!r.ok) {
      pushLog("ERR", `raid_explore 실패: ${r.error || "unknown"}`, "err");
      if (r._meta) pushLog("ERR", `meta: ${JSON.stringify(r._meta)}`, "err");
      if (r.raw) pushLog("ERR", r.raw, "err");
      return;
    }

    S.encounter = {
      token: r.token || "",
      title: r.title || r.kind || "Encounter",
      text_html: r.text_html || "",
      choices: Array.isArray(r.choices) ? r.choices : []
    };

    S.modalMode = "encounter";
    pushLog("SYS", `Explore Event: ${S.encounter.title}`, "sys");
    openModal(S.encounter.title, S.encounter.text_html, S.encounter.choices);

    await refreshRaidState();
    renderButtons();
  }

  async function onChoose(choice_id) {
    const uk = requireKey(); if (!uk) return;

    // ✅ token이 비어있으면 탐색을 다시 유도(UX)
    const token = (S.encounter?.token || "").trim();
    if (!token) {
      pushLog("ERR", "선택 토큰이 없습니다. Explore를 다시 눌러 이벤트를 새로 받으세요.", "err");
      return;
    }

    pushLog("SYS", `choice: ${choice_id}`, "sys");
    const r = await postForm("./api/raid_choice.php", { user_key: uk, token, choice_id });

    if (!r.ok) {
      pushLog("ERR", `raid_choice 실패: ${r.error || "unknown"}`, "err");
      if (r._meta) pushLog("ERR", `meta: ${JSON.stringify(r._meta)}`, "err");
      if (r.raw) pushLog("ERR", r.raw, "err");
      return;
    }

    if (Array.isArray(r.log_lines)) r.log_lines.forEach((ln) => pushLog("LOG", String(ln)));

    // ✅ 서버가 다음 단계용 token을 내려주는 설계로 바뀌어도 유지
    if (r.token && S.encounter) {
      S.encounter.token = String(r.token);
    }

    const title = r.title || "Result";
    const html = r.text_html || "";
    const nextChoices = Array.isArray(r.choices) ? r.choices : [];

    S.modalMode = "encounter";
    if (html) openModal(title, html, nextChoices);
    else closeModal();

    await refreshRaidState();
    renderButtons();

    // choice로 인해 즉시 조우가 생성될 수 있음
    if (hasEncounter() && !encounterIsDead()) {
      openCombatModal();
    }
  }

  async function endRaid(result) {
    const uk = requireKey(); if (!uk) return;
    if (!requireInRaid()) return;

    pushLog("SYS", `raid_end 요청: ${result}`, "sys");
    const r = await postForm("./api/raid_end.php", { user_key: uk, result });

    if (!r.ok) {
      pushLog("ERR", `raid_end 실패: ${r.error || "unknown"}`, "err");
      if (r._meta) pushLog("ERR", `meta: ${JSON.stringify(r._meta)}`, "err");
      if (r.raw) pushLog("ERR", r.raw, "err");
      return;
    }

    pushLog("SYS", `raid_end OK (${result})`, "sys");
    closeModal();
    await refreshAll();
  }

  // ---------- Combat ----------
  async function meleeHit() {
    const uk = requireKey(); if (!uk) return;
    if (!requireInRaid()) return;

    pushLog("SYS", "raid_melee_hit 요청", "sys");
    const r = await postForm("./api/raid_melee_hit.php", { user_key: uk });

    if (!r.ok) {
      pushLog("ERR", `raid_melee_hit 실패: ${r.error || "unknown"}`, "err");
      if (r._meta) pushLog("ERR", `meta: ${JSON.stringify(r._meta)}`, "err");
      if (r.raw) pushLog("ERR", r.raw, "err");
      return;
    }

    if (Array.isArray(r.log_lines)) r.log_lines.forEach((ln) => pushLog("LOG", String(ln)));
    if (r.msg) pushLog("LOG", String(r.msg));

    await refreshRaidState();
    renderButtons();

    if (hasEncounter() && encounterIsDead() && lootPending()) {
      openLootModal();
    } else if (hasEncounter() && !encounterIsDead()) {
      openCombatModal();
    } else {
      closeModal();
    }
  }

  async function gunFire() {
    const uk = requireKey(); if (!uk) return;
    if (!requireInRaid()) return;

    pushLog("SYS", "combat_gun 요청", "sys");
    const r = await postForm("./api/combat_gun.php", { user_key: uk });

    if (!r.ok) {
      pushLog("ERR", `combat_gun 실패: ${r.error || "unknown"}`, "err");
      if (r._meta) pushLog("ERR", `meta: ${JSON.stringify(r._meta)}`, "err");
      if (r.raw) pushLog("ERR", r.raw, "err");
      return;
    }

    if (Array.isArray(r.log_lines)) r.log_lines.forEach((ln) => pushLog("LOG", String(ln)));
    if (r.msg) pushLog("LOG", String(r.msg));

    await refreshRaidState();
    renderButtons();

    if (hasEncounter() && encounterIsDead() && lootPending()) {
      openLootModal();
    } else if (hasEncounter() && !encounterIsDead()) {
      openCombatModal();
    } else {
      closeModal();
    }
  }

  async function reloadGun() {
    const uk = requireKey(); if (!uk) return;
    if (!requireInRaid()) return;

    pushLog("SYS", "raid_reload 요청", "sys");
    const r = await postForm("./api/raid_reload.php", { user_key: uk });

    if (!r.ok) {
      pushLog("ERR", `raid_reload 실패: ${r.error || "unknown"}`, "err");
      if (r._meta) pushLog("ERR", `meta: ${JSON.stringify(r._meta)}`, "err");
      if (r.raw) pushLog("ERR", r.raw, "err");
      return;
    }

    if (Array.isArray(r.log_lines)) r.log_lines.forEach((ln) => pushLog("LOG", String(ln)));
    if (r.msg) pushLog("LOG", String(r.msg));

    await refreshRaidState();
    renderButtons();
    openCombatModal();
  }

  function openCombatModal() {
    if (!requireInRaid()) return;

    const e = S.raidSnap?.brought?.encounter;
    if (!e) {
      S.modalMode = "combat";
      openModal("Combat", "<p>현재 조우 중인 적이 없습니다.</p>", []);
      return;
    }

    const nm = e.name || e.type || e.npc_type || "Enemy";
    const hp = (e.hp != null) ? String(e.hp) : "?";

    S.modalMode = "combat";
    openModal(
      `ENCOUNTER: ${nm} (HP:${hp})`,
      `<p><b>${escapeHtml(nm)}</b> 와(과) 조우 중입니다.<br/>전투 행동을 선택하세요.</p>`,
      [
        { id:"__melee__",  label:"근접 공격" },
        { id:"__gun__",    label:"총기 사격" },
        { id:"__reload__", label:"재장전" },
        { id:"__throw__",  label:"투척" },
      ]
    );
  }

  // =========================================================
  // Loot UI/Action
  // =========================================================
  // ✅ PATCH: loot 구조가 무엇이든 최대한 파싱해서 목록 표시
  function buildLootSummaryHtml(loot) {
    // 0) loot가 문자열(JSON string)인 경우 파싱 시도
    try {
      if (typeof loot === "string" && loot.trim()) {
        loot = JSON.parse(loot);
      }
    } catch (_) {}

    // 1) {loot_json:"..."} / {data:"..."} 같은 경우도 파싱 시도
    if (loot && typeof loot === "object") {
      for (const k of ["loot_json", "json", "raw", "data"]) {
        const v = loot[k];
        if (typeof v === "string" && v.trim()) {
          try { loot = JSON.parse(v); break; } catch (_) {}
        }
      }
    }

    const addStack = (map, id, qty) => {
      const key = String(id || "").trim();
      const q = Number(qty);
      if (!key) return;
      if (!Number.isFinite(q) || q <= 0) return;
      map[key] = (map[key] || 0) + q;
    };

    // 어떤 형태든 최종적으로 {item_id: qty}로 정규화
    const stacks = {};

    // (A) loot가 배열인 경우
    if (Array.isArray(loot)) {
      for (const it of loot) {
        if (!it || typeof it !== "object") continue;
        const id = it.item_id || it.id || it.code || it.key || it.name;
        const qty = it.qty ?? it.count ?? it.amount ?? 1;
        addStack(stacks, id, qty);
      }
    }

    // (B) loot.items / loot.list / loot.drops 같은 배열 키 지원
    if (!Object.keys(stacks).length && loot && typeof loot === "object") {
      const arrKeys = ["items", "list", "drops", "drop_list", "loot_items"];
      for (const k of arrKeys) {
        if (Array.isArray(loot[k])) {
          for (const it of loot[k]) {
            if (!it || typeof it !== "object") continue;
            const id = it.item_id || it.id || it.code || it.key || it.name;
            const qty = it.qty ?? it.count ?? it.amount ?? 1;
            addStack(stacks, id, qty);
          }
        }
      }
    }

    // (C) loot.stacks / loot.items 가 object-map 형태 {id:qty}인 경우
    if (!Object.keys(stacks).length && loot && typeof loot === "object") {
      const mapKeys = ["stacks", "items", "loot", "drop_map"];
      for (const k of mapKeys) {
        const m = loot[k];
        if (m && typeof m === "object" && !Array.isArray(m)) {
          for (const [id, qty] of Object.entries(m)) addStack(stacks, id, qty);
        }
      }
    }

    // (D) loot 자체가 평평한 object-map {id:qty} 인 경우
    if (!Object.keys(stacks).length && loot && typeof loot === "object" && !Array.isArray(loot)) {
      const skip = new Set(["state","loot_state","ts","tier","seed","roll","kind","type","name"]);
      for (const [id, qty] of Object.entries(loot)) {
        if (skip.has(id)) continue;
        addStack(stacks, id, qty);
      }
    }

    const lines = [];
    for (const [id, qty] of Object.entries(stacks)) {
      lines.push(`- ${id} x${qty}`);
    }

    const txt = lines.length
      ? `전리품을 확인했다.\n가져갈까?\n\n${lines.join("\n")}`
      : `전리품 정보가 비어있거나 구조를 해석할 수 없습니다.\n(raid_state_get의 brought.encounter.loot 값을 확인해 주세요)`;

    return `<div class="typing" data-typing="1" data-text="${escapeHtml(txt)}"></div>`;
  }

  function openLootModal() {
    const e = S.raidSnap?.brought?.encounter;
    const nm = e?.name || e?.type || e?.npc_type || "Enemy";
    const loot = e?.loot;

    S.modalMode = "loot";
    openModal(
      `LOOT: ${nm}`,
      buildLootSummaryHtml(loot),
      [
        { id:"__take_all__", label:"전리품 획득" },
        { id:"__skip__",     label:"그냥 떠난다" },
      ]
    );
  }

  async function takeLoot(action) {
    const uk = requireKey(); if (!uk) return;
    if (!requireInRaid()) return;

    const urls = [
      "./api/raid_loot_take.php",
      "./api/loot_take.php",
      "./api/raid_loot.php",
      "./api/raid_take_loot.php",
    ];

    pushLog("SYS", `loot action: ${action}`, "sys");

    const tried = await postTry(urls, { user_key: uk, action });
    if (!tried.ok) {
      pushLog("ERR", `전리품 처리 실패. 시도: ${tried.tried.join(", ")}`, "err");
      if (tried.last?.error) pushLog("ERR", `last.error: ${tried.last.error}`, "err");
      if (tried.last?._meta) pushLog("ERR", `last.meta: ${JSON.stringify(tried.last._meta)}`, "err");
      if (tried.last?.raw) pushLog("ERR", tried.last.raw, "err");
      pushLog("HINT", "서버 전리품 API 파일명을 알려주면(또는 코드 붙여주면) 정확히 맞춰 드립니다.", "warn");
      return;
    }

    const r = tried.res;
    if (Array.isArray(r.log_lines)) r.log_lines.forEach((ln) => pushLog("LOG", String(ln)));
    if (r.msg) pushLog("LOG", String(r.msg));

    pushLog("SYS", `loot OK via ${r._url}`, "sys");

    await refreshRaidState();
    renderButtons();
    closeModal();
  }

  // =========================================================
  // Throw UI/Action (“번호모달” 제거)
  // =========================================================
  function normalizeThrowCandidates() {
    const out = [];

    const tj = S.raidSnap.throw;
    if (tj && typeof tj === "object") {
      if (Array.isArray(tj.items)) {
        for (const it of tj.items) {
          const id = it.item_id || it.id;
          if (!id) continue;
          const name = it.name || id;
          const qty = (it.qty != null) ? Number(it.qty) : 1;
          out.push({ id, label: `${name} (x${Number.isFinite(qty)?qty:1})`, qty, type:"throw" });
        }
      }
      if (Array.isArray(tj.list)) {
        for (const it of tj.list) {
          const id = it.item_id || it.id;
          if (!id) continue;
          const name = it.name || id;
          const qty = (it.qty != null) ? Number(it.qty) : 1;
          out.push({ id, label: `${name} (x${Number.isFinite(qty)?qty:1})`, qty, type:"throw" });
        }
      }
      for (const [k,v] of Object.entries(tj)) {
        if (k === "items" || k === "list") continue;
        if (typeof v === "number" || (typeof v === "string" && v !== "" && !Number.isNaN(Number(v)))) {
          const qty = Number(v);
          if (qty > 0) out.push({ id:k, label: `${k} (x${qty})`, qty, type:"throw" });
        }
      }
    }

    const bag = S.raidSnap.inventory;
    if (bag && typeof bag === "object") {
      if (Array.isArray(bag.items)) {
        for (const it of bag.items) {
          const id = it.item_id || it.id;
          if (!id) continue;
          const type = String(it.type || "");
          const name = it.name || id;
          const qty = (it.qty != null) ? Number(it.qty) : 1;

          const guessThrowable =
            type === "throw" || type === "throwable" ||
            id.startsWith("thr_") ||
            /grenade|molotov|flash|smoke/i.test(id) ||
            /grenade|molotov|flash|smoke/i.test(name);

          if (guessThrowable && qty > 0) {
            out.push({ id, label: `${name} (x${Number.isFinite(qty)?qty:1})`, qty, type:"throw" });
          }
        }
      }

      if (bag.stacks && typeof bag.stacks === "object") {
        for (const [id,qty0] of Object.entries(bag.stacks)) {
          const qty = Number(qty0);
          if (!Number.isFinite(qty) || qty <= 0) continue;
          const guessThrowable = id.startsWith("thr_") || /grenade|molotov|flash|smoke/i.test(id);
          if (guessThrowable) out.push({ id, label: `${id} (x${qty})`, qty, type:"throw" });
        }
      }
    }

    const map = new Map();
    for (const x of out) {
      const prev = map.get(x.id);
      if (!prev) map.set(x.id, x);
      else {
        const q1 = Number(prev.qty || 0);
        const q2 = Number(x.qty || 0);
        if (q2 > q1) map.set(x.id, x);
      }
    }

    return Array.from(map.values())
      .sort((a,b) => String(a.id).localeCompare(String(b.id)));
  }

  function openThrowPicker() {
    if (!requireInRaid()) return;

    S.throwCandidates = normalizeThrowCandidates();

    if (!S.throwCandidates.length) {
      S.modalMode = "combat";
      openModal(
        "투척",
        `<div class="typing" data-typing="1" data-text="${escapeHtml("투척 가능한 아이템이 없습니다.\n(raidbag/inventory_json 또는 throw_json에 투척템이 있어야 합니다.)")}"></div>`,
        [
          { id:"__melee__",  label:"근접 공격" },
          { id:"__gun__",    label:"총기 사격" },
          { id:"__reload__", label:"재장전" },
          { id:"__throw__",  label:"투척(재시도)" },
        ]
      );
      return;
    }

    S.modalMode = "throw";
    openModal(
      "투척 아이템 선택",
      `<div class="typing" data-typing="1" data-text="${escapeHtml("던질 아이템을 선택하세요.")}"></div>`,
      S.throwCandidates.map(x => ({ id: x.id, label: x.label }))
        .concat([{ id:"__cancel__", label:"취소" }])
    );
  }

  async function throwItem(item_id) {
    if (item_id === "__cancel__") {
      openCombatModal();
      return;
    }

    const uk = requireKey(); if (!uk) return;
    if (!requireInRaid()) return;

    const urls = [
      "./api/raid_throw.php",
      "./api/throw_use.php",
      "./api/raid_throw_use.php",
      "./api/raid_item_throw.php",
    ];

    pushLog("SYS", `throw: ${item_id}`, "sys");

    const tried = await postTry(urls, { user_key: uk, item_id });
    if (!tried.ok) {
      pushLog("ERR", `투척 실패. 시도: ${tried.tried.join(", ")}`, "err");
      if (tried.last?.error) pushLog("ERR", `last.error: ${tried.last.error}`, "err");
      if (tried.last?._meta) pushLog("ERR", `last.meta: ${JSON.stringify(tried.last._meta)}`, "err");
      if (tried.last?.raw) pushLog("ERR", tried.last.raw, "err");
      pushLog("HINT", "서버 투척 API 파일명을 알려주면 정확히 맞춰 드립니다.", "warn");
      openCombatModal();
      return;
    }

    const r = tried.res;
    if (Array.isArray(r.log_lines)) r.log_lines.forEach((ln) => pushLog("LOG", String(ln)));
    if (r.msg) pushLog("LOG", String(r.msg));

    pushLog("SYS", `throw OK via ${r._url}`, "sys");

    await refreshRaidState();
    renderButtons();

    if (hasEncounter() && encounterIsDead() && lootPending()) {
      openLootModal();
    } else if (hasEncounter() && !encounterIsDead()) {
      openCombatModal();
    } else {
      closeModal();
    }
  }

  // ---------- Wire / Init ----------
  function wire() {
    els.btnCreate.onclick  = createOrLogin;
    els.btnSaveKey.onclick = saveKeyOnly;

    els.btnRefresh.onclick = refreshAll;
    els.btnStart.onclick   = startRaid;
    els.btnExplore.onclick = explore;
    els.btnExtract.onclick = () => endRaid("extract");
    els.btnDeath.onclick   = () => endRaid("death");

    els.barStart.onclick   = startRaid;
    els.barExplore.onclick = explore;
    els.barRefresh.onclick = refreshAll;
    els.barExtract.onclick = () => endRaid("extract");

    if (els.barMelee)  els.barMelee.onclick  = meleeHit;
    if (els.barGun)    els.barGun.onclick    = gunFire;
    if (els.barReload) els.barReload.onclick = reloadGun;
    if (els.barThrow)  els.barThrow.onclick  = openThrowPicker;

    els.btnClearLog.onclick = () => { S.log = []; renderLog(); };
    els.btnAutoScroll.onclick = () => {
      S.autoScroll = !S.autoScroll;
      els.btnAutoScroll.textContent = `AutoScroll: ${S.autoScroll ? "ON" : "OFF"}`;
    };

    els.modalDim.onclick = closeModal;
    els.mClose.onclick   = closeModal;
    els.mCancel.onclick  = closeModal;
  }

  function init() {
    els.inName.value = S.name || "Player";
    els.inKey.value  = S.user_key || "";

    pushLog("SYS", "UI Minimal Ready (Typing Auto + Throw/Loot Modal Upgrade)", "sys");
    renderButtons();

    if (S.user_key && S.user_key.trim()) {
      setConn("OK", "ok");
      refreshAll().catch(() => {});
    }
  }

  wire();
  init();
})();

/* ================================
 * Typewriter (Modal Text)
 * ================================ */
function sleep(ms){ return new Promise(r => setTimeout(r, ms)); }

// root(모달 텍스트 영역) 안에 .typing[data-typing="1"] 있으면 타이핑 실행
async function runTypewriter(root){
  if (!root) return;

  const el = root.querySelector('.typing[data-typing="1"]');
  if (!el) return;

  const token = String(Date.now()) + '_' + String(Math.random());
  el.dataset.twToken = token;

  const text = el.getAttribute('data-text') || '';
  el.innerHTML = '';

  const esc = (s) => String(s)
    .replaceAll('&','&amp;')
    .replaceAll('<','&lt;')
    .replaceAll('>','&gt;')
    .replaceAll('"','&quot;')
    .replaceAll("'","&#039;");

  for (let i = 0; i < text.length; i++){
    if (el.dataset.twToken !== token) return;

    const ch = text[i];
    if (ch === '\n') el.insertAdjacentHTML('beforeend', '<br/>');
    else el.insertAdjacentHTML('beforeend', esc(ch));

    let delay = 14 + Math.floor(Math.random() * 9);
    if (ch === '.' || ch === '!' || ch === '?' ) delay += 120;
    else if (ch === ',') delay += 60;

    await sleep(delay);
  }
}
