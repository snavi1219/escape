(() => {
  "use strict";

  // ===== DOM Helpers =====
  const el = (id) => document.getElementById(id);

  // ===== LocalStorage Keys =====
  const LS_KEY = "efn_single_user_key_v1";
  const LS_NAME = "efn_single_user_name_v1";
  const LS_HELP_OFF = "efn_help_hide_v1";

  // ===== Utils =====
  function randInt(min, max){ return Math.floor(Math.random()*(max-min+1))+min; }
  function chance(pct){ return Math.random()*100 < pct; }
  function clamp(v,a,b){ return Math.max(a, Math.min(b, v)); }
  function escapeHtml(s){
    return String(s).replaceAll("&","&amp;").replaceAll("<","&lt;").replaceAll(">","&gt;")
      .replaceAll('"',"&quot;").replaceAll("'","&#039;");
  }
  function setBar(barEl, cur, max){
    const pct = max>0 ? (cur/max)*100 : 0;
    barEl.style.width = clamp(pct,0,100).toFixed(1)+"%";
  }

  // ===== Global Data =====
  // (주의) 여기서만 전역 상태를 소유하고, 다른 모듈은 window.EFN을 통해 접근합니다.
  const EFN = {
    el,
    LS_KEY, LS_NAME, LS_HELP_OFF,
    randInt, chance, clamp, escapeHtml, setBar,

    ITEM_MAP: {},
    STASH: [],
    LOADOUT: { primary:null, secondary:null, melee:null },
    RAID: {
      status:"idle",
      inventory:{},
      throw:{thr_stone:0, thr_ied:0, thr_grenade:0},
      brought:{primary:null,secondary:null,melee:null},
      instances:[],
      weapon_state:{},
      armor_state:null
    },

    state: {
      turn: 1,
      location: "은신처",
      inCombat: false,
      player: { hpMax: 80, hp: 80, def: 2 },
      enemy: null,
      log: []
    },

    lastLogRendered: 0,

    ENEMIES: [
      { id:"z1",  kind:"zombie", name:"부패한 워커",       hp:[24,34], atk:[4,7],  def:[0,1], loot:[["thr_stone",40],["melee_prybar",8]] },
      { id:"z2",  kind:"zombie", name:"울부짖는 러너",     hp:[18,28], atk:[6,9],  def:[0,1], loot:[["thr_stone",50],["melee_bat",6]] },
      { id:"z3",  kind:"zombie", name:"팽창 블로터",       hp:[32,46], atk:[5,8],  def:[1,2], loot:[["thr_ied",10],["melee_pipewrench",6]] },
      { id:"z4",  kind:"zombie", name:"철근 브루트",       hp:[40,58], atk:[7,10], def:[2,3], loot:[["melee_machete",4],["thr_grenade",5]] },
      { id:"z5",  kind:"zombie", name:"유리턱 크리퍼",     hp:[20,30], atk:[5,8],  def:[0,1], loot:[["thr_stone",55],["pst_compact9",3]] },
      { id:"z6",  kind:"zombie", name:"헬멧 헤드바터",     hp:[30,44], atk:[6,9],  def:[1,3], loot:[["pst_service9",4],["thr_stone",35]] },
      { id:"z7",  kind:"zombie", name:"어둠의 스토커",     hp:[22,32], atk:[6,10], def:[0,2], loot:[["thr_ied",8],["pst_machine9",3]] },
      { id:"z8",  kind:"zombie", name:"혈청 변이체",       hp:[36,52], atk:[8,12], def:[1,3], loot:[["rif_sm9",3],["thr_grenade",6]] },
      { id:"z9",  kind:"zombie", name:"절단자",           hp:[28,40], atk:[7,11], def:[1,2], loot:[["melee_scrapknife",6],["thr_stone",45]] },
      { id:"z10", kind:"zombie", name:"산성 스피터",       hp:[26,38], atk:[6,10], def:[0,2], loot:[["thr_ied",12],["pst_heavy45",2]] },

      { id:"s1", kind:"scav", name:"스캐브 정찰꾼",         hp:[26,38], atk:[6,9],  def:[1,2], loot:[["pst_compact9",10],["thr_stone",50]] },
      { id:"s2", kind:"scav", name:"스캐브 약탈자",         hp:[28,42], atk:[7,10], def:[1,2], loot:[["pst_service9",8],["melee_bat",10]] },
      { id:"s3", kind:"scav", name:"스캐브 방화범",         hp:[24,36], atk:[7,11], def:[0,2], loot:[["thr_ied",18],["melee_pipewrench",8]] },
      { id:"s4", kind:"scav", name:"스캐브 가드",           hp:[30,46], atk:[7,10], def:[2,3], loot:[["rif_sm9",7],["thr_grenade",6]] },
      { id:"s5", kind:"scav", name:"스캐브 저격수(허접)",   hp:[22,34], atk:[8,12], def:[0,2], loot:[["rif_bolt",5],["thr_stone",30]] },

      { id:"p1", kind:"pmc", name:"PMC 돌격수",             hp:[40,58], atk:[10,14], def:[3,5], loot:[["rif_carbine556",10],["thr_grenade",10]] },
      { id:"p2", kind:"pmc", name:"PMC 브리처",             hp:[38,56], atk:[9,13],  def:[3,5], loot:[["pst_heavy45",10],["melee_machete",8]] },
      { id:"p3", kind:"pmc", name:"PMC 지정사수",           hp:[36,54], atk:[11,16], def:[2,5], loot:[["rif_dmr762",8],["thr_ied",12]] },
      { id:"p4", kind:"pmc", name:"PMC 중화기",             hp:[46,68], atk:[12,18], def:[4,6], loot:[["rif_battle762",8],["thr_grenade",12]] },
      { id:"p5", kind:"pmc", name:"PMC 요원장(엘리트)",     hp:[52,78], atk:[13,19], def:[4,7], loot:[["rif_dmr762",10],["pst_revolver357",8]] },
    ],
  };

  window.EFN = EFN;
})();
