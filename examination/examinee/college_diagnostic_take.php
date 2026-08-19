<?php
require_once dirname(__DIR__, 2) . '/auth.php';
require_once dirname(__DIR__, 2) . '/includes/platform_access.php';
ereview_require_college_examination_portal();
require_once dirname(__DIR__) . '/includes/diagnostic_schema.php';
require_once dirname(__DIR__) . '/includes/diagnostic_exam_helpers.php';
require_once dirname(__DIR__) . '/includes/examination_eligibility.php';
require_once dirname(__DIR__, 2) . '/includes/quiz_helpers.php';

$pageTitle = 'Diagnostic exam';
$uid = (int)getCurrentUserId();
$batchId = sanitizeInt($_GET['batch_id'] ?? 0);
$reviewParam = $_GET['review'] ?? null;
$reviewMode = $reviewParam !== null && $reviewParam !== '' && !in_array(strtolower((string)$reviewParam), ['0', 'false', 'no'], true);
$csrf = generateCSRFToken();
$now = date('Y-m-d H:i:s');

if ($batchId <= 0) {
    header('Location: college_exams');
    exit;
}

$batch = diagnostic_exam_load_batch($conn, $batchId);
diagnostic_exam_finalize_expired_in_progress($conn, $batchId, $uid);
$attempt = diagnostic_exam_load_attempt($conn, $batchId, $uid);

if (!$batch || !examination_user_can_view_exam($conn, $uid, $batch, 'diagnostic', $attempt ?: null)) {
    $_SESSION['error'] = 'Diagnostic exam not available.';
    header('Location: college_exams');
    exit;
}
$attemptStatus = diagnostic_exam_attempt_status_normalized($attempt);
$attemptSubmitted = diagnostic_exam_attempt_is_submitted($attempt);

$profName = 'Professor';
$creatorId = (int)($batch['created_by'] ?? 0);
if ($creatorId > 0) {
    $pst = mysqli_prepare($conn, "SELECT full_name FROM users WHERE user_id=? LIMIT 1");
    if ($pst) {
        mysqli_stmt_bind_param($pst, 'i', $creatorId);
        mysqli_stmt_execute($pst);
        $pres = mysqli_stmt_get_result($pst);
        $prow = $pres ? mysqli_fetch_assoc($pres) : null;
        if ($prow && !empty($prow['full_name'])) {
            $profName = (string)$prow['full_name'];
        }
        mysqli_stmt_close($pst);
    }
}

$batchSubjects = diagnostic_exam_load_batch_subjects($conn, $batchId);
$stats = diagnostic_exam_batch_stats_for_student($conn, $batchId);
$attemptIdForQuestions = ($attempt && $attemptStatus === 'in_progress') ? (int)$attempt['attempt_id'] : null;
$questions = diagnostic_exam_build_flat_questions($conn, $batchId, $batchSubjects, $attemptIdForQuestions);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['start_diagnostic'])) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid request.';
        header('Location: college_diagnostic_take?batch_id=' . $batchId);
        exit;
    }
    if ($attemptSubmitted) {
        header('Location: college_diagnostic_take?batch_id=' . $batchId . '&review=1');
        exit;
    }
    if (!diagnostic_exam_user_can_start_batch($conn, $uid, $batch, $now)) {
        $_SESSION['error'] = 'This diagnostic examination is not available to you at this time.';
        header('Location: college_exams');
        exit;
    }
    if ($questions === []) {
        $_SESSION['error'] = 'This diagnostic has no questions yet.';
        header('Location: college_exams');
        exit;
    }
    $started = date('Y-m-d H:i:s');
    $expiresAt = diagnostic_exam_compute_expires_at((int)$batch['time_limit_seconds'], $batch['deadline'] ?? null);
    if (!$attempt) {
        $ins = mysqli_prepare($conn, "INSERT INTO diagnostic_attempts (batch_id, user_id, status, started_at, expires_at, last_seen_at, ui_state_json) VALUES (?, ?, 'in_progress', ?, ?, ?, ?)");
        $emptyState = '{"current_index":0,"flags":[],"updated_at":0}';
        mysqli_stmt_bind_param($ins, 'iissss', $batchId, $uid, $started, $expiresAt, $started, $emptyState);
        mysqli_stmt_execute($ins);
        mysqli_stmt_close($ins);
    } elseif ($attemptStatus === 'expired') {
        $_SESSION['error'] = 'Your previous attempt expired.';
        header('Location: college_diagnostic_take?batch_id=' . $batchId . '&review=1');
        exit;
    }
    header('Location: college_diagnostic_take?batch_id=' . $batchId);
    exit;
}

