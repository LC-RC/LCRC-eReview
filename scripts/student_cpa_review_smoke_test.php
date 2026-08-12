<?php
/**
 * CLI smoke checks for My CPA Review ownership + schema.
 * Usage: php scripts/student_cpa_review_smoke_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/student_cpa_review.php';

$fail = 0;
function assert_true(bool $cond, string $msg): void
{
    global $fail;
    if ($cond) {
        echo "[PASS] {$msg}\n";
    } else {
        echo "[FAIL] {$msg}\n";
        $fail++;
    }
}

student_cpa_review_ensure_schema($conn);
$tables = [
    'student_notes', 'student_bookmarks', 'student_favorites', 'student_important_items',
    'student_mistake_notebook', 'student_quick_review', 'student_cpa_activity_log',
];
foreach ($tables as $t) {
    assert_true(ereview_schema_table_exists($conn, $t), "table exists: {$t}");
}

// IDOR-style: create as user A, ensure user B cannot read/delete
$userA = 900001;
$userB = 900002;
$note = student_cpa_note_save($conn, $userA, [
    'title' => 'Smoke note A',
    'content' => '<p>Owned by A</p><script>alert(1)</script>',
    'tags' => 'smoke',
]);
assert_true(!empty($note['ok']) && !empty($note['note_id']), 'note_save user A');
$noteId = (int) ($note['note_id'] ?? 0);
$gotA = student_cpa_note_get($conn, $userA, $noteId);
$gotB = student_cpa_note_get($conn, $userB, $noteId);
assert_true(is_array($gotA), 'user A can read own note');
assert_true($gotB === null, 'user B cannot read A note (IDOR)');
assert_true(strpos((string) ($gotA['content'] ?? ''), '<script') === false, 'note HTML sanitize strips script');

$delB = student_cpa_note_delete($conn, $userB, $noteId);
assert_true($delB === false, 'user B cannot delete A note');
$delA = student_cpa_note_delete($conn, $userA, $noteId);
assert_true($delA === true, 'user A can delete own note');

@mysqli_query($conn, 'DELETE FROM student_bookmarks WHERE user_id = ' . (int) $userA . ' AND item_type = \'lesson\' AND item_id = 1');
$bm1 = student_cpa_bookmark_toggle($conn, $userA, [
    'item_type' => 'lesson', 'item_id' => 1, 'title' => 'L1', 'url' => 'student_lesson_viewer?lesson_id=1',
]);
$bm2 = student_cpa_bookmark_toggle($conn, $userA, [
    'item_type' => 'lesson', 'item_id' => 1, 'title' => 'L1',
]);
assert_true(!empty($bm1['ok']) && !empty($bm1['bookmarked']), 'bookmark add');
assert_true(!empty($bm2['ok']) && empty($bm2['bookmarked']), 'bookmark toggle off (unique)');

// Concept isolation (not notes)
$c1 = student_cpa_concept_save($conn, $userA, [
    'title' => 'Lower of Cost and NRV',
    'topic' => 'Inventories',
    'body' => 'Inventory valuation rule',
    'subject_id' => 0,
    'is_last_minute' => 1,
]);
assert_true(!empty($c1['ok']) && !empty($c1['important_id']), 'concept_save');
$cid = (int) ($c1['important_id'] ?? 0);
$cGetB = student_cpa_concept_get($conn, $userB, $cid);
assert_true($cGetB === null, 'user B cannot read A concept (IDOR)');
$clist = student_cpa_concepts_list($conn, $userA, ['per_page' => 50]);
$onlyConcepts = true;
foreach ($clist['rows'] as $row) {
    if (($row['item_type'] ?? '') !== 'concept') {
        $onlyConcepts = false;
        break;
    }
}
assert_true($onlyConcepts, 'concepts list is concept-only');
student_cpa_concept_delete($conn, $userA, $cid);

echo $fail === 0 ? "\nAll smoke checks passed.\n" : "\n{$fail} check(s) failed.\n";
exit($fail === 0 ? 0 : 1);
