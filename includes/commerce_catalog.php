<?php
/**
 * Commerce catalog helpers (Phase 3).
 *
 * Architecture (approved):
 * - Enrollment modes: package | by_topic | free_access (not SKUs).
 * - full_lms packages (Self-Paced, Pure Online, Hybrid, …): access_scope=full_lms;
 *   package_content_items NOT required; distinguish via duration + package_feature_items.
 * - mapped packages: package_content_items required at fulfill time.
 * - OCR/AI (later) = receipt verification only — not GCash API settlement proof.
 * - Free Access never creates payment rows.
 * - Repeat purchase stacks: new_ends_at = MAX(NOW(), current_effective_ends_at) + duration.
 * - One payment fulfill once (fulfilled_at); second purchase = new payment/items/grants.
 *
 * Runtime authorization remains student_content_permissions (SCA).
 */

declare(strict_types=1);

/** @return list<string> */
function commerce_sca_content_types(): array
{
    return [
        'full_lms', 'subject', 'lesson', 'quiz', 'video', 'handout',
        'preboard_subject', 'preboard_set', 'preweek_unit', 'preweek_topic', 'test_bank',
    ];
}

function commerce_is_valid_sca_type(string $type): bool
{
    return in_array($type, commerce_sca_content_types(), true);
}

/**
 * @param list<array{content_type?:string,type?:string,content_id?:int|string,id?:int|string}> $raw
 * @return list<array{content_type:string,content_id:int}>
 */
function commerce_normalize_content_map(array $raw): array
{
    if (function_exists('sca_normalize_permission_payload')) {
        return sca_normalize_permission_payload($raw);
    }
    $out = [];
    foreach ($raw as $item) {
        if (!is_array($item)) {
            continue;
        }
        $type = (string) ($item['content_type'] ?? $item['type'] ?? '');
        if (!commerce_is_valid_sca_type($type)) {
            continue;
        }
        $cid = (int) ($item['content_id'] ?? $item['id'] ?? 0);
        if ($type !== 'full_lms' && $cid <= 0) {
            continue;
        }
        if ($type === 'full_lms') {
            $cid = 0;
        }
        $out[] = ['content_type' => $type, 'content_id' => $cid];
    }
    return $out;
}

function commerce_pesos_to_centavos($pesos): int
{
    if (is_string($pesos)) {
        $pesos = str_replace([',', '₱', 'PHP', ' '], '', $pesos);
    }
    return (int) round(((float) $pesos) * 100);
}

function commerce_centavos_to_pesos_display(int $centavos): string
{
    return number_format($centavos / 100, 2, '.', ',');
}

function commerce_schema_ready(mysqli $conn): bool
{
    $r = @mysqli_query($conn, "SHOW TABLES LIKE 'sellable_packages'");
    $ok = $r && mysqli_num_rows($r) > 0;
    if ($r) {
        mysqli_free_result($r);
    }
    return $ok;
}

/** @return list<array<string,mixed>> */
function commerce_list_packages(mysqli $conn, bool $purchasableOnly = false): array
{
    $sql = 'SELECT * FROM sellable_packages';
    if ($purchasableOnly) {
        $sql .= ' WHERE is_active = 1 AND is_purchasable = 1';
    }
    $sql .= ' ORDER BY sort_order ASC, name ASC';
    $res = mysqli_query($conn, $sql);
    $rows = [];
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $rows[] = $row;
        }
        mysqli_free_result($res);
    }
    return $rows;
}