if ($attempt && $attemptSubmitted && !$reviewMode) {
    header('Location: college_diagnostic_take?batch_id=' . $batchId . '&review=1');
    exit;
}

$attempt = diagnostic_exam_load_attempt($conn, $batchId, $uid);
$attemptStatus = diagnostic_exam_attempt_status_normalized($attempt);
$attemptSubmitted = diagnostic_exam_attempt_is_submitted($attempt);
$attemptIdForQuestions = ($attempt && $attemptStatus === 'in_progress') ? (int)$attempt['attempt_id'] : null;
$questions = diagnostic_exam_build_flat_questions($conn, $batchId, $batchSubjects, $attemptIdForQuestions);

$answersMap = [];
if ($attempt) {
    $ar = mysqli_query($conn, 'SELECT question_id, selected_answer, is_correct FROM diagnostic_answers WHERE attempt_id=' . (int)$attempt['attempt_id']);
    if ($ar) {
        while ($r = mysqli_fetch_assoc($ar)) {
            $answersMap[(int)$r['question_id']] = $r;
        }
        mysqli_free_result($ar);
    }
}

$showIntro = !$attempt || ($attemptStatus !== 'in_progress' && !$attemptSubmitted);
$remainingSeconds = null;
if ($attempt && $attemptStatus === 'in_progress' && !empty($attempt['expires_at'])) {
    $remainingSeconds = max(0, strtotime((string)$attempt['expires_at']) - time());
}

$savedUiState = null;
if ($attempt && !empty($attempt['ui_state_json'])) {
    $tmp = json_decode((string)$attempt['ui_state_json'], true);
    if (is_array($tmp)) {
        $savedUiState = $tmp;
    }
}

$breakdown = [];
if ($attemptSubmitted && !empty($attempt['subject_breakdown_json'])) {
    $decoded = json_decode((string)$attempt['subject_breakdown_json'], true);
    if (is_array($decoded)) {
        $breakdown = $decoded;
    }
}

$subjectNav = [];
$subjectCounts = [];
foreach ($questions as $idx => $q) {
    $sid = (int)($q['subject_id'] ?? 0);
    if (!isset($subjectCounts[$sid])) {
        $subjectCounts[$sid] = 0;
        $subjectNav[$sid] = [
            'subject_id' => $sid,
            'subject_code' => (string)($q['_subject_code'] ?? ''),
            'subject_name' => (string)($q['_subject_name'] ?? ''),
            'start_index' => $idx,
            'count' => 0,
        ];
    }
    $subjectCounts[$sid]++;
    $subjectNav[$sid]['count'] = $subjectCounts[$sid];
}
$subjectNavList = array_values($subjectNav);

$questionsForJs = [];
foreach ($questions as $q) {
    $questionsForJs[] = [
        'question_id' => (int)($q['question_id'] ?? 0),
        'subject_id' => (int)($q['subject_id'] ?? 0),
        'subject_code' => (string)($q['_subject_code'] ?? ''),
        'question_text' => renderQuizRichText((string)($q['question_text'] ?? '')),
        'choice_a' => renderQuizRichText((string)($q['choice_a'] ?? '')),
        'choice_b' => renderQuizRichText((string)($q['choice_b'] ?? '')),
        'choice_c' => renderQuizRichText((string)($q['choice_c'] ?? '')),
        'choice_d' => renderQuizRichText((string)($q['choice_d'] ?? '')),
        'correct_answer' => $reviewMode && $attemptSubmitted ? (string)($q['correct_answer'] ?? '') : null,
    ];
}

