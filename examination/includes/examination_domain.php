<?php
declare(strict_types=1);

/**
 * Examination domain layer — unified read/list/normalize over type adapters.
 * Phase 1A: no writes, no UI routing, no take-flow changes.
 */

require_once __DIR__ . '/examination_assignment.php';
require_once __DIR__ . '/examination_type_interface.php';
require_once __DIR__ . '/types/examination_type_regular.php';
require_once __DIR__ . '/types/examination_type_diagnostic.php';
require_once __DIR__ . '/college_exam_helpers.php';

function examination_domain_nullable_datetime(mixed $raw): ?string
{
    if ($raw === null) {
        return null;
    }
    $s = trim((string)$raw);
    if ($s === '' || preg_match('/^0000-00-00(\s00:00:00)?$/', $s)) {
        return null;
    }

    return $s;
}

function examination_domain_window_state(array $row, string $nowSql, bool $isFinished): string
{
    if ($isFinished) {
        return 'finished';
    }
    if (empty($row['is_published'])) {
        return 'draft';
    }
    if (!empty($row['available_from']) && (string)$row['available_from'] > $nowSql) {
        return 'scheduled';
    }
    if (!empty($row['deadline']) && (string)$row['deadline'] < $nowSql) {
        return 'closed';
    }

    return 'open';
}

function examination_domain_status_label(string $windowState, bool $isPublished): string
{
    return match ($windowState) {
        'finished' => 'Finished',
        'draft' => 'Draft',
        'scheduled' => 'Scheduled',
        'closed' => 'Closed',
        default => $isPublished ? 'Published' : 'Draft',
    };
}

function examination_domain_build_record(array $fields): array
{
    $examType = examination_normalize_exam_type((string)($fields['exam_type'] ?? ''));
    $examineeScope = examination_normalize_examinee_scope((string)($fields['examinee_scope'] ?? 'college_student'));
    $assignmentMode = examination_normalize_assignment_mode((string)($fields['assignment_mode'] ?? 'all'));
    $sectionCount = (int)($fields['section_count'] ?? 0);
    $userCount = (int)($fields['user_count'] ?? 0);
    $assignedSections = is_array($fields['assigned_sections'] ?? null)
        ? array_values(array_filter(array_map(static fn($s) => trim((string)$s), $fields['assigned_sections']), static fn($s) => $s !== ''))
        : [];
    if ($assignedSections === [] && $sectionCount > 0 && is_array($fields['metadata']['assigned_sections'] ?? null)) {
        $assignedSections = $fields['metadata']['assigned_sections'];
    }
    $isPublished = !empty($fields['is_published']);
    $windowState = (string)($fields['window_state'] ?? 'draft');

    return [
        'source_id' => (int)($fields['source_id'] ?? 0),
        'source_table' => (string)($fields['source_table'] ?? ''),
        'exam_type' => $examType,
        'title' => (string)($fields['title'] ?? ''),
        'description' => (string)($fields['description'] ?? ''),
        'time_limit_seconds' => (int)($fields['time_limit_seconds'] ?? 3600),
        'available_from' => $fields['available_from'] ?? null,
        'deadline' => $fields['deadline'] ?? null,
        'is_published' => $isPublished,
        'examinee_scope' => $examineeScope,
        'assignment_mode' => $assignmentMode,
        'examinee_scope_label' => examination_examinee_scope_label($examineeScope),
        'assignment_mode_label' => examination_assignment_mode_label($assignmentMode),
        'assignment_summary' => examination_audience_display_summary($assignmentMode, $assignedSections, $userCount),
        'exam_type_label' => examination_exam_type_label($examType),
        'question_count' => (int)($fields['question_count'] ?? 0),
        'examinee_count' => (int)($fields['examinee_count'] ?? 0),
        'window_state' => $windowState,
        'status_label' => examination_domain_status_label($windowState, $isPublished),
        'is_finished' => !empty($fields['is_finished']),
        'is_running' => !empty($fields['is_running']),
        'schedule_line' => examination_format_schedule_line($fields['available_from'] ?? null, $fields['deadline'] ?? null),
        'created_at' => (string)($fields['created_at'] ?? ''),
        'updated_at' => (string)($fields['updated_at'] ?? ''),
        'metadata' => is_array($fields['metadata'] ?? null) ? $fields['metadata'] : [],
    ];
}