/** @return array<string,mixed>|null */
function commerce_get_package(mysqli $conn, int $packageId): ?array
{
    if ($packageId <= 0) {
        return null;
    }
    $stmt = mysqli_prepare($conn, 'SELECT * FROM sellable_packages WHERE package_id = ? LIMIT 1');
    if (!$stmt) {
        return null;
    }
    mysqli_stmt_bind_param($stmt, 'i', $packageId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    return $row ?: null;
}

/** @return list<array<string,mixed>> */
function commerce_get_package_content(mysqli $conn, int $packageId): array
{
    $stmt = mysqli_prepare(
        $conn,
        'SELECT * FROM package_content_items WHERE package_id = ? ORDER BY sort_order, package_content_item_id'
    );
    if (!$stmt) {
        return [];
    }
    mysqli_stmt_bind_param($stmt, 'i', $packageId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $rows = [];
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $rows[] = $row;
        }
        mysqli_free_result($res);
    }
    mysqli_stmt_close($stmt);
    return $rows;
}

/** @return list<array<string,mixed>> */
function commerce_get_package_features(mysqli $conn, int $packageId): array
{
    $stmt = mysqli_prepare(
        $conn,
        'SELECT * FROM package_feature_items WHERE package_id = ? ORDER BY sort_order, package_feature_item_id'
    );
    if (!$stmt) {
        return [];
    }
    mysqli_stmt_bind_param($stmt, 'i', $packageId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $rows = [];
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $rows[] = $row;
        }
        mysqli_free_result($res);
    }
    mysqli_stmt_close($stmt);
    return $rows;
}

/**
 * Replace package content map. For access_scope=full_lms, map may be empty.
 *
 * @param list<array{content_type:string,content_id:int}> $items
 */
function commerce_save_package_content(mysqli $conn, int $packageId, array $items): bool
{
    $del = mysqli_prepare($conn, 'DELETE FROM package_content_items WHERE package_id = ?');
    if (!$del) {
        return false;
    }
    mysqli_stmt_bind_param($del, 'i', $packageId);
    mysqli_stmt_execute($del);
    mysqli_stmt_close($del);

    $items = commerce_normalize_content_map($items);
    if ($items === []) {
        return true;
    }
    $ins = mysqli_prepare(
        $conn,
        'INSERT INTO package_content_items (package_id, content_type, content_id, sort_order) VALUES (?, ?, ?, ?)'
    );
    if (!$ins) {
        return false;
    }
    $sort = 0;
    foreach ($items as $item) {
        $type = $item['content_type'];
        $cid = (int) $item['content_id'];
        mysqli_stmt_bind_param($ins, 'isii', $packageId, $type, $cid, $sort);
        mysqli_stmt_execute($ins);
        $sort++;
    }
    mysqli_stmt_close($ins);
    return true;
}

/**
 * @param list<array{feature_key:string,feature_label:string,feature_description?:string,is_included?:int|bool}> $features
 */
