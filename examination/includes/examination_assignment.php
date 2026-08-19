<?php
declare(strict_types=1);

/**
 * Shared Examination normalization helpers (read-only domain layer).
 * Assignment eligibility for take flows remains in diagnostic_exam_helpers.php.
 */

require_once __DIR__ . '/diagnostic_exam_helpers.php';

function examination_normalize_exam_type(string $type): string
{
    $t = strtolower(trim($type));
    if ($t === 'college_exam') {
        return 'regular';
    }

    return in_array($t, ['regular', 'diagnostic'], true) ? $t : '';
}

function examination_exam_type_label(string $type): string
{
    return examination_normalize_exam_type($type) === 'diagnostic' ? 'Diagnostic Exam' : 'Regular Exam';
}

function examination_normalize_examinee_scope(string $scope): string
{
    return diagnostic_exam_normalize_examinee_scope($scope);
}

function examination_normalize_assignment_mode(string $mode): string
{
    return diagnostic_exam_normalize_assignment_mode($mode);
}

function examination_examinee_scope_label(string $scope): string
{
    return diagnostic_exam_examinee_scope_label($scope);
}

function examination_assignment_mode_label(string $mode): string
{
    return diagnostic_exam_assignment_mode_label($mode);
}

function examination_assignment_summary(string $mode, int $sectionCount, int $userCount): string
{
    return examination_audience_display_summary($mode, [], $userCount);
}

function examination_audience_display_summary(string $mode, array $sections, int $userCount): string
{
    $mode = examination_normalize_assignment_mode($mode);
    $sections = array_values(array_filter(array_map(static fn($s) => trim((string)$s), $sections), static fn($s) => $s !== ''));

    return match ($mode) {
        'all' => 'All students',
        'users' => $userCount . ' selected student' . ($userCount === 1 ? '' : 's'),
        'sections_and_users' => ($sections !== [] ? implode(', ', $sections) : 'No sections')
            . ' + ' . $userCount . ' additional student' . ($userCount === 1 ? '' : 's'),
        default => $sections !== [] ? implode(', ', $sections) : 'No sections selected',
    };
}

function examination_format_schedule_line(?string $from, ?string $deadline): string
{
    $parts = [];
    if ($from !== null && trim($from) !== '' && !preg_match('/^0000-00-00/', $from)) {
        $parts[] = 'From ' . date('M j, Y g:i A', strtotime($from));
    }
    if ($deadline !== null && trim($deadline) !== '' && !preg_match('/^0000-00-00/', $deadline)) {
        $parts[] = 'Until ' . date('M j, Y g:i A', strtotime($deadline));
    }

    return $parts !== [] ? implode(' · ', $parts) : 'No schedule set';
}

function examination_time_limit_from_post(array $post): int
{
    $h = max(0, (int)($post['time_limit_hours'] ?? 0));
    $m = max(0, min(59, (int)($post['time_limit_minutes'] ?? 0)));

    return min(999 * 3600 + 59 * 60, $h * 3600 + $m * 60);
}

function examination_format_time_limit_parts(int $seconds): array
{
    $seconds = max(0, $seconds);

    return [
        'hours' => intdiv($seconds, 3600),
        'minutes' => intdiv($seconds % 3600, 60),
    ];
}

function examination_parse_sections_from_post(array $post): array
{
    global $conn;
    $sections = [];
    $inputs = $post['sections'] ?? [];
    if (!is_array($inputs)) {
        return $sections;
    }
    $masterFile = __DIR__ . '/college_sections.php';
    $hasMaster = is_file($masterFile);
    if ($hasMaster) {
        require_once $masterFile;
    }
    foreach ($inputs as $value) {
        $trimmed = trim((string)$value);
        if ($trimmed === '') {
            continue;
        }
        if ($hasMaster && isset($conn) && $conn instanceof mysqli && function_exists('college_sections_resolve_active_name')) {
            $canonical = college_sections_resolve_active_name($conn, $trimmed);
            if ($canonical === null) {
                // Allow legacy values already assigned to this exam so edits don't wipe them.
                $canonical = $trimmed;
            }
            $trimmed = $canonical;
        }
        if (!in_array($trimmed, $sections, true)) {
            $sections[] = $trimmed;
        }
    }

    return $sections;
}

function examination_parse_user_ids_from_post(array $post): array
{
    $ids = [];
    $raw = $post['user_ids'] ?? [];
    if (!is_array($raw)) {
        return $ids;
    }
    foreach ($raw as $id) {
        $iv = (int)$id;
        if ($iv > 0) {
            $ids[] = $iv;
        }
    }

    return array_values(array_unique($ids));
}

function examination_format_time_limit_display(int $seconds): string
{
    if ($seconds <= 0) {
        return '—';
    }
    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    if ($hours > 0 && $minutes > 0) {
        return $hours . 'h ' . $minutes . 'm';
    }
    if ($hours > 0) {
        return $hours . 'h';
    }

    return $minutes . 'm';
}

function examination_parse_subject_ids_from_post(array $post): array
{
    $ids = [];
    $raw = $post['subject_ids'] ?? [];
    if (!is_array($raw)) {
        return $ids;
    }
    foreach ($raw as $id) {
        $iv = (int)$id;
        if ($iv > 0) {
            $ids[] = $iv;
        }
    }

    return array_values(array_unique($ids));
}
