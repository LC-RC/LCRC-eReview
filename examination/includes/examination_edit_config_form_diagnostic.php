<?php
/**
 * Diagnostic Exam authoring config — premium portal layout (page mode only).
 * Field names / POST contract match examination_edit_config_form.php.
 *
 * @var string $csrf
 * @var string $examType
 * @var bool $isNew
 * @var int $sourceId
 * @var string $titleVal
 * @var string $descVal
 * @var string $availVal
 * @var string $deadVal
 * @var array $timeParts
 * @var string $scopeVal
 * @var array $sectionsVal
 * @var array $suggestedSections
 * @var bool $assignmentLocked
 * @var array $examineeSearchResults
 * @var array $userIdsVal
 * @var array $subjectCatalog
 * @var array $selectedSubjects
 * @var array $questionsRequired
 * @var bool $shuffleQ
 * @var bool $shuffleC
 * @var bool $diagPublished
 * @var int $diagSummarySubjectCount
 * @var int $diagSummaryQuestionTotal
 * @var array $diagSummaryCodeParts
 */

$diagReviewUrl = !$isNew ? examination_domain_edit_url($examType, $sourceId, 'review') : '';
$diagQuestionsUrl = !$isNew ? examination_domain_edit_url($examType, $sourceId, 'questions') : '';
?>

<header class="diag-workspace-bar" id="diagWorkspaceBar">
  <div class="diag-workspace-bar__main">
    <p class="diag-workspace-bar__eyebrow">Diagnostic Exam</p>
    <h1 class="diag-workspace-bar__title" id="diagWorkspaceTitle"><?php echo h($titleVal !== '' ? $titleVal : 'CPA Diagnostic Assessment'); ?></h1>
    <p class="diag-workspace-bar__lede">Create and configure a multi-subject diagnostic examination.</p>
    <div class="diag-workspace-bar__meta">
      <span class="admin-badge <?php echo $diagPublished ? 'admin-badge--success' : 'admin-badge--warning'; ?>">
        <?php echo $diagPublished ? 'Published' : 'Draft'; ?>
      </span>
      <span class="diag-workspace-bar__summary" id="diagStickySummary">
        Selected: <strong id="diagStickySubjectCount"><?php echo (int)$diagSummarySubjectCount; ?></strong> subject<?php echo $diagSummarySubjectCount === 1 ? '' : 's'; ?>
        · Total questions: <strong id="diagStickyQuestionTotal"><?php echo (int)$diagSummaryQuestionTotal; ?></strong>
        · <span id="diagStickyCodes"><?php echo $diagSummaryCodeParts !== [] ? h(implode(' · ', $diagSummaryCodeParts)) : 'No subjects'; ?></span>
      </span>
    </div>
  </div>
  <div class="diag-workspace-bar__actions">
    <a class="admin-btn admin-btn--ghost admin-btn--sm" href="professor_examinations">Back</a>
    <?php if ($diagQuestionsUrl !== ''): ?>
      <a class="admin-btn admin-btn--ghost admin-btn--sm" href="<?php echo h($diagQuestionsUrl); ?>">Questions</a>
    <?php endif; ?>
    <button type="submit" name="save_action" value="draft" class="admin-btn admin-btn--primary admin-btn--sm"><i class="bi bi-save"></i> Save Changes</button>
    <?php if ($diagReviewUrl !== ''): ?>
      <a class="admin-btn admin-btn--secondary admin-btn--sm" href="<?php echo h($diagReviewUrl); ?>"><?php echo $diagPublished ? 'Review / Unpublish' : 'Review / Publish'; ?></a>
    <?php endif; ?>
  </div>
</header>

