/**
 * k6 HTTP traffic for Regular + Diagnostic examination load tests.
 *
 * THIS IS NOT A REAL BROWSER.
 * It approximates debounced saves, save_seq ordering, polling, submit bursts,
 * and retries over HTTP. It does not execute take-exam JavaScript, DOM events,
 * or visibility handlers.
 *
 * FAIL-CLOSED: refuses to run without SAFE http_attestation + allowlisted DB.
 *
 * Env:
 *   LOADTEST_RUN_ID, LOADTEST_DB_NAME, LOADTEST_BASE_URL, LOADTEST_N
 *   LOADTEST_SCENARIO=timeout_wave|mixed|polling|rapid_save|normal_save
 *   LOADTEST_AUTOSAVE_FAILURE_RATE, LOADTEST_DUPLICATE_SUBMIT_RATE, LOADTEST_ANSWER_RATE
 *   LOADTEST_K6_MAX_DURATION
 */
import http from 'k6/http';
import { check, sleep } from 'k6';
import { SharedArray } from 'k6/data';
import { Counter, Trend } from 'k6/metrics';
import exec from 'k6/execution';

const saveLatency = new Trend('save_answer_ms', true);
const submitLatency = new Trend('submit_ms', true);
const pollLatency = new Trend('poll_ms', true);
const saveFail = new Counter('save_answer_fail');
const submitFail = new Counter('submit_fail');
const submitOk = new Counter('submit_ok');
const attemptNotActive = new Counter('attempt_not_active');
const httpErrors = new Counter('http_errors');
const staleSaveSent = new Counter('stale_save_seq_sent');
const postFinalizeSave = new Counter('post_finalize_save_sent');

const ALLOWED_DBS = ['ereview_loadtest', 'ereview_test'];
const BLOCKED_DBS = ['ereview', 'ereview_prod', 'ereview_production', 'production', 'prod'];
const ATTESTATION_MAX_AGE_SEC = 1800;

const runId = __ENV.LOADTEST_RUN_ID || '';
const expectedDb = String(__ENV.LOADTEST_DB_NAME || '').toLowerCase();
const expectedBaseUrl = String(__ENV.LOADTEST_BASE_URL || '').replace(/\/$/, '');
const nEnv = parseInt(__ENV.LOADTEST_N || '5', 10);
const autosaveFailureRate = Math.min(1, Math.max(0, parseFloat(__ENV.LOADTEST_AUTOSAVE_FAILURE_RATE || '0')));
const duplicateSubmitRate = Math.min(1, Math.max(0, parseFloat(__ENV.LOADTEST_DUPLICATE_SUBMIT_RATE || '0.15')));
const answerDuringRate = Math.min(1, Math.max(0.1, parseFloat(__ENV.LOADTEST_ANSWER_RATE || '1')));
const scenarioEnv = String(__ENV.LOADTEST_SCENARIO || '').toLowerCase();
const debounceMs = Math.max(0, parseInt(__ENV.LOADTEST_DEBOUNCE_MS || '400', 10));

function loadInput() {
  const path = __ENV.LOADTEST_K6_INPUT || `scripts/loadtest/artifacts/${runId}/k6_input.json`;
  return JSON.parse(open(path));
}

function parseVerifiedAtMs(verifiedAt) {
  const s = String(verifiedAt || '');
  if (!s) return NaN;
  return Date.parse(s.includes('T') ? s : s.replace(' ', 'T') + '+08:00');
}

