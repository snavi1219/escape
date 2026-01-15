(function(){
  const $ = (id) => document.getElementById(id);
  const logEl = $("log");

  let userKey = null;
  let polling = null;
  let lastEvent = null;

  function logLine(t){
    const div = document.createElement("div");
    div.textContent = t;
    logEl.appendChild(div);
    logEl.scrollTop = logEl.scrollHeight;
  }

  function setStatus(t){ $("status").textContent = t; }
  function enable(btn, on){ btn.disabled = !on; }

  async function post(url, data){
    const res = await fetch(url, {
      method:"POST",
      headers:{ "Content-Type":"application/x-www-form-urlencoded" },
      body: new URLSearchParams(data).toString()
    });
    return res.json();
  }

  async function get(url){
    const res = await fetch(url);
    return res.json();
  }

  function renderRoom(s){
    $("roomId").textContent = s.room_id || "-";
    $("turnNo").textContent = s.turn_no ?? "-";
    $("turnPlayer").textContent = s.turn_player ?? "-";

    // players
    const me = s.players.find(p => p.user_key === userKey);
    const op = s.players.find(p => p.user_key !== userKey);

    const ms = (me && me.state) ? me.state : {};
    const os = (op && op.state) ? op.state : {};

    $("meHp").textContent = ms.hp ?? "-";
    $("meHpMax").textContent = ms.hpMax ?? "-";
    $("meAtk").textContent = ms.atk ?? "-";
    $("meDef").textContent = ms.def ?? "-";
    $("meCrit").textContent = ms.crit ?? "-";
    $("mePot").textContent = ms.potions ?? "-";
    $("meCd").textContent = ms.skillCd ?? "-";
    $("meGuard").textContent = ms.guard ?? "-";

    $("opHp").textContent = os.hp ?? "-";
    $("opHpMax").textContent = os.hpMax ?? "-";
    $("opAtk").textContent = os.atk ?? "-";
    $("opDef").textContent = os.def ?? "-";
    $("opCrit").textContent = os.crit ?? "-";
    $("opPot").textContent = os.potions ?? "-";
    $("opCd").textContent = os.skillCd ?? "-";
    $("opGuard").textContent = os.guard ?? "-";

    // event log
    if (s.last_event && s.last_event !== lastEvent) {
      lastEvent = s.last_event;
      logLine("EVT " + s.last_event);
    }

    // action enable only if my turn and playing
    const myTurn = (s.status === "playing" && s.turn_player === userKey);
    enable($("actAttack"), myTurn);
    enable($("actSkill"), myTurn);
    enable($("actPotion"), myTurn);
    enable($("actRun"), myTurn);
  }

  async function poll(){
    if (!userKey) return;
    const s = await get(`./api/poll.php?user_key=${encodeURIComponent(userKey)}&t=${Date.now()}`);
    if (!s.ok) return;

    if (s.status === "idle") {
      setStatus("IDLE");
      return;
    }

    setStatus(s.status.toUpperCase());
    renderRoom(s);
  }

  function startPolling(){
    if (polling) clearInterval(polling);
    polling = setInterval(poll, 1000);
    poll(); // 즉시 1회
  }

  // ---- UI bindings ----
  $("btnStart").addEventListener("click", async () => {
    const name = ($("name").value || "").trim() || "Player";
    const r = await post("./api/hello.php", { name });
    if (!r.ok) return logLine("ERR 유저 생성 실패");

    userKey = r.user_key;
    $("meKey").textContent = userKey;
    logLine("SYS 유저 생성: " + userKey);

    enable($("btnQueue"), true);
    enable($("btnCancel"), true);

    startPolling();
  });

  $("btnQueue").addEventListener("click", async () => {
    if (!userKey) return;
    const r = await post("./api/queue_join.php", { user_key: userKey });
    if (r.status === "queued") logLine("SYS 매칭 대기 시작");
    if (r.status === "matched") logLine("SYS 매칭 완료: room=" + r.room_id);
    startPolling();
  });

  $("btnCancel").addEventListener("click", async () => {
    if (!userKey) return;
    await post("./api/queue_leave.php", { user_key: userKey });
    logLine("SYS 대기 취소");
  });

  async function action(kind){
    if (!userKey) return;
    const r = await post("./api/turn_action.php", { user_key: userKey, kind });
    if (!r.ok) logLine("ERR 액션 실패: " + (r.error || "unknown"));
    poll(); // 즉시 갱신
  }

  $("actAttack").addEventListener("click", () => action("attack"));
  $("actSkill").addEventListener("click", () => action("skill"));
  $("actPotion").addEventListener("click", () => action("potion"));
  $("actRun").addEventListener("click", () => action("run"));

  // 초기 상태
  enable($("btnQueue"), false);
  enable($("btnCancel"), false);
  enable($("actAttack"), false);
  enable($("actSkill"), false);
  enable($("actPotion"), false);
  enable($("actRun"), false);
})();