function examination_domain_adapter(string $examType): ?array
{
    $examType = examination_normalize_exam_type($examType);
    if ($examType === '') {
        return null;
    }
    $registry = examination_type_registry();

    return $registry[$examType] ?? null;
}

function examination_domain_call(string $examType, string $hook, array $args = [])
{
    $adapter = examination_domain_adapter($examType);
    if ($adapter === null || !isset($adapter[$hook])) {
        return null;
    }
    $fn = $adapter[$hook];
    if (!is_string($fn) || !function_exists($fn)) {
        return null;
    }

    return $fn(...$args);
}

function examination_domain_list(mysqli $conn, int $professorId, array $filters = []): array
{
    $now = date('Y-m-d H:i:s');
    $typeFilter = examination_normalize_exam_type((string)($filters['exam_type'] ?? ''));
    $pubFilter = strtolower(trim((string)($filters['status'] ?? 'all')));
    $examineeFilter = trim((string)($filters['examinee_type'] ?? ''));
    $search = trim((string)($filters['q'] ?? ''));

    $out = [];
    $types = $typeFilter !== '' ? [$typeFilter] : examination_supported_types();

    foreach ($types as $type) {
        $rows = examination_domain_call($type, 'list_rows', [$conn, $professorId]);
        if (!is_array($rows)) {
            continue;
        }
        foreach ($rows as $raw) {
            $record = examination_domain_call($type, 'normalize', [$conn, $raw, $now]);
            if (!is_array($record)) {
                continue;
            }
            if ($search !== '') {
                $needle = mb_strtolower($search);
                $hay = mb_strtolower($record['title'] . ' ' . $record['description']);
                if (mb_strpos($hay, $needle) === false) {
                    continue;
                }
            }
            if ($examineeFilter === 'college_student' && $record['examinee_scope'] === 'reviewee') {
                continue;
            }
            if ($examineeFilter === 'reviewee' && $record['examinee_scope'] === 'college_student') {
                continue;
            }
            if ($examineeFilter === 'both' && $record['examinee_scope'] !== 'both') {
                continue;
            }
            if ($pubFilter === 'draft' && $record['is_published']) {
                continue;
            }
            if ($pubFilter === 'published' && (!$record['is_published'] || $record['window_state'] === 'finished')) {
                continue;
            }
            if ($pubFilter === 'finished' && !$record['is_finished']) {
                continue;
            }
            $out[] = $record;
        }
    }

    usort($out, static function (array $a, array $b): int {
        $ta = strtotime((string)($a['updated_at'] ?? '')) ?: 0;
        $tb = strtotime((string)($b['updated_at'] ?? '')) ?: 0;

        return $tb <=> $ta;
    });

    return $out;
}

function examination_domain_load(mysqli $conn, string $examType, int $sourceId, int $professorId): ?array
{
    $examType = examination_normalize_exam_type($examType);
    if ($examType === '' || $sourceId <= 0) {
        return null;
    }
    $raw = examination_domain_call($examType, 'load_raw', [$conn, $sourceId, $professorId]);
    if (!is_array($raw)) {
        return null;
    }

    return examination_domain_call($examType, 'normalize', [$conn, $raw, date('Y-m-d H:i:s')]);
}

function examination_domain_list_counts(mysqli $conn, int $professorId, array $filters = []): array
{
    $all = examination_domain_list($conn, $professorId, array_merge($filters, ['status' => 'all']));
    $counts = ['all' => count($all), 'draft' => 0, 'published' => 0, 'finished' => 0];
    foreach ($all as $row) {
        if (!$row['is_published']) {
            $counts['draft']++;
        }
        if ($row['is_published'] && !$row['is_finished']) {
            $counts['published']++;
        }
        if ($row['is_finished']) {
            $counts['finished']++;
        }
    }

    return $counts;
}

