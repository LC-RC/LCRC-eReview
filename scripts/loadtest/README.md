# Examination Load-Test Harness (Regular + Diagnostic)

Fail-closed harness for **HTTP load / concurrency approximation** against an
**isolated** load-test database and staging web runtime.

## Critical labels

| Claim | Status |
|-------|--------|
| Integrity remediation verified by PHP QA | Supported by `scripts/verify_exam_readiness.php` |
| k6 proves real-browser behavior | **No** — HTTP approximation only |
| 100–500 concurrent capacity proven | **Not by default** — run staged tests manually |

**k6 does NOT reproduce a real browser.** It approximates:

- debounced answer saves (`save_seq`, ~400ms pacing)
- `get_time` / `sync_state` polling
- timeout submit waves
- duplicate submit
- retries / mixed traffic

It does **not** execute take-exam JavaScript, DOM, visibility handlers, or exact client timers.

## Safety (fail closed — do not weaken)

Required before any HTTP/k6 traffic:

| Gate | Requirement |
|------|-------------|
| Flags | `EREVIEW_LOADTEST=1` and `EREVIEW_LOADTEST_CONFIRM=YES` |
| DB | `LOADTEST_DB_NAME` ∈ `{ereview_loadtest, ereview_test}` — production names blocked |
| URL | `LOADTEST_BASE_URL` explicit; non-loopback needs `LOADTEST_ALLOW_REMOTE_URL=YES` |
| Attestation | `http_attestation.json` **status=SAFE** via runtime probe |
| Start | Attempts created via CLI on guarded DB only (`LOADTEST_HTTP_START` rejected) |
| Teardown | Extra `LOADTEST_TEARDOWN_CONFIRM=YES` |

A command accidentally pointed at production must stop before sending load.

## Config (PowerShell)

```powershell
Copy-Item .\scripts\loadtest\config.example.env .\scripts\loadtest\config.local.env
# Edit config.local.env — set LOADTEST_DB_NAME and LOADTEST_BASE_URL
```

Never auto-load the example file as live config.

Prefer:

```powershell
Set-Item -Path Env:EREVIEW_LOADTEST -Value '1'
Set-Item -Path Env:EREVIEW_LOADTEST_CONFIRM -Value 'YES'
Set-Item -Path Env:LOADTEST_DB_NAME -Value 'ereview_loadtest'
Set-Item -Path Env:LOADTEST_BASE_URL -Value 'http://127.0.0.1/EreviewLoadtest'
```

Do **not** use Bash `export` or malformed `php -r` one-liners in PowerShell.

## Integrity-only QA (no k6, no HTTP load)

From repo root:

```powershell
C:\xampp\php\php.exe scripts\verify_exam_readiness.php
C:\xampp\php\php.exe scripts\qa_exam_concurrency_integrity.php
.\scripts\loadtest\Validate-LoadtestScripts.ps1
.\scripts\loadtest\Invoke-Loadtest.ps1 -Action SelfCheck
```

Expected verdict from the readiness runner when integrity is OK:

```text
INTEGRITY READY
CAPACITY NOT YET PROVEN
```

## Pipeline template (Regular or Diagnostic)

```powershell
# 0) Safety + SAFE attestation
.\scripts\loadtest\Invoke-Loadtest.ps1 -Action HttpPreflight

# 1) Seed → sessions → start → align → k6 input
.\scripts\loadtest\Invoke-Loadtest.ps1 -Mode regular -Action Seed -N 100
.\scripts\loadtest\Invoke-Loadtest.ps1 -Mode regular -Action Bootstrap
.\scripts\loadtest\Invoke-Loadtest.ps1 -Mode regular -Action StartAttempts
Set-Item -Path Env:LOADTEST_ALIGN_SECONDS -Value '90'
.\scripts\loadtest\Invoke-Loadtest.ps1 -Mode regular -Action Align
.\scripts\loadtest\Invoke-Loadtest.ps1 -Mode regular -Action BuildK6Input -Scenario mixed

# 2) k6 (requires k6 on PATH; exits with WARN if missing — does not invent traffic)
.\scripts\loadtest\Invoke-K6ExamTraffic.ps1 -Mode regular -N 100 -Scenario mixed

# 3) Verify
.\scripts\loadtest\Invoke-Loadtest.ps1 -Mode regular -Action ParseK6Stdout
.\scripts\loadtest\Invoke-Loadtest.ps1 -Mode regular -Action Verify

# 4) Teardown (LOADTEST-marked rows only)
Set-Item -Path Env:LOADTEST_TEARDOWN_CONFIRM -Value 'YES'
.\scripts\loadtest\Invoke-Loadtest.ps1 -Action Teardown
```

