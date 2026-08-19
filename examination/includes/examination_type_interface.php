<?php
declare(strict_types=1);

/**
 * Examination type adapter contract (Phase 1A — read/list/normalize only).
 *
 * Each type module in examination/includes/types/ implements:
 *   examination_type_{type}_list_rows(mysqli, int $professorId): list<array>
 *   examination_type_{type}_load_raw(mysqli, int $sourceId, int $professorId): ?array
 *   examination_type_{type}_normalize(mysqli, array $rawRow, string $nowSql): array
 *   examination_type_{type}_config_extras(mysqli, int $professorId, int $sourceId): array
 *   examination_type_{type}_save_config(mysqli, int $professorId, array $post, int $sourceId): array
 *
 * Normalized Examination record (domain shape):
 *   source_id, source_table, exam_type, title, description,
 *   time_limit_seconds, available_from, deadline, is_published,
 *   examinee_scope, assignment_mode, examinee_scope_label, assignment_mode_label,
 *   assignment_summary, exam_type_label, question_count, examinee_count,
 *   window_state, status_label, is_finished, is_running, schedule_line,
 *   created_at, updated_at, metadata
 */

function examination_type_registry(): array
{
    return [
        'regular' => [
            'source_table' => 'college_exams',
            'source_id_field' => 'exam_id',
            'list_rows' => 'examination_type_regular_list_rows',
            'load_raw' => 'examination_type_regular_load_raw',
            'normalize' => 'examination_type_regular_normalize',
            'config_extras' => 'examination_type_regular_config_extras',
            'save_config' => 'examination_type_regular_save_config',
        ],
        'diagnostic' => [
            'source_table' => 'diagnostic_batches',
            'source_id_field' => 'batch_id',
            'list_rows' => 'examination_type_diagnostic_list_rows',
            'load_raw' => 'examination_type_diagnostic_load_raw',
            'normalize' => 'examination_type_diagnostic_normalize',
            'config_extras' => 'examination_type_diagnostic_config_extras',
            'save_config' => 'examination_type_diagnostic_save_config',
        ],
    ];
}

function examination_supported_types(): array
{
    return array_keys(examination_type_registry());
}
