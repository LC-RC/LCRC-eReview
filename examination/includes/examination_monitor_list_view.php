<?php

/** @var array $assessments */

/** @var string $filterExamType */

/** @var string $filterSection */

/** @var string $filterExamineeType */

/** @var string $filterStatus */

/** @var string $filterQ */

/** @var string|null $monitorFlash */

require_once __DIR__ . '/college_sections.php';
$monitorSectionOptions = college_sections_active_names($conn);

$pageTitle = 'Examination Monitoring';

$adminLoadStudentsCss = true;
$professorFeatureHero = true;
$adminHeroIcon = 'graph-up';
$adminHeroEyebrow = 'Live operations';
$adminHeroTitle = 'Examination Monitoring';
$adminHeroSubtitle = 'Live console for attempts, progress, and submission status.';
$adminBreadcrumbs = [['Dashboard', 'professor_admin_dashboard'], ['Monitoring']];
$monitorLiveTaking = 0;
foreach ($assessments as $__aLive) {
    $monitorLiveTaking += (int) ($__aLive['taking_count'] ?? $__aLive['in_progress_count'] ?? 0);
}
$lmsHeroVariant = 'navy';
if ($monitorLiveTaking > 0) {
    $adminHeroMeta = '<span class="lms-status lms-status--live"><span class="prof-live-dot" aria-hidden="true"></span> Live</span>';
} else {
    $adminHeroMeta = '<span class="lms-status lms-status--info">' . count($assessments) . ' assessment' . (count($assessments) === 1 ? '' : 's') . '</span>';
}

?>

<!DOCTYPE html>

<html lang="en">

<head>

  <?php require_once dirname(__DIR__) . '/includes/examination_head_admin.php'; ?>

</head>

<body class="font-sans antialiased admin-app admin-students-page examination-admin-page professor-admin prof-page--monitor<?php echo ereview_lms_premium_body_class(); ?>">

<?php include dirname(__DIR__) . '/professor/professor_admin_sidebar.php'; ?>

<?php $toastSuccess = ereview_lms_toast_take('success', !empty($monitorFlash) ? (string) $monitorFlash : null); ?>
<?php include dirname(__DIR__, 2) . '/includes/components/admin_page_hero.php'; ?>



<?php if ($monitorFlash && empty($toastSuccess)): ?>

  <div class="admin-flash admin-flash--success mb-3 p-3 rounded-xl flex items-center gap-2">

    <i class="bi bi-check-circle-fill"></i><span><?php echo h($monitorFlash); ?></span>

  </div>

<?php endif; ?>