For Diagnostic, replace `-Mode regular` with `-Mode diagnostic`.

Legacy mass-timeout script remains: `Invoke-K6MassTimeout.ps1` / `k6_mass_timeout.js` (Regular-oriented). Prefer `Invoke-K6ExamTraffic.ps1` for the matrix below.

## Capacity ladder (prepare only — do not run against production)

Use **only** `ereview_loadtest` / `ereview_test` and `LOADTEST_BASE_URL` on loopback/staging.
Do **not** point this ladder at production. Do **not** treat k6 as a real browser.

Recommended order (stop if HTTP 5xx, connection errors, or answer-loss in Verify):

`20 → 50 → 80 → 100 → 150 → 200 → 300`

For each N:

```powershell
.\scripts\loadtest\Invoke-Loadtest.ps1 -Action HttpPreflight
.\scripts\loadtest\Invoke-Loadtest.ps1 -Mode regular -Action Seed -N <N>
.\scripts\loadtest\Invoke-Loadtest.ps1 -Mode regular -Action Bootstrap
.\scripts\loadtest\Invoke-Loadtest.ps1 -Mode regular -Action StartAttempts
Set-Item -Path Env:LOADTEST_ALIGN_SECONDS -Value '120'
.\scripts\loadtest\Invoke-Loadtest.ps1 -Mode regular -Action Align
.\scripts\loadtest\Invoke-Loadtest.ps1 -Mode regular -Action BuildK6Input -Scenario timeout_wave
.\scripts\loadtest\Invoke-K6ExamTraffic.ps1 -Mode regular -N <N> -Scenario timeout_wave
.\scripts\loadtest\Invoke-Loadtest.ps1 -Mode regular -Action ParseK6Stdout
.\scripts\loadtest\Invoke-Loadtest.ps1 -Mode regular -Action Verify
```

Also run `-Scenario mixed` at the same N after timeout_wave looks clean.

Record for every N:

| Metric | Source |
|---|---|
| HTTP 5xx / 4xx | k6 / Apache access+error |
| failed `save_answer` | k6 `save_answer_fail` |
| failed submit | k6 `submit_fail` |
| submit latency | k6 `submit_ms` |
| answers sent vs persisted | Verify action / DB counts |
| unanswered but selected before timeout | Verify vs payload (k6 approximation) |
| duplicate submits | k6 `LOADTEST_DUPLICATE_SUBMIT_RATE` + `already_submitted` |
| MySQL `Threads_connected` vs `max_connections` | `SHOW STATUS` / `SHOW VARIABLES` |
| lock waits / deadlocks | MySQL `Innodb_row_lock_waits`, error log |
| Apache/PHP worker saturation | Apache `server-status` / PHP-FPM if used |

**Capacity is not proven until this ladder is actually run on staging.** Default verdict remains: `CAPACITY NOT YET PROVEN`.

## Test matrix (exact commands)

### 1) Integrity-only QA

```powershell
C:\xampp\php\php.exe scripts\verify_exam_readiness.php
```

### 2) Regular 100 VU

```powershell
.\scripts\loadtest\Invoke-Loadtest.ps1 -Action HttpPreflight
.\scripts\loadtest\Invoke-Loadtest.ps1 -Mode regular -Action Seed -N 100
.\scripts\loadtest\Invoke-Loadtest.ps1 -Mode regular -Action Bootstrap
.\scripts\loadtest\Invoke-Loadtest.ps1 -Mode regular -Action StartAttempts
Set-Item -Path Env:LOADTEST_ALIGN_SECONDS -Value '120'
.\scripts\loadtest\Invoke-Loadtest.ps1 -Mode regular -Action Align
.\scripts\loadtest\Invoke-Loadtest.ps1 -Mode regular -Action BuildK6Input -Scenario mixed
.\scripts\loadtest\Invoke-K6ExamTraffic.ps1 -Mode regular -N 100 -Scenario mixed
.\scripts\loadtest\Invoke-Loadtest.ps1 -Mode regular -Action ParseK6Stdout
.\scripts\loadtest\Invoke-Loadtest.ps1 -Mode regular -Action Verify
```

