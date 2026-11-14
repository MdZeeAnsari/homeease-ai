// Auto-refresh: poll a fingerprint every 4 s and reload when data changes.
// Skips while the user is typing or a confirm dialog is up; keeps scroll position.
(function () {
  const KEY = 'he-scroll:' + location.pathname + location.search;
  const saved = sessionStorage.getItem(KEY);
  if (saved !== null) { window.scrollTo(0, +saved); sessionStorage.removeItem(KEY); }
  let version = null, busy = false;
  const typing = () => {
    const a = document.activeElement;
    if (a && ['INPUT', 'TEXTAREA', 'SELECT'].includes(a.tagName) && a.type !== 'hidden' && a.type !== 'radio') return true;
    if ([...document.querySelectorAll('input[type=radio],input[type=checkbox]')].some(i => i.checked !== i.defaultChecked)) return true;
    return [...document.querySelectorAll('input[type=text],input[type=number],input:not([type]),textarea')].some(i => i.value && i.value !== i.defaultValue);
  };
  async function tick() {
    if (busy || document.hidden) return;
    busy = true;
    try {
      const r = await fetch('api/pulse.php', { cache: 'no-store' });
      const { v } = await r.json();
      if (version === null) version = v;
      else if (v !== version && !typing()) {
        sessionStorage.setItem(KEY, String(window.scrollY));
        sessionStorage.setItem('he-noanim', '1');
        location.reload();
        return;
      }
    } catch (e) { /* offline: try again next tick */ }
    busy = false;
  }
  tick();
  setInterval(tick, 4000);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) tick(); });
})();