<?php if ($isNew): ?>
<section class="diag-panel">
  <h2 class="diag-panel__title">Exam type</h2>
  <p class="diag-panel__desc">Exam type is fixed after the examination is created.</p>
  <div class="diag-type-pick">
    <?php foreach (['regular' => 'Regular Exam', 'diagnostic' => 'Diagnostic Exam'] as $typeKey => $typeLabel): ?>
      <label class="diag-type-pick__item <?php echo $examType === $typeKey ? 'is-active' : ''; ?>">
        <input type="radio" name="exam_type_choice" value="<?php echo h($typeKey); ?>" <?php echo $examType === $typeKey ? 'checked' : ''; ?>>
        <span><?php echo h($typeLabel); ?></span>
      </label>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<section class="diag-panel">
  <h2 class="diag-panel__title">Exam Details</h2>
  <p class="diag-panel__desc">Title, description, time limit, and availability for examinees.</p>
  <div class="diag-details-grid">
    <div class="diag-details-grid__col">
      <label class="diag-field">
        <span class="diag-field__label">Title</span>
        <input class="diag-field__control" type="text" name="title" required value="<?php echo h($titleVal); ?>" id="diagTitleInput">
      </label>
      <label class="diag-field">
        <span class="diag-field__label">Description</span>
        <textarea class="diag-field__control" name="description" rows="4"><?php echo h($descVal); ?></textarea>
      </label>
    </div>
    <div class="diag-details-grid__col">
      <div class="diag-field">
        <span class="diag-field__label">Time Limit</span>
        <div class="diag-inline-pair">
          <label class="diag-inline-pair__item">
            <span class="diag-inline-pair__hint">Hours</span>
            <input class="diag-field__control" type="number" min="0" max="999" name="time_limit_hours" id="cfgTimeLimitHours" value="<?php echo (int)$timeParts['hours']; ?>">
          </label>
          <label class="diag-inline-pair__item">
            <span class="diag-inline-pair__hint">Minutes</span>
            <input class="diag-field__control" type="number" min="0" max="59" name="time_limit_minutes" id="cfgTimeLimitMinutes" value="<?php echo (int)$timeParts['minutes']; ?>">
          </label>
        </div>
      </div>
      <label class="diag-field">
        <span class="diag-field__label">Opens</span>
        <input class="diag-field__control" type="datetime-local" name="available_from" id="cfgAvailableFrom" value="<?php echo h($availVal); ?>">
      </label>
      <label class="diag-field">
        <span class="diag-field__label">Closes</span>
        <input class="diag-field__control" type="datetime-local" name="deadline" id="cfgDeadline" value="<?php echo h($deadVal); ?>">
      </label>
      <p class="diag-panel__hint">Examinees may start only within the availability window. The time limit applies after they start.</p>
    </div>
  </div>
</section>

