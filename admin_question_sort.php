<?php
/**
 * Admin: automated question sorting from .docx — parse, group by trailing (Topic), export JSON/HTML/DOCX.
 */
require_once 'auth.php';
requireRole('admin');
require_once __DIR__ . '/includes/docx_question_parser.php';
require_once __DIR__ . '/includes/docx_grouped_export.php';
require_once __DIR__ . '/includes/question_sort_cache.php';

$pageTitle = 'Question sorting';
$csrf = generateCSRFToken();

$sessionKey = 'ereview_qsort_cache_v1';

// Download handlers (GET)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['download'])) {
    $kind = (string)$_GET['download'];
    $data = ereview_qsort_load_cache();
    if ($data === null) {
        header('HTTP/1.1 404 Not Found');
        echo 'No parsed file in session. Upload a document again.';
        exit;
    }
    $baseName = preg_replace('/[^a-zA-Z0-9._-]+/', '_', (string)($data['meta']['original_filename'] ?? 'questions'));
    if ($kind === 'json') {
        header('Content-Type: application/json; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $baseName . '.grouped.json"');
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($kind === 'parse_trace') {
        $tr = $data['parse_trace'] ?? null;
        if (!is_array($tr)) {
            header('HTTP/1.1 404 Not Found');
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'No parse trace in this session. Upload the .docx again and enable “Capture parser trace”.';
            exit;
        }
        $export = [
            'meta' => $data['meta'] ?? [],
            'questions_outline' => $data['questions_outline'] ?? [],
            'parse_trace' => $tr,
        ];
        header('Content-Type: application/json; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $baseName . '.parse-trace.json"');
        echo json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($kind === 'html') {
        header('Content-Type: text/html; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $baseName . '.grouped.html"');
        $esc = static function ($s) {
            return htmlspecialchars((string)$s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        };
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Grouped — ' . $esc($baseName) . '</title>';
        echo '<style>body{font-family:Calibri,Arial,sans-serif;max-width:900px;margin:2rem auto;line-height:1.45;}';
        echo 'h1{font-size:1.35rem;}h2{font-size:1.1rem;margin-top:2rem;color:#1e3a5f;}';
        echo '.q{margin:1.25rem 0;padding-left:0.5rem;border-left:3px solid #cbd5e1;}';
        echo 'mark.ereview-qsort-hl,.ereview-qsort-hl{background:#fff176;padding:0 2px;}';
        echo '.gp-note{font-size:0.95rem;color:#475569;} .gp-after{margin:0.35rem 0 0.5rem;font-size:0.9rem;color:#64748b;}</style></head><body>';
        echo '<h1>Grouped questions</h1><p>Source: <strong>' . $esc($data['meta']['original_filename'] ?? '') . '</strong></p>';
        $gpHtml = $data['general_problems'] ?? [];
        if (is_array($gpHtml) && $gpHtml !== []) {
            echo '<h2>General problems</h2><p class="gp-note">These passages are not exam items; later numbered questions refer to this context.</p>';
            foreach ($gpHtml as $gp) {
                if (!is_array($gp)) {
                    continue;
                }
                $after = trim((string)($gp['extracted_after_question'] ?? ''));
                if ($after !== '') {
                    echo '<p class="gp-after"><em>Follows item ' . $esc($after) . '.</em></p>';
                }
                echo '<div class="q gp">';
                echo '<div class="stem">' . ($gp['stem_html_joined'] ?? '') . '</div>';
                echo '</div>';
            }
        }
        foreach ($data['by_topic'] as $block) {
            $topic = (string)($block['topic'] ?? '');
            echo '<h2>Topic: ' . $esc($topic) . '</h2>';
            foreach ($block['questions'] as $q) {
                echo '<div class="q">';
                echo '<div class="stem">' . ($q['stem_html_joined'] ?? '') . '</div>';
                if (!empty($q['choices_html']) && is_array($q['choices_html'])) {
                    foreach ($q['choices_html'] as $ch) {
                        echo '<div class="choice">' . $ch . '</div>';
                    }
                }
                echo '</div>';
            }
        }
        echo '</body></html>';
        exit;
    }
    if ($kind === 'docx') {
        $grouped = $data['grouped'] ?? null;
        if (!is_array($grouped)) {
            header('HTTP/1.1 500 Internal Server Error');
            echo 'Missing grouped payload.';
            exit;
        }
        $tmpDocx = tempnam(sys_get_temp_dir(), 'ereview_qsort_out_');
        if ($tmpDocx === false) {
            header('HTTP/1.1 500 Internal Server Error');
            echo 'Could not create temp file.';
            exit;
        }
        $docxPath = $tmpDocx . '.docx';
        @unlink($tmpDocx);
        if (!ereview_docx_write_grouped_docx($grouped, $docxPath)) {
            header('HTTP/1.1 500 Internal Server Error');
            echo 'Could not build .docx.';
            exit;
        }
        header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        header('Content-Disposition: attachment; filename="' . $baseName . '.grouped.docx"');
        readfile($docxPath);
        @unlink($docxPath);
        exit;
    }
    header('HTTP/1.1 400 Bad Request');
    echo 'Unknown download type.';
    exit;
}

$parseError = null;
$parseResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ereview_qsort_upload'])) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $parseError = 'Invalid security token. Please refresh the page and try again.';
    } elseif (empty($_FILES['docx']['tmp_name']) || (int)($_FILES['docx']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $parseError = 'Please choose a .docx file to upload.';
    } else {
        $tmp = (string)$_FILES['docx']['tmp_name'];
        $name = (string)($_FILES['docx']['name'] ?? 'upload.docx');
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($ext !== 'docx') {
            $parseError = 'Only .docx files are supported.';
        } elseif (!is_uploaded_file($tmp)) {
            $parseError = 'Invalid upload.';
        } else {
            try {
                $parseTrace = null;
                $wantParseTrace = !empty($_POST['ereview_qsort_debug']);
                if ($wantParseTrace) {
                    $parseTrace = [];
                    $grouped = ereview_docx_parse_and_group($tmp, $parseTrace);
                } else {
                    $grouped = ereview_docx_parse_and_group($tmp);
                }
                $byTopic = [];
                foreach ($grouped['topics'] as $topicName => $list) {
                    $qlist = [];
                    foreach ($list as $q) {
                        $choiceBodies = [];
                        foreach ($q['choice_paragraphs'] ?? [] as $cp) {
                            if (!is_array($cp)) {
                                continue;
                            }
                            $vis = ereview_docx_with_visible_list_marker($cp);
                            $choiceBodies[] = ereview_docx_strip_choice_label_prefix_from_html($vis['html']);
                        }
                        $qlist[] = [
                            'number' => $q['number'],
                            'stem_plain' => $q['stem_plain'],
                            'stem_html_joined' => $q['stem_html_joined'],
                            'choices_html' => $q['choices_html'],
                            'choice_bodies_html' => $choiceBodies,
                            'topic' => $q['topic'],
                            'topic_source' => $q['topic_source'] ?? '',
                            'correct_answer_letter' => $q['correct_answer_letter'] ?? null,
                        ];
                    }
                    $byTopic[] = ['topic' => $topicName, 'questions' => $qlist];
                }
                $payload = [
                    'meta' => array_merge($grouped['stats'], [
                        'original_filename' => $name,
                        'parsed_at' => date('c'),
                        'parse_trace_captured' => $wantParseTrace,
                    ]),
                    'by_topic' => $byTopic,
                    'general_problems' => $grouped['general_problems'] ?? [],
                    'grouped' => $grouped,
                ];
                if ($wantParseTrace && is_array($parseTrace)) {
                    $payload['parse_trace'] = $parseTrace;
                    $payload['questions_outline'] = [];
                    foreach ($grouped['questions'] as $q) {
                        $stem = (string)($q['stem_plain'] ?? '');
                        $payload['questions_outline'][] = [
                            'number' => (string)($q['number'] ?? ''),
                            'topic' => $q['topic'] ?? null,
                            'topic_source' => (string)($q['topic_source'] ?? ''),
                            'stem_preview' => function_exists('mb_substr')
                                ? mb_substr($stem, 0, 140, 'UTF-8')
                                : substr($stem, 0, 140),
                        ];
                    }
                }
                $cachePath = ereview_qsort_cache_path();
                if (@file_put_contents($cachePath, json_encode($payload, JSON_UNESCAPED_UNICODE)) === false) {
                    $parseError = 'Could not save parse result (temp directory not writable).';
                } else {
                    $_SESSION[$sessionKey] = ['path' => $cachePath, 'name' => $name];
                    header('Location: admin_question_sort.php?ok=1');
                    exit;
                }
            } catch (Throwable $e) {
                $parseError = 'Could not parse document: ' . $e->getMessage();
            }
        }
    }
}

if (isset($_GET['ok'])) {
    $parseResult = ereview_qsort_load_cache();
}

if ($parseResult === null && $parseError === null) {
    $parseResult = ereview_qsort_load_cache();
}

$uiPayload = null;
$parseTraceForView = null;
$questionsOutlineForView = null;
if (is_array($parseResult)) {
    $uiPayload = [
        'meta' => $parseResult['meta'] ?? [],
        'by_topic' => $parseResult['by_topic'] ?? [],
        'general_problems' => $parseResult['general_problems'] ?? [],
    ];
    if (!empty($parseResult['parse_trace']) && is_array($parseResult['parse_trace'])) {
        $parseTraceForView = $parseResult['parse_trace'];
    }
    if (!empty($parseResult['questions_outline']) && is_array($parseResult['questions_outline'])) {
        $questionsOutlineForView = $parseResult['questions_outline'];
    }
}

$qsortHasResults = is_array($uiPayload) && (
    !empty($uiPayload['by_topic']) || !empty($uiPayload['general_problems'])
);
$topicsAlpineJson = '[]';
$generalProblemsAlpineJson = '[]';
$deploySubjectsJson = '[]';
if ($qsortHasResults) {
    $topicsAlpine = [];
    foreach ($uiPayload['by_topic'] ?? [] as $b) {
        $topicsAlpine[] = array_merge($b, ['open' => true]);
    }
    $topicsAlpineJson = json_encode($topicsAlpine, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
    $generalProblemsAlpineJson = json_encode($uiPayload['general_problems'] ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
    $deploySubjects = [];
    $sr = mysqli_query($conn, 'SELECT subject_id, subject_name FROM subjects ORDER BY subject_name ASC');
    if ($sr) {
        while ($row = mysqli_fetch_assoc($sr)) {
            $deploySubjects[] = [
                'subject_id' => (int)$row['subject_id'],
                'subject_name' => (string)$row['subject_name'],
            ];
        }
        mysqli_free_result($sr);
    }
    $deploySubjectsJson = json_encode($deploySubjects, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_admin.php'; ?>
  <link rel="stylesheet" href="assets/css/admin-quiz-ui.css?v=13">
  <style>
    /* Local layout tweaks; chrome matches pre-week materials via admin-quiz-ui + .admin-question-sort-page */
    .ereview-qsort-upload-zone {
      border: 2px dashed rgba(148, 163, 184, 0.28);
      border-radius: 0.75rem;
      padding: 1.35rem 1.25rem;
      text-align: center;
      transition: border-color 0.2s ease, background 0.2s ease;
      background: rgba(255, 255, 255, 0.02);
    }
    .ereview-qsort-upload-zone:hover {
      border-color: rgba(251, 191, 36, 0.45);
      background: rgba(251, 191, 36, 0.06);
    }
    .ereview-qsort-q .stem { font-size: 0.9375rem; color: #e5e7eb; line-height: 1.55; }
    .ereview-qsort-q .choice { font-size: 0.875rem; color: #cbd5e1; margin-top: 0.4rem; padding-left: 0.35rem; border-left: 2px solid rgba(251, 191, 36, 0.35); }
    .ereview-qsort-gp-shell { border-left: 3px solid rgba(56, 189, 248, 0.45); }
    .ereview-qsort-gp-head { border-bottom-color: rgba(56, 189, 248, 0.2) !important; }
    .ereview-qsort-gp-meta { font-size: 0.75rem; color: #94a3b8; margin-bottom: 0.5rem; }
    .ereview-qsort-topic-body { padding: 1rem 1.25rem 1.25rem; }
    [x-cloak] { display: none !important; }

    /* Deploy modal — viewport-centered via x-teleport="body" + flex overlay */
    .ereview-deploy-backdrop {
      position: absolute;
      inset: 0;
      background: rgba(2, 6, 23, 0.72);
      backdrop-filter: blur(10px);
      -webkit-backdrop-filter: blur(10px);
    }
    .ereview-deploy-panel {
      position: relative;
      width: 100%;
      max-width: 28rem;
      max-height: min(92dvh, 880px);
      display: flex;
      flex-direction: column;
      overflow: hidden;
      border-radius: 1rem;
      background: linear-gradient(165deg, rgba(30, 41, 59, 0.98) 0%, rgba(15, 23, 42, 0.99) 55%, rgba(15, 23, 42, 1) 100%);
      border: 1px solid rgba(148, 163, 184, 0.22);
      box-shadow:
        0 0 0 1px rgba(255, 255, 255, 0.06) inset,
        0 25px 50px -12px rgba(0, 0, 0, 0.55),
        0 0 80px -20px rgba(124, 58, 237, 0.35);
    }
    .ereview-deploy-panel__head {
      flex-shrink: 0;
      padding: 1.125rem 1.25rem;
      border-bottom: 1px solid rgba(255, 255, 255, 0.08);
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 0.75rem;
    }
    .ereview-deploy-panel__title-wrap {
      min-width: 0;
      flex: 1;
    }
    .ereview-deploy-panel__eyebrow {
      font-size: 0.6875rem;
      font-weight: 600;
      letter-spacing: 0.12em;
      text-transform: uppercase;
      color: rgba(167, 139, 250, 0.95);
      margin: 0 0 0.25rem 0;
    }
    .ereview-deploy-panel__title {
      font-size: 1.125rem;
      font-weight: 700;
      color: #f1f5f9;
      margin: 0;
      line-height: 1.35;
    }
    .ereview-deploy-topic-card {
      flex-shrink: 0;
      margin: 0 1.25rem 0;
      padding: 0.875rem 1rem;
      border-radius: 0.75rem;
      background: rgba(124, 58, 237, 0.1);
      border: 1px solid rgba(167, 139, 250, 0.28);
      border-left-width: 3px;
      border-left-color: rgba(167, 139, 250, 0.85);
    }
    .ereview-deploy-topic-card__label {
      font-size: 0.6875rem;
      font-weight: 600;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      color: rgba(196, 181, 253, 0.85);
      margin: 0 0 0.35rem 0;
    }
    .ereview-deploy-topic-card__text {
      font-size: 1rem;
      font-weight: 600;
      color: #f8fafc;
      line-height: 1.45;
      margin: 0;
      word-break: break-word;
    }
    .ereview-deploy-panel__body {
      flex: 1;
      min-height: 0;
      overflow-y: auto;
      padding: 1.25rem;
      display: flex;
      flex-direction: column;
      gap: 1.25rem;
    }
    .ereview-deploy-panel__lead {
      font-size: 0.8125rem;
      line-height: 1.55;
      color: rgba(148, 163, 184, 0.95);
      margin: 0;
    }
    .ereview-deploy-field-group {
      border-radius: 0.75rem;
      border: 1px solid rgba(255, 255, 255, 0.08);
      background: rgba(255, 255, 255, 0.03);
      padding: 1rem 1.125rem;
    }
    .ereview-deploy-label {
      display: block;
      font-size: 0.8125rem;
      font-weight: 600;
      color: #e2e8f0;
      margin-bottom: 0.5rem;
    }
    .ereview-deploy-select-wrap select.input-custom,
    .ereview-deploy-field-group .input-custom {
      background: rgba(15, 23, 42, 0.65);
      border-color: rgba(148, 163, 184, 0.22);
    }
    .ereview-deploy-type-pill {
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
      padding: 0.5rem 0.75rem;
      border-radius: 0.5rem;
      font-size: 0.875rem;
      font-weight: 600;
      color: #e9d5ff;
      background: rgba(124, 58, 237, 0.15);
      border: 1px solid rgba(167, 139, 250, 0.35);
    }
    .ereview-deploy-type-pill span.muted {
      font-weight: 500;
      font-size: 0.8125rem;
      color: rgba(196, 181, 253, 0.65);
    }
    .ereview-deploy-time-grid {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 0.75rem;
    }
    @media (max-width: 380px) {
      .ereview-deploy-time-grid {
        grid-template-columns: 1fr;
      }
    }
    .ereview-deploy-time-cell label {
      display: block;
      font-size: 0.6875rem;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.06em;
      color: rgba(148, 163, 184, 0.95);
      margin-bottom: 0.35rem;
    }
    .ereview-deploy-time-cell .input-custom {
      width: 100%;
      text-align: center;
      font-variant-numeric: tabular-nums;
    }
    .ereview-deploy-hint {
      font-size: 0.75rem;
      color: rgba(148, 163, 184, 0.9);
      margin: 0.5rem 0 0 0;
    }
    .ereview-deploy-footer {
      flex-shrink: 0;
      padding: 1rem 1.25rem 1.25rem;
      border-top: 1px solid rgba(255, 255, 255, 0.08);
      background: rgba(2, 6, 23, 0.35);
      display: flex;
      justify-content: flex-end;
      gap: 0.625rem;
      flex-wrap: wrap;
    }
    .ereview-deploy-target-row {
      display: flex;
      flex-direction: column;
      gap: 0.5rem;
    }
    .ereview-deploy-select-wrap select.input-custom.ereview-deploy-target-select {
      font-weight: 500;
    }
    .ereview-deploy-title-input[disabled] {
      opacity: 0.72;
      cursor: not-allowed;
    }
    .ereview-deploy-quiz-load {
      font-size: 0.75rem;
      color: rgba(148, 163, 184, 0.95);
      min-height: 1.25rem;
    }

    /* Validation error modal (above deploy dialog) */
    .ereview-val-overlay {
      position: fixed;
      inset: 0;
      z-index: 1305;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 1rem;
      background: rgba(2, 6, 23, 0.78);
      backdrop-filter: blur(8px);
      -webkit-backdrop-filter: blur(8px);
    }
    .ereview-val-card {
      width: 100%;
      max-width: 22rem;
      border-radius: 1rem;
      background: linear-gradient(165deg, rgba(30, 27, 35, 0.98) 0%, rgba(15, 23, 42, 0.99) 100%);
      border: 1px solid rgba(248, 113, 113, 0.35);
      box-shadow:
        0 0 0 1px rgba(255, 255, 255, 0.05) inset,
        0 24px 48px -12px rgba(0, 0, 0, 0.55),
        0 0 60px -18px rgba(220, 38, 38, 0.35);
      padding: 1.5rem 1.35rem 1.35rem;
      text-align: center;
      transform-origin: center;
    }
    .ereview-val-icon-wrap {
      display: flex;
      justify-content: center;
      margin-bottom: 1rem;
    }
    .ereview-val-icon-x {
      width: 4.5rem;
      height: 4.5rem;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      background: radial-gradient(circle at 32% 22%, rgba(254, 202, 202, 0.22), transparent 52%),
        linear-gradient(150deg, rgba(127, 29, 29, 0.55), rgba(30, 27, 35, 0.4));
      border: 2px solid rgba(248, 113, 113, 0.5);
      animation: ereview-val-pop 0.45s cubic-bezier(0.34, 1.4, 0.64, 1) both;
    }
    @keyframes ereview-val-pop {
      from {
        transform: scale(0.35) rotate(-12deg);
        opacity: 0;
      }
      70% {
        transform: scale(1.06) rotate(2deg);
      }
      to {
        transform: scale(1) rotate(0deg);
        opacity: 1;
      }
    }
    .ereview-val-icon-x svg {
      width: 2.25rem;
      height: 2.25rem;
    }
    .ereview-val-icon-x .ereview-x-line {
      stroke: #fecaca;
      stroke-width: 3.25;
      stroke-linecap: round;
      fill: none;
      stroke-dasharray: 40;
      stroke-dashoffset: 40;
    }
    .ereview-val-icon-x .ereview-x-line--2 {
      animation: ereview-x-stroke 0.42s ease forwards 0.08s;
    }
    .ereview-val-icon-x .ereview-x-line--1 {
      animation: ereview-x-stroke 0.42s ease forwards 0.22s;
    }
    @keyframes ereview-x-stroke {
      to {
        stroke-dashoffset: 0;
      }
    }
    .ereview-val-title {
      font-size: 1.125rem;
      font-weight: 700;
      color: #fef2f2;
      margin: 0 0 0.5rem 0;
      line-height: 1.35;
    }
    .ereview-val-code {
      font-size: 0.6875rem;
      font-weight: 600;
      letter-spacing: 0.1em;
      text-transform: uppercase;
      color: rgba(252, 165, 165, 0.85);
      margin: 0 0 0.75rem 0;
    }
    .ereview-val-body {
      font-size: 0.875rem;
      line-height: 1.55;
      color: rgba(203, 213, 225, 0.95);
      margin: 0 0 1rem 0;
    }
    .ereview-val-nums {
      display: flex;
      flex-wrap: wrap;
      gap: 0.35rem;
      justify-content: center;
      margin-bottom: 1.1rem;
    }
    .ereview-val-num-pill {
      font-family: ui-monospace, monospace;
      font-size: 0.8125rem;
      font-weight: 600;
      padding: 0.25rem 0.55rem;
      border-radius: 0.375rem;
      background: rgba(127, 29, 29, 0.45);
      border: 1px solid rgba(248, 113, 113, 0.35);
      color: #fecaca;
    }
    .ereview-val-actions {
      display: flex;
      justify-content: center;
    }
    .ereview-val-btn {
      padding: 0.55rem 1.35rem;
      border-radius: 0.5rem;
      font-weight: 600;
      font-size: 0.875rem;
      border: 1px solid rgba(248, 113, 113, 0.4);
      background: rgba(220, 38, 38, 0.25);
      color: #fecaca;
      cursor: pointer;
      transition: background 0.15s ease, transform 0.12s ease;
    }
    .ereview-val-btn:hover {
      background: rgba(220, 38, 38, 0.4);
    }
    .ereview-val-btn:active {
      transform: scale(0.97);
    }
  </style>
</head>
<body class="font-sans antialiased admin-app admin-question-sort-page">
  <?php include __DIR__ . '/admin_sidebar.php'; ?>

  <div class="px-5 max-w-[1600px] mx-auto w-full">
  <div class="quiz-admin-hero rounded-xl px-5 py-5 mb-4">
    <h1 class="text-2xl font-bold text-gray-100 m-0 flex flex-wrap items-center gap-2">
      <span class="quiz-admin-hero-icon quiz-admin-hero-icon--preweek" aria-hidden="true"><i class="bi bi-diagram-3"></i></span>
      Question sorting
    </h1>
    <p class="text-gray-400 mt-3 mb-0 max-w-3xl text-sm sm:text-base leading-relaxed">
      Upload a Word <strong class="text-gray-300">.docx</strong> with numbered multiple-choice items. The parser reads <strong class="text-gray-300">typed numbers</strong> (e.g. <code class="text-gray-400">148.</code>) and <strong class="text-gray-300">Word list numbering</strong> (numbers that exist only as list labels). Topics prefer <strong class="text-gray-300">red parentheticals</strong>, then lettered parentheses at the end of the stem. Purely numeric parentheses such as <strong class="text-gray-300">(2,200)</strong> are ignored.
      Yellow highlight and wording stay as in the file.
    </p>
    <?php if ($uiPayload && !empty($uiPayload['meta'])): ?>
    <div class="flex flex-wrap items-center gap-2 mt-4">
      <span class="preweek-stat-pill" title="Questions parsed"><i class="bi bi-list-ol text-sky-400"></i> <?php echo (int)($uiPayload['meta']['question_count'] ?? 0); ?> questions</span>
      <span class="preweek-stat-pill" title="Topic groups"><i class="bi bi-folder2-open text-amber-300"></i> <?php echo (int)($uiPayload['meta']['topic_count'] ?? 0); ?> topics</span>
      <span class="preweek-stat-pill" title="Paragraphs read"><i class="bi bi-text-paragraph text-violet-300"></i> <?php echo (int)($uiPayload['meta']['paragraph_count'] ?? 0); ?> paragraphs</span>
      <?php
      $__gpHero = (int)($uiPayload['meta']['general_problem_count'] ?? 0);
      if ($__gpHero === 0 && !empty($uiPayload['general_problems'])) {
          $__gpHero = count($uiPayload['general_problems']);
      }
      ?>
      <?php if ($__gpHero > 0): ?>
      <span class="preweek-stat-pill" title="Shared case text (not counted as questions)"><i class="bi bi-journal-text text-sky-300"></i> <?php echo $__gpHero; ?> general problems</span>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>

  <?php if ($parseError): ?>
    <div class="quiz-admin-alert quiz-admin-alert--error mb-4 flex items-center gap-2" role="alert">
      <i class="bi bi-exclamation-triangle-fill shrink-0"></i>
      <span><?php echo h($parseError); ?></span>
    </div>
  <?php endif; ?>

  <div class="quiz-admin-table-shell rounded-xl overflow-hidden mb-5">
    <div class="preweek-section-toolbar px-4 sm:px-5 py-4 flex flex-wrap items-center justify-between gap-3 border-b border-white/[0.08]">
      <div>
        <h2 class="text-lg font-bold text-gray-100 m-0 flex items-center gap-2"><i class="bi bi-cloud-arrow-up text-sky-400"></i> Upload document</h2>
        <p class="text-xs text-gray-500 m-0 mt-1 max-w-2xl">Use <strong class="text-gray-400">Parse &amp; group</strong> after selecting your file. Results stay in this session until you upload again.</p>
      </div>
    </div>
    <div class="p-4 sm:p-5">
    <form method="post" enctype="multipart/form-data" class="space-y-4" id="ereviewQsortForm">
      <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
      <input type="hidden" name="ereview_qsort_upload" value="1">
      <div class="ereview-qsort-upload-zone">
        <label for="ereview_qsort_file" class="cursor-pointer block">
          <span class="text-gray-300 font-semibold">Choose .docx file</span>
          <span class="block text-sm text-gray-500 mt-1">or drag and drop here (browser may still require using the file picker)</span>
        </label>
        <input type="file" name="docx" id="ereview_qsort_file" accept=".docx,application/vnd.openxmlformats-officedocument.wordprocessingml.document" class="mt-3 block w-full max-w-md mx-auto text-sm text-gray-300 file:mr-3 file:py-2 file:px-3 file:rounded-lg file:border-0 file:bg-sky-600 file:text-white file:font-semibold">
      </div>
      <div class="flex flex-col gap-3">
        <label class="inline-flex items-start gap-2.5 cursor-pointer text-sm text-gray-400 max-w-2xl">
          <input type="checkbox" name="ereview_qsort_debug" value="1" class="mt-1 rounded border-gray-600 text-amber-500 focus:ring-amber-500/40">
          <span><strong class="text-gray-300">Capture parser trace</strong> — logs every paragraph decision (list metadata, <code class="text-gray-500">in_choices</code>, split-after-choices flags). Use when a question sticks to the wrong item or topic. After upload, open <a class="text-sky-400 hover:underline" href="admin_question_sort.php?qsort_debug=1">Parser debug</a> or download <code class="text-gray-500">.parse-trace.json</code>.</span>
        </label>
        <div class="flex flex-wrap gap-2 items-center">
        <button type="submit" class="admin-materials-submit-btn inline-flex items-center gap-2" id="ereviewQsortSubmit">
          <i class="bi bi-cpu"></i><span>Parse &amp; group</span>
        </button>
        <span class="text-xs text-gray-500 leading-relaxed">Items start with <code class="text-gray-400 bg-white/5 px-1 rounded">148.</code> or <code class="text-gray-400 bg-white/5 px-1 rounded">150.What</code>; choices: <code class="text-gray-400 bg-white/5 px-1 rounded">a.</code>&ndash;<code class="text-gray-400 bg-white/5 px-1 rounded">d.</code> or numeric lines (e.g. <code class="text-gray-400 bg-white/5 px-1 rounded">80,000</code>).</span>
        </div>
      </div>
    </form>
    </div>
  </div>

  <?php if ($qsortHasResults): ?>
  <script>
  document.addEventListener('alpine:init', function () {
    Alpine.data('adminQuestionSortResults', function () {
      return {
        topics: <?php echo $topicsAlpineJson; ?>,
        generalProblems: <?php echo $generalProblemsAlpineJson; ?>,
        deploySubjects: <?php echo $deploySubjectsJson; ?>,
        csrfToken: <?php echo json_encode($csrf); ?>,
        deployOpen: false,
        deployTopic: '',
        deploySubjectId: '',
        deployTitle: '',
        deployTargetQuizId: '',
        deployQuestions: [],
        existingQuizzes: [],
        deployQuizzesLoading: false,
        deployQuizzesErr: '',
        deployHours: 0,
        deployMins: 30,
        deploySecs: 0,
        deployBusy: false,
        deployErr: '',
        valModalOpen: false,
        valModalTitle: '',
        valModalCode: '',
        valModalMessage: '',
        valModalNumbers: [],
        gpOpen: true,
        init() {
          var self = this;
          this.$watch('deploySubjectId', function () {
            if (self.deployOpen) {
              self.loadDeployQuizzes();
            }
          });
        },
        filter: '',
        get generalProblemsFiltered() {
          var q = (this.filter || '').trim().toLowerCase();
          if (!q) return this.generalProblems;
          return this.generalProblems.filter(function (gp) {
            if ((gp.stem_plain || '').toLowerCase().indexOf(q) !== -1) return true;
            return (gp.extracted_after_question || '').toLowerCase().indexOf(q) !== -1;
          });
        },
        get topicsFiltered() {
          var q = (this.filter || '').trim().toLowerCase();
          if (!q) return this.topics;
          return this.topics.filter(function (block) {
            if ((block.topic || '').toLowerCase().indexOf(q) !== -1) return true;
            return (block.questions || []).some(function (qu) {
              if ((qu.stem_plain || '').toLowerCase().indexOf(q) !== -1) return true;
              return (qu.choices_html || []).some(function (h) {
                return (h || '').toLowerCase().indexOf(q) !== -1;
              });
            });
          });
        },
        get nothingMatchesFilter() {
          return this.topicsFiltered.length === 0 && this.generalProblemsFiltered.length === 0;
        },
        collectDuplicateQuestionNumbers(questions) {
          var counts = {};
          (questions || []).forEach(function (qu) {
            var n = String(qu && qu.number != null ? qu.number : '').trim();
            if (!n) return;
            counts[n] = (counts[n] || 0) + 1;
          });
          var dups = [];
          Object.keys(counts).forEach(function (k) {
            if (counts[k] > 1) dups.push(k);
          });
          dups.sort(function (a, b) {
            var na = parseInt(a, 10);
            var nb = parseInt(b, 10);
            if (!isNaN(na) && !isNaN(nb) && String(na) === a && String(nb) === b) return na - nb;
            return a < b ? -1 : a > b ? 1 : 0;
          });
          return dups;
        },
        showDeployValidationError(code, title, message, numbers) {
          this.valModalCode = code || '';
          this.valModalTitle = title || 'Cannot deploy';
          this.valModalMessage = message || '';
          this.valModalNumbers = Array.isArray(numbers) ? numbers : [];
          this.valModalOpen = true;
        },
        closeValModal() {
          this.valModalOpen = false;
        },
        valCodeLabel() {
          var c = this.valModalCode || '';
          if (c === 'DUPLICATE_IN_PARSE') return 'Duplicate in this topic';
          if (c === 'DUPLICATE_IN_QUIZ') return 'Already in that quiz';
          return c;
        },
        onDeployQuizTargetChange() {
          var id = this.deployTargetQuizId;
          if (!id) {
            this.deployTitle = this.deployTopic || '';
            return;
          }
          var list = this.existingQuizzes || [];
          for (var i = 0; i < list.length; i++) {
            if (String(list[i].quiz_id) === String(id)) {
              this.deployTitle = list[i].title || this.deployTopic || '';
              return;
            }
          }
        },
        async loadDeployQuizzes() {
          this.deployQuizzesErr = '';
          this.existingQuizzes = [];
          if (!this.deploySubjectId) return;
          this.deployQuizzesLoading = true;
          try {
            var fd = new FormData();
            fd.append('csrf_token', this.csrfToken);
            fd.append('subject_id', this.deploySubjectId);
            var res = await fetch('api/question_sort_quiz_options.php', { method: 'POST', body: fd, credentials: 'same-origin' });
            var data = await res.json().catch(function () { return {}; });
            if (data.ok && Array.isArray(data.quizzes)) {
              this.existingQuizzes = data.quizzes;
            } else {
              this.deployQuizzesErr = data.error || 'Could not load existing quizzes.';
            }
          } catch (e) {
            this.deployQuizzesErr = 'Network error loading quizzes.';
          } finally {
            this.deployQuizzesLoading = false;
          }
        },
        openDeploy(block) {
          this.deployTopic = block.topic || '';
          this.deployTitle = block.topic || '';
          this.deployQuestions = Array.isArray(block.questions) ? block.questions.slice() : [];
          this.deployTargetQuizId = '';
          var subs = this.deploySubjects || [];
          this.deploySubjectId = subs.length ? String(subs[0].subject_id) : '';
          this.deployHours = 0;
          this.deployMins = 30;
          this.deploySecs = 0;
          this.deployErr = '';
          this.valModalOpen = false;
          this.deployOpen = true;
          this.loadDeployQuizzes();
        },
        async submitDeploy() {
          this.deployErr = '';
          if (!this.deploySubjectId) {
            this.showDeployValidationError('', 'Missing subject', 'Choose a subject before deploying.', []);
            return;
          }
          var dups = this.collectDuplicateQuestionNumbers(this.deployQuestions);
          if (dups.length) {
            this.showDeployValidationError(
              'DUPLICATE_IN_PARSE',
              'Duplicate question numbers',
              'This topic contains more than one item with the same number. Fix the document or re-parse before deploying.',
              dups
            );
            return;
          }
          if (!this.deployTargetQuizId) {
            var t = (this.deployTitle || '').trim();
            if (!t) {
              this.showDeployValidationError('', 'Quiz title required', 'Enter a quiz title, or pick an existing topical quiz to append to.', []);
              return;
            }
          }
          this.deployBusy = true;
          try {
            var fd = new FormData();
            fd.append('csrf_token', this.csrfToken);
            fd.append('subject_id', this.deploySubjectId);
            fd.append('topic', this.deployTopic);
            fd.append('title', (this.deployTitle || '').trim() || this.deployTopic);
            fd.append('time_limit_hours', String(this.deployHours));
            fd.append('time_limit_mins', String(this.deployMins));
            fd.append('time_limit_secs', String(this.deploySecs));
            if (this.deployTargetQuizId) {
              fd.append('quiz_id', String(this.deployTargetQuizId));
            }
            var res = await fetch('api/question_sort_deploy.php', { method: 'POST', body: fd, credentials: 'same-origin' });
            var data = await res.json().catch(function () { return {}; });
            if (!data.ok) {
              var code = data.error_code || '';
              var nums = data.duplicate_numbers || [];
              if (code === 'DUPLICATE_IN_PARSE' || code === 'DUPLICATE_IN_QUIZ') {
                this.showDeployValidationError(
                  code,
                  code === 'DUPLICATE_IN_QUIZ' ? 'Numbers already in that quiz' : 'Duplicate question numbers',
                  data.error || 'Deploy blocked.',
                  nums
                );
              } else {
                this.showDeployValidationError(code, 'Deploy failed', data.error || 'Deploy failed.', []);
              }
              return;
            }
            window.location.href = data.redirect_questions;
          } catch (e) {
            this.showDeployValidationError('', 'Network error', 'Could not reach the server. Check your connection and try again.', []);
          } finally {
            this.deployBusy = false;
          }
        }
      };
    });
  });
  </script>

  <div x-data="adminQuestionSortResults()" class="space-y-4">
    <div class="quiz-admin-table-shell rounded-xl overflow-hidden">
      <div class="preweek-section-toolbar px-4 sm:px-5 py-3.5 flex flex-wrap items-center gap-3 border-b border-white/[0.08]">
        <span class="text-xs font-semibold uppercase tracking-wide text-gray-500 shrink-0">Export</span>
        <?php
        $__qc = (int)($uiPayload['meta']['question_count'] ?? 0);
        $__gpc = (int)($uiPayload['meta']['general_problem_count'] ?? count($uiPayload['general_problems'] ?? []));
        if ($__qc > 0 || $__gpc > 0):
        ?>
        <span class="text-sm text-gray-300 font-semibold tabular-nums" title="Parsed counts for this document"><?php echo $__qc; ?> questions<?php if ($__gpc > 0): ?> · <?php echo $__gpc; ?> general problem<?php echo $__gpc === 1 ? '' : 's'; ?><?php endif; ?></span>
        <?php endif; ?>
        <div class="flex flex-wrap items-center gap-2">
          <a href="admin_question_sort.php?download=json" class="quiz-admin-btn-secondary inline-flex items-center gap-2 px-3.5 py-2 rounded-lg font-semibold text-sm no-underline"><i class="bi bi-filetype-json text-sky-400"></i> JSON</a>
          <a href="admin_question_sort.php?download=parse_trace" class="quiz-admin-btn-secondary inline-flex items-center gap-2 px-3.5 py-2 rounded-lg font-semibold text-sm no-underline<?php echo $parseTraceForView === null ? ' opacity-40 pointer-events-none' : ''; ?>" title="<?php echo $parseTraceForView === null ? 'Re-upload with Capture parser trace enabled' : 'Download paragraph-by-paragraph parser log'; ?>"><i class="bi bi-bug text-amber-400"></i> Parse trace</a>
          <a href="admin_question_sort.php?download=html" class="quiz-admin-btn-secondary inline-flex items-center gap-2 px-3.5 py-2 rounded-lg font-semibold text-sm no-underline"><i class="bi bi-file-earmark-code text-emerald-400"></i> HTML</a>
          <a href="admin_question_sort.php?download=docx" class="quiz-admin-btn-secondary inline-flex items-center gap-2 px-3.5 py-2 rounded-lg font-semibold text-sm no-underline"><i class="bi bi-file-earmark-word text-blue-400"></i> Word</a>
          <?php if ($parseTraceForView !== null): ?>
          <a href="admin_question_sort.php?qsort_debug=1" class="quiz-admin-btn-secondary inline-flex items-center gap-2 px-3.5 py-2 rounded-lg font-semibold text-sm no-underline"><i class="bi bi-table text-violet-400"></i> Parser debug</a>
          <?php endif; ?>
        </div>
        <div class="w-full sm:flex-1 sm:max-w-md sm:ml-auto min-w-0">
          <label for="ereview_qsort_filter" class="block text-xs font-semibold uppercase tracking-wide text-gray-400 mb-1">Filter</label>
          <div class="relative">
            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-500 pointer-events-none"><i class="bi bi-search"></i></span>
            <input type="search" id="ereview_qsort_filter" x-model.debounce.200ms="filter" placeholder="Topic, general problem, or question…" class="input-custom w-full pl-10" autocomplete="off">
          </div>
        </div>
      </div>
    </div>

    <div class="quiz-admin-table-shell rounded-xl overflow-hidden ereview-qsort-gp-shell" x-show="generalProblems.length > 0" x-cloak>
      <div class="ereview-qsort-topic-head ereview-qsort-gp-head preweek-section-toolbar px-4 sm:px-5 py-3.5 flex flex-wrap items-center justify-between gap-3 cursor-pointer select-none" @click="gpOpen = !gpOpen" role="button" tabindex="0" @keydown.enter.prevent="gpOpen = !gpOpen" @keydown.space.prevent="gpOpen = !gpOpen" :aria-expanded="gpOpen ? 'true' : 'false'">
        <div class="flex items-center gap-2 min-w-0">
          <span class="quiz-admin-count-pill shrink-0 bg-sky-900/50 text-sky-200 border border-sky-500/30" x-text="generalProblems.length"></span>
          <h3 class="text-base font-bold text-gray-100 m-0 truncate">General problems</h3>
        </div>
        <span class="text-xs text-gray-500 shrink-0 inline-flex items-center gap-1">
          <span>Shared case text (not questions)</span>
          <i class="bi" :class="gpOpen ? 'bi-chevron-up' : 'bi-chevron-down'"></i>
        </span>
      </div>
      <div class="ereview-qsort-topic-body border-t border-white/[0.06]" x-show="gpOpen" x-cloak>
        <p class="text-xs text-gray-500 m-0 px-4 pt-3 pb-1">These blocks were split out of a preceding item’s choices. Later numbered questions (for example 205–212) refer to this context.</p>
        <p class="text-xs text-amber-200/80 m-0 px-4 pb-2" x-show="generalProblems.length > 0 && generalProblemsFiltered.length === 0" x-cloak>No general problems match your filter.</p>
        <template x-for="(gp, gix) in generalProblemsFiltered" :key="'gp-' + (gp.id || gix) + '-' + gix">
          <div class="ereview-qsort-q mt-3 first:mt-0 p-3 sm:p-3.5 border-t border-white/[0.06]">
            <p class="ereview-qsort-gp-meta m-0" x-show="gp.extracted_after_question">After item <span class="font-mono text-sky-300" x-text="gp.extracted_after_question"></span></p>
            <div class="stem" x-html="gp.stem_html_joined"></div>
          </div>
        </template>
      </div>
    </div>

    <div class="preweek-materials-stack space-y-3">
      <template x-for="(block, idx) in topicsFiltered" :key="block.topic + '-' + idx">
        <div class="quiz-admin-table-shell rounded-xl overflow-hidden ereview-qsort-topic-shell">
          <div class="ereview-qsort-topic-head preweek-section-toolbar px-4 sm:px-5 py-3.5 flex flex-wrap items-center justify-between gap-3 select-none">
            <div class="flex items-center gap-2 min-w-0 flex-1 cursor-pointer" @click="block.open = !block.open" role="button" tabindex="0" @keydown.enter.prevent="block.open = !block.open" @keydown.space.prevent="block.open = !block.open" :aria-expanded="block.open ? 'true' : 'false'">
              <span class="quiz-admin-count-pill quiz-admin-count-pill--preweek shrink-0" x-text="block.questions.length"></span>
              <h3 class="text-base font-bold text-gray-100 m-0 truncate" x-text="block.topic"></h3>
            </div>
            <div class="flex flex-wrap items-center gap-2 shrink-0">
              <button type="button" class="quiz-admin-btn-secondary inline-flex items-center gap-2 px-3 py-2 rounded-lg font-semibold text-sm border border-violet-500/40 text-violet-200 hover:bg-violet-500/15 transition" @click.stop="openDeploy(block)" title="Create a topical quiz from this topic">
                <i class="bi bi-send-fill"></i><span class="hidden sm:inline">Deploy to quizzes</span><span class="sm:hidden">Deploy</span>
              </button>
              <span class="text-xs text-gray-500 inline-flex items-center gap-1 cursor-pointer" @click="block.open = !block.open" role="presentation">
                <span x-text="block.questions.length === 1 ? '1 question' : (block.questions.length + ' questions')"></span>
                <i class="bi" :class="block.open ? 'bi-chevron-up' : 'bi-chevron-down'"></i>
              </span>
            </div>
          </div>
          <div class="ereview-qsort-topic-body border-t border-white/[0.06]" x-show="block.open" x-cloak>
            <template x-for="(qu, qix) in block.questions" :key="String(qu.number) + '-' + qix">
              <div class="ereview-qsort-q mt-3 first:mt-0 p-3 sm:p-3.5">
                <div class="stem" x-html="qu.stem_html_joined"></div>
                <template x-for="(ch, cix) in (qu.choices_html || [])" :key="'c-' + cix">
                  <div class="choice" x-html="ch"></div>
                </template>
              </div>
            </template>
          </div>
        </div>
      </template>
      <p class="quiz-admin-empty text-center py-10 m-0" x-show="nothingMatchesFilter" x-cloak><span class="quiz-admin-empty-icon block text-3xl mb-2"><i class="bi bi-funnel"></i></span>No topics or general problems match your filter.</p>
    </div>

    <template x-teleport="body">
      <div
        x-show="deployOpen"
        x-cloak
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        class="fixed inset-0 z-[1200] flex items-center justify-center p-4 sm:p-6 box-border"
        @keydown.escape.window="if (!valModalOpen) deployOpen = false"
        role="presentation"
      >
        <div class="ereview-deploy-backdrop" @click="deployOpen = false" aria-hidden="true"></div>
        <div
          class="ereview-deploy-panel relative mx-auto my-auto shadow-2xl"
          @click.stop
          role="dialog"
          aria-modal="true"
          aria-labelledby="ereview-deploy-dialog-title"
        >
          <header class="ereview-deploy-panel__head">
            <div class="ereview-deploy-panel__title-wrap">
              <p class="ereview-deploy-panel__eyebrow">Deploy to quizzes</p>
              <h2 id="ereview-deploy-dialog-title" class="ereview-deploy-panel__title" x-text="deployTargetQuizId ? 'Append to existing quiz' : 'New topical quiz'"></h2>
            </div>
            <button type="button" @click="deployOpen = false" class="p-2 rounded-lg text-gray-400 hover:bg-white/10 hover:text-white shrink-0 -mr-1" aria-label="Close dialog"><i class="bi bi-x-lg text-lg"></i></button>
          </header>

          <div class="ereview-deploy-topic-card">
            <p class="ereview-deploy-topic-card__label">Topic</p>
            <p class="ereview-deploy-topic-card__text" x-text="deployTopic"></p>
          </div>

          <div class="ereview-deploy-panel__body">
            <p class="ereview-deploy-panel__lead">Creates a quiz under the subject you choose and imports every question in this topic, including choices and the <strong class="text-amber-200/95 font-semibold">yellow-highlighted</strong> correct answers.</p>

            <div class="ereview-deploy-field-group ereview-deploy-select-wrap">
              <label class="ereview-deploy-label" for="ereview-deploy-subject">Subject</label>
              <select id="ereview-deploy-subject" x-model="deploySubjectId" class="input-custom w-full">
                <template x-for="s in deploySubjects" :key="s.subject_id">
                  <option :value="String(s.subject_id)" x-text="s.subject_name"></option>
                </template>
              </select>
              <p class="ereview-deploy-hint text-amber-200/85" x-show="deploySubjects.length === 0">No subjects yet. Add them under Content Hub first.</p>
            </div>

            <div class="ereview-deploy-field-group ereview-deploy-target-row">
              <label class="ereview-deploy-label" for="ereview-deploy-target">Quiz target</label>
              <select
                id="ereview-deploy-target"
                class="input-custom w-full ereview-deploy-target-select"
                x-model="deployTargetQuizId"
                @change="onDeployQuizTargetChange()"
              >
                <option value="">Create new topical quiz…</option>
                <template x-for="qz in existingQuizzes" :key="'qzopt-' + qz.quiz_id">
                  <option :value="String(qz.quiz_id)" x-text="(qz.title || 'Untitled') + ' · ' + qz.question_count + ' question' + (qz.question_count === 1 ? '' : 's')"></option>
                </template>
              </select>
              <p class="ereview-deploy-quiz-load" x-show="deployQuizzesLoading" x-cloak><i class="bi bi-arrow-repeat animate-spin"></i> Loading quizzes for this subject…</p>
              <p class="ereview-deploy-hint text-rose-300/90 m-0" x-show="deployQuizzesErr && !deployQuizzesLoading" x-cloak x-text="deployQuizzesErr"></p>
              <p class="ereview-deploy-hint m-0" x-show="deployTargetQuizId" x-cloak>New questions are <strong class="text-violet-200">appended</strong> to the quiz you select. Item numbers must not already exist in that quiz.</p>
            </div>

            <div class="ereview-deploy-field-group">
              <label class="ereview-deploy-label" for="ereview-deploy-quiz-title">Quiz title</label>
              <input
                id="ereview-deploy-quiz-title"
                type="text"
                x-model="deployTitle"
                class="input-custom w-full ereview-deploy-title-input"
                :disabled="!!deployTargetQuizId"
                placeholder="Defaults to topic name above"
                autocomplete="off"
              >
              <p class="ereview-deploy-hint m-0" x-show="!deployTargetQuizId" x-cloak>Used as the new quiz name. Pick an existing quiz above to merge into it instead.</p>
            </div>

            <div class="ereview-deploy-field-group">
              <span class="ereview-deploy-label">Quiz type</span>
              <div class="ereview-deploy-type-pill">
                <i class="bi bi-layers-fill text-violet-300" aria-hidden="true"></i>
                <span>Topical</span>
                <span class="muted">Fixed for this workflow</span>
              </div>
            </div>

            <div class="ereview-deploy-field-group">
              <span class="ereview-deploy-label">Time limit</span>
              <div class="ereview-deploy-time-grid">
                <div class="ereview-deploy-time-cell">
                  <label for="ereview-deploy-h">Hours</label>
                  <input id="ereview-deploy-h" type="number" x-model.number="deployHours" min="0" max="24" class="input-custom" placeholder="0" :disabled="!!deployTargetQuizId">
                </div>
                <div class="ereview-deploy-time-cell">
                  <label for="ereview-deploy-m">Minutes</label>
                  <input id="ereview-deploy-m" type="number" x-model.number="deployMins" min="0" max="59" class="input-custom" placeholder="30" :disabled="!!deployTargetQuizId">
                </div>
                <div class="ereview-deploy-time-cell">
                  <label for="ereview-deploy-s">Seconds</label>
                  <input id="ereview-deploy-s" type="number" x-model.number="deploySecs" min="0" max="59" class="input-custom" placeholder="0" :disabled="!!deployTargetQuizId">
                </div>
              </div>
              <p class="ereview-deploy-hint" x-show="!deployTargetQuizId" x-cloak>At least 1 second total. Maximum 24 hours.</p>
              <p class="ereview-deploy-hint m-0" x-show="deployTargetQuizId" x-cloak>Existing quiz time limit is kept when appending.</p>
            </div>

            <div x-show="deployErr" x-cloak class="quiz-admin-alert quiz-admin-alert--error text-sm m-0" role="alert" x-text="deployErr"></div>
          </div>

          <footer class="ereview-deploy-footer">
            <button type="button" @click="deployOpen = false" class="px-4 py-2.5 rounded-lg font-semibold border border-white/20 text-gray-200 hover:bg-white/10 transition min-w-[5.5rem]">Cancel</button>
            <button type="button" @click="submitDeploy()" :disabled="deployBusy || deploySubjects.length === 0" class="px-5 py-2.5 rounded-lg font-semibold bg-violet-600 text-white hover:bg-violet-500 transition inline-flex items-center justify-center gap-2 min-w-[7.5rem] shadow-lg shadow-violet-900/35 disabled:opacity-45 disabled:pointer-events-none">
              <i class="bi bi-send-fill" x-show="!deployBusy"></i>
              <i class="bi bi-hourglass-split animate-pulse" x-show="deployBusy" x-cloak></i>
              <span x-text="deployBusy ? (deployTargetQuizId ? 'Appending…' : 'Deploying…') : (deployTargetQuizId ? 'Append questions' : 'Deploy')"></span>
            </button>
          </footer>
        </div>
      </div>
    </template>

    <template x-teleport="body">
      <div
        x-show="valModalOpen"
        x-cloak
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        class="ereview-val-overlay"
        @click.self="closeValModal()"
        @keydown.escape.window="valModalOpen && closeValModal()"
        role="alertdialog"
        aria-modal="true"
        aria-labelledby="ereview-val-modal-title"
      >
        <div
          class="ereview-val-card"
          @click.stop
          x-transition:enter="transition ease-out duration-280"
          x-transition:enter-start="opacity-0 scale-90 translate-y-2"
          x-transition:enter-end="opacity-100 scale-100 translate-y-0"
        >
          <div class="ereview-val-icon-wrap" aria-hidden="true">
            <div class="ereview-val-icon-x">
              <svg viewBox="0 0 40 40" aria-hidden="true">
                <line class="ereview-x-line ereview-x-line--1" x1="10" y1="10" x2="30" y2="30" />
                <line class="ereview-x-line ereview-x-line--2" x1="30" y1="10" x2="10" y2="30" />
              </svg>
            </div>
          </div>
          <p class="ereview-val-code" x-show="valModalCode" x-text="valCodeLabel()"></p>
          <h3 id="ereview-val-modal-title" class="ereview-val-title" x-text="valModalTitle"></h3>
          <p class="ereview-val-body" x-text="valModalMessage"></p>
          <div class="ereview-val-nums" x-show="valModalNumbers.length" x-cloak>
            <template x-for="n in valModalNumbers" :key="'vdup-' + n">
              <span class="ereview-val-num-pill" x-text="'#' + n"></span>
            </template>
          </div>
          <div class="ereview-val-actions">
            <button type="button" class="ereview-val-btn" @click="closeValModal()">Dismiss</button>
          </div>
        </div>
      </div>
    </template>
  </div>
  <?php elseif ($uiPayload && !$qsortHasResults): ?>
    <div class="quiz-admin-alert quiz-admin-alert--warning mb-4" role="status">
      No numbered questions were detected. Ensure each item starts with a number and <code class="text-gray-300">.</code> or <code class="text-gray-300">)</code>.
    </div>
  <?php endif; ?>

  <?php if (isset($_GET['qsort_debug'])): ?>
  <div class="quiz-admin-table-shell rounded-xl overflow-hidden mb-5 ereview-qsort-debug-panel">
    <div class="preweek-section-toolbar px-4 sm:px-5 py-3.5 border-b border-white/[0.08]">
      <h2 class="text-lg font-bold text-gray-100 m-0 flex items-center gap-2"><i class="bi bi-bug text-amber-400"></i> Parser debug</h2>
      <p class="text-xs text-gray-500 m-0 mt-1 max-w-3xl">Rows match <code class="text-gray-400">word/document.xml</code> body paragraphs in order. Look for question 4’s last choice, then the next row: if <code class="text-gray-400">action</code> is <strong class="text-gray-300">append_while_in_choices_not_choice_shape</strong>, the splitter did not run. Check <code class="text-gray-400">after_choices.split_para</code> (should be <code class="text-gray-400">true</code> for a new item after <code class="text-gray-400">a.–d.</code>).</p>
    </div>
    <div class="p-4 sm:p-5">
      <?php if ($parseTraceForView === null): ?>
        <div class="quiz-admin-alert quiz-admin-alert--warning m-0" role="status">
          No trace in session. Upload the .docx again and enable <strong class="text-gray-300">Capture parser trace</strong>, then return to this link.
        </div>
      <?php else: ?>
        <?php if (is_array($questionsOutlineForView) && $questionsOutlineForView !== []): ?>
        <h3 class="text-sm font-bold text-gray-300 mt-0 mb-2">Questions outline (number → topic)</h3>
        <div class="overflow-x-auto mb-5 rounded-lg border border-white/[0.08]">
          <table class="w-full text-left text-xs text-gray-300 border-collapse">
            <thead><tr class="bg-white/[0.04] text-gray-500 uppercase tracking-wide"><th class="p-2 w-16">#</th><th class="p-2 w-40">Topic</th><th class="p-2">Stem preview</th><th class="p-2 w-36">Source</th></tr></thead>
            <tbody>
              <?php foreach ($questionsOutlineForView as $row): ?>
              <tr class="border-t border-white/[0.06]">
                <td class="p-2 font-mono tabular-nums"><?php echo h((string)($row['number'] ?? '')); ?></td>
                <td class="p-2"><?php echo h((string)($row['topic'] ?? '—')); ?></td>
                <td class="p-2 text-gray-400"><?php echo h((string)($row['stem_preview'] ?? '')); ?></td>
                <td class="p-2 text-gray-500"><?php echo h((string)($row['topic_source'] ?? '')); ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
        <h3 class="text-sm font-bold text-gray-300 mt-0 mb-2">Paragraph trace</h3>
        <div class="overflow-x-auto max-h-[70vh] rounded-lg border border-white/[0.08]">
          <table class="w-full text-left text-xs text-gray-300 border-collapse min-w-[900px]">
            <thead class="sticky top-0 z-10 bg-slate-900/95 shadow-sm">
              <tr class="text-gray-500 uppercase tracking-wide">
                <th class="p-2 w-12">Idx</th>
                <th class="p-2 w-44">Action</th>
                <th class="p-2 w-14">#in</th>
                <th class="p-2 w-16">ch?</th>
                <th class="p-2 w-14">ord</th>
                <th class="p-2 w-14">infer</th>
                <th class="p-2">Plain preview</th>
                <th class="p-2 w-36">Split hints</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($parseTraceForView as $row): ?>
              <?php
                if (!is_array($row)) {
                    continue;
                }
                $act = (string)($row['action'] ?? '');
                if ($act === 'summary') {
                    echo '<tr class="bg-amber-900/20 border-t border-amber-500/30"><td class="p-2 font-mono" colspan="8">summary — paragraphs: ' . h((string)($row['total_paragraphs'] ?? '')) . ', blocks: ' . h((string)($row['total_question_blocks'] ?? '')) . '</td></tr>';
                    continue;
                }
                $list = $row['list'] ?? [];
                $ac = $row['after_choices'] ?? null;
                $splitHint = '';
                if (is_array($ac)) {
                    $splitHint = 'line:' . (!empty($ac['split_line_only']) ? '1' : '0') . ' para:' . (!empty($ac['split_para']) ? '1' : '0');
                }
              ?>
              <tr class="border-t border-white/[0.06] align-top hover:bg-white/[0.02]">
                <td class="p-2 font-mono text-gray-500"><?php echo h((string)($row['para_index'] ?? '')); ?></td>
                <td class="p-2 font-mono text-amber-200/90"><?php echo h($act); ?></td>
                <td class="p-2 font-mono"><?php echo h((string)($row['state_in']['current_num'] ?? '')); ?></td>
                <td class="p-2"><?php echo !empty($row['state_in']['in_choices']) ? 'Y' : ''; ?></td>
                <td class="p-2 font-mono"><?php echo h((string)($list['list_ord'] ?? '')); ?></td>
                <td class="p-2 font-mono"><?php echo h((string)($row['infer_num'] ?? '')); ?></td>
                <td class="p-2 text-gray-400 break-words"><?php echo h((string)($row['plain_preview'] ?? '')); ?></td>
                <td class="p-2 font-mono text-gray-500"><?php echo h($splitHint); ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <p class="text-xs text-gray-500 mt-3 mb-0">Download full JSON: <a class="text-sky-400 hover:underline" href="admin_question_sort.php?download=parse_trace">parse_trace</a> (includes <code class="text-gray-500">list_fmt</code>, <code class="text-gray-500">lead_text_num</code>, flags).</p>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <script>
  (function () {
    var form = document.getElementById('ereviewQsortForm');
    var btn = document.getElementById('ereviewQsortSubmit');
    if (form && btn) {
      form.addEventListener('submit', function () {
        btn.disabled = true;
        var sp = btn.querySelector('span');
        if (sp) sp.textContent = 'Processing…';
      });
    }
  })();
  </script>
  </div>
</div>
</main>
</body>
</html>
