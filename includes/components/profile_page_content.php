<?php
/**
 * Shared profile page body: hero, sticky section nav, overview/account/security/enrollment/activity.
 *
 * Required variables (set by student_profile.php / staff_profile.php):
 * @var string $ereviewProfileTheme        'student' | 'staff'
 * @var string $ereviewProfileVariant      '' | 'professor'
 * @var string $ereviewProfileEditBtnId
 * @var string $ereviewProfilePwBtnId
 * @var array  $row
 * @var array  $cols
 * @var string $roleLabel
 * @var string $avatarSrc
 * @var string $avatarInitial
 * @var string|null $lastLogin
 * @var string|null $lastIp
 * @var string $createdAt
 * @var string $updatedAt
 * @var string|null $enrollStart
 * @var string|null $enrollEnd
 * @var int|null $enrollDaysLeft          days until access_end; negative if past; null if no end
 * @var bool   $ereviewProfileShowEnrollment
 * @var list<array{label:string,detail:string,time:string}> $activity
 * @var string $signInEmail
 * @var string $phoneDisp                  raw phone or ''
 * @var string $bioRaw
 * @var string|null $ereviewProfileDebugUrl
 * @var string $csrf
 * @var string $coverSrc
 */

$ereviewProfileTheme = $ereviewProfileTheme ?? 'student';
$ereviewProfileVariant = $ereviewProfileVariant ?? '';
$signInEmail = trim((string)($signInEmail ?? ''));
$phoneDisp = trim((string)($phoneDisp ?? ''));
$bioRaw = trim((string)($bioRaw ?? ''));
$ereviewProfileShowEnrollment = !empty($ereviewProfileShowEnrollment);
$activity = is_array($activity ?? null) ? $activity : [];
if (!isset($enrollDaysLeft)) {
    $enrollDaysLeft = null;
}
$fullNameDisplay = trim((string)($row['full_name'] ?? ''));
if ($fullNameDisplay === '') {
    $fullNameDisplay = trim((string)($_SESSION['full_name'] ?? ''));
}
$reviewTypeRaw = strtolower(trim((string)($row['review_type'] ?? '')));
$reviewTypeDisplay = 'Not set';
if ($reviewTypeRaw === 'undergrad') {
    $reviewTypeDisplay = 'Undergrad';
} elseif ($reviewTypeRaw === 'reviewee') {
    $reviewTypeDisplay = 'Reviewee';
} elseif ($reviewTypeRaw !== '') {
    $reviewTypeDisplay = ucwords(str_replace('_', ' ', $reviewTypeRaw));
}
$schoolRaw = trim((string)($row['school'] ?? ''));
$schoolOtherRaw = trim((string)($row['school_other'] ?? ''));
$schoolDisplay = $schoolRaw;
if ($schoolRaw === 'Other' && $schoolOtherRaw !== '') {
    $schoolDisplay = $schoolOtherRaw;
}
if ($schoolDisplay === '' && $schoolOtherRaw !== '') {
    $schoolDisplay = $schoolOtherRaw;
}
if ($schoolDisplay === '') {
    $schoolDisplay = 'Not set';
}
$paymentProofRaw = trim((string)($row['payment_proof'] ?? ''));
$hasPaymentProof = $paymentProofRaw !== '';
$paymentProofUrl = 'student_payment_proof.php';
$headerMetaBadges = [];
if ($reviewTypeDisplay !== 'Not set') {
    $headerMetaBadges[] = $reviewTypeDisplay;
}
if ($schoolDisplay !== 'Not set') {
    $headerMetaBadges[] = $schoolDisplay;
}
if (isset($enrollDaysLeft) && is_int($enrollDaysLeft) && $enrollDaysLeft >= 0) {
    $headerMetaBadges[] = $enrollDaysLeft . ' day' . ($enrollDaysLeft === 1 ? '' : 's') . ' left';
}

$enrollmentBadgeText = null;
if (isset($enrollDaysLeft) && is_int($enrollDaysLeft)) {
    if ($enrollDaysLeft < 0) {
        $enrollmentBadgeText = 'Access ended';
    } elseif ($enrollDaysLeft === 0) {
        $enrollmentBadgeText = 'Last day of access';
    } else {
        $enrollmentBadgeText = (int) $enrollDaysLeft . ' day' . ($enrollDaysLeft === 1 ? '' : 's') . ' left';
    }
}

