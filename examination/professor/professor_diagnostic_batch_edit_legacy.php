<?php
require_once dirname(__DIR__, 2) . '/auth.php';
requireRole('professor_admin');
require_once dirname(__DIR__) . '/includes/diagnostic_schema.php';
require_once dirname(__DIR__) . '/includes/diagnostic_exam_helpers.php';
require_once dirname(__DIR__, 2) . '/includes/quiz_helpers.php';
require_once dirname(__DIR__) . '/includes/examination_questions.php';
require_once dirname(__DIR__) . '/includes/college_sections.php';
require_once dirname(__DIR__) . '/includes/examination_assignment.php';

$pageTitle = 'Edit diagnostic batch';
$uid = (int)getCurrentUserId();
$batchId = sanitizeInt($_GET['id'] ?? 0);
$csrf = generateCSRFToken();
$error = null;

$catalog = diagnostic_exam_load_subject_catalog($conn);
college_sections_ensure_schema($conn);
$suggestedSections = college_sections_active_names($conn);
$batch = ($batchId > 0) ? diagnostic_exam_load_batch($conn, $batchId, $uid) : null;
if ($batchId > 0 && !$batch) {
    $_SESSION['error'] = 'Batch not found.';
    header('Location: professor_diagnostic_batches');
    exit;
}

$sections = $batch ? diagnostic_exam_load_batch_sections($conn, $batchId) : [];
$batchSubjects = $batch ? diagnostic_exam_load_batch_subjects($conn, $batchId) : [];
$questionsGrouped = $batch ? diagnostic_exam_load_questions_grouped($conn, $batchId) : [];

$selectedSubjectIds = array_map(static fn($r) => (int)($r['subject_id'] ?? 0), $batchSubjects);
$questionsRequiredMap = [];
foreach ($batchSubjects as $bs) {
    $questionsRequiredMap[(int)($bs['subject_id'] ?? 0)] = (int)($bs['questions_required'] ?? 0);
}

