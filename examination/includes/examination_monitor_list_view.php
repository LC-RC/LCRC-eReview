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
$adminHeroIcon = 'graph-up';

$adminHeroTitle = 'Examination Monitoring';

$adminHeroSubtitle = 'Monitor examination attempts and student progress across regular and diagnostic assessments.';

?>

<!DOCTYPE html>

<html lang="en">

<head>

  <?php require_once dirname(__DIR__) . '/includes/examination_head_admin.php'; ?>

</head>

<body class="font-sans antialiased admin-app admin-students-page examination-admin-page">

<?php include dirname(__DIR__) . '/professor/professor_admin_sidebar.php'; ?>



<?php include dirname(__DIR__, 2) . '/includes/components/admin_page_hero.php'; ?>



<?php if ($monitorFlash): ?>

  <div class="admin-flash admin-flash--success mb-3 p-3 rounded-xl flex items-center gap-2">

    <i class="bi bi-check-circle-fill"></i><span><?php echo h($monitorFlash); ?></span>

  </div>

<?php endif; ?>



<div class="examination-page-shell">

  <div class="students-toolbar page-filter">

    <form method="get" class="students-toolbar__search">

      <select name="exam_type" aria-label="Exam type" class="admin-btn admin-btn--secondary admin-btn--sm" style="min-height:2.25rem;">

        <option value="">All types</option>

        <option value="regular" <?php echo $filterExamType === 'regular' || $filterExamType === 'college_exam' ? 'selected' : ''; ?>>Regular Exam</option>

        <option value="diagnostic" <?php echo $filterExamType === 'diagnostic' ? 'selected' : ''; ?>>Diagnostic</option>

      </select>

      <select name="section" aria-label="Filter by section" class="admin-btn admin-btn--secondary admin-btn--sm" style="min-height:2.25rem;">
        <option value="">All sections</option>
        <?php foreach ($monitorSectionOptions as $secOpt): ?>
          <option value="<?php echo h($secOpt); ?>" <?php echo $filterSection === $secOpt ? 'selected' : ''; ?>><?php echo h($secOpt); ?></option>
        <?php endforeach; ?>
      </select>

      <select name="examinee_type" aria-label="Examinee type" class="admin-btn admin-btn--secondary admin-btn--sm" style="min-height:2.25rem;">

        <option value="">All examinees</option>

        <option value="college_student" <?php echo $filterExamineeType === 'college_student' ? 'selected' : ''; ?>>College Student</option>

        <option value="reviewee" <?php echo $filterExamineeType === 'reviewee' ? 'selected' : ''; ?>>Reviewee</option>

      </select>

      <select name="status" aria-label="Attempt status" class="admin-btn admin-btn--secondary admin-btn--sm" style="min-height:2.25rem;">

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

    <span class="students-toolbar__meta"><?php echo count($assessments); ?> assessment<?php echo count($assessments) === 1 ? '' : 's'; ?></span>

  </div>



  <div class="rounded-xl page-table students-table-shell">

    <div class="students-table-scroll">

      <table class="w-full text-left admin-students-table students-table--compact min-w-[960px]">

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

            <td><span class="admin-badge admin-badge--info"><?php echo h(examination_monitor_exam_type_label((string)$a['exam_type'])); ?></span></td>

            <td><span class="examination-title-cell"><?php echo h((string)$a['title']); ?></span></td>

            <td><span class="student-meta capitalize"><?php echo h((string)($a['window_state'] ?? '')); ?></span></td>

            <td class="text-right"><?php echo (int)($a['roster_count'] ?? 0); ?></td>

            <td class="text-right"><?php echo (int)($a['taking_count'] ?? 0); ?></td>

            <td class="text-right"><?php echo (int)($a['submitted_count'] ?? 0); ?></td>

            <td class="text-right"><?php echo ($a['avg_score'] ?? null) !== null ? h(number_format((float)$a['avg_score'], 1)) . '%' : '—'; ?></td>

            <td><a href="<?php echo h((string)$a['scope']); ?>" class="admin-btn admin-btn--ghost admin-btn--sm"><i class="bi bi-graph-up"></i> Monitor</a></td>

          </tr>

        <?php endforeach; endif; ?>

        </tbody>

      </table>

    </div>

  </div>

</div>

</body>

</html>

