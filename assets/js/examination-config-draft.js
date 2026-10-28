/**
 * Professor Examination config draft recovery (localStorage).
 * Does not create server exams. Never stores csrf/password fields.
 */
(function (global) {
  'use strict';
  var VERSION = 1;
  var PREFIX = 'ereview_exam_cfg_v1:';
  var BUNDLE = ':bundle';
  var SKIP = { csrf_token: 1, action: 1, save_action: 1, modal: 1 };
  var DEBOUNCE_MS = 800;
  var timer = null;
  var boundForm = null;
  var boundGen = 0;
  var restoreOpen = false;
  var lastWrittenAt = 0;
  var persistOk = true;

  function metaFromForm(form) {
    return {
      professorId: String(form.getAttribute('data-professor-id') || '0'),
      identity: String(form.getAttribute('data-exam-identity') || 'new'),
      examType: String(form.getAttribute('data-exam-type') || 'regular'),
      isNew: form.getAttribute('data-is-new') === '1',
      serverUpdated: String(form.getAttribute('data-server-updated') || ''),
      stashApplied: form.getAttribute('data-stash-applied') === '1'
    };
  }

  function typeKey(meta, examType) {
    return PREFIX + meta.professorId + ':' + meta.identity + ':' + (examType || meta.examType);
  }

  function bundleKey(meta) {
    return PREFIX + meta.professorId + ':' + meta.identity + BUNDLE;
  }

  function readJson(key) {
    try {
      var raw = global.localStorage.getItem(key);
      if (!raw) return null;
      var data = JSON.parse(raw);
      if (!data || data.v !== VERSION || typeof data !== 'object') return null;
      if (!data.values || typeof data.values !== 'object') return null;
      if (typeof data.savedAt !== 'number') return null;
      return data;
    } catch (e) {
      return null;
    }
  }

  function writeJson(key, data) {
    try {
      global.localStorage.setItem(key, JSON.stringify(data));
      return true;
    } catch (e) {
      return false;
    }
  }

  function collect(form) {
    var values = {};
    var names = {};
    Array.prototype.forEach.call(form.elements, function (el) {
      if (!el || !el.name || el.disabled) return;
      var n = el.name;
      if (SKIP[n] || /password|token|csrf/i.test(n)) return;
      if (el.type === 'file') return;
      names[n] = (names[n] || 0) + 1;
    });
    Array.prototype.forEach.call(form.elements, function (el) {
      if (!el || !el.name || el.disabled) return;
      var n = el.name;
      if (SKIP[n] || /password|token|csrf/i.test(n)) return;
      if (el.type === 'file') return;
      if (el.type === 'radio') {
        if (el.checked) values[n] = el.value;
        return;
      }
      if (el.type === 'checkbox') {
        if (n.slice(-2) === '[]' || names[n] > 1) {
          if (!Array.isArray(values[n])) values[n] = [];
          if (el.checked) values[n].push(el.value);
        } else {
          values[n] = el.checked ? (el.value || '1') : '';
        }
        return;
      }
      if (el.tagName === 'SELECT' && el.multiple) {
        values[n] = Array.prototype.map.call(el.selectedOptions, function (o) { return o.value; });
        return;
      }
      if (n.slice(-2) === '[]' || names[n] > 1) {
        if (!Array.isArray(values[n])) values[n] = [];
        values[n].push(el.value);
        return;
      }
      values[n] = el.value;
    });
    return values;
  }

  function isEmptyValues(values) {
    var keys = Object.keys(values || {});
    if (!keys.length) return true;
    return keys.every(function (k) {
      var v = values[k];
      if (Array.isArray(v)) return v.filter(function (x) { return String(x || '').trim() !== ''; }).length === 0;
      return String(v || '').trim() === '';
    });
  }

  function statusEl(form) {
    return document.getElementById('examConfigDraftStatus');
  }

  function setStatus(form, state, label) {
    var el = statusEl(form);
    if (!el) return;
    el.textContent = label;
    el.setAttribute('data-state', state);
  }

  function persist(form, silent) {
    var meta = metaFromForm(form);
    if (meta.professorId === '0') return false;
    var values = collect(form);
    var payload = {
      v: VERSION,
      savedAt: Date.now(),
      examType: meta.examType,
      identity: meta.identity,
      professorId: meta.professorId,
      values: values
    };
    var ok = writeJson(typeKey(meta), payload);
    var bundle = readJson(bundleKey(meta)) || { v: VERSION, savedAt: Date.now(), values: { types: {} } };
    if (!bundle.values || typeof bundle.values !== 'object') bundle.values = { types: {} };
    if (!bundle.values.types || typeof bundle.values.types !== 'object') bundle.values.types = {};
    bundle.values.types[meta.examType] = values;
    bundle.values.lastType = meta.examType;
    bundle.savedAt = payload.savedAt;
    bundle.professorId = meta.professorId;
    bundle.identity = meta.identity;
    ok = writeJson(bundleKey(meta), bundle) && ok;
    persistOk = ok;
    if (ok) lastWrittenAt = payload.savedAt;
    if (!silent) {
      setStatus(form, ok ? 'saved' : 'error', ok ? 'Draft saved' : 'Draft not saved');
    } else if (!ok) {
      setStatus(form, 'error', 'Draft not saved');
    }
    return ok;
  }

  function schedule(form) {
    setStatus(form, 'unsaved', 'Unsaved');
    if (timer) clearTimeout(timer);
    timer = setTimeout(function () {
      timer = null;
      setStatus(form, 'saving', 'Saving…');
      persist(form, false);
    }, DEBOUNCE_MS);
  }

  function flush(form) {
    if (timer) {
      clearTimeout(timer);
      timer = null;
    }
    if (form) persist(form, true);
  }

  function ensureSectionRows(form, count) {
    var list = form.querySelector('#sectionsList');
    if (!list) return;
    var addBtn = document.getElementById('addSectionBtn');
    var rows = list.querySelectorAll('.section-row');
    var i;
    for (i = rows.length; i < count; i++) {
      if (addBtn) addBtn.click();
    }
  }

  function ensureSubjectRows(form, count) {
    var list = form.querySelector('#regSubjectList');
    var addBtn = document.getElementById('regAddSubjectBtn');
    if (!list || !addBtn) return;
    var i;
    for (i = list.querySelectorAll('[data-reg-subject]').length; i < count; i++) {
      addBtn.click();
    }
  }

  function ensureTopicRows(sub, count) {
    var addBtn = sub.querySelector('[data-reg-add-topic]');
    if (!addBtn) return;
    var i;
    for (i = sub.querySelectorAll('[data-reg-topic]').length; i < count; i++) {
      addBtn.click();
    }
  }

  function maxIndex(values, re) {
    var max = -1;
    Object.keys(values).forEach(function (k) {
      var m = k.match(re);
      if (m) max = Math.max(max, parseInt(m[1], 10));
    });
    return max;
  }

  function namedNodes(form, name) {
    return Array.prototype.filter.call(form.elements, function (el) {
      return el && el.name === name;
    });
  }

  function setControl(form, name, value) {
    var nodes = namedNodes(form, name);
    if (!nodes.length) return;
    var first = nodes[0];
    if (first.type === 'radio') {
      Array.prototype.forEach.call(nodes, function (n) {
        n.checked = String(n.value) === String(value);
      });
      return;
    }
    if (first.type === 'checkbox') {
      if (Array.isArray(value)) {
        var set = {};
        value.forEach(function (v) { set[String(v)] = true; });
        Array.prototype.forEach.call(nodes, function (n) {
          n.checked = !!set[String(n.value)];
        });
      } else {
        first.checked = value === '1' || value === true || value === first.value;
      }
      return;
    }
    if (Array.isArray(value)) {
      Array.prototype.forEach.call(nodes, function (n, i) {
        if (value[i] != null) n.value = value[i];
      });
      return;
    }
    first.value = value == null ? '' : String(value);
  }

  function fire(el) {
    if (!el) return;
    el.dispatchEvent(new Event('change', { bubbles: true }));
  }

  function applyValues(form, values) {
    if (!values) return;
    if (values.examinee_scope != null) {
      setControl(form, 'examinee_scope', values.examinee_scope);
      fire(form.querySelector('input[name="examinee_scope"]:checked'));
    }
    if (values.assignment_mode != null) {
      setControl(form, 'assignment_mode', values.assignment_mode);
      fire(form.querySelector('input[name="assignment_mode"]:checked:not([disabled])'));
    }
    if (values.question_breakdown_mode != null) {
      setControl(form, 'question_breakdown_mode', values.question_breakdown_mode);
      fire(form.querySelector('input[name="question_breakdown_mode"]:checked'));
    }
    var sectionVals = values['sections[]'];
    if (Array.isArray(sectionVals)) ensureSectionRows(form, sectionVals.length);
    var subMax = maxIndex(values, /^reg_subjects\[(\d+)\]/);
    if (subMax >= 0) ensureSubjectRows(form, subMax + 1);
    var subjects = form.querySelectorAll('#regSubjectList [data-reg-subject]');
    subjects.forEach(function (sub, si) {
      var tMax = maxIndex(values, new RegExp('^reg_subjects\\[' + si + '\\]\\[topics\\]\\[(\\d+)\\]'));
      if (tMax >= 0) ensureTopicRows(sub, tMax + 1);
    });
    Object.keys(values).forEach(function (name) {
      if (name === 'examinee_scope' || name === 'assignment_mode' || name === 'question_breakdown_mode' || name === 'exam_type_choice') {
        return;
      }
      setControl(form, name, values[name]);
    });
    form.querySelectorAll('[data-diag-subject-qty]').forEach(function (qty) {
      qty.dispatchEvent(new Event('input', { bubbles: true }));
    });
  }

  function sharedFrom(values) {
    var keep = [
      'title', 'description', 'examinee_scope', 'assignment_mode', 'sections[]', 'user_ids[]',
      'available_from', 'deadline', 'time_limit_hours', 'time_limit_minutes',
      'shuffle_questions', 'shuffle_choices'
    ];
    var out = {};
    keep.forEach(function (k) {
      if (Object.prototype.hasOwnProperty.call(values, k)) out[k] = values[k];
    });
    return out;
  }

  function mergeTypeSwitch(form) {
    var meta = metaFromForm(form);
    var flag = null;
    try { flag = global.sessionStorage.getItem(PREFIX + 'type_switch'); } catch (e) {}
    if (!flag) return false;
    try { global.sessionStorage.removeItem(PREFIX + 'type_switch'); } catch (e2) {}
    var parsed = null;
    try { parsed = JSON.parse(flag); } catch (e3) { parsed = null; }
    if (!parsed || String(parsed.professorId) !== meta.professorId) return false;
    var bundle = readJson(bundleKey(meta));
    var types = (bundle && bundle.values && bundle.values.types) ? bundle.values.types : {};
    var specific = types[meta.examType] || {};
    var from = types[parsed.fromType] || parsed.values || {};
    var merged = Object.assign({}, sharedFrom(from), specific);
    applyValues(form, merged);
    persist(form, true);
    setStatus(form, 'saved', 'Draft saved');
    return true;
  }

  function closeRestore() {
    restoreOpen = false;
    var dlg = document.getElementById('examConfigDraftRestore');
    if (dlg) dlg.remove();
  }

  function showRestore(form, draft) {
    closeRestore();
    restoreOpen = true;
    var host = form.closest('.admin-modal--examination-edit') || form.parentElement || form;
    var wrap = document.createElement('div');
    wrap.id = 'examConfigDraftRestore';
    wrap.className = 'exam-config-draft-restore';
    wrap.setAttribute('role', 'dialog');
    wrap.setAttribute('aria-modal', 'true');
    wrap.setAttribute('aria-labelledby', 'examConfigDraftRestoreTitle');
    wrap.innerHTML = '<div class="exam-config-draft-restore__card">' +
      '<h3 id="examConfigDraftRestoreTitle">Restore unsaved examination?</h3>' +
      '<p>A draft of this form was saved on this device. Restore it, or start with a blank form.</p>' +
      '<div class="exam-config-draft-restore__actions">' +
      '<button type="button" class="admin-btn admin-btn--secondary admin-btn--sm" data-draft-discard>Start fresh</button>' +
      '<button type="button" class="admin-btn admin-btn--primary admin-btn--sm" data-draft-restore>Restore draft</button>' +
      '</div></div>';
    host.appendChild(wrap);
    wrap.querySelector('[data-draft-restore]').addEventListener('click', function () {
      applyValues(form, draft.values);
      persist(form, true);
      setStatus(form, 'saved', 'Draft saved');
      closeRestore();
    });
    wrap.querySelector('[data-draft-discard]').addEventListener('click', function () {
      var meta = metaFromForm(form);
      try {
        global.localStorage.removeItem(typeKey(meta));
        var bundle = readJson(bundleKey(meta));
        if (bundle && bundle.values && bundle.values.types) {
          delete bundle.values.types[meta.examType];
          writeJson(bundleKey(meta), bundle);
        }
      } catch (e) {}
      setStatus(form, 'unsaved', 'Unsaved');
      closeRestore();
    });
    var restoreBtn = wrap.querySelector('[data-draft-restore]');
    if (restoreBtn && restoreBtn.focus) restoreBtn.focus();
  }

  function maybePrompt(form) {
    var meta = metaFromForm(form);
    if (mergeTypeSwitch(form)) return;
    if (meta.stashApplied) {
      persist(form, true);
      setStatus(form, 'saved', 'Draft saved');
      return;
    }
    var draft = readJson(typeKey(meta));
    if (!draft || isEmptyValues(draft.values)) {
      setStatus(form, 'unsaved', 'Unsaved');
      return;
    }
    if (meta.serverUpdated && draft.savedAt) {
      var serverTs = Date.parse(String(meta.serverUpdated).replace(' ', 'T'));
      if (!isNaN(serverTs) && draft.savedAt + 2000 < serverTs) {
        setStatus(form, 'unsaved', 'Unsaved');
        return;
      }
    }
    showRestore(form, draft);
  }

  function bind(form) {
    if (!form || !form.getAttribute('data-exam-draft')) return;
    var clearRaw = form.getAttribute('data-clear-draft');
    if (clearRaw) {
      try { clearFor(JSON.parse(clearRaw)); } catch (eClr) {}
    }
    boundGen += 1;
    var gen = boundGen;
    boundForm = form;
    form.querySelectorAll('[data-exam-draft-bound]').forEach(function () {});
    if (form.getAttribute('data-exam-draft-bound') === '1') {
      maybePrompt(form);
      return;
    }
    form.setAttribute('data-exam-draft-bound', '1');
    form.addEventListener('input', function () { if (boundGen === gen) schedule(form); });
    form.addEventListener('change', function () { if (boundGen === gen) schedule(form); });
    form.addEventListener('submit', function () { flush(form); });
    if (!global.__ereviewExamDraftStorageBound) {
      global.__ereviewExamDraftStorageBound = true;
      global.addEventListener('storage', function (ev) {
        if (!boundForm || !ev.key || ev.key.indexOf(PREFIX) !== 0) return;
        var meta = metaFromForm(boundForm);
        if (ev.key !== typeKey(meta)) return;
        var incoming = readJson(ev.key);
        if (!incoming || isEmptyValues(incoming.values)) return;
        if (incoming.savedAt <= lastWrittenAt) return;
        showRestore(boundForm, incoming);
      });
    }
    maybePrompt(form);
  }

  function flushForTypeSwitch(form, nextType) {
    flush(form);
    var meta = metaFromForm(form);
    try {
      global.sessionStorage.setItem(PREFIX + 'type_switch', JSON.stringify({
        professorId: meta.professorId,
        identity: meta.identity,
        fromType: meta.examType,
        toType: nextType,
        values: collect(form)
      }));
    } catch (e) {}
  }

  function handleEscape() {
    if (!restoreOpen) return false;
    closeRestore();
    return true;
  }

  function clearFor(payload) {
    if (!payload) return;
    var pid = String(payload.professor_id || payload.professorId || '');
    if (!pid) return;
    var identities = [];
    if (Array.isArray(payload.identities)) {
      payload.identities.forEach(function (id) {
        if (id != null && String(id) !== '') identities.push(String(id));
      });
    }
    if (!identities.length) identities.push(String(payload.identity || 'new'));
    identities.forEach(function (identity) {
      ['regular', 'diagnostic'].forEach(function (t) {
        try {
          global.localStorage.removeItem(PREFIX + pid + ':' + identity + ':' + t);
        } catch (e) {}
      });
      try {
        global.localStorage.removeItem(PREFIX + pid + ':' + identity + BUNDLE);
      } catch (e2) {}
    });
  }

  global.addEventListener('beforeunload', function () {
    flush(boundForm);
  });

  global.ereviewExamConfigDraft = {
    bind: bind,
    flush: function () { flush(boundForm); },
    flushForTypeSwitch: flushForTypeSwitch,
    handleEscape: handleEscape,
    clearFor: clearFor
  };
})(window);
