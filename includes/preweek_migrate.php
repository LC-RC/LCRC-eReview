<?php
/**
 * Preweek module schema bootstrap.
 * Hierarchy: preweek_units (named preweek) → preweek_topics → videos / handouts.
 * Legacy year-only buckets may still exist in DB; admin UI no longer uses year.
 */

if (!isset($conn) || !($conn instanceof mysqli)) {
    return;
}

@mysqli_query($conn, "CREATE TABLE IF NOT EXISTS preweek_units (
  preweek_unit_id INT AUTO_INCREMENT PRIMARY KEY,
  subject_id INT NOT NULL DEFAULT 0,
  title VARCHAR(255) NOT NULL,
  description TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_preweek_units_subject (subject_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$colPy = @mysqli_query($conn, "SHOW COLUMNS FROM preweek_units LIKE 'preweek_year'");
if ($colPy && !mysqli_fetch_assoc($colPy)) {
    @mysqli_query($conn, 'ALTER TABLE preweek_units ADD COLUMN preweek_year INT NULL DEFAULT NULL AFTER subject_id');
    @mysqli_query($conn, 'ALTER TABLE preweek_units ADD KEY idx_preweek_units_year (preweek_year)');
}

@mysqli_query($conn, "CREATE TABLE IF NOT EXISTS preweek_topics (
  preweek_topic_id INT AUTO_INCREMENT PRIMARY KEY,
  preweek_unit_id INT NOT NULL,
  title VARCHAR(255) NOT NULL,
  description TEXT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_preweek_topics_unit (preweek_unit_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

@mysqli_query($conn, "CREATE TABLE IF NOT EXISTS preweek_videos (
  preweek_video_id INT AUTO_INCREMENT PRIMARY KEY,
  subject_id INT NOT NULL DEFAULT 0,
  preweek_unit_id INT NULL,
  video_title VARCHAR(255) NOT NULL,
  video_url TEXT NOT NULL,
  upload_type ENUM('url','file') NOT NULL DEFAULT 'url',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_preweek_videos_subject (subject_id),
  KEY idx_preweek_videos_unit (preweek_unit_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

@mysqli_query($conn, "CREATE TABLE IF NOT EXISTS preweek_handouts (
  preweek_handout_id INT AUTO_INCREMENT PRIMARY KEY,
  subject_id INT NOT NULL DEFAULT 0,
  preweek_unit_id INT NULL,
  handout_title VARCHAR(255) NOT NULL,
  file_path TEXT NOT NULL,
  file_name VARCHAR(255) NULL,
  file_size BIGINT NULL,
  allow_download TINYINT(1) NOT NULL DEFAULT 1,
  uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_preweek_handouts_subject (subject_id),
  KEY idx_preweek_handouts_unit (preweek_unit_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$col = @mysqli_query($conn, "SHOW COLUMNS FROM preweek_videos LIKE 'subject_id'");
if ($col && !mysqli_fetch_assoc($col)) {
    @mysqli_query($conn, "ALTER TABLE preweek_videos ADD COLUMN subject_id INT NOT NULL DEFAULT 0 AFTER preweek_video_id");
    @mysqli_query($conn, 'ALTER TABLE preweek_videos ADD KEY idx_preweek_videos_subject (subject_id)');
}
$col = @mysqli_query($conn, "SHOW COLUMNS FROM preweek_handouts LIKE 'subject_id'");
if ($col && !mysqli_fetch_assoc($col)) {
    @mysqli_query($conn, "ALTER TABLE preweek_handouts ADD COLUMN subject_id INT NOT NULL DEFAULT 0 AFTER preweek_handout_id");
    @mysqli_query($conn, 'ALTER TABLE preweek_handouts ADD KEY idx_preweek_handouts_subject (subject_id)');
}

$col = @mysqli_query($conn, "SHOW COLUMNS FROM preweek_videos LIKE 'preweek_unit_id'");
if ($col && !mysqli_fetch_assoc($col)) {
    @mysqli_query($conn, 'ALTER TABLE preweek_videos ADD COLUMN preweek_unit_id INT NULL AFTER subject_id');
    @mysqli_query($conn, 'ALTER TABLE preweek_videos ADD KEY idx_preweek_videos_unit (preweek_unit_id)');
}
$col = @mysqli_query($conn, "SHOW COLUMNS FROM preweek_handouts LIKE 'preweek_unit_id'");
if ($col && !mysqli_fetch_assoc($col)) {
    @mysqli_query($conn, 'ALTER TABLE preweek_handouts ADD COLUMN preweek_unit_id INT NULL AFTER subject_id');
    @mysqli_query($conn, 'ALTER TABLE preweek_handouts ADD KEY idx_preweek_handouts_unit (preweek_unit_id)');
}

$colTopicV = @mysqli_query($conn, "SHOW COLUMNS FROM preweek_videos LIKE 'preweek_topic_id'");
if ($colTopicV && !mysqli_fetch_assoc($colTopicV)) {
    @mysqli_query($conn, 'ALTER TABLE preweek_videos ADD COLUMN preweek_topic_id INT NULL AFTER preweek_unit_id');
    @mysqli_query($conn, 'ALTER TABLE preweek_videos ADD KEY idx_preweek_videos_topic (preweek_topic_id)');
}
$colTopicH = @mysqli_query($conn, "SHOW COLUMNS FROM preweek_handouts LIKE 'preweek_topic_id'");
if ($colTopicH && !mysqli_fetch_assoc($colTopicH)) {
    @mysqli_query($conn, 'ALTER TABLE preweek_handouts ADD COLUMN preweek_topic_id INT NULL AFTER preweek_unit_id');
    @mysqli_query($conn, 'ALTER TABLE preweek_handouts ADD KEY idx_preweek_handouts_topic (preweek_topic_id)');
}

$curYear = (int)date('Y');

$uqDrop = @mysqli_query($conn, "SHOW INDEX FROM preweek_units WHERE Key_name = 'uq_preweek_units_year'");
if ($uqDrop && mysqli_num_rows($uqDrop) > 0) {
    @mysqli_query($conn, 'ALTER TABLE preweek_units DROP INDEX uq_preweek_units_year');
}

$colNullable = @mysqli_query($conn, "SHOW COLUMNS FROM preweek_units LIKE 'preweek_year'");
if ($colNullable && ($colRow = mysqli_fetch_assoc($colNullable)) && !empty($colRow['Null']) && strtoupper((string)$colRow['Null']) === 'NO') {
    @mysqli_query($conn, 'ALTER TABLE preweek_units MODIFY preweek_year INT NULL DEFAULT NULL');
}

if (!function_exists('ereview_ensure_preweek_unit_for_year')) {
    /**
     * Legacy: one bucket per calendar year. Used by orphan backfill only.
     */
    function ereview_ensure_preweek_unit_for_year(mysqli $conn, int $year): int
    {
        if ($year < 2000 || $year > 2100) {
            return 0;
        }
        $year = (int)$year;
        $q = mysqli_query($conn, 'SELECT preweek_unit_id FROM preweek_units WHERE preweek_year=' . $year . ' LIMIT 1');
        if ($q && ($r = mysqli_fetch_assoc($q))) {
            return (int)($r['preweek_unit_id'] ?? 0);
        }
        $title = 'Preweek materials';
        $sid = 0;
        $stmt = mysqli_prepare($conn, 'INSERT INTO preweek_units (subject_id, preweek_year, title, description) VALUES (?, ?, ?, NULL)');
        if (!$stmt) {
            return 0;
        }
        mysqli_stmt_bind_param($stmt, 'iis', $sid, $year, $title);
        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            return 0;
        }
        $id = (int)mysqli_insert_id($conn);
        mysqli_stmt_close($stmt);
        return $id;
    }
}

if (!function_exists('ereview_create_preweek_named')) {
    /**
     * Create a named preweek (subject_id=0). Returns preweek_unit_id or 0.
     */
    function ereview_create_preweek_named(mysqli $conn, string $title): int
    {
        $title = trim($title);
        if ($title === '' || strlen($title) > 255) {
            return 0;
        }
        $stmt = mysqli_prepare($conn, 'INSERT INTO preweek_units (subject_id, preweek_year, title, description) VALUES (0, NULL, ?, NULL)');
        if (!$stmt) {
            return 0;
        }
        mysqli_stmt_bind_param($stmt, 's', $title);
        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            return 0;
        }
        $id = (int)mysqli_insert_id($conn);
        mysqli_stmt_close($stmt);
        return $id;
    }
}

// Attach legacy materials to a default topic "General" per unit (one-time)
$didTopicBackfill = @mysqli_query($conn, "SELECT 1 FROM preweek_videos WHERE preweek_unit_id IS NOT NULL AND preweek_unit_id > 0 AND preweek_topic_id IS NULL LIMIT 1");
$needTopicMigrate = ($didTopicBackfill && mysqli_fetch_assoc($didTopicBackfill));
if (!$needTopicMigrate) {
    $didH = @mysqli_query($conn, "SELECT 1 FROM preweek_handouts WHERE preweek_unit_id IS NOT NULL AND preweek_unit_id > 0 AND preweek_topic_id IS NULL LIMIT 1");
    $needTopicMigrate = ($didH && mysqli_fetch_assoc($didH));
}
if ($needTopicMigrate) {
    $uq = @mysqli_query($conn, 'SELECT DISTINCT preweek_unit_id AS uid FROM preweek_videos WHERE preweek_unit_id IS NOT NULL AND preweek_unit_id > 0
      UNION SELECT DISTINCT preweek_unit_id FROM preweek_handouts WHERE preweek_unit_id IS NOT NULL AND preweek_unit_id > 0');
    $unitIds = [];
    if ($uq) {
        while ($rw = mysqli_fetch_assoc($uq)) {
            $uid = (int)($rw['uid'] ?? 0);
            if ($uid > 0) {
                $unitIds[$uid] = true;
            }
        }
    }
    foreach (array_keys($unitIds) as $uid) {
        $uid = (int)$uid;
        $tid = 0;
        $find = @mysqli_query($conn, 'SELECT preweek_topic_id FROM preweek_topics WHERE preweek_unit_id=' . $uid . " AND title='General' LIMIT 1");
        if ($find && ($fr = mysqli_fetch_assoc($find))) {
            $tid = (int)($fr['preweek_topic_id'] ?? 0);
        }
        if ($tid <= 0) {
            $stmt = mysqli_prepare($conn, 'INSERT INTO preweek_topics (preweek_unit_id, title, sort_order) VALUES (?, ?, 0)');
            if ($stmt) {
                $g = 'General';
                mysqli_stmt_bind_param($stmt, 'is', $uid, $g);
                if (mysqli_stmt_execute($stmt)) {
                    $tid = (int)mysqli_insert_id($conn);
                }
                mysqli_stmt_close($stmt);
            }
        }
        if ($tid > 0) {
            @mysqli_query($conn, 'UPDATE preweek_videos SET preweek_topic_id=' . $tid . ' WHERE preweek_unit_id=' . $uid . ' AND preweek_topic_id IS NULL');
            @mysqli_query($conn, 'UPDATE preweek_handouts SET preweek_topic_id=' . $tid . ' WHERE preweek_unit_id=' . $uid . ' AND preweek_topic_id IS NULL');
        }
    }
}

// One-time backfill: attach orphan rows to the current year bucket
$needMigrate = false;
$o = @mysqli_query($conn, 'SELECT 1 FROM preweek_videos WHERE preweek_unit_id IS NULL LIMIT 1');
if ($o && mysqli_fetch_assoc($o)) {
    $needMigrate = true;
}
if (!$needMigrate) {
    $o2 = @mysqli_query($conn, 'SELECT 1 FROM preweek_handouts WHERE preweek_unit_id IS NULL LIMIT 1');
    if ($o2 && mysqli_fetch_assoc($o2)) {
        $needMigrate = true;
    }
}
if ($needMigrate) {
    $bucketId = ereview_ensure_preweek_unit_for_year($conn, $curYear);
    if ($bucketId > 0) {
        @mysqli_query($conn, 'UPDATE preweek_videos SET preweek_unit_id=' . $bucketId . ', subject_id=0 WHERE preweek_unit_id IS NULL');
        @mysqli_query($conn, 'UPDATE preweek_handouts SET preweek_unit_id=' . $bucketId . ', subject_id=0 WHERE preweek_unit_id IS NULL');
    }
}

// Any materials with unit but still no topic (e.g. after orphan bucket attach): assign "General"
$needTopic2 = @mysqli_query($conn, 'SELECT 1 FROM preweek_videos WHERE preweek_unit_id IS NOT NULL AND preweek_unit_id > 0 AND preweek_topic_id IS NULL LIMIT 1');
$runTopic2 = ($needTopic2 && mysqli_fetch_assoc($needTopic2));
if (!$runTopic2) {
    $needTopic2h = @mysqli_query($conn, 'SELECT 1 FROM preweek_handouts WHERE preweek_unit_id IS NOT NULL AND preweek_unit_id > 0 AND preweek_topic_id IS NULL LIMIT 1');
    $runTopic2 = ($needTopic2h && mysqli_fetch_assoc($needTopic2h));
}
if ($runTopic2) {
    $uq2 = @mysqli_query($conn, 'SELECT DISTINCT preweek_unit_id AS uid FROM preweek_videos WHERE preweek_unit_id IS NOT NULL AND preweek_unit_id > 0 AND preweek_topic_id IS NULL
      UNION SELECT DISTINCT preweek_unit_id FROM preweek_handouts WHERE preweek_unit_id IS NOT NULL AND preweek_unit_id > 0 AND preweek_topic_id IS NULL');
    if ($uq2) {
        while ($rw = mysqli_fetch_assoc($uq2)) {
            $uid = (int)($rw['uid'] ?? 0);
            if ($uid <= 0) {
                continue;
            }
            $tid = 0;
            $find = @mysqli_query($conn, 'SELECT preweek_topic_id FROM preweek_topics WHERE preweek_unit_id=' . $uid . " AND title='General' LIMIT 1");
            if ($find && ($fr = mysqli_fetch_assoc($find))) {
                $tid = (int)($fr['preweek_topic_id'] ?? 0);
            }
            if ($tid <= 0) {
                $stmt = mysqli_prepare($conn, 'INSERT INTO preweek_topics (preweek_unit_id, title, sort_order) VALUES (?, ?, 0)');
                if ($stmt) {
                    $g = 'General';
                    mysqli_stmt_bind_param($stmt, 'is', $uid, $g);
                    if (mysqli_stmt_execute($stmt)) {
                        $tid = (int)mysqli_insert_id($conn);
                    }
                    mysqli_stmt_close($stmt);
                }
            }
            if ($tid > 0) {
                @mysqli_query($conn, 'UPDATE preweek_videos SET preweek_topic_id=' . $tid . ' WHERE preweek_unit_id=' . $uid . ' AND preweek_topic_id IS NULL');
                @mysqli_query($conn, 'UPDATE preweek_handouts SET preweek_topic_id=' . $tid . ' WHERE preweek_unit_id=' . $uid . ' AND preweek_topic_id IS NULL');
            }
        }
    }
}