$initialAnswers = [];
foreach ($questions as $q) {
    $qid = (int)($q['question_id'] ?? 0);
    if (!empty($answersMap[$qid]['selected_answer'])) {
        $initialAnswers[$qid] = strtoupper((string)$answersMap[$qid]['selected_answer']);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once dirname(__DIR__) . '/includes/examination_head_app.php'; ?>
  <?php
    $__examTakeCss = dirname(__DIR__, 2) . '/assets/css/exam-take-shared.css';
    $__examTakeBase = rtrim(dirname((string)($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    $__examTakeHrefBase = preg_replace('#/examination/examinee$#', '', $__examTakeBase);
    if (!is_string($__examTakeHrefBase) || $__examTakeHrefBase === '') {
        $__examTakeHrefBase = $__examTakeBase;
    }
    if (is_file($__examTakeCss)) {
        echo '<link rel="stylesheet" href="' . h($__examTakeHrefBase) . '/assets/css/exam-take-shared.css?v=' . filemtime($__examTakeCss) . '">' . "\n";
    }
  ?>
</head>
<body class="font-sans antialiased">
<?php include __DIR__ . '/college_student_sidebar.php'; ?>
<main class="dashboard-shell cp-exam-shell pt-2">
  <?php
    $cpPageBackHref = 'college_exams';
    $cpPageBackLabel = 'Back to exams';
    $cpPageEyebrow = 'Diagnostic assessment';
    $cpPageTitle = (string)($batch['title'] ?? 'Diagnostic examination');
    $cpPageSubtitle = (int)$stats['subject_count'] . ' subjects · ' . (int)$stats['question_count'] . ' questions · ' . diagnostic_exam_human_duration((int)($batch['time_limit_seconds'] ?? 0));
    $cpPageIcon = 'bi-clipboard2-pulse';
    $cpPageActionHtml = '<span class="cp-type cp-type--diagnostic">Diagnostic</span>';
    require dirname(__DIR__, 2) . '/includes/components/college_portal_page_header.php';
  ?>

  <?php if ($showIntro && !$reviewMode): ?>
  <?php
    $diagOpensTs = !empty($batch['available_from']) ? strtotime((string)$batch['available_from']) : false;
    $diagOpens = $diagOpensTs !== false
        ? date('M j, Y', $diagOpensTs) . ' · ' . date('g:i A', $diagOpensTs)
        : 'Immediate';
    $diagClosesTs = !empty($batch['deadline']) ? strtotime((string)$batch['deadline']) : false;
    $diagCloses = $diagClosesTs !== false
        ? date('M j, Y', $diagClosesTs) . ' · ' . date('g:i A', $diagClosesTs)
        : 'No closing time';
    $diagDuration = diagnostic_exam_human_duration((int)($batch['time_limit_seconds'] ?? 0));
  ?>
  <div class="diag-card p-5 max-w-3xl">
    <h2 class="text-lg font-bold text-[#143D59] mt-0 mb-2">Before you begin</h2>
    <?php if (!empty($batch['description'])): ?><p class="text-sm text-gray-600 m-0 mb-3"><?php echo nl2br(h((string)$batch['description'])); ?></p><?php endif; ?>
    <div class="grid grid-cols-2 gap-3 my-3">
      <div class="intro-meta"><div class="text-xs text-gray-500 font-bold uppercase">Exam type</div><div class="font-bold text-[#143D59]">Diagnostic exam</div></div>
      <div class="intro-meta"><div class="text-xs text-gray-500 font-bold uppercase">Opens</div><div class="font-bold text-[#143D59]"><?php echo h($diagOpens); ?></div></div>
      <div class="intro-meta"><div class="text-xs text-gray-500 font-bold uppercase">Closes</div><div class="font-bold text-[#143D59]"><?php echo h($diagCloses); ?></div></div>
      <div class="intro-meta"><div class="text-xs text-gray-500 font-bold uppercase">Duration</div><div class="font-bold text-[#143D59]"><?php echo h($diagDuration); ?></div></div>
      <div class="intro-meta"><div class="text-xs text-gray-500 font-bold uppercase">Questions</div><div class="font-bold text-[#143D59]"><?php echo (int)$stats['question_count']; ?></div></div>
      <div class="intro-meta"><div class="text-xs text-gray-500 font-bold uppercase">Professor</div><div class="font-bold text-[#143D59]"><?php echo h($profName); ?></div></div>
    </div>
    <p class="text-sm text-gray-600">This is one diagnostic attempt covering all subjects. You can save answers and continue until you submit or time runs out.</p>
    <form method="post" class="mt-4">
      <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
      <button type="submit" name="start_diagnostic" value="1" class="cp-btn cp-btn--primary start-btn"><i class="bi bi-play-fill"></i> Start diagnostic</button>
    </form>
  </div>

  <?php elseif ($reviewMode && $attemptSubmitted): ?>
  <?php
    $dCorrect = (int)($attempt['correct_count'] ?? 0);
    $dTotal = (int)($attempt['total_count'] ?? 0);
    $dScore = (float)($attempt['score'] ?? 0);
  ?>
  <div class="cer-results" style="max-width:720px">
    <div class="cer-summary-grid" style="grid-template-columns:repeat(2,minmax(0,1fr))">
      <div class="cer-summary-card">
        <div class="cer-summary-k">Score</div>
        <div class="cer-summary-v cer-summary-v--score"><?php echo h(number_format($dScore, 1)); ?>%</div>
        <div class="cer-summary-sub"><?php echo $dCorrect; ?> / <?php echo $dTotal; ?> correct</div>
        <div class="cer-summary-note">Diagnostic score uses raw accuracy (correct ÷ total).</div>
      </div>
      <div class="cer-summary-card">
        <div class="cer-summary-k">Type</div>
        <div class="cer-summary-v" style="font-size:1.05rem"><span class="cp-type cp-type--diagnostic">Diagnostic</span></div>
        <div class="cer-summary-sub">Subject-level breakdown below</div>
      </div>
    </div>
    <?php if ($breakdown !== []): ?>
    <div class="cer-details">
      <h3 class="cer-details__title">Subject breakdown</h3>
      <div class="space-y-2">
      <?php foreach ($breakdown as $item): ?>
        <div class="breakdown-row flex justify-between gap-3 text-sm">
          <span class="font-semibold text-[#143D59]"><?php echo h((string)($item['subject_code'] ?? '')); ?></span>
          <span class="text-slate-600"><?php echo (int)($item['correct'] ?? 0); ?>/<?php echo (int)($item['total'] ?? 0); ?> · <?php echo h(number_format((float)($item['score_pct'] ?? 0), 1)); ?>%</span>
        </div>
      <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
    <a href="college_exams" class="cer-back"><i class="bi bi-arrow-left"></i> Back to exams</a>
  </div>

  <?php elseif ($attempt && $attemptStatus === 'in_progress'): ?>
  <div class="exam-toolbar flex flex-wrap items-center justify-between gap-3">
    <div>
      <div id="subjectLabel" class="font-bold text-[#143D59]">—</div>
      <div id="progressLabel" class="text-xs text-gray-500">—</div>
    </div>
    <div class="flex items-center gap-2">
      <span id="timerBadge" class="time-badge">—</span>
      <button type="button" id="submitBtn" class="action-btn action-next">Submit diagnostic</button>
    </div>
  </div>

  <div class="exam-grid">
    <div class="diag-card p-5">
      <div id="questionText" class="text-base leading-relaxed mb-4"></div>
      <div id="choicesWrap"></div>
      <div class="flex justify-between mt-4">
        <button type="button" id="prevBtn" class="action-btn action-prev">Previous</button>
        <button type="button" id="nextBtn" class="action-btn action-next">Next</button>
      </div>
    </div>
    <aside class="diag-card p-4">
      <h3 class="text-sm font-bold text-[#143D59] mt-0">Subjects</h3>
      <div id="subjectNav" class="flex flex-wrap gap-1 mb-3"></div>
      <h3 class="text-sm font-bold text-[#143D59]">Progress</h3>
      <div id="questionGrid" class="grid grid-cols-6 gap-1 mt-2"></div>
    </aside>
  </div>

  <div id="submitModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
    <div class="bg-white rounded-xl p-5 max-w-md w-full">
      <h3 class="font-bold text-lg m-0">Submit diagnostic?</h3>
      <p id="submitSummary" class="text-sm text-gray-600 mt-2"></p>
      <div class="flex justify-end gap-2 mt-4">
        <button type="button" id="cancelSubmit" class="action-btn action-prev">Cancel</button>
        <button type="button" id="confirmSubmit" class="action-btn action-next">Submit now</button>
      </div>
    </div>
  </div>

  <script>
  (function(){
    var questions = <?php echo json_encode($questionsForJs, JSON_UNESCAPED_UNICODE); ?>;
    var subjectNav = <?php echo json_encode($subjectNavList, JSON_UNESCAPED_UNICODE); ?>;
    var answers = <?php echo json_encode($initialAnswers, JSON_UNESCAPED_UNICODE); ?>;
    var attemptId = <?php echo (int)($attempt['attempt_id'] ?? 0); ?>;
    var csrf = <?php echo json_encode($csrf); ?>;
    var ajaxUrl = 'college_diagnostic_ajax';
    var idx = <?php echo (int)($savedUiState['current_index'] ?? 0); ?>;
    var flags = <?php echo json_encode($savedUiState['flags'] ?? [], JSON_UNESCAPED_UNICODE); ?>;
    var remaining = <?php echo $remainingSeconds !== null ? (int)$remainingSeconds : 'null'; ?>;

    function escHtml(s){ var d=document.createElement('div'); d.innerHTML=s||''; return d.innerHTML; }
    function qBySubjectPos(i){
      var q=questions[i]; if(!q) return {sub:0, pos:0, total:0};
      var nav=subjectNav.find(function(n){ return n.subject_id===q.subject_id; });
      var start=nav?nav.start_index:0;
      return { sub: q.subject_id, pos: i-start+1, total: nav?nav.count:0, code: q.subject_code };
    }
    function render(){
      var q=questions[idx]; if(!q) return;
      var meta=qBySubjectPos(idx);
      document.getElementById('subjectLabel').textContent=meta.code+' — Question '+meta.pos+' of '+meta.total;
      document.getElementById('progressLabel').textContent='Overall '+(idx+1)+' / '+questions.length;
      document.getElementById('questionText').innerHTML=escHtml(q.question_text);
      var wrap=document.getElementById('choicesWrap'); wrap.innerHTML='';
      ['A','B','C','D'].forEach(function(L){
        var key='choice_'+L.toLowerCase(); var val=q[key]||'';
        if(!val) return;
        var sel=(answers[q.question_id]||'')===L;
        var row=document.createElement('label');
        row.className='exam-choice'+(sel?' selected':'');
        row.innerHTML='<input type="radio" class="sr-only" name="ans" value="'+L+'"'+(sel?' checked':'')+'>'+
          '<span class="exam-choice-letter">'+L+'</span>'+
          '<div class="exam-choice-text">'+escHtml(val)+'</div>'+
          '<span class="exam-choice-check" aria-hidden="true"><i class="bi bi-check"></i></span>';
        row.onclick=function(e){ if(e.target.tagName!=='INPUT') row.querySelector('input').checked=true; saveAnswer(L); render(); };
        row.querySelector('input').onchange=function(){ saveAnswer(L); render(); };
        wrap.appendChild(row);
      });
      document.getElementById('prevBtn').disabled=idx<=0;
      document.getElementById('nextBtn').disabled=idx>=questions.length-1;
      renderSubjectNav(); renderGrid();
    }
    function renderSubjectNav(){
      var el=document.getElementById('subjectNav'); el.innerHTML='';
      var cur=questions[idx]?questions[idx].subject_id:0;
      subjectNav.forEach(function(s){
        var b=document.createElement('button');
        b.type='button'; b.className='subject-pill'+(s.subject_id===cur?' is-active':'');
        b.textContent=s.subject_code;
        b.onclick=function(){ idx=s.start_index; syncState(); render(); };
        el.appendChild(b);
      });
    }
    function renderGrid(){
      var g=document.getElementById('questionGrid'); g.innerHTML='';
      questions.forEach(function(q,i){
        var b=document.createElement('button');
        b.type='button';
        var cls='qchip';
        if(i===idx) cls+=' qchip-current';
        if(answers[q.question_id]) cls+=' qchip-answered';
        b.className=cls; b.textContent=String(i+1);
        b.onclick=function(){ idx=i; syncState(); render(); };
        g.appendChild(b);
      });
    }
    function saveAnswer(L){
      answers[questions[idx].question_id]=L;
      var fd=new FormData();
      fd.append('action','save_answer'); fd.append('csrf_token',csrf);
      fd.append('attempt_id',attemptId); fd.append('question_id',questions[idx].question_id);
      fd.append('selected_answer',L);
      fetch(ajaxUrl,{method:'POST',body:fd,credentials:'same-origin'});
    }
    function syncState(){
      var fd=new FormData();
      fd.append('action','sync_state'); fd.append('csrf_token',csrf);
      fd.append('attempt_id',attemptId); fd.append('current_index',idx);
      fd.append('flags',JSON.stringify(flags));
      fetch(ajaxUrl,{method:'POST',body:fd,credentials:'same-origin'});
    }
    function tickTimer(){
      if(remaining===null) return;
      var el=document.getElementById('timerBadge');
      if(remaining<=0){ el.textContent='Time up'; el.className='time-badge time-critical'; autoSubmit(); return; }
      var m=Math.floor(remaining/60), s=remaining%60;
      el.textContent=m+':'+(s<10?'0':'')+s;
      if(remaining<=300) el.className='time-badge time-critical';
      remaining--;
    }
    function autoSubmit(){ confirmSubmit(true); }
    function confirmSubmit(force){
      var fd=new FormData();
      fd.append('action','submit'); fd.append('csrf_token',csrf); fd.append('attempt_id',attemptId);
      fetch(ajaxUrl,{method:'POST',body:fd,credentials:'same-origin'}).then(function(r){return r.json();}).then(function(j){
        if(j.ok) window.location='college_diagnostic_take?batch_id=<?php echo (int)$batchId; ?>&review=1';
        else if(!force) alert(j.error||'Submit failed');
      });
    }
    document.getElementById('prevBtn').onclick=function(){ if(idx>0){ idx--; syncState(); render(); } };
    document.getElementById('nextBtn').onclick=function(){ if(idx<questions.length-1){ idx++; syncState(); render(); } };
    document.getElementById('submitBtn').onclick=function(){
      var ans=Object.keys(answers).length;
      document.getElementById('submitSummary').textContent='Answered '+ans+' of '+questions.length+' questions.';
      document.getElementById('submitModal').classList.remove('hidden');
    };
    document.getElementById('cancelSubmit').onclick=function(){ document.getElementById('submitModal').classList.add('hidden'); };
    document.getElementById('confirmSubmit').onclick=function(){ document.getElementById('submitModal').classList.add('hidden'); confirmSubmit(false); };
    document.addEventListener('visibilitychange',function(){
      if(document.hidden){
        var fd=new FormData(); fd.append('action','tab_blur'); fd.append('csrf_token',csrf); fd.append('attempt_id',attemptId);
        fetch(ajaxUrl,{method:'POST',body:fd,credentials:'same-origin'});
      }
    });
    // Heartbeat via sync_state every 15s (parity with regular exam)
    setInterval(function(){ syncState(); }, 15000);
    render(); setInterval(tickTimer,1000); tickTimer();
  })();
  </script>
  <?php else: ?>
  <div class="diag-card p-6"><p class="text-gray-600 m-0">Unable to load diagnostic session.</p></div>
  <?php endif; ?>
</main>
</body>
</html>