function examination_domain_resolve_type_from_request(array $request): string
{
    $type = examination_normalize_exam_type((string)($request['exam_type'] ?? ''));
    if ($type !== '') {
        return $type;
    }
    if ((int)($request['batch_id'] ?? 0) > 0) {
        return 'diagnostic';
    }
    if ((int)($request['exam_id'] ?? 0) > 0) {
        return 'regular';
    }

    return 'regular';
}

function examination_domain_source_id_from_request(array $request, string $examType): int
{
    $examType = examination_normalize_exam_type($examType);
    if ($examType === 'diagnostic') {
        return (int)($request['batch_id'] ?? $request['source_id'] ?? $request['id'] ?? 0);
    }

    return (int)($request['exam_id'] ?? $request['source_id'] ?? $request['id'] ?? 0);
}

function examination_domain_edit_url(string $examType, int $sourceId, string $step = 'config'): string
{
    $examType = examination_normalize_exam_type($examType);
    if ($examType === 'diagnostic') {
        $url = 'professor_examination_edit?exam_type=diagnostic&batch_id=' . $sourceId;
    } else {
        $url = 'professor_examination_edit?exam_type=regular&exam_id=' . $sourceId;
    }
    if ($step !== '' && $step !== 'config') {
        $url .= '&step=' . rawurlencode($step);
    }

    return $url;
}

function examination_domain_questions_url(string $examType, int $sourceId): string
{
    return examination_domain_edit_url($examType, $sourceId, 'questions');
}

function examination_domain_monitor_url(string $examType, int $sourceId): string
{
    if (examination_normalize_exam_type($examType) === 'diagnostic') {
        return 'professor_examination_monitor?exam_type=diagnostic&batch_id=' . $sourceId;
    }

    return 'professor_examination_monitor?exam_type=regular&exam_id=' . $sourceId;
}

function examination_domain_load_for_edit(mysqli $conn, string $examType, int $sourceId, int $professorId): array
{
    $examType = examination_normalize_exam_type($examType);
    if ($examType === '') {
        return ['record' => null, 'extras' => []];
    }

    $record = $sourceId > 0 ? examination_domain_load($conn, $examType, $sourceId, $professorId) : null;
    $extras = examination_domain_call($examType, 'config_extras', [$conn, $professorId, $sourceId]);

    return [
        'record' => $record,
        'extras' => is_array($extras) ? $extras : [],
    ];
}

function examination_domain_save_config(mysqli $conn, string $examType, int $professorId, array $post, int $sourceId = 0): array
{
    $examType = examination_normalize_exam_type($examType);
    if ($examType === '') {
        return ['ok' => false, 'error' => 'Invalid examination type.'];
    }

    $saveAction = strtolower(trim((string)($post['save_action'] ?? 'draft')));
    if ($saveAction === 'publish' && $sourceId > 0) {
        require_once __DIR__ . '/examination_questions.php';
        $qCheck = examination_questions_validate_for_publish($conn, $examType, $sourceId);
        if (empty($qCheck['ok'])) {
            return ['ok' => false, 'error' => (string)($qCheck['error'] ?? 'Questions are incomplete.')];
        }
    }

    $result = examination_domain_call($examType, 'save_config', [$conn, $professorId, $post, $sourceId]);
    if (!is_array($result)) {
        return ['ok' => false, 'error' => 'Configuration save is not available for this examination type.'];
    }

    return $result;
}

