/*
 * Club Manager 1.98.0: Keep it up (club request 25 Nov 2026).
 *
 * The start card's Play button opens the game over the whole window: tap or
 * click the ball to keep it up; where you hit it decides where it goes (the
 * middle sends it straight up, off-centre sends it away from that side);
 * touching the bottom of the window ends the game. Space or Enter hits it
 * dead centre while it is falling in the lower part of the window.
 *
 * The leaderboard, a member's best and their places come from the routes of
 * TeamOversight_Keep_It_Up (murvc/v1/keep-it-up/...): GET board for anyone,
 * POST start, finish and settings for logged-in members with the wp_rest
 * nonce. A guest's best is kept on this device only (localStorage, which may
 * be missing or refuse).
 *
 * Everything is drawn with createElement and textContent (never markup from
 * a string) and on the canvas; the ball is the club's volleyball drawing
 * (assets/keep-it-up-ball.svg) rasterised at the size it is drawn.
 */
(function () {
  'use strict';

  var STORE_KEY = 'murvc-keep-it-up-best';
  var STEP = 1 / 120;          // fixed physics step, seconds
  var MAX_FRAME = 0.1;         // a longer frame (a hiccup) counts as this much
  var RISE = 0.62;             // a touch lifts the ball about this share of the window's height
  var APEX = 0.72;             // seconds to the top of that rise at the start
  var GRAVITY_STEP = 1.05;     // every 10 touches gravity grows by this much...
  var GRAVITY_MAX = 1.5;       // ...up to this much in all
  var SIZE_STEP = 0.96;        // every 10 touches the ball shrinks by this much...
  var SIZE_MIN = 0.6;          // ...down to this share of its first size
  var SIZE_SHARE = 0.11;       // the ball's width: this share of the window's smaller side...
  var SIZE_LO = 56;            // ...at least this many pixels...
  var SIZE_HI = 120;           // ...and at most this many
  var SIDE = 0.75;             // an edge hit's sideways speed: this share of the smaller side a second
  var DEAD = 0.06;             // a hit this close to the middle goes straight up
  var WALL = 0.72;             // a side bounce keeps this much of the speed
  var DRAG = 0.12;             // sideways speed lost each second
  var SPIN = 0.5;              // spin follows the sideways speed
  var GRACE_TOUCH = 8;         // extra pixels around the ball for a finger (a mouse gets none)
  var COOLDOWN_MS = 180;       // touches closer than this count once (the site refuses under 120 ms)
  var REST = 0.78;             // the waiting ball's centre, as a share of the height
  var KEY_LOW = 0.6;           // Space or Enter hits only while the ball is falling below this share
  var KEY_LOCK_MS = 350;       // a second key press outside that window since the last hit locks the keys this long (longer than the window), so mashing loses the ball
  var LAG_MS = 100;            // a tap also counts on where the ball was drawn this recently (the screen and a finger lag the physics)
  var TRAIL = 16;              // drawn positions kept for that (100 ms at up to 160 frames a second)
  var START_WAIT_MS = 1500;    // a new game's start waits this long at most for the last game's save to land
  var WORDS = { 10: 'Nice dig!', 25: 'Rally!', 50: 'Libero!', 100: 'Hall of fame!', 150: 'Still up!', 200: 'Legend.' };

  var roots = [];
  var G = null; // the one game layer, made on the first Play
  var memBest = 0; // a guest's best for this page's life, when the device won't keep it

  // ------------------------------------------------------------------ helpers

  function el(tag, cls, text) {
    var e = document.createElement(tag);
    if (cls) { e.className = cls; }
    if (text !== undefined && text !== null) { e.textContent = String(text); }
    return e;
  }

  function clear(node) {
    while (node.firstChild) { node.removeChild(node.firstChild); }
  }

  function ord(n) {
    var s = ['th', 'st', 'nd', 'rd'], v = n % 100;
    return n + (s[(v - 20) % 10] || s[v] || s[0]);
  }

  function num(n) {
    n = Math.max(0, parseInt(n, 10) || 0);
    return n >= 1000 ? String(n).replace(/\B(?=(\d{3})+(?!\d))/g, ',') : String(n);
  }

  function clock() {
    return window.performance && performance.now ? performance.now() : Date.now();
  }

  /** A guest's best: the device's, or this page's when the device won't keep it (storage blocked). */
  function readBest() {
    var stored = 0;
    try {
      var n = parseInt(window.localStorage.getItem(STORE_KEY), 10);
      stored = n > 0 ? n : 0;
    } catch (e) { /* storage blocked: the page's own best still counts */ }
    return Math.max(memBest, stored);
  }

  function writeBest(n) {
    if (n > memBest) { memBest = n; }
    try {
      window.localStorage.setItem(STORE_KEY, String(n));
    } catch (e) { /* private window or storage switched off: the best is kept for this page only */ }
  }

  function withQuery(url, params) {
    var q = [];
    for (var k in params) {
      if (Object.prototype.hasOwnProperty.call(params, k)) {
        q.push(encodeURIComponent(k) + '=' + encodeURIComponent(params[k]));
      }
    }
    return url + (url.indexOf('?') === -1 ? '?' : '&') + q.join('&');
  }

  /** One request to a route: resolves {status, data}; status 0 when the network failed. */
  function call(cfg, method, url, body) {
    if (!window.fetch) {
      return Promise.resolve({ status: 0, data: {} });
    }
    var headers = { 'Accept': 'application/json' };
    if (cfg.nonce) { headers['X-WP-Nonce'] = cfg.nonce; }
    var opts = { method: method, credentials: 'same-origin', cache: 'no-store', headers: headers };
    if (body) {
      headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(body);
    }
    return window.fetch(url, opts).then(function (res) {
      return res.json().then(function (data) {
        return { status: res.status, data: data && typeof data === 'object' ? data : {} };
      }, function () {
        return { status: res.status, data: {} };
      });
    }, function () {
      return { status: 0, data: {} };
    });
  }

  /** The page's login has timed out (WordPress refuses the nonce): carry on as a guest who can't save. */
  function nonceExpired(r) {
    return r.status === 403 && r.data && r.data.code === 'rest_cookie_invalid_nonce';
  }

  function forgetNonce() {
    roots.forEach(function (root) {
      root.kiu.nonce = '';
      root.kiu.expired = true;
    });
  }

  // ------------------------------------------------------------------ the start card and the board

  function setup(root, index) {
    if (root.getAttribute('data-ready')) { return; }
    root.setAttribute('data-ready', '1');
    var cfg;
    try {
      cfg = JSON.parse(root.getAttribute('data-config') || '{}');
    } catch (e) {
      return;
    }
    cfg.uid = 'murvc-kiu-' + index;
    cfg.tab = 'year';
    cfg.viewer = null;
    root.kiu = cfg;
    roots.push(root);

    var play = root.querySelector('.murvc-kiu-play');
    if (play) {
      play.disabled = false;
      play.addEventListener('click', function () { openGame(root); });
    }
    var box = root.querySelector('.murvc-kiu-show-box');
    if (box) {
      box.disabled = false;
      box.addEventListener('change', function () { saveShown(root, box); });
    }
    showBest(root);
    loadBoard(root);
  }

  function showBest(root) {
    var cfg = root.kiu, span = root.querySelector('.murvc-kiu-best');
    if (!span) { return; }
    var text = '';
    if (cfg.member && cfg.viewer) {
      if (cfg.viewer.best_all > 0) {
        text = 'Your best: ' + num(cfg.viewer.best_all) + '. This year: ' + num(cfg.viewer.best_year) + '.';
      }
    } else if (!cfg.member) {
      var b = readBest();
      if (b > 0) { text = 'Your best on this device: ' + num(b); }
    }
    span.textContent = text;
    span.hidden = text === '';
  }

  function boardStatus(root, text) {
    var body = root.querySelector('.murvc-kiu-board-body');
    if (!body) { return; }
    clear(body);
    body.appendChild(el('p', 'murvc-kiu-status', text));
  }

  function loadBoard(root) {
    var cfg = root.kiu;
    return call(cfg, 'GET', withQuery(cfg.board, { limit: cfg.limit, _: Date.now() })).then(function (r) {
      if (nonceExpired(r)) {
        forgetNonce();
        return loadBoard(root);
      }
      if (r.status === 404 && r.data.code === 'off') {
        boardStatus(root, 'The game is switched off.');
        var play = root.querySelector('.murvc-kiu-play');
        if (play) { play.disabled = true; }
        return;
      }
      if (r.status !== 200 || !r.data.ok || !r.data.boards) {
        boardStatus(root, 'The leaderboard didn\'t load. Try again later.');
        return;
      }
      cfg.viewer = r.data.viewer || null;
      if (cfg.viewer && typeof cfg.viewer.shown === 'boolean') {
        cfg.shown = cfg.viewer.shown;
        var box = root.querySelector('.murvc-kiu-show-box');
        if (box) { box.checked = cfg.shown; }
      }
      renderBoard(root, r.data);
      showBest(root);
    });
  }

  function listOf(rows, empty) {
    if (!rows || !rows.length) {
      return el('p', 'murvc-kiu-status', empty);
    }
    var ol = el('ol', 'murvc-kiu-list');
    rows.forEach(function (row) {
      var li = el('li', row.me ? 'is-me' : '');
      li.appendChild(el('span', 'murvc-kiu-place', ord(row.place)));
      var name = el('span', 'murvc-kiu-name', row.name);
      if (row.me) { name.appendChild(el('span', 'murvc-kiu-sr', ' (you)')); }
      li.appendChild(name);
      li.appendChild(el('span', 'murvc-kiu-score', num(row.score)));
      ol.appendChild(li);
    });
    return ol;
  }

  /** The line under a board for a logged-in viewer: their place and best when they aren't shown in it. */
  function meLine(viewer, which, rows) {
    if (!viewer) { return ''; }
    var place = viewer['place_' + which] || 0, best = viewer['best_' + which] || 0;
    if (!viewer.shown) {
      return place > 0 ? 'You\'re hidden from the leaderboard. You\'d be ' + ord(place) + ', with a best of ' + num(best) + '.' : 'You\'re hidden from the leaderboard.';
    }
    for (var i = 0; i < rows.length; i++) {
      if (rows[i].me) { return ''; }
    }
    if (place > 0) { return 'You\'re ' + ord(place) + ', with a best of ' + num(best) + '.'; }
    return which === 'year' ? 'No score from you this year yet.' : 'No score from you yet.';
  }

  function renderBoard(root, data) {
    var cfg = root.kiu, body = root.querySelector('.murvc-kiu-board-body');
    if (!body) { return; }
    clear(body);
    if (cfg.compact) {
      body.appendChild(listOf(data.boards.year, 'No scores yet this year.'));
      return;
    }
    var keys = [['year', 'This year'], ['all', 'All time']];
    var tabs = el('div', 'murvc-kiu-tabs');
    tabs.setAttribute('role', 'tablist');
    tabs.setAttribute('aria-label', 'Leaderboards');
    var buttons = [], panels = [];
    keys.forEach(function (k) {
      var on = cfg.tab === k[0];
      var b = el('button', 'murvc-kiu-tab', k[1]);
      b.type = 'button';
      b.id = cfg.uid + '-tab-' + k[0];
      b.setAttribute('role', 'tab');
      b.setAttribute('aria-selected', on ? 'true' : 'false');
      b.setAttribute('aria-controls', cfg.uid + '-panel-' + k[0]);
      b.tabIndex = on ? 0 : -1;
      b.setAttribute('data-board', k[0]);
      tabs.appendChild(b);
      buttons.push(b);

      var p = el('div', 'murvc-kiu-tabpanel');
      p.id = cfg.uid + '-panel-' + k[0];
      p.setAttribute('role', 'tabpanel');
      p.setAttribute('aria-labelledby', b.id);
      p.hidden = !on;
      var rows = data.boards[k[0]] || [];
      if (k[0] === 'year' && data.year) {
        p.appendChild(el('p', 'murvc-kiu-period', String(data.year)));
      }
      p.appendChild(listOf(rows, k[0] === 'year' ? 'No scores yet this year.' : 'No scores yet.'));
      var line = meLine(data.viewer, k[0], rows);
      if (line) { p.appendChild(el('p', 'murvc-kiu-me', line)); }
      panels.push(p);
    });
    var pick = function (i, focus) {
      cfg.tab = keys[i][0];
      buttons.forEach(function (b, j) {
        b.setAttribute('aria-selected', j === i ? 'true' : 'false');
        b.tabIndex = j === i ? 0 : -1;
        panels[j].hidden = j !== i;
      });
      if (focus) { buttons[i].focus(); }
    };
    buttons.forEach(function (b, i) {
      b.addEventListener('click', function () { pick(i, false); });
      b.addEventListener('keydown', function (e) {
        var to = -1;
        if (e.key === 'ArrowRight' || e.key === 'ArrowDown') { to = (i + 1) % buttons.length; }
        if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') { to = (i + buttons.length - 1) % buttons.length; }
        if (e.key === 'Home') { to = 0; }
        if (e.key === 'End') { to = buttons.length - 1; }
        if (to >= 0) { e.preventDefault(); pick(to, true); }
      });
    });
    body.appendChild(tabs);
    panels.forEach(function (p) { body.appendChild(p); });
  }

  function saveShown(root, box) {
    var cfg = root.kiu, note = root.querySelector('.murvc-kiu-show-note');
    var want = box.checked;
    var say = function (t) { if (note) { note.textContent = t; } };
    if (!cfg.nonce) {
      box.checked = !want;
      say('Your login has timed out. Reload the page to change this.');
      return;
    }
    box.disabled = true;
    say('Saving...');
    call(cfg, 'POST', cfg.settings, { show: want }).then(function (r) {
      box.disabled = false;
      if (nonceExpired(r)) {
        forgetNonce();
        box.checked = !want;
        say('Your login has timed out. Reload the page to change this.');
        return;
      }
      if (r.status !== 200 || !r.data.ok) {
        box.checked = !want;
        say(r.data.reason || 'That didn\'t save. Try again.');
        return;
      }
      cfg.shown = !!r.data.shown;
      box.checked = cfg.shown;
      say(cfg.shown ? 'Saved. You\'re on the leaderboard.' : 'Saved. You\'re hidden from the leaderboard.');
      roots.forEach(function (other) { loadBoard(other); });
    });
  }

  // ------------------------------------------------------------------ the ball's picture

  var BALL = { text: '', direct: null, cache: null, size: 0, want: 0, loading: 0, url: '' };

  function loadBall(url) {
    if (BALL.url) { return; }
    BALL.url = url;
    var direct = function () {
      var img = new Image();
      img.onload = function () { BALL.direct = img; BALL.size = 0; ensureBall(0); };
      img.src = url;
    };
    if (!window.fetch) { direct(); return; }
    window.fetch(url, { credentials: 'same-origin' }).then(function (r) {
      return r.ok ? r.text() : '';
    }).then(function (t) {
      if (/^\s*<svg[\s>]/.test(t || '')) {
        BALL.text = t;
        ensureBall(0);
      } else {
        direct();
      }
    }, direct);
  }

  /** Make the ball's picture px device pixels wide (from the SVG at that size, so it stays sharp). */
  function ensureBall(px) {
    if (px) { BALL.want = px; }
    var size = BALL.want;
    if (!size || size === BALL.size || BALL.loading === size) { return; }
    if (BALL.text) {
      BALL.loading = size;
      var svg = BALL.text.replace(/^(\s*<svg\b[^>]*?)\swidth="100"\sheight="100"/, '$1 width="' + size + '" height="' + size + '"');
      var img = new Image();
      img.onload = function () {
        paintBall(img, size);
        if (BALL.loading === size) { BALL.loading = 0; }
        if (BALL.want !== BALL.size) { ensureBall(0); }
      };
      img.onerror = function () { if (BALL.loading === size) { BALL.loading = 0; } };
      img.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg);
    } else if (BALL.direct) {
      paintBall(BALL.direct, size);
    }
  }

  function paintBall(img, size) {
    var c = document.createElement('canvas');
    c.width = size;
    c.height = size;
    var cx = c.getContext('2d');
    if (!cx) { return; }
    cx.drawImage(img, 0, 0, size, size);
    BALL.cache = c;
    BALL.size = size;
  }

  // ------------------------------------------------------------------ the game layer

  function build() {
    var g = {
      open: false, root: null, cfg: null, opener: null,
      W: 0, H: 0, dpr: 1,
      x: 0, y: 0, px: 0, py: 0, vx: 0, vy: 0, angle: 0, pa: 0, spin: 0,
      base: 30, r: 30, level: 0, g0: 1, g: 1, v0: 1, vside: 1,
      state: 'ready', resumeTo: 'playing', score: 0, touches: [], t0: 0, lastTouch: 0,
      raf: 0, last: null, acc: 0, gameId: 0, run: null,
      drawX: 0, drawY: 0, trail: [], keyLock: 0, keyMiss: 0, pendingFinish: null, closeOnBall: false
    };
    var layer = el('div', 'murvc-kiu-layer');
    layer.setAttribute('role', 'dialog');
    layer.setAttribute('aria-modal', 'true');
    layer.setAttribute('aria-label', 'Keep it up');
    layer.setAttribute('aria-describedby', 'murvc-kiu-help');
    layer.tabIndex = -1;
    var help = el('p', 'murvc-kiu-sr', 'Tap or click the ball to keep it up. Space or Enter hits it while it is falling low. Escape closes the game.');
    help.id = 'murvc-kiu-help';
    var canvas = el('canvas');
    canvas.setAttribute('aria-hidden', 'true');
    var hud = el('div', 'murvc-kiu-hud', '0');
    hud.setAttribute('aria-hidden', 'true');
    var tip = el('div', 'murvc-kiu-tip', 'Tap or click the ball');
    tip.setAttribute('aria-hidden', 'true');
    var live = el('p', 'murvc-kiu-sr');
    live.setAttribute('aria-live', 'polite');
    var close = el('button', 'murvc-kiu-close', '\u00d7');
    close.type = 'button';
    close.setAttribute('aria-label', 'Close the game');

    var paused = el('div', 'murvc-kiu-panel');
    paused.setAttribute('role', 'group');
    paused.setAttribute('aria-label', 'Paused');
    paused.appendChild(el('h2', '', 'Paused'));
    paused.appendChild(el('p', 'murvc-kiu-note', 'The ball waits while you\'re away.'));
    var carry = el('button', 'murvc-kiu-again', 'Carry on');
    carry.type = 'button';
    var pb = el('div', 'murvc-kiu-buttons');
    pb.appendChild(carry);
    paused.appendChild(pb);
    paused.hidden = true;

    var over = el('div', 'murvc-kiu-panel');
    over.setAttribute('role', 'group');
    over.setAttribute('aria-labelledby', 'murvc-kiu-over-title');
    var title = el('h2', '', 'Game over');
    title.id = 'murvc-kiu-over-title';
    // Focus lands here at game over, never on Play again, so a late Space or Enter can't start the next game.
    title.tabIndex = -1;
    var big = el('p', 'murvc-kiu-big', '0');
    var fresh = el('p', 'murvc-kiu-new', 'New best!');
    var bestLine = el('p', 'murvc-kiu-note');
    var savedLine = el('p', 'murvc-kiu-note');
    var placeLine = el('p', 'murvc-kiu-note');
    var hiddenLine = el('p', 'murvc-kiu-note', 'You\'re hidden from the leaderboard.');
    var loginLine = el('p', 'murvc-kiu-note');
    var loginLink = el('a', '', 'Log in to get on the leaderboard');
    loginLine.appendChild(loginLink);
    var again = el('button', 'murvc-kiu-again', 'Play again');
    again.type = 'button';
    var quit = el('button', 'murvc-kiu-quit', 'Close');
    quit.type = 'button';
    var ob = el('div', 'murvc-kiu-buttons');
    ob.appendChild(again);
    ob.appendChild(quit);
    [title, big, fresh, bestLine, savedLine, placeLine, hiddenLine, loginLine, ob].forEach(function (n) { over.appendChild(n); });
    over.hidden = true;

    [help, canvas, hud, tip, live, close, paused, over].forEach(function (n) { layer.appendChild(n); });

    g.layer = layer;
    g.canvas = canvas;
    g.ctx = canvas.getContext('2d');
    g.hud = hud;
    g.tip = tip;
    g.live = live;
    g.close = close;
    g.paused = paused;
    g.carry = carry;
    g.over = over;
    g.o = { title: title, big: big, fresh: fresh, best: bestLine, saved: savedLine, place: placeLine, hidden: hiddenLine, login: loginLine, link: loginLink, again: again, quit: quit };

    var down = function (e, kind) {
      if (e.cancelable) { e.preventDefault(); }
      hitAt(e.clientX, e.clientY, kind);
    };
    if (window.PointerEvent) {
      canvas.addEventListener('pointerdown', function (e) {
        // Every finger counts (a second thumb, a resting palm): the cooldown stops one hit counting twice. A mouse: the main button only.
        if (e.pointerType === 'mouse' && e.button !== 0) { return; }
        down(e, e.pointerType === 'touch' ? 'touch' : 'mouse');
      });
    } else {
      canvas.addEventListener('touchstart', function (e) {
        if (e.changedTouches && e.changedTouches[0]) {
          if (e.cancelable) { e.preventDefault(); }
          hitAt(e.changedTouches[0].clientX, e.changedTouches[0].clientY, 'touch');
        }
      }, { passive: false });
      canvas.addEventListener('mousedown', function (e) { down(e, 'mouse'); });
    }
    canvas.addEventListener('contextmenu', function (e) { e.preventDefault(); });
    layer.addEventListener('touchmove', function (e) {
      if (!over.contains(e.target) && e.cancelable) { e.preventDefault(); }
    }, { passive: false });
    layer.addEventListener('keydown', onKey);
    if (window.PointerEvent) {
      // The close button sits over the court: a tap there that is on the ball is a hit, and the click after it doesn't close.
      close.addEventListener('pointerdown', function (e) {
        g.closeOnBall = false;
        if (e.pointerType === 'mouse' && e.button !== 0) { return; }
        if (hitAt(e.clientX, e.clientY, e.pointerType === 'touch' ? 'touch' : 'mouse')) {
          g.closeOnBall = true;
          if (e.cancelable) { e.preventDefault(); }
        }
      });
    }
    close.addEventListener('click', function (e) {
      // A click from the keyboard (detail 0) always closes. A swallowed click hands focus back to the court, so the next Space or Enter is a hit, not a close.
      if (g.closeOnBall && e.detail !== 0) {
        g.closeOnBall = false;
        if (document.activeElement === close) { focusLayer(); }
        return;
      }
      g.closeOnBall = false;
      closeGame();
    });
    quit.addEventListener('click', closeGame);
    again.addEventListener('click', function () { reset(); focusLayer(); });
    carry.addEventListener('click', function () { resume(); focusLayer(); });
    window.addEventListener('resize', function () { if (G && G.open) { fitLayer(); size(); } });
    window.addEventListener('orientationchange', function () { if (G && G.open) { setTimeout(function () { fitLayer(); size(); }, 120); } });
    if (window.visualViewport) {
      // A pinch-zoomed page: keep the court on the part of the page the player can see.
      var refit = function () { if (G && G.open) { fitLayer(); size(); } };
      window.visualViewport.addEventListener('resize', refit);
      window.visualViewport.addEventListener('scroll', refit);
    }
    document.addEventListener('visibilitychange', function () {
      if (G && G.open && document.hidden) { pause(); }
    });
    return g;
  }

  function focusOn(node) {
    try { node.focus({ preventScroll: true }); } catch (e) { node.focus(); }
  }

  function focusLayer() {
    focusOn(G.layer);
  }

  function openGame(root) {
    if (!G) { G = build(); }
    if (G.open) { return; }
    G.root = root;
    G.cfg = root.kiu;
    // Safari doesn't focus a clicked button, so the page itself is active then: Close falls back to this card's Play.
    var active = document.activeElement;
    G.opener = active && active !== document.body && active !== document.documentElement ? active : null;
    loadBall(G.cfg.ball);
    document.documentElement.classList.add('murvc-kiu-open');
    document.body.appendChild(G.layer);
    G.open = true;
    G.W = 0;
    G.H = 0;
    fitLayer();
    size();
    reset();
    focusLayer();
  }

  /**
   * The layer covers the window; on a pinch-zoomed phone it covers the part
   * of the page that is on the screen (the visual viewport), so the ball and
   * the close button are never off it.
   */
  function fitLayer() {
    var vv = window.visualViewport, s = G.layer.style;
    if (zoom() > 1) {
      s.left = vv.offsetLeft + 'px';
      s.top = vv.offsetTop + 'px';
      s.width = vv.width + 'px';
      s.height = vv.height + 'px';
      s.right = 'auto';
      s.bottom = 'auto';
    } else {
      s.left = s.top = s.width = s.height = s.right = s.bottom = '';
    }
  }

  /**
   * The pinch zoom fitLayer() fits the layer to (1 when it doesn't). The
   * ball's size limits and a finger's grace are screen pixels, so they are
   * divided by it: the ball keeps the same share of the screen zoomed or not.
   */
  function zoom() {
    var vv = window.visualViewport;
    return vv && vv.scale > 1.01 ? vv.scale : 1;
  }

  /** Device pixels per CSS pixel on the screen (a pinch zoom included), at most 3. */
  function pixelRatio() {
    var vv = window.visualViewport, scale = vv && vv.scale > 1 ? vv.scale : 1;
    return Math.max(1, Math.min(3, (window.devicePixelRatio || 1) * scale));
  }

  function closeGame() {
    if (!G || !G.open) { return; }
    G.open = false;
    G.gameId++;
    if (G.raf) { cancelAnimationFrame(G.raf); G.raf = 0; }
    if (G.layer.parentNode) { G.layer.parentNode.removeChild(G.layer); }
    document.documentElement.classList.remove('murvc-kiu-open');
    roots.forEach(function (r) { showBest(r); loadBoard(r); });
    var back = G.opener && document.body.contains(G.opener) ? G.opener : (G.root ? G.root.querySelector('.murvc-kiu-play') : null);
    if (back && back.focus) { back.focus(); }
  }

  /** Fit the court to the window: canvas pixels, the ball's size and the forces all follow the window. */
  function size() {
    var W = Math.max(1, G.layer.clientWidth || window.innerWidth), H = Math.max(1, G.layer.clientHeight || window.innerHeight);
    var dpr = pixelRatio();
    if (G.W && G.H && (W !== G.W || H !== G.H)) {
      var sx = W / G.W, sy = H / G.H;
      G.x *= sx; G.px *= sx; G.vx *= sx;
      G.y *= sy; G.py *= sy; G.vy *= sy;
      G.trail = [];
    }
    G.W = W;
    G.H = H;
    G.dpr = dpr;
    G.canvas.width = Math.round(W * dpr);
    G.canvas.height = Math.round(H * dpr);
    var z = zoom();
    G.base = Math.max(SIZE_LO / z, Math.min(SIZE_HI / z, SIZE_SHARE * Math.min(W, H))) / 2;
    G.g0 = 2 * RISE * H / (APEX * APEX);
    G.v0 = G.g0 * APEX;
    G.vside = SIDE * Math.min(W, H);
    applyLevel();
    if (G.state === 'ready') { rest(); }
    G.x = Math.max(G.r, Math.min(W - G.r, G.x));
    draw(1);
  }

  function applyLevel() {
    G.g = G.g0 * Math.min(GRAVITY_MAX, Math.pow(GRAVITY_STEP, G.level));
    G.r = G.base * Math.max(SIZE_MIN, Math.pow(SIZE_STEP, G.level));
    ensureBall(Math.ceil(2 * G.r * G.dpr));
  }

  function rest() {
    G.x = G.px = G.W / 2;
    G.y = G.py = G.H * REST;
    G.vx = G.vy = 0;
  }

  function reset() {
    G.gameId++;
    G.state = 'ready';
    G.score = 0;
    G.touches = [];
    G.lastTouch = 0;
    G.level = 0;
    G.angle = G.pa = 0;
    G.spin = 0;
    G.run = null;
    G.trail = [];
    G.keyLock = 0;
    G.keyMiss = 0;
    applyLevel();
    rest();
    G.hud.textContent = '0';
    G.hud.hidden = false;
    G.live.textContent = '';
    G.tip.hidden = false;
    G.over.hidden = true;
    G.paused.hidden = true;
    G.close.hidden = false;
    loop();
  }

  function loop() {
    if (G.raf) { cancelAnimationFrame(G.raf); }
    G.last = null;
    G.acc = 0;
    G.raf = requestAnimationFrame(frame);
  }

  function frame(ts) {
    G.raf = 0;
    if (!G.open) { return; }
    if (pixelRatio() !== G.dpr) { size(); }
    if (G.last === null) { G.last = ts; }
    var dt = Math.min(MAX_FRAME, Math.max(0, (ts - G.last) / 1000));
    G.last = ts;
    if (G.state === 'playing') {
      G.acc += dt;
      while (G.acc >= STEP && G.state === 'playing') {
        G.px = G.x; G.py = G.y; G.pa = G.angle;
        step(STEP);
        G.acc -= STEP;
      }
    } else {
      G.px = G.x; G.py = G.y; G.pa = G.angle;
      G.acc = 0;
    }
    draw(G.state === 'playing' ? G.acc / STEP : 1);
    if (G.state === 'playing' || G.state === 'ready') {
      // Where the ball was drawn, and when: a tap is judged against these too (see hitAt()).
      G.trail.push({ t: clock(), x: G.drawX, y: G.drawY });
      if (G.trail.length > TRAIL) { G.trail.shift(); }
      G.raf = requestAnimationFrame(frame);
    }
  }

  function step(dt) {
    G.vy += G.g * dt;
    G.vx *= Math.max(0, 1 - DRAG * dt);
    G.x += G.vx * dt;
    G.y += G.vy * dt;
    G.angle += G.spin * dt;
    if (G.x - G.r < 0) {
      G.x = G.r;
      if (G.vx < 0) { G.vx = -G.vx * WALL; G.spin = -G.spin * 0.6; }
    } else if (G.x + G.r > G.W) {
      G.x = G.W - G.r;
      if (G.vx > 0) { G.vx = -G.vx * WALL; G.spin = -G.spin * 0.6; }
    }
    if (G.y + G.r >= G.H) {
      G.y = G.H - G.r;
      gameOver();
    }
  }

  function draw(alpha) {
    var c = G.ctx;
    if (!c) { return; }
    var W = G.W, H = G.H, r = G.r;
    var x = G.px + (G.x - G.px) * alpha, y = G.py + (G.y - G.py) * alpha, a = G.pa + (G.angle - G.pa) * alpha;
    G.drawX = x;
    G.drawY = y;
    c.setTransform(G.dpr, 0, 0, G.dpr, 0, 0);
    c.clearRect(0, 0, W, H);
    // The floor.
    c.fillStyle = '#c8102e';
    c.fillRect(0, H - 6, W, 6);
    // The ball's shadow on the floor, smaller and fainter the higher it is.
    var s = Math.max(0.25, 1 - Math.max(0, H - (y + r)) / H);
    c.fillStyle = 'rgba(15,42,82,' + (0.2 * s).toFixed(3) + ')';
    c.beginPath();
    if (c.ellipse) {
      c.ellipse(x, H - 9, r * s, r * 0.2 * s, 0, 0, Math.PI * 2);
    } else {
      c.arc(x, H - 9, r * 0.3 * s, 0, Math.PI * 2);
    }
    c.fill();
    if (y + r < 0) {
      // Above the top of the window: a marker where it will come down.
      c.fillStyle = '#0f2a52';
      c.beginPath();
      c.moveTo(x, 4);
      c.lineTo(x - 9, 18);
      c.lineTo(x + 9, 18);
      c.closePath();
      c.fill();
      return;
    }
    c.save();
    c.translate(x, y);
    c.rotate(a);
    if (BALL.cache) {
      c.drawImage(BALL.cache, -r, -r, 2 * r, 2 * r);
    } else {
      c.fillStyle = '#fdfdfb';
      c.strokeStyle = '#1b2f55';
      c.lineWidth = Math.max(1.5, r * 0.06);
      c.beginPath();
      c.arc(0, 0, r * 0.94, 0, Math.PI * 2);
      c.fill();
      c.stroke();
      c.lineWidth = r * 0.22;
      c.strokeStyle = '#c8102e';
      c.beginPath();
      c.arc(0, 0, r * 0.55, -0.4, 1.2);
      c.stroke();
      c.strokeStyle = '#1f4e9c';
      c.beginPath();
      c.arc(0, 0, r * 0.55, 1.9, 3.5);
      c.stroke();
    }
    c.restore();
  }

  // ------------------------------------------------------------------ touches

  /**
   * A tap or click at (cx, cy). What the player sees is a frame or more
   * behind the physics, and a finger adds its own delay, so the tap counts
   * when it is on the ball where it is now or where it was drawn in the last
   * LAG_MS; the off-centre is measured from the nearest of those. Returns
   * true when the tap was on the ball (a touch, or a second tap the
   * cooldown folds into the last one), so the close button can tell.
   */
  function hitAt(cx, cy, kind) {
    if (!G || !G.open || (G.state !== 'ready' && G.state !== 'playing')) { return false; }
    var rect = G.canvas.getBoundingClientRect();
    var tx = cx - rect.left, ty = cy - rect.top;
    var reach = G.r + (kind === 'touch' ? GRACE_TOUCH / zoom() : 0);
    var now = clock();
    var spots = [{ x: G.x, y: G.y }];
    for (var i = 0; i < G.trail.length; i++) {
      if (now - G.trail[i].t <= LAG_MS) { spots.push(G.trail[i]); }
    }
    var near = null, nearD = Infinity;
    for (var j = 0; j < spots.length; j++) {
      var ddx = tx - spots[j].x, ddy = ty - spots[j].y, d = ddx * ddx + ddy * ddy;
      if (d < nearD) { nearD = d; near = spots[j]; }
    }
    if (!near || nearD > reach * reach) { return false; }
    touch(Math.max(-1, Math.min(1, (tx - near.x) / G.r)));
    return true;
  }

  /**
   * Space or Enter: a dead-centre hit, only while the ball waits or is
   * falling in the lower part of the window. A press at any other moment
   * does nothing, and the second such press since the last hit (or any
   * press while locked) locks the keys for KEY_LOCK_MS, so one early press
   * is forgiven but pressing over and over loses the ball: timing matters.
   */
  function keyHit() {
    if (G.state === 'ready') {
      touch(0);
      return;
    }
    if (G.state !== 'playing') { return; }
    var now = clock();
    if (now < G.keyLock || !(G.vy > 0 && G.y > G.H * KEY_LOW)) {
      if (G.keyMiss++ >= 1) { G.keyLock = now + KEY_LOCK_MS; }
      return;
    }
    touch(0);
  }

  function touch(off) {
    var now = clock();
    if (G.lastTouch && now - G.lastTouch < COOLDOWN_MS) { return; }
    if (G.state === 'ready') {
      G.state = 'playing';
      G.tip.hidden = true;
      G.t0 = now;
      startRun();
      loop();
    }
    G.lastTouch = now;
    G.keyMiss = 0;
    var side = Math.abs(off) <= DEAD ? 0 : (Math.abs(off) - DEAD) / (1 - DEAD);
    G.vx = (off < 0 ? 1 : -1) * side * G.vside;
    G.vy = -G.v0;
    G.spin = G.vx / G.r * SPIN;
    G.score++;
    G.touches.push(Math.round(now - G.t0));
    G.hud.textContent = String(G.score);
    var level = Math.floor(G.score / 10);
    if (level !== G.level) {
      G.level = level;
      applyLevel();
    }
    milestone(G.score);
  }

  function milestone(n) {
    var word = WORDS[n] || (n > 200 && n % 100 === 0 ? num(n) + '!' : '');
    if (!word) { return; }
    var w = el('div', 'murvc-kiu-word', word);
    w.setAttribute('aria-hidden', 'true');
    w.style.left = '50%';
    w.style.top = '24%';
    G.layer.appendChild(w);
    setTimeout(function () { if (w.parentNode) { w.parentNode.removeChild(w); } }, 1300);
    G.live.textContent = word + ' ' + num(n) + '.';
  }

  function onKey(e) {
    if (!G || !G.open) { return; }
    var k = e.key;
    if (k === 'Escape' || k === 'Esc') {
      e.preventDefault();
      closeGame();
      return;
    }
    if (k === 'Tab') {
      trapTab(e);
      return;
    }
    var onButton = e.target && e.target.tagName && /^(BUTTON|A)$/.test(e.target.tagName);
    if ((k === ' ' || k === 'Spacebar' || k === 'Enter') && !onButton) {
      e.preventDefault();
      if (e.repeat) { return; }
      if (G.state === 'paused') { resume(); } else { keyHit(); }
    }
  }

  function trapTab(e) {
    var list = Array.prototype.filter.call(G.layer.querySelectorAll('button, a[href]'), function (n) {
      return !n.disabled && n.offsetParent !== null;
    });
    if (!list.length) { e.preventDefault(); focusLayer(); return; }
    var i = list.indexOf(document.activeElement);
    e.preventDefault();
    if (e.shiftKey) {
      list[i <= 0 ? list.length - 1 : i - 1].focus();
    } else {
      list[i < 0 || i >= list.length - 1 ? 0 : i + 1].focus();
    }
  }

  function pause() {
    if (G.state !== 'playing') { return; }
    G.state = 'paused';
    if (G.raf) { cancelAnimationFrame(G.raf); G.raf = 0; }
    G.paused.hidden = false;
  }

  function resume() {
    if (G.state !== 'paused') { return; }
    G.paused.hidden = true;
    G.state = 'playing';
    loop();
  }

  // ------------------------------------------------------------------ saving

  /** Resolves when promise settles or after ms, whichever is first. */
  function within(promise, ms) {
    return new Promise(function (done) {
      var timer = setTimeout(done, ms);
      promise.then(function () { clearTimeout(timer); done(); }, function () { clearTimeout(timer); done(); });
    });
  }

  /**
   * A member's run starts on the site at the first touch; t0 is when it was
   * asked for. A new start replaces the pending run on the site, so it waits
   * (up to START_WAIT_MS, which the site's clock check allows for) until the
   * last game's save has landed: Play again can't make that score be lost.
   */
  function startRun() {
    var cfg = G.cfg;
    var run = { token: '', error: '', promise: null };
    G.run = run;
    if (!cfg.member) { run.promise = Promise.resolve(); return; }
    if (!cfg.nonce) {
      run.error = 'Your login has timed out. Reload the page to save scores.';
      run.promise = Promise.resolve();
      return;
    }
    var before = G.pendingFinish ? within(G.pendingFinish, START_WAIT_MS) : Promise.resolve();
    run.promise = before.then(function () {
      if (!cfg.nonce) {
        return { status: 0, data: { reason: 'Your login has timed out. Reload the page to save scores.' } };
      }
      return call(cfg, 'POST', cfg.start, {});
    }).then(function (r) {
      if (nonceExpired(r)) {
        forgetNonce();
        run.error = 'Your login has timed out. Reload the page to save scores.';
      } else if (r.status === 200 && r.data.ok && r.data.token) {
        run.token = r.data.token;
      } else {
        run.error = r.data.reason || 'The site couldn\'t start this game, so it won\'t be saved.';
      }
    });
  }

  function gameOver() {
    G.state = 'over';
    var id = G.gameId, cfg = G.cfg, score = G.score, touches = G.touches.slice(), run = G.run, o = G.o;
    G.over.hidden = false;
    G.close.hidden = true;
    // On a short window the score at the top peeks out behind the panel, which has the score too.
    G.hud.hidden = true;
    o.big.textContent = num(score);
    o.fresh.hidden = true;
    o.saved.textContent = '';
    o.place.textContent = '';
    o.hidden.hidden = true;
    o.login.hidden = true;
    var over = 'Game over. ' + num(score) + (score === 1 ? ' touch.' : ' touches.');
    // What the live region reads. Moving focus to the heading cuts off a message spoken before it, so nothing
    // is said until focus has moved, and every message starts with the score.
    var focused = false, said = over;
    var say = function (text) {
      said = text;
      if (focused) { G.live.textContent = text; }
    };
    if (!cfg.member) {
      var before = readBest();
      if (score > before) { writeBest(score); }
      o.fresh.hidden = !(score > before);
      o.best.textContent = 'Your best on this device: ' + num(Math.max(score, before));
      o.best.hidden = false;
      o.link.href = cfg.login || '#';
      o.login.hidden = !cfg.login;
      say(over + (score > before ? ' New best!' : ''));
    } else {
      // The saved best only: a score joins it when the site says it was saved. No board (a timed-out login): no line.
      memberBest(o.best, cfg.viewer ? cfg.viewer.best_all || 0 : 0);
      o.saved.textContent = 'Saving...';
      var pending = finish(cfg, run, score, touches);
      G.pendingFinish = pending;
      pending.then(function (res) {
        if (G.pendingFinish === pending) { G.pendingFinish = null; }
        var st = res.standing;
        if (st && cfg.viewer) {
          cfg.viewer.best_all = st.best_all;
          cfg.viewer.best_year = st.best_year;
        }
        if (!G.open) {
          // Closed while it was saving: the boards drawn at Close may be from before it, so draw them again.
          roots.forEach(function (r) { showBest(r); loadBoard(r); });
          return;
        }
        if (G.gameId !== id) { return; }
        o.saved.textContent = res.line || '';
        var heard = over + (res.line ? ' ' + res.line : '');
        if (st) {
          memberBest(o.best, st.best_all || 0);
          o.fresh.hidden = !st.new_best;
          if (st.new_best) { heard += ' New best!'; }
          if (st.place_year > 0) {
            o.place.textContent = 'This year: ' + ord(st.place_year) + '. All time: ' + ord(st.place_all) + '.';
            heard += ' ' + o.place.textContent;
          }
          o.hidden.hidden = st.shown !== false;
        } else {
          memberBest(o.best, cfg.viewer ? cfg.viewer.best_all || 0 : 0);
        }
        // Screen readers hear the score, whether it was saved, a new best and the places.
        say(heard);
      });
    }
    setTimeout(function () {
      if (G.open && G.gameId === id) {
        focusOn(o.title);
        focused = true;
        G.live.textContent = said;
      }
    }, 50);
  }

  /** "Your best: N" on the game over panel, or no line when there is no saved best to show. */
  function memberBest(node, n) {
    node.textContent = n > 0 ? 'Your best: ' + num(n) : '';
    node.hidden = !(n > 0);
  }

  /** Send a member's finished run: resolves {line, standing}. */
  function finish(cfg, run, score, touches) {
    if (!run || !run.promise) {
      return Promise.resolve({ line: 'Not saved. The game didn\'t start on the site.' });
    }
    return run.promise.then(function () {
      if (!run.token) {
        return { line: 'Not saved. ' + (run.error || 'The game didn\'t start on the site.') };
      }
      return call(cfg, 'POST', cfg.finish, { token: run.token, score: score, touches: touches }).then(function (r) {
        if (nonceExpired(r)) {
          forgetNonce();
          return { line: 'Not saved. Your login has timed out. Reload the page to save scores.' };
        }
        if (r.status !== 200 || !r.data.ok) {
          return { line: 'Not saved. ' + (r.data.reason || 'The site didn\'t answer. Try again later.') };
        }
        return { line: r.data.line || '', standing: r.data };
      });
    });
  }

  // ------------------------------------------------------------------ start

  function init() {
    var list = document.querySelectorAll('.murvc-kiu[data-config]');
    for (var i = 0; i < list.length; i++) { setup(list[i], i); }
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
