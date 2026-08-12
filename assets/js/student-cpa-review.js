/**
 * My CPA Review client helpers (toggles, note modal, mistake CTA).
 */
(function () {
  'use strict';

  var cfg = window.CPA_REVIEW || {};
  var apiUrl = cfg.apiUrl || 'student_cpa_review_api';
  var csrf = cfg.csrf || '';

  function post(action, data) {
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

  function setBtnActive(btn, active, onLabel, offLabel) {
    if (!btn) return;
    btn.classList.toggle('is-active', !!active);
    btn.setAttribute('aria-pressed', active ? 'true' : 'false');
    var label = btn.querySelector('[data-cpa-label]');
    if (label) label.textContent = active ? onLabel : offLabel;
  }

  document.addEventListener('click', function (e) {
    var t = e.target.closest('[data-cpa-action]');
    if (!t) return;
    var action = t.getAttribute('data-cpa-action');
    if (action === 'bookmark_toggle') {
      e.preventDefault();
      t.disabled = true;
      post('bookmark_toggle', {
        item_type: t.getAttribute('data-item-type') || 'lesson',
        item_id: parseInt(t.getAttribute('data-item-id') || '0', 10),
        title: t.getAttribute('data-title') || '',
        url: t.getAttribute('data-url') || window.location.href,
        subject_id: parseInt(t.getAttribute('data-subject-id') || '0', 10),
        lesson_id: parseInt(t.getAttribute('data-lesson-id') || '0', 10),
      }).then(function (res) {
        t.disabled = false;
        if (res && res.ok) {
          setBtnActive(t, res.bookmarked, 'Bookmarked', 'Bookmark');
          var row = t.closest('[data-cpa-row]');
          if (row && !res.bookmarked) row.remove();
        } else {
          alert((res && res.error) || 'Could not update bookmark');
        }
      });
      return;
    }
    if (action === 'favorite_toggle') {
      e.preventDefault();
      t.disabled = true;
      post('favorite_toggle', {
        item_type: t.getAttribute('data-item-type') || 'lesson',
        item_id: parseInt(t.getAttribute('data-item-id') || '0', 10),
        title: t.getAttribute('data-title') || '',
        url: t.getAttribute('data-url') || window.location.href,
        subject_id: parseInt(t.getAttribute('data-subject-id') || '0', 10),
        lesson_id: parseInt(t.getAttribute('data-lesson-id') || '0', 10),
      }).then(function (res) {
        t.disabled = false;
        if (res && res.ok) {
          setBtnActive(t, res.favorited, 'Favorited', 'Favorite');
          var row = t.closest('[data-cpa-row]');
          if (row && !res.favorited) row.remove();
        } else {
          alert((res && res.error) || 'Could not update favorite');
        }
      });
      return;
    }
    if (action === 'open_note_modal') {
      e.preventDefault();
      openNoteModal();
      return;
    }
    if (action === 'open_concept_modal') {
      e.preventDefault();
      openConceptModal();
      return;
    }
    if (action === 'mistake_add') {
      e.preventDefault();
      t.disabled = true;
      var explEl = t.closest('.review-item-wrong');
      var expl = '';
      if (explEl) {
        var explNode = explEl.querySelector('.quiz-rich-text');
        if (explNode) expl = explNode.textContent || '';
      }
      post('mistake_add', {
        question_id: parseInt(t.getAttribute('data-question-id') || '0', 10),
        attempt_id: parseInt(t.getAttribute('data-attempt-id') || '0', 10),
        quiz_id: parseInt(t.getAttribute('data-quiz-id') || '0', 10),
        subject_id: parseInt(t.getAttribute('data-subject-id') || '0', 10),
        selected_answer: t.getAttribute('data-selected') || '',
        correct_answer: t.getAttribute('data-correct') || '',
        explanation: (t.getAttribute('data-explanation') || expl || '').slice(0, 4000),
      }).then(function (res) {
        t.disabled = false;
        if (res && res.ok) {
          t.classList.add('is-active');
          var lab = t.querySelector('[data-cpa-label]');
          if (lab) lab.textContent = 'Saved to Mistakes';
          t.setAttribute('disabled', 'disabled');
        } else {
          alert((res && res.error) || 'Could not save mistake');
        }
      });
      return;
    }
    if (
      action === 'mistake_reviewed' ||
      action === 'mistake_delete' ||
      action === 'note_delete' ||
      action === 'quick_delete' ||
      action === 'important_toggle' ||
      action === 'concept_delete' ||
      action === 'concept_last_minute'
    ) {
      e.preventDefault();
      var confirmMsg = t.getAttribute('data-confirm');
      if (confirmMsg && !window.confirm(confirmMsg)) return;
      t.disabled = true;
      var payload = {};
      Array.prototype.forEach.call(t.attributes, function (attr) {
        if (attr.name.indexOf('data-') === 0 && attr.name !== 'data-cpa-action' && attr.name !== 'data-confirm') {
          var key = attr.name.replace(/^data-/, '').replace(/-/g, '_');
          payload[key] = attr.value;
        }
      });
      if (action === 'mistake_reviewed') {
        payload.is_reviewed = t.getAttribute('data-is-reviewed') || '1';
      }
      if (action === 'concept_last_minute') {
        payload.is_last_minute = t.getAttribute('data-is-last-minute') || '1';
      }
      post(action, payload).then(function (res) {
        t.disabled = false;
        if (res && res.ok) {
          var row = t.closest('[data-cpa-row]');
          if (action.indexOf('delete') !== -1 && row) {
            row.remove();
            if (!document.querySelector('[data-cpa-row]')) window.location.reload();
          } else {
            window.location.reload();
          }
        } else {
          alert((res && res.error) || 'Action failed');
        }
      });
    }
  });

  function openNoteModal() {
    var modal = document.getElementById('cpa-note-modal');
    if (!modal) return;
    modal.hidden = false;
    modal.setAttribute('aria-hidden', 'false');
    var title = document.getElementById('cpa-note-title');
    if (title) title.focus();
  }

  function closeNoteModal() {
    var modal = document.getElementById('cpa-note-modal');
    if (!modal) return;
    modal.hidden = true;
    modal.setAttribute('aria-hidden', 'true');
  }

  function openConceptModal() {
    var modal = document.getElementById('cpa-concept-modal');
    if (!modal) return;
    modal.hidden = false;
    modal.setAttribute('aria-hidden', 'false');
    var title = document.getElementById('cpa-concept-title');
    if (title) title.focus();
  }

  function closeConceptModal() {
    var modal = document.getElementById('cpa-concept-modal');
    if (!modal) return;
    modal.hidden = true;
    modal.setAttribute('aria-hidden', 'true');
  }

  document.addEventListener('click', function (e) {
    if (e.target.closest('[data-cpa-close]')) {
      closeNoteModal();
    }
    if (e.target.closest('[data-cpa-concept-close]')) {
      closeConceptModal();
    }
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      closeNoteModal();
      closeConceptModal();
    }
  });

  var conceptForm = document.getElementById('cpa-concept-form');
  if (conceptForm) {
    conceptForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var status = document.getElementById('cpa-concept-status');
      var data = {
        title: (document.getElementById('cpa-concept-title') || {}).value || '',
        topic: (document.getElementById('cpa-concept-topic') || {}).value || '',
        body: (document.getElementById('cpa-concept-body') || {}).value || '',
        subject_id: parseInt((document.getElementById('cpa-concept-subject') || {}).value || '0', 10),
        lesson_id: parseInt((document.getElementById('cpa-concept-lesson') || {}).value || '0', 10),
        is_last_minute: document.getElementById('cpa-concept-last-minute') && document.getElementById('cpa-concept-last-minute').checked ? 1 : 0,
      };
      if (status) status.textContent = 'Saving…';
      post('concept_save', data).then(function (res) {
        if (res && res.ok) {
          if (status) status.textContent = 'Saved to Important Concepts.';
          setTimeout(closeConceptModal, 450);
        } else {
          if (status) status.textContent = (res && res.error) || 'Save failed';
        }
      });
    });
  }

  var form = document.getElementById('cpa-note-form');
  if (form) {
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var status = document.getElementById('cpa-note-status');
      var editor = document.getElementById('cpa-note-content');
      var data = {
        note_id: parseInt((document.getElementById('cpa-note-id') || {}).value || '0', 10),
        subject_id: parseInt((document.getElementById('cpa-note-subject') || {}).value || '0', 10),
        lesson_id: parseInt((document.getElementById('cpa-note-lesson') || {}).value || '0', 10),
        title: (document.getElementById('cpa-note-title') || {}).value || '',
        content: editor ? editor.innerHTML : '',
        tags: (document.getElementById('cpa-note-tags') || {}).value || '',
        is_starred: document.getElementById('cpa-note-starred') && document.getElementById('cpa-note-starred').checked ? 1 : 0,
      };
      if (status) status.textContent = 'Saving…';
      post('note_save', data).then(function (res) {
        if (res && res.ok) {
          if (status) status.textContent = 'Saved.';
          var idEl = document.getElementById('cpa-note-id');
          if (idEl && res.note_id) idEl.value = String(res.note_id);
          setTimeout(closeNoteModal, 400);
          if (cfg.reloadOnSave) window.location.reload();
        } else {
          if (status) status.textContent = (res && res.error) || 'Save failed';
        }
      });
    });

    var autosaveTimer = null;
    var editor = document.getElementById('cpa-note-content');
    function scheduleAutosave() {
      var idEl = document.getElementById('cpa-note-id');
      if (!idEl || parseInt(idEl.value || '0', 10) <= 0) return;
      clearTimeout(autosaveTimer);
      autosaveTimer = setTimeout(function () {
        form.dispatchEvent(new Event('submit', { cancelable: true }));
      }, 1200);
    }
    if (editor) {
      editor.addEventListener('input', scheduleAutosave);
    }
  }

  document.querySelectorAll('.cpa-editor-toolbar [data-cmd]').forEach(function (btn) {
    btn.addEventListener('click', function (e) {
      e.preventDefault();
      var cmd = btn.getAttribute('data-cmd');
      var val = btn.getAttribute('data-value') || null;
      if (cmd === 'createLink') {
        var url = window.prompt('Link URL');
        if (!url) return;
        document.execCommand('createLink', false, url);
        return;
      }
      if (cmd === 'formatBlock' && val) {
        document.execCommand('formatBlock', false, val);
        return;
      }
      document.execCommand(cmd, false, null);
    });
  });

  window.CPA_REVIEW.post = post;
  window.CPA_REVIEW.openNoteModal = openNoteModal;
  window.CPA_REVIEW.openConceptModal = openConceptModal;
})();
