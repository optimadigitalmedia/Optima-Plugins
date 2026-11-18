/* Optima Chat widget. Plain JS, no dependencies. Design: Optima features
   DESIGN.md 5.1 (panel variant). chat-loader.js loads this file on the first
   tap on the button the plugin prints (#optima-chat), and the settings ride in
   that element's data-config. The panel opens only when the visitor asks:
   nothing here opens it on a timer, on scroll or on leaving the page.

   Law-firm sites (C.law): the fixed line always heads the panel; with AI
   answers off there is no free-text box, only a form for a name, a phone
   number and a short description (C.noteMax characters). Nothing else is sent. */
(function () {
  'use strict';
  var root = document.getElementById('optima-chat');
  if (root && root.querySelector('.oc-panel')) return; // Already running.
  var C = window.optimaChatConfig;
  if (!C && root) { try { C = JSON.parse(root.getAttribute('data-config') || 'null'); } catch (e) { C = null; } }
  if (!C) return;

  var TEAM = C.team, Team = TEAM.charAt(0).toUpperCase() + TEAM.slice(1);
  var LAW = !!C.law, MAX = C.noteMax || 500;
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var KEY = 'optimaChat';
  var state = { open: false, mode: 'ask', pending: '', kind: 'question', msgs: [], done: false };
  var busy = false, queue = [];

  var CHIPS = {
    cost: 'How does pricing work?',
    market: 'Is my market open?',
    work: 'See partner results',
    book: 'Book a strategy call',
    client: "I'm a current partner",
    other: 'Ask something else',
    change: 'Send a request',
    person: 'Talk to a strategist'
  };
  /* Law-firm sites: the only two paths. */
  var LCHIPS = { details: 'Leave my details', book: 'Book a call' };
  function startChips() {
    var c = [CHIPS.market, CHIPS.cost];
    if (C.workUrl) c.push(CHIPS.work);
    c.push(CHIPS.book, CHIPS.client, CHIPS.person, CHIPS.other);
    return c;
  }

  /* Events (docs/plugin-first.md rule 50): chat_start, booking_click and form_submit, reported by Optima
     Analytics. The other moments (chip taps, handoff, reopening the panel, links) are not standard events,
     so they send only their old name, and only on sites with old event names turned on. Never personal data. */
  function track(name, params, legacy) { if (typeof window.optimaTrack === 'function') window.optimaTrack(name, params || {}, legacy); }
  function save() { try { sessionStorage.setItem(KEY, JSON.stringify({ msgs: state.msgs.slice(-40), mode: state.mode, kind: state.kind, pending: state.pending, done: state.done })); } catch (e) {} }
  function load() { try { var s = JSON.parse(sessionStorage.getItem(KEY) || 'null'); if (s && s.msgs) { state.msgs = s.msgs; state.mode = s.mode; state.kind = s.kind; state.pending = s.pending; state.done = s.done; } } catch (e) {} }
  function el(tag, cls, text) { var n = document.createElement(tag); if (cls) n.className = cls; if (text != null) n.textContent = text; return n; }
  function mark() { var s = el('span', 'oc-mark'); s.setAttribute('aria-hidden', 'true'); return s; }

  function avatars(size) {
    var wrap = el('span', 'oc-stack oc-stack-' + size);
    var a = el('span', 'oc-ai'); a.appendChild(mark()); wrap.appendChild(a);
    (C.photos || []).forEach(function (src) { var i = el('img'); i.src = src; i.alt = ''; i.width = 24; i.height = 24; i.loading = 'lazy'; wrap.appendChild(i); });
    return wrap;
  }

  /* ---- Shell ----
     The plugin prints the root and the button with the page. Without them (an
     older page in a cache), build the same button here. */
  if (!root) { root = el('div'); root.id = 'optima-chat'; root.className = 'oc-root'; document.body.appendChild(root); }
  var launcher = root.querySelector('.oc-launcher');
  if (!launcher) {
    launcher = el('button', 'oc-launcher'); launcher.type = 'button'; launcher.setAttribute('aria-label', C.launcher);
    launcher.appendChild(avatars('sm')); launcher.appendChild(el('span', 'oc-launcher-label', C.launcher));
    if (!reduce) launcher.classList.add('oc-pulse');
    root.appendChild(launcher);
  }
  launcher.setAttribute('aria-haspopup', 'dialog'); launcher.setAttribute('aria-expanded', 'false');

  var panel = el('div', 'oc-panel'); panel.hidden = true; panel.tabIndex = -1;
  panel.setAttribute('role', 'dialog'); panel.setAttribute('aria-modal', 'false'); panel.setAttribute('aria-label', C.name);
  var head = el('div', 'oc-head');
  head.appendChild(avatars('md'));
  var ht = el('div', 'oc-head-text');
  ht.appendChild(el('strong', null, C.name));
  var status = el('span', 'oc-status'); status.appendChild(el('span', 'oc-dot'));
  status.appendChild(document.createTextNode(LAW ? 'Messages go to ' + TEAM : 'Strategists on call'));
  ht.appendChild(status); head.appendChild(ht);
  var personBtn = el('button', 'oc-person', LAW ? 'Talk to a person' : 'Talk to a strategist'); personBtn.type = 'button';
  if (LAW && !C.ai) personBtn.hidden = true; // The form is already the way to a person.
  var minBtn = el('button', 'oc-min'); minBtn.type = 'button'; minBtn.setAttribute('aria-label', 'Close chat');
  minBtn.innerHTML = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>';
  head.appendChild(personBtn); head.appendChild(minBtn);

  /* The disclosure: on screen from the moment the panel opens, before the visitor
     types. On a law-firm site the first line is the fixed line from the plugin. */
  var lines = [].concat(C.disclose || []).filter(Boolean);
  var disclose = el('div', 'oc-disclosure'); disclose.setAttribute('role', 'note');
  disclose.id = 'oc-disclosure';
  lines.forEach(function (t, i) { disclose.appendChild(el('p', LAW && i === 0 ? 'oc-fixed' : null, t)); });
  if (lines.length) panel.setAttribute('aria-describedby', disclose.id); else disclose.hidden = true;
  var stream = el('div', 'oc-stream');
  var live = el('div', 'oc-sr'); live.setAttribute('aria-live', 'polite');
  var form = el('form', 'oc-composer');
  var input = el('input'); input.type = 'text'; input.placeholder = 'Type your question'; input.setAttribute('aria-label', 'Your message'); input.maxLength = 1000;
  var send = el('button', 'oc-send'); send.type = 'submit'; send.setAttribute('aria-label', 'Send');
  send.innerHTML = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 19V5M5 12l7-7 7 7"/></svg>';
  form.appendChild(input); form.appendChild(send);
  if (LAW && !C.ai) form.hidden = true; // Collect mode on a law-firm site: no free text, only the form.
  var foot = el('p', 'oc-foot', LAW
    ? (C.ai ? 'AI answers. A person is one tap away.' : 'Every message goes to ' + TEAM + '.')
    : (C.ai ? 'AI answers, reviewed by ' + TEAM + '. A person is one tap away.' : 'A strategist reads every question. A person is one tap away.'));

  panel.appendChild(head); panel.appendChild(disclose); panel.appendChild(stream); panel.appendChild(form); panel.appendChild(foot); panel.appendChild(live);
  root.insertBefore(panel, launcher);

  /* ---- Rendering ---- */
  function scroll() { stream.scrollTop = stream.scrollHeight; }
  function clearChips() { [].forEach.call(stream.querySelectorAll('.oc-chips'), function (c) { c.remove(); }); }

  function render(m, animate) {
    var row;
    if (m.from === 'user') {
      row = el('div', 'oc-msg oc-user'); row.appendChild(el('div', 'oc-bubble', m.text));
    } else if (m.kind === 'tiers') {
      row = el('div', 'oc-msg oc-bot');
      var card = el('div', 'oc-card');
      card.appendChild(el('p', 'oc-eyebrow', 'What we manage'));
      [['01', 'Paid search and LSAs', 'Local Services Ads and Google Ads, managed to cost per signed case.'],
       ['02', 'Local SEO', 'Your Google Business Profile, Map Pack visibility and citations, built where injury cases start.'],
       ['03', 'SEO and content', 'Non-brand organic growth from practice-area content written to YMYL standards.'],
       ['04', 'Websites', 'Fast, conversion-built sites your firm owns outright.']].forEach(function (t) {
        var r = el('div', 'oc-tier'); r.appendChild(el('span', 'oc-num', t[0]));
        var b = el('div'); b.appendChild(el('strong', null, t[1])); b.appendChild(el('span', null, t[2])); r.appendChild(b); card.appendChild(r);
      });
      row.appendChild(card);
    } else if (m.kind === 'link') {
      row = el('div', 'oc-msg oc-bot');
      var a = el('a', 'oc-linkbtn', m.label); a.href = m.url; a.setAttribute('data-optima-location', 'chat');
      if (m.event) a.setAttribute('data-optima-track', m.event);
      a.setAttribute('data-optima-legacy', m.track || 'chat_link'); row.appendChild(a);
    } else if (m.kind === 'form') {
      row = el('div', 'oc-msg oc-bot'); row.appendChild(LAW ? lawForm() : leadForm(m));
    } else if (m.kind === 'done') {
      row = el('div', 'oc-msg oc-bot');
      var d = el('div', 'oc-done'); var ic = el('span', 'oc-check'); ic.innerHTML = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 12 5 5L20 7"/></svg>';
      d.appendChild(ic); var db = el('div'); db.appendChild(el('strong', null, m.title)); db.appendChild(el('span', null, m.text)); d.appendChild(db); row.appendChild(d);
    } else {
      row = el('div', 'oc-msg oc-bot'); var bub = el('div', 'oc-bubble'); row.appendChild(bub);
      if (animate && !reduce) { streamText(bub, m.text, function () { live.textContent = m.text; chips(m); }); stream.appendChild(row); scroll(); return; }
      bub.textContent = m.text;
    }
    stream.appendChild(row); scroll();
    if (m.kind !== 'form') chips(m);
  }

  function chips(m) {
    if (!m.chips || !m.chips.length || m !== state.msgs[state.msgs.length - 1]) return;
    clearChips();
    var w = el('div', 'oc-chips');
    m.chips.forEach(function (label) {
      var b = el('button', 'oc-chip', label); b.type = 'button';
      b.addEventListener('click', function () { track('', { link_label: label, location: 'chat' }, 'chip_click'); clearChips(); user(label); route(label); });
      w.appendChild(b);
    });
    stream.appendChild(w); scroll();
  }

  function streamText(node, text, done) {
    var words = text.split(' '), n = 0, caret = el('span', 'oc-caret');
    node.appendChild(caret);
    var iv = setInterval(function () {
      n += 1; node.textContent = words.slice(0, n).join(' '); node.appendChild(caret); scroll();
      if (n >= words.length) { clearInterval(iv); caret.remove(); done(); }
    }, 28);
  }

  /* Bot messages play one at a time, with a short "typing" pause. */
  function bot(items) {
    items.forEach(function (m) { m.from = 'bot'; queue.push(m); });
    if (!busy) next();
  }
  function next() {
    var m = queue.shift();
    if (!m) { busy = false; save(); return; }
    busy = true;
    var dots = el('div', 'oc-msg oc-bot oc-typing'); dots.innerHTML = '<span></span><span></span><span></span>';
    stream.appendChild(dots); scroll();
    setTimeout(function () {
      dots.remove(); state.msgs.push(m); render(m, true);
      var wait = (m.kind && m.kind !== 'text') || reduce ? 250 : m.text.split(' ').length * 28 + 250;
      setTimeout(next, wait);
    }, reduce ? 150 : 650);
  }
  function user(text) { var m = { from: 'user', text: text }; state.msgs.push(m); render(m); save(); }

  /* ---- Flows ---- */
  function route(label) {
    if (LAW) {
      if (label === LCHIPS.details) handoff();
      else if (label === LCHIPS.book) bot([{ kind: 'link', label: 'See open times', url: C.bookUrl, track: 'chat_book', event: 'booking_click' }]);
      return;
    }
    if (label === CHIPS.cost) {
      bot([{ kind: 'tiers' }, { text: 'We scope pricing after a strategy call, once we know your market, your case mix and which channels it needs. Every partnership runs on a 90-day notice, and your firm owns every account, every asset and all of its data.', chips: [CHIPS.book, CHIPS.market, CHIPS.other] }]);
    } else if (label === CHIPS.market) {
      state.mode = 'market'; bot([{ text: 'We partner with one injury firm per market, so it depends on who already holds yours. Which city or metro, and what is your main practice focus?' }]); input.focus();
    } else if (label === CHIPS.work) {
      bot([{ text: 'These are partner results, described by market and practice. We never name a partner firm.' }, { kind: 'link', label: 'See the results', url: C.workUrl, track: 'chat_work' }, { text: 'Anything else I can help with?', chips: [CHIPS.book, CHIPS.other] }]);
    } else if (label === CHIPS.book) {
      bot([{ text: 'Pick a time that works for you. You will talk with ' + TEAM + ' directly, and we come with a first read on your market.' }, { kind: 'link', label: 'See open times', url: C.bookUrl, track: 'chat_book', event: 'booking_click' }]);
    } else if (label === CHIPS.client) {
      bot([{ text: 'Good to see you. What do you need today?', chips: [CHIPS.change, CHIPS.person] }]);
    } else if (label === CHIPS.change) {
      state.mode = 'change'; bot([{ text: 'Tell us what you need in a sentence or two: a site edit, a campaign change or a question about your report.' }]); input.focus();
    } else if (label === CHIPS.person) {
      handoff();
    } else if (label === CHIPS.other) {
      state.mode = 'ask'; bot([{ text: 'Go ahead and type your question.' }]); input.focus();
    }
  }

  function handoff(note) {
    track('', { location: 'chat' }, 'handoff');
    state.kind = 'person';
    if (LAW) { bot([{ text: 'Leave your name, your phone number and a few words about what happened, and ' + TEAM + ' will get back to you.' }, { kind: 'form' }]); return; }
    bot([{ text: (note || 'Happy to bring in ' + TEAM + '.') + ' Where should they reach you?' }, { kind: 'form' }]);
  }

  function askForContact(kind, lead) {
    state.kind = kind;
    if (LAW) { bot([{ text: lead + 'Leave your name and phone number, and ' + TEAM + ' will get back to you.' }, { kind: 'form' }]); return; }
    bot([{ text: lead + ' Where should we send it?' }, { kind: 'form' }]);
  }

  function onUserText(text) {
    if (LAW) state.mode = 'ask'; // Law-firm sites have no scripted modes.
    if (state.mode === 'change') {
      state.mode = 'ask'; state.pending = text;
      askForContact('change', 'Got it. ' + Team + ' will take care of it and confirm by email.');
      return;
    }
    if (state.mode === 'market') {
      state.mode = 'ask'; state.pending = text;
      askForContact('market', 'Thanks. We will check who holds that market and send you the answer, usually within one business day.');
      return;
    }
    // A hello or a thanks is not a question: greet back and offer the buttons.
    if (/^((hi+|hello|hey+|hiya|howdy|yo|sup|wh?at'?s ?up|good (morning|afternoon|evening))( there| y'?all| guys)?|thanks?( you)?|thank you|ty|ok(ay)?|cool)[\s!.,?]*$/i.test(text.trim())) {
      bot([{ text: 'Hi there. What can we help you with? Type your question, or pick one below.', chips: LAW ? [LCHIPS.details, LCHIPS.book] : startChips() }]);
      return;
    }
    state.pending = text;
    var lead = /\?\s*$/.test(text) ? 'Good question. ' : 'Thanks. ';
    if (!C.ai) { askForContact('question', LAW ? lead : lead + 'A strategist answers these personally, usually within one business day.'); return; }
    var dots = el('div', 'oc-msg oc-bot oc-typing'); dots.innerHTML = '<span></span><span></span><span></span>'; stream.appendChild(dots); scroll(); busy = true;
    post('/ask', { question: text, history: history(), page: location.href }).then(function (r) {
      dots.remove(); busy = false;
      if (r && r.answer) {
        bot([{ text: r.answer, chips: LAW ? [LCHIPS.details, LCHIPS.book] : (r.handoff ? [CHIPS.person, CHIPS.book] : [CHIPS.book, CHIPS.person]) }]);
      } else {
        askForContact('question', LAW ? lead : lead + Team + ' will answer it personally, usually within one business day.');
      }
    });
  }

  function history() { return state.msgs.filter(function (m) { return m.text; }).map(function (m) { return { from: m.from, text: m.text }; }); }

  function post(path, body) {
    return fetch(C.rest + path, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body), credentials: 'omit' })
      .then(function (r) { return r.json().then(function (j) { return r.ok ? j : { error: j && j.message ? j.message : 'Something went wrong. Please try again.' }; }); })
      .catch(function () { return { error: 'We could not reach the site. Please try again.' }; });
  }

  function leadForm() {
    var f = el('form', 'oc-form'); f.noValidate = true;
    var n = el('input'); n.type = 'text'; n.name = 'name'; n.autocomplete = 'name'; n.required = true;
    var t = el('input'); t.type = 'tel'; t.name = 'phone'; t.autocomplete = 'tel'; t.inputMode = 'tel';
    var e = el('input'); e.type = 'email'; e.name = 'email'; e.autocomplete = 'email';
    var hp = el('input'); hp.type = 'text'; hp.name = 'website'; hp.tabIndex = -1; hp.autocomplete = 'off'; hp.className = 'oc-hp'; hp.setAttribute('aria-hidden', 'true');
    var id = 'sc' + Math.random().toString(36).slice(2, 7);
    n.id = id + 'n'; t.id = id + 't'; e.id = id + 'e';
    var ln = el('label', null, 'Your name'); ln.htmlFor = n.id;
    var lt = el('label', null, 'Phone, to text you back'); lt.htmlFor = t.id;
    var le = el('label', null, 'Or email'); le.htmlFor = e.id;
    var err = el('p', 'oc-err'); err.setAttribute('role', 'alert');
    var b = el('button', 'oc-submit', 'Send'); b.type = 'submit';
    [ln, n, lt, t, le, e, hp, err, b].forEach(function (x) { f.appendChild(x); });
    f.addEventListener('submit', function (ev) {
      ev.preventDefault(); err.textContent = '';
      if (!n.value.trim()) { err.textContent = 'Add your name so we know who we are talking to.'; n.focus(); return; }
      var digits = t.value.replace(/\D/g, ''), mail = e.value.trim();
      if (!digits && !mail) { err.textContent = 'Add a phone number or an email so we can reply.'; t.focus(); return; }
      if (digits && (digits.length < 10 || digits.length > 11)) { err.textContent = 'That phone number does not look quite right.'; t.focus(); return; }
      if (mail && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(mail)) { err.textContent = 'That email does not look quite right.'; e.focus(); return; }
      b.disabled = true; b.textContent = 'Sending';
      post('/lead', { name: n.value.trim(), phone: t.value.trim(), email: e.value.trim(), website: hp.value, kind: state.kind, history: history(), page: location.href }).then(function (r) {
        if (r.error) { err.textContent = r.error; b.disabled = false; b.textContent = 'Send'; return; }
        track('form_submit', { form_id: 'chat', link_label: state.kind, location: 'chat', method: 'chat' }, 'lead_submit');
        f.querySelectorAll('input,button').forEach(function (x) { x.disabled = true; }); b.textContent = 'Sent';
        state.done = true;
        bot([{ kind: 'done', title: 'Sent', text: 'Thanks, ' + n.value.trim().split(' ')[0] + '. ' + Team + (digits ? ' will text you at ' + t.value.trim() : ' will reply to ' + mail) + ', usually within one business day.' },
             { text: 'Anything else?', chips: [CHIPS.book, CHIPS.other] }]);
      });
    });
    setTimeout(function () { n.focus(); }, 50);
    return f;
  }

  /* Law-firm sites: a name, a phone number and a short description. Nothing
     else is asked for or sent (no email, no transcript, no files); the server
     drops anything else too. */
  function lawForm() {
    var f = el('form', 'oc-form'); f.noValidate = true;
    var n = el('input'); n.type = 'text'; n.name = 'name'; n.autocomplete = 'name'; n.required = true; n.maxLength = 100;
    var t = el('input'); t.type = 'tel'; t.name = 'phone'; t.autocomplete = 'tel'; t.inputMode = 'tel'; t.required = true;
    var d = el('textarea'); d.name = 'description'; d.maxLength = MAX; d.rows = 4;
    if (state.pending) d.value = state.pending.slice(0, MAX);
    var hp = el('input'); hp.type = 'text'; hp.name = 'website'; hp.tabIndex = -1; hp.autocomplete = 'off'; hp.className = 'oc-hp'; hp.setAttribute('aria-hidden', 'true');
    var id = 'sc' + Math.random().toString(36).slice(2, 7);
    n.id = id + 'n'; t.id = id + 't'; d.id = id + 'd';
    var ln = el('label', null, 'Your name'); ln.htmlFor = n.id;
    var lt = el('label', null, 'Phone number'); lt.htmlFor = t.id;
    var ld = el('label', null, 'What happened, in a few words'); ld.htmlFor = d.id;
    var hint = el('p', 'oc-hint', 'Up to ' + MAX + ' characters. Please leave out anything confidential.'); hint.id = id + 'h';
    d.setAttribute('aria-describedby', hint.id);
    var err = el('p', 'oc-err'); err.setAttribute('role', 'alert');
    var b = el('button', 'oc-submit', 'Send'); b.type = 'submit';
    [ln, n, lt, t, ld, d, hint, hp, err, b].forEach(function (x) { f.appendChild(x); });
    f.addEventListener('submit', function (ev) {
      ev.preventDefault(); err.textContent = '';
      if (!n.value.trim()) { err.textContent = 'Add your name so we know who we are talking to.'; n.focus(); return; }
      var digits = t.value.replace(/\D/g, '');
      if (!digits) { err.textContent = 'Add a phone number so we can reach you.'; t.focus(); return; }
      if (digits.length < 10 || digits.length > 11) { err.textContent = 'That phone number does not look quite right.'; t.focus(); return; }
      b.disabled = true; b.textContent = 'Sending';
      post('/lead', { name: n.value.trim(), phone: t.value.trim(), description: d.value.trim().slice(0, MAX), website: hp.value, kind: state.kind, page: location.origin + location.pathname }).then(function (r) {
        if (r.error) { err.textContent = r.error; b.disabled = false; b.textContent = 'Send'; return; }
        track('form_submit', { form_id: 'chat', link_label: state.kind, location: 'chat', method: 'chat' }, 'lead_submit');
        f.querySelectorAll('input,textarea,button').forEach(function (x) { x.disabled = true; }); b.textContent = 'Sent';
        state.done = true; state.pending = '';
        /* No name or number in the reply: the conversation is kept in the browser for the visit. */
        var after = [{ kind: 'done', title: 'Sent', text: 'Thanks. ' + Team + ' will get back to you at the number you gave.' }];
        if (C.ai) after.push({ text: 'Anything else?', chips: [LCHIPS.book] });
        bot(after);
      });
    });
    setTimeout(function () { n.focus(); }, 50);
    return f;
  }

  /* ---- Open and close ---- */
  function open() {
    if (state.open) return;
    state.open = true; panel.hidden = false; launcher.setAttribute('aria-expanded', 'true'); root.classList.add('is-open');
    /* chat_start once per visit (the first time the panel opens); the old chat_open on every open. */
    var started = false; try { started = !!sessionStorage.getItem(KEY + 'Started'); sessionStorage.setItem(KEY + 'Started', '1'); } catch (e) {}
    track(started ? '' : 'chat_start', { location: 'chat', method: C.ai ? 'ai' : 'collect' }, 'chat_open');
    if (!stream.childNodes.length) {
      if (state.msgs.length) {
        state.msgs.forEach(function (m) { if (m.kind !== 'form') render(m, false); });
        if (LAW && !C.ai && !state.done) bot([{ kind: 'form' }]); // No text box here, so the form comes back.
      }
      else if (LAW && !C.ai) bot([{ text: 'Hi, I\'m ' + C.name + '. Leave your name, your phone number and a few words about what happened, and ' + TEAM + ' will get back to you.' }, { kind: 'form' }]);
      else if (LAW) bot([{ text: 'Hi, I\'m ' + C.name + '. Type a question, or leave your details and ' + TEAM + ' will get back to you.', chips: [LCHIPS.details, LCHIPS.book] }]);
      else bot([{ text: 'Hi, I\'m ' + C.name + '. I can answer common questions right away, and ' + TEAM + ' is a tap away whenever you want a person.' },
                { text: 'What brings you by?', chips: startChips() }]);
    }
    /* Focus moves into the panel: the text box, or the panel itself when there is none. */
    setTimeout(function () { (form.hidden ? panel : input).focus(); }, 60);
  }
  function close() { state.open = false; panel.hidden = true; launcher.setAttribute('aria-expanded', 'false'); root.classList.remove('is-open'); launcher.focus(); }

  launcher.addEventListener('click', function () { state.open ? close() : open(); });
  minBtn.addEventListener('click', close);
  personBtn.addEventListener('click', function () { if (!state.open) open(); clearChips(); handoff(); });
  panel.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
  form.addEventListener('submit', function (e) {
    e.preventDefault(); var t = input.value.trim(); if (!t || busy) return;
    input.value = ''; clearChips(); user(t); onUserText(t);
  });

  load();
  /* chat-loader.js calls open() once this file and chat.css have loaded. */
  window.optimaChat = { open: open, close: close };
})();