function commerce_save_package_features(mysqli $conn, int $packageId, array $features): bool
{
    $del = mysqli_prepare($conn, 'DELETE FROM package_feature_items WHERE package_id = ?');
    if (!$del) {
        return false;
    }
    mysqli_stmt_bind_param($del, 'i', $packageId);
    mysqli_stmt_execute($del);
    mysqli_stmt_close($del);

    if ($features === []) {
        return true;
    }
    $ins = mysqli_prepare(
        $conn,
        'INSERT INTO package_feature_items (package_id, feature_key, feature_label, feature_description, is_included, sort_order)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    if (!$ins) {
        return false;
    }
    $sort = 0;
    $seen = [];
    foreach ($features as $f) {
        $key = preg_replace('/[^a-z0-9_]+/i', '_', strtolower(trim((string) ($f['feature_key'] ?? '')))) ?: '';
        $label = trim((string) ($f['feature_label'] ?? ''));
        if ($key === '' || $label === '' || isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $desc = trim((string) ($f['feature_description'] ?? ''));
        $incl = !empty($f['is_included']) ? 1 : 0;
        mysqli_stmt_bind_param($ins, 'isssii', $packageId, $key, $label, $desc, $incl, $sort);
        mysqli_stmt_execute($ins);
        $sort++;
    }
    mysqli_stmt_close($ins);
    return true;
}

/** @return array<string,mixed> */
function commerce_get_payment_settings(mysqli $conn): array
{
    $res = mysqli_query($conn, 'SELECT * FROM payment_settings WHERE setting_id = 1 LIMIT 1');
    $row = $res ? mysqli_fetch_assoc($res) : null;
    if ($res) {
        mysqli_free_result($res);
    }
    return $row ?: [
        'setting_id' => 1,
        'gcash_account_name' => '',
        'gcash_number' => '',
        'gcash_qr_path' => null,
        'payment_instructions' => null,
        'ocr_confidence_threshold' => 85.00,
        'receipt_max_age_days' => 7,
        'vision_fallback_enabled' => 0,
    ];
}

/**
 * Subjects + lessons for mapped package picker (existing LMS content only).
 *
 * @return list<array{subject_id:int,subject_name:string,lessons:list<array{lesson_id:int,title:string}>}>
 */
function commerce_subject_lesson_picker(mysqli $conn): array
{
    $out = [];
    $sq = mysqli_query($conn, "SELECT subject_id, subject_name FROM subjects WHERE status = 'active' ORDER BY subject_name");
    if (!$sq) {
        return [];
    }
    while ($s = mysqli_fetch_assoc($sq)) {
        $sid = (int) $s['subject_id'];
        $lessons = [];
        $lq = mysqli_prepare($conn, 'SELECT lesson_id, title FROM lessons WHERE subject_id = ? ORDER BY title');
        if ($lq) {
            mysqli_stmt_bind_param($lq, 'i', $sid);
            mysqli_stmt_execute($lq);
            $lr = mysqli_stmt_get_result($lq);
            if ($lr) {
                while ($les = mysqli_fetch_assoc($lr)) {
                    $lessons[] = [
                        'lesson_id' => (int) $les['lesson_id'],
                        'title' => (string) $les['title'],
                    ];
                }
                mysqli_free_result($lr);
            }
            mysqli_stmt_close($lq);
        }
        $out[] = [
            'subject_id' => $sid,
            'subject_name' => (string) $s['subject_name'],
            'lessons' => $lessons,
        ];
    }
    mysqli_free_result($sq);
    return $out;
}

/**
 * Purchasable packages for registration UI (DB-only; includes features + mapped content labels).
 *
 * @return list<array<string,mixed>>
 */
function commerce_catalog_packages_for_registration(mysqli $conn): array
{
    if (!commerce_schema_ready($conn)) {
        return [];
    }
    $packages = commerce_list_packages($conn, true);
    $out = [];
    foreach ($packages as $p) {
        $pid = (int) $p['package_id'];
        $features = [];
        foreach (commerce_get_package_features($conn, $pid) as $f) {
            if (empty($f['is_included'])) {
                continue;
            }
            $features[] = [
                'key' => (string) $f['feature_key'],
                'label' => (string) $f['feature_label'],
                'description' => (string) ($f['feature_description'] ?? ''),
            ];
        }
        $mapped = [];
        if (($p['access_scope'] ?? '') === 'mapped') {
            foreach (commerce_get_package_content($conn, $pid) as $c) {
                $mapped[] = [
                    'content_type' => (string) $c['content_type'],
                    'content_id' => (int) $c['content_id'],
                    'label' => commerce_content_label($conn, (string) $c['content_type'], (int) $c['content_id']),
                ];
            }
        }
        $out[] = [
            'package_id' => $pid,
            'code' => (string) $p['code'],
            'name' => (string) $p['name'],
            'description' => (string) ($p['description'] ?? ''),
            'price_centavos' => (int) $p['price_centavos'],
            'price_display' => '₱' . commerce_centavos_to_pesos_display((int) $p['price_centavos']),
            'currency' => (string) ($p['currency'] ?? 'PHP'),
            'duration_value' => (int) $p['duration_value'],
            'duration_unit' => (string) $p['duration_unit'],
            'duration_label' => (int) $p['duration_value'] . ' ' . ((string) $p['duration_unit']) . (((int) $p['duration_value'] === 1) ? '' : 's'),
            'access_scope' => (string) $p['access_scope'],
            'features' => $features,
            'mapped_content' => $mapped,
        ];
    }
    return $out;
}

function commerce_content_label(mysqli $conn, string $type, int $id): string
{
    if ($type === 'full_lms') {
        return 'Full LMS';
    }
    $map = [
        'subject' => ['subjects', 'subject_id', 'subject_name'],
        'lesson' => ['lessons', 'lesson_id', 'title'],
        'quiz' => ['quizzes', 'quiz_id', 'title'],
        'video' => ['lesson_videos', 'video_id', 'video_title'],
        'handout' => ['lesson_handouts', 'handout_id', 'handout_title'],
        'preboard_subject' => ['preboards_subjects', 'preboards_subject_id', 'name'],
        'preboard_set' => ['preboards_sets', 'preboards_set_id', 'set_label'],
        'preweek_unit' => ['preweek_units', 'preweek_unit_id', 'title'],
        'preweek_topic' => ['preweek_topics', 'preweek_topic_id', 'title'],
        'test_bank' => ['test_bank', 'id', 'title'],
    ];
    if (!isset($map[$type]) || $id <= 0) {
        return $type . ' #' . $id;
    }
    [$table, $pk, $labelCol] = $map[$type];
    $sql = "SELECT `{$labelCol}` AS lbl FROM `{$table}` WHERE `{$pk}` = ? LIMIT 1";
    $stmt = @mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return $type . ' #' . $id;
    }
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    $lbl = trim((string) ($row['lbl'] ?? ''));
    return $lbl !== '' ? $lbl : ($type . ' #' . $id);
}

