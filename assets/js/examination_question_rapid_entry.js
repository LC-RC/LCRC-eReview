/**
 * Professor examination rapid-entry question workspace.
 * Server-side autosave via professor_examination_questions_ajax.php.
 * Persisted question_id is retained per entry to prevent duplicate INSERTs.
 */
(function (window) {
  'use strict';

  var DEBOUNCE_MS = 700;
  var RETRY_MS = 1600;

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function plainPreview(html, max) {
    max = max || 140;
    var t = String(html || '').replace(/<[^>]+>/g, ' ').replace(/&nbsp;/g, ' ').replace(/\s+/g, ' ').trim();
    if (t.length > max) t = t.slice(0, max - 1) + '…';
    return t || '—';
  }

  function typeLabel(t) {
    return String(t || '').toLowerCase() === 'tf' ? 'True/False' : 'Multiple';
  }

  function answerLabel(q) {
    var ans = String(q.correct_answer || '').toUpperCase();
    if (String(q.question_type || '') === 'tf') {
      if (ans === 'A') return 'True';
      if (ans === 'B') return 'False';
    }
    return ans || '—';
  }

  function createController(cfg) {
    var examType = cfg.examType || 'regular';
    var sourceId = cfg.sourceId | 0;
    var subjectId = cfg.subjectId | 0;
    var subjectLabel = String(cfg.subjectLabel || '');
    var subjectName = String(cfg.subjectName || '');
    var requiredCount = cfg.requiredCount | 0;
    var csrf = cfg.csrf || '';
    var ajaxUrl = cfg.ajaxUrl || 'professor_examination_questions_ajax';
    var allowTf = examType === 'regular';
    var isDiagnostic = examType === 'diagnostic';
    var locked = !!cfg.locked;

    var questions = Array.isArray(cfg.questions) ? cfg.questions.slice() : [];
    var nextNumber = (cfg.nextNumber | 0) || (questions.length + 1);

    var tableBody = document.getElementById(cfg.tableBodyId || 'eqbQuestionRows');
    var slotList = document.getElementById(cfg.slotListId || 'diagSlotList');
    var countEl = document.getElementById(cfg.countId || 'eqbQuestionCount');
    var searchEl = document.getElementById(cfg.searchId || 'eqbSearch');
    var filterEl = document.getElementById(cfg.filterId || 'eqbTypeFilter');
    var rapidTitleEl = document.getElementById('eqbRapidTitle');
    var rapidSubtitleEl = document.getElementById('eqbRapidSubtitle');
    var editTitleEl = document.getElementById('eqbEditTitle');
    var editSubtitleEl = document.getElementById('eqbEditSubtitle');

    var addOverlay = document.getElementById('eqbRapidOverlay');
    var addList = document.getElementById('eqbRapidList');
    var addAnotherBtn = document.getElementById('eqbAddAnotherBtn');
    var addCloseBtn = document.getElementById('eqbRapidClose');
    var addCloseBtn2 = document.getElementById('eqbRapidCloseFooter');
    var addCancelBtn = document.getElementById('eqbRapidCancel');
    var addSaveBtn = document.getElementById('eqbRapidSaveBtn');

    var editOverlay = document.getElementById('eqbEditOverlay');
    var editMount = document.getElementById('eqbEditMount');
    var editCloseBtn = document.getElementById('eqbEditClose');
    var editCloseBtn2 = document.getElementById('eqbEditCloseFooter');

    var entries = [];
    var editEntry = null;
    var dirtySinceOpen = false;
    var sessionNext = questions.length + 1;

    function allocateDisplayNumber() {
      var n = Math.max(sessionNext, nextNumber, questions.length + 1);
      entries.forEach(function (e) {
        if ((e.displayNumber | 0) >= n) n = (e.displayNumber | 0) + 1;
      });
      sessionNext = n + 1;
      nextNumber = Math.max(nextNumber, sessionNext);
      return n;
    }

    function openOverlay(el) {
      if (!el) return;
      el.hidden = false;
      el.classList.add('is-open');
    }
    function closeOverlay(el) {
      if (!el) return;
      el.hidden = true;
      el.classList.remove('is-open');
    }

    function post(action, payload) {
      var body = Object.assign({
        action: action,
        csrf_token: csrf,
        exam_type: examType,
        exam_id: sourceId,
        batch_id: sourceId,
        source_id: sourceId,
        subject_id: subjectId
      }, payload || {});
      return fetch(ajaxUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json'
        },
        body: JSON.stringify(body)
      }).then(function (r) {
        return r.json().catch(function () {
          return null;
        }).then(function (data) {
          if (data && typeof data === 'object') return data;
          return {
            ok: false,
            error: r.status === 404
              ? 'Question save endpoint not found. Please reload the page.'
              : ('Unable to save question (HTTP ' + r.status + '). Please reload and try again.')
          };
        });
      });
    }

    function setCount(n) {
      if (countEl) {
        var extra = countEl.getAttribute('data-extra') || '';
        countEl.innerHTML = '<strong>' + String(n) + '</strong> Question' + (n === 1 ? '' : 's') + extra;
      }
      nextNumber = n + 1;
    }

    function applyTableFilters() {
      if (!tableBody) return;
      var q = searchEl ? String(searchEl.value || '').toLowerCase().trim() : '';
      var f = filterEl ? String(filterEl.value || 'all') : 'all';
      tableBody.querySelectorAll('tr[data-eqb-row]').forEach(function (tr) {
        var hay = tr.getAttribute('data-eqb-search') || '';
        var typ = tr.getAttribute('data-eqb-type') || 'mcq';
        var typeOk = f === 'all' || (f === 'mcq' && typ === 'mcq') || (f === 'tf' && typ === 'tf');
        var searchOk = !q || hay.indexOf(q) !== -1;
        tr.style.display = typeOk && searchOk ? '' : 'none';
      });
    }

    function updateDiagProgress() {
      var authored = questions.length;
      var req = requiredCount | 0;
      var toward = req > 0 ? Math.min(authored, req) : authored;
      var pct = req > 0 ? Math.round((toward / req) * 100) : (authored > 0 ? 100 : 0);
      var ok = req > 0 ? authored >= req : authored >= 1;
      var fill = document.getElementById('diagProgressFill');
      if (fill) fill.style.width = pct + '%';
      var num = document.getElementById('diagCompletedNum');
      if (num) num.textContent = String(toward);
      var stageLabel = document.getElementById('diagStageProgressLabel');
      if (stageLabel) {
        if (req > 0) {
          stageLabel.textContent = req + ' questions required · ' + authored + ' / ' + req + ' authored' + (ok ? ' ✓' : '');
        } else {
          stageLabel.textContent = authored + ' authored · use all (need ≥ 1)';
        }
      }
      var status = document.getElementById('diagProgressStatus');
      if (status) {
        if (req > 0) {
          if (ok) status.innerHTML = '<span class="diag-progress__done">✓ Target reached · ' + authored + ' / ' + req + ' questions</span>';
          else if (authored > req) {
            status.textContent = 'Required: ' + req + ' · Authored: ' + authored + ' (extra questions stay in the pool; first ' + req + ' are used)';
          } else {
            status.textContent = authored + ' / ' + req + ' questions authored';
          }
        } else {
          status.textContent = authored >= 1
            ? '✓ At least one question authored for this subject.'
            : 'Add at least one question for this subject.';
        }
      }
      var stage = document.getElementById('diagSubjectStage');
      if (stage) stage.setAttribute('data-authored', String(authored));
      var activeTab = document.querySelector('.diag-subject-nav__tab.is-active [data-diag-tab-meta]');
      if (activeTab) {
        activeTab.textContent = req > 0 ? (toward + '/' + req) : (authored + ' authored');
      }
      var activeTabEl = document.querySelector('.diag-subject-nav__tab.is-active');
      if (activeTabEl) {
        activeTabEl.classList.toggle('is-complete', !!ok);
        var codeEl = activeTabEl.querySelector('.diag-subject-nav__code');
        if (codeEl) {
          var baseCode = subjectLabel || String(codeEl.textContent || '').replace(/\s*✓\s*$/, '').trim();
          codeEl.textContent = baseCode + (ok ? ' ✓' : '');
        }
      }
      setCount(authored);
    }

    function modalProgressLabel(forNew) {
      var authored = questions.length;
      var req = requiredCount | 0;
      var nextSlot = forNew ? (authored + 1) : authored;
      if (req > 0) {
        return subjectLabel + ' · Question ' + nextSlot + ' of ' + req;
      }
      return subjectLabel + (subjectLabel ? ' · ' : '') + 'Question ' + nextSlot;
    }

    function renderDiagSlots() {
      if (!slotList) return;
      updateDiagProgress();
      var authored = questions.length;
      if (!authored) {
        slotList.innerHTML = '<tr class="diag-q-empty-row"><td colspan="5" class="students-empty-cell">No questions authored yet for ' + esc(subjectLabel || 'this subject') + '. Use Add Question to begin.</td></tr>';
        return;
      }
      var html = '';
      questions.forEach(function (q, si) {
        var typ = String(q.question_type || 'mcq') === 'tf' ? 'tf' : 'mcq';
        var preview = q.preview || plainPreview(q.question_text);
        var tLabel = q.type_label || typeLabel(typ);
        var aLabel = q.answer_label || answerLabel(q);
        var hay = (preview + ' ' + (q.question_text || '') + ' ' + (q.correct_answer || '')).toLowerCase();
        html += '<tr data-eqb-row data-eqb-id="' + (q.question_id | 0) + '" data-eqb-type="' + typ + '" data-eqb-search="' + esc(hay) + '">';
        html += '<td>' + (si + 1) + '</td>';
        html += '<td class="diag-q-table__question">' + esc(preview) + '</td>';
        html += '<td><span class="eqb-type">' + esc(tLabel) + '</span></td>';
        html += '<td>' + esc(aLabel) + '</td>';
        html += '<td class="eqb-row-actions">';
        if (!locked) {
          html += '<button type="button" class="admin-btn admin-btn--ghost admin-btn--sm" data-eqb-edit="' + (q.question_id | 0) + '">Edit</button>';
          html += '<form method="post" class="eqb-inline-form diag-q-inline-delete" data-eqb-delete-form>';
          html += '<input type="hidden" name="csrf_token" value="' + esc(csrf) + '">';
          html += '<input type="hidden" name="action" value="delete_question">';
          html += '<input type="hidden" name="exam_type" value="diagnostic">';
          html += '<input type="hidden" name="batch_id" value="' + sourceId + '">';
          html += '<input type="hidden" name="subject_id" value="' + subjectId + '">';
          html += '<input type="hidden" name="question_id" value="' + (q.question_id | 0) + '">';
          html += '<button type="submit" class="admin-btn admin-btn--ghost admin-btn--sm is-danger">Delete</button>';
          html += '</form>';
        } else {
          html += '<span class="opacity-60 text-sm">Locked</span>';
        }
        html += '</td></tr>';
      });
      slotList.innerHTML = html;
    }

    function renderTable() {
      if (isDiagnostic) {
        renderDiagSlots();
        return;
      }
      if (!tableBody) return;
      setCount(questions.length);
      if (!questions.length) {
        tableBody.innerHTML = '<tr><td colspan="4" class="students-empty-cell">No questions yet. Use Add Question or Import.</td></tr>';
        return;
      }
      var html = '';
      questions.forEach(function (q, i) {
        var num = i + 1;
        var typ = String(q.question_type || 'mcq') === 'tf' ? 'tf' : 'mcq';
        var preview = q.preview || plainPreview(q.question_text);
        var tLabel = q.type_label || typeLabel(typ);
        var hay = (preview + ' ' + (q.question_text || '') + ' ' + (q.correct_answer || '')).toLowerCase();
        html += '<tr data-eqb-row data-eqb-id="' + (q.question_id | 0) + '" data-eqb-type="' + typ + '" data-eqb-search="' + esc(hay) + '">';
        html += '<td>' + num + '</td>';
        html += '<td>' + esc(preview) + '</td>';
        html += '<td><span class="eqb-type">' + esc(tLabel) + '</span></td>';
        html += '<td class="eqb-row-actions">';
        if (!locked) {
          html += '<button type="button" class="admin-btn admin-btn--ghost admin-btn--sm" data-eqb-edit="' + (q.question_id | 0) + '">Edit</button>';
          html += '<div class="admin-student-action-menu-wrap eqb-more-wrap">';
          html += '<button type="button" class="admin-btn admin-btn--ghost admin-btn--sm admin-student-action-menu-trigger" aria-label="More actions" data-eqb-more>⋮</button>';
          html += '<div class="admin-student-action-menu" hidden>';
          html += '<button type="button" class="admin-student-action-item" data-eqb-preview-id="' + (q.question_id | 0) + '">Preview</button>';
          html += '<form method="post" class="eqb-inline-form">';
          html += '<input type="hidden" name="csrf_token" value="' + esc(csrf) + '">';
          html += '<input type="hidden" name="action" value="duplicate_question">';
          html += '<input type="hidden" name="exam_type" value="' + esc(examType) + '">';
          html += '<input type="hidden" name="exam_id" value="' + sourceId + '">';
          html += '<input type="hidden" name="question_id" value="' + (q.question_id | 0) + '">';
          html += '<button type="submit" class="admin-student-action-item">Duplicate</button>';
          html += '</form>';
          html += '<form method="post" class="eqb-inline-form" data-eqb-delete-form>';
          html += '<input type="hidden" name="csrf_token" value="' + esc(csrf) + '">';
          html += '<input type="hidden" name="action" value="delete_question">';
          html += '<input type="hidden" name="exam_type" value="' + esc(examType) + '">';
          html += '<input type="hidden" name="exam_id" value="' + sourceId + '">';
          html += '<input type="hidden" name="question_id" value="' + (q.question_id | 0) + '">';
          html += '<button type="submit" class="admin-student-action-item is-danger">Delete</button>';
          html += '</form>';
          html += '</div></div>';
        } else {
          html += '<span class="opacity-60 text-sm">Locked</span>';
        }
        html += '</td></tr>';
      });
      tableBody.innerHTML = html;
      applyTableFilters();
    }

    function findQuestion(id) {
      id = id | 0;
      for (var i = 0; i < questions.length; i++) {
        if ((questions[i].question_id | 0) === id) return questions[i];
      }
      return null;
    }

    function upsertLocalQuestion(row) {
      if (!row || !(row.question_id | 0)) return;
      var id = row.question_id | 0;
      var found = false;
      for (var i = 0; i < questions.length; i++) {
        if ((questions[i].question_id | 0) === id) {
          questions[i] = Object.assign({}, questions[i], row);
          found = true;
          break;
        }
      }
      if (!found) questions.push(row);
      dirtySinceOpen = true;
    }

    function removeLocalQuestion(id) {
      id = id | 0;
      questions = questions.filter(function (q) { return (q.question_id | 0) !== id; });
      dirtySinceOpen = true;
    }

    function refreshFromServer() {
      return post('list_questions', {}).then(function (res) {
        if (!res || !res.ok) return;
        questions = Array.isArray(res.questions) ? res.questions : [];
        nextNumber = (res.next_number | 0) || (questions.length + 1);
        renderTable();
      });
    }

    function statusHtml(state, message) {
      var cls = 'eqb-save-status';
      var text = 'Draft';
      if (state === 'saving') { cls += ' is-saving'; text = 'Saving…'; }
      else if (state === 'saved') { cls += ' is-saved'; text = '✓ Saved'; }
      else if (state === 'retrying') { cls += ' is-retry'; text = 'Retrying…'; }
      else if (state === 'error') { cls += ' is-error'; text = '⚠ ' + (message || 'Save failed'); }
      else { cls += ' is-draft'; text = 'Draft'; }
      return '<span class="' + cls + '" data-status>' + esc(text) + '</span>';
    }

    function setEntryStatus(entry, state, message) {
      entry.status = state;
      entry.statusMessage = message || '';
      if (entry.statusEl) {
        entry.statusEl.outerHTML = statusHtml(state, message);
        entry.statusEl = entry.root.querySelector('[data-status]');
      }
    }

    function getEditorContent(entry) {
      if (entry.textEl && entry.textEl.id && window.tinymce) {
        var ed = tinymce.get(entry.textEl.id);
        if (ed) {
          ed.save();
          return String(ed.getContent() || '');
        }
      }
      return entry.textEl ? String(entry.textEl.value || '') : '';
    }

    function collectPayload(entry) {
      var type = allowTf && entry.typeSel && entry.typeSel.value === 'tf' ? 'tf' : 'mcq';
      var text = getEditorContent(entry);
      var a = '', b = '', c = '', d = '', cor = '';
      var extra = {};
      if (type === 'tf') {
        a = 'True';
        b = 'False';
        c = '';
        d = '';
        cor = entry.correctSel ? String(entry.correctSel.value || '').toUpperCase() : '';
        if (cor !== 'A' && cor !== 'B') cor = '';
      } else if (entry.choiceInputs && entry.choiceInputs.length) {
        entry.choiceInputs.forEach(function (inp) {
          var L = String(inp.getAttribute('data-choice-letter') || '').toUpperCase();
          var val = String(inp.value || '').trim();
          if (L === 'A') a = val;
          else if (L === 'B') b = val;
          else if (L === 'C') c = val;
          else if (L === 'D') d = val;
          else if (/^[E-Z]$/.test(L) && val !== '') extra[L] = val;
        });
        cor = entry.correctSel ? String(entry.correctSel.value || '').toUpperCase() : '';
        if (!/^[A-Z]$/.test(cor)) cor = '';
      } else {
        a = entry.choiceA ? String(entry.choiceA.value || '').trim() : '';
        b = entry.choiceB ? String(entry.choiceB.value || '').trim() : '';
        c = entry.choiceC ? String(entry.choiceC.value || '').trim() : '';
        d = entry.choiceD ? String(entry.choiceD.value || '').trim() : '';
        cor = entry.correctSel ? String(entry.correctSel.value || '').toUpperCase() : '';
        if (!/^[A-D]$/.test(cor)) cor = '';
      }
      return {
        question_id: entry.questionId | 0,
        question_type: type,
        question_text: text,
        choice_a: a,
        choice_b: b,
        choice_c: c,
        choice_d: d,
        extra_choices: extra,
        correct_answer: cor,
        client_rev: entry.rev | 0
      };
    }

    function isPersistable(payload) {
      var plain = String(payload.question_text || '').replace(/<[^>]+>/g, ' ').replace(/&nbsp;/g, ' ').trim();
      if (!plain) return false;
      if (payload.question_type === 'tf') {
        return payload.correct_answer === 'A' || payload.correct_answer === 'B';
      }
      if (!payload.choice_a || !payload.choice_b) return false;
      if (!payload.choice_c && payload.choice_d) return false;
      var map = { A: payload.choice_a, B: payload.choice_b, C: payload.choice_c, D: payload.choice_d };
      var extra = payload.extra_choices && typeof payload.extra_choices === 'object' ? payload.extra_choices : {};
      Object.keys(extra).forEach(function (L) { map[L] = extra[L]; });
      if (!/^[A-Z]$/.test(payload.correct_answer || '')) return false;
      return !!(map[payload.correct_answer] || '').trim();
    }

    function setPanelVisible(el, show) {
      if (!el) return;
      if (show) {
        el.hidden = false;
        el.removeAttribute('hidden');
        el.classList.remove('is-hidden');
      } else {
        el.hidden = true;
        el.setAttribute('hidden', 'hidden');
        el.classList.add('is-hidden');
      }
    }

    function choiceInputByLetter(entry, letter) {
      if (!entry || !entry.choiceInputs) return null;
      letter = String(letter || '').toUpperCase();
      for (var i = 0; i < entry.choiceInputs.length; i++) {
        if (String(entry.choiceInputs[i].getAttribute('data-choice-letter') || '').toUpperCase() === letter) {
          return entry.choiceInputs[i];
        }
      }
      return null;
    }

    function choiceOptionLabel(letter, text) {
      var t = String(text || '').replace(/\s+/g, ' ').trim();
      if (t.length > 52) t = t.slice(0, 49) + '…';
      if (!t) t = 'Choice ' + letter;
      return letter + ' — ' + t;
    }

    function entryChoiceLetters(entry) {
      if (!entry || !entry.choiceInputs) return ['A', 'B', 'C', 'D'];
      return entry.choiceInputs.map(function (inp) {
        return String(inp.getAttribute('data-choice-letter') || '').toUpperCase();
      }).filter(Boolean);
    }

    function availableCorrectLetters(entry, isTf) {
      if (isTf) return ['A', 'B'];
      var letters = entryChoiceLetters(entry);
      if (!letters.length) letters = ['A', 'B', 'C', 'D'];
      return letters.filter(function (L) {
        var inp = choiceInputByLetter(entry, L);
        return !!(inp && String(inp.value || '').trim() !== '');
      });
    }

    function rebuildCorrectOptions(entry, isTf, prefer) {
      if (!entry.correctSel) return;
      var prev = prefer != null ? String(prefer).toUpperCase() : String(entry.correctSel.value || '').toUpperCase();
      while (entry.correctSel.options.length) entry.correctSel.remove(0);
      var ph = document.createElement('option');
      ph.value = '';
      ph.textContent = isTf ? 'Select True or False' : 'Select correct answer';
      entry.correctSel.appendChild(ph);
      if (isTf) {
        [['A', 'True'], ['B', 'False']].forEach(function (pair) {
          var o = document.createElement('option');
          o.value = pair[0];
          o.textContent = pair[1];
          entry.correctSel.appendChild(o);
        });
        entry.correctSel.value = (prev === 'A' || prev === 'B') ? prev : '';
        return;
      }
      if (isDiagnostic) {
        var letters = availableCorrectLetters(entry, false);
        letters.forEach(function (L) {
          var inp = choiceInputByLetter(entry, L);
          var o = document.createElement('option');
          o.value = L;
          o.textContent = choiceOptionLabel(L, inp ? inp.value : '');
          entry.correctSel.appendChild(o);
        });
        entry.correctSel.value = letters.indexOf(prev) >= 0 ? prev : '';
        return;
      }
      // Regular exam: keep stable A–D letter options (unchanged behavior).
      ['A', 'B', 'C', 'D'].forEach(function (L) {
        var o = document.createElement('option');
        o.value = L;
        o.textContent = L;
        entry.correctSel.appendChild(o);
      });
      entry.correctSel.value = /^[A-D]$/.test(prev) ? prev : '';
    }

    function syncCorrectRadios(entry) {
      // Diagnostic uses dropdown only; retained no-op for call sites.
      void entry;
    }

    function addExtraChoiceRow(entry) {
      if (!entry || !entry.choicesMount) return;
      var letters = entryChoiceLetters(entry);
      var last = letters.length ? letters[letters.length - 1] : 'D';
      if (last >= 'Z') return;
      var next = String.fromCharCode(last.charCodeAt(0) + 1);
      if (next < 'E') next = 'E';
      var row = document.createElement('div');
      row.className = 'eqb-choice-row';
      row.innerHTML = '<span class="eqb-choice-letter">' + next + '</span>' +
        '<input class="eqb-choice-input" data-choice-letter="' + next + '" placeholder="Choice ' + next + '">';
      entry.choicesMount.appendChild(row);
      var inp = row.querySelector('input');
      entry.choiceInputs = Array.prototype.slice.call(entry.choicesMount.querySelectorAll('[data-choice-letter]'));
      if (inp) {
        inp.addEventListener('input', function () {
          rebuildCorrectOptions(entry, false, entry.correctSel ? entry.correctSel.value : '');
          scheduleSave(entry);
        });
        inp.addEventListener('change', function () {
          rebuildCorrectOptions(entry, false, entry.correctSel ? entry.correctSel.value : '');
          scheduleSave(entry);
        });
        inp.focus();
      }
      rebuildCorrectOptions(entry, false, entry.correctSel ? entry.correctSel.value : '');
    }

    function applyTypeUi(entry, opts) {
      opts = opts || {};
      var isTf = allowTf && entry.typeSel && entry.typeSel.value === 'tf';
      var switching = !!opts.switching;

      // Conditional panels: only one type's controls exist in the interactive layout.
      setPanelVisible(entry.mcqBlock, !isTf);
      // TF uses Correct Answer dropdown only — no separate True/False choice rows.

      if (switching) {
        if (isTf) {
          // Backup MCQ fields, then clear so stale A–D/C never ride along.
          entry._mcqBackup = {
            a: entry.choiceA ? entry.choiceA.value : '',
            b: entry.choiceB ? entry.choiceB.value : '',
            c: entry.choiceC ? entry.choiceC.value : '',
            d: entry.choiceD ? entry.choiceD.value : '',
            cor: entry.correctSel ? entry.correctSel.value : ''
          };
          if (entry.choiceA) entry.choiceA.value = '';
          if (entry.choiceB) entry.choiceB.value = '';
          if (entry.choiceC) entry.choiceC.value = '';
          if (entry.choiceD) entry.choiceD.value = '';
          rebuildCorrectOptions(entry, true, '');
        } else {
          var bak = entry._mcqBackup || null;
          if (entry.choiceA) entry.choiceA.value = bak ? bak.a : '';
          if (entry.choiceB) entry.choiceB.value = bak ? bak.b : '';
          if (entry.choiceC) entry.choiceC.value = bak ? bak.c : '';
          if (entry.choiceD) entry.choiceD.value = bak ? bak.d : '';
          // Avoid restoring literal True/False as MCQ choice text.
          if (entry.choiceA && /^true$/i.test(String(entry.choiceA.value || '').trim())) entry.choiceA.value = '';
          if (entry.choiceB && /^false$/i.test(String(entry.choiceB.value || '').trim())) entry.choiceB.value = '';
          rebuildCorrectOptions(entry, false, bak ? bak.cor : '');
        }
      } else {
        rebuildCorrectOptions(entry, isTf, entry.correctSel ? entry.correctSel.value : '');
      }
    }

    function initEntryEditor(entry) {
      if (!entry || !entry.textEl || !window.tinymce) return;
      if (!entry.textEl.id) {
        entry.textEl.id = 'eqb-rapid-q-' + Math.random().toString(36).slice(2, 10);
      }
      if (tinymce.get(entry.textEl.id)) return;
      var contentCss = 'body{font-family:Nunito,system-ui,sans-serif;font-size:15px;line-height:1.55;color:#0f172a}'
        + 'table{border-collapse:collapse;width:100%;margin:0.75rem 0}'
        + 'td,th{border:1px solid #cbd5e1;padding:0.4rem 0.55rem;vertical-align:top}'
        + 'p{margin:0 0 0.5em 0}ul,ol{margin:0.25em 0 0.5em 1.25em}';
      tinymce.init({
        selector: '#' + entry.textEl.id,
        menubar: false,
        height: 260,
        branding: false,
        promotion: false,
        plugins: 'table lists advlist link hr',
        toolbar: 'undo redo | bold italic underline strikethrough | bullist numlist | alignleft aligncenter alignright | outdent indent | superscript subscript | link table hr | removeformat',
        valid_elements: 'p[style],br,strong/b,em/i,u,s,strike,sub,sup,hr,a[href|target|rel],ul,ol,li,table,thead,tbody,tfoot,tr,th[colspan|rowspan|scope|style],td[colspan|rowspan|style]',
        valid_styles: { '*': 'text-align' },
        content_style: contentCss,
        forced_root_block: 'p',
        entity_encoding: 'raw',
        skin: 'oxide',
        content_css: false,
        setup: function (editor) {
          entry.editor = editor;
          editor.on('change input undo redo keyup SetContent', function () {
            editor.save();
            scheduleSave(entry);
          });
        }
      });
    }

    function destroyEntryEditor(entry) {
      if (!entry || !entry.textEl || !window.tinymce) return;
      var id = entry.textEl.id;
      if (!id) return;
      var ed = tinymce.get(id);
      if (ed) {
        try { ed.save(); } catch (e) {}
        try { ed.remove(); } catch (e2) {}
      }
      entry.editor = null;
    }

    function destroyAllEditors(list) {
      (list || []).forEach(destroyEntryEditor);
      if (editEntry) destroyEntryEditor(editEntry);
    }

    function scheduleSave(entry) {
      if (locked) return;
      entry.rev = (entry.rev | 0) + 1;
      var payload = collectPayload(entry);
      if (!isPersistable(payload)) {
        if (entry.timer) { clearTimeout(entry.timer); entry.timer = null; }
        if (!(entry.questionId | 0)) setEntryStatus(entry, 'draft');
        else setEntryStatus(entry, 'draft'); // unsaved edits on existing row
        return;
      }
      setEntryStatus(entry, entry.status === 'error' ? 'retrying' : 'saving');
      if (entry.timer) clearTimeout(entry.timer);
      entry.timer = setTimeout(function () {
        entry.timer = null;
        flushSave(entry);
      }, DEBOUNCE_MS);
    }

    function flushSave(entry) {
      if (locked) return Promise.resolve({ ok: true, skipped: true });
      var payload = collectPayload(entry);
      var revAtSend = payload.client_rev | 0;
      if (!isPersistable(payload)) {
        setEntryStatus(entry, 'draft');
        return Promise.resolve({
          ok: false,
          incomplete: true,
          error: 'Unable to save question. Please check the required fields.'
        });
      }

      // Prevent duplicate INSERTs: serialize first create.
      if (!(entry.questionId | 0)) {
        if (entry.inserting) {
          entry.pendingAfterInsert = true;
          return entry.insertPromise || Promise.resolve({ ok: true, skipped: true });
        }
        entry.inserting = true;
        setEntryStatus(entry, 'saving');
        entry.insertPromise = post('save_question', payload).then(function (res) {
          if (!res || !res.ok) {
            setEntryStatus(entry, 'error', (res && res.error) || 'Save failed');
            if (entry.retryTimer) clearTimeout(entry.retryTimer);
            entry.retryTimer = setTimeout(function () {
              entry.retryTimer = null;
              scheduleSave(entry);
            }, RETRY_MS);
            return {
              ok: false,
              error: (res && res.error) || 'Unable to save question. Please check the required fields.'
            };
          }
          // Ignore stale response only for UPDATEs; first INSERT must keep ID.
          entry.questionId = res.question_id | 0;
          if (res.question) upsertLocalQuestion(res.question);
          else if (res.question_id) {
            upsertLocalQuestion({
              question_id: res.question_id,
              question_type: payload.question_type,
              question_text: payload.question_text,
              preview: plainPreview(payload.question_text),
              choice_a: payload.choice_a,
              choice_b: payload.choice_b,
              choice_c: payload.choice_c,
              choice_d: payload.choice_d,
              correct_answer: payload.correct_answer,
              type_label: typeLabel(payload.question_type),
              answer_label: answerLabel(payload),
              display_number: res.display_number || 0
            });
          }
          if (res.next_number) nextNumber = res.next_number | 0;
          if (res.display_number) {
            entry.displayNumber = res.display_number | 0;
            if (entry.numEl) entry.numEl.textContent = 'Question ' + entry.displayNumber;
          }
          if ((entry.rev | 0) === revAtSend) setEntryStatus(entry, 'saved');
          else setEntryStatus(entry, 'saving');
          dirtySinceOpen = true;
          return { ok: true, question_id: entry.questionId | 0, res: res };
        }).catch(function () {
          setEntryStatus(entry, 'error', 'Network error');
          if (entry.retryTimer) clearTimeout(entry.retryTimer);
          entry.retryTimer = setTimeout(function () {
            entry.retryTimer = null;
            scheduleSave(entry);
          }, RETRY_MS);
          return { ok: false, error: 'Network error while saving. Please try again.' };
        }).finally(function () {
          entry.inserting = false;
          entry.insertPromise = null;
          if (entry.pendingAfterInsert) {
            entry.pendingAfterInsert = false;
            flushSave(entry);
          } else if ((entry.rev | 0) !== revAtSend) {
            flushSave(entry);
          }
        });
        return entry.insertPromise;
      }

      // UPDATE path with stale-response protection.
      if (entry.inFlight && entry.abortController) {
        try { entry.abortController.abort(); } catch (e) {}
      }
      entry.inFlight = true;
      setEntryStatus(entry, 'saving');
      var ac = typeof AbortController !== 'undefined' ? new AbortController() : null;
      entry.abortController = ac;

      var body = Object.assign({
        action: 'save_question',
        csrf_token: csrf,
        exam_type: examType,
        exam_id: sourceId,
        batch_id: sourceId,
        source_id: sourceId,
        subject_id: subjectId
      }, payload);

      return fetch(ajaxUrl, {
        method: 'POST',
        credentials: 'same-origin',
        signal: ac ? ac.signal : undefined,
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify(body)
      }).then(function (r) {
        return r.json().catch(function () { return { ok: false, error: 'Invalid server response.' }; });
      }).then(function (res) {
        if ((entry.rev | 0) !== revAtSend) {
          // Stale response — do not apply status overwrite as Saved.
          return { ok: true, stale: true };
        }
        if (!res || !res.ok) {
          setEntryStatus(entry, 'error', (res && res.error) || 'Save failed');
          if (entry.retryTimer) clearTimeout(entry.retryTimer);
          entry.retryTimer = setTimeout(function () {
            entry.retryTimer = null;
            scheduleSave(entry);
          }, RETRY_MS);
          return {
            ok: false,
            error: (res && res.error) || 'Unable to save question. Please check the required fields.'
          };
        }
        entry.questionId = res.question_id | 0;
        if (res.question) upsertLocalQuestion(res.question);
        if (res.next_number) nextNumber = res.next_number | 0;
        setEntryStatus(entry, 'saved');
        dirtySinceOpen = true;
        return { ok: true, question_id: entry.questionId | 0, res: res };
      }).catch(function (err) {
        if (err && err.name === 'AbortError') return { ok: true, aborted: true };
        if ((entry.rev | 0) !== revAtSend) return { ok: true, stale: true };
        setEntryStatus(entry, 'error', 'Network error');
        if (entry.retryTimer) clearTimeout(entry.retryTimer);
        entry.retryTimer = setTimeout(function () {
          entry.retryTimer = null;
          scheduleSave(entry);
        }, RETRY_MS);
        return { ok: false, error: 'Network error while saving. Please try again.' };
      }).finally(function () {
        entry.inFlight = false;
        if (entry.abortController === ac) entry.abortController = null;
      });
    }

    function buildEntryDom(seed, displayNumber) {
      seed = seed || {};
      var isTf = allowTf && String(seed.question_type || '') === 'tf';
      var extraSeed = seed.extra_choices && typeof seed.extra_choices === 'object' ? seed.extra_choices : {};
      var wrap = document.createElement('article');
      wrap.className = 'eqb-rapid-entry';
      var typeHtml = allowTf
        ? ('<label class="eqb-field"><span class="eqb-label">Question Type</span>' +
           '<select class="eqb-select" data-type>' +
             '<option value="mcq"' + (!isTf ? ' selected' : '') + '>Multiple Choice</option>' +
             '<option value="tf"' + (isTf ? ' selected' : '') + '>True or False</option>' +
           '</select></label>')
        : ('<label class="eqb-field"><span class="eqb-label">Question Type</span>' +
           '<select class="eqb-select" data-type disabled>' +
             '<option value="mcq" selected>Multiple Choice</option>' +
           '</select>' +
           (isDiagnostic ? '<p class="eqb-hint m-0 mt-1">Diagnostic exams currently support Multiple Choice only.</p>' : '') +
           '</label>');

      var choiceRows = '';
      ['A', 'B', 'C', 'D'].forEach(function (L) {
        var key = 'choice_' + L.toLowerCase();
        var val = isTf ? '' : String(seed[key] || '');
        choiceRows += '<div class="eqb-choice-row"><span class="eqb-choice-letter">' + L + '</span>' +
          '<input class="eqb-choice-input" data-choice-letter="' + L + '" value="' + esc(val) + '" placeholder="Choice ' + L + '"></div>';
      });
      Object.keys(extraSeed).sort().forEach(function (L) {
        if (!/^[E-Z]$/.test(L)) return;
        choiceRows += '<div class="eqb-choice-row"><span class="eqb-choice-letter">' + L + '</span>' +
          '<input class="eqb-choice-input" data-choice-letter="' + L + '" value="' + esc(String(extraSeed[L] || '')) + '" placeholder="Choice ' + L + '"></div>';
      });

      var correctHtml = isDiagnostic
        ? ('<label class="eqb-field eqb-correct-wrap"><span class="eqb-label">Correct Answer</span>' +
           '<select class="eqb-select eqb-correct-select" data-correct></select></label>')
        : ('<label class="eqb-field eqb-correct-wrap"><span class="eqb-label">Correct Answer</span>' +
           '<select class="eqb-select" data-correct></select></label>');

      var topicHtml = isDiagnostic
        ? ('<label class="eqb-field"><span class="eqb-label">Topic <span class="opacity-60">(Optional)</span></span>' +
           '<select class="eqb-select" data-topic disabled><option value="">— None —</option></select>' +
           '<p class="eqb-hint m-0 mt-1">Topics are optional for now. Scoring remains overall + per subject.</p></label>')
        : '';

      wrap.innerHTML =
        '<div class="eqb-rapid-entry__head">' +
          '<h4 class="eqb-rapid-entry__title" data-num>Question ' + (displayNumber | 0) + '</h4>' +
          statusHtml(seed.question_id ? 'saved' : 'draft') +
        '</div>' +
        '<div class="eqb-field">' +
          '<span class="eqb-label">Question</span>' +
          '<p class="eqb-hint">Use formatting for readability. Tables are recommended for accounting data.</p>' +
          '<textarea class="js-exam-q-richtext eqb-rapid-text" data-text rows="8">' + esc(seed.question_text || '') + '</textarea>' +
        '</div>' +
        typeHtml +
        '<div class="eqb-choices eqb-type-panel" data-mcq ' + (isTf ? 'hidden' : '') + '>' +
          '<div class="eqb-choices__head"><h3 class="eqb-section-title">Answer Choices</h3>' +
            '<p class="eqb-hint m-0">A and B are required. C and D are optional. Use + Add Choice for E, F, G…</p></div>' +
          '<div data-choices-mount>' + choiceRows + '</div>' +
          '<button type="button" class="admin-btn admin-btn--ghost admin-btn--sm mt-2" data-add-choice>+ Add Choice</button>' +
        '</div>' +
        correctHtml +
        topicHtml;

      var typeEl = wrap.querySelector('select[data-type]');
      var choicesMount = wrap.querySelector('[data-choices-mount]');
      var entry = {
        root: wrap,
        questionId: seed.question_id | 0,
        displayNumber: displayNumber | 0,
        rev: 0,
        status: seed.question_id ? 'saved' : 'draft',
        numEl: wrap.querySelector('[data-num]'),
        statusEl: wrap.querySelector('[data-status]'),
        typeSel: typeEl,
        textEl: wrap.querySelector('[data-text]'),
        mcqBlock: wrap.querySelector('[data-mcq]'),
        tfBlock: null,
        choicesMount: choicesMount,
        choiceInputs: choicesMount ? Array.prototype.slice.call(choicesMount.querySelectorAll('[data-choice-letter]')) : [],
        choiceA: choicesMount ? choicesMount.querySelector('[data-choice-letter="A"]') : null,
        choiceB: choicesMount ? choicesMount.querySelector('[data-choice-letter="B"]') : null,
        choiceC: choicesMount ? choicesMount.querySelector('[data-choice-letter="C"]') : null,
        choiceD: choicesMount ? choicesMount.querySelector('[data-choice-letter="D"]') : null,
        addChoiceBtn: wrap.querySelector('[data-add-choice]'),
        correctSel: wrap.querySelector('[data-correct]'),
        correctRadiosWrap: null,
        correctRadios: [],
        editor: null,
        _mcqBackup: null,
        timer: null,
        inserting: false,
        pendingAfterInsert: false,
        insertPromise: null,
        inFlight: false,
        abortController: null,
        retryTimer: null
      };

      // Hide Add Choice for regular TF-capable UI when not diagnostic? Keep for both for consistency on MCQ.
      if (!isDiagnostic && entry.addChoiceBtn) {
        // Regular exam schema is A–D only — hide E+ for regular to avoid broken saves.
        entry.addChoiceBtn.hidden = true;
      }

      applyTypeUi(entry, { switching: false });
      if (seed.correct_answer) {
        var seedCor = String(seed.correct_answer).toUpperCase();
        if (isTf) {
          entry.correctSel.value = (seedCor === 'A' || seedCor === 'B') ? seedCor : '';
        } else if (/^[A-Z]$/.test(seedCor)) {
          // Prefer seeded value; rebuildCorrectOptions may clear if choice text missing.
          entry.correctSel.value = seedCor;
        }
      }
      rebuildCorrectOptions(entry, isTf, seed.correct_answer ? String(seed.correct_answer).toUpperCase() : '');

      function onChange() { scheduleSave(entry); }
      function onChoiceChange() {
        rebuildCorrectOptions(entry, allowTf && entry.typeSel && entry.typeSel.value === 'tf', entry.correctSel ? entry.correctSel.value : '');
        onChange();
      }
      if (entry.typeSel && entry.typeSel.tagName === 'SELECT' && !entry.typeSel.disabled) {
        entry.typeSel.addEventListener('change', function () {
          applyTypeUi(entry, { switching: true });
          onChange();
        });
      }
      entry.choiceInputs.forEach(function (el) {
        el.addEventListener('input', onChoiceChange);
        el.addEventListener('change', onChoiceChange);
      });
      if (entry.correctSel) {
        entry.correctSel.addEventListener('change', onChange);
      }
      if (entry.addChoiceBtn) {
        entry.addChoiceBtn.addEventListener('click', function () {
          addExtraChoiceRow(entry);
        });
      }

      return entry;
    }

    function openAddModal() {
      if (locked) return;
      destroyAllEditors(entries);
      entries = [];
      if (addList) addList.innerHTML = '';
      if (rapidTitleEl) {
        rapidTitleEl.textContent = isDiagnostic ? 'Add Question' : 'Add Questions';
      }
      if (rapidSubtitleEl) {
        rapidSubtitleEl.textContent = isDiagnostic ? modalProgressLabel(true) : '';
      }
      sessionNext = Math.max(questions.length + 1, nextNumber);
      var first = buildEntryDom(null, allocateDisplayNumber());
      entries.push(first);
      if (addList) addList.appendChild(first.root);
      openOverlay(addOverlay);
      setTimeout(function () {
        initEntryEditor(first);
        if (first.editor) first.editor.focus();
        else if (first.textEl) first.textEl.focus();
      }, 30);
    }

    function addAnother() {
      if (locked) return;
      // Flush TinyMCE into textareas before validating/saving.
      entries.forEach(function (e) { getEditorContent(e); });
      var unsaved = entries.filter(function (e) {
        return !(e.questionId | 0) || e.status !== 'saved';
      });
      var chain = Promise.resolve({ ok: true });
      unsaved.forEach(function (e) {
        chain = chain.then(function (prev) {
          if (prev && prev.ok === false) return prev;
          return flushSave(e);
        });
      });
      chain.then(function (last) {
        if (last && last.ok === false) {
          window.alert((last && last.error) || 'Unable to save question. Please check the required fields.');
          return;
        }
        // Keep only a fresh blank entry for the next question in this subject.
        destroyAllEditors(entries);
        entries = [];
        if (addList) addList.innerHTML = '';
        renderTable();
        if (rapidSubtitleEl && isDiagnostic) {
          rapidSubtitleEl.textContent = modalProgressLabel(true);
        }
        var entry = buildEntryDom(null, allocateDisplayNumber());
        entries.push(entry);
        if (addList) addList.appendChild(entry.root);
        entry.root.scrollIntoView({ behavior: 'smooth', block: 'start' });
        setTimeout(function () {
          initEntryEditor(entry);
          if (entry.editor) entry.editor.focus();
          else if (entry.textEl) entry.textEl.focus();
        }, 30);
      });
    }

    function closeAddModalDiscard() {
      destroyAllEditors(entries);
      entries = [];
      if (addList) addList.innerHTML = '';
      closeOverlay(addOverlay);
      renderTable();
    }

    function closeAddModal() {
      // Flush TinyMCE into textareas before validating/saving.
      entries.forEach(function (e) { getEditorContent(e); });

      var hasContent = entries.some(function (e) {
        var p = collectPayload(e);
        var plain = String(p.question_text || '').replace(/<[^>]+>/g, ' ').replace(/&nbsp;/g, ' ').trim();
        return !!(plain || p.choice_a || p.choice_b || p.correct_answer);
      });
      var pending = entries.filter(function (e) {
        return isPersistable(collectPayload(e)) && e.status !== 'saved';
      });
      var alreadySaved = entries.every(function (e) {
        return (e.questionId | 0) > 0 && e.status === 'saved';
      });

      if (!pending.length) {
        if (hasContent && !alreadySaved) {
          window.alert('Unable to save question. Please check the required fields.');
          return;
        }
        destroyAllEditors(entries);
        entries = [];
        if (addList) addList.innerHTML = '';
        closeOverlay(addOverlay);
        if (dirtySinceOpen || alreadySaved) refreshFromServer();
        else renderTable();
        return;
      }

      var chain = Promise.resolve({ ok: true });
      pending.forEach(function (e) {
        chain = chain.then(function (prev) {
          if (prev && prev.ok === false) return prev;
          return flushSave(e);
        });
      });
      chain.then(function (last) {
        if (last && last.ok === false) {
          window.alert((last && last.error) || 'Unable to save question. Please check the required fields.');
          return;
        }
        destroyAllEditors(entries);
        entries = [];
        if (addList) addList.innerHTML = '';
        closeOverlay(addOverlay);
        refreshFromServer();
      });
    }

    function openEditModal(questionId) {
      if (locked) return;
      var q = findQuestion(questionId);
      if (!q) return;
      if (editEntry) destroyEntryEditor(editEntry);
      if (editTitleEl) {
        editTitleEl.textContent = isDiagnostic
          ? ('Edit Question' + (subjectLabel ? ' — ' + subjectLabel : ''))
          : 'Edit Question';
      }
      if (editSubtitleEl && isDiagnostic) {
        var idx = questions.indexOf(q);
        var n = (idx >= 0 ? idx + 1 : (q.display_number || 1));
        editSubtitleEl.textContent = requiredCount > 0
          ? (subjectLabel + ' · Question ' + n + ' of ' + requiredCount)
          : (subjectLabel + ' · Question ' + n);
      }
      editEntry = buildEntryDom(q, q.display_number || (questions.indexOf(q) + 1) || 1);
      if (editMount) {
        editMount.innerHTML = '';
        editMount.appendChild(editEntry.root);
      }
      openOverlay(editOverlay);
      setTimeout(function () {
        initEntryEditor(editEntry);
        if (editEntry.editor) editEntry.editor.focus();
        else if (editEntry.textEl) editEntry.textEl.focus();
      }, 30);
    }

    function closeEditModal() {
      if (editEntry) getEditorContent(editEntry);
      if (editEntry && isPersistable(collectPayload(editEntry)) && editEntry.status !== 'saved') {
        flushSave(editEntry).then(function (res) {
          if (res && res.ok === false) {
            window.alert((res && res.error) || 'Unable to save question. Please check the required fields.');
            return;
          }
          destroyEntryEditor(editEntry);
          editEntry = null;
          if (editMount) editMount.innerHTML = '';
          closeOverlay(editOverlay);
          refreshFromServer();
        });
        return;
      }
      if (editEntry && !(editEntry.questionId | 0)) {
        var p = collectPayload(editEntry);
        var plain = String(p.question_text || '').replace(/<[^>]+>/g, ' ').replace(/&nbsp;/g, ' ').trim();
        if (plain || p.choice_a || p.choice_b) {
          window.alert('Unable to save question. Please check the required fields.');
          return;
        }
      }
      if (editEntry) destroyEntryEditor(editEntry);
      editEntry = null;
      if (editMount) editMount.innerHTML = '';
      closeOverlay(editOverlay);
      if (dirtySinceOpen) refreshFromServer();
      else renderTable();
    }

    function openPreview(q) {
      var overlay = document.getElementById('eqbPreviewOverlay');
      var body = document.getElementById('eqbPreviewBody');
      if (!overlay || !body || !q) return;
      var isTf = String(q.question_type || '') === 'tf';
      var parts = [];
      parts.push('<div class="eqb-preview-stem"></div>');
      parts.push('<div class="eqb-preview-choices">');
      if (isTf) {
        parts.push('<div class="eqb-preview-choice"><div>True</div></div>');
        parts.push('<div class="eqb-preview-choice"><div>False</div></div>');
      } else {
        [['A', q.choice_a], ['B', q.choice_b], ['C', q.choice_c], ['D', q.choice_d]].forEach(function (pair) {
          if (!pair[1]) return;
          parts.push('<div class="eqb-preview-choice"><span>' + pair[0] + '</span><div>' + esc(pair[1]) + '</div></div>');
        });
      }
      parts.push('</div>');
      body.innerHTML = parts.join('');
      var stem = body.querySelector('.eqb-preview-stem');
      if (stem) stem.innerHTML = q.question_text || '<em>Empty question</em>';
      overlay.hidden = false;
      overlay.classList.add('is-open');
    }

    // Events
    if (searchEl) searchEl.addEventListener('input', applyTableFilters);
    if (filterEl) filterEl.addEventListener('change', applyTableFilters);

    document.querySelectorAll('[data-eqb-open-add]').forEach(function (btn) {
      btn.addEventListener('click', openAddModal);
    });
    if (addAnotherBtn) addAnotherBtn.addEventListener('click', addAnother);
    if (addCloseBtn) addCloseBtn.addEventListener('click', isDiagnostic ? closeAddModalDiscard : closeAddModal);
    if (addCloseBtn2) addCloseBtn2.addEventListener('click', closeAddModal);
    if (addCancelBtn) addCancelBtn.addEventListener('click', closeAddModalDiscard);
    if (addSaveBtn) addSaveBtn.addEventListener('click', closeAddModal);
    if (addOverlay) addOverlay.addEventListener('click', function (e) {
      if (e.target === addOverlay) {
        if (isDiagnostic) closeAddModalDiscard();
        else closeAddModal();
      }
    });
    // Re-bind open-add after slot re-renders
    if (slotList) {
      slotList.addEventListener('click', function (e) {
        var btn = e.target && e.target.closest ? e.target.closest('[data-eqb-open-add]') : null;
        if (btn) {
          e.preventDefault();
          openAddModal();
        }
      });
    }
    if (editCloseBtn) editCloseBtn.addEventListener('click', closeEditModal);
    if (editCloseBtn2) editCloseBtn2.addEventListener('click', closeEditModal);
    if (editOverlay) editOverlay.addEventListener('click', function (e) {
      if (e.target === editOverlay) closeEditModal();
    });

    document.addEventListener('click', function (e) {
      var t = e.target;
      if (!t || !t.closest) return;

      var moreBtn = t.closest('[data-eqb-more]');
      if (moreBtn) {
        e.preventDefault();
        var wrap = moreBtn.closest('.eqb-more-wrap');
        var menu = wrap ? wrap.querySelector('.admin-student-action-menu') : null;
        document.querySelectorAll('.eqb-more-wrap .admin-student-action-menu').forEach(function (m) {
          if (m !== menu) { m.hidden = true; m.classList.remove('open'); }
        });
        if (menu) {
          menu.hidden = !menu.hidden;
          menu.classList.toggle('open', !menu.hidden);
        }
        return;
      }

      if (!t.closest('.eqb-more-wrap')) {
        document.querySelectorAll('.eqb-more-wrap .admin-student-action-menu').forEach(function (m) {
          m.hidden = true; m.classList.remove('open');
        });
      }

      var editBtn = t.closest('[data-eqb-edit]');
      if (editBtn) {
        e.preventDefault();
        openEditModal(editBtn.getAttribute('data-eqb-edit'));
        return;
      }

      var prevBtn = t.closest('[data-eqb-preview-id]');
      if (prevBtn) {
        e.preventDefault();
        openPreview(findQuestion(prevBtn.getAttribute('data-eqb-preview-id')));
        return;
      }
    });

    document.addEventListener('submit', function (e) {
      var form = e.target;
      if (!form || !form.getAttribute) return;
      if (form.getAttribute('data-eqb-delete-form') !== null) {
        e.preventDefault();
        if (!window.confirm('Delete this question?')) return;
        var qidInput = form.querySelector('input[name="question_id"]');
        var qid = qidInput ? (qidInput.value | 0) : 0;
        if (!qid) return;
        post('delete_question', { question_id: qid }).then(function (res) {
          if (!res || !res.ok) {
            window.alert((res && res.error) || 'Could not delete question.');
            return;
          }
          if (Array.isArray(res.questions)) {
            questions = res.questions.slice();
          } else {
            questions = questions.filter(function (q) { return (q.question_id | 0) !== qid; });
          }
          if (res.next_number) nextNumber = res.next_number | 0;
          dirtySinceOpen = true;
          renderTable();
        }).catch(function () {
          window.alert('Network error while deleting.');
        });
      }
    });

    var prevOverlay = document.getElementById('eqbPreviewOverlay');
    var prevClose = document.getElementById('eqbPreviewClose');
    if (prevClose && prevOverlay) {
      prevClose.addEventListener('click', function () {
        prevOverlay.hidden = true;
        prevOverlay.classList.remove('is-open');
      });
      prevOverlay.addEventListener('click', function (e) {
        if (e.target === prevOverlay) {
          prevOverlay.hidden = true;
          prevOverlay.classList.remove('is-open');
        }
      });
    }

    // Deep-link edit support
    if (cfg.initialEditId) {
      openEditModal(cfg.initialEditId);
    }

    renderTable();

    return {
      refreshFromServer: refreshFromServer,
      openAddModal: openAddModal,
      openEditModal: openEditModal
    };
  }

  window.EreviewQuestionRapidEntry = { create: createController };
})(window);