<section class="diag-panel" id="subjectsPanel">
  <div class="diag-panel__head">
    <div>
      <h2 class="diag-panel__title">Subjects</h2>
      <p class="diag-panel__desc">Select subjects included in this diagnostic and define how many questions should be used. Entering a positive count includes the subject. Checked + 0 = use all authored questions.</p>
    </div>
    <div class="diag-subject-live" id="diagSubjectLive">
      <span>Selected: <strong id="diagLiveSubjectCount"><?php echo (int)$diagSummarySubjectCount; ?></strong></span>
      <span>Total questions: <strong id="diagLiveQuestionTotal"><?php echo (int)$diagSummaryQuestionTotal; ?></strong></span>
    </div>
  </div>
  <div class="diag-subject-grid" id="diagSubjectGrid">
    <?php foreach ($subjectCatalog as $sub): ?>
      <?php
        $sid = (int)($sub['subject_id'] ?? 0);
        $checked = in_array($sid, $selectedSubjects, true);
        $reqVal = (int)($questionsRequired[$sid] ?? 0);
        $code = (string)($sub['subject_code'] ?? '');
        $name = (string)($sub['subject_name'] ?? '');
      ?>
      <div class="diag-subject-card <?php echo $checked ? 'is-selected' : ''; ?>" data-diag-subject-row data-subject-code="<?php echo h($code); ?>">
        <div class="diag-subject-card__main">
          <input type="checkbox" name="subject_ids[]" value="<?php echo $sid; ?>" <?php echo $checked ? 'checked' : ''; ?> class="diag-subject-card__check" data-diag-subject-toggle aria-label="Include <?php echo h($code); ?>">
          <div class="diag-subject-card__identity">
            <span class="diag-subject-card__code"><?php echo h($code); ?></span>
            <span class="diag-subject-card__name"><?php echo h($name); ?></span>
          </div>
        </div>
        <label class="diag-subject-card__qty">
          <span class="diag-subject-card__qty-label">Questions</span>
          <input type="number" min="0" step="1" inputmode="numeric" name="questions_required[<?php echo $sid; ?>]" value="<?php echo $reqVal; ?>" placeholder="0" data-diag-subject-qty aria-label="Questions for <?php echo h($code); ?>">
        </label>
      </div>
    <?php endforeach; ?>
  </div>
  <div class="diag-subject-selection" id="diagSubjectSelection" <?php echo $diagSummarySubjectCount > 0 ? '' : 'hidden'; ?>>
    <div class="diag-subject-selection__chips" id="diagSubjectSelectionChips">
      <?php foreach ($subjectCatalog as $subChip): ?>
        <?php
          $sidChip = (int)($subChip['subject_id'] ?? 0);
          if ($sidChip <= 0 || !in_array($sidChip, $selectedSubjects, true)) {
              continue;
          }
          $codeChip = (string)($subChip['subject_code'] ?? '');
          $reqChip = (int)($questionsRequired[$sidChip] ?? 0);
          $qtyLabel = $reqChip > 0 ? (string)$reqChip : 'all';
        ?>
        <span class="diag-subject-selection__chip"><?php echo h($codeChip); ?> <?php echo h($qtyLabel); ?></span>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="diag-panel" id="examineePanel">
  <h2 class="diag-panel__title">Audience &amp; Assignment</h2>
  <p class="diag-panel__desc">Who can take this diagnostic and how they are assigned.</p>

  <div class="diag-choice-group" role="radiogroup" aria-label="Audience">
    <p class="diag-choice-group__label">Audience</p>
    <div class="diag-choice-group__options">
      <?php foreach (['reviewee' => 'Reviewees', 'college_student' => 'College Students', 'both' => 'Both'] as $sk => $sl): ?>
        <label class="diag-choice">
          <input type="radio" name="examinee_scope" value="<?php echo h($sk); ?>" <?php echo $scopeVal === $sk ? 'checked' : ''; ?>>
          <span><?php echo h($sl); ?></span>
        </label>
      <?php endforeach; ?>
    </div>
    <p class="diag-panel__hint" id="scopeHelp">College students can be assigned by section. Reviewees are assigned individually or as all reviewees.</p>
  </div>

  <div class="diag-choice-group mt-4" id="assignmentPanel">
    <p class="diag-choice-group__label">Assignment</p>
    <div id="assignmentCollegeStudent" class="hidden">
      <div class="diag-choice-group__options diag-choice-group__options--wrap">
        <label class="diag-choice"><input type="radio" name="assignment_mode" value="all" data-assignment-option> All eligible users</label>
        <label class="diag-choice"><input type="radio" name="assignment_mode" value="sections" data-assignment-option> By section</label>
        <label class="diag-choice"><input type="radio" name="assignment_mode" value="users" data-assignment-option> Selected students</label>
        <label class="diag-choice"><input type="radio" name="assignment_mode" value="sections_and_users" data-assignment-option> Sections + selected students</label>
      </div>
    </div>
    <div id="assignmentReviewee" class="hidden">
      <div class="diag-choice-group__options diag-choice-group__options--wrap">
        <label class="diag-choice"><input type="radio" name="assignment_mode" value="all" data-assignment-option> All reviewees</label>
        <label class="diag-choice"><input type="radio" name="assignment_mode" value="users" data-assignment-option> Selected reviewees</label>
      </div>
      <p class="diag-panel__hint">Section-based assignment does not apply to reviewees.</p>
    </div>
    <div id="assignmentBoth" class="hidden">
      <div class="diag-choice-group__options diag-choice-group__options--wrap">
        <label class="diag-choice"><input type="radio" name="assignment_mode" value="all" data-assignment-option> All students and all reviewees</label>
        <label class="diag-choice"><input type="radio" name="assignment_mode" value="sections_and_users" data-assignment-option> By section (students) + selected individuals</label>
        <label class="diag-choice"><input type="radio" name="assignment_mode" value="users" data-assignment-option> Selected individuals only</label>
      </div>
    </div>

    <div id="sectionsBlock" class="hidden diag-reveal mt-3">
      <span class="diag-field__label">College student sections</span>
      <?php if ($suggestedSections === []): ?>
        <p class="diag-panel__hint text-amber-700">No active sections yet. Create sections under <a class="underline font-semibold" href="professor_college_sections">Sections</a> first.</p>
      <?php endif; ?>
      <div id="sectionsList">
        <?php foreach ($sectionsVal as $idx => $sec): ?>
          <div class="diag-inline-row section-row">
            <select class="diag-field__control" name="sections[]" <?php echo $assignmentLocked ? 'disabled data-locked="1"' : ''; ?>>
              <option value="">Select section</option>
              <?php foreach ($suggestedSections as $sg): ?>
                <?php $sg = trim((string) $sg); if ($sg === '') continue; ?>
                <option value="<?php echo h($sg); ?>" <?php echo trim((string) $sec) === $sg ? 'selected' : ''; ?>><?php echo h($sg); ?></option>
              <?php endforeach; ?>
              <?php
                $secTrim = trim((string) $sec);
                if ($secTrim !== '' && !in_array($secTrim, $suggestedSections, true)):
              ?>
                <option value="<?php echo h($secTrim); ?>" selected><?php echo h($secTrim); ?> (legacy)</option>
              <?php endif; ?>
            </select>
            <?php if ($idx > 0 && !$assignmentLocked): ?><button type="button" class="admin-btn admin-btn--ghost admin-btn--sm remove-section">Remove</button><?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
      <?php if (!$assignmentLocked): ?>
      <button type="button" class="admin-btn admin-btn--ghost admin-btn--sm mt-1" id="addSectionBtn"><i class="bi bi-plus"></i> Add section</button>
      <?php endif; ?>
    </div>

    <div id="usersBlock" class="hidden diag-reveal mt-3">
      <span class="diag-field__label" id="usersBlockLabel">Selected individuals</span>
      <div class="diag-user-list">
        <?php if ($examineeSearchResults === []): ?>
          <p class="diag-panel__hint m-0">No accounts match the current examinee type.</p>
        <?php else: ?>
          <?php foreach ($examineeSearchResults as $eu): ?>
            <?php
              $euid = (int)($eu['user_id'] ?? 0);
              $euRt = strtolower(trim((string)($eu['review_type'] ?? 'reviewee')));
              $euType = ($euRt === 'undergrad') ? 'Student' : 'Reviewee';
              $euSection = trim((string)($eu['section'] ?? ''));
              $euStudentNum = trim((string)($eu['student_number'] ?? ''));
              $euLabel = (string)($eu['full_name'] ?? '');
              if ($euStudentNum !== '') {
                $euLabel .= ' — ' . $euStudentNum;
              }
              if ($euSection !== '') {
                $euLabel .= ' — ' . $euSection;
              } elseif ($euType === 'Student') {
                $euLabel .= ' — (no section)';
              }
            ?>
            <label class="diag-user-list__item">
              <input type="checkbox" name="user_ids[]" value="<?php echo $euid; ?>" <?php echo in_array($euid, $userIdsVal, true) ? 'checked' : ''; ?>>
              <span><?php echo h($euLabel); ?></span>
            </label>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>

<section class="diag-panel">
  <h2 class="diag-panel__title">Advanced Options</h2>
  <div class="diag-advanced-row">
    <label class="diag-choice"><input type="checkbox" name="shuffle_questions" id="cfgShuffleQ" value="1" <?php echo $shuffleQ ? 'checked' : ''; ?>> Shuffle questions</label>
    <label class="diag-choice"><input type="checkbox" name="shuffle_choices" id="cfgShuffleC" value="1" <?php echo $shuffleC ? 'checked' : ''; ?>> Shuffle choices</label>
  </div>
</section>

<div class="diag-workspace-footer">
  <button type="submit" name="save_action" value="draft" class="admin-btn admin-btn--primary"><i class="bi bi-save"></i> Save Changes</button>
  <?php if (!$isNew): ?>
    <a href="<?php echo h($diagQuestionsUrl); ?>" class="admin-btn admin-btn--secondary">Continue to Questions</a>
    <a href="<?php echo h($diagReviewUrl); ?>" class="admin-btn admin-btn--ghost">Review / Publish</a>
  <?php endif; ?>
  <a href="professor_examinations" class="admin-btn admin-btn--ghost">Cancel</a>
</div>
