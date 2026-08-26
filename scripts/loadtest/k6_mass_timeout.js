/**
 * k6 mass-timeout examinee traffic for Ereview Phase A load tests.
 *
 * SELF-GUARD: this script refuses to run unless k6_input.json embeds a valid
 * http_attestation with status=SAFE and an allowlisted database. PowerShell
 * wrappers are NOT the only safety layer — a direct `k6 run` fails closed too.
 *
 * Cookie / session isolation (per VU):
 *   - examinees[i] has its own PHPSESSID, user_id, attempt_id, csrf_token
 *   - vuIndex = exec.vu.idInTest - 1; no shared cookie jar
 */

import http from 'k6/http';
import { check, sleep } from 'k6';
import { SharedArray } from 'k6/data';
import { Counter, Trend } from 'k6/metrics';
import exec from 'k6/execution';

const saveLatency = new Trend('save_answer_ms', true);
const submitLatency = new Trend('submit_ms', true);
const saveFail = new Counter('save_answer_fail');
const submitFail = new Counter('submit_fail');
const submitOk = new Counter('submit_ok');
const attemptNotActive = new Counter('attempt_not_active');
const httpErrors = new Counter('http_errors');

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

function loadInput() {
  const path = __ENV.LOADTEST_K6_INPUT || `scripts/loadtest/artifacts/${runId}/k6_input.json`;
  const raw = open(path);
  return JSON.parse(raw);
}

function parseVerifiedAtMs(verifiedAt) {
  const s = String(verifiedAt || '');
  if (!s) return NaN;
  // Support ISO8601 and "YYYY-MM-DD HH:MM:SS"
  const ms = Date.parse(s.includes('T') ? s : s.replace(' ', 'T') + '+08:00');
  return ms;
}