<div class="examination-page-shell">

  <section class="lms-kpi-row" aria-label="Monitoring snapshot">
    <article class="lms-kpi lms-kpi--total">
      <span class="lms-kpi__label"><i class="bi bi-journal-text" aria-hidden="true"></i> Assessments</span>
      <span class="lms-kpi__value"><?php echo (int) count($assessments); ?></span>
      <span class="lms-kpi__hint">On this list</span>
    </article>
    <article class="lms-kpi lms-kpi--ok">
      <span class="lms-kpi__label"><i class="bi bi-people" aria-hidden="true"></i> Roster</span>
      <span class="lms-kpi__value"><?php
          $__monRoster = 0;
          foreach ($assessments as $__a) { $__monRoster += (int)($__a['roster_count'] ?? $__a['total_students'] ?? 0); }
          echo (int) $__monRoster;
        ?></span>
      <span class="lms-kpi__hint">Assigned seats</span>
    </article>
    <article class="lms-kpi lms-kpi--live">
      <span class="lms-kpi__label"><i class="bi bi-broadcast" aria-hidden="true"></i> Taking</span>
      <span class="lms-kpi__value"><?php
          $__monTaking = 0;
          foreach ($assessments as $__a) { $__monTaking += (int)($__a['taking_count'] ?? $__a['in_progress_count'] ?? 0); }
          echo (int) $__monTaking;
        ?></span>
      <span class="lms-kpi__hint">In progress now</span>
    </article>
    <article class="lms-kpi lms-kpi--wait">
      <span class="lms-kpi__label"><i class="bi bi-check2-circle" aria-hidden="true"></i> Submitted</span>
      <span class="lms-kpi__value"><?php
          $__monSubmitted = 0;
          foreach ($assessments as $__a) { $__monSubmitted += (int)($__a['submitted_count'] ?? 0); }
          echo (int) $__monSubmitted;
        ?></span>
      <span class="lms-kpi__hint">Completed attempts</span>
    </article>
  </section>

  <section class="prof-workspace lms-workspace">
  <div class="prof-workspace__head lms-workspace__head">
    <div class="lms-workspace__heading">
      <div class="lms-workspace__title-row">
        <h2 class="prof-workspace__title">Monitoring</h2>
        <span class="prof-count lms-count-badge"><?php echo count($assessments); ?> assessment<?php echo count($assessments) === 1 ? '' : 's'; ?></span>
      </div>
      <p class="prof-workspace__sub">Track live attempts, roster progress, and submission status.</p>
    </div>
  </div>

  <div class="students-toolbar page-filter lms-toolbar">

    <form method="get" class="students-toolbar__search">

      <select name="exam_type" aria-label="Exam type" class="admin-btn admin-btn--secondary admin-btn--sm">

        <option value="">All types</option>

        <option value="regular" <?php echo $filterExamType === 'regular' || $filterExamType === 'college_exam' ? 'selected' : ''; ?>>Regular Exam</option>

        <option value="diagnostic" <?php echo $filterExamType === 'diagnostic' ? 'selected' : ''; ?>>Diagnostic</option>

      </select>

      <select name="section" aria-label="Filter by section" class="admin-btn admin-btn--secondary admin-btn--sm">
        <option value="">All sections</option>
        <?php foreach ($monitorSectionOptions as $secOpt): ?>
          <option value="<?php echo h($secOpt); ?>" <?php echo $filterSection === $secOpt ? 'selected' : ''; ?>><?php echo h($secOpt); ?></option>
        <?php endforeach; ?>
      </select>

      <select name="examinee_type" aria-label="Examinee type" class="admin-btn admin-btn--secondary admin-btn--sm">

        <option value="">All examinees</option>

        <option value="college_student" <?php echo $filterExamineeType === 'college_student' ? 'selected' : ''; ?>>College Student</option>

        <option value="reviewee" <?php echo $filterExamineeType === 'reviewee' ? 'selected' : ''; ?>>Reviewee</option>

      </select>

      <select name="status" aria-label="Attempt status" class="admin-btn admin-btn--secondary admin-btn--sm">

        <option value="">Any status</option>

        <option value="not_started" <?php echo $filterStatus === 'not_started' ? 'selected' : ''; ?>>Not started</option>

        <option value="in_progress" <?php echo $filterStatus === 'in_progress' ? 'selected' : ''; ?>>In progress</option>

        <option value="submitted" <?php echo $filterStatus === 'submitted' ? 'selected' : ''; ?>>Submitted</option>

      </select>

      <div class="students-search">

        <i class="bi bi-search" aria-hidden="true"></i>

        <input type="search" name="q" value="<?php echo h($filterQ); ?>" placeholder="Search assessment title..." aria-label="Search assessments">

      </div>

      <button type="submit" class="admin-btn admin-btn--secondary admin-btn--sm"><i class="bi bi-funnel"></i> Filter</button>

      <?php if ($filterExamType !== '' || $filterSection !== '' || $filterExamineeType !== '' || $filterStatus !== '' || $filterQ !== ''): ?>

        <a href="professor_examination_monitor" class="students-clear-link">Clear</a>

      <?php endif; ?>

    </form>

  </div>



  <div class="rounded-2xl page-table students-table-shell lms-table-wrap overflow-hidden">

    <div class="students-table-scroll">

      <table class="w-full text-left admin-students-table students-table--compact pex-monitor-table pex-list-table lms-table">
        <colgroup>
          <col style="width:12%">
          <col style="width:28%">
          <col style="width:12%">
          <col style="width:9%">
          <col style="width:9%">
          <col style="width:10%">
          <col style="width:9%">
          <col style="width:9.5rem">
        </colgroup>
        <thead>

          <tr>

            <th scope="col">Type</th>

            <th scope="col">Assessment</th>

            <th scope="col">Window</th>

            <th scope="col" class="text-right">Roster</th>

            <th scope="col" class="text-right">Taking</th>

            <th scope="col" class="text-right">Submitted</th>

            <th scope="col" class="text-right">Avg</th>

            <th scope="col" class="student-actions-head">Actions</th>

          </tr>

        </thead>

        <tbody>

        <?php if ($assessments === []): ?>

          <tr>

            <td colspan="8" class="students-empty-cell">

              <div class="font-semibold">No assessments match these filters</div>

              <p class="text-sm mt-1 mb-0">Try clearing filters or check back when examinations are published.</p>

            </td>

          </tr>

        <?php else: foreach ($assessments as $a): ?>

          <tr>

            <td class="pcs-badge-cell" data-label="Type"><span class="lms-status lms-status--info"><?php echo h(examination_monitor_exam_type_label((string)$a['exam_type'])); ?></span></td>

            <td class="pcs-primary-cell" data-label="Assessment"><span class="examination-title-cell" title="<?php echo h((string)$a['title']); ?>"><?php echo h((string)$a['title']); ?></span></td>

            <td class="pcs-meta-cell" data-label="Window"><span class="student-meta capitalize"><?php echo h((string)($a['window_state'] ?? '')); ?></span></td>

            <td class="text-right prof-num" data-label="Roster"><?php echo (int)($a['roster_count'] ?? 0); ?></td>

            <td class="text-right prof-num" data-label="Taking"><?php echo (int)($a['taking_count'] ?? 0); ?></td>

            <td class="text-right prof-num" data-label="Submitted"><?php echo (int)($a['submitted_count'] ?? 0); ?></td>

            <td class="text-right prof-num" data-label="Avg"><?php echo ($a['avg_score'] ?? null) !== null ? h(number_format((float)$a['avg_score'], 1)) . '%' : '—'; ?></td>

            <td class="student-action-cell" data-label="Actions"><a href="<?php echo h((string)$a['scope']); ?>" class="admin-btn admin-btn--primary admin-btn--sm admin-btn--view"><i class="bi bi-graph-up" aria-hidden="true"></i> Monitor</a></td>

          </tr>

        <?php endforeach; endif; ?>

        </tbody>

      </table>

    </div>

  </div>

  </section>

</div>

</body>

</html>

