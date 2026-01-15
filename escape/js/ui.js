(() => {
  "use strict";
  const EFN = window.EFN;
  const { el, escapeHtml, setBar, clamp } = EFN;
  const { getUserKey } = EFN.api;

  function nm(id){
    if (!id) return "-";
    return EFN.ITEM_MAP[id]?.name || id;
  }
  function slotLabel(slot){
    if (slot === "primary") return "주무기";
    if (slot === "secondary") return "보조";
    if (slot === "melee") return "근접";
    if (slot === "armor") return "방어구";
    return slot;
  }

  function setApiStatus(text, level){
    el("apiStatus").textContent = text;
    const dot = el("apiDot");
    dot.className = "dot";
    if (level) dot.classList.add(level);
  }

  function tagLabel(tag){
    if (tag === "SYS") return { cls:"info", name:"SYSTEM" };
    if (tag === "P")   return { cls:"ok",   name:"YOU" };
    if (tag === "E")   return { cls:"bad",  name:"HOSTILE" };
    if (tag === "EVT") return { cls:"warn", name:"EVENT" };
    return { cls:"", name:tag };
  }

  /* =========================================================
     ✅ Typewriter for Log
     - 새 로그 1줄 생성 후 메시지 부분을 타이핑
     - 클릭/스페이스/엔터로 스킵
     - queue로 순서 보장
     ========================================================= */
  const Type = {
    busy: false,
    skip: false,
    queue: Promise.resolve(),
    speed: 14,        // 기본 타이핑 속도(ms)
    speedCombat: 10,  // 전투(짧은 문장) 조금 빠르게
  };

  function _bindTypeSkip(){
    const onKey = (e) => {
      if (e.key === " " || e.key === "Enter") Type.skip = true;
    };
    const onClick = () => { Type.skip = true; };
    window.addEventListener("keydown", onKey, { passive:true });
    window.addEventListener("click", onClick, { passive:true });
  }
  _bindTypeSkip();

  function isTyping(){ return Type.busy; }

  async function typeInto(spanEl, text, speed){
    Type.busy = true;
    Type.skip = false;

    const sp = Math.max(6, Number(speed || Type.speed));
    spanEl.textContent = "";

    let out = "";
    for (let i=0; i<text.length; i++){
      if (Type.skip){
        spanEl.textContent = text;
        out = text;
        break;
      }
      out += text[i];
      spanEl.textContent = out;
      await new Promise(r => setTimeout(r, sp));
    }

    Type.busy = false;
  }

  function enqueueTyping(fn){
    Type.queue = Type.queue.then(fn).catch(()=>{});
    return Type.queue;
  }

  /* =========================================================
     pushLog: 기존처럼 state.log 적재는 유지
     - 단, renderLogAppend에서 "새로 추가되는 줄"은 타이핑 처리
     ========================================================= */
  function pushLog(tag, text, opt = {}){
    EFN.state.log.push({ t:Date.now(), tag, text });
    if (EFN.state.log.length > 260) EFN.state.log.shift();

    // opt.typing === false 이면 즉시 출력 (서버 동기화/에러 등)
    renderLogAppend({ typing: opt.typing !== false });
  }

  function renderLogAppend(opt = {}){
    const logEl = el("log");
    const wantTyping = opt.typing !== false;

    // 새로 렌더링해야 할 것들만 추가
    for (; EFN.lastLogRendered < EFN.state.log.length; EFN.lastLogRendered++){
      const item = EFN.state.log[EFN.lastLogRendered];
      const tag = tagLabel(item.tag);

      const line = document.createElement("div");
      line.className = "line";

      const tt = new Date(item.t);
      const h = String(tt.getHours()).padStart(2,"0");
      const m = String(tt.getMinutes()).padStart(2,"0");
      const s = String(tt.getSeconds()).padStart(2,"0");

      // ✅ 메시지는 span.msg에 "텍스트로" 찍는다 (escapeHtml 결과를 textContent로 넣기 위해)
      // 기존처럼 innerHTML로 통째로 박으면 타이핑 제어가 불가능함.
      line.innerHTML = `
        <span class="t">${h}:${m}:${s}</span>
        <span class="tag2 ${tag.cls}">${tag.name}</span>
        <span class="msg"></span>
      `;
      const msgSpan = line.querySelector(".msg");
      logEl.appendChild(line);

      // 메시지 원문은 escapeHtml로 안전 처리 후, textContent로 출력
      // (HTML 태그 쓰지 않는 로그 구조이므로 textContent가 안전/정확)
      const safeText = (typeof item.text === "string") ? item.text : String(item.text || "");
      const finalText = safeText; // 여기서는 텍스트만 타이핑

      // ✅ 타이핑 여부 결정:
      // - SYS 중 일부는 즉시(네트워크/동기화 메시지 등)로 쓰고 싶으면 pushLog(tag, text, {typing:false})
      // - 전투(P/E)는 빠르게
      const speed =
        (item.tag === "P" || item.tag === "E") ? Type.speedCombat : Type.speed;

      if (wantTyping){
        enqueueTyping(async () => {
          // 혹시 로그가 엄청 쌓인 후에도 순서대로 타이핑 보장
          await typeInto(msgSpan, finalText, speed);
          logEl.scrollTop = logEl.scrollHeight;
        });
      } else {
        msgSpan.textContent = finalText;
        logEl.scrollTop = logEl.scrollHeight;
      }
    }

    while (logEl.children.length > 320) logEl.removeChild(logEl.firstChild);
    logEl.scrollTop = logEl.scrollHeight;
  }

  function availThrow(id){
    return (Number(EFN.RAID.throw?.[id] || 0) + Number(EFN.RAID.inventory?.[id] || 0));
  }

  function curWeaponSlot(){
    if (EFN.LOADOUT.primary) return "primary";
    if (EFN.LOADOUT.secondary) return "secondary";
    return "melee";
  }

  function renderPlayer(){
    el("loc").textContent = EFN.state.location;
    el("zone").textContent = "R1";
    el("turnNo").textContent = String(EFN.state.turn);

    el("pName").textContent = (el("singleName").value || localStorage.getItem(EFN.LS_NAME) || "Survivor");
    el("pHp").textContent = EFN.state.player.hp;
    el("pHpMax").textContent = EFN.state.player.hpMax;
    el("pDef").textContent = EFN.state.player.def;
    setBar(el("pHpBar"), EFN.state.player.hp, EFN.state.player.hpMax);

    el("raidStatus").textContent = EFN.RAID.status;

    el("tStone").textContent = availThrow("thr_stone");
    el("tIed").textContent   = availThrow("thr_ied");
    el("tG").textContent     = availThrow("thr_grenade");

    el("eqNowPrimary").textContent   = nm(EFN.LOADOUT.primary);
    el("eqNowSecondary").textContent = nm(EFN.LOADOUT.secondary);
    el("eqNowMelee").textContent     = nm(EFN.LOADOUT.melee);

    const b = EFN.RAID.brought || {primary:null,secondary:null,melee:null};
    el("broughtHint").textContent =
      `출격 반입 장비(유실 위험): 주무기 ${nm(b.primary)} / 보조 ${nm(b.secondary)} / 근접 ${nm(b.melee)}`;

    const ws = EFN.RAID.weapon_state || {};
    const slot = curWeaponSlot();
    const ammoTxt = (ws[slot] && ws[slot].ammo_type)
      ? `${ws[slot].ammo_type} ${ws[slot].ammo_loaded ?? 0}`
      : (slot === "melee" ? "—" : "0");
    el("hudAmmo").textContent = ammoTxt;

    const ar = EFN.RAID.armor_state;
    el("hudArmor").textContent = ar ? `${ar.name || ar.item_id} ${ar.durability}/${ar.durability_max}` : "—";

    const hasUser = !!getUserKey();

    el("btnInvRefresh").disabled = !hasUser;
    el("btnBagRefresh").disabled = !hasUser;
    el("btnExtract").disabled = !(hasUser && EFN.RAID.status === "in_raid" && !EFN.state.inCombat);

    el("actAttack").disabled = !(hasUser && EFN.RAID.status === "in_raid" && EFN.state.inCombat);
    el("actThrow").disabled  = !(hasUser && EFN.RAID.status === "in_raid" && EFN.state.inCombat);
    el("actHeal").disabled   = !(hasUser && EFN.RAID.status === "in_raid" && EFN.state.inCombat);
    el("actRun").disabled    = !(hasUser && EFN.RAID.status === "in_raid" && EFN.state.inCombat);

    el("mExplore").disabled = !hasUser;
    el("mRest").disabled = !(hasUser && EFN.RAID.status === "in_raid" && !EFN.state.inCombat);
    el("mExtract").disabled = !(hasUser && EFN.RAID.status === "in_raid" && !EFN.state.inCombat);
    el("mBag").disabled = !hasUser;
    el("mSync").disabled = !hasUser;

    el("mAttack").disabled = el("actAttack").disabled;
    el("mThrow").disabled  = el("actThrow").disabled;
    el("mHeal").disabled   = el("actHeal").disabled;
    el("mRun").disabled    = el("actRun").disabled;
  }

  function buildEquipOptions(){
    const noneOpt = (label) => `<option value="">(해제) ${label}</option>`;
    const items = Object.values(EFN.ITEM_MAP);

    const stashQty = {};
    for (const s of EFN.STASH) stashQty[s.item_id] = (stashQty[s.item_id] ?? 0) + Number(s.qty||0);

    const bagQty = {};
    for (const [id,q] of Object.entries(EFN.RAID.inventory || {})) bagQty[id] = Number(q||0);

    const source = (EFN.RAID.status === "in_raid") ? "bag" : "stash";
    const getQty = (id) => source === "bag" ? (bagQty[id] ?? 0) : (stashQty[id] ?? 0);

    function optionsFor(types){
      return items
        .filter(it => types.includes(it.type))
        .filter(it => getQty(it.item_id) > 0)
        .map(it => {
          const q = getQty(it.item_id);
          const suffix = (source === "bag") ? ` (BAG x${q})` : ` (STASH x${q})`;
          return `<option value="${it.item_id}">${escapeHtml(it.name)}${suffix}</option>`;
        })
        .join("");
    }

    el("eqPrimary").innerHTML   = noneOpt("주무기") + optionsFor(["rifle","pistol"]);
    el("eqSecondary").innerHTML = noneOpt("보조")   + optionsFor(["pistol","rifle"]);
    el("eqMelee").innerHTML     = noneOpt("근접")   + optionsFor(["melee"]);

    el("eqPrimary").value   = EFN.LOADOUT.primary || "";
    el("eqSecondary").value = EFN.LOADOUT.secondary || "";
    el("eqMelee").value     = EFN.LOADOUT.melee || "";
  }

  function instTitle(it){
    const name = it.name || EFN.ITEM_MAP[it.item_id]?.name || it.item_id;
    const type = it.type || EFN.ITEM_MAP[it.item_id]?.type || "unknown";
    const tier = (it.tier != null) ? `T${it.tier}` : (EFN.ITEM_MAP[it.item_id]?.rarity || "-");
    const tag = `<span class="badge">${escapeHtml(type)}</span> <span class="badge">${escapeHtml(tier)}</span>`;
    return `${escapeHtml(name)} ${tag}`;
  }

  function instSub(it){
    const iid = it.instance_id || "-";
    const slot = it.slot_hint || it.slot || "-";
    const d = `${Number(it.durability||0)}/${Number(it.durability_max||0)}`;
    let extra = `dur ${d}`;
    const t = it.type || EFN.ITEM_MAP[it.item_id]?.type || "";
    if (t === "rifle" || t === "pistol"){
      const a = (it.ammo_type || "-") + " " + (it.ammo_loaded ?? "-");
      extra += ` · ammo ${a}`;
    }
    return `${escapeHtml(iid)} · slot ${escapeHtml(slot)} · ${escapeHtml(extra)}`;
  }

  function instEquipButtons(it){
    const t = it.type || EFN.ITEM_MAP[it.item_id]?.type || "unknown";
    const iid = it.instance_id;
    if (!iid) return "";
    const isRaid = (EFN.RAID.status === "in_raid");

    const btn = (slot, label) =>
      `<button class="btn tiny ${isRaid ? "warn":"info"}" data-inst-equip="1" data-inst-id="${escapeHtml(iid)}" data-inst-slot="${escapeHtml(slot)}">${escapeHtml(label)}</button>`;

    if (t === "melee") return btn("melee","근접 장착");
    if (t === "pistol") return btn("secondary","보조 장착");
    if (t === "rifle") return btn("primary","주무기 장착");
    if (t === "armor") return btn("armor","방어구 장착");
    return "";
  }

  function renderBagInstances(){
    const root = el("bagInstList");
    root.innerHTML = "";

    const list = Array.isArray(EFN.RAID.instances) ? EFN.RAID.instances : [];
    if (!list.length){
      root.innerHTML = `<div class="hint">인스턴스 장비가 없습니다. (스캐브/PMC 처치 시 확률 드랍)</div>`;
      return;
    }

    for (const it of list){
      const d = Number(it.durability||0);
      const dm = Math.max(1, Number(it.durability_max||0));
      const pct = clamp((d/dm)*100, 0, 100);

      const card = document.createElement("div");
      card.className = "instCard";
      card.innerHTML = `
        <div class="meta">
          <b>${instTitle(it)}</b>
          <div class="durbar" title="내구도"><i style="width:${pct.toFixed(1)}%"></i></div>
          <small>${instSub(it)}</small>
        </div>
        <div class="right">
          <div class="mini">내구도 ${d}/${dm}</div>
          <div class="actions">${instEquipButtons(it)}</div>
        </div>
      `;
      root.appendChild(card);
    }
  }

  function equipButtonsFor(item){
    const type = item?.type || "unknown";
    const id = item?.item_id || "";
    if (!id) return "";

    const inRaid = (EFN.RAID.status === "in_raid");
    const idle = (EFN.RAID.status === "idle");

    if (inRaid){
      if (type === "melee" || type === "pistol" || type === "rifle" || type === "armor"){
        return `<span class="hint">인스턴스로 스왑</span>`;
      }
      return "";
    }

    if (idle){
      const btn = (slot, label) => `<button class="btn tiny info" data-equip-slot="${slot}" data-equip-id="${id}">${label}</button>`;
      if (type === "melee") return btn("melee","근접 장착");
      if (type === "pistol") return btn("secondary","보조 장착");
      if (type === "rifle") return btn("primary","주무기 장착");
    }
    return "";
  }

  function renderLists(){
    const stashEl = el("stashList");
    stashEl.innerHTML = "";
    if (!EFN.STASH.length){
      stashEl.innerHTML = `<div class="hint">보관함이 비어 있습니다. (탈출 성공 시 파밍이 저장됩니다.)</div>`;
    } else {
      for (const row of EFN.STASH){
        const it = EFN.ITEM_MAP[row.item_id];
        const nm2 = it ? it.name : row.item_id;
        const tp = it ? it.type : "unknown";
        const rq = it ? it.rarity : "-";
        const div = document.createElement("div");
        div.className = "row";
        div.innerHTML = `
          <div class="name">
            <b>${escapeHtml(nm2)} <span class="badge">${escapeHtml(tp)}</span> <span class="badge">${escapeHtml(rq)}</span></b>
            <small>${escapeHtml(row.item_id)}</small>
          </div>
          <div class="rowRight">
            <div class="qty">x ${Number(row.qty||0)}</div>
            <div class="actions">${equipButtonsFor(it || {item_id: row.item_id, type: tp})}</div>
          </div>
        `;
        stashEl.appendChild(div);
      }
    }

    const bagEl = el("bagList");
    bagEl.innerHTML = "";
    const bagPairs = Object.entries(EFN.RAID.inventory || {}).filter(([_,q]) => Number(q)>0);

    if (!bagPairs.length){
      bagEl.innerHTML = `<div class="hint">스택형 아이템이 없습니다. (투척/탄약팩/재료)</div>`;
    } else {
      for (const [item_id, qty] of bagPairs){
        const it = EFN.ITEM_MAP[item_id];
        const nm2 = it ? it.name : item_id;
        const tp = it ? it.type : "unknown";
        const rq = it ? it.rarity : "-";
        const div = document.createElement("div");
        div.className = "row";
        div.innerHTML = `
          <div class="name">
            <b>${escapeHtml(nm2)} <span class="badge">${escapeHtml(tp)}</span> <span class="badge">${escapeHtml(rq)}</span></b>
            <small>${escapeHtml(item_id)}</small>
          </div>
          <div class="rowRight">
            <div class="qty">x ${Number(qty||0)}</div>
            <div class="actions">${equipButtonsFor(it || {item_id, type: tp})}</div>
          </div>
        `;
        bagEl.appendChild(div);
      }
    }

    renderBagInstances();
  }

  function wireTab(){
    document.querySelectorAll(".tab").forEach(btn => {
      btn.addEventListener("click", () => openTab(btn.dataset.tab));
    });
  }
  function openTab(id){
    document.querySelectorAll(".tab").forEach(t => t.classList.remove("on"));
    document.querySelectorAll(".tabPanel").forEach(p => p.classList.remove("on"));
    document.querySelector(`.tab[data-tab="${id}"]`)?.classList.add("on");
    el(id)?.classList.add("on");
  }

  const ThrowUI = {
    open(){ syncThrowModal(); el("throwModalWrap").style.display = "flex"; },
    close(){ el("throwModalWrap").style.display = "none"; }
  };
  function syncThrowModal(){
    const s = availThrow("thr_stone");
    const i = availThrow("thr_ied");
    const g = availThrow("thr_grenade");
    el("throwStoneQty").textContent = s;
    el("throwIedQty").textContent = i;
    el("throwGQty").textContent = g;
    el("btnThrowStone").disabled = (s <= 0);
    el("btnThrowIed").disabled   = (i <= 0);
    el("btnThrowG").disabled     = (g <= 0);
  }

  const HelpUI = {
    open(){ el("helpModalWrap").style.display = "flex"; },
    close(){ el("helpModalWrap").style.display = "none"; }
  };

  function wireModalClose(wrapId, closeFn){
    el(wrapId).addEventListener("click", (e) => { if (e.target === el(wrapId)) closeFn(); });
    window.addEventListener("keydown", (e) => { if (e.key === "Escape" && el(wrapId).style.display === "flex") closeFn(); });
  }

  function renderAll(){
    renderPlayer();
    buildEquipOptions();
    renderLists();
    renderLogAppend({ typing:false }); // 전체 리렌더 시에는 타이핑 재생성 금지(즉시)
  }

  // ✅ 모바일에서 로그(.kv#log 포함)를 HUD 최상단으로 이동
  function moveLogForMobile(){
    const mq = window.matchMedia("(max-width: 560px)");
    const logEl = el("log");
    if (!logEl) return;

    const logKv = logEl.closest(".kv");
    const hud = document.querySelector(".hud");
    if (!logKv || !hud) return;

    if (mq.matches){
      if (logKv.dataset.moved !== "1"){
        hud.insertBefore(logKv, hud.firstChild);
        logKv.dataset.moved = "1";
      }
    } else {
      logKv.dataset.moved = "0";
    }
  }

  window.addEventListener("DOMContentLoaded", () => {
    moveLogForMobile();
    const mq = window.matchMedia("(max-width: 560px)");
    mq.addEventListener?.("change", moveLogForMobile);
    window.addEventListener("resize", moveLogForMobile);
  });

  window.EFN.ui = {
    nm, slotLabel,
    setApiStatus,
    pushLog,
    renderAll,
    renderLogAppend,
    openTab, wireTab,
    ThrowUI, HelpUI, wireModalClose,
    availThrow,

    // ✅ 외부에서 상태 체크용
    isTyping,
  };
})();

