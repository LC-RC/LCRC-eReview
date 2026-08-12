/**
 * LMS student presence / location / video progress (HTML5 + YouTube + Vimeo).
 */
(function () {
  'use strict';
  var cfg = window.EreviewStudentActivity || {};
  var endpoint = cfg.endpoint || 'student_activity_api';
  var csrf = cfg.csrf || '';
  var base = {
    page_key: cfg.page_key || (document.body && document.body.getAttribute('data-page')) || '',
    page_title: cfg.page_title || document.title || '',
    page_url: cfg.page_url || (window.location.pathname + window.location.search),
    subject_id: cfg.subject_id || 0,
    lesson_id: cfg.lesson_id || 0,
    quiz_id: cfg.quiz_id || 0,
    video_id: cfg.video_id || 0,
    attempt_id: cfg.attempt_id || 0,
    resume_sec: parseFloat(cfg.resume_sec || 0) || 0,
    csrf_token: csrf
  };

  var skipResume = false;
  function resumeSecondsFor(el) {
    if (skipResume) return 0;
    var fromEl = parseFloat(el && el.getAttribute ? (el.getAttribute('data-resume-sec') || '0') : '0');
    if (fromEl > 0) return fromEl;
    return base.resume_sec > 0 ? base.resume_sec : 0;
  }

  document.addEventListener('click', function (e) {
    var btn = e.target && e.target.closest ? e.target.closest('#video-resume-restart') : null;
    if (!btn) return;
    e.preventDefault();
    skipResume = true;
    document.querySelectorAll('[data-resume-sec]').forEach(function (n) {
      n.removeAttribute('data-resume-sec');
    });
    var banner = document.getElementById('video-resume-banner');
    if (banner) banner.remove();
    // Reload players from 0
    document.querySelectorAll('video[data-activity-video]').forEach(function (vid) {
      try { vid.currentTime = 0; } catch (err) {}
    });
    document.querySelectorAll('iframe[data-activity-vimeo]').forEach(function (iframe) {
      if (iframe.__ereviewVimeoPlayer) {
        iframe.__ereviewVimeoPlayer.setCurrentTime(0).catch(function () {});
      }
    });
  });

  function post(action, extra) {
    var body = Object.assign({ action: action }, base, extra || {});
    try {
      if (navigator.sendBeacon && action === 'heartbeat') {
        var blob = new Blob([JSON.stringify(body)], { type: 'application/json' });
        navigator.sendBeacon(endpoint, blob);
        return;
      }
    } catch (e) {}
    fetch(endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify(body),
      keepalive: true
    }).catch(function () {});
  }

  function loadScript(src, cb) {
    var existing = document.querySelector('script[src="' + src + '"]');
    if (existing) {
      if (cb) setTimeout(cb, 0);
      return;
    }
    var s = document.createElement('script');
    s.src = src;
    s.async = true;
    s.onload = function () { if (cb) cb(); };
    s.onerror = function () { if (cb) cb(new Error('load failed')); };
    document.head.appendChild(s);
  }

  function makeProgressReporter(getVideoId) {
    var lastSent = 0;
    var pendingWatch = 0;
    var lastTick = 0;
    var lastPos = 0;
    var playing = false;

    function markPlaying(isPlaying, pos) {
      playing = !!isPlaying;
      if (playing) {
        lastTick = Date.now();
        lastPos = typeof pos === 'number' ? pos : lastPos;
      } else {
        lastTick = 0;
      }
    }

    function accumulate(pos) {
      if (!playing || document.hidden) {
        lastTick = 0;
        return;
      }
      var now = Date.now();
      if (!lastTick) {
        lastTick = now;
        lastPos = pos;
        return;
      }
      var wall = (now - lastTick) / 1000;
      // Credit wall-clock while playing (Vimeo/YouTube pings can be sparse).
      if (wall > 0 && wall <= 12) {
        pendingWatch += wall;
      }
      lastTick = now;
      lastPos = pos;
    }

    function send(force, pos, duration, isPlayingFlag) {
      var videoId = parseInt(getVideoId() || base.video_id || '0', 10);
      if (!videoId) return;
      if (typeof isPlayingFlag === 'boolean') playing = isPlayingFlag;
      accumulate(pos || 0);
      var now = Date.now();
      if (!force && now - lastSent < 2500) return;
      var delta = pendingWatch;
      pendingWatch = 0;
      lastSent = now;
      post('video_progress', {
        video_id: videoId,
        position_sec: pos || 0,
        duration_sec: duration != null && isFinite(duration) ? duration : null,
        watch_delta_sec: delta,
        is_playing: playing && !document.hidden ? 1 : 0,
        lesson_id: base.lesson_id,
        subject_id: base.subject_id
      });
    }

    return { markPlaying: markPlaying, send: send, accumulate: accumulate };
  }

  post('page_view');
  // Faster presence while on tracked pages so Live board feels real-time
  setInterval(function () { post('heartbeat'); }, 15000);

  function bindHtml5() {
    document.querySelectorAll('video[data-activity-video], video.video-embed[data-video-id]').forEach(function (vid) {
      if (vid.__ereviewActivityBound) return;
      vid.__ereviewActivityBound = true;
      var rep = makeProgressReporter(function () {
        return vid.getAttribute('data-video-id') || base.video_id || '0';
      });
      var resumeAt = resumeSecondsFor(vid);
      if (resumeAt > 0) {
        var applyResume = function () {
          try {
            if (!skipResume && Math.abs((vid.currentTime || 0) - resumeAt) > 1.5) {
              vid.currentTime = resumeAt;
            }
          } catch (err) {}
        };
        if (vid.readyState >= 1) applyResume();
        else vid.addEventListener('loadedmetadata', applyResume, { once: true });
      }
      vid.addEventListener('play', function () {
        rep.markPlaying(true, vid.currentTime || 0);
        rep.send(true, vid.currentTime || 0, vid.duration, true);
      });
      vid.addEventListener('pause', function () {
        rep.send(true, vid.currentTime || 0, vid.duration, false);
        rep.markPlaying(false);
      });
      vid.addEventListener('ended', function () {
        rep.send(true, vid.currentTime || 0, vid.duration, false);
        rep.markPlaying(false);
      });
      vid.addEventListener('timeupdate', function () {
        rep.send(false, vid.currentTime || 0, vid.duration, !vid.paused && !vid.ended);
      });
      setInterval(function () {
        if (!vid.paused && !vid.ended) {
          rep.send(true, vid.currentTime || 0, vid.duration, true);
        }
      }, 3000);
    });
  }

  function bindVimeo() {
    var nodes = document.querySelectorAll('iframe[data-activity-vimeo]');
    if (!nodes.length) return;
    loadScript('https://player.vimeo.com/api/player.js', function (err) {
      if (err || !window.Vimeo || !window.Vimeo.Player) return;
      nodes.forEach(function (iframe) {
        if (iframe.__ereviewActivityBound) return;
        iframe.__ereviewActivityBound = true;
        var player;
        try {
          player = new window.Vimeo.Player(iframe);
        } catch (e) {
          return;
        }
        iframe.__ereviewVimeoPlayer = player;
        var rep = makeProgressReporter(function () {
          return iframe.getAttribute('data-video-id') || base.video_id || '0';
        });
        var duration = null;
        var resumeAt = resumeSecondsFor(iframe);
        player.getDuration().then(function (d) { duration = d; }).catch(function () {});
        if (resumeAt > 0) {
          player.setCurrentTime(resumeAt).catch(function () {});
        }
        player.on('play', function () {
          player.getCurrentTime().then(function (t) {
            rep.markPlaying(true, t);
            rep.send(true, t, duration, true);
          });
        });
        player.on('pause', function () {
          player.getCurrentTime().then(function (t) {
            rep.send(true, t, duration, false);
            rep.markPlaying(false);
          });
        });
        player.on('ended', function () {
          player.getCurrentTime().then(function (t) {
            rep.send(true, t, duration, false);
            rep.markPlaying(false);
          });
        });
        player.on('timeupdate', function (data) {
          if (data && data.duration) duration = data.duration;
          rep.send(false, (data && data.seconds) || 0, duration, true);
        });
        // Hard poll every 3s while this tab is open (keeps Live board current)
        setInterval(function () {
          player.getPaused().then(function (paused) {
            player.getCurrentTime().then(function (t) {
              player.getDuration().then(function (d) {
                if (d) duration = d;
                rep.send(true, t, duration, !paused);
              }).catch(function () {
                rep.send(true, t, duration, !paused);
              });
            });
          }).catch(function () {});
        }, 3000);
      });
    });
  }

  function bindYoutube() {
    var nodes = document.querySelectorAll('iframe[data-activity-youtube]');
    if (!nodes.length) return;

    function attachAll() {
      nodes.forEach(function (iframe) {
        if (iframe.__ereviewActivityBound) return;
        iframe.__ereviewActivityBound = true;
        if (!iframe.id) {
          iframe.id = 'yt_act_' + Math.random().toString(36).slice(2);
        }
        var rep = makeProgressReporter(function () {
          return iframe.getAttribute('data-video-id') || base.video_id || '0';
        });
        var player = new window.YT.Player(iframe.id, {
          events: {
            onReady: function (e) {
              var resumeAt = resumeSecondsFor(iframe);
              if (resumeAt > 0) {
                try { e.target.seekTo(resumeAt, true); } catch (err) {}
              }
              setInterval(function () {
                try {
                  var st = e.target.getPlayerState();
                  var playing = st === window.YT.PlayerState.PLAYING;
                  var t = e.target.getCurrentTime() || 0;
                  var d = e.target.getDuration() || null;
                  if (playing) {
                    rep.markPlaying(true, t);
                    rep.send(true, t, d, true);
                  } else {
                    rep.send(false, t, d, false);
                  }
                } catch (err) {}
              }, 5000);
            },
            onStateChange: function (e) {
              try {
                var t = e.target.getCurrentTime() || 0;
                var d = e.target.getDuration() || null;
                var playing = e.data === window.YT.PlayerState.PLAYING;
                rep.markPlaying(playing, t);
                rep.send(true, t, d, playing);
              } catch (err) {}
            }
          }
        });
        void player;
      });
    }

    if (window.YT && window.YT.Player) {
      attachAll();
      return;
    }
    var prev = window.onYouTubeIframeAPIReady;
    window.onYouTubeIframeAPIReady = function () {
      if (typeof prev === 'function') prev();
      attachAll();
    };
    loadScript('https://www.youtube.com/iframe_api');
  }

  function bindAll() {
    bindHtml5();
    bindVimeo();
    bindYoutube();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bindAll);
  } else {
    bindAll();
  }

  if (cfg.attempt_id) {
    setInterval(function () {
      post('quiz_heartbeat', { attempt_id: cfg.attempt_id, quiz_id: cfg.quiz_id });
    }, 20000);
    document.addEventListener('visibilitychange', function () {
      if (document.hidden) {
        post('quiz_heartbeat', { attempt_id: cfg.attempt_id, quiz_id: cfg.quiz_id, tab_switch: 1 });
      }
    });
  }

  window.EreviewStudentActivityPing = post;
})();
