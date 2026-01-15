(() => {
  "use strict";
  const { el, LS_KEY } = window.EFN;

  async function apiPost(url, data){
    const res = await fetch(url, {
      method:"POST",
      headers:{ "Content-Type":"application/x-www-form-urlencoded" },
      body: new URLSearchParams(data).toString()
    });
    return res.json();
  }
  async function apiGet(url){
    const res = await fetch(url);
    return res.json();
  }
  async function apiPostTry(url, data){
    try{
      const r = await apiPost(url, data);
      return r;
    }catch(e){
      return {ok:false, error:"network_error"};
    }
  }

  function getUserKey(){ return localStorage.getItem(LS_KEY) || ""; }
  function setUserKey(k){
    localStorage.setItem(LS_KEY, k);
    el("singleKey").textContent = k || "-";
  }

  window.EFN.api = { apiPost, apiGet, apiPostTry, getUserKey, setUserKey };
})();
