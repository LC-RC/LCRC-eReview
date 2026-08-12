<?php
/**
 * Commerce OCR helpers (Phase 6) - Tesseract CLI + optional OpenAI vision fallback.
 *
 * Scope: read payment proof images/PDFs into raw text + confidence.
 * Does NOT: verification rules, fulfillment, access_grants, SCA, activate_user.
 *
 * Test hook (CLI only via commerce_ocr_test_mode_active()):
 *   $GLOBALS['commerce_test_ocr_result'] = [
 *     'ok' => true, 'engine' => 'tesseract', 'raw_text' => '...', 'confidence' => 90.0
 *   ];
 * Left as-is after read so acceptance tests can reuse the same fixture across calls.
 */

declare(strict_types=1);

const COMMERCE_OCR_TIMEOUT_SECONDS = 20;
const COMMERCE_OCR_DEFAULT_CONFIDENCE_NONEMPTY = 70.0;
const COMMERCE_OCR_VISION_DEFAULT_CONFIDENCE = 75.0;

/**
 * Locate tesseract binary on PATH or common Windows install paths.
 */
function commerce_ocr_find_tesseract_binary(): ?string
{
    static $cached = false;
    static $path = null;
    if ($cached) {
        return $path;
    }
    $cached = true;

    $candidates = [];

    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        $candidates[] = 'C:\\Program Files\\Tesseract-OCR\\tesseract.exe';
        $candidates[] = 'C:\\Program Files (x86)\\Tesseract-OCR\\tesseract.exe';
        $local = getenv('LOCALAPPDATA');
        if (is_string($local) && $local !== '') {
            $candidates[] = $local . '\\Tesseract-OCR\\tesseract.exe';
        }
        $pf = getenv('ProgramFiles');
        if (is_string($pf) && $pf !== '') {
            $candidates[] = $pf . '\\Tesseract-OCR\\tesseract.exe';
        }
    } else {
        $candidates[] = '/usr/bin/tesseract';
        $candidates[] = '/usr/local/bin/tesseract';
        $candidates[] = '/opt/homebrew/bin/tesseract';
    }

    foreach ($candidates as $c) {
        if (is_string($c) && $c !== '' && is_file($c) && is_executable($c)) {
            $path = $c;
            return $path;
        }
    }

    // PATH lookup
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        $cmd = 'where tesseract 2>NUL';
    } else {
        $cmd = 'command -v tesseract 2>/dev/null';
    }
    $out = [];
    $code = 1;
    @exec($cmd, $out, $code);
    if ($code === 0 && !empty($out[0])) {
        $found = trim((string) $out[0]);
        if ($found !== '' && is_file($found)) {
            $path = $found;
            return $path;
        }
    }

    $path = null;
    return null;
}

/**
 * True only when COMMERCE_PAYMENT_TEST_MODE or COMMERCE_OCR_TEST_MODE is defined/true
 * AND PHP is running as CLI. Never via web/GET/POST/cookie.
 */
function commerce_ocr_test_mode_active(): bool
{
    if (PHP_SAPI !== 'cli') {
        return false;
    }
    if (defined('COMMERCE_OCR_TEST_MODE') && COMMERCE_OCR_TEST_MODE) {
        return true;
    }
    if (defined('COMMERCE_PAYMENT_TEST_MODE') && COMMERCE_PAYMENT_TEST_MODE) {
        return true;
    }
    return false;
}

/**
 * Resolve OpenAI API key using the same env/local pattern as chat support.
 *
 * @return array{key:string,model:string}
 */
