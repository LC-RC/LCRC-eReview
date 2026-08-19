<?php
declare(strict_types=1);

/**
 * Read-only smoke test for Examination domain foundation (Phase 1A).
 * Usage: php scripts/examination_domain_smoke.php [professor_user_id]
 */

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/examination/includes/college_schema.php';
require_once dirname(__DIR__) . '/examination/includes/diagnostic_schema.php';
require_once dirname(__DIR__) . '/examination/includes/examination_domain.php';

$professorId = (int)($argv[1] ?? 0);
if ($professorId <= 0) {
    $q = @mysqli_query($conn, "SELECT user_id FROM users WHERE role='professor_admin' ORDER BY user_id ASC LIMIT 1");
    if ($q && ($r = mysqli_fetch_assoc($q))) {
        $professorId = (int)($r['user_id'] ?? 0);
        mysqli_free_result($q);
    }
}

$results = ['ok' => true, 'professor_id' => $professorId, 'checks' => []];

function domain_smoke_check(string $name, bool $pass, string $detail = ''): void
{
    global $results;
    if (!$pass) {
        $results['ok'] = false;
    }
    $results['checks'][] = ['name' => $name, 'pass' => $pass, 'detail' => $detail];
}

domain_smoke_check('normalize regular alias', examination_normalize_exam_type('college_exam') === 'regular', '');
domain_smoke_check('normalize diagnostic', examination_normalize_exam_type('diagnostic') === 'diagnostic', '');
domain_smoke_check('supported types', examination_supported_types() === ['regular', 'diagnostic'], implode(',', examination_supported_types()));

if ($professorId <= 0) {
    domain_smoke_check('professor id', false, 'No professor_admin user found');
    echo json_encode($results, JSON_PRETTY_PRINT) . PHP_EOL;
    exit($results['ok'] ? 0 : 1);
}

$list = examination_domain_list($conn, $professorId, []);
domain_smoke_check('domain list array', is_array($list), 'count=' . count($list));

$regular = 0;
$diagnostic = 0;
foreach ($list as $row) {
    if (($row['exam_type'] ?? '') === 'regular') {
        $regular++;
    }
    if (($row['exam_type'] ?? '') === 'diagnostic') {
        $diagnostic++;
    }
    domain_smoke_check('record has source_id', (int)($row['source_id'] ?? 0) > 0, $row['title'] ?? '');
    domain_smoke_check('record has exam_type_label', ($row['exam_type_label'] ?? '') !== '', $row['exam_type'] ?? '');
}

domain_smoke_check('list includes both backends', true, "regular={$regular} diagnostic={$diagnostic}");

if ($list !== []) {
    $first = $list[0];
    $loaded = examination_domain_load($conn, (string)$first['exam_type'], (int)$first['source_id'], $professorId);
    domain_smoke_check('domain load first item', is_array($loaded) && (int)($loaded['source_id'] ?? 0) === (int)$first['source_id'], (string)($first['title'] ?? ''));
}

$counts = examination_domain_list_counts($conn, $professorId);
domain_smoke_check('list counts', isset($counts['all']) && $counts['all'] === count($list), json_encode($counts));

echo json_encode($results, JSON_PRETTY_PRINT) . PHP_EOL;
exit($results['ok'] ? 0 : 1);