$headerContextLine = [];
if ($reviewTypeDisplay !== 'Not set') {
    $headerContextLine[] = $reviewTypeDisplay;
}
if ($schoolDisplay !== 'Not set') {
    $headerContextLine[] = $schoolDisplay;
}
$headerContextLineStr = implode(' · ', $headerContextLine);

$attrTheme = h($ereviewProfileTheme);
$attrVar = $ereviewProfileVariant !== '' ? ' data-ere-profile-variant="' . h($ereviewProfileVariant) . '"' : '';
$attrReviewType = $ereviewProfileTheme === 'student' ? ' data-ere-review-type="' . h($reviewTypeRaw !== '' ? $reviewTypeRaw : 'reviewee') . '"' : '';
$pwLabel = $ereviewProfileTheme === 'staff' ? 'Change password' : 'Change password in editor';
$ereviewProfileDebugUrl = isset($ereviewProfileDebugUrl) ? trim((string)$ereviewProfileDebugUrl) : '';
$debugAttr = $ereviewProfileDebugUrl !== '' ? ' data-ere-debug-url="' . h($ereviewProfileDebugUrl) . '"' : '';
?>
<div
  class="ere-prof profile-page<?php echo $ereviewProfileTheme === 'student' ? ' ere-prof--student-refresh' : ''; ?>"
  id="ereviewProfilePage"
  data-ere-profile-root
  data-ere-profile-theme="<?php echo $attrTheme; ?>"<?php echo $attrVar; ?><?php echo $attrReviewType; ?>
  data-ere-edit-btn-id="<?php echo h($ereviewProfileEditBtnId); ?>"
  data-ere-pw-btn-id="<?php echo h($ereviewProfilePwBtnId); ?>"
  <?php echo $debugAttr; ?>