/**
 * Purchasable topics grouped by subject for registration.
 *
 * @return list<array{subject_id:int,subject_name:string,topics:list<array<string,mixed>>}>
 */
function commerce_catalog_topics_for_registration(mysqli $conn): array
{
    if (!commerce_schema_ready($conn)) {
        return [];
    }
    $sql = "SELECT l.lesson_id, l.title, l.price_centavos, l.access_duration_value, l.access_duration_unit,
                   s.subject_id, s.subject_name
            FROM lessons l
            INNER JOIN subjects s ON s.subject_id = l.subject_id AND s.status = 'active'
            WHERE l.is_purchasable = 1
              AND l.price_centavos IS NOT NULL
              AND l.price_centavos > 0
              AND l.access_duration_value IS NOT NULL
              AND l.access_duration_value > 0
              AND l.access_duration_unit IS NOT NULL
              AND l.access_duration_unit IN ('day','month')
            ORDER BY s.subject_name ASC, l.title ASC";
    $res = mysqli_query($conn, $sql);
    $bySubject = [];
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $sid = (int) $row['subject_id'];
            if (!isset($bySubject[$sid])) {
                $bySubject[$sid] = [
                    'subject_id' => $sid,
                    'subject_name' => (string) $row['subject_name'],
                    'topics' => [],
                ];
            }
            $centavos = (int) $row['price_centavos'];
            $durVal = (int) $row['access_duration_value'];
            $durUnit = (string) $row['access_duration_unit'];
            $bySubject[$sid]['topics'][] = [
                'lesson_id' => (int) $row['lesson_id'],
                'title' => (string) $row['title'],
                'price_centavos' => $centavos,
                'price_display' => '₱' . commerce_centavos_to_pesos_display($centavos),
                'duration_value' => $durVal,
                'duration_unit' => $durUnit,
                'duration_label' => $durVal . ' ' . $durUnit . ($durVal === 1 ? '' : 's'),
            ];
        }
        mysqli_free_result($res);
    }
    return array_values($bySubject);
}