function assertSafeInput(doc) {
  if (!runId) {
    throw new Error('k6 SELF-GUARD: LOADTEST_RUN_ID is required');
  }
  if (!expectedDb) {
    throw new Error('k6 SELF-GUARD: LOADTEST_DB_NAME env is required (no crafted-input bypass)');
  }
  if (ALLOWED_DBS.indexOf(expectedDb) < 0 || BLOCKED_DBS.indexOf(expectedDb) >= 0) {
    throw new Error(`k6 SELF-GUARD: LOADTEST_DB_NAME not allowlisted (${expectedDb})`);
  }
  if (!expectedBaseUrl || !/^https?:\/\//i.test(expectedBaseUrl)) {
    throw new Error('k6 SELF-GUARD: LOADTEST_BASE_URL env is required and must be absolute http(s)');
  }
  if (!doc || typeof doc !== 'object') {
    throw new Error('k6_input.json missing or invalid');
  }
  if (String(doc.run_id || '') !== runId) {
    throw new Error(`k6 SELF-GUARD: run_id mismatch (input=${doc.run_id || 'missing'} env=${runId})`);
  }
  if (String(doc.db || '').toLowerCase() !== expectedDb) {
    throw new Error(`k6 SELF-GUARD: input.db !== LOADTEST_DB_NAME (${doc.db} vs ${expectedDb})`);
  }

  const att = doc.http_attestation;
  if (!att || typeof att !== 'object') {
    throw new Error('k6 SELF-GUARD: missing http_attestation — refusing to send examination traffic');
  }
  const status = String(att.status || '').toUpperCase();
  if (status === 'CONFIG_ATTESTED') {
    throw new Error('k6 SELF-GUARD: CONFIG_ATTESTED is not sufficient; status must be SAFE');
  }
  if (status !== 'SAFE') {
    throw new Error(`k6 SELF-GUARD: attestation status must be SAFE (got ${att.status || 'missing'})`);
  }
  const database = String(att.database || '').toLowerCase();
  if (!database || BLOCKED_DBS.indexOf(database) >= 0) {
    throw new Error(`k6 SELF-GUARD: attested database blocked/missing (${database})`);
  }
  if (ALLOWED_DBS.indexOf(database) < 0) {
    throw new Error(`k6 SELF-GUARD: attested database not allowlisted (${database})`);
  }
  if (database !== expectedDb) {
    throw new Error(`k6 SELF-GUARD: attested database !== LOADTEST_DB_NAME (${database} vs ${expectedDb})`);
  }
  const attRun = String(att.run_id || '');
  if (attRun && attRun !== runId && attRun !== 'preflight' && !attRun.startsWith('preflight')) {
    throw new Error(`k6 SELF-GUARD: attestation run_id mismatch (${attRun} vs ${runId})`);
  }
  const verifiedMs = parseVerifiedAtMs(att.verified_at);
  if (!Number.isFinite(verifiedMs)) {
    throw new Error('k6 SELF-GUARD: attestation verified_at missing/invalid');
  }
  const ageSec = (Date.now() - verifiedMs) / 1000;
  if (ageSec > ATTESTATION_MAX_AGE_SEC) {
    throw new Error(`k6 SELF-GUARD: attestation stale (${Math.floor(ageSec)}s > ${ATTESTATION_MAX_AGE_SEC}s)`);
  }
  if (ageSec < -120) {
    throw new Error('k6 SELF-GUARD: attestation verified_at is in the future');
  }
  if (!String(att.verification_method || '').includes('runtime_db_probe')) {
    throw new Error('k6 SELF-GUARD: verification_method must include runtime_db_probe');
  }
  if (!doc.base_url || typeof doc.base_url !== 'string' || !/^https?:\/\//i.test(doc.base_url)) {
    throw new Error('k6 SELF-GUARD: missing/invalid base_url');
  }
  const docBase = String(doc.base_url).replace(/\/$/, '');
  const attBase = String(att.base_url || '').replace(/\/$/, '');
  if (!attBase || attBase !== docBase) {
    throw new Error('k6 SELF-GUARD: attestation base_url mismatch vs k6_input.base_url');
  }
  if (docBase !== expectedBaseUrl) {
    throw new Error('k6 SELF-GUARD: k6_input.base_url !== LOADTEST_BASE_URL env');
  }
  if (!doc.ajax_url || typeof doc.ajax_url !== 'string') {
    throw new Error('k6 SELF-GUARD: missing ajax_url');
  }
  if (String(doc.ajax_url).indexOf(docBase) !== 0) {
    throw new Error('k6 SELF-GUARD: ajax_url must start with base_url');
  }
  if (!doc.t_end) {
    throw new Error('k6 SELF-GUARD: missing t_end');
  }
  const tEndMs = (doc.t_end_unix && Number(doc.t_end_unix) > 0)
    ? Number(doc.t_end_unix) * 1000
    : Date.parse(String(doc.t_end).replace(' ', 'T') + '+08:00');
  if (!Number.isFinite(tEndMs) || tEndMs <= 0) {
    throw new Error('k6 SELF-GUARD: invalid t_end / t_end_unix');
  }
  const list = doc.examinees || [];
  if (!Array.isArray(list) || list.length < 1) {
    throw new Error('k6 SELF-GUARD: no examinees');
  }
  const seenSid = {};
  for (let i = 0; i < list.length; i++) {
    const ex = list[i];
    if (!ex || !ex.user_id) {
      throw new Error(`k6 SELF-GUARD: examinee[${i}] missing user_id`);
    }
    if (!ex.PHPSESSID) {
      throw new Error(`k6 SELF-GUARD: examinee[${i}] missing PHPSESSID/session`);
    }
    if (seenSid[ex.PHPSESSID]) {
      throw new Error('k6 SELF-GUARD: duplicate PHPSESSID across examinees');
    }
    seenSid[ex.PHPSESSID] = true;
    if (!ex.csrf_token) {
      throw new Error(`k6 SELF-GUARD: examinee[${i}] missing csrf_token`);
    }
    if (!ex.attempt_id) {
      throw new Error(`k6 SELF-GUARD: examinee[${i}] missing attempt_id`);
    }
    if (!Array.isArray(ex.answers) || ex.answers.length < 1) {
      throw new Error(`k6 SELF-GUARD: examinee[${i}] missing expected answers`);
    }
  }
  return tEndMs;
}

const input = new SharedArray('loadtest_input', function () {
  if (!runId) {
    throw new Error('LOADTEST_RUN_ID is required');
  }
  const parsed = loadInput();
  assertSafeInput(parsed);
  return [parsed];
});

const doc = input[0];
// Re-validate in init context (SharedArray already validated; keep explicit fail-closed).
assertSafeInput(doc);
const vus = Math.min(nEnv, (doc.examinees || []).length);
if (!vus || vus < 1) {
  throw new Error('No examinees in k6_input.json');
}

export const options = {
  scenarios: {
    examinees: {
      executor: 'per-vu-iterations',
      vus: vus,
      iterations: 1,
      maxDuration: __ENV.LOADTEST_K6_MAX_DURATION || '10m',
      gracefulStop: '30s',
    },
  },
  thresholds: {
    // Machine-readable gates for future controlled runs (integrity verifier remains hard gate).
    http_req_failed: ['rate<0.05'],
    submit_ms: ['p(95)<5000', 'p(99)<15000'],
    checks: ['rate>0.95'],
  },
};

