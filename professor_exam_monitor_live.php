<?php
require_once __DIR__ . '/auth.php';
requireRole('professor_admin');
require_once __DIR__ . '/includes/college_schema.php';
require_once __DIR__ . '/includes/college_exam_helpers.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$uid = (int)getCurrentUserId();
$examId = (int)($_GET['exam_id'] ?? 0);
if ($examId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Invalid exam']);
    exit;
}

$stmt = mysqli_prepare($conn, 'SELECT exam_id FROM college_exams WHERE exam_id=? AND created_by=? LIMIT 1');
if (!$stmt) {
    echo json_encode(['ok' => false, 'error' => 'Server error']);
    exit;
}
mysqli_stmt_bind_param($stmt, 'ii', $examId, $uid);
mysqli_stmt_execute($stmt);
$okExam = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);
if (!$okExam) {
    echo json_encode(['ok' => false, 'error' => 'Not found']);
    exit;
}

$autoFinalized = college_exam_finalize_expired_in_progress($conn, $examId, 0, 0);

$metrics = [
    'taking_count' => 0,
    'submitted_count' => 0,
    'avg_score' => null,
    'pass_count' => 0,
    'fail_count' => 0,
];
$mq = @mysqli_query($conn, "
  SELECT
    SUM(CASE WHEN status='in_progress' THEN 1 ELSE 0 END) AS taking_count,
    SUM(CASE WHEN status='submitted' THEN 1 ELSE 0 END) AS submitted_count,
    AVG(CASE WHEN status='submitted' AND IFNULL(total_count,0) > 0
      THEN (50 + 0.5 * (100.0 * COALESCE(correct_count,0) / total_count))
      WHEN status='submitted' THEN score END) AS avg_score,
    SUM(CASE WHEN status='submitted' AND IFNULL(total_count,0) > 0
      AND COALESCE(correct_count,0) >= CEILING(total_count / 2) THEN 1
      ELSE 0 END) AS pass_count,
    SUM(CASE WHEN status='submitted' AND IFNULL(total_count,0) > 0
      AND COALESCE(correct_count,0) < CEILING(total_count / 2) THEN 1
      WHEN status='submitted' AND IFNULL(total_count,0) <= 0 THEN 1
      ELSE 0 END) AS fail_count
  FROM college_exam_attempts
  WHERE exam_id=" . (int)$examId
);
if ($mq) {
    $m = mysqli_fetch_assoc($mq);
    if ($m) {
        $metrics['taking_count'] = (int)($m['taking_count'] ?? 0);
        $metrics['submitted_count'] = (int)($m['submitted_count'] ?? 0);
        $metrics['avg_score'] = $m['avg_score'] !== null ? (float)$m['avg_score'] : null;
        $metrics['pass_count'] = (int)($m['pass_count'] ?? 0);
        $metrics['fail_count'] = (int)($m['fail_count'] ?? 0);
    }
    mysqli_free_result($mq);
}

function professor_exam_monitor_live_fmt_dt($raw): string
{
    if ($raw === null || $raw === '') {
        return '';
    }
    $s = trim((string) $raw);
    if ($s === '' || preg_match('/^0000-00-00(\s00:00:00)?$/', $s)) {
        return '';
    }
    $ts = strtotime($s);
    if ($ts === false) {
        return '';
    }

    return date('M j, Y g:i A', $ts);
}

$sq = mysqli_prepare($conn, "
  SELECT
    u.user_id, u.full_name,
    COALESCE(a.tab_switch_count, 0) AS tab_switch_count,
    a.last_tab_switch_at
  FROM users u
  INNER JOIN (
    SELECT user_id FROM college_exam_attempts WHERE exam_id=?
    UNION
    SELECT u2.user_id FROM users u2
    WHERE u2.role='college_student'
      AND (
        u2.status='approved' OR u2.status IS NULL OR TRIM(COALESCE(u2.status,''))=''
      )
  ) exam_roster ON exam_roster.user_id = u.user_id
  LEFT JOIN college_exam_attempts a ON a.user_id=u.user_id AND a.exam_id=?
  ORDER BY u.full_name ASC
");
mysqli_stmt_bind_param($sq, 'ii', $examId, $examId);
mysqli_stmt_execute($sq);
$sres = mysqli_stmt_get_result($sq);
$rows = [];
$totalTab = 0;
if ($sres) {
    while ($row = mysqli_fetch_assoc($sres)) {
        $tc = (int)($row['tab_switch_count'] ?? 0);
        $totalTab += $tc;
        $rows[] = [
            'user_id' => (int)$row['user_id'],
            'full_name' => (string)($row['full_name'] ?? ''),
            'tab_switch_count' => $tc,
            'last_tab_switch_at' => $row['last_tab_switch_at'],
            'last_tab_switch_fmt' => professor_exam_monitor_live_fmt_dt($row['last_tab_switch_at'] ?? null),
        ];
    }
    mysqli_free_result($sres);
}
mysqli_stmt_close($sq);

echo json_encode([
    'ok' => true,
    'exam_id' => $examId,
    'auto_finalized' => $autoFinalized,
    'taking_count' => $metrics['taking_count'],
    'submitted_count' => $metrics['submitted_count'],
    'avg_score' => $metrics['avg_score'],
    'pass_count' => $metrics['pass_count'],
    'fail_count' => $metrics['fail_count'],
    'total_tab_leaves' => $totalTab,
    'students' => $rows,
]);
exit;
