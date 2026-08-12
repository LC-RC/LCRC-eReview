/**
 * CPA Battle — multiplayer client (AJAX polling, nicknames, sync reveal).
 */
(function () {
  'use strict';

  if (!window.PG_BATTLE) return;

  var cfg = window.PG_BATTLE;
  var MUSIC_KEY = 'ereview_pg_music';
  var SFX_KEY = 'ereview_pg_sfx';
  var MUTE_KEY = 'ereview_pg_mute';
  var WANT_MUSIC_KEY = 'ereview_pg_want_music';
  var POLL_MS = 1500;

  function post(action, data) {
    var body = Object.assign({ action: action, csrf_token: cfg.csrf }, data || {});
    return fetch(cfg.apiUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify(body),
    }).then(function (r) {
      return r.json().catch(function () {
        return { ok: false, error: 'Invalid response' };
      });
    });
  }

  /* ---------- Audio (thinking MP3 + SFX) ---------- */
  var BattleSound = (function () {
    var ctx = null;
    var unlocked = false;
    var musicOn = true;
    var sfxOn = true;
    var muted = false;
    var musicEl = null;
    var musicUrl = cfg.musicUrl || 'assets/audio/thinking-time.mp3';
    var lastPlayed = {};

    try {
      if (localStorage.getItem(MUSIC_KEY) === '0') musicOn = false;
      if (localStorage.getItem(SFX_KEY) === '0') sfxOn = false;
      if (localStorage.getItem(MUTE_KEY) === '1') muted = true;
    } catch (e) {}

    function ensureCtx() {
      if (ctx) return ctx;
      var Ctx = window.AudioContext || window.webkitAudioContext;
      if (!Ctx) return null;
      ctx = new Ctx();
      return ctx;
    }

    function unlock() {
      unlocked = true;
      var c = ensureCtx();
      if (c && c.state === 'suspended') c.resume().catch(function () {});
      syncMusic();
    }

    function ensureMusic() {
      if (!musicEl) {
        musicEl = new Audio(musicUrl);
        musicEl.loop = true;
        musicEl.preload = 'auto';
        musicEl.volume = 0.2;
      }
      return musicEl;
    }

    function syncMusic() {
      if (!unlocked || muted || !musicOn) {
        if (musicEl) {
          try { musicEl.pause(); } catch (e1) {}
        }
        return;
      }
      var el = ensureMusic();
      var p = el.play();
      if (p && p.catch) p.catch(function () {});
    }

    function tone(freq, dur, type, vol, when) {
      var c = ensureCtx();
      if (!c) return;
      var t0 = c.currentTime + (when || 0);
      var osc = c.createOscillator();
      var g = c.createGain();
      osc.type = type || 'sine';
      osc.frequency.setValueAtTime(freq, t0);
      g.gain.setValueAtTime(0.0001, t0);
      g.gain.exponentialRampToValueAtTime(Math.max(0.0002, vol || 0.04), t0 + 0.015);
      g.gain.exponentialRampToValueAtTime(0.0001, t0 + dur);
      osc.connect(g);
      g.connect(c.destination);
      osc.start(t0);
      osc.stop(t0 + dur + 0.02);
    }

    function canSfx(name, gap) {
      if (muted || !sfxOn || !unlocked) return false;
      var now = Date.now();
      if (lastPlayed[name] && now - lastPlayed[name] < (gap || 90)) return false;
      lastPlayed[name] = now;
      return true;
    }

    function play(name) {
      if (!canSfx(name, name.indexOf('warn') === 0 ? 800 : 90)) return;
      try {
        switch (name) {
          case 'click':
            tone(700, 0.035, 'square', 0.018);
            break;
          case 'countdown':
            tone(440, 0.12, 'triangle', 0.04);
            break;
          case 'go':
            tone(523, 0.1, 'triangle', 0.04);
            tone(659, 0.14, 'triangle', 0.04, 0.08);
            break;
          case 'correct':
            tone(523, 0.09, 'sine', 0.04);
            tone(659, 0.11, 'sine', 0.04, 0.07);
            tone(784, 0.16, 'sine', 0.035, 0.16);
            break;
          case 'wrong':
            tone(220, 0.12, 'triangle', 0.03);
            tone(175, 0.14, 'triangle', 0.025, 0.07);
            break;
          case 'tick':
            tone(880, 0.04, 'square', 0.02);
            break;
          case 'expire':
            tone(200, 0.2, 'triangle', 0.04);
            break;
          case 'victory':
            tone(523, 0.12, 'triangle', 0.04);
            tone(659, 0.12, 'triangle', 0.04, 0.1);
            tone(784, 0.14, 'triangle', 0.04, 0.2);
            tone(1046, 0.26, 'triangle', 0.035, 0.34);
            break;
          default:
            break;
        }
      } catch (err) {}
    }

    function syncUi() {
      var m = document.getElementById('pg-music-toggle');
      var s = document.getElementById('pg-sfx-toggle');
      var u = document.getElementById('pg-mute-toggle');
      if (m) {
        m.classList.toggle('is-off', !musicOn || muted);
        m.textContent = musicOn && !muted ? '🎵 Music' : '🎵 Off';
      }
      if (s) {
        s.classList.toggle('is-off', !sfxOn || muted);
        s.textContent = sfxOn && !muted ? '🔔 SFX' : '🔔 Off';
      }
      if (u) {
        u.classList.toggle('is-off', muted);
        u.textContent = muted ? '🔇' : '🔊';
      }
    }

    function bindUi() {
      var m = document.getElementById('pg-music-toggle');
      var s = document.getElementById('pg-sfx-toggle');
      var u = document.getElementById('pg-mute-toggle');
      if (m && !m._b) {
        m._b = true;
        m.addEventListener('click', function () {
          unlock();
          if (muted) {
            muted = false;
            try { localStorage.setItem(MUTE_KEY, '0'); } catch (e) {}
          }
          musicOn = !musicOn;
          try { localStorage.setItem(MUSIC_KEY, musicOn ? '1' : '0'); } catch (e2) {}
          syncMusic();
          syncUi();
        });
      }
      if (s && !s._b) {
        s._b = true;
        s.addEventListener('click', function () {
          unlock();
          if (muted) {
            muted = false;
            try { localStorage.setItem(MUTE_KEY, '0'); } catch (e) {}
          }
          sfxOn = !sfxOn;
          try { localStorage.setItem(SFX_KEY, sfxOn ? '1' : '0'); } catch (e2) {}
          syncUi();
          if (sfxOn) play('click');
        });
      }
      if (u && !u._b) {
        u._b = true;
        u.addEventListener('click', function () {
          unlock();
          muted = !muted;
          try { localStorage.setItem(MUTE_KEY, muted ? '1' : '0'); } catch (e) {}
          syncMusic();
          syncUi();
        });
      }
      syncUi();
    }

    return { unlock: unlock, play: play, syncMusic: syncMusic, bindUi: bindUi, stop: function () {
      if (musicEl) {
        try { musicEl.pause(); } catch (e) {}
      }
    } };
  })();

  function showErr(el, msg) {
    if (!el) return;
    el.textContent = msg || '';
    el.classList.toggle('hidden', !msg);
  }

  /* ---------- HUB ---------- */
  if (cfg.view === 'hub') {
    var nickPanel = document.getElementById('pg-battle-nick-panel');
    var main = document.getElementById('pg-battle-main');
    var nickInput = document.getElementById('pg-battle-nick');
    var nickErr = document.getElementById('pg-battle-nick-error');
    var nickDisplay = document.getElementById('pg-battle-nick-display');
    var createPanel = document.getElementById('pg-battle-create-panel');
    var joinPanel = document.getElementById('pg-battle-join-panel');
    var subjectsWrap = document.getElementById('pg-battle-subjects-wrap');
    var timeValue = document.getElementById('pg-battle-time-value');
    var timeUnit = document.getElementById('pg-battle-time-unit');
    var timePresets = document.getElementById('pg-battle-time-presets');

    var PRESETS = { seconds: [30, 60, 90, 120], minutes: [5, 10, 15, 30], hours: [1, 2] };

    function renderPresets() {
      if (!timePresets || !timeUnit) return;
      var unit = timeUnit.value;
      var list = PRESETS[unit] || PRESETS.minutes;
      var cur = timeValue ? parseInt(timeValue.value, 10) : 0;
      timePresets.innerHTML = '';
      list.forEach(function (v) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'pg-opt pg-time-preset' + (cur === v ? ' is-active' : '');
        b.textContent = unit === 'hours' ? v + ' hr' : unit === 'seconds' ? v + 's' : v + ' min';
        b.addEventListener('click', function () {
          if (timeValue) timeValue.value = String(v);
          renderPresets();
        });
        timePresets.appendChild(b);
      });
    }

    function setNickUi(nick) {
      cfg.nickname = nick;
      if (nickDisplay) nickDisplay.textContent = nick;
      if (nickPanel) nickPanel.hidden = !!nick;
      if (main) main.hidden = !nick;
    }

    document.getElementById('pg-battle-nick-continue') &&
      document.getElementById('pg-battle-nick-continue').addEventListener('click', function () {
        BattleSound.unlock();
        var nick = (nickInput && nickInput.value) || '';
        post('nick_set', { nickname: nick }).then(function (res) {
          if (!res || !res.ok) {
            showErr(nickErr, (res && res.error) || 'Invalid game name');
            return;
          }
          showErr(nickErr, '');
          setNickUi(res.nickname);
        });
      });

    document.getElementById('pg-battle-change-nick') &&
      document.getElementById('pg-battle-change-nick').addEventListener('click', function () {
        if (nickPanel) nickPanel.hidden = false;
        if (main) main.hidden = true;
      });

    document.getElementById('pg-battle-show-create') &&
      document.getElementById('pg-battle-show-create').addEventListener('click', function () {
        if (createPanel) createPanel.hidden = false;
        if (joinPanel) joinPanel.hidden = true;
      });
    document.getElementById('pg-battle-show-join') &&
      document.getElementById('pg-battle-show-join').addEventListener('click', function () {
        if (joinPanel) joinPanel.hidden = false;
        if (createPanel) createPanel.hidden = true;
      });

    document.querySelectorAll('input[name="battle_source"]').forEach(function (r) {
      r.addEventListener('change', function () {
        if (subjectsWrap) subjectsWrap.hidden = r.value !== 'subjects' || !r.checked
          ? (document.querySelector('input[name="battle_source"]:checked') || {}).value !== 'subjects'
          : false;
        var src = (document.querySelector('input[name="battle_source"]:checked') || {}).value;
        if (subjectsWrap) subjectsWrap.hidden = src !== 'subjects';
      });
    });

    if (timeUnit) timeUnit.addEventListener('change', renderPresets);
    renderPresets();

    document.getElementById('pg-battle-create-btn') &&
      document.getElementById('pg-battle-create-btn').addEventListener('click', function () {
        BattleSound.unlock();
        try { sessionStorage.setItem(WANT_MUSIC_KEY, '1'); } catch (e) {}
        var subjectIds = [];
        document.querySelectorAll('input[name="battle_subject"]:checked').forEach(function (c) {
          subjectIds.push(parseInt(c.value, 10));
        });
        var src = (document.querySelector('input[name="battle_source"]:checked') || {}).value || 'mixed';
        var q = (document.querySelector('input[name="battle_qcount"]:checked') || {}).value || 10;
        var bal = (document.querySelector('input[name="battle_balanced"]:checked') || {}).value || '0';
        var err = document.getElementById('pg-battle-create-error');
        post('create', {
          nickname: cfg.nickname || (nickInput && nickInput.value) || '',
          title: (document.getElementById('pg-battle-title') || {}).value || 'CPA Battle',
          selection_mode: src,
          subject_ids: subjectIds,
          question_count: parseInt(q, 10),
          time_value: parseInt((timeValue && timeValue.value) || '10', 10),
          time_unit: (timeUnit && timeUnit.value) || 'minutes',
          balanced: bal === '1' ? 1 : 0,
          speed_bonus: document.getElementById('pg-battle-speed') && document.getElementById('pg-battle-speed').checked ? 1 : 0,
          streak_bonus: document.getElementById('pg-battle-streak') && document.getElementById('pg-battle-streak').checked ? 1 : 0,
        }).then(function (res) {
          if (!res || !res.ok) {
            showErr(err, (res && res.error) || 'Could not create');
            return;
          }
          window.location.href = 'student_playground_battle_lobby?room=' + encodeURIComponent(res.room_code);
        });
      });

    document.getElementById('pg-battle-join-btn') &&
      document.getElementById('pg-battle-join-btn').addEventListener('click', function () {
        BattleSound.unlock();
        try { sessionStorage.setItem(WANT_MUSIC_KEY, '1'); } catch (e) {}
        var code = ((document.getElementById('pg-battle-join-code') || {}).value || '').trim();
        var nick = ((document.getElementById('pg-battle-join-nick') || {}).value || cfg.nickname || '').trim();
        var err = document.getElementById('pg-battle-join-error');
        post('join', { room_code: code, nickname: nick }).then(function (res) {
          if (!res || !res.ok) {
            if (res && res.finished && res.room_code) {
              window.location.href = 'student_playground_battle_result?room=' + encodeURIComponent(res.room_code);
              return;
            }
            showErr(err, (res && res.error) || 'Could not join');
            return;
          }
          window.location.href = 'student_playground_battle_lobby?room=' + encodeURIComponent(res.room_code);
        });
      });

    if (cfg.prefillRoom && joinPanel) {
      joinPanel.hidden = false;
    }
  }

  /* ---------- LOBBY ---------- */
  if (cfg.view === 'lobby') {
    var listEl = document.getElementById('pg-battle-player-list');
    var countEl = document.getElementById('pg-battle-player-count');
    var hintEl = document.getElementById('pg-battle-lobby-hint');
    var readyBtn = document.getElementById('pg-battle-ready-btn');
    var startBtn = document.getElementById('pg-battle-start-btn');
    var leaveBtn = document.getElementById('pg-battle-leave-btn');
    var cancelBtn = document.getElementById('pg-battle-cancel-btn');
    var errEl = document.getElementById('pg-battle-lobby-error');
    var amReady = false;
    var pollId = null;

    if (cfg.isHost && startBtn) startBtn.hidden = false;
    if (cfg.isHost && cancelBtn) cancelBtn.hidden = false;

    function renderPlayers(state) {
      var players = state.players || [];
      if (countEl) countEl.textContent = players.length + ' / ' + (state.max_players || 10);
      if (!listEl) return;
      listEl.innerHTML = '';
      players.forEach(function (p) {
        var li = document.createElement('li');
        li.className = 'pg-battle-player-row is-' + (p.status || 'joined');
        li.innerHTML =
          '<span class="av" data-av="' +
          (p.avatar_key || 'a1') +
          '"></span><span class="nick"></span><span class="st"></span>';
        li.querySelector('.nick').textContent = p.nickname || 'Player';
        li.querySelector('.st').textContent = (p.status || '').toUpperCase();
        if (cfg.isHost && p.nickname && p.nickname !== (state.me && state.me.nickname)) {
          var kick = document.createElement('button');
          kick.type = 'button';
          kick.className = 'pg-battle-kick';
          kick.textContent = 'Remove';
          kick.addEventListener('click', function () {
            post('kick', { room_code: cfg.roomCode, nickname: p.nickname }).then(poll);
          });
          li.appendChild(kick);
        }
        listEl.appendChild(li);
      });
      if (state.me) {
        amReady = state.me.status === 'ready';
        if (readyBtn) readyBtn.textContent = amReady ? 'Unready' : 'Ready';
      }
      var allReady =
        players.length >= (state.min_players || 2) &&
        players.every(function (p) {
          return p.status === 'ready';
        });
      if (hintEl) {
        hintEl.textContent = allReady
          ? 'All players ready — host can start.'
          : 'Waiting for players… (' + players.filter(function (p) { return p.status === 'ready'; }).length + ' ready)';
      }
      if (startBtn) startBtn.disabled = !allReady;
    }

    function poll() {
      return post('state', { room_code: cfg.roomCode }).then(function (res) {
        if (!res || !res.ok) {
          showErr(errEl, (res && res.error) || 'Lost connection');
          return;
        }
        showErr(errEl, '');
        if (res.status === 'cancelled') {
          window.location.href = 'student_playground_battle';
          return;
        }
        if (res.status === 'finished') {
          window.location.href = 'student_playground_battle_result?room=' + encodeURIComponent(cfg.roomCode);
          return;
        }
        if (res.status !== 'lobby') {
          window.location.href = 'student_playground_battle_play?room=' + encodeURIComponent(cfg.roomCode);
          return;
        }
        renderPlayers(res);
      });
    }

    if (readyBtn) {
      readyBtn.addEventListener('click', function () {
        BattleSound.unlock();
        BattleSound.play('click');
        post(amReady ? 'unready' : 'ready', { room_code: cfg.roomCode }).then(poll);
      });
    }
    if (startBtn) {
      startBtn.addEventListener('click', function () {
        BattleSound.unlock();
        post('start', { room_code: cfg.roomCode }).then(function (res) {
          if (!res || !res.ok) {
            showErr(errEl, (res && res.error) || 'Could not start');
            return;
          }
          window.location.href = 'student_playground_battle_play?room=' + encodeURIComponent(cfg.roomCode);
        });
      });
    }
    if (leaveBtn) {
      leaveBtn.addEventListener('click', function () {
        post('leave', { room_code: cfg.roomCode }).then(function () {
          window.location.href = 'student_playground_battle';
        });
      });
    }
    if (cancelBtn) {
      cancelBtn.addEventListener('click', function () {
        if (!confirm('Cancel this battle for everyone?')) return;
        post('cancel', { room_code: cfg.roomCode }).then(function () {
          window.location.href = 'student_playground_battle';
        });
      });
    }

    document.getElementById('pg-copy-code') &&
      document.getElementById('pg-copy-code').addEventListener('click', function () {
        var code = cfg.roomCode || '';
        if (navigator.clipboard) navigator.clipboard.writeText(code);
        else {
          var t = document.createElement('textarea');
          t.value = code;
          document.body.appendChild(t);
          t.select();
          document.execCommand('copy');
          document.body.removeChild(t);
        }
      });
    document.getElementById('pg-copy-link') &&
      document.getElementById('pg-copy-link').addEventListener('click', function () {
        var url = cfg.inviteUrl || '';
        if (navigator.clipboard) navigator.clipboard.writeText(url);
      });

    poll();
    pollId = setInterval(poll, POLL_MS);
  }

  /* ---------- PLAY ---------- */
  if (cfg.view === 'play') {
    BattleSound.bindUi();
    try {
      if (sessionStorage.getItem(WANT_MUSIC_KEY) === '1') BattleSound.unlock();
    } catch (e) {}
    document.addEventListener(
      'pointerdown',
      function once() {
        BattleSound.unlock();
        document.removeEventListener('pointerdown', once, true);
      },
      true
    );

    var elProgress = document.getElementById('pg-battle-progress');
    var elSubject = document.getElementById('pg-battle-subject');
    var elScore = document.getElementById('pg-battle-score');
    var elStreak = document.getElementById('pg-battle-streak');
    var elTimer = document.getElementById('pg-battle-timer');
    var elTimerWrap = document.getElementById('pg-battle-q-timer');
    var elTimerLabel = document.getElementById('pg-battle-timer-label');
    var elCountdown = document.getElementById('pg-battle-countdown');
    var elCountdownNum = document.getElementById('pg-battle-countdown-num');
    var elQuestion = document.getElementById('pg-battle-question');
    var elChoices = document.getElementById('pg-battle-choices');
    var elLocked = document.getElementById('pg-battle-locked');
    var elReveal = document.getElementById('pg-battle-reveal');
    var elRevealIcon = document.getElementById('pg-battle-reveal-icon');
    var elRevealTitle = document.getElementById('pg-battle-reveal-title');
    var elRevealSub = document.getElementById('pg-battle-reveal-sub');
    var elRevealMetrics = document.getElementById('pg-battle-reveal-metrics');
    var elRank = document.getElementById('pg-battle-rank-list');
    var elTotalLeft = document.getElementById('pg-battle-total-left');
    var submitting = false;
    var lastStatus = '';
    var lastOrdinal = 0;
    var lastCountdownSec = -1;
    var warnedTick = false;

    function fmt(sec) {
      sec = Math.max(0, sec | 0);
      var m = Math.floor(sec / 60);
      var s = sec % 60;
      return (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
    }

    function secondsUntil(iso) {
      if (!iso) return 0;
      var t = Date.parse(iso);
      if (isNaN(t)) return 0;
      return Math.max(0, Math.floor((t - Date.now()) / 1000));
    }

    function renderBoard(players) {
      if (!elRank) return;
      elRank.innerHTML = '';
      (players || []).forEach(function (p, i) {
        var li = document.createElement('li');
        li.innerHTML =
          '<span class="rank">' +
          (p.rank || i + 1) +
          '</span><span class="nick"></span><span class="pts"></span>';
        li.querySelector('.nick').textContent = p.nickname || '';
        li.querySelector('.pts').textContent = String(p.score || 0);
        elRank.appendChild(li);
      });
    }

    function renderQuestion(q) {
      if (!q) return;
      if (elSubject) elSubject.textContent = q.subject_name || 'CPA';
      if (elQuestion) elQuestion.innerHTML = q.question_text || '';
      if (!elChoices) return;
      elChoices.innerHTML = '';
      elChoices.hidden = false;
      (q.choices || []).forEach(function (c) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'pg-choice';
        btn.setAttribute('data-letter', c.letter || '');
        btn.innerHTML =
          '<span class="pg-choice-letter">' +
          (c.display || c.letter) +
          '</span><span class="pg-choice-text quiz-rich-text">' +
          (c.text || '') +
          '</span>';
        btn.addEventListener('click', function () {
          submitAnswer(c.letter, q.game_question_id);
        });
        elChoices.appendChild(btn);
      });
    }

    function submitAnswer(letter, gqId) {
      if (submitting || !letter || !gqId) return;
      submitting = true;
      BattleSound.play('click');
      if (elChoices) {
        elChoices.querySelectorAll('.pg-choice').forEach(function (b) {
          b.disabled = true;
          b.classList.toggle('is-selected', b.getAttribute('data-letter') === letter);
          if (b.getAttribute('data-letter') !== letter) b.classList.add('is-locked-out');
        });
      }
      post('answer', {
        room_code: cfg.roomCode,
        game_question_id: gqId,
        selected_answer: letter,
      }).then(function (res) {
        submitting = false;
        if (!res || !res.ok) {
          if (res && res.duplicate) {
            if (elLocked) elLocked.hidden = false;
            return;
          }
          if (elChoices) {
            elChoices.querySelectorAll('.pg-choice').forEach(function (b) {
              b.disabled = false;
              b.classList.remove('is-locked-out');
            });
          }
          return;
        }
        if (elLocked) elLocked.hidden = false;
      });
    }

    function applyState(res) {
      if (!res || !res.ok) return;
      if (res.status === 'lobby') {
        window.location.href = 'student_playground_battle_lobby?room=' + encodeURIComponent(cfg.roomCode);
        return;
      }
      if (res.status === 'finished' || res.status === 'cancelled') {
        BattleSound.stop();
        window.location.href = 'student_playground_battle_result?room=' + encodeURIComponent(cfg.roomCode);
        return;
      }

      if (res.me) {
        if (elScore) elScore.textContent = String(res.me.score || 0);
        if (elStreak) elStreak.textContent = String(res.me.current_streak || 0);
      }
      if (elProgress) {
        elProgress.textContent =
          'Q ' +
          (res.current_ordinal || '—') +
          ' / ' +
          (res.question_count || '—');
      }
      renderBoard(res.players);
      var totalLeft = secondsUntil(res.ends_at);
      if (elTotalLeft) elTotalLeft.textContent = 'Total time remaining: ' + fmt(totalLeft);

      var qLeft = secondsUntil(res.question_ends_at);

      if (res.status === 'countdown') {
        BattleSound.syncMusic();
        if (elCountdown) elCountdown.hidden = false;
        if (elLocked) elLocked.hidden = true;
        if (elReveal) elReveal.hidden = true;
        if (elChoices) elChoices.hidden = true;
        var n = Math.max(1, qLeft);
        if (elCountdownNum) {
          elCountdownNum.textContent = n <= 0 ? 'GO!' : String(n);
        }
        if (n !== lastCountdownSec) {
          lastCountdownSec = n;
          if (n > 0) BattleSound.play('countdown');
          else BattleSound.play('go');
        }
        if (elTimerLabel) elTimerLabel.textContent = 'STARTING';
        if (elTimer) elTimer.textContent = n <= 0 ? 'GO' : String(n);
        lastStatus = 'countdown';
        return;
      }

      if (elCountdown) elCountdown.hidden = true;

      if (res.status === 'question') {
        if (lastStatus !== 'question' || lastOrdinal !== res.current_ordinal) {
          lastOrdinal = res.current_ordinal;
          warnedTick = false;
          if (elReveal) elReveal.hidden = true;
          if (elLocked) elLocked.hidden = !!(res.me && res.me.answered_current);
          if (res.question) renderQuestion(res.question);
          BattleSound.syncMusic();
        } else if (res.me && res.me.answered_current && elLocked) {
          elLocked.hidden = false;
          if (elChoices) {
            elChoices.querySelectorAll('.pg-choice').forEach(function (b) {
              b.disabled = true;
            });
          }
        }
        if (elTimerLabel) elTimerLabel.textContent = 'QUESTION TIME';
        if (elTimer) elTimer.textContent = String(qLeft);
        if (elTimerWrap) {
          elTimerWrap.setAttribute('data-state', qLeft <= 5 ? 'urgent' : qLeft <= 10 ? 'warn2' : 'normal');
        }
        if (qLeft <= 5 && qLeft > 0 && !warnedTick) {
          warnedTick = true;
          BattleSound.play('tick');
        }
        lastStatus = 'question';
        return;
      }

      if (res.status === 'reveal') {
        if (elLocked) elLocked.hidden = true;
        if (elChoices) elChoices.hidden = true;
        if (elReveal && res.reveal) {
          elReveal.hidden = false;
          var ok = !!res.reveal.is_correct;
          elReveal.className = 'pg-reveal pg-reveal-flash is-' + (ok ? 'correct' : 'wrong');
          if (elRevealIcon) elRevealIcon.textContent = ok ? '✓' : '✕';
          if (elRevealTitle) elRevealTitle.textContent = '⚡ ANSWER REVEALED';
          if (elRevealSub) {
            elRevealSub.textContent = 'Correct Answer: ' + (res.reveal.correct_display || res.reveal.correct_answer || '—');
          }
          if (elRevealMetrics) {
            elRevealMetrics.innerHTML =
              '<span class="pg-chip ' +
              (ok ? 'points' : 'zero') +
              '">' +
              (ok ? '+' + (res.reveal.points || 0) + ' XP' : '0 XP') +
              '</span>' +
              (ok && res.me && res.me.current_streak > 1
                ? '<span class="pg-chip streak">🔥 ' + res.me.current_streak + ' STREAK</span>'
                : '');
          }
          if (lastStatus !== 'reveal') {
            BattleSound.play(ok ? 'correct' : 'wrong');
          }
        }
        if (elTimerLabel) elTimerLabel.textContent = 'REVEAL';
        if (elTimer) elTimer.textContent = String(qLeft);
        lastStatus = 'reveal';
      }
    }

    function poll() {
      post('state', { room_code: cfg.roomCode }).then(applyState);
    }
    poll();
    setInterval(poll, POLL_MS);
  }

  /* ---------- RESULT ---------- */
  if (cfg.view === 'result') {
    BattleSound.bindUi();
    if (cfg.playVictory) {
      try {
        BattleSound.unlock();
        BattleSound.play('victory');
      } catch (e) {}
    }
    document.querySelectorAll('.pg-mistake-btn').forEach(function (btn) {
      btn.addEventListener('click', function () {
        btn.disabled = true;
        post('mistake_add', {
          question_id: parseInt(btn.getAttribute('data-question-id') || '0', 10),
          quiz_id: parseInt(btn.getAttribute('data-quiz-id') || '0', 10),
          subject_id: parseInt(btn.getAttribute('data-subject-id') || '0', 10),
          selected_answer: btn.getAttribute('data-selected') || '',
          correct_answer: btn.getAttribute('data-correct') || '',
          explanation: btn.getAttribute('data-explanation') || '',
        }).then(function (res) {
          btn.textContent = res && res.ok ? 'Added ✓' : 'Retry';
          btn.disabled = !!(res && res.ok);
        });
      });
    });
  }
})();
