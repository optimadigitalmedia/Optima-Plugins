/* Optima Chat loader. The page ships only the button (printed by the plugin)
   and this file. On the first tap, click or key press on the button it loads
   chat.css and chat.js, then opens the panel. The chat never opens by itself. */
(function () {
  'use strict';
  var root = document.getElementById('optima-chat');
  var btn = root && root.querySelector('.oc-launcher');
  if (!btn) return;
  var busy = false, css = null;

  function load(e) {
    if (e) e.preventDefault();
    if (busy) return;
    if (window.optimaChat) { window.optimaChat.open(); return; }
    busy = true;
    btn.removeEventListener('click', load);
    btn.setAttribute('aria-busy', 'true');
    var left = css ? 1 : 2, failed = false;
    function done(ok) {
      if (!ok) failed = true;
      if (--left) return;
      btn.removeAttribute('aria-busy');
      if (!failed && window.optimaChat) { window.optimaChat.open(); return; }
      busy = false; btn.addEventListener('click', load); // Could not load: the next tap tries again.
    }
    if (!css) {
      css = document.createElement('link');
      css.rel = 'stylesheet'; css.href = root.getAttribute('data-css');
      css.onload = function () { done(true); }; css.onerror = function () { done(true); }; // Unstyled beats nothing.
      document.head.appendChild(css);
    }
    var js = document.createElement('script');
    js.src = root.getAttribute('data-js'); js.async = true;
    js.onload = function () { done(true); }; js.onerror = function () { done(false); };
    document.body.appendChild(js);
  }

  btn.addEventListener('click', load);
})();