function diagnostic_batch_time_from_post(array $post): int
{
    $h = max(0, sanitizeInt($post['time_limit_hours'] ?? 0));
    $m = max(0, min(59, sanitizeInt($post['time_limit_minutes'] ?? 0)));
    return min(999 * 3600 + 59 * 60, $h * 3600 + $m * 60);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request.';
    } else {
        $title = trim((string)($_POST['title'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $timeLimit = diagnostic_batch_time_from_post($_POST);
        if ($timeLimit < 60) {
            $timeLimit = 3600;
        }
        $availRaw = trim((string)($_POST['available_from'] ?? ''));
        $deadRaw = trim((string)($_POST['deadline'] ?? ''));
        $availSql = ($availRaw !== '') ? date('Y-m-d H:i:s', strtotime($availRaw)) : null;
        $deadSql = ($deadRaw !== '') ? date('Y-m-d H:i:s', strtotime($deadRaw)) : null;
        $isPublished = !empty($_POST['is_published']) ? 1 : 0;
        $shuffleQ = !empty($_POST['shuffle_questions']) ? 1 : 0;
        $shuffleC = !empty($_POST['shuffle_choices']) ? 1 : 0;

        $sectionInputs = $_POST['sections'] ?? [];
        $sectionsClean = examination_parse_sections_from_post($_POST);

        $subjectIds = [];
        $rawSubs = $_POST['subject_ids'] ?? [];
        if (is_array($rawSubs)) {
            foreach ($rawSubs as $sid) {
                $iv = (int)$sid;
                if ($iv > 0) {
                    $subjectIds[] = $iv;
                }
            }
        }
        $subjectIds = array_values(array_unique($subjectIds));

        if ($title === '') {
            $error = 'Title is required.';
        } elseif ($sectionsClean === []) {
            $error = 'Add at least one section.';
        } elseif ($subjectIds === []) {
            $error = 'Select at least one subject.';
        } elseif ($batchId > 0 && examination_questions_mutations_locked($conn, 'diagnostic', (int)$batchId)) {
            $error = 'Questions are locked because this examination already has student attempts. Destructive replace is blocked. Use the Examination wizard Questions step.';
        } else {
            if ($batchId <= 0) {
                $ins = mysqli_prepare($conn, 'INSERT INTO diagnostic_batches (title, description, time_limit_seconds, available_from, deadline, is_published, shuffle_questions, shuffle_choices, created_by) VALUES (?,?,?,?,?,?,?,?,?)');
                mysqli_stmt_bind_param($ins, 'ssissiiii', $title, $description, $timeLimit, $availSql, $deadSql, $isPublished, $shuffleQ, $shuffleC, $uid);
                mysqli_stmt_execute($ins);
                $batchId = (int)mysqli_insert_id($conn);
                mysqli_stmt_close($ins);
            } else {
                $upd = mysqli_prepare($conn, 'UPDATE diagnostic_batches SET title=?, description=?, time_limit_seconds=?, available_from=?, deadline=?, is_published=?, shuffle_questions=?, shuffle_choices=? WHERE batch_id=? AND created_by=?');
                mysqli_stmt_bind_param($upd, 'ssissiiiii', $title, $description, $timeLimit, $availSql, $deadSql, $isPublished, $shuffleQ, $shuffleC, $batchId, $uid);
                mysqli_stmt_execute($upd);
                mysqli_stmt_close($upd);
            }

            @mysqli_query($conn, 'DELETE FROM diagnostic_batch_sections WHERE batch_id=' . (int)$batchId);
            @mysqli_query($conn, 'DELETE FROM diagnostic_batch_subjects WHERE batch_id=' . (int)$batchId);
            @mysqli_query($conn, 'DELETE FROM diagnostic_questions WHERE batch_id=' . (int)$batchId);

            foreach ($sectionsClean as $sec) {
                $st = mysqli_prepare($conn, 'INSERT INTO diagnostic_batch_sections (batch_id, section_value) VALUES (?,?)');
                mysqli_stmt_bind_param($st, 'is', $batchId, $sec);
                mysqli_stmt_execute($st);
                mysqli_stmt_close($st);
            }

            $reqMap = is_array($_POST['questions_required'] ?? null) ? $_POST['questions_required'] : [];
            $sort = 0;
            foreach ($subjectIds as $sid) {
                $sort++;
                $req = max(0, (int)($reqMap[$sid] ?? $reqMap[(string)$sid] ?? 0));
                $st = mysqli_prepare($conn, 'INSERT INTO diagnostic_batch_subjects (batch_id, subject_id, sort_order, questions_required) VALUES (?,?,?,?)');
                mysqli_stmt_bind_param($st, 'iiii', $batchId, $sid, $sort, $req);
                mysqli_stmt_execute($st);
                mysqli_stmt_close($st);

                $texts = $_POST['q_text'][$sid] ?? $_POST['q_text'][(string)$sid] ?? [];
                $ca = $_POST['q_ca'][$sid] ?? $_POST['q_ca'][(string)$sid] ?? [];
                $cb = $_POST['q_cb'][$sid] ?? $_POST['q_cb'][(string)$sid] ?? [];
                $cc = $_POST['q_cc'][$sid] ?? $_POST['q_cc'][(string)$sid] ?? [];
                $cd = $_POST['q_cd'][$sid] ?? $_POST['q_cd'][(string)$sid] ?? [];
                $corr = $_POST['q_corr'][$sid] ?? $_POST['q_corr'][(string)$sid] ?? [];
                if (!is_array($texts)) {
                    continue;
                }
                $qSort = 0;
                foreach ($texts as $qi => $qtRaw) {
                    $qt = sanitizeQuizRichHtmlForStorage(trim((string)$qtRaw));
                    if ($qt === '') {
                        continue;
                    }
                    $qSort++;
                    $cA = trim((string)($ca[$qi] ?? ''));
                    $cB = trim((string)($cb[$qi] ?? ''));
                    $cC = trim((string)($cc[$qi] ?? ''));
                    $cD = trim((string)($cd[$qi] ?? ''));
                    $cor = strtoupper(trim((string)($corr[$qi] ?? 'A')));
                    if (!preg_match('/^[A-D]$/', $cor)) {
                        $cor = 'A';
                    }
                    $insQ = mysqli_prepare($conn, 'INSERT INTO diagnostic_questions (batch_id, subject_id, question_text, question_type, choice_a, choice_b, choice_c, choice_d, correct_answer, sort_order) VALUES (?,?,?,?,?,?,?,?,?,?)');
                    $qtype = 'mcq';
                    mysqli_stmt_bind_param($insQ, 'iisssssssi', $batchId, $sid, $qt, $qtype, $cA, $cB, $cC, $cD, $cor, $qSort);
                    mysqli_stmt_execute($insQ);
                    mysqli_stmt_close($insQ);
                }
            }

            $_SESSION['message'] = 'Diagnostic batch saved.';
            header('Location: professor_diagnostic_batch_edit?id=' . (int)$batchId);
            exit;
        }
    }
}

if ($batch) {
    $sections = diagnostic_exam_load_batch_sections($conn, $batchId);
    $batchSubjects = diagnostic_exam_load_batch_subjects($conn, $batchId);
    $questionsGrouped = diagnostic_exam_load_questions_grouped($conn, $batchId);
    $selectedSubjectIds = array_map(static fn($r) => (int)($r['subject_id'] ?? 0), $batchSubjects);
    $questionsRequiredMap = [];
    foreach ($batchSubjects as $bs) {
        $questionsRequiredMap[(int)($bs['subject_id'] ?? 0)] = (int)($bs['questions_required'] ?? 0);
    }
}

$tlSec = (int)($batch['time_limit_seconds'] ?? 3600);
$tlH = intdiv($tlSec, 3600);
$tlM = intdiv($tlSec % 3600, 60);

$sectionSelectOptionsHtml = '<option value="">Select section</option>';
foreach ($suggestedSections as $sg) {
    $sg = trim((string) $sg);
    if ($sg === '') {
        continue;
    }
    $sectionSelectOptionsHtml .= '<option value="' . h($sg) . '">' . h($sg) . '</option>';
}
$adminHeroIcon = 'clipboard2-pulse';
$adminHeroTitle = $batch ? 'Edit diagnostic batch' : 'New diagnostic batch';
$adminHeroSubtitle = 'Configure subjects, sections, and questions for one multi-subject diagnostic.';
$adminHeroActions = '<a href="professor_diagnostic_batches" class="admin-btn admin-btn--ghost"><i class="bi bi-arrow-left"></i> Back to list</a>';
if ($batchId > 0) {
    $adminHeroActions .= ' <a href="professor_diagnostic_monitor?batch_id=' . (int) $batchId . '" class="admin-btn admin-btn--primary"><i class="bi bi-eye"></i> Monitor</a>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once dirname(__DIR__) . '/includes/examination_head_app.php'; ?>
  <style>
    .panel { background: var(--admin-surface, #fff); border: 1px solid var(--admin-border, #e2e8f0); border-radius: .75rem; padding: 1rem; margin-bottom: 1rem; }
    .subject-tab { border: 1px solid var(--admin-border, #e2e8f0); background: var(--admin-surface-muted, #f8fafc); border-radius: .5rem; padding: .45rem .75rem; font-weight: 700; cursor: pointer; }
    .subject-tab.is-active { background: var(--admin-primary, #166534); color: #fff; border-color: var(--admin-primary, #166534); }
    .q-block { border: 1px solid var(--admin-border, #e2e8f0); border-radius: .65rem; padding: .85rem; margin-bottom: .75rem; background: var(--admin-surface-muted, #fafafa); }
    .section-row { display: flex; gap: .5rem; margin-bottom: .5rem; align-items: center; }
    .section-row select { flex: 1; }
  </style>
</head>
<body class="font-sans antialiased admin-app admin-students-page examination-admin-page">
<?php include __DIR__ . '/professor_admin_sidebar.php'; ?>
<?php include dirname(__DIR__, 2) . '/includes/components/admin_page_hero.php'; ?>

<?php if ($error): ?><div class="admin-flash admin-flash--error mb-3 p-3 rounded-xl flex items-center gap-2"><i class="bi bi-exclamation-triangle-fill"></i><span><?php echo h($error); ?></span></div><?php endif; ?>

<main class="examination-page-shell pb-10">
  <form method="post" id="batchForm">
    <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">

    <div class="panel">
      <h2 class="text-lg font-bold text-green-900 mt-0">Batch details</h2>
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <label class="block">Title<input class="w-full mt-1 border rounded-lg px-3 py-2" name="title" required value="<?php echo h((string)($batch['title'] ?? '')); ?>"></label>
        <label class="block">Time limit (hours / minutes)
          <div class="flex gap-2 mt-1">
            <input class="w-24 border rounded-lg px-3 py-2" type="number" min="0" max="999" name="time_limit_hours" value="<?php echo (int)$tlH; ?>">
            <input class="w-24 border rounded-lg px-3 py-2" type="number" min="0" max="59" name="time_limit_minutes" value="<?php echo (int)$tlM; ?>">
          </div>
        </label>
        <label class="block md:col-span-2">Description<textarea class="w-full mt-1 border rounded-lg px-3 py-2" name="description" rows="2"><?php echo h((string)($batch['description'] ?? '')); ?></textarea></label>
        <label class="block">Available from<input class="w-full mt-1 border rounded-lg px-3 py-2" type="datetime-local" name="available_from" value="<?php echo !empty($batch['available_from']) ? h(date('Y-m-d\TH:i', strtotime((string)$batch['available_from']))) : ''; ?>"></label>
        <label class="block">Deadline<input class="w-full mt-1 border rounded-lg px-3 py-2" type="datetime-local" name="deadline" value="<?php echo !empty($batch['deadline']) ? h(date('Y-m-d\TH:i', strtotime((string)$batch['deadline']))) : ''; ?>"></label>
      </div>
      <div class="flex flex-wrap gap-4 mt-3 text-sm">
        <label class="inline-flex items-center gap-2"><input type="checkbox" name="is_published" value="1" <?php echo !empty($batch['is_published']) ? 'checked' : ''; ?>> Publish</label>
        <label class="inline-flex items-center gap-2"><input type="checkbox" name="shuffle_questions" value="1" <?php echo !empty($batch['shuffle_questions']) ? 'checked' : ''; ?>> Shuffle questions</label>
        <label class="inline-flex items-center gap-2"><input type="checkbox" name="shuffle_choices" value="1" <?php echo !empty($batch['shuffle_choices']) ? 'checked' : ''; ?>> Shuffle choices</label>
      </div>
    </div>

    <div class="panel">
      <h2 class="text-lg font-bold mt-0">Sections (multi-select)</h2>
      <p class="text-sm opacity-70">Choose from centralized <a class="underline font-semibold" href="professor_college_sections">Sections</a>. Students match by <code>users.section</code>.</p>
      <?php if ($suggestedSections === []): ?>
        <p class="text-sm text-amber-700 mb-2">No active sections yet. Create sections first.</p>
      <?php endif; ?>
      <div id="sectionsWrap" class="mt-3">
        <?php if ($sections === []): $sections = ['']; endif; ?>
        <?php foreach ($sections as $sec): ?>
        <div class="section-row">
          <select class="flex-1 border rounded-lg px-3 py-2" name="sections[]">
            <option value="">Select section</option>
            <?php foreach ($suggestedSections as $sg): ?>
              <?php $sg = trim((string) $sg); if ($sg === '') continue; ?>
              <option value="<?php echo h($sg); ?>" <?php echo trim((string) $sec) === $sg ? 'selected' : ''; ?>><?php echo h($sg); ?></option>
            <?php endforeach; ?>
            <?php
              $secTrim = trim((string) $sec);
              if ($secTrim !== '' && !in_array($secTrim, $suggestedSections, true)):
            ?>
              <option value="<?php echo h($secTrim); ?>" selected><?php echo h($secTrim); ?> (legacy)</option>
            <?php endif; ?>
          </select>
          <button type="button" class="admin-btn admin-btn--ghost admin-btn--sm remove-section">Remove</button>
        </div>
        <?php endforeach; ?>
      </div>
      <button type="button" id="addSectionBtn" class="mt-2 admin-btn admin-btn--ghost admin-btn--sm">+ Add section</button>
    </div>

    <div class="panel">
      <h2 class="text-lg font-bold text-green-900 mt-0">Subjects</h2>
      <p class="text-sm text-gray-600">Select multiple subjects and set how many questions students answer per subject (0 = all authored).</p>
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 mt-3">
        <?php foreach ($catalog as $cat): $sid = (int)($cat['subject_id'] ?? 0); $checked = in_array($sid, $selectedSubjectIds, true); ?>
        <label class="border rounded-lg p-3 bg-white flex flex-col gap-2">
          <span class="inline-flex items-center gap-2 font-bold text-green-900">
            <input type="checkbox" name="subject_ids[]" value="<?php echo $sid; ?>" class="subject-check" data-subject-id="<?php echo $sid; ?>" <?php echo $checked ? 'checked' : ''; ?>>
            <?php echo h((string)($cat['subject_code'] ?? '')); ?>
          </span>
          <span class="text-xs text-gray-600"><?php echo h((string)($cat['subject_name'] ?? '')); ?></span>
          <label class="text-xs">Questions to use
            <input type="number" min="0" class="w-full mt-1 border rounded px-2 py-1" name="questions_required[<?php echo $sid; ?>]" value="<?php echo (int)($questionsRequiredMap[$sid] ?? 0); ?>">
          </label>
        </label>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="panel">
      <h2 class="text-lg font-bold text-green-900 mt-0">Questions by subject</h2>
      <div id="subjectTabs" class="flex flex-wrap gap-2 mb-4"></div>
      <div id="subjectPanels"></div>
      <p class="text-xs text-gray-500 m-0">Select subjects above, then add questions under each subject tab.</p>
    </div>

    <button type="submit" class="px-6 py-3 rounded-lg bg-green-700 text-white font-bold">Save diagnostic batch</button>
  </form>
</main>
<script>
(function(){
  var catalog = <?php echo json_encode($catalog, JSON_UNESCAPED_UNICODE); ?>;
  var grouped = <?php echo json_encode($questionsGrouped, JSON_UNESCAPED_UNICODE); ?>;
  var tabsEl = document.getElementById('subjectTabs');
  var panelsEl = document.getElementById('subjectPanels');
  var activeSid = null;

  function esc(s){ var d=document.createElement('div'); d.textContent=s||''; return d.innerHTML; }

  function qTemplate(sid, q, idx){
    q = q || {};
    return '<div class="q-block" data-q-index="'+idx+'">'+
      '<div class="flex justify-between mb-2"><strong>Question '+(idx+1)+'</strong><button type="button" class="text-red-700 text-sm remove-q">Remove</button></div>'+
      '<textarea class="w-full border rounded-lg px-3 py-2 mb-2" name="q_text['+sid+'][]" rows="2" placeholder="Question stem">'+esc(q.question_text||'')+'</textarea>'+
      '<div class="grid grid-cols-1 md:grid-cols-2 gap-2">'+
      ['A','B','C','D'].map(function(L,i){ var k='choice_'+L.toLowerCase(); return '<input class="border rounded px-2 py-1" name="q_c'+L.toLowerCase()+['a','b','c','d'][i]+'['+sid+'][]" placeholder="Choice '+L+'" value="'+esc(q[k]||'')+'">'; }).join('')+
      '</div>'+
      '<label class="block mt-2 text-sm">Correct <select class="border rounded px-2 py-1" name="q_corr['+sid+'][]"><option value="A"'+(q.correct_answer==='A'?' selected':'')+'>A</option><option value="B"'+(q.correct_answer==='B'?' selected':'')+'>B</option><option value="C"'+(q.correct_answer==='C'?' selected':'')+'>C</option><option value="D"'+(q.correct_answer==='D'?' selected':'')+'>D</option></select></label>'+
    '</div>';
  }

  function renderPanels(){
    var checked = Array.prototype.slice.call(document.querySelectorAll('.subject-check:checked'));
    tabsEl.innerHTML = '';
    panelsEl.innerHTML = '';
    if (!checked.length) return;
    checked.forEach(function(cb, i){
      var sid = cb.getAttribute('data-subject-id');
      var meta = catalog.find(function(c){ return String(c.subject_id)===String(sid); }) || {subject_code:'Subject'};
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'subject-tab'+(activeSid===sid || (!activeSid && i===0) ? ' is-active':'');
      btn.textContent = meta.subject_code;
      btn.setAttribute('data-sid', sid);
      btn.addEventListener('click', function(){ activeSid=sid; renderPanels(); });
      tabsEl.appendChild(btn);

      var panel = document.createElement('div');
      panel.className = 'subject-panel'+(activeSid===sid || (!activeSid && i===0) ? '' : ' hidden');
      panel.setAttribute('data-panel-sid', sid);
      if (activeSid===sid || (!activeSid && i===0)) { if(!activeSid) activeSid=sid; }
      panel.style.display = (activeSid===sid) ? 'block' : 'none';
      var qs = grouped[sid] || grouped[parseInt(sid,10)] || [];
      if (!qs.length) qs = [{}];
      panel.innerHTML = '<h3 class="text-base font-bold text-green-800 mt-0">'+esc(meta.subject_code)+' — '+esc(meta.subject_name)+'</h3>'+
        '<div class="questions-wrap" data-sid="'+sid+'">'+qs.map(function(q,idx){ return qTemplate(sid,q,idx); }).join('')+'</div>'+
        '<button type="button" class="add-q mt-2 px-3 py-2 rounded-lg border border-green-200 text-green-800 font-semibold" data-sid="'+sid+'">+ Add question</button>';
      panelsEl.appendChild(panel);
    });
    bindPanelEvents();
  }

  function bindPanelEvents(){
    panelsEl.querySelectorAll('.add-q').forEach(function(btn){
      btn.onclick = function(){
        var sid = btn.getAttribute('data-sid');
        var wrap = panelsEl.querySelector('.questions-wrap[data-sid="'+sid+'"]');
        var idx = wrap.querySelectorAll('.q-block').length;
        wrap.insertAdjacentHTML('beforeend', qTemplate(sid, {}, idx));
        bindPanelEvents();
      };
    });
    panelsEl.querySelectorAll('.remove-q').forEach(function(btn){
      btn.onclick = function(){ btn.closest('.q-block').remove(); };
    });
  }

  document.querySelectorAll('.subject-check').forEach(function(cb){ cb.addEventListener('change', function(){ activeSid=null; renderPanels(); }); });
  renderPanels();

  document.getElementById('addSectionBtn').addEventListener('click', function(){
    var sectionOptionsHtml = <?php echo json_encode($sectionSelectOptionsHtml, JSON_UNESCAPED_UNICODE); ?>;
    var row = document.createElement('div');
    row.className = 'section-row';
    row.innerHTML = '<select class="flex-1 border rounded-lg px-3 py-2" name="sections[]">' + sectionOptionsHtml + '</select><button type="button" class="admin-btn admin-btn--ghost admin-btn--sm remove-section">Remove</button>';
    document.getElementById('sectionsWrap').appendChild(row);
    row.querySelector('.remove-section').onclick = function(){ row.remove(); };
  });
  document.querySelectorAll('.remove-section').forEach(function(btn){
    btn.onclick = function(){ btn.closest('.section-row').remove(); };
  });
})();
</script>
</body>
</html>
