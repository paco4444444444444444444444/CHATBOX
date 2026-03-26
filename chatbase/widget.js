/*!
 * Chatbase Widget v1.0
 * Universal embeddable chatbot widget
 *
 * USAGE:
 *   <script>
 *     window.CHATBASE_URL    = 'https://yoursite.com/chatbase';
 *     window.CHATBASE_BOT_ID = 'your-bot-id';
 *   </script>
 *   <script src="https://yoursite.com/chatbase/widget.js"></script>
 */
(function () {
  'use strict';

  var BASE_URL   = (window.CHATBASE_URL    || '').replace(/\/$/, '');
  var BOT_ID     = window.CHATBASE_BOT_ID  || '';
  var MAX_HIST   = 100;
  var TIMEOUT_MS = 300000;

  if (!BASE_URL || !BOT_ID) { console.warn('Chatbase: CHATBASE_URL and CHATBASE_BOT_ID required'); return; }

  // Avoid double-init
  if (window.__chatbase_loaded) return;
  window.__chatbase_loaded = true;

  // ── State ─────────────────────────────────────────────────────────────
  var cfg          = { name: 'Asistente', welcome: 'Hola, ¿en qué puedo ayudarte?', placeholder: 'Escribe tu pregunta...', color: '#2563eb' };
  var isOpen       = false;
  var isLoading    = false;
  var history      = [];
  var sessionId    = Math.random().toString(36).slice(2);
  var initialized  = false;

  // ── CSS ───────────────────────────────────────────────────────────────
  function injectCSS(color) {
    var c = color || '#2563eb';
    var css = [
      '#cb-root *{box-sizing:border-box;margin:0;padding:0}',

      '#cb-btn{position:fixed;bottom:24px;right:24px;z-index:2147483647;',
        'width:60px;height:60px;border-radius:50%;',
        'background:' + c + ';border:none;cursor:pointer;',
        'box-shadow:0 4px 20px rgba(0,0,0,0.25);',
        'display:flex;align-items:center;justify-content:center;',
        'transition:transform .2s,box-shadow .2s;outline:none}',
      '#cb-btn:hover{transform:scale(1.08);box-shadow:0 6px 28px rgba(0,0,0,0.3)}',
      '#cb-btn svg{width:28px;height:28px;fill:#fff}',
      '#cb-btn .i-open{display:block}#cb-btn .i-close{display:none}',
      '#cb-btn.open .i-open{display:none}#cb-btn.open .i-close{display:block}',

      '#cb-win{position:fixed;bottom:96px;right:24px;z-index:2147483646;',
        'width:520px;height:650px;background:#fff;border-radius:16px;',
        'box-shadow:0 8px 40px rgba(0,0,0,0.18);',
        'display:flex;flex-direction:column;overflow:hidden;',
        'opacity:0;transform:translateY(16px) scale(0.96);pointer-events:none;',
        'transition:opacity .25s,transform .25s;',
        'font-family:system-ui,-apple-system,"Segoe UI",sans-serif}',
      '#cb-win.open{opacity:1;transform:none;pointer-events:all}',

      '#cb-head{background:' + c + ';padding:14px 16px;display:flex;align-items:center;gap:12px;flex-shrink:0}',
      '#cb-head-icon{width:36px;height:36px;border-radius:50%;background:rgba(255,255,255,.2);',
        'display:flex;align-items:center;justify-content:center;flex-shrink:0}',
      '#cb-head-icon svg{width:20px;height:20px;fill:#fff}',
      '#cb-head-info{flex:1;min-width:0}',
      '#cb-head-name{color:#fff;font-weight:700;font-size:15px}',
      '#cb-head-status{display:flex;align-items:center;gap:5px;margin-top:2px}',
      '#cb-status-dot{width:7px;height:7px;border-radius:50%;background:#4ade80;animation:cb-blink 2s infinite}',
      '@keyframes cb-blink{0%,100%{opacity:1}50%{opacity:.4}}',
      '#cb-status-txt{color:rgba(255,255,255,.75);font-size:11px}',
      '#cb-clear,#cb-close-btn{background:rgba(255,255,255,.15);border:none;width:28px;height:28px;border-radius:7px;',
        'cursor:pointer;display:flex;align-items:center;justify-content:center;transition:background .2s}',
      '#cb-clear:hover,#cb-close-btn:hover{background:rgba(255,255,255,.25)}',
      '#cb-clear svg,#cb-close-btn svg{width:14px;height:14px;fill:#fff}',
      '#cb-close-btn{display:none}',

      '#cb-msgs{flex:1;overflow-y:auto;padding:16px 14px;scroll-behavior:smooth}',
      '#cb-msgs-inner{display:flex;flex-direction:column;gap:10px;min-height:100%;justify-content:flex-end}',
      '#cb-msgs::-webkit-scrollbar{width:4px}',
      '#cb-msgs::-webkit-scrollbar-thumb{background:#d1d5db;border-radius:4px}',

      '.cb-m{display:flex;gap:8px;align-items:flex-end;animation:cb-in .18s ease}',
      '@keyframes cb-in{from{opacity:0;transform:translateY(5px)}to{opacity:1;transform:none}}',
      '.cb-m.u{flex-direction:row-reverse}',
      '.cb-avatar{width:30px;height:30px;border-radius:50%;flex-shrink:0;background:' + c + ';display:flex;align-items:center;justify-content:center}',
      '.cb-avatar svg{width:16px;height:16px;fill:#fff}',
      '.cb-body{max-width:100%;display:flex;flex-direction:column;gap:4px}',
      '.cb-m.a .cb-body{width:100%}',
      '.cb-m.u .cb-body{align-items:flex-end}',
      '.cb-bubble{padding:14px 18px !important;border-radius:18px !important;font-size:13px !important;line-height:1.6 !important;word-break:break-word !important}',
      '.cb-bubble p{margin:0 0 10px}.cb-bubble p:last-child{margin:0}',
      '.cb-bubble ul{margin:10px 0 10px 22px}.cb-bubble ol{margin:10px 0 10px 24px}',
      '.cb-bubble li{padding:4px 0}',
      '.cb-m.a .cb-bubble{background:#e8edf5;color:#1e293b;border-bottom-left-radius:4px;box-shadow:0 1px 3px rgba(0,0,0,.1);width:100%}',
      '.cb-m.u .cb-bubble{background:' + c + ';color:#fff;border-bottom-right-radius:4px;padding:12px 18px}',
      '.cb-bubble a{color:' + c + ';text-decoration:underline}',
      '.cb-m.u .cb-bubble a{color:rgba(255,255,255,.9)}',
      '.cb-time{font-size:11px;color:#94a3b8;padding:0 3px}',

      '.cb-err{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;',
        'padding:9px 12px;border-radius:12px;font-size:13px;display:flex;gap:7px;align-items:flex-start}',
      '.cb-err svg{width:15px;height:15px;fill:#ef4444;flex-shrink:0;margin-top:1px}',

      '#cb-typing{display:none;align-items:flex-end;gap:8px;padding:0 14px 4px}',
      '#cb-typing.show{display:flex}',
      '.cb-typing-bub{background:#f1f5f9;padding:9px 13px;border-radius:14px;border-bottom-left-radius:4px;display:flex;gap:4px}',
      '.cb-dot{width:6px;height:6px;border-radius:50%;background:#94a3b8;animation:cb-bounce 1.2s infinite}',
      '.cb-dot:nth-child(2){animation-delay:.2s}.cb-dot:nth-child(3){animation-delay:.4s}',
      '@keyframes cb-bounce{0%,60%,100%{transform:none}30%{transform:translateY(-5px)}}',

      '#cb-input{padding:14px 16px;border-top:1px solid #e2e8f0;display:flex;gap:10px;align-items:flex-end;flex-shrink:0}',
      '#cb-ta{flex:1;resize:none;border:1.5px solid #e2e8f0;border-radius:14px;padding:14px 18px;',
        'font-family:inherit;font-size:14px;color:#1e293b;outline:none;transition:border-color .2s;max-height:160px;overflow-y:auto;min-height:52px}',
      '#cb-ta:focus{border-color:' + c + '}',
      '#cb-ta:disabled{background:#f8fafc;cursor:not-allowed}',
      '#cb-ta::placeholder{color:#94a3b8}',
      '#cb-send{width:38px;height:38px;border-radius:50%;border:none;background:' + c + ';',
        'cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0;',
        'transition:opacity .2s,transform .15s;opacity:.4}',
      '#cb-send.on{opacity:1}#cb-send.on:hover{transform:scale(1.08)}',
      '#cb-send:disabled{cursor:not-allowed}',
      '#cb-send svg{width:17px;height:17px;fill:#fff}',

      '@media(max-width:540px){',
        '#cb-win{width:100%;height:80dvh;bottom:0;right:0;left:0;border-radius:18px 18px 0 0;padding-bottom:env(safe-area-inset-bottom)}',
        '#cb-btn.open{display:none}',
        '#cb-btn{bottom:16px;right:16px}',
        '#cb-ta{font-size:16px}',
        '#cb-close-btn{display:flex}',
      '}',
      '@media(max-width:380px){#cb-win{height:100dvh;border-radius:0;padding-top:env(safe-area-inset-top)}}',
    ].join('');

    var st = document.createElement('style');
    st.textContent = css;
    document.head.appendChild(st);
  }

  // ── SVG icons ─────────────────────────────────────────────────────────
  var IC_CHAT  = '<svg class="i-open" viewBox="0 0 24 24"><path d="M20 2H4C2.9 2 2 2.9 2 4v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 12H6l-2 2V4h16v10z"/></svg>';
  var IC_CLOSE = '<svg class="i-close" viewBox="0 0 24 24"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>';
  var IC_BOT   = '<svg viewBox="0 0 24 24"><path d="M20 9V7c0-1.1-.9-2-2-2h-3V3.5a1.5 1.5 0 10-3 0V5H9C7.9 5 7 5.9 7 7v2c-1.66 0-3 1.34-3 3s1.34 3 3 3v3c0 1.1.9 2 2 2h10c1.1 0 2-.9 2-2v-3c1.66 0 3-1.34 3-3s-1.34-3-3-3zm-2 10H8v-8h10v8zm-8-5c-.55 0-1-.45-1-1s.45-1 1-1 1 .45 1 1-.45 1-1 1zm6 0c-.55 0-1-.45-1-1s.45-1 1-1 1 .45 1 1-.45 1-1 1z"/></svg>';
  var IC_TRASH = '<svg viewBox="0 0 24 24"><path d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"/></svg>';
  var IC_SEND  = '<svg viewBox="0 0 24 24"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg>';
  var IC_ERR   = '<svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg>';

  // ── Text formatting ───────────────────────────────────────────────────
  function esc(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }
  function inline(t) {
    return t
      .replace(/\*\*(.*?)\*\*/g,'<strong>$1</strong>')
      .replace(/__(.*?)__/g,'<strong>$1</strong>')
      .replace(/\*(.*?)\*/g,'<em>$1</em>')
      .replace(/(https?:\/\/[^\s<"]+?)([.,;!?)\]]*)?(?=\s|$|<)/g,
        function(_,u){ return '<a href="'+u+'" target="_blank" rel="noopener">'+u+'</a>'; });
  }
  function fmt(text) {
    var lines = esc(text).split('\n'), html = '', ul = false, ol = false;
    for (var i = 0; i < lines.length; i++) {
      var l = lines[i], isB = /^[-•*] /.test(l), isN = /^\d+[.)]\s/.test(l), empty = !l.trim();
      if (isB) {
        if (ol) { html += '</ol>'; ol = false; }
        if (!ul) { html += '<ul>'; ul = true; }
        html += '<li>' + inline(l.replace(/^[-•*] /,'').trim()) + '</li>';
      } else if (isN) {
        if (ul) { html += '</ul>'; ul = false; }
        if (!ol) { html += '<ol>'; ol = true; }
        html += '<li>' + inline(l.replace(/^\d+[.)]\s/,'').trim()) + '</li>';
      } else {
        if (ul) { html += '</ul>'; ul = false; }
        if (ol) { html += '</ol>'; ol = false; }
        if (empty) { if (html && !html.endsWith('<br>')) html += '<br>'; }
        else html += '<p>' + inline(l) + '</p>';
      }
    }
    if (ul) html += '</ul>'; if (ol) html += '</ol>';
    return html;
  }
  function hhmm() {
    return new Date().toLocaleTimeString('es-ES',{hour:'2-digit',minute:'2-digit'});
  }

  // ── DOM ───────────────────────────────────────────────────────────────
  function buildDOM() {
    var root = document.createElement('div');
    root.id = 'cb-root';
    root.innerHTML =
      '<button id="cb-btn" aria-label="Abrir chat">' + IC_CHAT + IC_CLOSE + '</button>' +
      '<div id="cb-win" role="dialog" aria-label="Chat" aria-hidden="true">' +
        '<div id="cb-head">' +
          '<div id="cb-head-icon">' + IC_BOT + '</div>' +
          '<div id="cb-head-info">' +
            '<div id="cb-head-name">' + esc(cfg.name) + '</div>' +
            '<div id="cb-head-status"><div id="cb-status-dot"></div><span id="cb-status-txt">En línea</span></div>' +
          '</div>' +
          '<button id="cb-clear" title="Nueva conversación">' + IC_TRASH + '</button>' +
          '<button id="cb-close-btn" title="Cerrar">' + IC_CLOSE + '</button>' +
        '</div>' +
        '<div id="cb-msgs"><div id="cb-msgs-inner"></div></div>' +
        '<div id="cb-typing"><div class="cb-avatar">' + IC_BOT + '</div>' +
          '<div class="cb-typing-bub"><div class="cb-dot"></div><div class="cb-dot"></div><div class="cb-dot"></div></div>' +
        '</div>' +
        '<div id="cb-input">' +
          '<textarea id="cb-ta" rows="2" placeholder="' + esc(cfg.placeholder) + '"></textarea>' +
          '<button id="cb-send" aria-label="Enviar">' + IC_SEND + '</button>' +
        '</div>' +
      '</div>';
    document.body.appendChild(root);

    document.getElementById('cb-btn').addEventListener('click', toggle);
    document.getElementById('cb-clear').addEventListener('click', clearChat);
    document.getElementById('cb-close-btn').addEventListener('click', toggle);
    document.getElementById('cb-send').addEventListener('click', function() { doSend(); });

    var ta = document.getElementById('cb-ta');
    ta.addEventListener('input', function() {
      this.style.height = 'auto';
      this.style.height = Math.min(this.scrollHeight, 100) + 'px';
      document.getElementById('cb-send').classList.toggle('on', !!this.value.trim());
    });
    ta.addEventListener('keydown', function(e) {
      if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); doSend(); }
    });

    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape' && isOpen) toggle();
    });
  }

  function toggle() {
    isOpen = !isOpen;
    document.getElementById('cb-btn').classList.toggle('open', isOpen);
    var win = document.getElementById('cb-win');
    win.classList.toggle('open', isOpen);
    win.setAttribute('aria-hidden', String(!isOpen));
    if (isOpen && !initialized) { initialized = true; addMsg('a', cfg.welcome); }
    if (isOpen) setTimeout(function() { var ta=document.getElementById('cb-ta'); if(ta) ta.focus(); }, 250);
  }

  function addMsg(role, text, isErr) {
    var wrap = document.getElementById('cb-msgs');
    var inner = document.getElementById('cb-msgs-inner');
    var div = document.createElement('div');
    div.className = 'cb-m ' + (role === 'u' ? 'u' : 'a');
    var avatar = role === 'a' ? '<div class="cb-avatar">' + IC_BOT + '</div>' : '';
    var bubbleStyle = 'padding:14px 18px;border-radius:18px;font-size:13px;line-height:1.6;word-break:break-word;';
    var bubble = isErr
      ? '<div class="cb-err">' + IC_ERR + '<div>' + fmt(text) + '</div></div>'
      : '<div class="cb-bubble" style="' + bubbleStyle + '">' + fmt(text) + '</div>';
    div.innerHTML = avatar + '<div class="cb-body">' + bubble + '<div class="cb-time">' + hhmm() + '</div></div>';
    inner.appendChild(div);
    wrap.scrollTop = wrap.scrollHeight;
    return div;
  }

  function clearChat() {
    history = [];
    document.getElementById('cb-msgs-inner').innerHTML = '';
    initialized = false;
    addMsg('a', cfg.welcome);
    initialized = true;
  }

  function setLoading(v) {
    isLoading = v;
    var ta = document.getElementById('cb-ta');
    var btn = document.getElementById('cb-send');
    if (ta) ta.disabled = v;
    if (btn) { btn.disabled = v; if (!v) btn.classList.toggle('on', !!(ta && ta.value.trim())); }
    var typing = document.getElementById('cb-typing');
    if (typing) typing.classList.toggle('show', v);
    var msgs = document.getElementById('cb-msgs');
    if (msgs) msgs.scrollTop = msgs.scrollHeight;
  }

  function doSend() {
    var ta = document.getElementById('cb-ta');
    if (!ta) return;
    var text = ta.value.trim();
    if (!text || isLoading) return;
    ta.value = ''; ta.style.height = 'auto';
    document.getElementById('cb-send').classList.remove('on');
    addMsg('u', text);
    history.push({ role: 'user', content: text });
    while (history.length > MAX_HIST * 2) history.splice(0, 2);
    callAPI(text);
  }

  function callAPI(userText) {
    setLoading(true);
    var ctrl = new AbortController();
    var tid  = setTimeout(function() { ctrl.abort(); }, TIMEOUT_MS);

    fetch(BASE_URL + '/chat.php', {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify({ bot_id: BOT_ID, messages: history, session_id: sessionId }),
      signal:  ctrl.signal
    })
    .then(function(r) {
      clearTimeout(tid);
      if (!r.ok) return r.json().catch(function(){ return {}; }).then(function(e){ throw new Error(e.error || 'HTTP ' + r.status); });
      return r.json();
    })
    .then(function(data) {
      setLoading(false);
      var text = (data.content && data.content[0] && data.content[0].text) || '';
      if (!text) throw new Error('Respuesta vacía');
      if (data.session_id) sessionId = data.session_id;
      history.push({ role: 'assistant', content: text });
      addMsg('a', text);
      var ta = document.getElementById('cb-ta');
      if (ta) ta.focus();
    })
    .catch(function(err) {
      clearTimeout(tid);
      setLoading(false);
      // Remove last user msg from history on error
      if (history.length && history[history.length-1].role === 'user') history.pop();
      var msg = err.name === 'AbortError'
        ? 'La respuesta tardó demasiado. Inténtalo de nuevo.'
        : 'Error: ' + (err.message || 'Sin conexión');
      addMsg('a', msg, true);
    });
  }

  // ── Init ──────────────────────────────────────────────────────────────
  function init() {
    fetch(BASE_URL + '/api.php?action=config&bot=' + encodeURIComponent(BOT_ID))
      .then(function(r) { return r.ok ? r.json() : Promise.reject(); })
      .then(function(data) {
        cfg.name        = data.name        || cfg.name;
        cfg.welcome     = data.welcome     || cfg.welcome;
        cfg.placeholder = data.placeholder || cfg.placeholder;
        cfg.color       = data.color       || cfg.color;
        injectCSS(cfg.color);
        buildDOM();
      })
      .catch(function() {
        // If config fails, still load with defaults
        injectCSS(cfg.color);
        buildDOM();
      });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
