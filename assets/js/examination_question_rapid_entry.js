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
    return String(t || '').toLowerCase() === 'tf' ? 'True / False' : 'Multiple Choice';
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
    var topicOptions = Array.isArray(cfg.topicOptions) ? cfg.topicOptions : [];
    var subjectOptions = Array.isArray(cfg.subjectOptions) ? cfg.subjectOptions : [];
    var breakdownMode = String(cfg.breakdownMode || 'overall');
    var blueprint = cfg.blueprint && typeof cfg.blueprint === 'object' ? cfg.blueprint : null;
    var subjectsRequired = !isDiagnostic && (breakdownMode === 'subject' || breakdownMode === 'subject_topic') && subjectOptions.length > 0;
    var topicsOptional = !isDiagnostic && breakdownMode === 'subject_topic' && topicOptions.length > 0;
    var showSubjectCols = subjectsRequired;
    var showTopicCols = topicsOptional;
    var tableColspan = 5 + (showSubjectCols ? 1 : 0) + (showTopicCols ? 1 : 0);
    var sortKey = 'num';
    var sortDir = 'asc';
    var searchDebounceTimer = null;

    var questions = Array.isArray(cfg.questions) ? cfg.questions.slice() : [];
    var nextNumber = (cfg.nextNumber | 0) || (questions.length + 1);

    var tableBody = document.getElementById(cfg.tableBodyId || 'eqbQuestionRows');
    var slotList = document.getElementById(cfg.slotListId || 'diagSlotList');
    var countEl = document.getElementById(cfg.countId || 'eqbQuestionCount');
    var searchEl = document.getElementById(cfg.searchId || 'eqbSearch');
    var filterEl = document.getElementById(cfg.filterId || 'eqbTypeFilter');
    var subjectFilterEl = document.getElementById('eqbSubjectFilter');
    var topicFilterEl = document.getElementById('eqbTopicFilter');
    var answerFilterEl = document.getElementById('eqbAnswerFilter');
    var coverageFilterEl = document.getElementById('eqbCoverageFilter');
    var coverageEl = document.getElementById('eqbCoverage');
    var coverageTotalEl = document.getElementById('eqbCoverageTotal');
    var coverageListEl = document.getElementById('eqbCoverageList');
    var questionsTableEl = document.getElementById('eqbQuestionsTable');
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

    function setCount(n, showing) {
      if (countEl) {
        var showN = showing == null ? n : showing;
        countEl.innerHTML = '<strong>' + String(n) + '</strong> Question' + (n === 1 ? '' : 's')
          + ' · Showing ' + String(showN);
      }
      nextNumber = n + 1;
    }

    function choiceTextForAnswer(q, letter) {
      letter = String(letter || '').toUpperCase();
      if (letter === 'A') return String(q.choice_a || '').trim();
      if (letter === 'B') return String(q.choice_b || '').trim();
      if (letter === 'C') return String(q.choice_c || '').trim();
      if (letter === 'D') return String(q.choice_d || '').trim();
      return '';
    }

    function correctAnswerMeta(q) {
      var typ = String(q.question_type || 'mcq') === 'tf' ? 'tf' : 'mcq';
      var letter = String(q.correct_answer || '').toUpperCase();
      if (typ === 'tf') {
        var tf = letter === 'A' ? 'True' : (letter === 'B' ? 'False' : (letter || '—'));
        return { filter: tf, primary: tf, secondary: '', sort: tf };
      }
      var text = choiceTextForAnswer(q, letter);
      return {
        filter: letter || '',
        primary: letter || '—',
        secondary: text,
        sort: (letter || '') + ' ' + text
      };
    }

    function correctAnswerHtml(q) {
      var meta = correctAnswerMeta(q);
      return '<span class="eqb-ans-compact" title="' + esc(meta.secondary || meta.primary) + '"><span class="eqb-ans-compact__key">' + esc(meta.primary) + '</span></span>';
    }

    function bucketCoverageStatus(sid, tid) {
      blueprint = recomputeBlueprintFromQuestions();
      if (!blueprint || !blueprint.configured) return 'all';
      if (breakdownMode === 'overall') {
        var auth = blueprint.total_authored | 0;
        var req = blueprint.total_required | 0;
        if (req > 0 && auth > req) return 'over';
        if (req > 0 && auth >= req) return 'complete';
        return 'missing';
      }
      var ss = null;
      (blueprint.subjects || []).forEach(function (s) {
        if ((s.exam_subject_id | 0) === (sid | 0)) ss = s;
      });
      if (!ss) return 'missing';
      if (tid > 0 && Array.isArray(ss.topics) && ss.topics.length) {
        var tt = null;
        ss.topics.forEach(function (t) {
          if ((t.exam_topic_id | 0) === (tid | 0)) tt = t;
        });
        if (!tt) return 'missing';
        var ta = tt.authored | 0;
        var tr = tt.required | 0;
        if (tr > 0 && ta > tr) return 'over';
        if (tr > 0 && ta >= tr) return 'complete';
        return 'missing';
      }
      var sa = ss.authored | 0;
      var sr = ss.questions_required | 0;
      if (sr > 0 && sa > sr) return 'over';
      if (sr > 0 && sa >= sr) return 'complete';
      return 'missing';
    }

    function syncTopicFilterOptions() {
      if (!topicFilterEl) return;
      var sid = subjectFilterEl ? String(subjectFilterEl.value || 'all') : 'all';
      var cur = String(topicFilterEl.value || 'all');
      var keep = false;
      Array.prototype.forEach.call(topicFilterEl.options, function (opt) {
        if (opt.value === 'all') {
          opt.hidden = false;
          return;
        }
        var osid = opt.getAttribute('data-subject-id') || '0';
        var show = sid === 'all' || osid === sid;
        opt.hidden = !show;
        if (show && opt.value === cur) keep = true;
      });
      if (!keep) topicFilterEl.value = 'all';
    }

    function updateSortIndicators() {
      if (!questionsTableEl) return;
      questionsTableEl.querySelectorAll('[data-eqb-sort]').forEach(function (th) {
        var ind = th.querySelector('.eqb-sort-ind');
        var key = th.getAttribute('data-eqb-sort');
        th.classList.toggle('is-sorted', key === sortKey);
        th.classList.toggle('is-asc', key === sortKey && sortDir === 'asc');
        th.classList.toggle('is-desc', key === sortKey && sortDir === 'desc');
        if (ind) ind.textContent = key === sortKey ? (sortDir === 'asc' ? '↑' : '↓') : '';
      });
    }

    function sortQuestionsInPlace() {
      var dir = sortDir === 'desc' ? -1 : 1;
      questions.sort(function (a, b) {
        var av = '';
        var bv = '';
        if (sortKey === 'num') {
          return ((a.display_number | 0) - (b.display_number | 0)) * dir
            || ((a.question_id | 0) - (b.question_id | 0)) * dir;
        }
        if (sortKey === 'question') {
          av = String(a.preview || plainPreview(a.question_text) || '').toLowerCase();
          bv = String(b.preview || plainPreview(b.question_text) || '').toLowerCase();
        } else if (sortKey === 'subject') {
          av = String(a.subject_name || '').toLowerCase();
          bv = String(b.subject_name || '').toLowerCase();
        } else if (sortKey === 'topic') {
          av = String(a.topic_name || '').toLowerCase();
          bv = String(b.topic_name || '').toLowerCase();
        } else if (sortKey === 'type') {
          av = String(a.question_type || '');
          bv = String(b.question_type || '');
        } else if (sortKey === 'answer') {
          av = correctAnswerMeta(a).sort.toLowerCase();
          bv = correctAnswerMeta(b).sort.toLowerCase();
        }
        if (av < bv) return -1 * dir;
        if (av > bv) return 1 * dir;
        return ((a.question_id | 0) - (b.question_id | 0)) * dir;
      });
    }

    function statusLabel(st, remaining) {
      if (st === 'complete') return 'Complete';
      if (st === 'progress') return 'In progress';
      return (remaining | 0) > 0 ? ((remaining | 0) + ' remaining') : 'Missing';
    }

    function recomputeBlueprintFromQuestions() {
      if (!blueprint || !blueprint.configured) return blueprint;
      var mode = breakdownMode;
      var next = JSON.parse(JSON.stringify(blueprint));
      if (mode === 'overall') {
        var req = next.total_required | 0;
        var authored = questions.length;
        next.total_authored = authored;
        next.remaining = Math.max(0, req - authored);
        next.status = req > 0 && authored >= req ? 'complete' : (authored > 0 ? 'progress' : 'missing');
        next.ok = req <= 0 || authored >= req;
        return next;
      }
      var bySub = {};
      var byTopic = {};
      questions.forEach(function (q) {
        var sid = q.exam_subject_id | 0;
        var tid = q.exam_topic_id | 0;
        if (sid > 0) bySub[sid] = (bySub[sid] | 0) + 1;
        if (tid > 0) byTopic[tid] = (byTopic[tid] | 0) + 1;
      });
      var totalAuth = 0;
      var totalReq = 0;
      var allOk = true;
      (next.subjects || []).forEach(function (ss) {
        var sid = ss.exam_subject_id | 0;
        var topics = ss.topics || [];
        if (mode === 'subject_topic' && topics.length) {
          var subAuth = 0;
          var subOk = true;
          topics.forEach(function (tt) {
            var tid = tt.exam_topic_id | 0;
            var auth = byTopic[tid] | 0;
            var req = tt.required | 0;
            tt.authored = auth;
            tt.remaining = Math.max(0, req - auth);
            tt.status = req > 0 && auth >= req ? 'complete' : (auth > 0 ? 'progress' : 'missing');
            tt.ok = req > 0 && auth >= req;
            if (!tt.ok) subOk = false;
            subAuth += auth;
          });
          ss.authored = subAuth;
          ss.ok = subOk;
        } else {
          var authS = bySub[sid] | 0;
          ss.authored = authS;
          ss.ok = (ss.questions_required | 0) > 0 && authS >= (ss.questions_required | 0);
        }
        var reqS = ss.questions_required | 0;
        ss.remaining = Math.max(0, reqS - (ss.authored | 0));
        ss.status = reqS > 0 && (ss.authored | 0) >= reqS ? 'complete' : ((ss.authored | 0) > 0 ? 'progress' : 'missing');
        if (!ss.ok) allOk = false;
        totalAuth += ss.authored | 0;
        totalReq += reqS;
      });
      next.total_authored = totalAuth;
      next.total_required = totalReq || (next.total_required | 0);
      next.remaining = Math.max(0, (next.total_required | 0) - totalAuth);
      next.ok = allOk;
      next.status = next.ok ? 'complete' : (totalAuth > 0 ? 'progress' : 'missing');
      return next;
    }

    function updateRegularCoverage() {
      if (isDiagnostic || !coverageEl) return;
      blueprint = recomputeBlueprintFromQuestions();
      if (!blueprint || !blueprint.configured) return;
      var auth = blueprint.total_authored | 0;
      var req = blueprint.total_required | 0;
      var remain = blueprint.remaining | 0;
      if (coverageTotalEl) {
        var html = 'Total: <strong>' + auth + '</strong> / <strong>' + req + '</strong>';
        if (remain > 0) html += ' <span class="eqb-coverage__remain">' + remain + ' remaining</span>';
        else html += ' <span class="eqb-coverage__badge eqb-coverage__badge--complete">Complete</span>';
        coverageTotalEl.innerHTML = html;
      }
      if (!coverageListEl) return;
      var rowsHtml = '';
      if (breakdownMode === 'overall') {
        var st = blueprint.status || 'missing';
        rowsHtml += '<div class="eqb-coverage__row eqb-coverage__row--' + st + '">';
        rowsHtml += '<span class="eqb-coverage__name">Overall</span>';
        rowsHtml += '<span class="eqb-coverage__frac">' + auth + ' / ' + req + '</span>';
        rowsHtml += '<span class="eqb-coverage__badge eqb-coverage__badge--' + st + '">' + esc(statusLabel(st, remain)) + '</span></div>';
      } else {
        (blueprint.subjects || []).forEach(function (ss) {
          var st = ss.status || 'missing';
          rowsHtml += '<div class="eqb-coverage__row eqb-coverage__row--' + st + '" data-subject-id="' + (ss.exam_subject_id | 0) + '">';
          rowsHtml += '<span class="eqb-coverage__name">' + esc(ss.subject_name || '') + '</span>';
          rowsHtml += '<span class="eqb-coverage__frac">' + (ss.authored | 0) + ' / ' + (ss.questions_required | 0) + '</span>';
          rowsHtml += '<span class="eqb-coverage__badge eqb-coverage__badge--' + st + '">' + esc(statusLabel(st, ss.remaining | 0)) + '</span></div>';
          if (showTopicCols && Array.isArray(ss.topics)) {
            ss.topics.forEach(function (tt) {
              var tst = tt.status || 'missing';
              rowsHtml += '<div class="eqb-coverage__row eqb-coverage__row--topic eqb-coverage__row--' + tst + '" data-topic-id="' + (tt.exam_topic_id | 0) + '">';
              rowsHtml += '<span class="eqb-coverage__name">' + esc(tt.topic_name || '') + '</span>';
              rowsHtml += '<span class="eqb-coverage__frac">' + (tt.authored | 0) + ' / ' + (tt.required | 0) + '</span>';
              rowsHtml += '<span class="eqb-coverage__badge eqb-coverage__badge--' + tst + '">' + esc(statusLabel(tst, tt.remaining | 0)) + '</span></div>';
            });
          }
        });
      }
      coverageListEl.innerHTML = rowsHtml;
    }

    function bucketCapacityLabel(subjectId, topicId) {
      blueprint = recomputeBlueprintFromQuestions();
      if (!blueprint || !blueprint.configured) return '';
      if (breakdownMode === 'overall') {
        var req = blueprint.total_required | 0;
        var auth = blueprint.total_authored | 0;
        var rem = Math.max(0, req - auth);
        if (req <= 0) return '';
        if (rem <= 0) return 'Complete — ' + auth + ' / ' + req;
        return auth + ' / ' + req + ', ' + rem + ' remaining';
      }
      var ss = null;
      (blueprint.subjects || []).forEach(function (s) {
        if ((s.exam_subject_id | 0) === (subjectId | 0)) ss = s;
      });
      if (!ss) return '';
      if (topicId > 0 && Array.isArray(ss.topics)) {
        var tt = null;
        ss.topics.forEach(function (t) {
          if ((t.exam_topic_id | 0) === (topicId | 0)) tt = t;
        });
        if (!tt) return '';
        var remT = tt.remaining | 0;
        if (remT <= 0) return 'Complete — ' + (tt.authored | 0) + ' / ' + (tt.required | 0);
        return (ss.subject_name || '') + ' / ' + (tt.topic_name || '') + ' — ' + (tt.authored | 0) + ' / ' + (tt.required | 0) + ', ' + remT + ' remaining';
      }
      var remS = ss.remaining | 0;
      if (remS <= 0) return 'Complete — ' + (ss.authored | 0) + ' / ' + (ss.questions_required | 0);
      return (ss.subject_name || '') + ' — ' + (ss.authored | 0) + ' / ' + (ss.questions_required | 0) + ', ' + remS + ' remaining';
    }

    function isBucketFull(subjectId, topicId, excludeQid) {
      var counts = {};
      questions.forEach(function (q) {
        if ((q.question_id | 0) === (excludeQid | 0)) return;
        if (breakdownMode === 'overall') {
          counts.all = (counts.all | 0) + 1;
          return;
        }
        if (topicId > 0) {
          if ((q.exam_topic_id | 0) === (topicId | 0)) counts.t = (counts.t | 0) + 1;
        } else if (subjectId > 0) {
          if ((q.exam_subject_id | 0) === (subjectId | 0)) counts.s = (counts.s | 0) + 1;
        }
      });
      if (breakdownMode === 'overall') {
        var reqO = (blueprint && blueprint.total_required) | 0;
        return reqO > 0 && (counts.all | 0) >= reqO;
      }
      var ss = null;
      (blueprint && blueprint.subjects || []).forEach(function (s) {
        if ((s.exam_subject_id | 0) === (subjectId | 0)) ss = s;
      });
      if (!ss) return false;
      if (topicId > 0) {
        var tt = null;
        (ss.topics || []).forEach(function (t) {
          if ((t.exam_topic_id | 0) === (topicId | 0)) tt = t;
        });
        return tt && (tt.required | 0) > 0 && (counts.t | 0) >= (tt.required | 0);
      }
      return (ss.questions_required | 0) > 0 && (counts.s | 0) >= (ss.questions_required | 0);
    }

    function applyTableFilters() {
      if (!tableBody) return;
      syncTopicFilterOptions();
      var q = searchEl ? String(searchEl.value || '').toLowerCase().trim() : '';
      var f = filterEl ? String(filterEl.value || 'all') : 'all';
      var sf = subjectFilterEl ? String(subjectFilterEl.value || 'all') : 'all';
      var tf = topicFilterEl ? String(topicFilterEl.value || 'all') : 'all';
      var af = answerFilterEl ? String(answerFilterEl.value || 'all') : 'all';
      var cf = coverageFilterEl ? String(coverageFilterEl.value || 'all') : 'all';
      var shown = 0;
      tableBody.querySelectorAll('tr[data-eqb-row]').forEach(function (tr) {
        var hay = tr.getAttribute('data-eqb-search') || '';
        var typ = tr.getAttribute('data-eqb-type') || 'mcq';
        var sid = tr.getAttribute('data-eqb-subject') || '0';
        var tid = tr.getAttribute('data-eqb-topic') || '0';
        var ans = tr.getAttribute('data-eqb-answer') || '';
        var typeOk = f === 'all' || (f === 'mcq' && typ === 'mcq') || (f === 'tf' && typ === 'tf');
        var searchOk = !q || hay.indexOf(q) !== -1;
        var subOk = sf === 'all' || sid === String(sf);
        var topOk = tf === 'all' || tid === String(tf);
        var ansOk = af === 'all' || ans === af;
        var covOk = true;
        if (cf !== 'all') {
          covOk = bucketCoverageStatus(sid | 0, tid | 0) === cf;
        }
        var visible = typeOk && searchOk && subOk && topOk && ansOk && covOk;
        tr.style.display = visible ? '' : 'none';
        if (visible) shown++;
      });
      tableBody.querySelectorAll('tr.eqb-group-row').forEach(function (g) {
        var vis = false;
        var n = g.nextElementSibling;
        while (n && !n.classList.contains('eqb-group-row')) {
          if (n.getAttribute('data-eqb-row') !== null && n.style.display !== 'none') vis = true;
          n = n.nextElementSibling;
        }
        g.style.display = vis ? '' : 'none';
      });
      setCount(questions.length, shown);
    }

    function scheduleApplyTableFilters() {
      if (searchDebounceTimer) clearTimeout(searchDebounceTimer);
      searchDebounceTimer = setTimeout(function () {
        searchDebounceTimer = null;
        applyTableFilters();
      }, 160);
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
      sortQuestionsInPlace();
      updateSortIndicators();
      updateRegularCoverage();
      if (!questions.length) {
        setCount(0, 0);
        tableBody.innerHTML = '<tr><td colspan="' + tableColspan + '" class="students-empty-cell">No questions yet. Use Add Question or Import.</td></tr>';
        return;
      }
      var html = '';
      var prevG = null;
      questions.forEach(function (q, i) {
        var gk = q.group_key ? String(q.group_key) : '';
        if (showSubjectCols && gk && gk !== 'overall' && gk !== prevG) {
          prevG = gk;
          var parent = q.group_parent ? String(q.group_parent) : '';
          var lab = q.group_label ? String(q.group_label) : '';
          var title = parent ? (parent + ' · ' + lab) : lab;
          if (title) {
            html += '<tr class="eqb-group-row" data-eqb-group="' + esc(gk) + '"><td colspan="' + tableColspan + '">' + esc(title) + '</td></tr>';
          }
        }
        var num = (q.display_number | 0) || (i + 1);
        var typ = String(q.question_type || 'mcq') === 'tf' ? 'tf' : 'mcq';
        var preview = q.preview || plainPreview(q.question_text, 72);
        var compactPreview = plainPreview(q.question_text || q.preview, 72);
        var tLabel = q.type_label || typeLabel(typ);
        var sName = q.subject_name || '';
        var topName = q.topic_name || '';
        if (!sName && (q.exam_subject_id | 0)) {
          subjectOptions.forEach(function (o) {
            if ((o.exam_subject_id | 0) === (q.exam_subject_id | 0)) sName = o.subject_name || '';
          });
        }
        if (!topName && (q.exam_topic_id | 0)) {
          topicOptions.forEach(function (o) {
            if ((o.exam_topic_id | 0) === (q.exam_topic_id | 0)) topName = o.topic_name || '';
          });
        }
        var ansMeta = correctAnswerMeta(q);
        var hay = (compactPreview + ' ' + (q.question_text || '') + ' ' + (q.correct_answer || '') + ' ' + sName + ' ' + topName + ' ' + ansMeta.secondary).toLowerCase();
        html += '<tr data-eqb-row data-eqb-id="' + (q.question_id | 0) + '" data-eqb-type="' + typ + '" data-eqb-subject="' + (q.exam_subject_id | 0) + '" data-eqb-topic="' + (q.exam_topic_id | 0) + '" data-eqb-answer="' + esc(ansMeta.filter) + '" data-eqb-search="' + esc(hay) + '">';
        html += '<td class="eqb-td-num">' + num + '</td>';
        html += '<td class="eqb-td-question"><span class="eqb-q-preview" title="' + esc(preview) + '">' + esc(compactPreview) + '</span></td>';
        if (showSubjectCols) html += '<td class="eqb-td-subject">' + esc(sName || '—') + '</td>';
        if (showTopicCols) html += '<td class="eqb-td-topic">' + esc(topName || '—') + '</td>';
        html += '<td class="eqb-td-type"><span class="eqb-type">' + esc(tLabel) + '</span></td>';
        html += '<td class="eqb-td-answer">' + correctAnswerHtml(q) + '</td>';
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
        }         else {
          html += '<span class="eqb-locked" title="Question editing is locked because this examination already has student attempts."><i class="bi bi-lock-fill" aria-hidden="true"></i> Locked</span>';
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
        if (res.blueprint && typeof res.blueprint === 'object') blueprint = res.blueprint;
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
        exam_topic_id: entry.topicSel ? (entry.topicSel.value | 0) : 0,
        exam_subject_id: entry.subjectSel ? (entry.subjectSel.value | 0) : 0,
        client_rev: entry.rev | 0
      };
    }

    function isPersistable(payload) {
      var plain = String(payload.question_text || '').replace(/<[^>]+>/g, ' ').replace(/&nbsp;/g, ' ').trim();
      if (!plain) return false;
      if (subjectsRequired && !(payload.exam_subject_id > 0)) return false;
      if (topicsOptional && payload.exam_subject_id > 0) {
        var needsTopic = false;
        topicOptions.forEach(function (o) {
          if ((o.exam_subject_id | 0) === (payload.exam_subject_id | 0)) needsTopic = true;
        });
        if (needsTopic && !(payload.exam_topic_id > 0)) return false;
      }
      if (!isDiagnostic && blueprint && blueprint.configured) {
        if (isBucketFull(payload.exam_subject_id | 0, payload.exam_topic_id | 0, payload.question_id | 0)) {
          return false;
        }
      }
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
          o.textContent = pair[0] + ' — ' + pair[1];
          entry.correctSel.appendChild(o);
        });
        entry.correctSel.value = (prev === 'A' || prev === 'B') ? prev : '';
        syncTfRadios(entry);
        syncChoiceCorrectState(entry);
        return;
      }
      // Letter options with live choice preview (A — Cash). Values remain A/B/C/D for the API.
      var letters = isDiagnostic ? availableCorrectLetters(entry, false) : ['A', 'B', 'C', 'D'];
      if (!isDiagnostic) {
        // Always keep A–D selectable for regular schema; empty choices still listed.
        letters = ['A', 'B', 'C', 'D'];
      }
      letters.forEach(function (L) {
        var inp = choiceInputByLetter(entry, L);
        var o = document.createElement('option');
        o.value = L;
        o.textContent = choiceOptionLabel(L, inp ? inp.value : '');
        entry.correctSel.appendChild(o);
      });
      if (isDiagnostic) {
        entry.correctSel.value = letters.indexOf(prev) >= 0 ? prev : '';
      } else {
        entry.correctSel.value = /^[A-D]$/.test(prev) ? prev : '';
      }
      syncChoiceCorrectState(entry);
      updateChoicesCount(entry);
    }

    function syncTfRadios(entry) {
      if (!entry || !entry.tfRadios) return;
      var cur = entry.correctSel ? String(entry.correctSel.value || '').toUpperCase() : '';
      entry.tfRadios.forEach(function (r) {
        r.checked = String(r.value || '').toUpperCase() === cur;
      });
    }

    function syncChoiceCorrectState(entry) {
      if (!entry || !entry.root) return;
      var cur = entry.correctSel ? String(entry.correctSel.value || '').toUpperCase() : '';
      entry.root.querySelectorAll('.eqb-choice-row').forEach(function (row) {
        var inp = row.querySelector('[data-choice-letter]');
        var L = inp ? String(inp.getAttribute('data-choice-letter') || '').toUpperCase() : '';
        row.classList.toggle('is-correct', !!L && L === cur);
      });
      entry.root.querySelectorAll('.eqb-tf-choice').forEach(function (row) {
        var r = row.querySelector('[data-tf-correct]');
        var L = r ? String(r.value || '').toUpperCase() : '';
        row.classList.toggle('is-correct', !!L && L === cur);
      });
    }

    function updateChoicesCount(entry) {
      if (!entry || !entry.choicesCountEl) return;
      var n = 0;
      (entry.choiceInputs || []).forEach(function (inp) {
        if (String(inp.value || '').trim() !== '') n += 1;
      });
      entry.choicesCountEl.textContent = n === 1 ? '1 choice' : (n + ' choices');
    }

    /**
     * Google Forms–style multiline choice paste parser.
     * Returns [{letter, text}, ...] or null when paste should stay normal.
     */
    function parseSmartChoicePaste(raw) {
      var normalized = String(raw || '').replace(/\r\n/g, '\n').replace(/\r/g, '\n');
      if (!normalized || normalized.indexOf('\n') < 0) return null;

      var lines = normalized.split('\n').map(function (l) {
        return String(l || '').replace(/\t+/g, ' ').replace(/[\u00a0 ]+/g, ' ').trim();
      }).filter(function (l) { return l.length > 0; });

      if (lines.length < 2 || lines.length > 26) return null;

      // Reject single-block prose that only wrapped (one very long "paragraph" feel).
      var marked = [];
      var markedCount = 0;
      lines.forEach(function (line) {
        // A. / A) / A - / A – / A — / A:
        var m = line.match(/^([A-Za-z])\s*[.):\-–—:]\s+(.+)$/);
        if (!m) {
          // A Cash  (letter + whitespace + rest) — only single letter
          m = line.match(/^([A-Za-z])\s+(.+)$/);
        }
        if (m && m[2] && String(m[2]).trim() !== '') {
          markedCount += 1;
          marked.push({ letter: String(m[1]).toUpperCase(), text: String(m[2]).trim() });
        } else {
          marked.push(null);
        }
      });

      // Strong labeled list: ≥2 marked lines and ≥75% of lines marked with A–Z letters.
      if (markedCount >= 2 && markedCount >= Math.ceil(lines.length * 0.75)) {
        var byLetter = {};
        marked.forEach(function (item) {
          if (!item || !/^[A-Z]$/.test(item.letter)) return;
          if (!byLetter[item.letter]) byLetter[item.letter] = item.text;
        });
        var out = [];
        for (var i = 0; i < 26; i++) {
          var L = String.fromCharCode(65 + i);
          if (byLetter[L]) out.push({ letter: L, text: byLetter[L] });
        }
        // If letters were weird (e.g. only X,Y), fall back to sequential order of marked lines.
        if (out.length < 2) {
          out = [];
          marked.forEach(function (item, idx) {
            if (!item) return;
            out.push({ letter: String.fromCharCode(65 + out.length), text: item.text });
          });
        }
        return out.length >= 2 ? out : null;
      }

      // Plain multiline: no marker majority — each non-empty line is a choice (A, B, C…).
      if (markedCount === 0) {
        // Avoid treating a bullet essay as choices: require reasonably short lines.
        var longLines = lines.filter(function (l) { return l.length > 220; }).length;
        if (longLines > 0 && lines.length <= 2) return null;
        return lines.map(function (t, idx) {
          return { letter: String.fromCharCode(65 + idx), text: t };
        });
      }

      return null;
    }

    function flashPasteNotice(entry, count) {
      if (!entry || !entry.pasteNoticeEl) return;
      entry.pasteNoticeEl.textContent = count + ' choice' + (count === 1 ? '' : 's') + ' detected';
      entry.pasteNoticeEl.hidden = false;
      entry.pasteNoticeEl.classList.add('is-visible');
      if (entry._pasteNoticeTimer) clearTimeout(entry._pasteNoticeTimer);
      entry._pasteNoticeTimer = setTimeout(function () {
        entry.pasteNoticeEl.classList.remove('is-visible');
        entry.pasteNoticeEl.hidden = true;
      }, 2200);
    }

    function ensureChoiceSlots(entry, lettersNeeded) {
      if (!entry || !isDiagnostic || !entry.choicesMount) return;
      var maxNeeded = 'D';
      lettersNeeded.forEach(function (L) {
        if (/^[E-Z]$/.test(L) && L > maxNeeded) maxNeeded = L;
      });
      var guard = 0;
      while (guard < 26) {
        var letters = entryChoiceLetters(entry);
        var last = letters.length ? letters[letters.length - 1] : 'D';
        if (last >= maxNeeded) break;
        if (last >= 'Z') break;
        addExtraChoiceRow(entry);
        guard += 1;
      }
    }

    function applySmartChoicePaste(entry, parsed) {
      if (!entry || !parsed || !parsed.length) return false;
      var maxLetter = isDiagnostic ? 'Z' : 'D';
      var capped = parsed.filter(function (item) {
        return item && item.letter <= maxLetter;
      });
      if (!capped.length) return false;

      ensureChoiceSlots(entry, capped.map(function (p) { return p.letter; }));

      // Clear existing A–max then fill.
      (entry.choiceInputs || []).forEach(function (inp) {
        var L = String(inp.getAttribute('data-choice-letter') || '').toUpperCase();
        if (L >= 'A' && L <= maxLetter) inp.value = '';
      });
      capped.forEach(function (item) {
        var inp = choiceInputByLetter(entry, item.letter);
        if (inp) inp.value = item.text;
      });
      entry.choiceInputs = entry.choicesMount
        ? Array.prototype.slice.call(entry.choicesMount.querySelectorAll('[data-choice-letter]'))
        : entry.choiceInputs;
      entry.choiceA = choiceInputByLetter(entry, 'A');
      entry.choiceB = choiceInputByLetter(entry, 'B');
      entry.choiceC = choiceInputByLetter(entry, 'C');
      entry.choiceD = choiceInputByLetter(entry, 'D');
      rebuildCorrectOptions(entry, false, entry.correctSel ? entry.correctSel.value : '');
      updateChoicesCount(entry);
      flashPasteNotice(entry, capped.length);
      scheduleSave(entry);
      return true;
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
      row.innerHTML = '<button type="button" class="eqb-choice-letter" data-mark-correct="' + next + '" title="Mark ' + next + ' as correct" aria-label="Mark choice ' + next + ' as correct">' + next + '</button>' +
        '<input class="eqb-choice-input" data-choice-letter="' + next + '" placeholder="Choice ' + next + '" autocomplete="off">';
      entry.choicesMount.appendChild(row);
      var inp = row.querySelector('input');
      var markBtn = row.querySelector('[data-mark-correct]');
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
        inp.addEventListener('paste', function (ev) { handleChoicePaste(entry, ev); });
        inp.addEventListener('keydown', function (ev) {
          if (ev.key === 'Enter') ev.preventDefault();
        });
        inp.focus();
      }
      if (markBtn) {
        markBtn.addEventListener('click', function () {
          if (entry.correctSel) {
            entry.correctSel.value = next;
            syncChoiceCorrectState(entry);
            scheduleSave(entry);
          }
        });
      }
      rebuildCorrectOptions(entry, false, entry.correctSel ? entry.correctSel.value : '');
    }

    function handleChoicePaste(entry, ev) {
      if (!entry || !ev || !ev.clipboardData) return;
      var isTf = allowTf && entry.typeSel && entry.typeSel.value === 'tf';
      if (isTf) return;
      var text = ev.clipboardData.getData('text/plain');
      var parsed = parseSmartChoicePaste(text);
      if (!parsed) return;
      ev.preventDefault();
      applySmartChoicePaste(entry, parsed);
    }

    function applyTypeUi(entry, opts) {
      opts = opts || {};
      var isTf = allowTf && entry.typeSel && entry.typeSel.value === 'tf';
      var switching = !!opts.switching;

      // Conditional panels: MCQ choices vs TF True/False.
      setPanelVisible(entry.mcqBlock, !isTf);
      setPanelVisible(entry.tfBlock, isTf);

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
      var contentCss = 'body{font-family:Nunito,system-ui,sans-serif;font-size:14px;line-height:1.45;color:#0f172a}'
        + 'table{border-collapse:collapse;width:100%;margin:0.5rem 0}'
        + 'td,th{border:1px solid #cbd5e1;padding:0.35rem 0.5rem;vertical-align:top}'
        + 'p{margin:0 0 0.4em 0}ul,ol{margin:0.2em 0 0.4em 1.2em}';
      tinymce.init({
        selector: '#' + entry.textEl.id,
        menubar: false,
        height: 118,
        resize: true,
        branding: false,
        promotion: false,
        plugins: 'table lists advlist link hr',
        toolbar: 'undo redo | bold italic underline strikethrough | bullist numlist | alignleft aligncenter alignright | superscript subscript | link table hr | removeformat',
        toolbar_mode: 'sliding',
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
        var capMsg = 'Unable to save question. Please check the required fields.';
        if (subjectsRequired && !(payload.exam_subject_id > 0)) {
          capMsg = 'Select a subject for this question.';
        } else if (!isDiagnostic && blueprint && blueprint.configured && isBucketFull(payload.exam_subject_id | 0, payload.exam_topic_id | 0, payload.question_id | 0)) {
          capMsg = bucketCapacityLabel(payload.exam_subject_id | 0, payload.exam_topic_id | 0) || 'This subject/topic already has enough questions.';
          if (capMsg.indexOf('Complete') === 0) {
            capMsg = 'Capacity reached: ' + capMsg + '. Choose another subject/topic or remove an existing question.';
          }
        }
        return Promise.resolve({
          ok: false,
          incomplete: true,
          error: capMsg
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
              display_number: res.display_number || 0,
              exam_subject_id: payload.exam_subject_id | 0,
              exam_topic_id: payload.exam_topic_id | 0
            });
          }
          if (res.blueprint && typeof res.blueprint === 'object') blueprint = res.blueprint;
          if (res.next_number) nextNumber = res.next_number | 0;
          updateRegularCoverage();
          renderTable();
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
        if (res.blueprint && typeof res.blueprint === 'object') blueprint = res.blueprint;
        if (res.next_number) nextNumber = res.next_number | 0;
        updateRegularCoverage();
        renderTable();
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
      var radioName = 'eqb-tf-' + Math.random().toString(36).slice(2, 9);

      var typeHtml = allowTf
        ? ('<label class="eqb-field eqb-field--compact"><span class="eqb-label">Question Type</span>' +
           '<select class="eqb-select" data-type>' +
             '<option value="mcq"' + (!isTf ? ' selected' : '') + '>Multiple Choice</option>' +
             '<option value="tf"' + (isTf ? ' selected' : '') + '>True or False</option>' +
           '</select></label>')
        : ('<label class="eqb-field eqb-field--compact"><span class="eqb-label">Question Type</span>' +
           '<select class="eqb-select" data-type disabled>' +
             '<option value="mcq" selected>Multiple Choice</option>' +
           '</select>' +
           (isDiagnostic ? '<p class="eqb-hint eqb-hint--tight">Diagnostic exams support Multiple Choice only.</p>' : '') +
           '</label>');

      var choiceRows = '';
      ['A', 'B', 'C', 'D'].forEach(function (L) {
        var key = 'choice_' + L.toLowerCase();
        var val = isTf ? '' : String(seed[key] || '');
        choiceRows += '<div class="eqb-choice-row">' +
          '<button type="button" class="eqb-choice-letter" data-mark-correct="' + L + '" title="Mark ' + L + ' as correct" aria-label="Mark choice ' + L + ' as correct">' + L + '</button>' +
          '<input class="eqb-choice-input" data-choice-letter="' + L + '" value="' + esc(val) + '" placeholder="Choice ' + L + '" autocomplete="off">' +
          '</div>';
      });
      Object.keys(extraSeed).sort().forEach(function (L) {
        if (!/^[E-Z]$/.test(L)) return;
        choiceRows += '<div class="eqb-choice-row">' +
          '<button type="button" class="eqb-choice-letter" data-mark-correct="' + L + '" title="Mark ' + L + ' as correct" aria-label="Mark choice ' + L + ' as correct">' + L + '</button>' +
          '<input class="eqb-choice-input" data-choice-letter="' + L + '" value="' + esc(String(extraSeed[L] || '')) + '" placeholder="Choice ' + L + '" autocomplete="off">' +
          '</div>';
      });

      var correctHtml =
        '<label class="eqb-field eqb-correct-wrap"><span class="eqb-label">Correct Answer</span>' +
        '<select class="eqb-select eqb-correct-select" data-correct aria-label="Correct answer"></select></label>';

      var subjectTypeHtml = '';
      var topicExtraHtml = '';
      if (!isDiagnostic && subjectsRequired) {
        var curSid = seed.exam_subject_id | 0;
        var curTid = seed.exam_topic_id | 0;
        if (!curSid && curTid) {
          topicOptions.forEach(function (opt) {
            if ((opt.exam_topic_id | 0) === curTid) curSid = opt.exam_subject_id | 0;
          });
        }
        var subjectField = '<label class="eqb-field eqb-field--compact"><span class="eqb-label">Subject</span><select class="eqb-select" data-subject required>';
        subjectField += '<option value="">Select subject…</option>';
        subjectOptions.forEach(function (opt) {
          var sid = opt.exam_subject_id | 0;
          var cap = bucketCapacityLabel(sid, 0);
          var label = (opt.subject_name || '') + (cap ? ' — ' + cap : '');
          subjectField += '<option value="' + sid + '"' + (sid === curSid ? ' selected' : '') + '>' + esc(label) + '</option>';
        });
        subjectField += '</select><p class="eqb-capacity-hint" data-capacity-hint></p></label>';

        subjectTypeHtml = '<div class="eqb-meta-row">' + subjectField + typeHtml + '</div>';

        if (topicsOptional) {
          topicExtraHtml = '<label class="eqb-field eqb-field--compact eqb-topic-field"><span class="eqb-label">Topic</span><select class="eqb-select" data-topic>';
          topicExtraHtml += '<option value="">— Select topic —</option>';
          topicOptions.forEach(function (opt) {
            var tid = opt.exam_topic_id | 0;
            var sid = opt.exam_subject_id | 0;
            if (curSid && sid !== curSid) return;
            var cap = bucketCapacityLabel(sid, tid);
            var label = (opt.topic_name || '') + (cap ? ' — ' + cap : '');
            topicExtraHtml += '<option value="' + tid + '" data-subject-id="' + sid + '"' + (tid === curTid ? ' selected' : '') + '>' + esc(label) + '</option>';
          });
          topicExtraHtml += '</select></label>';
        }
      } else if (isDiagnostic) {
        subjectTypeHtml = '<div class="eqb-meta-row">' + typeHtml +
          '<label class="eqb-field eqb-field--compact"><span class="eqb-label">Topic <span class="opacity-60">(Optional)</span></span>' +
          '<select class="eqb-select" data-topic disabled><option value="">— None —</option></select></label></div>';
      } else {
        subjectTypeHtml = '<div class="eqb-meta-row">' + typeHtml + '</div>';
      }

      wrap.innerHTML =
        '<div class="eqb-rapid-entry__head">' +
          '<div class="eqb-rapid-entry__head-left">' +
            '<h4 class="eqb-rapid-entry__title" data-num>Question ' + (displayNumber | 0) + '</h4>' +
            '<span class="eqb-rapid-entry__type-pill" data-type-pill>' + (isTf ? 'True / False' : 'Multiple Choice') + '</span>' +
          '</div>' +
          statusHtml(seed.question_id ? 'saved' : 'draft') +
        '</div>' +
        subjectTypeHtml +
        topicExtraHtml +
        '<div class="eqb-field eqb-question-field">' +
          '<span class="eqb-label">Question</span>' +
          '<textarea class="js-exam-q-richtext eqb-rapid-text" data-text rows="4">' + esc(seed.question_text || '') + '</textarea>' +
        '</div>' +
        '<div class="eqb-choices eqb-type-panel" data-mcq ' + (isTf ? 'hidden' : '') + '>' +
          '<div class="eqb-choices__head">' +
            '<h3 class="eqb-section-title">Answer Choices</h3>' +
            '<div class="eqb-choices__meta">' +
              '<span class="eqb-choices-count" data-choices-count>0 choices</span>' +
              '<span class="eqb-paste-notice" data-paste-notice hidden></span>' +
            '</div>' +
          '</div>' +
          '<p class="eqb-hint eqb-hint--tight">Paste a list (A. B. C. D.) to fill all choices at once.</p>' +
          '<div class="eqb-choices-mount" data-choices-mount>' + choiceRows + '</div>' +
          '<button type="button" class="admin-btn admin-btn--ghost admin-btn--sm eqb-add-choice-btn" data-add-choice>+ Add choice</button>' +
        '</div>' +
        '<div class="eqb-tf-choices eqb-type-panel" data-tf ' + (isTf ? '' : 'hidden') + '>' +
          '<div class="eqb-choices__head"><h3 class="eqb-section-title">Answer Choices</h3></div>' +
          '<div class="eqb-tf-choice-list" role="radiogroup" aria-label="True or False">' +
            '<label class="eqb-tf-choice"><input type="radio" name="' + radioName + '" value="A" data-tf-correct> <span class="eqb-choice-letter" aria-hidden="true">A</span> <span class="eqb-tf-choice__text">True</span></label>' +
            '<label class="eqb-tf-choice"><input type="radio" name="' + radioName + '" value="B" data-tf-correct> <span class="eqb-choice-letter" aria-hidden="true">B</span> <span class="eqb-tf-choice__text">False</span></label>' +
          '</div>' +
        '</div>' +
        correctHtml;

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
        typePill: wrap.querySelector('[data-type-pill]'),
        typeSel: typeEl,
        textEl: wrap.querySelector('[data-text]'),
        mcqBlock: wrap.querySelector('[data-mcq]'),
        tfBlock: wrap.querySelector('[data-tf]'),
        choicesMount: choicesMount,
        choicesCountEl: wrap.querySelector('[data-choices-count]'),
        pasteNoticeEl: wrap.querySelector('[data-paste-notice]'),
        choiceInputs: choicesMount ? Array.prototype.slice.call(choicesMount.querySelectorAll('[data-choice-letter]')) : [],
        choiceA: choicesMount ? choicesMount.querySelector('[data-choice-letter="A"]') : null,
        choiceB: choicesMount ? choicesMount.querySelector('[data-choice-letter="B"]') : null,
        choiceC: choicesMount ? choicesMount.querySelector('[data-choice-letter="C"]') : null,
        choiceD: choicesMount ? choicesMount.querySelector('[data-choice-letter="D"]') : null,
        addChoiceBtn: wrap.querySelector('[data-add-choice]'),
        correctSel: wrap.querySelector('[data-correct]'),
        subjectSel: wrap.querySelector('select[data-subject]'),
        topicSel: wrap.querySelector('select[data-topic]'),
        tfRadios: Array.prototype.slice.call(wrap.querySelectorAll('[data-tf-correct]')),
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
        retryTimer: null,
        _pasteNoticeTimer: null
      };

      // Hide Add Choice for regular — schema is A–D only.
      if (!isDiagnostic && entry.addChoiceBtn) {
        entry.addChoiceBtn.hidden = true;
      }

      applyTypeUi(entry, { switching: false });
      if (entry.subjectSel && entry.topicSel && topicsOptional) {
        entry.subjectSel.addEventListener('change', function () {
          var sid = entry.subjectSel.value | 0;
          var prev = entry.topicSel.value | 0;
          entry.topicSel.innerHTML = '<option value="">— Select topic —</option>';
          topicOptions.forEach(function (opt) {
            var tid = opt.exam_topic_id | 0;
            var osid = opt.exam_subject_id | 0;
            if (sid && osid !== sid) return;
            var cap = bucketCapacityLabel(osid, tid);
            var label = (opt.topic_name || '') + (cap ? ' — ' + cap : '');
            var optEl = document.createElement('option');
            optEl.value = String(tid);
            optEl.textContent = label;
            optEl.setAttribute('data-subject-id', String(osid));
            if (tid === prev) optEl.selected = true;
            entry.topicSel.appendChild(optEl);
          });
          var hint = entry.root.querySelector('[data-capacity-hint]');
          if (hint) {
            var h = bucketCapacityLabel(sid, 0);
            hint.textContent = h || '';
            hint.classList.toggle('is-full', h.indexOf('Complete') === 0);
          }
        });
      }
      if (entry.subjectSel) {
        entry.subjectSel.addEventListener('change', function () {
          var hint = entry.root.querySelector('[data-capacity-hint]');
          if (!hint) return;
          var sid = entry.subjectSel.value | 0;
          var tid = entry.topicSel ? (entry.topicSel.value | 0) : 0;
          var h = bucketCapacityLabel(sid, tid);
          hint.textContent = h || '';
          hint.classList.toggle('is-full', !!(h && h.indexOf('Complete') === 0));
        });
      }
      if (seed.correct_answer) {
        var seedCor = String(seed.correct_answer).toUpperCase();
        if (isTf) {
          entry.correctSel.value = (seedCor === 'A' || seedCor === 'B') ? seedCor : '';
        } else if (/^[A-Z]$/.test(seedCor)) {
          entry.correctSel.value = seedCor;
        }
      }
      rebuildCorrectOptions(entry, isTf, seed.correct_answer ? String(seed.correct_answer).toUpperCase() : '');

      function onChange() { scheduleSave(entry); }
      function onChoiceChange() {
        rebuildCorrectOptions(entry, allowTf && entry.typeSel && entry.typeSel.value === 'tf', entry.correctSel ? entry.correctSel.value : '');
        onChange();
      }
      function refreshTypePill() {
        if (!entry.typePill) return;
        var tf = allowTf && entry.typeSel && entry.typeSel.value === 'tf';
        entry.typePill.textContent = tf ? 'True / False' : 'Multiple Choice';
      }
      if (entry.typeSel && entry.typeSel.tagName === 'SELECT' && !entry.typeSel.disabled) {
        entry.typeSel.addEventListener('change', function () {
          applyTypeUi(entry, { switching: true });
          refreshTypePill();
          onChange();
        });
      }
      entry.choiceInputs.forEach(function (el) {
        el.addEventListener('input', onChoiceChange);
        el.addEventListener('change', onChoiceChange);
        el.addEventListener('paste', function (ev) { handleChoicePaste(entry, ev); });
        el.addEventListener('keydown', function (ev) {
          if (ev.key === 'Enter') ev.preventDefault();
        });
      });
      wrap.querySelectorAll('[data-mark-correct]').forEach(function (btn) {
        btn.addEventListener('click', function () {
          var L = String(btn.getAttribute('data-mark-correct') || '').toUpperCase();
          if (!/^[A-Z]$/.test(L) || !entry.correctSel) return;
          var isTfNow = allowTf && entry.typeSel && entry.typeSel.value === 'tf';
          if (isTfNow) return;
          entry.correctSel.value = L;
          // Ensure option exists even if choice text empty (regular A–D).
          if (entry.correctSel.value !== L) {
            rebuildCorrectOptions(entry, false, L);
            entry.correctSel.value = L;
          }
          syncChoiceCorrectState(entry);
          onChange();
        });
      });
      if (entry.correctSel) {
        entry.correctSel.addEventListener('change', function () {
          syncChoiceCorrectState(entry);
          syncTfRadios(entry);
          onChange();
        });
      }
      entry.tfRadios.forEach(function (r) {
        r.addEventListener('change', function () {
          if (!r.checked || !entry.correctSel) return;
          entry.correctSel.value = String(r.value || '').toUpperCase();
          syncChoiceCorrectState(entry);
          onChange();
        });
      });
      if (entry.topicSel) {
        entry.topicSel.addEventListener('change', onChange);
      }
      if (entry.addChoiceBtn) {
        entry.addChoiceBtn.addEventListener('click', function () {
          addExtraChoiceRow(entry);
        });
      }
      // Paste onto choices container (empty area / head) also works.
      if (entry.mcqBlock) {
        entry.mcqBlock.addEventListener('paste', function (ev) {
          var t = ev.target;
          if (t && t.matches && t.matches('[data-choice-letter]')) return; // handled on input
          handleChoicePaste(entry, ev);
        });
      }

      updateChoicesCount(entry);
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
        if (first.subjectSel && !first.subjectSel.value) {
          first.subjectSel.focus();
        } else if (first.editor) {
          first.editor.focus();
        } else if (first.textEl) {
          first.textEl.focus();
        }
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
          if (entry.subjectSel && subjectsRequired && !entry.subjectSel.value) {
            entry.subjectSel.focus();
          } else if (entry.editor) {
            entry.editor.focus();
          } else if (entry.textEl) {
            entry.textEl.focus();
          }
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
    if (searchEl) searchEl.addEventListener('input', scheduleApplyTableFilters);
    if (filterEl) filterEl.addEventListener('change', applyTableFilters);
    if (subjectFilterEl) {
      subjectFilterEl.addEventListener('change', function () {
        syncTopicFilterOptions();
        applyTableFilters();
      });
    }
    if (topicFilterEl) topicFilterEl.addEventListener('change', applyTableFilters);
    if (answerFilterEl) answerFilterEl.addEventListener('change', applyTableFilters);
    if (coverageFilterEl) coverageFilterEl.addEventListener('change', applyTableFilters);
    if (questionsTableEl) {
      questionsTableEl.addEventListener('click', function (e) {
        var th = e.target && e.target.closest ? e.target.closest('[data-eqb-sort]') : null;
        if (!th || isDiagnostic) return;
        e.preventDefault();
        var key = th.getAttribute('data-eqb-sort') || 'num';
        if (sortKey === key) sortDir = sortDir === 'asc' ? 'desc' : 'asc';
        else {
          sortKey = key;
          sortDir = key === 'num' ? 'asc' : 'asc';
        }
        renderTable();
      });
    }
    // Coverage row click → filter by subject/topic
    if (coverageListEl && !isDiagnostic) {
      coverageListEl.addEventListener('click', function (e) {
        var row = e.target && e.target.closest ? e.target.closest('[data-subject-id], [data-topic-id]') : null;
        if (!row) return;
        var tid = row.getAttribute('data-topic-id');
        var sid = row.getAttribute('data-subject-id');
        if (tid && topicFilterEl) {
          if (subjectFilterEl && sid) subjectFilterEl.value = String(sid);
          syncTopicFilterOptions();
          topicFilterEl.value = String(tid);
        } else if (sid && subjectFilterEl) {
          subjectFilterEl.value = String(sid);
          syncTopicFilterOptions();
          if (topicFilterEl) topicFilterEl.value = 'all';
        }
        applyTableFilters();
      });
    }

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
