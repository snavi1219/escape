(() => {
  'use strict';

  const $ = (sel, el=document) => el.querySelector(sel);
  const $$ = (sel, el=document) => Array.from(el.querySelectorAll(sel));

  const state = {
    typing: false,
    queue: [],
    bag: [],
    player: { name:'-', hp:'-', arm:'-', raid:'IDLE', status:'-' },
    loadout: { primary:'-', secondary:'-', melee:'-' },
    api: { connected: false, text: 'DISCONNECTED' },
  };

  // ---- Base path resolver (optional) ----
  function getBase(){
    const meta = document.querySelector('meta[name="efn-base"]');
    const base = (meta && meta.content) ? meta.content.trim() : './';
    return base.endsWith('/') ? base : (base + '/');
  }

  // ---- UI refs ----
  const elLog = $('#log');
  const elChoices = $('#choices');

  const elApiDot = $('#apiDot');
  const elApiTxt = $('#apiTxt');

  const elStHp = $('#st_hp');
  const elStArm = $('#st_arm');
  const elStRaid = $('#st_raid');

  const elChName = $('#ch_name');
  const elChHp = $('#ch_hp');
  const elChArm = $('#ch_arm');
  const elChState = $('#ch_state');

  const elLdPrimary = $('#ld_primary');
  const elLdSecondary = $('#ld_secondary');
  const elLdMelee = $('#ld_melee');

  const drawerBag = $('#drawerBag');
  const drawerChar = $('#drawerChar');

  const bagList = $('#bagList');

  const modalPick = $('#modalPick');
  const pickTitle = $('#pickTitle');
  const pickBody = $('#pickBody');

  // ---- Helpers ----
  function scrollLogToBottom(){
    if (!elLog) return;
    elLog.scrollTop = elLog.scrollHeight;
  }

  function setApiStatus(connected, text){
    state.api.connected = !!connected;
    state.api.text = text || (connected ? 'CONNECTED' : 'DISCONNECTED');
    elApiTxt.textContent = state.api.text;
    elApiDot.style.background = connected ? 'rgba(61,220,151,.95)' : 'rgba(255,92,122,.85)';
  }

  function updateStatusUI(){
    elStHp.textContent = String(state.player.hp ?? '-');
    elStArm.textContent = String(state.player.arm ?? '-');
    elStRaid.textContent = String(state.player.raid ?? 'IDLE');

    elChName.textContent = String(state.player.name ?? '-');
    elChHp.textContent = String(state.player.hp ?? '-');
    elChArm.textContent = String(state.player.arm ?? '-');
    elChState.textContent = String(state.player.status ?? '-');

    elLdPrimary.textContent = String(state.loadout.primary ?? '-');
    elLdSecondary.textContent = String(state.loadout.secondary ?? '-');
    elLdMelee.textContent = String(state.loadout.melee ?? '-');
  }

  function openDrawer(el){
    el.classList.add('is-open');
    el.setAttribute('aria-hidden', 'false');
  }
  function closeDrawer(el){
    el.classList.remove('is-open');
    el.setAttribute('aria-hidden', 'true');
  }

  function openModal(el){
    el.classList.add('is-open');
    el.setAttribute('aria-hidden', 'false');
  }
  function closeModal(el){
    el.classList.remove('is-open');
    el.setAttribute('aria-hidden', 'true');
  }

  // ---- Log + typing ----
  function addLine(text, tone){
    const p = document.createElement('p');
    p.className = 'line' + (tone ? ` ${tone}` : '');
    const span = document.createElement('span');
    span.className = 't';
    span.textContent = text;
    p.appendChild(span);
    elLog.appendChild(p);
    scrollLogToBottom();
  }

  async function typeLine(text, tone, speed=12){
    state.typing = true;

    const p = document.createElement('p');
    p.className = 'line' + (tone ? ` ${tone}` : '');
    const span = document.createElement('span');
    span.className = 't';
    p.appendChild(span);
    elLog.appendChild(p);
    scrollLogToBottom();

    const s = String(text ?? '');
    let out = '';
    for (let i=0; i<s.length; i++){
      out += s[i];
      span.textContent = out;
      scrollLogToBottom();
      // 너무 느리면 전투 템포가 죽으니 기본 12ms, 길면 가속
      const delay = s.length > 220 ? Math.max(4, speed - 6) : speed;
      await new Promise(r => setTimeout(r, delay));
    }

    state.typing = false;
  }

  function enqueueLog(text, tone, typing=true){
    state.queue.push({ text, tone, typing });
    pumpQueue();
  }

  async function pumpQueue(){
    if (state.typing) return;
    if (!state.queue.length) return;

    const item = state.queue.shift();
    if (!item) return;

    // 타이핑 중엔 선택지 잠깐 비활성화
    setChoicesDisabled(true);

    if (item.typing) await typeLine(item.text, item.tone);
    else addLine(item.text, item.tone);

    setChoicesDisabled(false);

    // 다음 큐
    if (state.queue.length) pumpQueue();
  }

  function setChoicesDisabled(disabled){
    $$('.choiceBtn', elChoices).forEach(b => b.disabled = !!disabled);
  }

  // ---- Choices ----
  function renderChoices(choices){
    elChoices.innerHTML = '';

    const arr = Array.isArray(choices) ? choices : [];
    if (!arr.length){
      // 최소 2개 고정: 탐색 / 대기 (탐색은 EFN.game.explore)
      elChoices.appendChild(makeChoiceBtn({ id:'explore', label:'탐색', kind:'primary' }));
      elChoices.appendChild(makeChoiceBtn({ id:'wait', label:'대기', kind:'' }));
      return;
    }

    for (const c of arr){
      elChoices.appendChild(makeChoiceBtn(c));
    }
  }

  function makeChoiceBtn(choice){
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'choiceBtn' + (choice.kind ? ` ${choice.kind}` : '');
    btn.textContent = choice.label ?? '선택';

    btn.addEventListener('click', async () => {
      if (state.typing) return;
      // UI 로그에 선택을 먼저 남김
      enqueueLog(`> ${btn.textContent}`, 'info', false);

      // choice handler
      try {
        await handleChoice(choice);
      } catch (e){
        enqueueLog(`오류: ${e?.message || e}`, 'bad', false);
      }
    });

    return btn;
  }

  async function handleChoice(choice){
    const id = choice.id;

    // 핵심 규칙: 탐색은 무조건 EFN.game.explore()만 호출
    if (id === 'explore'){
      assertExploreReady();
      await window.EFN.game.explore();
      return;
    }

    // 나머지는 game.js가 제공하는 핸들러가 있으면 위임
    const h = window.EFN?.ui?.onChoice;
    if (typeof h === 'function'){
      await h(choice);
      return;
    }

    // game.js 위임이 없다면 최소 동작 (대기 정도)
    if (id === 'wait'){
      enqueueLog('잠시 숨을 고릅니다...', 'm', true);
      return;
    }

    enqueueLog('이 선택지는 현재 연결된 핸들러가 없습니다.', 'warn', false);
  }

  function assertExploreReady(){
    if (!window.EFN || !window.EFN.game || typeof window.EFN.game.explore !== 'function'){
      throw new Error('EFN.game.explore()가 준비되지 않았습니다. game.js에서 EFN 브리지를 확인하세요.');
    }
  }

  // ---- Bag render ----
  function renderBag(items){
    state.bag = Array.isArray(items) ? items : [];
    if (!bagList) return;

    if (!state.bag.length){
      bagList.textContent = '레이드 백이 비어 있습니다.';
      return;
    }

    const wrap = document.createElement('div');
    state.bag.forEach((it, idx) => {
      const row = document.createElement('div');
      row.className = 'row';

      const name = document.createElement('span');
      name.className = 'k';
      name.textContent = `${it.name ?? it.item_name ?? it.item_id ?? 'ITEM'} x${it.qty ?? it.count ?? 1}`;

      const meta = document.createElement('b');
      meta.className = 'v';
      meta.textContent = (it.rarity ? String(it.rarity) : (it.type ? String(it.type) : ''));

      row.appendChild(name);
      row.appendChild(meta);
      wrap.appendChild(row);
    });

    bagList.innerHTML = '';
    bagList.appendChild(wrap);
  }

  // ---- Item picker modal (투척/사용) ----
  function openPicker(title, filterFn, onPick){
    pickTitle.textContent = title || '선택';
    pickBody.innerHTML = '';

    const candidates = state.bag.filter(filterFn || (()=>true));
    if (!candidates.length){
      const div = document.createElement('div');
      div.className = 'hint';
      div.textContent = '선택할 수 있는 아이템이 없습니다.';
      pickBody.appendChild(div);
    } else {
      for (const it of candidates){
        const card = document.createElement('div');
        card.className = 'pickItem';

        const left = document.createElement('div');
        const nm = document.createElement('div');
        nm.className = 'name';
        nm.textContent = `${it.name ?? it.item_name ?? it.item_id ?? 'ITEM'} x${it.qty ?? it.count ?? 1}`;
        const mt = document.createElement('div');
        mt.className = 'meta';
        mt.textContent = [it.type, it.rarity].filter(Boolean).join(' / ');
        left.appendChild(nm);
        left.appendChild(mt);

        const btn = document.createElement('button');
        btn.className = 'btn';
        btn.type = 'button';
        btn.textContent = '선택';
        btn.addEventListener('click', async () => {
          closeModal(modalPick);
          try { await onPick(it); }
          catch (e){ enqueueLog(`오류: ${e?.message || e}`, 'bad', false); }
        });

        card.appendChild(left);
        card.appendChild(btn);
        pickBody.appendChild(card);
      }
    }

    openModal(modalPick);
  }

  // ---- Bind events ----
  $('#btnBag').addEventListener('click', () => openDrawer(drawerBag));
  $('#btnChar').addEventListener('click', () => openDrawer(drawerChar));
  $('#btnExit').addEventListener('click', async () => {
    // 탈출도 game.js에 위임 (있으면)
    const fn = window.EFN?.game?.extract;
    if (typeof fn === 'function'){
      enqueueLog('탈출을 시도합니다...', 'warn', true);
      await fn();
      return;
    }
    enqueueLog('탈출 기능이 연결되지 않았습니다(EFN.game.extract).', 'warn', false);
  });

  $$('[data-drawer-close]').forEach(btn => {
    btn.addEventListener('click', () => {
      const id = btn.getAttribute('data-drawer-close');
      const el = document.getElementById(id);
      if (el) closeDrawer(el);
    });
  });

  $('#pickClose').addEventListener('click', () => closeModal(modalPick));
  $('#pickCancel').addEventListener('click', () => closeModal(modalPick));
  $$('[data-modal-close]').forEach(dim => {
    dim.addEventListener('click', () => {
      const id = dim.getAttribute('data-modal-close');
      const el = document.getElementById(id);
      if (el) closeModal(el);
    });
  });

  // Bag actions
  $('#btnThrow').addEventListener('click', () => {
    openPicker('투척할 아이템 선택', (it) => {
      // throw_json/throwable 개념이 game.js에 있을 수 있으니, 여기선 최소 필터만
      return true;
    }, async (it) => {
      const fn = window.EFN?.game?.throwItem;
      if (typeof fn !== 'function'){
        enqueueLog('투척 기능이 연결되지 않았습니다(EFN.game.throwItem).', 'warn', false);
        return;
      }
      await fn(it);
    });
  });

  $('#btnUse').addEventListener('click', () => {
    openPicker('사용할 아이템 선택', (it) => true, async (it) => {
      const fn = window.EFN?.game?.useItem;
      if (typeof fn !== 'function'){
        enqueueLog('사용 기능이 연결되지 않았습니다(EFN.game.useItem).', 'warn', false);
        return;
      }
      await fn(it);
    });
  });

  // ---- EFN UI bridge: game.js가 emit 해주면 여기서 갱신 ----
  function installBusListeners(){
    const bus = window.EFN?.bus;
    if (!bus || typeof bus.on !== 'function') return;

    bus.on('api', (p) => setApiStatus(!!p?.connected, p?.text));
    bus.on('log', (p) => enqueueLog(p?.text ?? '', p?.tone ?? '', p?.typing !== false));
    bus.on('choices', (p) => renderChoices(p?.choices ?? []));
    bus.on('bag', (p) => renderBag(p?.items ?? []));
    bus.on('status', (p) => {
      if (p?.player) state.player = { ...state.player, ...p.player };
      if (p?.loadout) state.loadout = { ...state.loadout, ...p.loadout };
      updateStatusUI();
    });
    bus.on('raid', (p) => {
      state.player.raid = p?.state ?? state.player.raid;
      updateStatusUI();
    });
  }

  // ---- Boot ----
  function boot(){
    // 최초 UI
    setApiStatus(false, 'DISCONNECTED');
    updateStatusUI();
    renderChoices([]); // 기본 탐색/대기 버튼

    enqueueLog('RAID 로그가 메인입니다. 아래 선택지로 진행하세요.', 'm', true);

    // base 적용(필요 시)
    const base = getBase();
    // base를 직접 사용하진 않지만, 프로젝트에서 필요하면 window.EFN.base로 공유 가능
    window.EFN = window.EFN || {};
    window.EFN.base = base;

    // bus 연결
    installBusListeners();

    // 연결 체크(선택): game.js가 api 연결 emit하면 갱신됨
    // 탐색 버튼은 EFN.game.explore() 준비되어야 동작
  }

  document.addEventListener('DOMContentLoaded', boot);
})();