/**
 * Whether a SCA content_type + content_id refers to an existing LMS entity.
 * full_lms must use content_id = 0.
 */
function commerce_content_entity_exists(mysqli $conn, string $type, int $contentId): bool
{
    if (!commerce_is_valid_sca_type($type)) {
        return false;
    }
    if ($type === 'full_lms') {
        return $contentId === 0;
    }
    if ($contentId <= 0) {
        return false;
    }

    $map = [
        'subject' => ['subjects', 'subject_id'],
        'lesson' => ['lessons', 'lesson_id'],
        'quiz' => ['quizzes', 'quiz_id'],
        'video' => ['lesson_videos', 'video_id'],
        'handout' => ['lesson_handouts', 'handout_id'],
        'preboard_subject' => ['preboards_subjects', 'preboards_subject_id'],
        'preboard_set' => ['preboards_sets', 'preboards_set_id'],
        'preweek_unit' => ['preweek_units', 'preweek_unit_id'],
        'preweek_topic' => ['preweek_topics', 'preweek_topic_id'],
        'test_bank' => ['test_bank', 'id'],
    ];
    if (!isset($map[$type])) {
        return false;
    }
    [$table, $pk] = $map[$type];
    // Ensure table exists (preweek/preboards may be absent on older installs).
    $tq = @mysqli_query($conn, "SHOW TABLES LIKE '" . mysqli_real_escape_string($conn, $table) . "'");
    if (!$tq || mysqli_num_rows($tq) === 0) {
        if ($tq) {
            mysqli_free_result($tq);
        }
        return false;
    }
    if ($tq) {
        mysqli_free_result($tq);
    }
    $sql = "SELECT 1 FROM `{$table}` WHERE `{$pk}` = ? LIMIT 1";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return false;
    }
    mysqli_stmt_bind_param($stmt, 'i', $contentId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $ok = $res && (bool) mysqli_fetch_row($res);
    mysqli_stmt_close($stmt);
    return $ok;
}

/**
 * Validate a list of package content mappings (reusable for Phase 5+).
 *
 * @param list<array{content_type?:string,content_id?:int|string}|array<string,mixed>> $items
 * @return array{ok:bool,error?:string,items?:list<array{content_type:string,content_id:int}>}
 */
function commerce_validate_package_content_map(mysqli $conn, array $items): array
{
    $normalized = commerce_normalize_content_map($items);
    if ($normalized === []) {
        // Distinguish "empty after normalize" vs submitted junk
        if ($items === []) {
            return ['ok' => false, 'error' => 'Mapped packages require at least one content mapping.'];
        }
        return ['ok' => false, 'error' => 'Mapped package contains invalid content types or IDs.'];
    }

    foreach ($normalized as $row) {
        $type = $row['content_type'];
        $cid = (int) $row['content_id'];
        if (!commerce_is_valid_sca_type($type)) {
            return ['ok' => false, 'error' => 'Mapped package references an unsupported content type: ' . $type];
        }
        if (!commerce_content_entity_exists($conn, $type, $cid)) {
            return [
                'ok' => false,
                'error' => 'Mapped package references missing or invalid LMS content (' . $type . ' #' . $cid . ').',
            ];
        }
    }

    return ['ok' => true, 'items' => $normalized];
}

/**
 * Server-side validate package selection. Returns package row or null + error.
 *
 * @return array{ok:bool,error?:string,package?:array<string,mixed>}
 */
