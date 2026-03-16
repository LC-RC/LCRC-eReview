<?php
/**
 * One-click migration: add choice_e..choice_j and allow correct_answer A–J.
 * Run once. Safe to run again (ignores "Duplicate column").
 */
require_once 'auth.php';
requireRole('admin');

$pageTitle = 'Run Quiz Choices Migration';
$steps = [];
$allOk = true;

$migrations = [
    "ADD COLUMN choice_e"  => "ALTER TABLE `quiz_questions` ADD COLUMN `choice_e` text DEFAULT NULL AFTER `choice_d`",
    "ADD COLUMN choice_f"   => "ALTER TABLE `quiz_questions` ADD COLUMN `choice_f` text DEFAULT NULL AFTER `choice_e`",
    "ADD COLUMN choice_g"  => "ALTER TABLE `quiz_questions` ADD COLUMN `choice_g` text DEFAULT NULL AFTER `choice_f`",
    "ADD COLUMN choice_h"  => "ALTER TABLE `quiz_questions` ADD COLUMN `choice_h` text DEFAULT NULL AFTER `choice_g`",
    "ADD COLUMN choice_i"  => "ALTER TABLE `quiz_questions` ADD COLUMN `choice_i` text DEFAULT NULL AFTER `choice_h`",
    "ADD COLUMN choice_j"  => "ALTER TABLE `quiz_questions` ADD COLUMN `choice_j` text DEFAULT NULL AFTER `choice_i`",
    "MODIFY correct_answer" => "ALTER TABLE `quiz_questions` MODIFY `correct_answer` varchar(1) NOT NULL",
    "MODIFY selected_answer" => "ALTER TABLE `quiz_answers` MODIFY `selected_answer` varchar(1) DEFAULT NULL",
];

foreach ($migrations as $label => $sql) {
    if (mysqli_query($conn, $sql)) {
        $steps[] = ['ok' => true, 'label' => $label, 'msg' => 'OK'];
    } else {
        $err = mysqli_error($conn);
        $code = mysqli_errno($conn);
        // 1060 = Duplicate column name; 1068 = Multiple primary key (ignore for re-runs)
        if ($code == 1060) {
            $steps[] = ['ok' => true, 'label' => $label, 'msg' => 'Already exists (skipped)'];
        } else {
            $steps[] = ['ok' => false, 'label' => $label, 'msg' => $err];
            $allOk = false;
        }
    }
}

require_once __DIR__ . '/includes/head_app.php';
?>
<body class="font-sans antialiased bg-gray-50">
  <?php include 'admin_sidebar.php'; ?>
  <main class="md:ml-64 p-6">
    <div class="max-w-2xl mx-auto">
      <div class="bg-white rounded-xl shadow-card border border-gray-100 p-6 mb-6">
        <h1 class="text-xl font-bold text-gray-800 mb-2"><i class="bi bi-database-gear mr-2"></i>Quiz choices migration</h1>
        <p class="text-gray-600 text-sm mb-6">Adds columns for choices E–J and allows correct answer A–J. Safe to run more than once.</p>

        <?php if ($allOk): ?>
          <div class="rounded-lg bg-green-50 border border-green-200 text-green-800 px-4 py-3 mb-6">
            <strong>Migration completed successfully.</strong> You can now save questions with more than 4 choices (E–J) and set any of them as the correct answer.
          </div>
        <?php else: ?>
          <div class="rounded-lg bg-red-50 border border-red-200 text-red-800 px-4 py-3 mb-6">
            <strong>Some steps failed.</strong> Check the list below. You may need to run <code class="bg-red-100 px-1 rounded">quiz_choices_extend_migration.sql</code> manually in phpMyAdmin.
          </div>
        <?php endif; ?>

        <ul class="space-y-2 text-sm">
          <?php foreach ($steps as $s): ?>
            <li class="flex items-center gap-2">
              <?php if ($s['ok']): ?>
                <i class="bi bi-check-circle-fill text-green-600"></i>
              <?php else: ?>
                <i class="bi bi-x-circle-fill text-red-600"></i>
              <?php endif; ?>
              <span class="font-medium"><?php echo h($s['label']); ?></span>
              <span class="text-gray-500"><?php echo h($s['msg']); ?></span>
            </li>
          <?php endforeach; ?>
        </ul>

        <div class="mt-6 pt-4 border-t border-gray-100 flex flex-wrap gap-3">
          <a href="admin_quizzes.php" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg font-semibold bg-primary text-white hover:bg-primary-dark transition"><i class="bi bi-list-ul"></i> Back to Quizzes</a>
          <a href="admin_subjects.php" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg font-semibold border-2 border-gray-200 text-gray-700 hover:bg-gray-50 transition"><i class="bi bi-folder"></i> Subjects</a>
        </div>
      </div>
    </div>
  </main>
</body>
</html>