function commerce_ocr_openai_settings(): array
{
    $pick = static function (string $name): string {
        $v = getenv($name);
        if (is_string($v) && trim($v) !== '') {
            return trim($v);
        }
        if (!empty($_SERVER[$name]) && is_string($_SERVER[$name]) && trim($_SERVER[$name]) !== '') {
            return trim($_SERVER[$name]);
        }
        if (!empty($_ENV[$name]) && is_string($_ENV[$name]) && trim($_ENV[$name]) !== '') {
            return trim($_ENV[$name]);
        }
        return '';
    };

    $key = $pick('EREVIEW_OPENAI_API_KEY');
    if ($key === '') {
        $key = $pick('OPENAI_API_KEY');
    }

    $model = $pick('EREVIEW_OPENAI_MODEL');
    if ($model === '') {
        $model = 'gpt-4o-mini';
    }

    $local = __DIR__ . '/chat_openai.local';
    if (is_readable($local)) {
        $cfg = include $local;
        if (is_array($cfg)) {
            if (!empty($cfg['openai_api_key']) && is_string($cfg['openai_api_key']) && trim($cfg['openai_api_key']) !== '') {
                $key = trim($cfg['openai_api_key']);
            } elseif (!empty($cfg['api_key']) && is_string($cfg['api_key']) && trim($cfg['api_key']) !== '') {
                $key = trim($cfg['api_key']);
            }
            if (!empty($cfg['model']) && is_string($cfg['model']) && trim($cfg['model']) !== '') {
                $model = trim($cfg['model']);
            }
        }
    }

    return ['key' => $key, 'model' => $model];
}

/**
 * Run a process with a wall-clock timeout. Returns stdout, stderr, exit code.
 *
 * @param list<string> $cmd
 * @return array{ok:bool,stdout:string,stderr:string,exit_code:int,timed_out:bool,error?:string}
 */
function commerce_ocr_proc_run(array $cmd, int $timeoutSeconds = COMMERCE_OCR_TIMEOUT_SECONDS): array
{
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $pipes = [];
    $proc = @proc_open($cmd, $descriptors, $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($proc)) {
        return [
            'ok' => false,
            'stdout' => '',
            'stderr' => '',
            'exit_code' => -1,
            'timed_out' => false,
            'error' => 'proc_open_failed',
        ];
    }

    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $stdout = '';
    $stderr = '';
    $start = microtime(true);
    $timedOut = false;

    while (true) {
        $status = proc_get_status($proc);
        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);

        if (!$status['running']) {
            break;
        }
        if ((microtime(true) - $start) >= $timeoutSeconds) {
            $timedOut = true;
            proc_terminate($proc, 9);
            // Brief wait then force-close on Windows if still running
            usleep(200000);
            $status = proc_get_status($proc);
            if ($status['running']) {
                proc_terminate($proc, 9);
            }
            break;
        }
        usleep(50000);
    }

    $stdout .= (string) stream_get_contents($pipes[1]);
    $stderr .= (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($proc);

    if ($timedOut) {
        return [
            'ok' => false,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'exit_code' => $exit,
            'timed_out' => true,
            'error' => 'timeout',
        ];
    }

    return [
        'ok' => $exit === 0,
        'stdout' => $stdout,
        'stderr' => $stderr,
        'exit_code' => $exit,
        'timed_out' => false,
    ];
}

/**
 * Parse average word confidence from Tesseract TSV (conf column; ignore -1).
 */
function commerce_ocr_parse_tsv_confidence(string $tsv): ?float
{
    $lines = preg_split('/\R/', $tsv) ?: [];
    $sum = 0.0;
    $n = 0;
    $headerSeen = false;
    foreach ($lines as $line) {
        if ($line === '') {
            continue;
        }
        $cols = explode("\t", $line);
        if (!$headerSeen) {
            $headerSeen = true;
            // Skip header row if present
            if (isset($cols[0]) && strcasecmp((string) $cols[0], 'level') === 0) {
                continue;
            }
        }
        // TSV columns: level page_num block_num par_num line_num word_num left top width height conf text
        if (count($cols) < 12) {
            continue;
        }
        $level = (int) $cols[0];
        if ($level !== 5) {
            continue; // word level
        }
        $conf = (float) $cols[10];
        if ($conf < 0) {
            continue;
        }
        $sum += $conf;
        $n++;
    }
    if ($n === 0) {
        return null;
    }
    return round($sum / $n, 2);
}

/**
 * @return array{ok:bool,engine:string,raw_text:string,confidence:float,error?:string,warnings?:list<string>,vision_fields?:array<string,mixed>}
 */