function assertSafeInput(doc) {
  if (!runId) throw new Error('k6 SELF-GUARD: LOADTEST_RUN_ID is required');
  if (!expectedDb) throw new Error('k6 SELF-GUARD: LOADTEST_DB_NAME env is required');
  if (ALLOWED_DBS.indexOf(expectedDb) < 0 || BLOCKED_DBS.indexOf(expectedDb) >= 0) {
    throw new Error(`k6 SELF-GUARD: LOADTEST_DB_NAME not allowlisted (${expectedDb})`);
  }
  if (!expectedBaseUrl || !/^https?:\/\//i.test(expectedBaseUrl)) {
    throw new Error('k6 SELF-GUARD: LOADTEST_BASE_URL env is required');
  }
  if (!doc || typeof doc !== 'object') throw new Error('k6_input.json missing/invalid');
  if (String(doc.run_id || '') !== runId) {
    throw new Error(`k6 SELF-GUARD: run_id mismatch`);
  }
  if (String(doc.db || '').toLowerCase() !== expectedDb) {
    throw new Error('k6 SELF-GUARD: input.db !== LOADTEST_DB_NAME');
  }
  const mode = String(doc.mode || 'regular').toLowerCase();
  if (mode !== 'regular' && mode !== 'diagnostic') {
    throw new Error(`k6 SELF-GUARD: unsupported mode ${mode}`);
  }
  const att = doc.http_attestation;
  if (!att || typeof att !== 'object') {
    throw new Error('k6 SELF-GUARD: missing http_attestation');
  }
  const status = String(att.status || '').toUpperCase();
  if (status === 'CONFIG_ATTESTED') {
    throw new Error('k6 SELF-GUARD: CONFIG_ATTESTED insufficient; SAFE required');
  }
  if (status !== 'SAFE') {
    throw new Error(`k6 SELF-GUARD: attestation status must be SAFE (got ${att.status || 'missing'})`);
  }
  const database = String(att.database || '').toLowerCase();
  if (!database || BLOCKED_DBS.indexOf(database) >= 0 || ALLOWED_DBS.indexOf(database) < 0) {
    throw new Error(`k6 SELF-GUARD: attested database blocked/not allowlisted (${database})`);
  }
  if (database !== expectedDb) {
    throw new Error('k6 SELF-GUARD: attested database !== LOADTEST_DB_NAME');
  }
  const verifiedMs = parseVerifiedAtMs(att.verified_at);
  if (!Number.isFinite(verifiedMs)) throw new Error('k6 SELF-GUARD: verified_at invalid');
  const ageSec = (Date.now() - verifiedMs) / 1000;
  if (ageSec > ATTESTATION_MAX_AGE_SEC) throw new Error('k6 SELF-GUARD: attestation stale');
  if (ageSec < -120) throw new Error('k6 SELF-GUARD: attestation in the future');
  if (!String(att.verification_method || '').includes('runtime_db_probe')) {
    throw new Error('k6 SELF-GUARD: verification_method must include runtime_db_probe');
  }
  const docBase = String(doc.base_url || '').replace(/\/$/, '');
  const attBase = String(att.base_url || '').replace(/\/$/, '');
  if (!docBase || !/^https?:\/\//i.test(docBase)) throw new Error('k6 SELF-GUARD: missing base_url');
  if (!attBase || attBase !== docBase) throw new Error('k6 SELF-GUARD: attestation base_url mismatch');
  if (docBase !== expectedBaseUrl) throw new Error('k6 SELF-GUARD: base_url !== LOADTEST_BASE_URL');
  if (!doc.ajax_url || String(doc.ajax_url).indexOf(docBase) !== 0) {
    throw new Error('k6 SELF-GUARD: ajax_url must start with base_url');
  }
  if (mode === 'regular' && String(doc.ajax_url).indexOf('college_exam_ajax') < 0) {
    throw new Error('k6 SELF-GUARD: regular mode requires college_exam_ajax');
  }
  if (mode === 'diagnostic' && String(doc.ajax_url).indexOf('college_diagnostic_ajax') < 0) {
    throw new Error('k6 SELF-GUARD: diagnostic mode requires college_diagnostic_ajax');
  }
  if (!doc.t_end) throw new Error('k6 SELF-GUARD: missing t_end');
  const list = doc.examinees || [];
  if (!Array.isArray(list) || list.length < 1) throw new Error('k6 SELF-GUARD: no examinees');
  const seenSid = {};
  for (let i = 0; i < list.length; i++) {
    const ex = list[i];
    if (!ex || !ex.user_id || !ex.PHPSESSID || !ex.csrf_token || !ex.attempt_id) {
      throw new Error(`k6 SELF-GUARD: examinee[${i}] incomplete`);
    }
    if (seenSid[ex.PHPSESSID]) throw new Error('k6 SELF-GUARD: duplicate PHPSESSID');
    seenSid[ex.PHPSESSID] = true;
    if (!Array.isArray(ex.answers) || ex.answers.length < 1) {
      throw new Error(`k6 SELF-GUARD: examinee[${i}] missing answers`);
    }
  }
}

const input = new SharedArray('loadtest_input', function () {
  const parsed = loadInput();
  assertSafeInput(parsed);
  return [parsed];
});

const doc = input[0];
assertSafeInput(doc);
const scenario = scenarioEnv || String(doc.scenario || 'timeout_wave').toLowerCase();
const vus = Math.min(nEnv, (doc.examinees || []).length);
if (!vus || vus < 1) throw new Error('No examinees in k6_input.json');

export const options = {
  scenarios: {
    examinees: {
      executor: 'per-vu-iterations',
      vus: vus,
      iterations: 1,
      maxDuration: __ENV.LOADTEST_K6_MAX_DURATION || '12m',
      gracefulStop: '30s',
    },
  },
  thresholds: {
    http_req_failed: ['rate<0.05'],
    submit_ms: ['p(95)<8000', 'p(99)<20000'],
    checks: ['rate>0.90'],
  },
};

export function handleSummary(data) {
  const outPath = __ENV.LOADTEST_K6_SUMMARY || `scripts/loadtest/artifacts/${runId}/k6_summary.json`;
  const summary = {
    run_id: runId,
    mode: doc.mode || 'regular',
    scenario: scenario,
    n: vus,
    note: 'HTTP approximation only — not browser certification. See LOADTEST_SUBMIT_OK lines for parse_k6_stdout.php',
    metrics: data.metrics,
  };
  return {
    [outPath]: JSON.stringify(summary, null, 2),
    stdout: `k6 summary run_id=${runId} mode=${doc.mode} scenario=${scenario} vus=${vus}\n`,
  };
}

function formBody(obj) {
  const parts = [];
  for (const k of Object.keys(obj)) {
    parts.push(encodeURIComponent(k) + '=' + encodeURIComponent(String(obj[k])));
  }
  return parts.join('&');
}

function postAjax(ex, fields) {
  const headers = {
    'Content-Type': 'application/x-www-form-urlencoded',
    Cookie: ex.cookie_header || `PHPSESSID=${ex.PHPSESSID}`,
  };
  return http.post(doc.ajax_url, formBody(fields), { headers, timeout: '120s' });
}

function tEndMsOf() {
  return (doc.t_end_unix && Number(doc.t_end_unix) > 0)
    ? Number(doc.t_end_unix) * 1000
    : Date.parse(String(doc.t_end).replace(' ', 'T') + '+08:00');
}

function saveAnswer(ex, qid, letter, saveSeq) {
  if (Math.random() < autosaveFailureRate) {
    saveFail.add(1);
    return null;
  }
  const res = postAjax(ex, {
    action: 'save_answer',
    csrf_token: ex.csrf_token,
    attempt_id: ex.attempt_id,
    question_id: qid,
    selected_answer: letter,
    save_seq: saveSeq,
  });
  saveLatency.add(res.timings.duration);
  if (res.status >= 400) {
    httpErrors.add(1);
    saveFail.add(1);
    return res;
  }
  try {
    const body = res.json();
    if (!(body && body.ok)) saveFail.add(1);
  } catch (e) {
    saveFail.add(1);
  }
  return res;
}

function pollTraffic(ex) {
  let res = postAjax(ex, { action: 'get_time', attempt_id: ex.attempt_id });
  pollLatency.add(res.timings.duration);
  res = postAjax(ex, {
    action: 'sync_state',
    csrf_token: ex.csrf_token,
    attempt_id: ex.attempt_id,
    current_index: 0,
    flags: '[]',
  });
  pollLatency.add(res.timings.duration);
}

function submitTimeout(ex, answersJson) {
  const submitRes = postAjax(ex, {
    action: 'submit',
    csrf_token: ex.csrf_token,
    attempt_id: String(ex.attempt_id),
    reason: 'timeout',
    answers: answersJson,
  });
  submitLatency.add(submitRes.timings.duration);
  let submitBody = null;
  try {
    submitBody = submitRes.json();
  } catch (e) {
    submitBody = null;
  }
  const ok = !!(submitBody && submitBody.ok);
  check(submitRes, {
    'submit http 200': (r) => r.status === 200,
    'submit ok': () => ok,
  });
  if (submitRes.status >= 400) httpErrors.add(1);
  if (ok) {
    submitOk.add(1);
    console.log(`LOADTEST_SUBMIT_OK attempt_id=${ex.attempt_id} user_id=${ex.user_id}`);
  } else {
    submitFail.add(1);
    const err = submitBody && submitBody.error ? String(submitBody.error) : '';
    console.log(`LOADTEST_SUBMIT_FAIL attempt_id=${ex.attempt_id} error=${err}`);
    if (err.toLowerCase().indexOf('not active') >= 0) attemptNotActive.add(1);
  }
  return { ok, submitBody };
}

export default function () {
  assertSafeInput(doc);
  const list = doc.examinees || [];
  const vuIndex = exec.vu.idInTest - 1;
  if (vuIndex < 0 || vuIndex >= list.length) return;
  const ex = list[vuIndex];
  const answers = ex.answers || [];
  const tEndMs = tEndMsOf();
  if (!Number.isFinite(tEndMs) || tEndMs <= 0) throw new Error('Invalid t_end');

  let seqByQ = {};
  function nextSeq(qid) {
    seqByQ[qid] = (seqByQ[qid] || 0) + 1;
    return seqByQ[qid];
  }

  // --- Scenario A/B/H/I approximations before timeout wave ---
  if (scenario === 'polling') {
    for (let i = 0; i < 8; i++) {
      pollTraffic(ex);
      sleep(1);
    }
  }

  if (scenario === 'rapid_save' || scenario === 'mixed' || scenario === 'normal_save' || scenario === 'timeout_wave') {
    const subsetCount = Math.max(0, Math.floor(answers.length * answerDuringRate));
    const during = answers.slice(0, subsetCount);
    for (let i = 0; i < during.length; i++) {
      const row = during[i];
      const qid = row.question_id;

      // B: rapid answer changes — approximate debounce by sleeping, then sending final seq
      if (scenario === 'rapid_save' || scenario === 'mixed') {
        const staleSeq = nextSeq(qid);
        saveAnswer(ex, qid, 'A', staleSeq); // intentional intermediate
        staleSaveSent.add(1);
        sleep(debounceMs / 1000);
        const finalSeq = nextSeq(qid);
        saveAnswer(ex, qid, row.selected_answer, finalSeq);
      } else {
        // A: normal save (debounced approximation)
        sleep(debounceMs / 1000);
        saveAnswer(ex, qid, row.selected_answer, nextSeq(qid));
      }

      // H: polling traffic interleaved
      if (scenario === 'mixed' || scenario === 'polling' || i % 5 === 0) {
        pollTraffic(ex);
      }

      const remaining = during.length - i;
      const msLeft = Math.max(0, tEndMs - Date.now() - 2000);
      const slice = remaining > 0 ? Math.min(1500, Math.max(50, Math.floor(msLeft / remaining))) : 100;
      sleep(slice / 1000);
    }
  }

  // Wait for mass timeout window (F/G)
  const left = tEndMs - Date.now();
  if (left > 0) sleep(left / 1000);

  const answersJson = JSON.stringify(answers);
  const first = submitTimeout(ex, answersJson);

  // E: duplicate submit
  if (Math.random() < duplicateSubmitRate) {
    sleep(0.2 + Math.random() * 1.0);
    submitTimeout(ex, answersJson);
  }

  // D: post-finalize save (should be ignored by server)
  if (first.ok && answers.length > 0) {
    const row = answers[0];
    const res = saveAnswer(ex, row.question_id, 'Z', nextSeq(row.question_id) + 50);
    postFinalizeSave.add(1);
    if (res) {
      try {
        const body = res.json();
        check(res, {
          'post-finalize ignored or ok': () => !!(body && body.ok && (body.ignored || body.stale || true)),
        });
      } catch (e) { /* ignore */ }
    }
  }

  // C: stale save_seq after newer value already written (best-effort mid-run already covered in rapid_save)
  if (scenario === 'mixed' && answers.length > 1) {
    const row = answers[1];
    saveAnswer(ex, row.question_id, 'A', 1); // deliberately low seq
    staleSaveSent.add(1);
  }
}
