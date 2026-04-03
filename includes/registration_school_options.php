<?php
/**
 * Registration school dropdown: merge preset schools, catalog, and distinct schools
 * from enrolled/reviewee students (users + pending_registrations).
 */

if (!function_exists('ereview_ensure_registration_school_catalog')) {
    function ereview_ensure_registration_school_catalog($conn): void {
        if (!$conn) {
            return;
        }
        @mysqli_query($conn, "
            CREATE TABLE IF NOT EXISTS `registration_school_catalog` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `school_name` VARCHAR(255) NOT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_school_name` (`school_name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    /**
     * @return string[]
     */
    function ereview_registration_school_presets(): array {
        return ['SLU', 'UB', 'BSU'];
    }

    /**
     * Case-insensitive unique merge of school display names.
     *
     * @param array<int,string> $names
     * @return array<int,string>
     */
    function ereview_registration_school_merge_unique(array $names): array {
        $seen = [];
        $out = [];
        foreach ($names as $n) {
            $t = trim((string) $n);
            if ($t === '' || strcasecmp($t, 'Other') === 0) {
                continue;
            }
            $k = mb_strtolower($t, 'UTF-8');
            if (isset($seen[$k])) {
                continue;
            }
            $seen[$k] = true;
            $out[] = $t;
        }
        return $out;
    }

    /**
     * Distinct schools from student reviewee rows (same source as admin student list).
     *
     * @return array<int,string>
     */
    function ereview_registration_school_collect_from_students($conn): array {
        $names = [];
        $hasUsers = @mysqli_query($conn, "SHOW TABLES LIKE 'users'");
        if (!$hasUsers || !mysqli_fetch_row($hasUsers)) {
            return $names;
        }
        $res = @mysqli_query($conn, "
            SELECT DISTINCT TRIM(school) AS nm FROM users
            WHERE role = 'student' AND school IS NOT NULL AND TRIM(school) <> '' AND TRIM(school) <> 'Other'
        ");
        if ($res) {
            while ($row = mysqli_fetch_assoc($res)) {
                if (!empty($row['nm'])) {
                    $names[] = (string) $row['nm'];
                }
            }
        }
        $res2 = @mysqli_query($conn, "
            SELECT DISTINCT TRIM(school_other) AS nm FROM users
            WHERE role = 'student' AND school = 'Other'
              AND school_other IS NOT NULL AND TRIM(school_other) <> ''
        ");
        if ($res2) {
            while ($row = mysqli_fetch_assoc($res2)) {
                if (!empty($row['nm'])) {
                    $names[] = (string) $row['nm'];
                }
            }
        }
        $pr = @mysqli_query($conn, "SHOW TABLES LIKE 'pending_registrations'");
        if ($pr && mysqli_fetch_row($pr)) {
            $res3 = @mysqli_query($conn, "
                SELECT DISTINCT TRIM(school) AS nm FROM pending_registrations
                WHERE school IS NOT NULL AND TRIM(school) <> '' AND TRIM(school) <> 'Other'
            ");
            if ($res3) {
                while ($row = mysqli_fetch_assoc($res3)) {
                    if (!empty($row['nm'])) {
                        $names[] = (string) $row['nm'];
                    }
                }
            }
            $res4 = @mysqli_query($conn, "
                SELECT DISTINCT TRIM(school_other) AS nm FROM pending_registrations
                WHERE school = 'Other' AND school_other IS NOT NULL AND TRIM(school_other) <> ''
            ");
            if ($res4) {
                while ($row = mysqli_fetch_assoc($res4)) {
                    if (!empty($row['nm'])) {
                        $names[] = (string) $row['nm'];
                    }
                }
            }
        }
        return ereview_registration_school_merge_unique($names);
    }

    /**
     * @return array<int,string>
     */
    function ereview_registration_school_collect_from_catalog($conn): array {
        $names = [];
        ereview_ensure_registration_school_catalog($conn);
        $res = @mysqli_query($conn, 'SELECT school_name FROM registration_school_catalog ORDER BY school_name ASC');
        if ($res) {
            while ($row = mysqli_fetch_assoc($res)) {
                if (!empty($row['school_name'])) {
                    $names[] = trim((string) $row['school_name']);
                }
            }
        }
        return ereview_registration_school_merge_unique($names);
    }

    /**
     * Full ordered list for <select>: presets first, then rest A–Z, then "Other".
     *
     * @return array<int,string>
     */
    function ereview_get_registration_school_dropdown_options($conn): array {
        $presets = ereview_registration_school_presets();
        $fromDb = ereview_registration_school_collect_from_students($conn);
        $fromCatalog = ereview_registration_school_collect_from_catalog($conn);
        $merged = ereview_registration_school_merge_unique(array_merge($fromDb, $fromCatalog));

        $presetSet = [];
        foreach ($presets as $p) {
            $presetSet[mb_strtolower($p, 'UTF-8')] = $p;
        }

        $orderedPresets = [];
        foreach ($presets as $p) {
            $orderedPresets[] = $p;
        }

        $extras = [];
        foreach ($merged as $name) {
            $lk = mb_strtolower($name, 'UTF-8');
            if (isset($presetSet[$lk])) {
                continue;
            }
            $extras[] = $name;
        }
        sort($extras, SORT_NATURAL | SORT_FLAG_CASE);

        $out = array_merge($orderedPresets, $extras);
        $out = ereview_registration_school_merge_unique($out);
        $out[] = 'Other';
        return $out;
    }

    /**
     * Persist a school label so future registrants see it in the dropdown immediately.
     */
    function ereview_registration_school_catalog_save($conn, string $school, ?string $school_other): void {
        ereview_ensure_registration_school_catalog($conn);
        $label = '';
        if ($school === 'Other') {
            $label = trim((string) $school_other);
        } else {
            $label = trim($school);
        }
        if ($label === '' || strcasecmp($label, 'Other') === 0) {
            return;
        }
        $stmt = @mysqli_prepare($conn, 'INSERT IGNORE INTO registration_school_catalog (school_name) VALUES (?)');
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 's', $label);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
    }

    /**
     * Validate submitted school against current allowed dropdown values.
     */
    function ereview_registration_school_is_submitted_value_allowed($conn, string $school, ?string $school_other): bool {
        $school = trim($school);
        if ($school === '') {
            return false;
        }
        $allowed = ereview_get_registration_school_dropdown_options($conn);
        $allowedMap = [];
        foreach ($allowed as $a) {
            $allowedMap[mb_strtolower(trim($a), 'UTF-8')] = true;
        }
        if ($school === 'Other') {
            $o = trim((string) $school_other);
            return $o !== '';
        }
        return isset($allowedMap[mb_strtolower($school, 'UTF-8')]);
    }
}