function commerce_validate_package_selection(mysqli $conn, int $packageId): array
{
    if ($packageId <= 0) {
        return ['ok' => false, 'error' => 'Please select a package.'];
    }
    $pkg = commerce_get_package($conn, $packageId);
    if (!$pkg || empty($pkg['is_active']) || empty($pkg['is_purchasable'])) {
        return ['ok' => false, 'error' => 'Selected package is not available. Please choose another.'];
    }
    $scope = (string) ($pkg['access_scope'] ?? 'full_lms');
    if ($scope === 'full_lms') {
        // Full LMS packages do not require package_content_items.
        return ['ok' => true, 'package' => $pkg];
    }
    if ($scope === 'mapped') {
        $items = commerce_get_package_content($conn, $packageId);
        $mapCheck = commerce_validate_package_content_map($conn, $items);
        if (!$mapCheck['ok']) {
            return ['ok' => false, 'error' => $mapCheck['error'] ?? 'Selected package has invalid content mappings.'];
        }
        return ['ok' => true, 'package' => $pkg];
    }
    return ['ok' => false, 'error' => 'Selected package has an unsupported access scope.'];
}

/**
 * Server-side validate topic IDs and recompute total from DB.
 *
 * @param list<int|string> $lessonIds
 * @return array{ok:bool,error?:string,lessons?:list<array<string,mixed>>,total_centavos?:int}
 */
function commerce_validate_topic_selection(mysqli $conn, array $lessonIds): array
{
    $ids = [];
    foreach ($lessonIds as $id) {
        $id = (int) $id;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }
    $ids = array_values($ids);
    if ($ids === []) {
        return ['ok' => false, 'error' => 'Please select at least one topic.'];
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $sql = "SELECT l.lesson_id, l.title, l.price_centavos, l.access_duration_value, l.access_duration_unit,
                   l.is_purchasable, s.subject_id, s.subject_name, s.status AS subject_status
            FROM lessons l
            INNER JOIN subjects s ON s.subject_id = l.subject_id
            WHERE l.lesson_id IN ($placeholders)";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Could not validate topic selection.'];
    }
    mysqli_stmt_bind_param($stmt, $types, ...$ids);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $found = [];
    while ($res && $row = mysqli_fetch_assoc($res)) {
        $found[(int) $row['lesson_id']] = $row;
    }
    mysqli_stmt_close($stmt);

    $lessons = [];
    $total = 0;
    foreach ($ids as $id) {
        if (!isset($found[$id])) {
            return ['ok' => false, 'error' => 'One or more selected topics are invalid.'];
        }
        $row = $found[$id];
        $centavos = $row['price_centavos'] === null ? null : (int) $row['price_centavos'];
        if (($row['subject_status'] ?? '') !== 'active'
            || empty($row['is_purchasable'])
            || $centavos === null
            || $centavos <= 0
            || $row['access_duration_value'] === null
            || (int) $row['access_duration_value'] <= 0
            || $row['access_duration_unit'] === null
            || !in_array((string) $row['access_duration_unit'], ['day', 'month'], true)
        ) {
            return ['ok' => false, 'error' => 'One or more selected topics are no longer available for purchase.'];
        }
        $total += $centavos;
        $lessons[] = [
            'lesson_id' => $id,
            'title' => (string) $row['title'],
            'subject_id' => (int) $row['subject_id'],
            'subject_name' => (string) $row['subject_name'],
            'price_centavos' => $centavos,
            'duration_value' => (int) $row['access_duration_value'],
            'duration_unit' => (string) $row['access_duration_unit'],
        ];
    }

    return ['ok' => true, 'lessons' => $lessons, 'total_centavos' => $total];
}

function commerce_next_free_access_ref(mysqli $conn): string
{
    $year = date('Y');
    $prefix = 'FAR-' . $year . '-';
    $res = @mysqli_query(
        $conn,
        "SELECT request_ref FROM free_access_requests WHERE request_ref LIKE '" . mysqli_real_escape_string($conn, $prefix) . "%' ORDER BY request_id DESC LIMIT 1"
    );
    $next = 1;
    if ($res && ($row = mysqli_fetch_assoc($res))) {
        $tail = (int) substr((string) $row['request_ref'], -6);
        $next = $tail + 1;
        mysqli_free_result($res);
    }
    return $prefix . str_pad((string) $next, 6, '0', STR_PAD_LEFT);
}