### 3) Regular 200 VU

Same as (2) with `-N 200` on Seed and k6. **Only after 100 succeeds** and Apache/MySQL/logs look healthy.

### 4) Diagnostic 100 VU

```powershell
.\scripts\loadtest\Invoke-Loadtest.ps1 -Action HttpPreflight
.\scripts\loadtest\Invoke-Loadtest.ps1 -Mode diagnostic -Action Seed -N 100
.\scripts\loadtest\Invoke-Loadtest.ps1 -Mode diagnostic -Action Bootstrap
.\scripts\loadtest\Invoke-Loadtest.ps1 -Mode diagnostic -Action StartAttempts
Set-Item -Path Env:LOADTEST_ALIGN_SECONDS -Value '120'
.\scripts\loadtest\Invoke-Loadtest.ps1 -Mode diagnostic -Action Align
.\scripts\loadtest\Invoke-Loadtest.ps1 -Mode diagnostic -Action BuildK6Input -Scenario mixed
.\scripts\loadtest\Invoke-K6ExamTraffic.ps1 -Mode diagnostic -N 100 -Scenario mixed
.\scripts\loadtest\Invoke-Loadtest.ps1 -Mode diagnostic -Action ParseK6Stdout
.\scripts\loadtest\Invoke-Loadtest.ps1 -Mode diagnostic -Action Verify
```

### 5) Diagnostic 200 VU

Same as (4) with `-N 200`. **Only after Diagnostic 100 succeeds.**

### 6) Timeout burst

```powershell
.\scripts\loadtest\Invoke-Loadtest.ps1 -Mode regular -Action BuildK6Input -Scenario timeout_wave
.\scripts\loadtest\Invoke-K6ExamTraffic.ps1 -Mode regular -N 100 -Scenario timeout_wave
```

(Align `LOADTEST_ALIGN_SECONDS` short enough that VUs converge near `T_end`.)

### 7) Mixed traffic

```powershell
.\scripts\loadtest\Invoke-K6ExamTraffic.ps1 -Mode regular -N 100 -Scenario mixed
```

Scenarios cover (approximately):

| Letter | Behavior |
|--------|----------|
| A | normal answer save |
| B | rapid answer changes + debounce pacing |
| C | stale `save_seq` (mixed) |
| D | post-finalize save |
| E | duplicate submit |
| F | timeout submit wave |
| G | many VUs submitting together |
| H | polling (`get_time` / `sync_state`) |
| I | mixed save + polling + submit |

## Staged capacity sequence

Execute **sequentially** with monitoring between stages:

`100 → 200 → 300 → 500`

Do **not** jump to 500. Between stages check:

- Apache busy workers / AH00161
- PHP/`[exam_ajax]` error_log
- MySQL threads / slow queries
- Integrity verify PASS

Do **not** change `MaxRequestWorkers` as part of this harness.

## Install k6 manually (optional)

Do not auto-install from this repo.

1. Download from https://k6.io/docs/get-started/installation/
2. Ensure `k6` is on PATH (`where.exe k6`)
3. Re-run `Invoke-K6ExamTraffic.ps1`

If k6 is missing, runners report:

`[WARN] k6 not installed — capacity test not executed.`

## Env knobs

| Variable | Purpose |
|----------|---------|
| `LOADTEST_MODE` | `regular` \| `diagnostic` |
| `LOADTEST_SCENARIO` | `timeout_wave` \| `mixed` \| `polling` \| `rapid_save` \| `normal_save` |
| `LOADTEST_N` / `-N` | VU / examinee count |
| `LOADTEST_BASE_URL` | Staging base URL (required) |
| `LOADTEST_DB_NAME` | Isolated DB name |
| `LOADTEST_ALIGN_SECONDS` | Shared `T_end` offset |
| `LOADTEST_ALLOW_REMOTE_URL` | `YES` only for non-loopback staging |
| `LOADTEST_K6_MAX_DURATION` | k6 maxDuration override |

Never hard-code production exam/batch IDs. Seed creates `[LOADTEST] …` titles only.

## Auth / start model

- Sessions: CLI bootstrap under `scripts/loadtest/sessions`
- Attempts: CLI using college/diagnostic helpers on guarded DB
  Not identical to browser start (no start form CSRF / session-lock cookie)
- Answers: k6 submit with full expected JSON + `save_seq` on autosaves
