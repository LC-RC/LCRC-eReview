# Examination Load-Test Harness

Fail-closed harness for mass-timeout integrity testing against an **isolated**
load-test database and staging web runtime.

This is **not** a capacity certificate. N=5 passing later does not prove 80/100/200 users.

## Current machine defaults (important)

| Item | Typical local status |
|------|----------------------|
| PHP 8.2 CLI | Available (`C:\xampp\php\php.exe`) |
| k6 | **NOT INSTALLED** (install manually before traffic) |
| `db.local.php` `$db` | Often `ereview` (production) — HTTP must stay **BLOCKED** |
| `ereview_loadtest` | May not exist yet — create/import **manually** |

Until HttpPreflight writes `artifacts/http_attestation.json` with `status=SAFE`, **do not run k6**.

## PowerShell 5.1 rules

Use PowerShell only (not bash/`export`).

Never write `$db:something` — PowerShell treats `$name:` as a drive scope.

```powershell
# Correct
Write-Host "${env:LOADTEST_DB_NAME}"
Write-Host ("url={0}" -f "${env:LOADTEST_BASE_URL}")
```

Prefer runners:

- `Invoke-Loadtest.ps1`
- `Invoke-K6MassTimeout.ps1`
- `Get-LoadtestDbName.ps1`
- `Validate-LoadtestScripts.ps1`

## Mandatory env (fail closed)

```text
EREVIEW_LOADTEST=1
EREVIEW_LOADTEST_CONFIRM=YES
LOADTEST_DB_NAME=ereview_loadtest   # or ereview_test only
LOADTEST_BASE_URL=<isolated staging base URL>   # REQUIRED — no default
```

Copy template:

```powershell
Copy-Item .\scripts\loadtest\config.example.env .\scripts\loadtest\config.local.env
# Edit config.local.env — set LOADTEST_DB_NAME and LOADTEST_BASE_URL
```

Runners load `config.local.env` only (never auto-apply the example).

Allowlisted DBs: `ereview_loadtest`, `ereview_test`  
Blocked: `ereview`, `ereview_prod`, empty/unknown names  

CLI connects **only** to `LOADTEST_DB_NAME` and re-checks `SELECT DATABASE()`.

## Architecture

| Layer | What | Gate |
|-------|------|------|
| A. CLI prep | seed / sessions / start / align / verify / teardown | guarded `loadtest_connect()` |
| B. HTTP/k6 | posts to Apache `college_exam_ajax` | `http_attestation.json` **status=SAFE** only |

SAFE requires a live probe of `{LOADTEST_BASE_URL}/scripts/loadtest/runtime_db_probe.php`:

- Apache `db.php` → `SELECT DATABASE()` allowlisted and equals `LOADTEST_DB_NAME`
- Web `session.save_path` matches harness `scripts/loadtest/sessions`
- Fresh attestation (max age 30 minutes)
- Explicit `LOADTEST_BASE_URL`

Operator flags / reading `db.local.php` alone **never** yield SAFE.  
`CONFIG_ATTESTED` is rejected.

`k6_mass_timeout.js` independently refuses to run without SAFE attestation + matching env (`LOADTEST_RUN_ID`, `LOADTEST_DB_NAME`, `LOADTEST_BASE_URL`).

## Static verification (no traffic)

From repo root:

```powershell
.\scripts\loadtest\Validate-LoadtestScripts.ps1
.\scripts\loadtest\Invoke-Loadtest.ps1 -Action SelfCheck
```

`SelfCheck` runs `harness_selfcheck.php` and `negative_safety_tests.php`.

## Create isolated DB (manual — do not auto-create)

```sql
CREATE DATABASE ereview_loadtest CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Import examination schema into `ereview_loadtest` only. Do not copy production rows.

Point a **staging** Apache/vhost `db.local.php` `$db` at `ereview_loadtest` and set its PHP `session.save_path` to:

`C:\xampp\htdocs\Ereview\scripts\loadtest\sessions`

Do not change production php.ini for the live site.

## Pipeline (after isolated staging is ready)

```powershell
# Prefer Set-Item so values never collide with PowerShell drive-scope parsing.
Set-Item -Path Env:EREVIEW_LOADTEST -Value '1'
Set-Item -Path Env:EREVIEW_LOADTEST_CONFIRM -Value 'YES'
Set-Item -Path Env:LOADTEST_DB_NAME -Value 'ereview_loadtest'
Set-Item -Path Env:LOADTEST_BASE_URL -Value 'http://127.0.0.1/EreviewLoadtest'
Set-Item -Path Env:LOADTEST_N -Value '5'

.\scripts\loadtest\Invoke-Loadtest.ps1 -Action HttpPreflight
# Must produce artifacts\http_attestation.json with status=SAFE

.\scripts\loadtest\Invoke-Loadtest.ps1 -Action Seed -N 5
.\scripts\loadtest\Invoke-Loadtest.ps1 -Action Bootstrap
.\scripts\loadtest\Invoke-Loadtest.ps1 -Action StartAttempts
Set-Item -Path Env:LOADTEST_ALIGN_SECONDS -Value '90'
.\scripts\loadtest\Invoke-Loadtest.ps1 -Action Align
.\scripts\loadtest\Invoke-Loadtest.ps1 -Action BuildK6Input

# Requires k6 installed:
.\scripts\loadtest\Invoke-K6MassTimeout.ps1 -N 5
.\scripts\loadtest\Invoke-Loadtest.ps1 -Action ParseK6Stdout
.\scripts\loadtest\Invoke-Loadtest.ps1 -Action Verify

Set-Item -Path Env:LOADTEST_TEARDOWN_CONFIRM -Value 'YES'
.\scripts\loadtest\Invoke-Loadtest.ps1 -Action Teardown
```

## Scripts

| File | Role |
|------|------|
| `00_env_guard.php` | Safety + connect + attestation helpers |
| `01_seed.php` | LOADTEST users/exam/questions |
| `02_bootstrap_sessions.php` | Dedicated sessions + CSRF |
| `03_start_attempts.php` | CLI start (guarded DB; no HTTP start) |
| `04_align_expires.php` | MySQL-aligned `T_end` |
| `05_monitor_poll.php` | Optional professor monitor observer |
| `06_verify_integrity.php` | Hard integrity gate |
| `07_collect_mysql_status.php` | MySQL snapshot |
| `08_teardown.php` | Marked LOADTEST rows only |
| `09_http_preflight.php` | Writes `http_attestation.json` |
| `runtime_db_probe.php` | Read-only Apache runtime DB/session probe |
| `build_k6_input.php` | Requires SAFE |
| `k6_mass_timeout.js` | k6 scenario + self-guard |
| `expected_answers.php` | Deterministic answers |
| `negative_safety_tests.php` | Fail-closed tests |
| `harness_selfcheck.php` | Static self-check |

## k6

Install separately if needed: https://k6.io/docs/get-started/installation/

Do **not** use Node/Artillery for this harness.

## Auth / start model

- Sessions: CLI bootstrap (same keys as authenticated QA), path `scripts/loadtest/sessions`
- Attempts: CLI using `college_exam_*` helpers against guarded DB  
  Not identical to browser start (no start form CSRF / session-lock cookie)
- Answers: k6 timeout submit with full expected JSON payload

## Integrity hard-fails

Missing/mismatched expected answer, duplicates, submit_ok while still `in_progress`, score mismatch, flush-fail then submitted.
