<?php
declare(strict_types=1);

/**
 * Server-side config POST stash for failed validation (not a silent exam INSERT).
 * CSRF tokens are never stored. Cleared only after successful save.
 */

const EXAMINATION_CONFIG_STASH_KEY = 'examination_config_post_stash';
const EXAMINATION_CONFIG_STASH_MAX_BYTES = 524288;

/**
 * @param array<string,mixed> $post
 * @return array<string,mixed>
 */
function examination_config_draft_sanitize_post(array $post): array
{
    unset($post['csrf_token'], $post['action'], $post['save_action']);
    foreach (array_keys($post) as $k) {
        $lk = strtolower((string) $k);
        if (str_contains($lk, 'password') || str_contains($lk, 'token') || str_contains($lk, 'csrf')) {
            unset($post[$k]);
        }
    }

    return $post;
}

function examination_config_draft_identity(string $examType, int $sourceId): string
{
    $examType = examination_normalize_exam_type($examType) ?: 'regular';

    return $sourceId > 0 ? ($examType . ':' . $sourceId) : 'new';
}

/**
 * @param array<string,mixed> $post
 */
function examination_config_draft_stash(int $professorId, string $examType, int $sourceId, array $post): void
{
    if ($professorId <= 0 || !isset($_SESSION) || !is_array($_SESSION)) {
        return;
    }
    $clean = examination_config_draft_sanitize_post($post);
    $encoded = json_encode($clean, JSON_UNESCAPED_UNICODE);
    if (!is_string($encoded) || strlen($encoded) > EXAMINATION_CONFIG_STASH_MAX_BYTES) {
        return;
    }
    if (!isset($_SESSION[EXAMINATION_CONFIG_STASH_KEY]) || !is_array($_SESSION[EXAMINATION_CONFIG_STASH_KEY])) {
        $_SESSION[EXAMINATION_CONFIG_STASH_KEY] = [];
    }
    $_SESSION[EXAMINATION_CONFIG_STASH_KEY][(string) $professorId] = [
        'identity' => examination_config_draft_identity($examType, $sourceId),
        'exam_type' => examination_normalize_exam_type($examType) ?: 'regular',
        'source_id' => max(0, $sourceId),
        'post' => $clean,
        'saved_at' => time(),
    ];
}

/**
 * @return array<string,mixed>|null
 */
function examination_config_draft_peek(int $professorId, string $examType, int $sourceId): ?array
{
    if ($professorId <= 0 || !isset($_SESSION[EXAMINATION_CONFIG_STASH_KEY]) || !is_array($_SESSION[EXAMINATION_CONFIG_STASH_KEY])) {
        return null;
    }
    $row = $_SESSION[EXAMINATION_CONFIG_STASH_KEY][(string) $professorId] ?? null;
    if (!is_array($row) || !is_array($row['post'] ?? null)) {
        return null;
    }
    $want = examination_config_draft_identity($examType, $sourceId);
    if ((string) ($row['identity'] ?? '') !== $want) {
        return null;
    }
    if ((time() - (int) ($row['saved_at'] ?? 0)) > 86400) {
        return null;
    }

    return $row['post'];
}

function examination_config_draft_clear(int $professorId): void
{
    if ($professorId <= 0 || !isset($_SESSION[EXAMINATION_CONFIG_STASH_KEY]) || !is_array($_SESSION[EXAMINATION_CONFIG_STASH_KEY])) {
        return;
    }
    unset($_SESSION[EXAMINATION_CONFIG_STASH_KEY][(string) $professorId]);
}

/**
 * Browser localStorage identities to drop after a successful config save.
 *
 * @return array{professor_id:int,identity:string,identities:list<string>}
 */
function examination_config_draft_browser_clear_payload(int $professorId, string $examType, int $postedSourceId, int $savedSourceId): array
{
    $identities = [];
    if ($postedSourceId <= 0) {
        $identities[] = 'new';
    }
    if ($postedSourceId > 0) {
        $identities[] = examination_config_draft_identity($examType, $postedSourceId);
    }
    if ($savedSourceId > 0) {
        $identities[] = examination_config_draft_identity($examType, $savedSourceId);
    }
    if ($identities === []) {
        $identities[] = 'new';
    }
    $identities = array_values(array_unique($identities));

    return [
        'professor_id' => $professorId,
        'identity' => (string) $identities[count($identities) - 1],
        'identities' => $identities,
    ];
}

/**
 * Display tree from posted regular subjects (failed validation / stash).
 *
 * @param array<string,mixed> $post
 * @return array{mode:string,total:int,tree:list<array<string,mixed>>}
 */
function examination_config_draft_regular_tree_from_post(array $post): array
{
    require_once __DIR__ . '/college_exam_subject_topic_helpers.php';
    $mode = college_exam_normalize_breakdown_mode((string) ($post['question_breakdown_mode'] ?? 'overall'));
    $total = max(0, (int) ($post['total_questions_required'] ?? 0));
    $tree = [];
    $raw = is_array($post['reg_subjects'] ?? null) ? $post['reg_subjects'] : [];
    foreach ($raw as $i => $s) {
        if (!is_array($s)) {
            continue;
        }
        $name = trim(preg_replace('/\s+/u', ' ', (string) ($s['name'] ?? '')) ?? '');
        $qty = max(0, (int) ($s['questions'] ?? 0));
        $topics = [];
        $topicsRaw = is_array($s['topics'] ?? null) ? $s['topics'] : [];
        foreach ($topicsRaw as $j => $t) {
            if (!is_array($t)) {
                continue;
            }
            $tName = trim(preg_replace('/\s+/u', ' ', (string) ($t['name'] ?? '')) ?? '');
            $topics[] = [
                'exam_topic_id' => 0,
                'topic_name' => $tName,
                'questions_required' => max(0, (int) ($t['questions'] ?? 0)),
                'sort_order' => (int) $j,
            ];
        }
        if ($name === '' && $qty < 1 && $topics === []) {
            continue;
        }
        $tree[] = [
            'exam_subject_id' => 0,
            'subject_name' => $name,
            'questions_required' => $qty,
            'sort_order' => (int) $i,
            'topics' => $topics,
        ];
        $total += $qty;
    }
    if ($mode === 'overall') {
        $total = max(0, (int) ($post['total_questions_required'] ?? 0));
    }

    return ['mode' => $mode, 'total' => $total, 'tree' => $tree];
}
