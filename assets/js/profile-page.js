/**
 * Profile page: section scroll-spy, chip nav, copy-to-clipboard, modal triggers.
 */
(function () {
  'use strict';

  var root = document.querySelector('.ere-prof[data-ere-profile-root]');
  if (!root) return;

  var chips = root.querySelectorAll('[data-ere-prof-chip]');
  var spyObserver = null;
  var ratioMap = {};
  var sectionConfig = [];

  function setActiveChip(id) {
    chips.forEach(function (btn) {
      var on = btn.id === id;
      btn.classList.toggle('is-active', on);
      btn.setAttribute('aria-current', on ? 'true' : 'false');
    });
  }

  function scrollToSection(sectionId) {
    var el = document.getElementById(sectionId);
    if (!el) return;
    var chipWrap = root.querySelector('.ere-prof__chip-wrap');
    var extra = chipWrap ? chipWrap.offsetHeight + 12 : 72;
    var rect = el.getBoundingClientRect();
    var top = window.scrollY + rect.top - extra;
    window.scrollTo({ top: Math.max(0, top), behavior: 'smooth' });
  }

  function installSpy() {
    if (!window.IntersectionObserver) return;
    if (spyObserver) {
      try {
        spyObserver.disconnect();
      } catch (e) {}
      spyObserver = null;
    }
    sectionConfig = [];
    chips.forEach(function (btn) {
      var tid = btn.getAttribute('data-ere-prof-target');
      if (tid) {
        sectionConfig.push({ id: tid, chip: btn.id });
        ratioMap[tid] = 0;
      }
    });
    if (!sectionConfig.length) return;
    spyObserver = new IntersectionObserver(
      function (entries) {
        entries.forEach(function (en) {
          var tid = en.target.id;
          if (Object.prototype.hasOwnProperty.call(ratioMap, tid)) {
            ratioMap[tid] = en.isIntersecting ? en.intersectionRatio : 0;
          }
        });
        var bestChip = sectionConfig[0].chip;
        var best = -1;
        sectionConfig.forEach(function (m) {
          if (ratioMap[m.id] > best) {
            best = ratioMap[m.id];
            bestChip = m.chip;
          }
        });
        if (best > 0.04) setActiveChip(bestChip);
      },
      {
        root: null,
        threshold: [0, 0.08, 0.2, 0.35, 0.55, 0.75, 1]
      }
    );
    sectionConfig.forEach(function (m) {
      var el = document.getElementById(m.id);
      if (el) spyObserver.observe(el);
    });
  }

  chips.forEach(function (btn) {
    btn.addEventListener('click', function () {
      var tid = btn.getAttribute('data-ere-prof-target');
      if (tid) {
        setActiveChip(btn.id);
        scrollToSection(tid);
      }
    });
  });

  root.querySelectorAll('[data-ere-prof-copy]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var text = btn.getAttribute('data-ere-prof-copy') || '';
      if (!text) return;
      function markCopied() {
        btn.classList.add('is-copied');
        var prev = btn.getAttribute('aria-label') || '';
        btn.setAttribute('data-ere-prev-aria', prev);
        btn.setAttribute('aria-label', 'Copied');
        setTimeout(function () {
          btn.classList.remove('is-copied');
          var p = btn.getAttribute('data-ere-prev-aria');
          if (p) btn.setAttribute('aria-label', p);
          btn.removeAttribute('data-ere-prev-aria');
        }, 1600);
      }
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(markCopied).catch(function () {});
      } else {
        try {
          var ta = document.createElement('textarea');
          ta.value = text;
          ta.setAttribute('readonly', '');
          ta.style.position = 'fixed';
          ta.style.left = '-9999px';
          document.body.appendChild(ta);
          ta.select();
          document.execCommand('copy');
          document.body.removeChild(ta);
          markCopied();
        } catch (e) {}
      }
    });
  });

  var coverTrigger = root.querySelector('[data-ere-cover-trigger]');
  var coverInput = root.querySelector('[data-ere-cover-input]');
  if (coverTrigger && coverInput) {
    coverTrigger.addEventListener('click', function () {
      try { coverInput.click(); } catch (e) {}
    });
    coverInput.addEventListener('change', function () {
      if (!coverInput.files || !coverInput.files.length) return;
      var form = coverInput.closest('form');
      if (form) form.submit();
    });
  }

  function openEdit() {
    if (window.ereviewOpenProfileEditModal) window.ereviewOpenProfileEditModal();
  }

  var editBtn = document.getElementById(
    root.getAttribute('data-ere-edit-btn-id') || 'ereviewProfilePageEditBtn'
  );
  var pwBtn = document.getElementById(
    root.getAttribute('data-ere-pw-btn-id') || 'ereviewProfilePageEditPwBtn'
  );
  if (editBtn) editBtn.addEventListener('click', openEdit);
  if (pwBtn) pwBtn.addEventListener('click', openEdit);

  root.querySelectorAll('[data-ere-prof-avatar-edit]').forEach(function (btn) {
    btn.addEventListener('click', openEdit);
  });

  try {
    var sp = new URLSearchParams(window.location.search);
    if (sp.get('edit') === '1' && window.ereviewOpenProfileEditModal) {
      window.ereviewOpenProfileEditModal();
      sp.delete('edit');
      var qs = sp.toString();
      var nu = window.location.pathname + (qs ? '?' + qs : '') + window.location.hash;
      window.history.replaceState({}, '', nu);
    }
  } catch (e1) {}

  window.addEventListener('ereview-profile-updated', function (ev) {
    if (ev.detail && ev.detail.ereviewStayOpen) return;
    root.classList.add('is-loading');
    fetch('api/profile/get_profile.php', { credentials: 'same-origin' })
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        root.classList.remove('is-loading');
        if (data && data.ok) window.location.reload();
      })
      .catch(function () {
        root.classList.remove('is-loading');
        window.location.reload();
      });
  });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', installSpy);
  } else {
    installSpy();
  }

  (function installProfileDebugTools() {
    if (root.getAttribute('data-ere-profile-theme') !== 'student') return;
    var debugUrl = root.getAttribute('data-ere-debug-url') || 'api/profile/debug_student_profile.php';
    if (!debugUrl) return;
    function syncVisibleStudentFields(data) {
      if (!data || !data.ok || !data.debug || !data.debug.display) return;
      var display = data.debug.display;
      var reviewEl = document.getElementById('ereProfStatReviewType');
      var schoolEl = document.getElementById('ereProfStatSchool');
      var proofEl = document.getElementById('ereProfStatProof');
      if (reviewEl && display.review_type) {
        reviewEl.textContent = String(display.review_type);
      }
      if (schoolEl && display.school) {
        schoolEl.textContent = String(display.school);
      }
      if (proofEl) {
        var hasProof = !!display.has_payment_proof;
        if (hasProof) {
          var link = document.createElement('a');
          link.className = 'ere-prof__proof-link';
          link.id = 'ereProfProofLink';
          link.href = 'student_payment_proof.php';
          link.target = '_blank';
          link.rel = 'noopener';
          link.innerHTML = '<i class="bi bi-receipt-cutoff" aria-hidden="true"></i> View file';
          proofEl.innerHTML = '';
          proofEl.appendChild(link);
        } else {
          proofEl.textContent = 'Not uploaded';
        }
      }
    }

    function fetchProfileDebug() {
      return fetch(debugUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then(function (r) { return r.json(); });
    }

    fetchProfileDebug().then(syncVisibleStudentFields).catch(function () {});

    window.ereProfileDebug = {
      url: debugUrl,
      run: function () {
        return fetchProfileDebug()
          .then(function (data) {
            if (!data || !data.ok || !data.debug) {
              console.error('[ereProfileDebug] Unexpected response:', data);
              return data;
            }
            syncVisibleStudentFields(data);
            var dbg = data.debug;
            console.groupCollapsed('%c[ereProfileDebug] Student Profile Snapshot', 'color:#1665a0;font-weight:700;');
            console.log('Queried at:', dbg.queried_at);
            console.log('User ID:', dbg.user_id);
            console.log('Raw DB values:');
            console.table(dbg.raw || {});
            console.log('UI display mapping:');
            console.table(dbg.display || {});
            console.groupEnd();
            return data;
          })
          .catch(function (err) {
            console.error('[ereProfileDebug] Failed:', err);
            throw err;
          });
      },
      help: function () {
        console.info('Run `ereProfileDebug.run()` to print raw DB values and UI mapping.');
      }
    };
    console.info('[ereProfileDebug] Ready. Run `ereProfileDebug.run()` in console.');
  })();
})();