/* ✅ /js/ui.js  (도킹 로그 "접기/펼치기" 토글 추가) */
(() => {
  "use strict";
  const EFN = window.EFN;
  const { el } = EFN;

  function initMobileLogDockToggle(){
    const logEl = el("log");
    if (!logEl) return;

    const isMobile = () => window.matchMedia("(max-width: 560px)").matches;
    const KEY = "efn_logdock_collapsed_v1";

    const apply = () => {
      if (!isMobile()){
        logEl.dataset.collapsed = "0";
        logEl.style.height = "";
        return;
      }
      const collapsed = localStorage.getItem(KEY) === "1";
      logEl.dataset.collapsed = collapsed ? "1" : "0";
      logEl.style.height = collapsed ? "54px" : "";
    };

    apply();
    window.addEventListener("resize", apply);

    logEl.addEventListener("click", (e) => {
      if (!isMobile()) return;
      if (Math.abs((e.movementY || 0)) > 6) return;

      const now = (localStorage.getItem(KEY) === "1");
      localStorage.setItem(KEY, now ? "0" : "1");
      apply();
    }, { passive:true });
  }

  if (document.readyState === "loading"){
    document.addEventListener("DOMContentLoaded", initMobileLogDockToggle);
  }else{
    initMobileLogDockToggle();
  }
})();