function commerce_ocr_run_on_file(string $absPath, string $mime): array
{
    // CLI test fixture - leave $GLOBALS['commerce_test_ocr_result'] intact for reuse.
    if (
        commerce_ocr_test_mode_active()
        && isset($GLOBALS['commerce_test_ocr_result'])
        && is_array($GLOBALS['commerce_test_ocr_result'])
    ) {
        $fixture = $GLOBALS['commerce_test_ocr_result'];
        return [
            'ok' => !empty($fixture['ok']),
            'engine' => (string) ($fixture['engine'] ?? 'test'),
            'raw_text' => (string) ($fixture['raw_text'] ?? ''),
            'confidence' => (float) ($fixture['confidence'] ?? 0),
            'error' => isset($fixture['error']) ? (string) $fixture['error'] : null,
            'warnings' => isset($fixture['warnings']) && is_array($fixture['warnings'])
                ? array_values($fixture['warnings'])
                : [],
        ];
    }

    if ($absPath === '' || !is_file($absPath) || !is_readable($absPath)) {
        return [
            'ok' => false,
            'engine' => 'none',
            'raw_text' => '',
            'confidence' => 0.0,
            'error' => 'proof_unreadable',
        ];
    }

    $mime = strtolower(trim($mime));
    $warnings = [];

    if ($mime === 'application/pdf') {
        return [
            'ok' => false,
            'engine' => 'none',
            'raw_text' => '',
            'confidence' => 0.0,
            'error' => 'pdf_needs_review',
            'warnings' => ['PDF OCR requires Imagick conversion; send to manual review or vision fallback.'],
        ];
    }

    $imageMimes = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];
    if (!in_array($mime, $imageMimes, true)) {
        return [
            'ok' => false,
            'engine' => 'none',
            'raw_text' => '',
            'confidence' => 0.0,
            'error' => 'unsupported_mime',
        ];
    }

    $bin = commerce_ocr_find_tesseract_binary();
    if ($bin === null) {
        return [
            'ok' => false,
            'engine' => 'none',
            'raw_text' => '',
            'confidence' => 0.0,
            'error' => 'tesseract_unavailable',
        ];
    }

    // Prefer TSV for confidence; fall back to plain stdout text.
    $tsvRun = commerce_ocr_proc_run([$bin, $absPath, 'stdout', '-l', 'eng', '--psm', '6', 'tsv']);
    $rawText = '';
    $confidence = 0.0;

    if (!empty($tsvRun['ok']) && trim((string) $tsvRun['stdout']) !== '') {
        $tsv = (string) $tsvRun['stdout'];
        $parsedConf = commerce_ocr_parse_tsv_confidence($tsv);
        // Rebuild text from word tokens
        $words = [];
        foreach (preg_split('/\R/', $tsv) ?: [] as $line) {
            $cols = explode("\t", $line);
            if (count($cols) < 12) {
                continue;
            }
            if ((int) $cols[0] !== 5) {
                continue;
            }
            $w = trim((string) $cols[11]);
            if ($w !== '') {
                $words[] = $w;
            }
        }
        $rawText = trim(implode(' ', $words));
        if ($parsedConf !== null) {
            $confidence = $parsedConf;
        } elseif ($rawText !== '') {
            $confidence = COMMERCE_OCR_DEFAULT_CONFIDENCE_NONEMPTY;
            $warnings[] = 'tsv_confidence_unavailable';
        }
    } else {
        $textRun = commerce_ocr_proc_run([$bin, $absPath, 'stdout', '-l', 'eng', '--psm', '6']);
        if (!empty($textRun['timed_out']) || (!empty($tsvRun['timed_out']))) {
            return [
                'ok' => false,
                'engine' => 'tesseract',
                'raw_text' => '',
                'confidence' => 0.0,
                'error' => 'timeout',
                'warnings' => $warnings,
            ];
        }
        if (empty($textRun['ok'])) {
            $err = !empty($tsvRun['error']) ? (string) $tsvRun['error'] : 'tesseract_failed';
            if (!empty($textRun['error'])) {
                $err = (string) $textRun['error'];
            }
            return [
                'ok' => false,
                'engine' => 'tesseract',
                'raw_text' => '',
                'confidence' => 0.0,
                'error' => $err,
                'warnings' => $warnings,
            ];
        }
        $rawText = trim((string) $textRun['stdout']);
        if ($rawText !== '') {
            $confidence = COMMERCE_OCR_DEFAULT_CONFIDENCE_NONEMPTY;
            $warnings[] = 'confidence_defaulted';
        }
    }

    if (!empty($tsvRun['timed_out'])) {
        return [
            'ok' => false,
            'engine' => 'tesseract',
            'raw_text' => $rawText,
            'confidence' => $confidence,
            'error' => 'timeout',
            'warnings' => $warnings,
        ];
    }

    if ($rawText === '') {
        return [
            'ok' => false,
            'engine' => 'tesseract',
            'raw_text' => '',
            'confidence' => 0.0,
            'error' => 'empty_ocr_text',
            'warnings' => $warnings,
        ];
    }

    return [
        'ok' => true,
        'engine' => 'tesseract',
        'raw_text' => $rawText,
        'confidence' => $confidence,
        'warnings' => $warnings,
    ];
}

