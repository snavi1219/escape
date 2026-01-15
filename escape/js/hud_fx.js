/* hud_fx.js
 * - Threat meter (idle vs in_raid + hp 기반)
 * - Heartbeat (hp 기반 bpm/상태 + low hp vignette)
 * - Hit direction overlay:
 *    1) 전투 로그 변화 감지(피해/데미지 문구)로 자동 플래시
 *    2) HP 급감 감지로 자동 플래시
 *    3) 필요 시 window.hudFx.hit('left', 12) 직접 호출 가능
 */
(function(){
  const $ = (id)=>document.getElementById(id);

  const el = {
    threatFill: $("threatFill"),
    threatVal : $("threatVal"),
    heartState: $("heartState"),
    heartBpm  : $("heartBpm"),
    vignette  : $("vignette"),
    log       : $("log"),
    hp        : $("pHp"),
    hpMax     : $("pHpMax"),
    raid      : $("raidStatus"),
    turn      : $("turnNo"),
    fxWrap    : document.querySelector(".hitFx"),                 // ✅ 추가
    dirs      : Array.from(document.querySelectorAll(".hitDir")),
  };

  // 안전 장치
  if (!el.threatFill || !el.threatVal || !el.heartState || !el.heartBpm) return;

  let threat = 0;
  let lastHp = readNum(el.hp?.textContent, 0);
  let lastLogText = "";
  let lastHitAt = 0;

  function readNum(v, fallback=0){
    const n = parseFloat(String(v||"").replace(/[^\d.]/g,""));
    return Number.isFinite(n) ? n : fallback;
  }

  function clamp(n, a, b){ return Math.max(a, Math.min(b, n)); }
  function lerp(a,b,t){ return a + (b-a)*t; }

  function getHpRatio(){
    const hp = readNum(el.hp?.textContent, 0);
    const mx = Math.max(1, readNum(el.hpMax?.textContent, 1));
    return clamp(hp/mx, 0, 1);
  }

  function computeThreat(){
    const inRaid = String(el.raid?.textContent||"").includes("in_raid");
    const r = getHpRatio();

    let base = inRaid ? 55 : 18;
    base += (1 - r) * 42;

    const t = readNum(el.turn?.textContent, 1);
    base += clamp((t-1) * 1.2, 0, 18);

    const jitter = (Math.random() - 0.5) * (inRaid ? 10 : 6);
    return clamp(base + jitter, 0, 100);
  }

  function updateThreat(){
    const target = computeThreat();
    threat = lerp(threat, target, 0.12);
    const v = Math.round(threat);

    el.threatFill.style.width = v + "%";
    el.threatVal.textContent = v + "%";
    el.threatFill.style.opacity = String(clamp(0.55 + v/160, 0.55, 1));
  }

  function updateHeart(){
    const r = getHpRatio();
    const bpm = Math.round(72 + (1 - r) * 78);

    let state = "CALM";
    if (r < 0.75) state = "ALERT";
    if (r < 0.45) state = "STRESS";
    if (r < 0.25) state = "CRITICAL";

    el.heartState.textContent = state;
    el.heartBpm.textContent = bpm + " bpm";

    if (el.vignette){
      if (r < 0.35) el.vignette.classList.add("is-on");
      else el.vignette.classList.remove("is-on");
    }
  }

  // ✅ 핵심: 플래시 끝나면 무조건 opacity 0 + class 제거
  function cleanupDir(node){
    if (!node) return;
    node.classList.remove("is-on");
    node.style.opacity = "0";
  }

  function flashDir(dir, intensity=12){
    const now = Date.now();
    if (now - lastHitAt < 120) return;
    lastHitAt = now;

    const d = String(dir||"").toLowerCase();
    const key =
      (d==="up"||d==="front"||d==="f"||d==="top") ? "up" :
      (d==="right"||d==="r") ? "right" :
      (d==="down"||d==="back"||d==="b"||d==="bottom") ? "down" :
      (d==="left"||d==="l") ? "left" : "up";

    const node = el.dirs.find(x => x.classList.contains(key));
    if (!node) return;

    // ✅ 컨테이너는 플래시 순간에만 보여주고 다시 끔
    if (el.fxWrap){
      el.fxWrap.classList.add("is-show");
      // 플래시 종료 후 숨김(안전)
      window.setTimeout(() => el.fxWrap && el.fxWrap.classList.remove("is-show"), 360);
    }

    // intensity -> 강도(opacity)
    const op = clamp(0.45 + (readNum(intensity,12) / 40), 0.45, 1);

    // ✅ 재트리거: 기존 애니메이션/스타일 제거 -> reflow -> 재적용
    cleanupDir(node);
    void node.offsetWidth;

    // 애니메이션 동안만 보이게 inline opacity를 “잠깐”만 적용
    node.style.opacity = String(op);
    node.classList.add("is-on");

    // ✅ 애니메이션 종료 후 강제 정리(가장 중요)
    window.setTimeout(() => cleanupDir(node), 320);
  }

  function inferFromLogText(text){
    const s = String(text||"");
    if (!s || s === lastLogText) return;
    lastLogText = s;

    let dmg = 12;
    const m = s.match(/(\d+)\s*(?:피해|데미지|dmg)/i);
    if (m) dmg = parseInt(m[1], 10) || dmg;

    let dir = null;
    if (/(정면|앞|전방)/.test(s)) dir = "up";
    else if (/(우측|오른쪽)/.test(s)) dir = "right";
    else if (/(후방|뒤|배후)/.test(s)) dir = "down";
    else if (/(좌측|왼쪽)/.test(s)) dir = "left";
    else {
      const r = Math.random();
      dir = r < .25 ? "up" : r < .5 ? "right" : r < .75 ? "down" : "left";
    }

    if (/(피격|피해|데미지|공격받)/.test(s)) {
      flashDir(dir, dmg);
    }
  }

  function watchHpDrop(){
    const hp = readNum(el.hp?.textContent, lastHp);
    const diff = lastHp - hp;
    if (diff >= 6){
      const r = Math.random();
      const dir = r < .25 ? "up" : r < .5 ? "right" : r < .75 ? "down" : "left";
      flashDir(dir, diff);
    }
    lastHp = hp;
  }

  function bindLogObserver(){
    if (!el.log) return;
    const ob = new MutationObserver(() => {
      const last = el.log.lastElementChild;
      if (!last) return;
      inferFromLogText(last.textContent);
    });
    ob.observe(el.log, { childList: true, subtree: true });
  }

  // ✅ animationend에서도 정리(브라우저/타이밍 이슈 대비)
  el.dirs.forEach(node => {
    node.addEventListener("animationend", () => cleanupDir(node));
  });

  window.hudFx = {
    hit: (dir, dmg)=>flashDir(dir, dmg),
    threat: (v)=>{ threat = clamp(readNum(v, threat), 0, 100); },
    ping: ()=>flashDir("up", 10)
  };

  window.addEventListener("hud:hit", (e)=>{
    const d = e?.detail || {};
    flashDir(d.dir, d.dmg);
  });

  bindLogObserver();
  setInterval(() => {
    updateThreat();
    updateHeart();
    watchHpDrop();
  }, 180);
})();