export function handleSummary(data) {
  const outPath = __ENV.LOADTEST_K6_SUMMARY || `scripts/loadtest/artifacts/${runId}/k6_summary.json`;
  const summary = {
    run_id: runId,
    n: vus,
    autosave_failure_rate: autosaveFailureRate,
    duplicate_submit_rate: duplicateSubmitRate,
    metrics: data.metrics,
    note: 'Attempt IDs are also emitted as LOADTEST_SUBMIT_OK / LOADTEST_FLUSH_FAIL stdout lines for parse_k6_stdout.php',
    root_group: data.root_group,
  };
  return {
    [outPath]: JSON.stringify(summary, null, 2),
    stdout: `k6 summary run_id=${runId} vus=${vus}\n`,
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

export default function () {
  // Defense in depth — never send traffic if attestation was stripped.
  assertSafeInput(doc);

  const list = doc.examinees || [];
  const vuIndex = exec.vu.idInTest - 1;
  if (vuIndex < 0 || vuIndex >= list.length) {
    return;
  }
  const ex = list[vuIndex];
  const answers = ex.answers || [];
  const answerSubsetCount = Math.max(0, Math.floor(answers.length * answerDuringRate));
  const during = answers.slice(0, answerSubsetCount);

  // Prefer MySQL-derived unix timestamp from align/build (avoids Windows clock drift
  // and ambiguous Date.parse of "YYYY-MM-DD HH:MM:SS" without timezone).
  const tEndMs = (doc.t_end_unix && Number(doc.t_end_unix) > 0)
    ? Number(doc.t_end_unix) * 1000
    : Date.parse(String(doc.t_end).replace(' ', 'T') + '+08:00');
  if (!Number.isFinite(tEndMs) || tEndMs <= 0) {
    throw new Error('Invalid t_end / t_end_unix in k6_input.json');
  }
  if (!ex.csrf_token || !ex.PHPSESSID || !ex.attempt_id) {
    throw new Error(`Examinee mapping incomplete for VU index ${vuIndex}`);
  }
  if (!Array.isArray(answers) || answers.length < 1) {
    throw new Error(`Examinee ${ex.user_id} missing full expected answers payload`);
  }

  // Spread autosaves until near T_end
  for (let i = 0; i < during.length; i++) {
    const row = during[i];
    // Intentional autosave failure/skip scenario (H)
    if (Math.random() < autosaveFailureRate) {
      saveFail.add(1);
    } else {
      const res = postAjax(ex, {
        action: 'save_answer',
        csrf_token: ex.csrf_token,
        attempt_id: ex.attempt_id,
        question_id: row.question_id,
        selected_answer: row.selected_answer,
      });
      saveLatency.add(res.timings.duration);
      if (res.status >= 400) {
        httpErrors.add(1);
        saveFail.add(1);
      } else {
        let ok = false;
        try {
          const body = res.json();
          ok = !!(body && body.ok);
          if (body && String(body.error || '').toLowerCase().indexOf('not active') >= 0) {
            attemptNotActive.add(1);
          }
        } catch (e) {
          ok = false;
        }
        if (!ok) saveFail.add(1);
      }
    }

    if (i % 5 === 0) {
      postAjax(ex, { action: 'get_time', attempt_id: ex.attempt_id });
    }
    if (i % 7 === 0) {
      postAjax(ex, {
        action: 'sync_state',
        csrf_token: ex.csrf_token,
        attempt_id: ex.attempt_id,
        current_index: i,
        flags: '[]',
      });
    }

    // Pace toward T_end
    const remaining = during.length - i;
    const msLeft = Math.max(0, tEndMs - Date.now() - 2000);
    const slice = remaining > 0 ? Math.min(1500, Math.max(50, Math.floor(msLeft / remaining))) : 100;
    sleep(slice / 1000);
  }

  // Wait for mass timeout window
  const left = tEndMs - Date.now();
  if (left > 0) {
    sleep(left / 1000);
  }

  // Authoritative timeout flush with FULL expected answers
  const answersJson = JSON.stringify(answers);
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

  if (submitRes.status >= 400) {
    httpErrors.add(1);
  }
  if (ok) {
    submitOk.add(1);
    console.log(`LOADTEST_SUBMIT_OK attempt_id=${ex.attempt_id} user_id=${ex.user_id}`);
  } else {
    submitFail.add(1);
    const err = submitBody && submitBody.error ? String(submitBody.error) : '';
    console.log(`LOADTEST_SUBMIT_FAIL attempt_id=${ex.attempt_id} error=${err}`);
    if (err.toLowerCase().indexOf('not active') >= 0) {
      attemptNotActive.add(1);
    }
    if (err.toLowerCase().indexOf('could not save answers') >= 0 || (submitBody && submitBody.retry)) {
      console.log(`LOADTEST_FLUSH_FAIL attempt_id=${ex.attempt_id}`);
    }
  }

  // Duplicate timeout submit (G)
  if (Math.random() < duplicateSubmitRate) {
    sleep(0.2 + Math.random() * 1.5);
    const dup = postAjax(ex, {
      action: 'submit',
      csrf_token: ex.csrf_token,
      attempt_id: String(ex.attempt_id),
      reason: 'timeout',
      answers: answersJson,
    });
    let dupBody = null;
    try {
      dupBody = dup.json();
    } catch (e) {
      dupBody = null;
    }
    check(dup, {
      'duplicate submit ok or already_submitted': () => !!(dupBody && dupBody.ok),
    });
  }
}
