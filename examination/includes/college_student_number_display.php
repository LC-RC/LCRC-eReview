<?php
declare(strict_types=1);

require_once __DIR__ . '/college_student_number.php';

/**
 * Read-only College Exam ID display. Never looks up another user by ID query param.
 */

function college_student_number_label_for_user(mysqli $conn, int $userId): string
{
    if ($userId <= 0 || !college_student_number_users_column_ready($conn)) {
        return '';
    }
    $stmt = mysqli_prepare($conn, 'SELECT student_number FROM users WHERE user_id=? LIMIT 1');
    if (!$stmt) {
        return '';
    }
    mysqli_stmt_bind_param($stmt, 'i', $userId);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    return trim((string) ($row['student_number'] ?? ''));
}

/**
 * @param array{copy?:bool, compact?:bool} $opts
 */
function college_exam_id_chip_html(string $studentNumber, array $opts = []): string
{
    $sn = trim($studentNumber);
    $copy = !empty($opts['copy']);
    $compact = !empty($opts['compact']);
    if ($sn === '') {
        return '<span class="college-exam-id-chip college-exam-id-chip--empty">No College Exam ID</span>';
    }
    $safe = htmlspecialchars($sn, ENT_QUOTES, 'UTF-8');
    $cls = 'college-exam-id-chip' . ($compact ? ' college-exam-id-chip--compact' : '');
    $html = '<span class="' . $cls . '"><span class="college-exam-id-chip__k">College Exam ID</span>';
    $html .= '<code class="college-exam-id-chip__v" translate="no">' . $safe . '</code>';
    if ($copy) {
        $html .= '<button type="button" class="college-exam-id-chip__copy" data-copy-college-exam-id="' . $safe . '" aria-label="Copy College Exam ID ' . $safe . '">'
            . '<svg class="college-exam-id-chip__copy-svg" width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true">'
            . '<rect x="9" y="9" width="13" height="13" rx="2" stroke="currentColor" stroke-width="2"/>'
            . '<path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1" stroke="currentColor" stroke-width="2"/>'
            . '</svg></button>';
    }
    $html .= '</span>';

    return $html;
}

function college_student_number_display_html(string $studentNumber): string
{
    $sn = trim($studentNumber);
    if ($sn === '') {
        return '';
    }

    return '<div class="college-exam-id-block">'
        . '<p class="college-exam-id-block__label">My College Exam ID</p>'
        . college_exam_id_chip_html($sn, ['copy' => true])
        . '<p class="college-exam-id-block__hint">This ID identifies you on published examination results. It does not change if your name, school, section, or email changes.</p>'
        . '</div>';
}

function college_exam_id_copy_script(): void
{
    static $sent = false;
    if ($sent) {
        return;
    }
    $sent = true;
    echo <<<'HTML'
<script>
(function () {
  if (window.__ereviewCollegeExamIdCopy) return;
  window.__ereviewCollegeExamIdCopy = true;
  document.addEventListener('click', function (e) {
    var btn = e.target && e.target.closest ? e.target.closest('[data-copy-college-exam-id]') : null;
    if (!btn) return;
    e.preventDefault();
    var id = String(btn.getAttribute('data-copy-college-exam-id') || '').trim();
    if (!id) return;
    var done = function () {
      btn.classList.add('is-copied');
      btn.setAttribute('data-copied', '1');
      var icon = btn.querySelector('i');
      if (icon) icon.className = 'bi bi-check2';
      if (window.LmsPremiumToast && typeof window.LmsPremiumToast.success === 'function') {
        window.LmsPremiumToast.success('Copied College Exam ID', id);
      }
      setTimeout(function () {
        btn.classList.remove('is-copied');
        if (icon) icon.className = 'bi bi-clipboard';
      }, 1600);
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(id).then(done).catch(done);
    } else {
      done();
    }
  });
})();
</script>
HTML;
}
