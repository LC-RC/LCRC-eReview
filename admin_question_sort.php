<?php
/**
 * Admin: automated question sorting from .docx — parse, group by trailing (Topic), export JSON/HTML/DOCX.
 */
require_once 'auth.php';
requireRole('admin');
require_once __DIR__ . '/includes/docx_question_parser.php';
require_once __DIR__ . '/includes/docx_grouped_export.php';

$pageTitle = 'Question sorting';
$csrf = generateCSRFToken();

$sessionKey = 'ereview_qsort_cache_v1';

function ereview_qsort_cache_path(): string {
    $sid = session_id();
    if ($sid === '') {
        $sid = 'nosess';
    }
    return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ereview_qsort_' . preg_replace('/[^a-zA-Z0-9_-]/', '', $sid) . '.json';
}

function ereview_qsort_is_temp_json_safe(string $path): bool {
    $real = realpath($path);
    if ($real === false || !is_readable($real)) {
        return false;
    }
    $tmp = realpath(sys_get_temp_dir());
    if ($tmp === false) {
        return false;
    }
    return strncmp($real, $tmp, strlen($tmp)) === 0 && strncmp(basename($real), 'ereview_qsort_', 14) === 0;
}

/** @return array|null */
function ereview_qsort_load_cache(): ?array {
    global $sessionKey;
    if (empty($_SESSION[$sessionKey]['path'])) {
        return null;
    }
    $p = (string)$_SESSION[$sessionKey]['path'];
    if (!ereview_qsort_is_temp_json_safe($p)) {
        unset($_SESSION[$sessionKey]);
        return null;
    }
    $raw = @file_get_contents($p);
    if ($raw === false || $raw === '') {
        return null;
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

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
                        $qlist[] = [
                            'number' => $q['number'],
                            'stem_plain' => $q['stem_plain'],
                            'stem_html_joined' => $q['stem_html_joined'],
                            'choices_html' => $q['choices_html'],
                            'topic' => $q['topic'],
                            'topic_source' => $q['topic_source'] ?? '',
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
if ($qsortHasResults) {
    $topicsAlpine = [];
    foreach ($uiPayload['by_topic'] ?? [] as $b) {
        $topicsAlpine[] = array_merge($b, ['open' => true]);
    }
    $topicsAlpineJson = json_encode($topicsAlpine, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
    $generalProblemsAlpineJson = json_encode($uiPayload['general_problems'] ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
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
        gpOpen: true,
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
          <div class="ereview-qsort-topic-head preweek-section-toolbar px-4 sm:px-5 py-3.5 flex flex-wrap items-center justify-between gap-3 cursor-pointer select-none" @click="block.open = !block.open" role="button" tabindex="0" @keydown.enter.prevent="block.open = !block.open" @keydown.space.prevent="block.open = !block.open" :aria-expanded="block.open ? 'true' : 'false'">
            <div class="flex items-center gap-2 min-w-0">
              <span class="quiz-admin-count-pill quiz-admin-count-pill--preweek shrink-0" x-text="block.questions.length"></span>
              <h3 class="text-base font-bold text-gray-100 m-0 truncate" x-text="block.topic"></h3>
            </div>
            <span class="text-xs text-gray-500 shrink-0 inline-flex items-center gap-1">
              <span x-text="block.questions.length === 1 ? '1 question' : (block.questions.length + ' questions')"></span>
              <i class="bi" :class="block.open ? 'bi-chevron-up' : 'bi-chevron-down'"></i>
            </span>
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