/**
 * Optional OpenAI vision fallback. Conservative; never uses browser Tesseract.
 *
 * @return array{ok:bool,engine:string,raw_text:string,confidence:float,error?:string,warnings?:list<string>,vision_fields?:array<string,mixed>}
 */
function commerce_ocr_run_vision_fallback(string $absPath, string $mime): array
{
    // CLI test fixture - leave $GLOBALS['commerce_test_vision_result'] intact for reuse.
    if (
        commerce_ocr_test_mode_active()
        && isset($GLOBALS['commerce_test_vision_result'])
        && is_array($GLOBALS['commerce_test_vision_result'])
    ) {
        $fixture = $GLOBALS['commerce_test_vision_result'];
        $out = [
            'ok' => !empty($fixture['ok']),
            'engine' => (string) ($fixture['engine'] ?? 'vision_openai'),
            'raw_text' => (string) ($fixture['raw_text'] ?? ''),
            'confidence' => (float) ($fixture['confidence'] ?? 0),
            'error' => isset($fixture['error']) ? (string) $fixture['error'] : null,
            'warnings' => isset($fixture['warnings']) && is_array($fixture['warnings'])
                ? array_values($fixture['warnings'])
                : [],
        ];
        if (!empty($fixture['vision_fields']) && is_array($fixture['vision_fields'])) {
            $out['vision_fields'] = $fixture['vision_fields'];
        }
        return $out;
    }

    try {
        if ($absPath === '' || !is_file($absPath) || !is_readable($absPath)) {
            return [
                'ok' => false,
                'engine' => 'vision_openai',
                'raw_text' => '',
                'confidence' => 0.0,
                'error' => 'proof_unreadable',
            ];
        }

        $settings = commerce_ocr_openai_settings();
        $apiKey = $settings['key'];
        if ($apiKey === '') {
            return [
                'ok' => false,
                'engine' => 'vision_openai',
                'raw_text' => '',
                'confidence' => 0.0,
                'error' => 'openai_key_missing',
            ];
        }
        if (!function_exists('curl_init')) {
            return [
                'ok' => false,
                'engine' => 'vision_openai',
                'raw_text' => '',
                'confidence' => 0.0,
                'error' => 'curl_unavailable',
            ];
        }

        $mime = strtolower(trim($mime));
        $allowed = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp', 'application/pdf'];
        if (!in_array($mime, $allowed, true)) {
            return [
                'ok' => false,
                'engine' => 'vision_openai',
                'raw_text' => '',
                'confidence' => 0.0,
                'error' => 'unsupported_mime',
            ];
        }
        // Vision image_url data URIs work for images; PDF is not reliably supported - fail closed.
        if ($mime === 'application/pdf') {
            return [
                'ok' => false,
                'engine' => 'vision_openai',
                'raw_text' => '',
                'confidence' => 0.0,
                'error' => 'pdf_vision_unsupported',
                'warnings' => ['PDF vision fallback not supported; needs manual review.'],
            ];
        }
        if ($mime === 'image/jpg') {
            $mime = 'image/jpeg';
        }

        $bytes = @file_get_contents($absPath);
        if ($bytes === false || $bytes === '') {
            return [
                'ok' => false,
                'engine' => 'vision_openai',
                'raw_text' => '',
                'confidence' => 0.0,
                'error' => 'proof_unreadable',
            ];
        }
        // Cap payload size (~4 MB base64)
        if (strlen($bytes) > 3500000) {
            return [
                'ok' => false,
                'engine' => 'vision_openai',
                'raw_text' => '',
                'confidence' => 0.0,
                'error' => 'proof_too_large',
            ];
        }

        $b64 = base64_encode($bytes);
        $dataUrl = 'data:' . $mime . ';base64,' . $b64;
        $model = $settings['model'] !== '' ? $settings['model'] : 'gpt-4o-mini';

        $prompt = "You are extracting fields from a Philippine GCash payment receipt image. "
            . "Respond with ONLY a JSON object (no markdown) with keys: "
            . "amount_pesos (number|null), reference (string|null), recipient (string|null), "
            . "paid_at_iso (string|null ISO-8601), success_text_found (boolean), "
            . "raw_text_summary (string). Be conservative: use null when unsure.";

        $payload = [
            'model' => $model,
            'temperature' => 0,
            'max_tokens' => 800,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        ['type' => 'text', 'text' => $prompt],
                        [
                            'type' => 'image_url',
                            'image_url' => [
                                'url' => $dataUrl,
                                'detail' => 'low',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => COMMERCE_OCR_TIMEOUT_SECONDS + 20,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $resp = curl_exec($ch);
        $errno = curl_errno($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0 || !is_string($resp) || $resp === '') {
            return [
                'ok' => false,
                'engine' => 'vision_openai',
                'raw_text' => '',
                'confidence' => 0.0,
                'error' => 'openai_request_failed',
            ];
        }

        $decoded = json_decode($resp, true);
        if (!is_array($decoded) || $http < 200 || $http >= 300) {
            return [
                'ok' => false,
                'engine' => 'vision_openai',
                'raw_text' => '',
                'confidence' => 0.0,
                'error' => 'openai_http_' . $http,
            ];
        }

        $content = (string) ($decoded['choices'][0]['message']['content'] ?? '');
        $content = trim($content);
        if ($content === '') {
            return [
                'ok' => false,
                'engine' => 'vision_openai',
                'raw_text' => '',
                'confidence' => 0.0,
                'error' => 'openai_empty_response',
            ];
        }

        // Strip optional ```json fences
        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/is', $content, $m)) {
            $content = $m[1];
        }

        $fields = json_decode($content, true);
        if (!is_array($fields)) {
            // Treat whole response as summary text only
            return [
                'ok' => true,
                'engine' => 'vision_openai',
                'raw_text' => $content,
                'confidence' => 50.0,
                'warnings' => ['vision_json_parse_failed'],
                'vision_fields' => [],
            ];
        }

        $summary = trim((string) ($fields['raw_text_summary'] ?? ''));
        if ($summary === '') {
            $parts = [];
            if (isset($fields['amount_pesos'])) {
                $parts[] = 'Amount: ' . $fields['amount_pesos'];
            }
            if (!empty($fields['reference'])) {
                $parts[] = 'Ref: ' . $fields['reference'];
            }
            if (!empty($fields['recipient'])) {
                $parts[] = 'To: ' . $fields['recipient'];
            }
            if (!empty($fields['paid_at_iso'])) {
                $parts[] = 'Paid: ' . $fields['paid_at_iso'];
            }
            if (!empty($fields['success_text_found'])) {
                $parts[] = 'Payment successful';
            }
            $summary = implode("\n", $parts);
        }

        if ($summary === '') {
            return [
                'ok' => false,
                'engine' => 'vision_openai',
                'raw_text' => '',
                'confidence' => 0.0,
                'error' => 'vision_empty_text',
                'vision_fields' => $fields,
            ];
        }

        return [
            'ok' => true,
            'engine' => 'vision_openai',
            'raw_text' => $summary,
            'confidence' => COMMERCE_OCR_VISION_DEFAULT_CONFIDENCE,
            'warnings' => ['vision_used'],
            'vision_fields' => $fields,
        ];
    } catch (Throwable $e) {
        error_log('commerce_ocr_run_vision_fallback: ' . $e->getMessage());
        return [
            'ok' => false,
            'engine' => 'vision_openai',
            'raw_text' => '',
            'confidence' => 0.0,
            'error' => 'vision_exception',
        ];
    }
}
