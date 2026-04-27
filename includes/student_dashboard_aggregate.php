<?php
/**
 * Consolidated DB reads for student_dashboard.php (fewer round-trips, no per-subject N+1).
 *
 * @param callable(mysqli,string):bool $tableExists
 * @param array|null $info users row (access_start/access_end) for focus access strip
 * @return array<string,mixed>
 */
function ereview_student_dashboard_aggregate(mysqli $conn, int $uid, callable $tableExists, ?array $info, int $weeklyActivityGoal): array
{
    $scalar = static function (mysqli $conn, string $sql, int $default = 0): int {
        $res = @mysqli_query($conn, $sql);
        if (!$res) {
            return $default;
        }
        $row = mysqli_fetch_assoc($res);
        return (int)($row['c'] ?? $default);
    };

    $counts = [];
    $unionParts = [];
    $unionParts[] = "SELECT 'subjects' AS k, COUNT(*) AS c FROM subjects WHERE status = 'active'";
    $unionParts[] = "SELECT 'lessons', COUNT(*) FROM lessons l INNER JOIN subjects s ON s.subject_id = l.subject_id WHERE s.status = 'active'";
    $unionParts[] = "SELECT 'quizzes', COUNT(*) FROM quizzes q INNER JOIN subjects s ON s.subject_id = q.subject_id WHERE s.status = 'active'";
    if ($tableExists($conn, 'quiz_attempts')) {
        $unionParts[] = 'SELECT \'quiz_submitted\', COUNT(*) FROM quiz_attempts WHERE user_id = ' . (int)$uid . " AND status = 'submitted'";
        $unionParts[] = 'SELECT \'quiz_distinct\', COUNT(DISTINCT quiz_id) FROM quiz_attempts WHERE user_id = ' . (int)$uid . " AND status = 'submitted'";
        $unionParts[] = 'SELECT \'quiz_in_progress\', COUNT(*) FROM quiz_attempts WHERE user_id = ' . (int)$uid . " AND status = 'in_progress'";
    }
    if ($tableExists($conn, 'preboards_subjects')) {
        $unionParts[] = "SELECT 'preboards_subjects', COUNT(*) FROM preboards_subjects WHERE status = 'active'";
    }
    if ($tableExists($conn, 'preboards_sets') && $tableExists($conn, 'preboards_subjects')) {
        $unionParts[] = "SELECT 'preboards_sets', COUNT(*) FROM preboards_sets ps INNER JOIN preboards_subjects sub ON sub.preboards_subject_id = ps.preboards_subject_id WHERE sub.status = 'active'";
    }
    if ($tableExists($conn, 'preboards_attempts')) {
        $unionParts[] = 'SELECT \'preboards_submitted\', COUNT(*) FROM preboards_attempts WHERE user_id = ' . (int)$uid . " AND status = 'submitted'";
        $unionParts[] = 'SELECT \'preboards_sets_done\', COUNT(DISTINCT preboards_set_id) FROM preboards_attempts WHERE user_id = ' . (int)$uid . " AND status = 'submitted'";
    }
    $uq = @mysqli_query($conn, implode(' UNION ALL ', $unionParts));
    if ($uq) {
        while ($row = mysqli_fetch_assoc($uq)) {
            $counts[(string)($row['k'] ?? '')] = (int)($row['c'] ?? 0);
        }
    }

    $subjectsCount = (int)($counts['subjects'] ?? 0);
    $lessonsCount = (int)($counts['lessons'] ?? 0);
    $quizzesCount = (int)($counts['quizzes'] ?? 0);
    $quizSubmittedCount = (int)($counts['quiz_submitted'] ?? 0);
    $quizzesSubmittedDistinct = (int)($counts['quiz_distinct'] ?? 0);
    $quizInProgressCount = (int)($counts['quiz_in_progress'] ?? 0);
    $preboardsSubjectsCount = (int)($counts['preboards_subjects'] ?? 0);
    $preboardsSetsCount = (int)($counts['preboards_sets'] ?? 0);
    $preboardsSubmittedCount = (int)($counts['preboards_submitted'] ?? 0);
    $preboardsSetsSubmittedDistinct = (int)($counts['preboards_sets_done'] ?? 0);

    $focusQuizRows = [];
    if ($tableExists($conn, 'quiz_attempts') && $tableExists($conn, 'quizzes') && $tableExists($conn, 'subjects')) {
        $fq = @mysqli_query($conn, "
            SELECT qa.attempt_id, qa.quiz_id, qa.started_at, qa.expires_at,
                   COALESCE(NULLIF(TRIM(q.title), ''), CONCAT('Quiz #', q.quiz_id)) AS quiz_title,
                   q.subject_id, s.subject_name
            FROM quiz_attempts qa
            INNER JOIN quizzes q ON q.quiz_id = qa.quiz_id
            INNER JOIN subjects s ON s.subject_id = q.subject_id AND s.status = 'active'
            WHERE qa.user_id = " . (int)$uid . " AND qa.status = 'in_progress'
            ORDER BY qa.started_at DESC
            LIMIT 5
        ");
        if ($fq) {
            while ($r = mysqli_fetch_assoc($fq)) {
                $focusQuizRows[] = $r;
            }
        }
    }

    $focusPreboardRows = [];
    if ($tableExists($conn, 'preboards_attempts') && $tableExists($conn, 'preboards_sets') && $tableExists($conn, 'preboards_subjects')) {
        $fp = @mysqli_query($conn, "
            SELECT pa.preboards_attempt_id, pa.preboards_set_id, pa.started_at,
                   COALESCE(NULLIF(TRIM(ps.title), ''), CONCAT('Set ', ps.set_label)) AS set_title,
                   ps.set_label, sub.subject_name, sub.preboards_subject_id
            FROM preboards_attempts pa
            INNER JOIN preboards_sets ps ON ps.preboards_set_id = pa.preboards_set_id
            INNER JOIN preboards_subjects sub ON sub.preboards_subject_id = ps.preboards_subject_id AND sub.status = 'active'
            WHERE pa.user_id = " . (int)$uid . " AND pa.status = 'in_progress'
            ORDER BY pa.started_at DESC
            LIMIT 5
        ");
        if ($fp) {
            while ($r = mysqli_fetch_assoc($fp)) {
                $focusPreboardRows[] = $r;
            }
        }
    }

    $avgQuizScore = 0;
    if ($tableExists($conn, 'quiz_attempts')) {
        $avgRes = @mysqli_query($conn, 'SELECT AVG(score) AS avg_score FROM quiz_attempts WHERE user_id = ' . (int)$uid . " AND status = 'submitted' AND score IS NOT NULL");
        if ($avgRes && ($avgRow = mysqli_fetch_assoc($avgRes)) && $avgRow['avg_score'] !== null) {
            $avgQuizScore = (int)round((float)$avgRow['avg_score']);
        }
    }

    $weeklyActivity = [];
    $weeklyQuizAct = [];
    $weeklyPreAct = [];
    $weeklyLabels = [];
    for ($i = 7; $i >= 0; $i--) {
        $weekTs = strtotime("monday this week -{$i} week");
        $key = date('o', $weekTs) . '-' . date('W', $weekTs);
        $weeklyActivity[$key] = 0;
        $weeklyQuizAct[$key] = 0;
        $weeklyPreAct[$key] = 0;
        $weeklyLabels[$key] = 'Wk ' . date('M j', $weekTs);
    }
    $ywExpr = "CONCAT(DATE_FORMAT(submitted_at, '%x'), '-', DATE_FORMAT(submitted_at, '%v'))";
    if ($tableExists($conn, 'quiz_attempts')) {
        $act1 = @mysqli_query($conn, "SELECT {$ywExpr} AS yw, COUNT(*) AS c FROM quiz_attempts WHERE user_id=" . (int)$uid . " AND status='submitted' AND submitted_at >= DATE_SUB(CURDATE(), INTERVAL 8 WEEK) GROUP BY yw");
        if ($act1) {
            while ($row = mysqli_fetch_assoc($act1)) {
                $k = (string)($row['yw'] ?? '');
                if (isset($weeklyQuizAct[$k])) {
                    $c = (int)($row['c'] ?? 0);
                    $weeklyQuizAct[$k] += $c;
                    $weeklyActivity[$k] += $c;
                }
            }
        }
    }
    if ($tableExists($conn, 'preboards_attempts')) {
        $act2 = @mysqli_query($conn, "SELECT {$ywExpr} AS yw, COUNT(*) AS c FROM preboards_attempts WHERE user_id=" . (int)$uid . " AND status='submitted' AND submitted_at >= DATE_SUB(CURDATE(), INTERVAL 8 WEEK) GROUP BY yw");
        if ($act2) {
            while ($row = mysqli_fetch_assoc($act2)) {
                $k = (string)($row['yw'] ?? '');
                if (isset($weeklyPreAct[$k])) {
                    $c = (int)($row['c'] ?? 0);
                    $weeklyPreAct[$k] += $c;
                    $weeklyActivity[$k] += $c;
                }
            }
        }
    }

    $activityLast8Weeks = array_sum($weeklyActivity);
    $wkVals = array_values($weeklyActivity);
    $wkLen = count($wkVals);
    $currentWeekActivity = $wkLen > 0 ? (int)$wkVals[$wkLen - 1] : 0;
    $prevWeekActivity = $wkLen > 1 ? (int)$wkVals[$wkLen - 2] : 0;
    $activityWoW = $currentWeekActivity - $prevWeekActivity;
    $hour = (int)date('G');
    $greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');

    $quizCoverage = $quizzesCount > 0 ? min(100, (int)round(($quizzesSubmittedDistinct / max(1, $quizzesCount)) * 100)) : 0;
    $preboardCoverage = $preboardsSetsCount > 0 ? min(100, (int)round(($preboardsSetsSubmittedDistinct / max(1, $preboardsSetsCount)) * 100)) : 0;
    if ($quizzesCount > 0 && $preboardsSetsCount > 0) {
        $learningProgressPct = (int)round(($quizCoverage + $preboardCoverage) / 2);
    } elseif ($quizzesCount > 0) {
        $learningProgressPct = $quizCoverage;
    } elseif ($preboardsSetsCount > 0) {
        $learningProgressPct = $preboardCoverage;
    } else {
        $learningProgressPct = 0;
    }

    $nextExpireQuizRow = null;
    $nextExpireQuizTimeNote = '';
    if ($tableExists($conn, 'quiz_attempts') && $tableExists($conn, 'quizzes') && $tableExists($conn, 'subjects')) {
        $nex = @mysqli_query($conn, "
            SELECT qa.quiz_id, qa.expires_at,
                   COALESCE(NULLIF(TRIM(q.title), ''), CONCAT('Quiz #', q.quiz_id)) AS quiz_title,
                   q.subject_id, s.subject_name
            FROM quiz_attempts qa
            INNER JOIN quizzes q ON q.quiz_id = qa.quiz_id
            INNER JOIN subjects s ON s.subject_id = q.subject_id AND s.status = 'active'
            WHERE qa.user_id = " . (int)$uid . " AND qa.status = 'in_progress' AND qa.expires_at IS NOT NULL
            ORDER BY qa.expires_at ASC
            LIMIT 1
        ");
        if ($nex && ($nr = mysqli_fetch_assoc($nex))) {
            $nextExpireQuizRow = $nr;
            $nextExpireQuizRow['href'] = 'student_take_quiz.php?quiz_id=' . (int)$nr['quiz_id'] . '&subject_id=' . (int)$nr['subject_id'];
            $ex = strtotime((string)($nr['expires_at'] ?? ''));
            if ($ex !== false) {
                $remain = $ex - time();
                if ($remain <= 0) {
                    $nextExpireQuizTimeNote = 'Due now';
                } elseif ($remain < 3600) {
                    $nextExpireQuizTimeNote = (int)ceil($remain / 60) . ' min remaining';
                } elseif ($remain < 86400) {
                    $nextExpireQuizTimeNote = (int)ceil($remain / 3600) . ' hours remaining';
                } else {
                    $nextExpireQuizTimeNote = (int)floor($remain / 86400) . ' day' . ((int)floor($remain / 86400) === 1 ? '' : 's') . ' remaining';
                }
            }
        }
    }

    $subjectStudyHints = [];
    if ($tableExists($conn, 'subjects') && $tableExists($conn, 'quizzes')) {
        $hasAttempts = $tableExists($conn, 'quiz_attempts');
        $sql = "
            SELECT s.subject_id, s.subject_name,
              (SELECT COUNT(*) FROM quizzes q WHERE q.subject_id = s.subject_id) AS quiz_total,
              " . ($hasAttempts
                ? "(SELECT COUNT(DISTINCT qa.quiz_id) FROM quiz_attempts qa INNER JOIN quizzes qq ON qq.quiz_id = qa.quiz_id WHERE qq.subject_id = s.subject_id AND qa.user_id = " . (int)$uid . " AND qa.status = 'submitted') AS quiz_done,
              (SELECT COUNT(*) FROM quiz_attempts qa INNER JOIN quizzes qq ON qq.quiz_id = qa.quiz_id WHERE qq.subject_id = s.subject_id AND qa.user_id = " . (int)$uid . " AND qa.status = 'submitted' AND qa.submitted_at >= DATE_FORMAT(NOW(), '%Y-%m-01')) AS month_submissions"
                : '0 AS quiz_done, 0 AS month_submissions') . "
            FROM subjects s
            WHERE s.status = 'active'
            ORDER BY s.subject_name ASC
        ";
        $sr = @mysqli_query($conn, $sql);
        if ($sr) {
            while ($srow = mysqli_fetch_assoc($sr)) {
                $sid = (int)($srow['subject_id'] ?? 0);
                if ($sid <= 0) {
                    continue;
                }
                $qzTot = (int)($srow['quiz_total'] ?? 0);
                $qzDone = (int)($srow['quiz_done'] ?? 0);
                $monthCt = (int)($srow['month_submissions'] ?? 0);
                $cov = $qzTot > 0 ? (int)round(($qzDone / max(1, $qzTot)) * 100) : 100;
                $subjectStudyHints[] = [
                    'subject_id' => $sid,
                    'subject_name' => (string)($srow['subject_name'] ?? ''),
                    'quiz_total' => $qzTot,
                    'quiz_done' => $qzDone,
                    'coverage' => $cov,
                    'month_submissions' => $monthCt,
                    'quiet_month' => ($qzTot > 0 && $monthCt === 0),
                ];
            }
        }
    }
    usort($subjectStudyHints, static function ($a, $b) {
        if (($a['quiet_month'] ?? false) !== ($b['quiet_month'] ?? false)) {
            return ($a['quiet_month'] ?? false) ? -1 : 1;
        }
        return ($a['coverage'] ?? 0) <=> ($b['coverage'] ?? 0);
    });
    $subjectStudyHints = array_values(array_filter($subjectStudyHints, static function ($h) {
        return (int)($h['quiz_total'] ?? 0) > 0;
    }));
    $subjectStudyHints = array_slice($subjectStudyHints, 0, 5);

    $last5Scores = [];
    $last5Avg = 0;
    $last5Trend = 'flat';
    if ($tableExists($conn, 'quiz_attempts')) {
        $l5 = @mysqli_query($conn, 'SELECT score, submitted_at FROM quiz_attempts WHERE user_id = ' . (int)$uid . " AND status = 'submitted' AND score IS NOT NULL ORDER BY submitted_at DESC, attempt_id DESC LIMIT 5");
        if ($l5) {
            while ($lr = mysqli_fetch_assoc($l5)) {
                $last5Scores[] = ['score' => (float)($lr['score'] ?? 0), 'at' => (string)($lr['submitted_at'] ?? '')];
            }
        }
        if (count($last5Scores) > 0) {
            $sum = 0.0;
            foreach ($last5Scores as $ls) {
                $sum += (float)($ls['score'] ?? 0);
            }
            $last5Avg = (int)round($sum / count($last5Scores));
            if (count($last5Scores) >= 2) {
                $a = (float)$last5Scores[0]['score'];
                $b = (float)$last5Scores[count($last5Scores) - 1]['score'];
                if ($a > $b + 0.5) {
                    $last5Trend = 'up';
                } elseif ($a < $b - 0.5) {
                    $last5Trend = 'down';
                }
            }
        }
    }

    $distinctActivityDates = [];
    if ($tableExists($conn, 'quiz_attempts') || $tableExists($conn, 'preboards_attempts')) {
        $dateParts = [];
        if ($tableExists($conn, 'quiz_attempts')) {
            $dateParts[] = 'SELECT DATE(submitted_at) AS d FROM quiz_attempts WHERE user_id = ' . (int)$uid . " AND status = 'submitted' AND submitted_at >= DATE_SUB(CURDATE(), INTERVAL 400 DAY)";
        }
        if ($tableExists($conn, 'preboards_attempts')) {
            $dateParts[] = 'SELECT DATE(submitted_at) AS d FROM preboards_attempts WHERE user_id = ' . (int)$uid . " AND status = 'submitted' AND submitted_at >= DATE_SUB(CURDATE(), INTERVAL 400 DAY)";
        }
        if ($dateParts) {
            $dunion = @mysqli_query($conn, 'SELECT DISTINCT d FROM (' . implode(' UNION ', $dateParts) . ') u WHERE d IS NOT NULL');
            if ($dunion) {
                while ($dr = mysqli_fetch_assoc($dunion)) {
                    if (!empty($dr['d'])) {
                        $distinctActivityDates[(string)$dr['d']] = true;
                    }
                }
            }
        }
    }
    $todayYmd = date('Y-m-d');
    $daysActiveStreak = 0;
    for ($i = 0; $i < 400; $i++) {
        $d = date('Y-m-d', strtotime($todayYmd . ' -' . $i . ' day'));
        if (!empty($distinctActivityDates[$d])) {
            $daysActiveStreak++;
        } else {
            break;
        }
    }

    $weeksActiveStreak = 0;
    $wkOrder = array_keys($weeklyActivity);
    $wkCount = count($wkOrder);
    for ($i = $wkCount - 1; $i >= 0; $i--) {
        $k = $wkOrder[$i];
        if ((int)($weeklyActivity[$k] ?? 0) > 0) {
            $weeksActiveStreak++;
        } else {
            break;
        }
    }

    $drillByWeek = [];
    foreach (array_keys($weeklyLabels) as $__wk) {
        $drillByWeek[$__wk] = [];
    }
    if ($tableExists($conn, 'quiz_attempts') && $tableExists($conn, 'quizzes')) {
        $dq = @mysqli_query($conn, "
            SELECT {$ywExpr} AS yw, qa.submitted_at, qa.score,
                   COALESCE(NULLIF(TRIM(q.title), ''), CONCAT('Quiz #', q.quiz_id)) AS title,
                   s.subject_name, s.subject_id
            FROM quiz_attempts qa
            INNER JOIN quizzes q ON q.quiz_id = qa.quiz_id
            INNER JOIN subjects s ON s.subject_id = q.subject_id AND s.status = 'active'
            WHERE qa.user_id = " . (int)$uid . " AND qa.status = 'submitted' AND qa.submitted_at >= DATE_SUB(CURDATE(), INTERVAL 8 WEEK)
            ORDER BY qa.submitted_at DESC
        ");
        if ($dq) {
            while ($dr = mysqli_fetch_assoc($dq)) {
                $wk = (string)($dr['yw'] ?? '');
                if (!isset($drillByWeek[$wk])) {
                    continue;
                }
                $drillByWeek[$wk][] = [
                    'kind' => 'quiz',
                    'title' => (string)($dr['title'] ?? 'Quiz'),
                    'sub' => (string)($dr['subject_name'] ?? ''),
                    'at' => (string)($dr['submitted_at'] ?? ''),
                    'score' => $dr['score'] !== null ? (float)$dr['score'] : null,
                    'href' => 'student_subject.php?subject_id=' . (int)($dr['subject_id'] ?? 0),
                ];
            }
        }
    }
    if ($tableExists($conn, 'preboards_attempts') && $tableExists($conn, 'preboards_sets')) {
        $dp = @mysqli_query($conn, "
            SELECT {$ywExpr} AS yw, pa.submitted_at, pa.score,
                   COALESCE(NULLIF(TRIM(ps.title), ''), CONCAT('Set ', ps.set_label)) AS title,
                   sub.subject_name, sub.preboards_subject_id
            FROM preboards_attempts pa
            INNER JOIN preboards_sets ps ON ps.preboards_set_id = pa.preboards_set_id
            INNER JOIN preboards_subjects sub ON sub.preboards_subject_id = ps.preboards_subject_id AND sub.status = 'active'
            WHERE pa.user_id = " . (int)$uid . " AND pa.status = 'submitted' AND pa.submitted_at >= DATE_SUB(CURDATE(), INTERVAL 8 WEEK)
            ORDER BY pa.submitted_at DESC
        ");
        if ($dp) {
            while ($dr = mysqli_fetch_assoc($dp)) {
                $wk = (string)($dr['yw'] ?? '');
                if (!isset($drillByWeek[$wk])) {
                    continue;
                }
                $psid = (int)($dr['preboards_subject_id'] ?? 0);
                $drillByWeek[$wk][] = [
                    'kind' => 'preboard',
                    'title' => (string)($dr['title'] ?? 'Preboard'),
                    'sub' => (string)($dr['subject_name'] ?? ''),
                    'at' => (string)($dr['submitted_at'] ?? ''),
                    'score' => $dr['score'] !== null ? (float)$dr['score'] : null,
                    'href' => $psid > 0 ? ('student_preboards_view.php?preboards_subject_id=' . $psid) : 'student_preboards.php',
                ];
            }
        }
    }

    $weekDrillForJs = [];
    foreach (array_keys($weeklyLabels) as $__wk) {
        $weekDrillForJs[] = [
            'key' => $__wk,
            'label' => $weeklyLabels[$__wk],
            'items' => $drillByWeek[$__wk] ?? [],
        ];
    }

    $focusAccessDaysLeft = null;
    $focusAccessEndTs = 0;
    $focusAccessPctUsed = null;
    if ($info && !empty($info['access_end'])) {
        $focusAccessEndTs = (int)strtotime((string)$info['access_end']);
        if ($focusAccessEndTs > 0) {
            $focusAccessDaysLeft = (int)floor(($focusAccessEndTs - time()) / 86400);
            $startTs = !empty($info['access_start']) ? strtotime((string)$info['access_start']) : null;
            if ($startTs && $focusAccessEndTs > $startTs) {
                $focusAccessPctUsed = (int)round(((time() - $startTs) / ($focusAccessEndTs - $startTs)) * 100);
                $focusAccessPctUsed = max(0, min(100, $focusAccessPctUsed));
            }
        }
    }

    $goalThisWeekCount = $currentWeekActivity;
    $goalProgressPct = 0;
    if ($weeklyActivityGoal > 0) {
        $goalProgressPct = (int)min(100, round(($goalThisWeekCount / $weeklyActivityGoal) * 100));
    }

    $activityChartEmpty = ($activityLast8Weeks === 0);

    return compact(
        'subjectsCount',
        'lessonsCount',
        'quizzesCount',
        'quizSubmittedCount',
        'quizzesSubmittedDistinct',
        'quizInProgressCount',
        'preboardsSubjectsCount',
        'preboardsSetsCount',
        'preboardsSubmittedCount',
        'preboardsSetsSubmittedDistinct',
        'focusQuizRows',
        'focusPreboardRows',
        'avgQuizScore',
        'weeklyActivity',
        'weeklyQuizAct',
        'weeklyPreAct',
        'weeklyLabels',
        'activityLast8Weeks',
        'wkLen',
        'currentWeekActivity',
        'prevWeekActivity',
        'activityWoW',
        'greeting',
        'quizCoverage',
        'preboardCoverage',
        'learningProgressPct',
        'nextExpireQuizRow',
        'nextExpireQuizTimeNote',
        'subjectStudyHints',
        'last5Scores',
        'last5Avg',
        'last5Trend',
        'daysActiveStreak',
        'weeksActiveStreak',
        'drillByWeek',
        'weekDrillForJs',
        'focusAccessDaysLeft',
        'focusAccessEndTs',
        'focusAccessPctUsed',
        'goalThisWeekCount',
        'goalProgressPct',
        'activityChartEmpty'
    );
}