>
  <header class="ere-prof__hero" aria-labelledby="ereProfHeading">
    <?php if ($ereviewProfileTheme === 'student'): ?>
      <div class="ere-prof__student-dash" aria-label="Profile summary">
        <div class="ere-prof__student-dash-cover">
          <div class="ere-prof__student-dash-bg" aria-hidden="true">
            <?php if (!empty($coverSrc)): ?>
              <img src="<?php echo h($coverSrc); ?>" alt="" class="ere-prof__student-dash-bg-img">
            <?php else: ?>
              <div class="ere-prof__student-dash-bg-fallback"></div>
            <?php endif; ?>
          </div>
          <div class="ere-prof__student-dash-overlay" aria-hidden="true"></div>
          <form class="ere-prof__student-dash-upload" method="post" enctype="multipart/form-data" action="student_profile.php">
            <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
            <input type="hidden" name="profile_cover_upload" value="1">
            <input type="file" name="profile_cover" accept="image/jpeg,image/png,image/webp,image/gif" class="sr-only" data-ere-cover-input>
            <button type="button" class="ere-prof__student-dash-upload-btn" data-ere-cover-trigger>
              <i class="bi bi-camera" aria-hidden="true"></i> Update cover
            </button>
          </form>
        </div>
        <div class="ere-prof__student-dash-avatar-bridge">
          <div class="ere-prof__student-dash-avatar-ring">
            <button type="button" class="ere-prof__student-dash-avatar-btn" data-ere-prof-avatar-edit aria-label="Change profile photo">
              <?php if ($avatarSrc !== ''): ?>
                <img src="<?php echo h($avatarSrc); ?>" alt="" class="ere-prof__student-dash-avatar-img" width="220" height="220">
              <?php else: ?>
                <span class="ere-prof__student-dash-avatar-initial"><?php echo h($avatarInitial); ?></span>
              <?php endif; ?>
              <span class="ere-prof__student-dash-avatar-hint" aria-hidden="true"><i class="bi bi-camera"></i> Change</span>
            </button>
          </div>
        </div>
        <div class="ere-prof__student-dash-identity">
          <div class="ere-prof__student-dash-identity-inner">
            <h1 class="ere-prof__student-dash-title" id="ereProfHeading"><?php echo h($fullNameDisplay !== '' ? $fullNameDisplay : 'Your profile'); ?></h1>
            <?php if ($signInEmail !== ''): ?>
              <p class="ere-prof__student-dash-email m-0">
                <i class="bi bi-envelope-fill" aria-hidden="true"></i>
                <span><?php echo h($signInEmail); ?></span>
              </p>
            <?php endif; ?>
            <?php if ($bioRaw !== ''): ?>
              <p class="ere-prof__student-dash-bio m-0"><?php echo h($bioRaw); ?></p>
            <?php else: ?>
              <p class="ere-prof__student-dash-bio ere-prof__student-dash-bio--empty m-0">No bio yet — add a short introduction when you edit your profile.</p>
            <?php endif; ?>
          </div>
          <div class="ere-prof__student-dash-toolbar" role="group" aria-label="Profile status and actions">
            <div class="ere-prof__student-dash-toolbar__chips" aria-label="Profile tags">
              <span class="ere-prof__student-dash-chip">
                <i class="bi bi-mortarboard" aria-hidden="true"></i> <?php echo h($roleLabel); ?> · LCRC eReview
              </span>
              <?php if ($enrollmentBadgeText !== null): ?>
                <span class="ere-prof__student-dash-chip ere-prof__student-dash-chip--enroll"><?php echo h($enrollmentBadgeText); ?></span>
              <?php endif; ?>
              <?php if ($headerContextLineStr !== ''): ?>
                <span class="ere-prof__student-dash-chip ere-prof__student-dash-chip--muted"><?php echo h($headerContextLineStr); ?></span>
              <?php endif; ?>
            </div>
            <div class="ere-prof__student-dash-toolbar__cta">
              <button type="button" class="ere-prof__student-dash-btn ere-prof__student-dash-btn--primary" id="<?php echo h($ereviewProfileEditBtnId); ?>">
                <i class="bi bi-pencil-square" aria-hidden="true"></i> Edit profile
              </button>
              <?php if ($signInEmail !== ''): ?>
                <button
                  type="button"
                  class="ere-prof__student-dash-btn ere-prof__student-dash-btn--ghost"
                  data-ere-prof-copy="<?php echo h($signInEmail); ?>"
                  aria-label="Copy sign-in email"
                  title="Copy sign-in email to clipboard"
                >
                  <i class="bi bi-clipboard" aria-hidden="true"></i> Copy email
                </button>
              <?php endif; ?>
            </div>
            <div class="ere-prof__student-dash-toolbar__meta" aria-label="Sign-in activity">
              <?php if ($lastLogin): ?>
                <span class="ere-prof__student-dash-toolbar-meta-bit">
                  <i class="bi bi-clock-history" aria-hidden="true"></i>
                  Last login <?php echo h($lastLogin); ?>
                </span>
              <?php else: ?>
                <span class="ere-prof__student-dash-toolbar-meta-bit">
                  <i class="bi bi-clock-history" aria-hidden="true"></i>
                  Last login not recorded yet
                </span>
              <?php endif; ?>
              <?php if ($lastIp): ?>
                <span class="ere-prof__student-dash-toolbar-meta-sep" aria-hidden="true">·</span>
                <span class="ere-prof__student-dash-toolbar-meta-bit">
                  <span class="ere-prof__student-dash-toolbar-meta-ip-label">IP</span>
                  <?php echo h($lastIp); ?>
                </span>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    <?php endif; ?>
  </header>

  <?php if ($ereviewProfileTheme !== 'student'): ?>
  <section class="ere-prof__hero-details" aria-labelledby="ereProfHeading">
    <div class="ere-prof__hero-inner">
      <div class="ere-prof__hero-main">
        <div class="ere-prof__avatar" aria-hidden="true">
          <?php if ($avatarSrc !== ''): ?>
            <img src="<?php echo h($avatarSrc); ?>" alt="">
          <?php else: ?>
            <?php echo h($avatarInitial); ?>
          <?php endif; ?>
        </div>
        <div class="min-w-0">
          <h1 class="ere-prof__title" id="ereProfHeading"><?php echo h($fullNameDisplay); ?></h1>
          <div class="ere-prof__role-pill">
            <i class="bi bi-person-badge" aria-hidden="true"></i>
            <?php echo h($roleLabel); ?> · LCRC eReview
          </div>
          <?php if ($headerMetaBadges): ?>
            <div class="ere-prof__hero-badges" aria-label="Profile highlights">
              <?php foreach ($headerMetaBadges as $badge): ?>
                <span class="ere-prof__hero-badge"><?php echo h($badge); ?></span>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
          <?php if ($signInEmail !== ''): ?>
            <p class="ere-prof__hero-meta m-0"><?php echo h($signInEmail); ?></p>
          <?php endif; ?>
          <?php if ($bioRaw !== ''): ?>
            <p class="ere-prof__hero-bio m-0"><?php echo h($bioRaw); ?></p>
          <?php endif; ?>
        </div>
      </div>
      <div class="ere-prof__hero-actions">
        <button type="button" class="ere-prof__btn ere-prof__btn--primary" id="<?php echo h($ereviewProfileEditBtnId); ?>">
          <i class="bi bi-pencil-square" aria-hidden="true"></i> Edit profile
        </button>
        <?php if ($signInEmail !== ''): ?>
          <button
            type="button"
            class="ere-prof__btn ere-prof__btn--ghost"
            data-ere-prof-copy="<?php echo h($signInEmail); ?>"
            aria-label="Copy sign-in email"
          >
            <i class="bi bi-clipboard" aria-hidden="true"></i> Copy email
          </button>
        <?php endif; ?>
      </div>
    </div>
    <div class="ere-prof__hero-strip">
      <?php if ($lastLogin): ?>
        <span class="ere-prof__hero-chip">
          <i class="bi bi-clock-history" aria-hidden="true"></i>
          Last login <?php echo h($lastLogin); ?>
        </span>
      <?php else: ?>
        <span class="ere-prof__hero-chip">
          <i class="bi bi-clock-history" aria-hidden="true"></i>
          Last login not recorded yet
        </span>
      <?php endif; ?>
      <?php if ($lastIp): ?>
        <span class="ere-prof__dot" aria-hidden="true">·</span>
        <span class="ere-prof__hero-chip">
          <i class="bi bi-globe2" aria-hidden="true"></i>
          IP <?php echo h($lastIp); ?>
        </span>
      <?php endif; ?>
    </div>
  </section>
  <?php endif; ?>

  <nav class="ere-prof__chip-wrap" id="ereProfChipWrap" aria-label="Profile sections">
    <button type="button" class="ere-prof__chip is-active" id="ereProfChipOverview" data-ere-prof-chip data-ere-prof-target="ereProfSectionOverview" aria-current="true">Overview</button>
    <button type="button" class="ere-prof__chip" id="ereProfChipAccount" data-ere-prof-chip data-ere-prof-target="ereProfSectionAccount" aria-current="false">Account</button>
    <button type="button" class="ere-prof__chip" id="ereProfChipSecurity" data-ere-prof-chip data-ere-prof-target="ereProfSectionSecurity" aria-current="false">Security</button>
    <?php if ($ereviewProfileShowEnrollment): ?>
      <button type="button" class="ere-prof__chip" id="ereProfChipEnrollment" data-ere-prof-chip data-ere-prof-target="ereProfSectionEnrollment" aria-current="false">Enrollment</button>
    <?php endif; ?>
    <button type="button" class="ere-prof__chip" id="ereProfChipActivity" data-ere-prof-chip data-ere-prof-target="ereProfSectionActivity" aria-current="false">Activity</button>
  </nav>

  <div class="ere-prof__skeleton px-1 mb-6" aria-hidden="true">
    <div class="ere-prof__card mb-4"><div class="ere-prof__skel-line"></div><div class="ere-prof__skel-line" style="width:70%"></div></div>
    <div class="ere-prof__card"><div class="ere-prof__skel-line"></div><div class="ere-prof__skel-line"></div></div>
  </div>

  <div class="ere-prof__live">
    <section class="ere-prof__section" id="ereProfSectionOverview" aria-labelledby="ereProfOverviewTitle">
      <h2 class="ere-prof__section-head" id="ereProfOverviewTitle"><i class="bi bi-grid-1x2" aria-hidden="true"></i> Overview</h2>
      <div class="ere-prof__card">
        <div class="ere-prof__stat-grid">
          <?php if ($ereviewProfileTheme === 'student'): ?>
            <div class="ere-prof__stat ere-prof__stat--modern">
              <span class="ere-prof__stat-icon" aria-hidden="true"><i class="bi bi-person-vcard"></i></span>
              <div class="ere-prof__stat-text">
                <p class="ere-prof__stat-label">Full name</p>
                <p class="ere-prof__stat-value"><?php echo h($fullNameDisplay !== '' ? $fullNameDisplay : 'Not set'); ?></p>
              </div>
            </div>
            <div class="ere-prof__stat ere-prof__stat--modern">
              <span class="ere-prof__stat-icon" aria-hidden="true"><i class="bi bi-mortarboard"></i></span>
              <div class="ere-prof__stat-text">
                <p class="ere-prof__stat-label">Review type</p>
                <p class="ere-prof__stat-value" id="ereProfStatReviewType"><?php echo h($reviewTypeDisplay); ?></p>
              </div>
            </div>
            <div class="ere-prof__stat ere-prof__stat--modern">
              <span class="ere-prof__stat-icon" aria-hidden="true"><i class="bi bi-building"></i></span>
              <div class="ere-prof__stat-text">
                <p class="ere-prof__stat-label">School</p>
                <p class="ere-prof__stat-value" id="ereProfStatSchool"><?php echo h($schoolDisplay); ?></p>
              </div>
            </div>
            <div class="ere-prof__stat ere-prof__stat--modern">
              <span class="ere-prof__stat-icon" aria-hidden="true"><i class="bi bi-receipt-cutoff"></i></span>
              <div class="ere-prof__stat-text">
                <p class="ere-prof__stat-label">Proof of payment</p>
                <p class="ere-prof__stat-value" id="ereProfStatProof">
                  <?php if ($hasPaymentProof): ?>
                    <a class="ere-prof__proof-link" id="ereProfProofLink" href="<?php echo h($paymentProofUrl); ?>" target="_blank" rel="noopener">
                      <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i> View file
                    </a>
                  <?php else: ?>
                    Not uploaded
                  <?php endif; ?>
                </p>
              </div>
            </div>
            <div class="ere-prof__stat ere-prof__stat--modern">
              <span class="ere-prof__stat-icon" aria-hidden="true"><i class="bi bi-calendar-plus"></i></span>
              <div class="ere-prof__stat-text">
                <p class="ere-prof__stat-label">Member since</p>
                <p class="ere-prof__stat-value"><?php echo h($createdAt); ?></p>
              </div>
            </div>
            <div class="ere-prof__stat ere-prof__stat--modern">
              <span class="ere-prof__stat-icon" aria-hidden="true"><i class="bi bi-arrow-repeat"></i></span>
              <div class="ere-prof__stat-text">
                <p class="ere-prof__stat-label">Profile updated</p>
                <p class="ere-prof__stat-value"><?php echo h($updatedAt); ?></p>
              </div>
            </div>
            <?php if (!empty($cols['phone'])): ?>
              <div class="ere-prof__stat ere-prof__stat--modern">
                <span class="ere-prof__stat-icon" aria-hidden="true"><i class="bi bi-telephone"></i></span>
                <div class="ere-prof__stat-text">
                  <p class="ere-prof__stat-label">Phone</p>
                  <p class="ere-prof__stat-value"><?php echo $phoneDisp !== '' ? h($phoneDisp) : 'Not on file'; ?></p>
                </div>
              </div>
            <?php endif; ?>
            <div class="ere-prof__stat ere-prof__stat--modern">
              <span class="ere-prof__stat-icon" aria-hidden="true"><i class="bi bi-envelope-check"></i></span>
              <div class="ere-prof__stat-text">
                <p class="ere-prof__stat-label">Sign-in</p>
                <p class="ere-prof__stat-value"><?php echo $signInEmail !== '' ? 'Email on file' : '—'; ?></p>
              </div>
            </div>
            <?php if ($enrollEnd && $enrollDaysLeft !== null): ?>
              <?php
              $accClass = 'ere-prof__stat ere-prof__stat--accent';
              if ($enrollDaysLeft < 0) {
                  $accClass .= ' ere-prof__stat--danger';
              } elseif ($enrollDaysLeft <= 7) {
                  $accClass .= ' ere-prof__stat--warn';
              }
              ?>
              <div class="<?php echo $accClass; ?> ere-prof__stat--modern">
                <span class="ere-prof__stat-icon ere-prof__stat-icon--accent" aria-hidden="true"><i class="bi bi-calendar-range"></i></span>
                <div class="ere-prof__stat-text">
                  <p class="ere-prof__stat-label">Enrollment access</p>
                  <p class="ere-prof__stat-value">
                    <?php if ($enrollDaysLeft < 0): ?>
                      Access period ended
                    <?php elseif ($enrollDaysLeft === 0): ?>
                      Last day of access
                    <?php else: ?>
                      <?php echo (int)$enrollDaysLeft; ?> day<?php echo $enrollDaysLeft === 1 ? '' : 's'; ?> left
                    <?php endif; ?>
                  </p>
                </div>
              </div>
            <?php endif; ?>
          <?php else: ?>
            <div class="ere-prof__stat">
              <p class="ere-prof__stat-label">Account created</p>
              <p class="ere-prof__stat-value"><?php echo h($createdAt); ?></p>
            </div>
            <div class="ere-prof__stat">
              <p class="ere-prof__stat-label">Last updated</p>
              <p class="ere-prof__stat-value"><?php echo h($updatedAt); ?></p>
            </div>
            <?php if (!empty($cols['phone'])): ?>
              <div class="ere-prof__stat">
                <p class="ere-prof__stat-label">Phone</p>
                <p class="ere-prof__stat-value"><?php echo $phoneDisp !== '' ? h($phoneDisp) : 'Not on file'; ?></p>
              </div>
            <?php endif; ?>
            <div class="ere-prof__stat ere-prof__stat--accent ere-prof__stat--wide-force">
              <p class="ere-prof__stat-label">Role</p>
              <p class="ere-prof__stat-value"><?php echo h($roleLabel); ?></p>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </section>

    <section class="ere-prof__section ere-prof__section--delay-1" id="ereProfSectionAccount" aria-labelledby="ereProfAccountTitle">
      <h2 class="ere-prof__section-head" id="ereProfAccountTitle"><i class="bi bi-person-vcard" aria-hidden="true"></i> Account</h2>
      <div class="ere-prof__card">
        <div class="ere-prof__row">
          <p class="ere-prof__dt">Sign-in email</p>
          <div class="ere-prof__dd ere-prof__copy-wrap">
            <span><?php echo $signInEmail !== '' ? h($signInEmail) : '—'; ?></span>
            <?php if ($signInEmail !== ''): ?>
              <button type="button" class="ere-prof__copy" data-ere-prof-copy="<?php echo h($signInEmail); ?>" aria-label="Copy sign-in email" title="Copy email">
                <i class="bi bi-clipboard" aria-hidden="true"></i>
              </button>
            <?php endif; ?>
          </div>
        </div>
        <?php if (!empty($cols['phone'])): ?>
          <div class="ere-prof__row">
            <p class="ere-prof__dt">Phone</p>
            <div class="ere-prof__dd ere-prof__copy-wrap">
              <span><?php echo $phoneDisp !== '' ? h($phoneDisp) : '—'; ?></span>
              <?php if ($phoneDisp !== ''): ?>
                <button type="button" class="ere-prof__copy" data-ere-prof-copy="<?php echo h($phoneDisp); ?>" aria-label="Copy phone number" title="Copy phone">
                  <i class="bi bi-clipboard" aria-hidden="true"></i>
                </button>
              <?php endif; ?>
            </div>
          </div>
        <?php endif; ?>
        <?php if ($bioRaw !== ''): ?>
          <div class="ere-prof__bio"><?php echo nl2br(h($bioRaw)); ?></div>
        <?php endif; ?>
      </div>
    </section>

    <section class="ere-prof__section ere-prof__section--delay-2" id="ereProfSectionSecurity" aria-labelledby="ereProfSecurityTitle">
      <h2 class="ere-prof__section-head" id="ereProfSecurityTitle"><i class="bi bi-shield-lock" aria-hidden="true"></i> Security</h2>
      <div class="ere-prof__card">
        <p class="ere-prof__hint m-0 mb-3">Password and recent sign-in details stay collapsed until you need them.</p>
        <details class="ere-prof__security-details">
          <summary class="ere-prof__security-summary">
            <span>Password &amp; sign-in activity</span>
          </summary>
          <div class="ere-prof__security-body">
            <div class="ere-prof__row ere-prof__row--tight-top">
              <p class="ere-prof__dt">Password</p>
              <p class="ere-prof__dd">••••••••</p>
            </div>
            <div class="ere-prof__row">
              <p class="ere-prof__dt">Last login</p>
              <p class="ere-prof__dd"><?php echo h($lastLogin ?? '—'); ?></p>
            </div>
            <?php if ($lastIp): ?>
              <div class="ere-prof__row">
                <p class="ere-prof__dt">Last IP</p>
                <p class="ere-prof__dd"><?php echo h($lastIp); ?></p>
              </div>
            <?php endif; ?>
            <button type="button" class="ere-prof__btn-sec" id="<?php echo h($ereviewProfilePwBtnId); ?>">
              <i class="bi bi-key" aria-hidden="true"></i> <?php echo h($pwLabel); ?>
            </button>
          </div>
        </details>
      </div>
    </section>

    <?php if ($ereviewProfileShowEnrollment): ?>
      <section class="ere-prof__section ere-prof__section--delay-2" id="ereProfSectionEnrollment" aria-labelledby="ereProfEnrollTitle">
        <h2 class="ere-prof__section-head" id="ereProfEnrollTitle"><i class="bi bi-calendar-check" aria-hidden="true"></i> Enrollment</h2>
        <div class="ere-prof__card">
          <dl class="m-0">
            <?php if ($enrollStart): ?>
              <div class="ere-prof__row">
                <p class="ere-prof__dt">Access began</p>
                <p class="ere-prof__dd"><?php echo h($enrollStart); ?> · PHT</p>
              </div>
            <?php endif; ?>
            <?php if ($enrollEnd): ?>
              <div class="ere-prof__row">
                <p class="ere-prof__dt">Access ends</p>
                <p class="ere-prof__dd"><?php echo h($enrollEnd); ?> · PHT</p>
              </div>
            <?php endif; ?>
          </dl>
        </div>
      </section>
    <?php endif; ?>

    <section class="ere-prof__section ere-prof__section--delay-3" id="ereProfSectionActivity" aria-labelledby="ereProfActivityTitle">
      <h2 class="ere-prof__section-head" id="ereProfActivityTitle"><i class="bi bi-lightning-charge" aria-hidden="true"></i> Recent activity</h2>
      <div class="ere-prof__card">
        <?php if ($activity): ?>
          <?php foreach ($activity as $act): ?>
            <div class="ere-prof__activity-item">
              <span><span class="ere-prof__activity-strong"><?php echo h($act['label']); ?></span> · <?php echo h($act['detail']); ?></span>
              <span class="ere-prof__activity-time"><?php echo h($act['time']); ?></span>
            </div>
          <?php endforeach; ?>
        <?php else: ?>
          <p class="ere-prof__hint m-0">No recent quiz or preboard submissions yet.</p>
        <?php endif; ?>
      </div>
    </section>

    <?php if ($ereviewProfileTheme === 'staff'): ?>
      <section class="ere-prof__section ere-prof__section--delay-3 ere-prof__hint-card ere-prof__card" aria-label="Note">
        <p class="ere-prof__hint m-0">
          <i class="bi bi-info-circle" aria-hidden="true"></i>
          Workspace metrics and alerts stay on your dashboard. Update your photo or contact details anytime with Edit profile.
        </p>
      </section>
    <?php endif; ?>
  </div>
</div>
