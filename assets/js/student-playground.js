/**
 * CPA Playground — total exam timer, free navigation, music/SFX, instant select flow.
 */
(function () {
  'use strict';

  var MUSIC_KEY = 'ereview_pg_music';
  var SFX_KEY = 'ereview_pg_sfx';
  var MUTE_KEY = 'ereview_pg_mute';
  var WANT_MUSIC_KEY = 'ereview_pg_want_music';
  var DEFAULT_MUSIC_URL = 'assets/audio/thinking-time.mp3';
  var MUSIC_VOL = 0.32;
  var DUCK_VOL = 0.045;

  function resolveAssetUrl(url) {
    if (!url || /^https?:\/\//i.test(url) || url.charAt(0) === '/') return url;
    try {
      return new URL(url, window.location.href).pathname;
    } catch (e) {
      return url;
    }
  }

  /* ---------- Audio: thinking-time MP3 + WebAudio SFX + master mute ---------- */
  var PgSound = (function () {
    var ctx = null;
    var unlocked = false;
    var musicOn = true;
    var sfxOn = true;
    var muted = false;
    var lastPlayed = {};
    var musicEl = null;
    var musicUrl = DEFAULT_MUSIC_URL;
    var fadeTimer = null;
    var ducked = false;
    var targetVol = MUSIC_VOL;
    var warned = { m10: false, m5: false, m2: false, m1: false };
    var gestureArmed = false;
    var playRetryBound = false;

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

    function isMusicPlaying() {
      return !!(musicEl && !musicEl.paused && !musicEl.ended);
    }

    function ensureMusicEl() {
      if (musicEl) return musicEl;
      var inline = document.getElementById('pg-bg-music');
      var src = resolveAssetUrl(musicUrl);
      if (inline && inline.tagName === 'AUDIO') {
        musicEl = inline;
        if (!musicEl.getAttribute('src') && (!musicEl.currentSrc || musicEl.currentSrc === '')) {
          musicEl.src = src;
        } else if (musicEl.getAttribute('src') !== src && musicEl.src.indexOf(src) === -1) {
          // Prefer configured URL when inline src is empty/stale.
          try { musicEl.src = src; } catch (eSrc) {}
        }
      } else {
        musicEl = new Audio(src);
      }
      musicEl.loop = true;
      musicEl.preload = 'auto';
      try { musicEl.volume = MUSIC_VOL; } catch (eVol) {}
      musicEl.setAttribute('playsinline', 'true');
      musicEl.setAttribute('webkit-playsinline', 'true');
      if (!playRetryBound) {
        playRetryBound = true;
        musicEl.addEventListener('canplay', function () {
          if (musicShouldPlay() && !isMusicPlaying()) tryPlayMusic();
        });
        musicEl.addEventListener('playing', function () {
          syncMusicHint(false);
        });
        musicEl.addEventListener('error', function () {
          syncMusicHint(true);
        });
      }
      try { musicEl.load(); } catch (eLoad) {}
      return musicEl;
    }

    function clearFade() {
      if (fadeTimer) {
        clearInterval(fadeTimer);
        fadeTimer = null;
      }
    }

    function fadeMusicTo(vol, ms) {
      var el = musicEl;
      if (!el) return;
      clearFade();
      targetVol = vol;
      var start = el.volume;
      var dur = Math.max(40, ms || 280);
      var t0 = performance.now();
      fadeTimer = setInterval(function () {
        var p = Math.min(1, (performance.now() - t0) / dur);
        // ease-out
        var e = 1 - Math.pow(1 - p, 2);
        try {
          el.volume = Math.max(0, Math.min(1, start + (vol - start) * e));
        } catch (err) {}
        if (p >= 1) {
          clearFade();
          try { el.volume = vol; } catch (err2) {}
        }
      }, 32);
    }

    function musicShouldPlay() {
      return unlocked && musicOn && !muted;
    }

    function syncMusicHint(show) {
      var hint = document.getElementById('pg-music-hint');
      if (!hint) return;
      if (show == null) show = musicShouldPlay() && !isMusicPlaying();
      hint.hidden = !show;
    }

    function tryPlayMusic() {
      if (!musicShouldPlay()) {
        syncMusicHint(false);
        return;
      }
      var el = ensureMusicEl();
      if (!el) return;
      if (!ducked) {
        try { el.volume = MUSIC_VOL; } catch (e0) {}
      }
      var p = el.play();
      if (p && typeof p.then === 'function') {
        p.then(function () {
          syncMusicHint(false);
        }).catch(function () {
          /* Autoplay blocked — keep asking for a gesture. */
          syncMusicHint(true);
          armGestureUnlock();
        });
      } else if (!isMusicPlaying()) {
        syncMusicHint(true);
        armGestureUnlock();
      }
    }

    function armGestureUnlock() {
      if (gestureArmed) return;
      gestureArmed = true;
      var retry = function () {
        unlock();
        syncMusic();
        if (isMusicPlaying() || !musicShouldPlay()) {
          gestureArmed = false;
          document.removeEventListener('pointerdown', retry, true);
          document.removeEventListener('keydown', retry, true);
          document.removeEventListener('touchstart', retry, true);
          syncMusicHint(false);
        }
      };
      document.addEventListener('pointerdown', retry, true);
      document.addEventListener('keydown', retry, true);
      document.addEventListener('touchstart', retry, { capture: true, passive: true });
    }

    function pauseMusicKeepPosition() {
      clearFade();
      if (!musicEl) return;
      try {
        musicEl.pause();
      } catch (e1) {}
    }

    function syncMusic() {
      if (!musicShouldPlay()) {
        pauseMusicKeepPosition();
        return;
      }
      tryPlayMusic();
    }

    function unlock() {
      unlocked = true;
      var c = ensureCtx();
      if (c && c.state === 'suspended') {
        c.resume().catch(function () {});
      }
      try {
        if (
          sessionStorage.getItem(WANT_MUSIC_KEY) === '1' ||
          window.PG_PLAY ||
          (window.PG && !window.PG_PLAY)
        ) {
          ensureMusicEl();
        }
      } catch (e2) {}
      syncMusic();
    }

    function setMusicUrl(url) {
      if (!url || typeof url !== 'string') return;
      var next = resolveAssetUrl(url);
      if (musicUrl === next && musicEl) return;
      var wasPlaying = isMusicPlaying();
      var pos = musicEl ? musicEl.currentTime : 0;
      musicUrl = next;
      if (musicEl) {
        var reuseInline = musicEl.id === 'pg-bg-music';
        if (reuseInline) {
          try { musicEl.pause(); } catch (e3a) {}
          musicEl.src = next;
          try { musicEl.load(); } catch (e3b) {}
        } else {
          try { musicEl.pause(); } catch (e3) {}
          musicEl = null;
          playRetryBound = false;
        }
      }
      ensureMusicEl();
      if (musicEl && pos > 0) {
        try { musicEl.currentTime = pos; } catch (e4) {}
      }
      if (wasPlaying || musicShouldPlay()) syncMusic();
    }

    // Intentionally no duck/fade on answer clicks — thinking music stays steady.
    function duckForFeedback() {}
    function unduckAfterFeedback() {}

    function canSfx(name, gap) {
      if (muted || !sfxOn || !unlocked) return false;
      var now = Date.now();
      gap = gap == null ? 90 : gap;
      if (lastPlayed[name] && now - lastPlayed[name] < gap) return false;
      lastPlayed[name] = now;
      return true;
    }

    function tone(freq, dur, type, vol, when) {
      var c = ensureCtx();
      if (!c) return;
      var t0 = (when != null ? when : 0) + c.currentTime;
      var osc = c.createOscillator();
      var gain = c.createGain();
      osc.type = type || 'sine';
      osc.frequency.setValueAtTime(freq, t0);
      gain.gain.setValueAtTime(0.0001, t0);
      gain.gain.exponentialRampToValueAtTime(Math.max(0.0002, vol || 0.04), t0 + 0.015);
      gain.gain.exponentialRampToValueAtTime(0.0001, t0 + dur);
      osc.connect(gain);
      gain.connect(c.destination);
      osc.start(t0);
      osc.stop(t0 + dur + 0.02);
    }

    function play(name) {
      if (!canSfx(name, name.indexOf('warn') === 0 ? 2000 : 90)) return;
      try {
        var c = ensureCtx();
        if (c && c.state === 'suspended') c.resume().catch(function () {});
        switch (name) {
          case 'start':
            tone(392, 0.1, 'triangle', 0.04);
            tone(523, 0.14, 'triangle', 0.04, 0.08);
            tone(659, 0.16, 'triangle', 0.035, 0.18);
            break;
          case 'click':
            tone(700, 0.035, 'square', 0.018);
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
          case 'warn10':
            tone(520, 0.08, 'sine', 0.028);
            break;
          case 'warn5':
            tone(480, 0.1, 'triangle', 0.032);
            tone(600, 0.1, 'triangle', 0.028, 0.1);
            break;
          case 'warn2':
            tone(440, 0.12, 'triangle', 0.03);
            tone(330, 0.14, 'triangle', 0.028, 0.1);
            break;
          case 'warn1':
            tone(400, 0.14, 'square', 0.028);
            tone(300, 0.16, 'square', 0.026, 0.12);
            break;
          case 'expire':
            tone(200, 0.22, 'triangle', 0.04);
            tone(140, 0.28, 'triangle', 0.035, 0.14);
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

    /** Play correct/wrong SFX without fading the thinking music. */
    function playAnswerFeedback(isCorrect) {
      play(isCorrect ? 'correct' : 'wrong');
    }

    function setMusic(on) {
      musicOn = !!on;
      try { localStorage.setItem(MUSIC_KEY, musicOn ? '1' : '0'); } catch (e) {}
      if (!musicOn) {
        ducked = false;
        pauseMusicKeepPosition();
      } else {
        syncMusic();
      }
      syncUi();
    }
    function setSfx(on) {
      sfxOn = !!on;
      try { localStorage.setItem(SFX_KEY, sfxOn ? '1' : '0'); } catch (e) {}
      syncUi();
    }
    function setMute(on) {
      muted = !!on;
      try { localStorage.setItem(MUTE_KEY, muted ? '1' : '0'); } catch (e) {}
      if (muted) {
        ducked = false;
        pauseMusicKeepPosition();
      } else {
        syncMusic();
      }
      syncUi();
    }

    function syncUi() {
      var m = document.getElementById('pg-music-toggle');
      var s = document.getElementById('pg-sfx-toggle');
      var u = document.getElementById('pg-mute-toggle');
      if (m) {
        m.classList.toggle('is-off', !musicOn || muted);
        m.textContent = musicOn && !muted ? '🎵 Music' : '🎵 Off';
        m.setAttribute('aria-pressed', musicOn && !muted ? 'true' : 'false');
      }
      if (s) {
        s.classList.toggle('is-off', !sfxOn || muted);
        s.textContent = sfxOn && !muted ? '🔔 SFX' : '🔔 Off';
        s.setAttribute('aria-pressed', sfxOn && !muted ? 'true' : 'false');
      }
      if (u) {
        u.classList.toggle('is-off', muted);
        u.textContent = muted ? '🔇' : '🔊';
        u.setAttribute('aria-pressed', muted ? 'true' : 'false');
      }
    }

    function bindUi() {
      var m = document.getElementById('pg-music-toggle');
      var s = document.getElementById('pg-sfx-toggle');
      var u = document.getElementById('pg-mute-toggle');
      if (m && !m._pgBound) {
        m._pgBound = true;
        m.addEventListener('click', function () {
          unlock();
          if (muted) setMute(false);
          // First click should START music if preference is on but autoplay was blocked.
          if (musicOn && !isMusicPlaying()) {
            setMusic(true);
            play('click');
            return;
          }
          setMusic(!musicOn);
          if (musicOn) play('click');
        });
      }
      if (s && !s._pgBound) {
        s._pgBound = true;
        s.addEventListener('click', function () {
          unlock();
          if (muted) setMute(false);
          setSfx(!sfxOn);
          if (sfxOn) play('click');
        });
      }
      if (u && !u._pgBound) {
        u._pgBound = true;
        u.addEventListener('click', function () {
          unlock();
          setMute(!muted);
          if (!muted) play('click');
        });
      }
      syncUi();
    }

    function timerWarnings(remainingSec) {
      if (remainingSec <= 60 && !warned.m1) {
        warned.m1 = true;
        play('warn1');
        return 'urgent';
      }
      if (remainingSec <= 120 && !warned.m2) {
        warned.m2 = true;
        play('warn2');
        return 'warn2';
      }
      if (remainingSec <= 300 && !warned.m5) {
        warned.m5 = true;
        play('warn5');
        return 'warn5';
      }
      if (remainingSec <= 600 && !warned.m10) {
        warned.m10 = true;
        play('warn10');
        return 'normal';
      }
      if (remainingSec <= 60) return 'urgent';
      if (remainingSec <= 120) return 'warn2';
      if (remainingSec <= 300) return 'warn5';
      return 'normal';
    }

    function stopAll() {
      ducked = false;
      clearFade();
      pauseMusicKeepPosition();
    }

    return {
      unlock: unlock,
      play: play,
      playAnswerFeedback: playAnswerFeedback,
      duckForFeedback: duckForFeedback,
      unduckAfterFeedback: unduckAfterFeedback,
      setMusicUrl: setMusicUrl,
      bindUi: bindUi,
      syncUi: syncUi,
      syncMusic: syncMusic,
      stopAll: stopAll,
      armGestureUnlock: armGestureUnlock,
      isMusicPlaying: isMusicPlaying,
      timerWarnings: timerWarnings,
      resetWarnings: function () {
        warned = { m10: false, m5: false, m2: false, m1: false };
      },
    };
  })();

  window.PgSound = PgSound;

  function post(apiUrl, csrf, action, data) {
    var body = Object.assign({ action: action, csrf_token: csrf }, data || {});
    return fetch(apiUrl, {
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

  function fmtTime(sec) {
    sec = Math.max(0, sec | 0);
    var m = Math.floor(sec / 60);
    var s = sec % 60;
    return (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
  }

  if (document.body.classList.contains('pg-game-mode')) {
    try {
      if (window.closeAppShellSidebar) window.closeAppShellSidebar();
    } catch (e) {}
  }

  /* ---------- Lobby ---------- */
  if (window.PG && !window.PG_PLAY) {
    var modeInput = document.getElementById('pg-mode');
    var subjectWrap = document.getElementById('pg-subject-wrap');
    var countWrap = document.getElementById('pg-count-wrap');
    var timeValueEl = document.getElementById('pg-time-value');
    var timeUnitEl = document.getElementById('pg-time-unit');
    var timePresetsEl = document.getElementById('pg-time-presets');
    var hintEl = document.getElementById('pg-time-hint');
    var errEl = document.getElementById('pg-setup-error');
    var startBtn = document.getElementById('pg-start');
    var modeChip = document.getElementById('pg-mode-chip');

    var MODE_LABELS = {
      quick_play: '⚡ Quick Play',
      subject_challenge: '📚 Subject Challenge',
      mixed_challenge: '🔀 Mixed CPA',
      daily_challenge: '📅 Daily Challenge',
    };

    var PRESETS = {
      seconds: [30, 60, 90, 120, 180],
      minutes: [5, 10, 15, 30, 60],
      hours: [1, 2],
    };
    var MIN_TOTAL_SEC = 30;
    var MAX_TOTAL_SEC = 10800; // 3 hours — matches server clamp

    function qCount() {
      var mode = (modeInput && modeInput.value) || 'quick_play';
      if (mode === 'quick_play') return 10;
      if (mode === 'daily_challenge') return 5;
      var qc = document.querySelector('input[name="qcount"]:checked');
      return qc ? parseInt(qc.value, 10) : 10;
    }

    function recommendedMinutes(n) {
      // Keep in sync with student_playground_recommended_total_seconds().
      if (n <= 5) return 5;
      if (n <= 10) return 10;
      if (n <= 20) return 15;
      if (n <= 30) return 30;
      return 45;
    }

    function currentUnit() {
      return (timeUnitEl && timeUnitEl.value) || 'minutes';
    }

    function durationToSeconds(value, unit) {
      value = Math.max(1, parseInt(value, 10) || 0);
      unit = unit || 'minutes';
      var sec = unit === 'seconds' ? value : unit === 'hours' ? value * 3600 : value * 60;
      return Math.max(MIN_TOTAL_SEC, Math.min(MAX_TOTAL_SEC, sec));
    }

    function clampDurationInput() {
      if (!timeValueEl) return;
      var unit = currentUnit();
      var raw = parseInt(timeValueEl.value, 10);
      if (!isFinite(raw) || raw < 1) {
        timeValueEl.value = unit === 'hours' ? '1' : unit === 'seconds' ? '30' : '10';
        return;
      }
      var sec = durationToSeconds(raw, unit);
      // If clamp changed the seconds, rewrite the value in the active unit.
      var expected =
        unit === 'seconds' ? sec : unit === 'hours' ? Math.round(sec / 3600) : Math.round(sec / 60);
      if (expected !== raw) timeValueEl.value = String(Math.max(1, expected));
      // Unit-specific max for the number input UX.
      if (unit === 'seconds') {
        timeValueEl.max = String(MAX_TOTAL_SEC);
        timeValueEl.min = String(MIN_TOTAL_SEC);
      } else if (unit === 'hours') {
        timeValueEl.max = '3';
        timeValueEl.min = '1';
      } else {
        timeValueEl.max = '180';
        timeValueEl.min = '1';
      }
    }

    function selectedDuration() {
      clampDurationInput();
      var value = timeValueEl ? parseInt(timeValueEl.value, 10) || 10 : 10;
      var unit = currentUnit();
      return {
        time_value: value,
        time_unit: unit,
        total_seconds: durationToSeconds(value, unit),
      };
    }

    function syncTimeHint() {
      var n = qCount();
      var rec = recommendedMinutes(n);
      if (hintEl) {
        if (n <= 10) {
          hintEl.textContent =
            'Recommended: 10–15 minutes for ' + n + ' questions. Total game time applies to the entire session.';
        } else {
          hintEl.textContent =
            'Recommended: about ' + rec + ' minutes for ' + n +
            ' questions. Total game time applies to the entire session — not per question.';
        }
      }
    }

    function applyRecommendedDuration() {
      if (!timeValueEl || !timeUnitEl) return;
      var rec = recommendedMinutes(qCount());
      timeUnitEl.value = 'minutes';
      timeValueEl.value = String(rec);
      clampDurationInput();
      renderTimePresets();
      syncTimeHint();
    }

    function renderTimePresets() {
      if (!timePresetsEl) return;
      var unit = currentUnit();
      var list = PRESETS[unit] || PRESETS.minutes;
      var cur = timeValueEl ? parseInt(timeValueEl.value, 10) : 0;
      timePresetsEl.innerHTML = '';
      list.forEach(function (v) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'pg-opt pg-time-preset' + (cur === v ? ' is-active' : '');
        b.textContent = unit === 'hours' ? v + ' hr' : unit === 'seconds' ? v + 's' : v + ' min';
        b.setAttribute('data-value', String(v));
        b.addEventListener('click', function () {
          if (timeValueEl) timeValueEl.value = String(v);
          clampDurationInput();
          renderTimePresets();
        });
        timePresetsEl.appendChild(b);
      });
    }

    function setMode(mode) {
      if (modeInput) modeInput.value = mode;
      document.querySelectorAll('[data-pg-mode]').forEach(function (btn) {
        var on = btn.getAttribute('data-pg-mode') === mode;
        btn.classList.toggle('is-selected', on);
        btn.setAttribute('aria-selected', on ? 'true' : 'false');
      });
      if (modeChip) modeChip.textContent = MODE_LABELS[mode] || mode;
      if (subjectWrap) subjectWrap.hidden = mode !== 'subject_challenge';
      if (countWrap) countWrap.hidden = mode === 'quick_play' || mode === 'daily_challenge';
      applyRecommendedDuration();
    }

    function startGame(opts) {
      opts = opts || {};
      var mode = opts.mode || (modeInput && modeInput.value) || 'quick_play';
      var subjectId = opts.subject_id != null
        ? opts.subject_id
        : parseInt((document.getElementById('pg-subject') || {}).value || '0', 10);
      var count = opts.question_count != null ? opts.question_count : qCount();
      var duration = opts.duration || selectedDuration();

      if (mode === 'subject_challenge' && subjectId <= 0) {
        if (errEl) {
          errEl.textContent = 'Please select a subject.';
          errEl.classList.remove('hidden');
        }
        document.getElementById('pg-setup').scrollIntoView({ behavior: 'smooth' });
        return;
      }
      if (duration.total_seconds < MIN_TOTAL_SEC) {
        if (errEl) {
          errEl.textContent = 'Please set a total exam time of at least 30 seconds.';
          errEl.classList.remove('hidden');
        }
        return;
      }
      if (errEl) errEl.classList.add('hidden');

      PgSound.unlock();
      PgSound.setMusicUrl(DEFAULT_MUSIC_URL);
      PgSound.play('start');
      try {
        sessionStorage.setItem('ereview_pg_pending_start_sound', '1');
        sessionStorage.setItem(WANT_MUSIC_KEY, '1');
        PgSound.syncMusic();
      } catch (e2) {}

      if (startBtn) {
        startBtn.disabled = true;
        startBtn.textContent = 'Starting…';
      }

      post(window.PG.apiUrl, window.PG.csrf, 'start', {
        mode: mode,
        play_style: 'playground',
        subject_id: subjectId,
        question_count: count,
        time_value: duration.time_value,
        time_unit: duration.time_unit,
        // Legacy keys kept for older clients / debugging; server clamps either way.
        time_minutes: duration.time_unit === 'minutes' ? duration.time_value : Math.round(duration.total_seconds / 60),
        custom_time: 1,
        difficulty: 'mixed',
      }).then(function (res) {
        if (res && res.ok && res.session_id) {
          window.location.href = 'student_playground_play?session_id=' + res.session_id;
          return;
        }
        if (startBtn) {
          startBtn.disabled = false;
          startBtn.innerHTML = '<i class="bi bi-play-fill"></i> Start Game';
        }
        if (res && res.already_done && res.session_id) {
          window.location.href = 'student_playground_result?session_id=' + res.session_id;
          return;
        }
        if (errEl) {
          errEl.textContent = (res && res.error) || 'Could not start game.';
          errEl.classList.remove('hidden');
        }
      });
    }

    document.querySelectorAll('[data-pg-mode]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var mode = btn.getAttribute('data-pg-mode');
        if (mode === 'daily_challenge' && window.PG.dailyDone && window.PG.dailySessionId) {
          window.location.href = 'student_playground_result?session_id=' + window.PG.dailySessionId;
          return;
        }
        setMode(mode);
        if (errEl) errEl.classList.add('hidden');
        if (startBtn && typeof startBtn.focus === 'function') {
          try { startBtn.focus({ preventScroll: true }); } catch (eFocus) {}
        }
      });
    });

    document.querySelectorAll('input[name="qcount"]').forEach(function (r) {
      r.addEventListener('change', function () {
        applyRecommendedDuration();
      });
    });
    if (timeUnitEl) {
      timeUnitEl.addEventListener('change', function () {
        // Sensible defaults when switching units.
        if (timeValueEl) {
          if (currentUnit() === 'seconds' && parseInt(timeValueEl.value, 10) < 30) {
            timeValueEl.value = '30';
          } else if (currentUnit() === 'hours' && parseInt(timeValueEl.value, 10) > 3) {
            timeValueEl.value = '1';
          } else if (currentUnit() === 'minutes' && parseInt(timeValueEl.value, 10) > 180) {
            timeValueEl.value = '10';
          }
        }
        clampDurationInput();
        renderTimePresets();
        syncTimeHint();
      });
    }
    if (timeValueEl) {
      timeValueEl.addEventListener('change', function () {
        clampDurationInput();
        renderTimePresets();
      });
      timeValueEl.addEventListener('input', function () {
        // Keep presets highlight in sync while typing.
        if (timePresetsEl) {
          var cur = parseInt(timeValueEl.value, 10);
          timePresetsEl.querySelectorAll('.pg-time-preset').forEach(function (b) {
            b.classList.toggle('is-active', parseInt(b.getAttribute('data-value'), 10) === cur);
          });
        }
      });
    }

    if (startBtn) startBtn.addEventListener('click', function () { startGame(); });
    setMode((modeInput && modeInput.value) || 'quick_play');

    // Lobby soundtrack — browsers block autoplay until a real click/key/tap.
    var lobbyMusicUrl = (window.PG && window.PG.musicUrl) || DEFAULT_MUSIC_URL;
    PgSound.setMusicUrl(lobbyMusicUrl);
    PgSound.bindUi();
    try { sessionStorage.setItem(WANT_MUSIC_KEY, '1'); } catch (eLobbyWant) {}
    PgSound.unlock();
    PgSound.armGestureUnlock();
  }

  if (window.PG_RESULT && window.PG_RESULT.playVictory) {
    PgSound.bindUi();
    try {
      if (sessionStorage.getItem('ereview_pg_game_active') === '1') {
        PgSound.unlock();
        PgSound.play('victory');
        sessionStorage.removeItem('ereview_pg_game_active');
      }
    } catch (e) {}
  }

  /* ---------- Play ---------- */
  if (window.PG_PLAY) {
    var cfg = window.PG_PLAY;
    var examTimerId = null;
    var advanceTimer = null;
    var endsAtMs = cfg.endsAt ? Date.parse(cfg.endsAt) : 0;
    var remainingTotal = cfg.remainingTotalSeconds | 0;
    var phase = 'loading';
    var currentQ = null;
    var submitting = false;
    var lastResult = null;
    var navigatorData = [];
    var feedbackMs = Math.max(1000, Math.min(1600, cfg.feedbackMs || 1300));
    var displayScore = 0;

    var elScore = document.getElementById('pg-score');
    var elScoreChip = document.getElementById('pg-score-chip');
    var elProgress = document.getElementById('pg-progress');
    var elProgressFill = document.getElementById('pg-progress-fill');
    var elProgressBar = document.getElementById('pg-progress-bar');
    var elTimer = document.getElementById('pg-timer');
    var elTimerWrap = document.getElementById('pg-exam-timer');
    var elTimerWarn = document.getElementById('pg-timer-warn');
    var elSubject = document.getElementById('pg-subject');
    var elQuestion = document.getElementById('pg-question');
    var elChoices = document.getElementById('pg-choices');
    var elNav = document.getElementById('pg-nav');
    var elReveal = document.getElementById('pg-reveal');
    var elRevealTitle = document.getElementById('pg-reveal-title');
    var elRevealSub = document.getElementById('pg-reveal-sub');
    var elRevealIcon = document.getElementById('pg-reveal-icon');
    var elRevealMetrics = document.getElementById('pg-reveal-metrics');
    var elMilestone = document.getElementById('pg-milestone');
    var elStreakWrap = document.getElementById('pg-streak-wrap');
    var elStreakN = document.getElementById('pg-streak-n');
    var elConfetti = document.getElementById('pg-confetti');
    var elSkip = document.getElementById('pg-skip');
    var elFinishGame = document.getElementById('pg-finish-game');
    var elModal = document.getElementById('pg-submit-modal');
    var elModalTitle = document.getElementById('pg-modal-title');
    var elModalBody = document.getElementById('pg-modal-body');
    var elReviewUnanswered = document.getElementById('pg-review-unanswered');
    var elConfirmSubmit = document.getElementById('pg-confirm-submit');
    var elModalCancel = document.getElementById('pg-modal-cancel');

    if (cfg.musicUrl) PgSound.setMusicUrl(cfg.musicUrl);
    PgSound.bindUi();
    PgSound.resetWarnings();
    try { sessionStorage.setItem('ereview_pg_game_active', '1'); } catch (e) {}

    // Resume thinking music after explicit Start Game (gesture chain / first interaction).
    try {
      if (sessionStorage.getItem(WANT_MUSIC_KEY) === '1') {
        PgSound.unlock();
        PgSound.syncMusic();
      }
    } catch (eWant) {}

    document.addEventListener(
      'pointerdown',
      function once() {
        PgSound.unlock();
        PgSound.syncMusic();
        try {
          if (sessionStorage.getItem('ereview_pg_pending_start_sound') === '1') {
            sessionStorage.removeItem('ereview_pg_pending_start_sound');
            PgSound.play('start');
          }
        } catch (e3) {}
        document.removeEventListener('pointerdown', once, true);
      },
      true
    );

    function clearAdvance() {
      if (advanceTimer) {
        clearTimeout(advanceTimer);
        advanceTimer = null;
      }
    }

    function bumpChip(el) {
      if (!el) return;
      el.classList.remove('is-bump');
      void el.offsetWidth;
      el.classList.add('is-bump');
    }

    function floatPoints(pts) {
      if (!pts || !elScoreChip) return;
      var rect = elScoreChip.getBoundingClientRect();
      var el = document.createElement('div');
      el.className = 'pg-score-float';
      el.textContent = (pts > 0 ? '+' : '') + pts;
      el.style.left = rect.left + rect.width / 2 - 20 + 'px';
      el.style.top = rect.top - 8 + 'px';
      document.body.appendChild(el);
      setTimeout(function () {
        if (el.parentNode) el.parentNode.removeChild(el);
      }, 900);
      bumpChip(elScoreChip);
    }

    function burstConfetti() {
      if (!elConfetti) return;
      elConfetti.hidden = false;
      elConfetti.innerHTML = '';
      var colors = ['#8b6cff', '#34d399', '#fbbf24', '#4db3ef', '#fb923c'];
      for (var i = 0; i < 12; i++) {
        var bit = document.createElement('i');
        bit.style.left = 10 + Math.random() * 80 + '%';
        bit.style.background = colors[i % colors.length];
        elConfetti.appendChild(bit);
      }
      setTimeout(function () {
        elConfetti.hidden = true;
        elConfetti.innerHTML = '';
      }, 850);
    }

    function updateExamTimerDisplay(sec) {
      remainingTotal = Math.max(0, sec | 0);
      if (elTimer) elTimer.textContent = fmtTime(remainingTotal);
      var state = PgSound.timerWarnings(remainingTotal);
      if (elTimerWrap) elTimerWrap.setAttribute('data-state', state);
      if (elTimerWarn) {
        if (remainingTotal <= 60 && remainingTotal > 0) {
          elTimerWarn.hidden = false;
          elTimerWarn.textContent = '🔥 ' + fmtTime(remainingTotal) + ' REMAINING';
        } else if (remainingTotal <= 120) {
          elTimerWarn.hidden = false;
          elTimerWarn.textContent = '⚠️ Under 2 minutes';
        } else if (remainingTotal <= 300) {
          elTimerWarn.hidden = false;
          elTimerWarn.textContent = '⚠️ 5 minutes remaining';
        } else if (remainingTotal <= 600) {
          elTimerWarn.hidden = false;
          elTimerWarn.textContent = '10 minutes remaining';
        } else {
          elTimerWarn.hidden = true;
        }
      }
    }

    function tickExamTimer() {
      var left;
      if (endsAtMs && !isNaN(endsAtMs)) {
        left = Math.max(0, Math.floor((endsAtMs - Date.now()) / 1000));
      } else {
        left = Math.max(0, remainingTotal - 1);
      }
      updateExamTimerDisplay(left);
      if (left <= 0) {
        if (examTimerId) {
          clearInterval(examTimerId);
          examTimerId = null;
        }
        PgSound.play('expire');
        finishGame();
      }
    }

    function startExamTimer(sec, endsAt) {
      if (endsAt) {
        var parsed = Date.parse(endsAt);
        if (!isNaN(parsed)) endsAtMs = parsed;
      }
      if (!endsAtMs && sec != null) {
        endsAtMs = Date.now() + sec * 1000;
      }
      updateExamTimerDisplay(
        endsAtMs ? Math.max(0, Math.floor((endsAtMs - Date.now()) / 1000)) : sec || 0
      );
      if (examTimerId) clearInterval(examTimerId);
      examTimerId = setInterval(tickExamTimer, 1000);
    }

    function renderNav(nav, currentOrd) {
      navigatorData = nav || [];
      if (!elNav) return;
      elNav.innerHTML = '';
      navigatorData.forEach(function (n) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'pg-nav-btn';
        var st = n.status;
        if (n.ordinal === currentOrd) st = 'current';
        else if (n.answered) st = 'answered';
        else st = 'unanswered';
        b.classList.add('is-' + st);
        b.textContent =
          (n.answered ? '✓ ' : st === 'current' ? '● ' : '— ') + n.ordinal;
        b.title = 'Question ' + n.ordinal;
        b.addEventListener('click', function () {
          gotoOrdinal(n.ordinal);
        });
        elNav.appendChild(b);
      });
    }

    function clearReveal() {
      clearAdvance();
      if (elReveal) {
        elReveal.hidden = true;
        elReveal.className = 'pg-reveal pg-reveal-flash';
      }
      if (elMilestone) {
        elMilestone.hidden = true;
        elMilestone.textContent = '';
      }
      if (elRevealMetrics) elRevealMetrics.innerHTML = '';
    }

    function updateHud(s, ordinal) {
      s = s || {};
      displayScore = s.score != null ? s.score : displayScore;
      if (elScore) elScore.textContent = String(displayScore);
      var total = s.question_count || cfg.questionCount || '?';
      if (elProgress) elProgress.textContent = 'Q ' + (ordinal || 1) + ' / ' + total;
      var answered = s.answered_count != null ? s.answered_count : 0;
      var pct = total && total !== '?' ? Math.round((answered / total) * 100) : 0;
      if (elProgressFill) elProgressFill.style.width = pct + '%';
      if (elProgressBar) elProgressBar.setAttribute('aria-valuenow', String(pct));
      if (elStreakN) elStreakN.textContent = String(s.current_streak || 0);
      if (s.remaining_total_seconds != null) {
        updateExamTimerDisplay(s.remaining_total_seconds);
      }
      if (s.ends_at) {
        var p = Date.parse(s.ends_at);
        if (!isNaN(p)) endsAtMs = p;
      }
    }

    function renderQuestion(payload) {
      phase = 'select';
      submitting = false;
      lastResult = null;
      currentQ = payload.question;
      var s = payload.session || {};
      clearReveal();
      updateHud(s, currentQ.ordinal);
      if (payload.remaining_total_seconds != null || s.remaining_total_seconds != null) {
        startExamTimer(
          payload.remaining_total_seconds != null
            ? payload.remaining_total_seconds
            : s.remaining_total_seconds,
          s.ends_at || cfg.endsAt
        );
      }
      if (elSubject) elSubject.textContent = currentQ.subject_name || 'CPA';
      if (elQuestion) elQuestion.innerHTML = currentQ.question_text || '';
      renderNav(payload.navigator || s.navigator, currentQ.ordinal);

      if (elChoices) {
        elChoices.innerHTML = '';
        (currentQ.choices || []).forEach(function (c) {
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
          if (currentQ.prior_selected && currentQ.prior_selected === c.letter) {
            btn.classList.add('is-selected');
          }
          btn.addEventListener('click', function () {
            selectAndSubmit(c.letter);
          });
          elChoices.appendChild(btn);
        });
      }
    }

    function selectAndSubmit(letter) {
      if (phase !== 'select' || !currentQ || submitting) return;
      phase = 'locked';
      submitting = true;
      PgSound.play('click');
      if (elChoices) {
        elChoices.querySelectorAll('.pg-choice').forEach(function (btn) {
          btn.disabled = true;
          var sel = btn.getAttribute('data-letter') === letter;
          btn.classList.toggle('is-selected', sel);
          if (!sel) btn.classList.add('is-locked-out');
        });
      }
      post(cfg.apiUrl, cfg.csrf, 'answer', {
        session_id: cfg.sessionId,
        question_id: currentQ.question_id,
        selected_answer: letter,
        response_ms: 0,
      }).then(handleAnswerResult);
    }

    function paintChoiceResults(d) {
      if (!elChoices) return;
      var correctOrig = d.correct_answer || '';
      var selectedOrig = d.selected_answer || '';
      elChoices.querySelectorAll('.pg-choice').forEach(function (btn) {
        btn.disabled = true;
        btn.classList.remove('is-selected', 'is-locked-out', 'is-correct', 'is-wrong');
        var letter = btn.getAttribute('data-letter') || '';
        if (letter === correctOrig) btn.classList.add('is-correct');
        else if (selectedOrig && letter === selectedOrig && !d.is_correct) btn.classList.add('is-wrong');
        else btn.classList.add('is-locked-out');
      });
    }

    function handleAnswerResult(res) {
      submitting = false;
      if (res && res.time_expired) {
        window.location.href = 'student_playground_result?session_id=' + cfg.sessionId;
        return;
      }
      if (!res || !res.ok) {
        phase = 'select';
        if (elChoices) {
          elChoices.querySelectorAll('.pg-choice').forEach(function (btn) {
            btn.disabled = false;
            btn.classList.remove('is-locked-out');
          });
        }
        alert((res && res.error) || 'Could not submit');
        return;
      }
      var d = res.data || {};
      if (d.unchanged) {
        phase = 'select';
        if (elChoices) {
          elChoices.querySelectorAll('.pg-choice').forEach(function (btn) {
            btn.disabled = false;
            btn.classList.remove('is-locked-out');
          });
        }
        return;
      }

      phase = 'reveal';
      lastResult = d;
      displayScore = d.score || 0;
      if (elScore) elScore.textContent = String(displayScore);
      if (d.points_delta) floatPoints(d.points_delta);
      else if (d.points) floatPoints(d.points);
      if (elStreakN) elStreakN.textContent = String(d.current_streak || 0);
      if (elStreakWrap && d.current_streak > 0) bumpChip(elStreakWrap);

      var total = d.question_count || cfg.questionCount;
      var answered = d.answered_count || 0;
      if (elProgressFill) elProgressFill.style.width = Math.round((answered / total) * 100) + '%';
      if (elProgress) elProgress.textContent = 'Q ' + (d.ordinal || 1) + ' / ' + total;

      paintChoiceResults(d);
      if (d.navigator) renderNav(d.navigator, d.ordinal);

      var mode = d.is_correct ? 'correct' : 'wrong';
      PgSound.playAnswerFeedback(!!d.is_correct);
      if (elReveal) {
        elReveal.hidden = false;
        elReveal.className = 'pg-reveal pg-reveal-flash is-' + mode;
      }
      if (elRevealIcon) elRevealIcon.textContent = mode === 'correct' ? '✓' : '✕';
      if (elRevealTitle) elRevealTitle.textContent = mode === 'correct' ? 'CORRECT!' : 'NOT QUITE';
      if (elRevealSub) {
        elRevealSub.textContent =
          mode === 'correct' ? '' : 'Correct Answer: ' + (d.correct_display || d.correct_answer || '—');
      }
      if (elRevealMetrics) {
        var pts = d.points_delta != null ? d.points_delta : d.points || 0;
        var chips =
          '<span class="pg-chip ' +
          (pts > 0 ? 'points' : 'zero') +
          '">' +
          (pts > 0 ? '+' + pts + ' POINTS' : pts + ' POINTS') +
          '</span>';
        if (d.is_correct && d.current_streak > 0) {
          chips += '<span class="pg-chip streak">🔥 ' + d.current_streak + ' STREAK</span>';
        } else if (!d.is_correct) {
          chips += '<span class="pg-chip zero">Streak Reset</span>';
        }
        elRevealMetrics.innerHTML = chips;
      }
      if (mode === 'correct') burstConfetti();
      if (elMilestone) {
        if (d.streak_milestone) {
          elMilestone.hidden = false;
          elMilestone.textContent = '🔥 ' + d.current_streak + ' ANSWER STREAK!';
        } else elMilestone.hidden = true;
      }

      var wait = d.is_correct ? feedbackMs : Math.max(feedbackMs, 1450);
      // Re-answer: stay on question. First answer: auto-advance.
      clearAdvance();
      advanceTimer = setTimeout(function () {
        advanceTimer = null;
        PgSound.unduckAfterFeedback();
        if (d.reanswer) {
          phase = 'select';
          gotoOrdinal(d.ordinal);
          return;
        }
        if (d.all_answered) {
          openSubmitModal();
          return;
        }
        // Prefer next unanswered; otherwise next ordinal from server.
        var nextUn = findNextUnanswered(d.ordinal);
        if (nextUn != null) gotoOrdinal(nextUn);
        else gotoOrdinal(d.next_ordinal || d.ordinal + 1);
      }, wait);
    }

    function findNextUnanswered(afterOrdinal) {
      var cur = afterOrdinal || 0;
      var i;
      for (i = 0; i < navigatorData.length; i++) {
        if (!navigatorData[i].answered && navigatorData[i].ordinal > cur) {
          return navigatorData[i].ordinal;
        }
      }
      for (i = 0; i < navigatorData.length; i++) {
        if (!navigatorData[i].answered && navigatorData[i].ordinal !== cur) {
          return navigatorData[i].ordinal;
        }
      }
      return null;
    }

    function gotoOrdinal(ord) {
      clearAdvance();
      phase = 'loading';
      post(cfg.apiUrl, cfg.csrf, 'goto', {
        session_id: cfg.sessionId,
        ordinal: ord,
      }).then(function (res) {
        if (res && (res.finished || res.time_expired)) {
          window.location.href = 'student_playground_result?session_id=' + cfg.sessionId;
          return;
        }
        if (!res || !res.ok) {
          alert((res && res.error) || 'Could not load question');
          return;
        }
        // Music continues looping — do not restart on question change.
        PgSound.unduckAfterFeedback();
        renderQuestion(res);
      });
    }

    function unansweredCount() {
      return navigatorData.filter(function (n) { return !n.answered; }).length;
    }

    function openSubmitModal() {
      var u = unansweredCount();
      if (elModalTitle) elModalTitle.textContent = 'Finish Game?';
      if (elModalBody) {
        elModalBody.textContent =
          u > 0
            ? 'You still have ' + u + ' unanswered question' + (u === 1 ? '' : 's') + '.'
            : 'Ready to submit your game and view results?';
      }
      if (elReviewUnanswered) {
        elReviewUnanswered.hidden = u <= 0;
        elReviewUnanswered.textContent = 'Review Questions';
      }
      if (elModal) elModal.hidden = false;
      phase = 'select';
    }

    function closeModal() {
      if (elModal) elModal.hidden = true;
    }

    function finishGame() {
      clearAdvance();
      if (examTimerId) {
        clearInterval(examTimerId);
        examTimerId = null;
      }
      try { PgSound.stopAll(); } catch (eStop) {}
      post(cfg.apiUrl, cfg.csrf, 'finish', { session_id: cfg.sessionId }).then(function () {
        window.location.href = 'student_playground_result?session_id=' + cfg.sessionId;
      });
    }

    function skipToNextUnanswered() {
      if (phase !== 'select' || !currentQ) return;
      var cur = currentQ.ordinal || 1;
      var next = findNextUnanswered(cur);
      if (next == null) {
        // No other unanswered questions — offer finish (current may still be unanswered).
        openSubmitModal();
        return;
      }
      gotoOrdinal(next);
    }

    if (elSkip) {
      elSkip.addEventListener('click', function () {
        PgSound.play('click');
        skipToNextUnanswered();
      });
    }
    if (elFinishGame) elFinishGame.addEventListener('click', openSubmitModal);
    if (elModalCancel) elModalCancel.addEventListener('click', closeModal);
    if (elConfirmSubmit) {
      elConfirmSubmit.addEventListener('click', function () {
        closeModal();
        finishGame();
      });
    }
    if (elReviewUnanswered) {
      elReviewUnanswered.addEventListener('click', function () {
        closeModal();
        var first = navigatorData.find(function (n) { return !n.answered; });
        if (first) gotoOrdinal(first.ordinal);
      });
    }
    if (elModal) {
      elModal.addEventListener('click', function (ev) {
        // Backdrop click must not submit.
        if (ev.target === elModal) {
          /* ignore */
        }
      });
    }

    // Initial load
    post(cfg.apiUrl, cfg.csrf, 'current', { session_id: cfg.sessionId }).then(function (res) {
      if (res && (res.finished || res.time_expired)) {
        window.location.href = 'student_playground_result?session_id=' + cfg.sessionId;
        return;
      }
      if (!res || !res.ok) {
        alert((res && res.error) || 'Could not load question');
        return;
      }
      if (res.session && res.session.ends_at) cfg.endsAt = res.session.ends_at;
      startExamTimer(
        res.remaining_total_seconds != null
          ? res.remaining_total_seconds
          : cfg.remainingTotalSeconds,
        cfg.endsAt
      );
      renderQuestion(res);
    });
  }
})();
