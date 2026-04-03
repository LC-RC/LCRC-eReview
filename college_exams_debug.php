<?php
/**
 * Diagnostic JSON for college exam visibility (college_student session only).
 * Use with the browser console snippet; remove from production when done debugging.
 */
require_once __DIR__ . '/auth.php';
requireRole('college_student');
require_once __DIR__ . '/includes/college_schema.php';
require_once __DIR__ . '/includes/college_exam_helpers.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

$uid = (int)(getCurrentUserId() ?? 0);
$role = (string)(getCurrentUserRole() ?? '');
$dbName = '';
if ($conn instanceof mysqli) {
    $dr = mysqli_query($conn, 'SELECT DATABASE() AS db');
    if ($dr && ($drow = mysqli_fetch_assoc($dr))) {
        $dbName = (string)($drow['db'] ?? '');
    }
    if ($dr) {
        mysqli_free_result($dr);
    }
}

$tableExists = static function (mysqli $conn, string $name): bool {
    $esc = mysqli_real_escape_string($conn, $name);
    $r = mysqli_query($conn, "SHOW TABLES LIKE '{$esc}'");
    if (!$r) {
        return false;
    }
    $ok = mysqli_num_rows($r) > 0;
    mysqli_free_result($r);
    return $ok;
};

$out = [
    'ok' => true,
    'time_utc' => gmdate('c'),
    'session_user_id' => $uid,
    'session_role' => $role,
    'mysqli_database' => $dbName,
    'table_college_exams' => $tableExists($conn, 'college_exams'),
    'table_college_exam_attempts' => $tableExists($conn, 'college_exam_attempts'),
];

$counts = [];
$pubWhere = college_exam_where_published_sql();
foreach ([
    'all' => 'SELECT COUNT(*) AS c FROM college_exams',
    'published_eq_1' => 'SELECT COUNT(*) AS c FROM college_exams WHERE is_published = 1',
    'published_app' => 'SELECT COUNT(*) AS c FROM college_exams WHERE ' . $pubWhere,
] as $key => $sql) {
    $cr = mysqli_query($conn, $sql);
    if (!$cr) {
        $counts[$key] = null;
        $out['count_errors'][$key] = mysqli_error($conn);
    } else {
        $row = mysqli_fetch_assoc($cr);
        mysqli_free_result($cr);
        $counts[$key] = isset($row['c']) ? (int)$row['c'] : null;
    }
}
$out['counts'] = $counts;

$recent = [];
$rr = mysqli_query($conn, 'SELECT exam_id, title, is_published, available_from, deadline, created_at FROM college_exams ORDER BY exam_id DESC LIMIT 15');
if ($rr) {
    while ($row = mysqli_fetch_assoc($rr)) {
        $recent[] = [
            'exam_id' => (int)($row['exam_id'] ?? 0),
            'title' => (string)($row['title'] ?? ''),
            'is_published' => $row['is_published'] ?? null,
            'available_from' => $row['available_from'] ?? null,
            'deadline' => $row['deadline'] ?? null,
            'created_at' => $row['created_at'] ?? null,
        ];
    }
    mysqli_free_result($rr);
} else {
    $out['recent_exams_error'] = mysqli_error($conn);
}
$out['recent_exams'] = $recent;

// Same SELECT as college_exams.php (list step)
$listErr = null;
$examRows = [];
$pw = college_exam_where_published_sql();
$q = mysqli_query($conn, "
  SELECT exam_id, title, is_published
  FROM college_exams
  WHERE {$pw}
  ORDER BY deadline IS NULL, deadline ASC, title ASC
");
if (!$q) {
    $listErr = mysqli_error($conn);
} else {
    while ($row = mysqli_fetch_assoc($q)) {
        $examRows[] = $row;
    }
    mysqli_free_result($q);
}
$out['list_query_error'] = $listErr;
$out['list_query_match_count'] = count($examRows);
$out['list_query_titles'] = array_map(static function ($r) {
    return (string)($r['title'] ?? '');
}, $examRows);

// Render-probe: execute the same row computations as college_exams.php and capture any fatal/warning source.
$renderProbe = [
    'ok' => true,
    'row_count' => 0,
    'rows' => [],
    'error' => null,
];
try {
    $now = date('Y-m-d H:i:s');
    $uidInt = $uid;
    $fullExamRows = [];
    $q2 = mysqli_query($conn, "
      SELECT *
      FROM college_exams
      WHERE {$pw}
      ORDER BY deadline IS NULL, deadline ASC, title ASC
    ");
    if (!$q2) {
        throw new RuntimeException('full exam query failed: ' . mysqli_error($conn));
    }
    while ($r = mysqli_fetch_assoc($q2)) {
        $fullExamRows[] = $r;
    }
    mysqli_free_result($q2);

    $attemptByExam = [];
    if ($fullExamRows !== [] && $uidInt > 0) {
        $ids = array_values(array_unique(array_map(static function ($r) {
            return (int)($r['exam_id'] ?? 0);
        }, $fullExamRows)));
        $ids = array_values(array_filter($ids, static function ($id) {
            return $id > 0;
        }));
        if ($ids !== []) {
            $inSql = implode(',', $ids);
            $aq = mysqli_query($conn, "
              SELECT exam_id, status AS attempt_status, score, correct_count, total_count, submitted_at
              FROM college_exam_attempts
              WHERE user_id = " . $uidInt . " AND exam_id IN (" . $inSql . ")
            ");
            if ($aq) {
                while ($ar = mysqli_fetch_assoc($aq)) {
                    $eid = (int)($ar['exam_id'] ?? 0);
                    if ($eid > 0) {
                        $attemptByExam[$eid] = $ar;
                    }
                }
                mysqli_free_result($aq);
            }
        }
    }

    set_error_handler(static function ($severity, $message, $file, $line) {
        throw new ErrorException($message, 0, $severity, $file, $line);
    });
    try {
        foreach ($fullExamRows as $e) {
            $eid = (int)($e['exam_id'] ?? 0);
            if ($eid > 0 && isset($attemptByExam[$eid])) {
                $e = array_merge($e, $attemptByExam[$eid]);
            } else {
                $e['attempt_status'] = null;
                $e['score'] = null;
                $e['correct_count'] = null;
                $e['total_count'] = null;
                $e['submitted_at'] = null;
            }

            // Same logic path as template.
            $avail = true;
            if (empty($e['is_published'])) {
                $avail = false;
            }
            if (!empty($e['available_from']) && $e['available_from'] > $now) {
                $avail = false;
            }
            if (!empty($e['deadline']) && $e['deadline'] < $now) {
                $avail = false;
            }
            $st = $e['attempt_status'] ?? '';
            $deadlineLabel = '—';
            if (!empty($e['deadline'])) {
                $ts = strtotime((string)$e['deadline']);
                $deadlineLabel = $ts !== false ? date('M j, Y g:i A', $ts) : '—';
            }
            $titleSafe = h((string)($e['title'] ?? ''));
            $renderProbe['rows'][] = [
                'exam_id' => $eid,
                'title' => $titleSafe,
                'status' => (string)$st,
                'deadline_label' => $deadlineLabel,
                'available' => $avail,
            ];
        }
    } finally {
        restore_error_handler();
    }
    $renderProbe['row_count'] = count($renderProbe['rows']);
} catch (Throwable $t) {
    $renderProbe['ok'] = false;
    $renderProbe['error'] = [
        'class' => get_class($t),
        'message' => $t->getMessage(),
        'file' => $t->getFile(),
        'line' => $t->getLine(),
    ];
}
$out['render_probe'] = $renderProbe;

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