function examination_domain_build_config_post_from_record(mysqli $conn, string $examType, int $sourceId, int $professorId, array $overrides = []): array
{
    $ctx = examination_domain_load_for_edit($conn, $examType, $sourceId, $professorId);
    $record = is_array($ctx['record'] ?? null) ? $ctx['record'] : null;
    $extras = is_array($ctx['extras'] ?? null) ? $ctx['extras'] : [];
    if ($record === null) {
        return $overrides;
    }

    $timeParts = examination_format_time_limit_parts((int)($record['time_limit_seconds'] ?? 3600));
    $post = [
        'exam_type' => (string)$record['exam_type'],
        'title' => (string)$record['title'],
        'description' => (string)$record['description'],
        'time_limit_hours' => $timeParts['hours'],
        'time_limit_minutes' => $timeParts['minutes'],
        'available_from' => college_exam_format_datetime_local($record['available_from'] ?? null),
        'deadline' => college_exam_format_datetime_local($record['deadline'] ?? null),
        'examinee_scope' => (string)$record['examinee_scope'],
        'assignment_mode' => (string)$record['assignment_mode'],
        'sections' => $extras['sections'] ?? [''],
        'user_ids' => $extras['assigned_user_ids'] ?? [],
        'subject_ids' => array_map(static fn($row) => (int)($row['subject_id'] ?? 0), $extras['batch_subjects'] ?? []),
        'questions_required' => $extras['questions_required'] ?? [],
        'shuffle_questions' => !empty($extras['shuffle_questions']) ? '1' : '',
        'shuffle_choices' => !empty($extras['shuffle_choices']) ? '1' : '',
        'shuffle_mcq_questions' => !empty($extras['shuffle_mcq_questions']) ? '1' : '',
        'shuffle_tf_questions' => !empty($extras['shuffle_tf_questions']) ? '1' : '',
        'description_markdown' => !empty($extras['description_markdown']) ? '1' : '',
        'review_sheet_available_from' => college_exam_format_datetime_local($extras['review_sheet_available_from'] ?? null),
        'review_sheet_available_until' => college_exam_format_datetime_local($extras['review_sheet_available_until'] ?? null),
    ];

    if ($examType === 'diagnostic') {
        $post['batch_id'] = $sourceId;
    } else {
        $post['exam_id'] = $sourceId;
    }

    foreach ($overrides as $key => $value) {
        $post[$key] = $value;
    }

    return $post;
}

/**
 * Permanently delete one examination owned by the professor.
 *
 * @return array{ok:bool,error?:string,title?:string}
 */
function examination_domain_delete(mysqli $conn, string $examType, int $sourceId, int $professorId): array
{
    $examType = examination_normalize_exam_type($examType);
    if ($examType === '' || $sourceId <= 0 || $professorId <= 0) {
        return ['ok' => false, 'error' => 'Invalid examination.'];
    }

    $result = examination_domain_call($examType, 'delete', [$conn, $sourceId, $professorId]);
    if (!is_array($result)) {
        return ['ok' => false, 'error' => 'Delete is not available for this examination type.'];
    }

    return $result;
}

/**
 * Delete many examinations. Keys are "exam_type:source_id" (e.g. regular:12).
 *
 * @param list<string> $keys
 * @return array{ok:bool,deleted:int,skipped:int,errors:list<string>}
 */
function examination_domain_delete_many(mysqli $conn, array $keys, int $professorId): array
{
    $deleted = 0;
    $skipped = 0;
    $errors = [];
    $seen = [];

    foreach ($keys as $rawKey) {
        $key = trim((string)$rawKey);
        if ($key === '' || isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;

        $parts = explode(':', $key, 2);
        if (count($parts) !== 2) {
            $skipped++;
            $errors[] = 'Invalid selection: ' . $key;
            continue;
        }
        $examType = examination_normalize_exam_type($parts[0]);
        $sourceId = (int)$parts[1];
        if ($examType === '' || $sourceId <= 0) {
            $skipped++;
            $errors[] = 'Invalid selection: ' . $key;
            continue;
        }

        $result = examination_domain_delete($conn, $examType, $sourceId, $professorId);
        if (!empty($result['ok'])) {
            $deleted++;
            continue;
        }

        $skipped++;
        $label = trim((string)($result['title'] ?? ''));
        if ($label === '') {
            $label = $examType . ' #' . $sourceId;
        }
        $errors[] = $label . ': ' . (string)($result['error'] ?? 'Could not delete.');
    }

    return [
        'ok' => $deleted > 0 && $skipped === 0,
        'deleted' => $deleted,
        'skipped' => $skipped,
        'errors' => $errors,
    ];
}
