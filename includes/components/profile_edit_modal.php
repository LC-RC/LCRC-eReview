<?php
/**
 * Global profile editor modal (included from app_shell_topbar). Requires auth + generateCSRFToken.
 */
$__ereviewCsrf = function_exists('generateCSRFToken') ? generateCSRFToken() : '';
$ereviewPeditUserId = 0;
if (function_exists('getCurrentUserId')) {
    $___uid = getCurrentUserId();
    $ereviewPeditUserId = $___uid ? (int)$___uid : 0;
}
$___peditRole = function_exists('getCurrentUserRole') ? (string) getCurrentUserRole() : '';
$ereviewPeditSaveStayOpen = function_exists('isStaffRole') && isStaffRole($___peditRole);
$ereviewPeditEmailLocked = ($___peditRole === 'student' || $___peditRole === 'college_student');
$ereviewPeditUiTheme = $ereviewPeditSaveStayOpen ? 'staff' : 'student';
$ereviewPeditHelpHref = $ereviewHelpHref ?? 'help_center.php';
$ereviewPeditBoot = [
    'userId' => $ereviewPeditUserId,
    'saveStayOpen' => $ereviewPeditSaveStayOpen,
    'emailLocked' => $ereviewPeditEmailLocked,
    'uiTheme' => $ereviewPeditUiTheme,
    'csrf' => $__ereviewCsrf,
    'analytics' => true,
];
?>
<div id="ereviewProfileEditOverlay" class="ere-pedit-overlay" data-ere-pedit-theme="<?php echo h($ereviewPeditUiTheme); ?>" hidden aria-hidden="true">
  <div class="ere-pedit-backdrop" data-ereview-profile-request-close></div>
  <div class="ere-pedit-shell" role="dialog" aria-modal="true" aria-labelledby="ereviewProfileEditTitle" data-ere-pedit-theme="<?php echo h($ereviewPeditUiTheme); ?>">
    <div id="erePeditLiveRegion" class="sr-only" aria-live="polite" aria-atomic="true"></div>
    <script type="application/json" id="ereviewPeditBoot"><?php echo json_encode($ereviewPeditBoot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>

    <div id="erePeditDiscardOverlay" class="ere-pedit-discard-overlay" hidden>
      <div class="ere-pedit-discard-card" role="alertdialog" aria-modal="true" aria-labelledby="erePeditDiscardTitle" aria-describedby="erePeditDiscardDesc">
        <h3 id="erePeditDiscardTitle" class="ere-pedit-discard-title">Discard changes?</h3>
        <p id="erePeditDiscardDesc" class="ere-pedit-discard-desc">You have unsaved edits. If you close now, those changes will be lost.</p>
        <div class="ere-pedit-discard-actions">
          <button type="button" class="ere-pedit-btn ere-pedit-btn--primary" id="erePeditDiscardStay">Keep editing</button>
          <button type="button" class="ere-pedit-btn ere-pedit-btn--outline ere-pedit-btn--danger-outline" id="erePeditDiscardConfirm">Discard</button>
        </div>
      </div>
    </div>

    <header class="ere-pedit-top ere-pedit-top--branded">
      <div class="ere-pedit-top-icon" aria-hidden="true"><i class="bi bi-person-fill-gear"></i></div>
      <div class="ere-pedit-top-text">
        <h2 id="ereviewProfileEditTitle" class="ere-pedit-title">Edit profile</h2>
        <p class="ere-pedit-lead">Photo, contact details, and optional password update.<?php if ($ereviewPeditEmailLocked): ?> <span class="ere-pedit-lead-privacy">Your name, photo, and bio may be shown on the profile your program sees.</span><?php endif; ?></p>
      </div>
      <button type="button" class="ere-pedit-close" data-ereview-profile-request-close aria-label="Close dialog">
        <i class="bi bi-x-lg" aria-hidden="true"></i>
      </button>
    </header>

    <div id="ereviewProfileEditSkeleton" class="ere-pedit-skel" hidden>
      <div class="ere-pedit-skel-grid">
        <div class="ere-pedit-skel-ph"></div>
        <div class="ere-pedit-skel-lines">
          <div class="ere-pedit-skel-line"></div>
          <div class="ere-pedit-skel-line ere-pedit-skel-line--short"></div>
          <div class="ere-pedit-skel-line"></div>
        </div>
      </div>
      <div class="ere-pedit-skel-line"></div>
      <div class="ere-pedit-skel-line ere-pedit-skel-line--mid"></div>
    </div>

    <form id="ereviewProfileEditForm" class="ere-pedit-form" enctype="multipart/form-data" autocomplete="off" hidden>
      <input type="hidden" name="csrf_token" value="<?php echo h($__ereviewCsrf); ?>">
      <input type="hidden" name="remove_avatar" id="ereviewProfileRemoveAvatar" value="0">

      <div class="ere-pedit-scroll" id="erePeditScrollRegion">
        <div id="erePeditDraftBanner" class="ere-pedit-draft-banner" role="status" hidden>
          <div class="ere-pedit-draft-banner__text">
            <i class="bi bi-cloud-arrow-down" aria-hidden="true"></i>
            <span>Restored an unsaved draft for phone and bio from this browser.</span>
          </div>
          <div class="ere-pedit-draft-banner__actions">
            <button type="button" class="ere-pedit-btn ere-pedit-btn--ghost ere-pedit-btn--sm" id="erePeditDraftUseServer">Use saved profile</button>
            <button type="button" class="ere-pedit-btn ere-pedit-btn--outline ere-pedit-btn--sm" id="erePeditDraftDismiss">Dismiss</button>
          </div>
        </div>

        <nav class="ere-pedit-chips" id="erePeditChips" aria-label="Form sections">
          <button type="button" class="ere-pedit-chip is-active" data-ere-pedit-chip data-ere-pedit-target="erePeditPhotoZone" id="erePeditChipProfile" aria-current="true">Profile</button>
          <button type="button" class="ere-pedit-chip" data-ere-pedit-chip data-ere-pedit-target="erePeditSectionContact" id="erePeditChipContact" aria-current="false">Contact</button>
          <button type="button" class="ere-pedit-chip" data-ere-pedit-chip data-ere-pedit-target="erePeditSectionSecurity" id="erePeditChipSecurity" aria-current="false">Security</button>
        </nav>

        <section class="ere-pedit-section ere-pedit-card ere-pedit-card--hero ere-pedit-photo-dropzone" id="erePeditPhotoZone" aria-labelledby="ere-pedit-photo-label">
          <h3 id="ere-pedit-photo-label" class="ere-pedit-hero-title">Profile photo</h3>
          <p class="ere-pedit-drop-hint ere-pedit-drop-hint--hero" id="erePeditDropHint"><i class="bi bi-cloud-arrow-up" aria-hidden="true"></i> Drop an image here or tap <strong>Change photo</strong></p>
          <div class="ere-pedit-hero-layout">
            <button type="button" class="ere-pedit-avatar-btn ere-pedit-avatar-btn--hero" id="ereviewProfilePhotoPreview" aria-label="Open photo chooser">
              <span class="ere-pedit-avatar-placeholder" id="ereviewProfilePhotoPlaceholder">
                <span class="ere-pedit-avatar-initial" id="erePeditAvatarInitial">?</span>
                <span class="ere-pedit-avatar-empty-msg">No photo yet</span>
              </span>
              <img alt="" class="ere-pedit-avatar-img" id="ereviewProfilePhotoImg" width="160" height="160" decoding="async">
              <span class="ere-pedit-avatar-shade">
                <i class="bi bi-camera-fill" aria-hidden="true"></i>
                <span>Change</span>
              </span>
            </button>
            <div class="ere-pedit-hero-actions">
              <button type="button" class="ere-pedit-btn ere-pedit-btn--primary ere-pedit-btn--change-photo" id="ereviewProfilePickPhoto">
                <i class="bi bi-camera-fill" aria-hidden="true"></i> Change photo
              </button>
              <button type="button" class="ere-pedit-link-danger" id="ereviewProfileClearPhoto">Remove photo</button>
              <p class="ere-pedit-micro">JPG, PNG, WebP, or GIF · max 2&nbsp;MB · square crop after upload</p>
              <p class="ere-pedit-compress-hint" id="erePeditCompressHint" hidden></p>
            </div>
          </div>
          <div id="erePeditCropStage" class="ere-pedit-crop-stage" hidden>
            <div class="ere-pedit-crop-head">
              <span class="ere-pedit-crop-title"><i class="bi bi-crop" aria-hidden="true"></i> Adjust crop</span>
              <span class="ere-pedit-crop-sub">1:1 · drag to move · scroll or pinch to zoom</span>
            </div>
            <div class="ere-pedit-crop-frame">
              <img class="ere-pedit-crop-img" id="erePeditCropImg" alt="">
            </div>
            <div class="ere-pedit-crop-actions">
              <button type="button" class="ere-pedit-btn ere-pedit-btn--ghost" id="erePeditCropCancel">Cancel</button>
              <button type="button" class="ere-pedit-btn ere-pedit-btn--outline" id="erePeditCropApply">Apply crop</button>
            </div>
          </div>
          <input type="file" id="ereviewProfileFileInput" name="profile_image" accept="image/jpeg,image/png,image/webp,image/gif" class="sr-only">
        </section>

        <section id="erePeditSectionContact" class="ere-pedit-section ere-pedit-section--contact" aria-labelledby="ere-pedit-contact-label">
          <div class="ere-pedit-card ere-pedit-card--fields ere-pedit-card--stack">
            <h3 id="ere-pedit-contact-label" class="ere-pedit-card-title">Contact</h3>
            <div class="ere-pedit-field">
              <label class="ere-pedit-label" for="ereviewProfileFullName">Full name</label>
              <div class="ere-pedit-input-row">
                <input class="ere-pedit-input ere-pedit-input--grow" type="text" id="ereviewProfileFullName" name="full_name" required maxlength="200" placeholder="Your full name">
                <span class="ere-pedit-valid-badge" id="erePeditValidName" hidden aria-hidden="true" title="Looks good"><i class="bi bi-check-lg"></i></span>
              </div>
              <p class="ere-pedit-err" id="ereviewErrFullName" hidden role="alert"></p>
            </div>
            <div class="ere-pedit-field">
              <label class="ere-pedit-label" for="ereviewProfileEmail">Email</label>
              <div class="ere-pedit-input-row">
                <input class="ere-pedit-input ere-pedit-input--grow<?php echo $ereviewPeditEmailLocked ? ' ere-pedit-input--readonly' : ''; ?>" type="email" id="ereviewProfileEmail" name="email" required maxlength="190" placeholder="you@example.com" inputmode="email" autocomplete="email"<?php echo $ereviewPeditEmailLocked ? ' readonly aria-readonly="true"' : ''; ?>>
                <span class="ere-pedit-valid-badge" id="erePeditValidEmail" hidden aria-hidden="true" title="Looks good"><i class="bi bi-check-lg"></i></span>
              </div>
              <p class="ere-pedit-trust" id="erePeditEmailTrust"><?php echo $ereviewPeditEmailLocked
                ? 'This is your sign-in email. To change it, please contact your administrator.'
                : 'Used to sign in. Changing it will update your login.'; ?></p>
              <div class="ere-pedit-email-status" id="ereviewEmailAvailRow" hidden<?php echo $ereviewPeditEmailLocked ? ' data-ere-pedit-skip-email-status="1"' : ''; ?>>
                <span class="ere-pedit-email-status-inner" id="ereviewEmailAvailText" aria-live="polite"></span>
              </div>
              <p class="ere-pedit-err" id="ereviewErrEmail" hidden role="alert"></p>
            </div>
            <div class="ere-pedit-field">
              <label class="ere-pedit-label" for="ereviewProfilePhone">Phone <span class="ere-pedit-opt">optional</span></label>
              <input class="ere-pedit-input" type="tel" id="ereviewProfilePhone" name="phone" maxlength="40" autocomplete="tel" placeholder="+63 · optional">
              <p class="ere-pedit-err" id="ereviewErrPhone" hidden role="alert"></p>
            </div>
          </div>
          <div class="ere-pedit-card ere-pedit-card--full ere-pedit-card--bio ere-pedit-card--stack">
            <div class="ere-pedit-label-row">
              <label class="ere-pedit-label" for="ereviewProfileBio">Short bio <span class="ere-pedit-opt">optional</span></label>
              <span class="ere-pedit-count" id="ereviewProfileBioCount" aria-live="polite">0 / 500</span>
            </div>
            <textarea class="ere-pedit-textarea" id="ereviewProfileBio" name="profile_bio" rows="3" maxlength="500" placeholder="A line or two about you — shown on your profile."></textarea>
            <p class="ere-pedit-err" id="ereviewErrBio" hidden role="alert"></p>
          </div>
        </section>

        <section id="erePeditSectionSecurity" class="ere-pedit-section ere-pedit-section--security" aria-labelledby="ere-pedit-security-label">
          <h3 id="ere-pedit-security-label" class="ere-pedit-security-heading">Security</h3>
          <p class="ere-pedit-security-lead">Update your password and learn about future sign-in options.</p>
          <details class="ere-pedit-pw-details" id="ereEditPwDetails">
            <summary class="ere-pedit-pw-summary">
              <span class="ere-pedit-pw-summary-inner">
                <i class="bi bi-shield-lock" aria-hidden="true"></i>
                <span class="ere-pedit-pw-summary-text">Change password</span>
                <span class="ere-pedit-pw-summary-hint">Leave closed to keep your current password</span>
              </span>
              <i class="bi bi-chevron-down ere-pedit-pw-chev" aria-hidden="true"></i>
            </summary>
            <div class="ere-pedit-pw-panel">
              <p class="ere-pedit-hint">Use at least 8 characters with uppercase, lowercase, and a number.</p>
              <p class="ere-pedit-future-hint">Passkeys and two-step verification may be offered here in a future update. <a href="<?php echo h($ereviewPeditHelpHref); ?>" class="ere-pedit-inline-link" target="_blank" rel="noopener noreferrer">Help center</a></p>
              <div class="ere-pedit-pw-tools">
                <button type="button" class="ere-pedit-btn ere-pedit-btn--outline ere-pedit-btn--sm" id="erePeditPwGenerate" title="Fill both fields with a strong random password">
                  <i class="bi bi-shuffle" aria-hidden="true"></i> Suggest password
                </button>
                <button type="button" class="ere-pedit-btn ere-pedit-btn--ghost ere-pedit-btn--sm" id="erePeditPwCopy" title="Copy new password to clipboard">
                  <i class="bi bi-clipboard" aria-hidden="true"></i> Copy
                </button>
              </div>
              <div class="ere-pedit-field">
                <label class="ere-pedit-label" for="ereviewProfilePw">New password</label>
                <div class="ere-pedit-pw-wrap">
                  <input class="ere-pedit-input ere-pedit-input--pw" type="password" id="ereviewProfilePw" name="password" autocomplete="new-password" placeholder="••••••••">
                  <button type="button" class="ere-pedit-pw-toggle" data-target="ereviewProfilePw" aria-label="Show password"><i class="bi bi-eye" aria-hidden="true"></i></button>
                </div>
                <div class="ere-pedit-strength" id="ereviewPwStrength" aria-hidden="true">
                  <span class="ere-pedit-strength-bar" data-i="0"></span>
                  <span class="ere-pedit-strength-bar" data-i="1"></span>
                  <span class="ere-pedit-strength-bar" data-i="2"></span>
                  <span class="ere-pedit-strength-bar" data-i="3"></span>
                </div>
                <p class="ere-pedit-err" id="ereviewErrPw" hidden role="alert"></p>
              </div>
              <div class="ere-pedit-field">
                <label class="ere-pedit-label" for="ereviewProfilePw2">Confirm password</label>
                <div class="ere-pedit-pw-wrap">
                  <input class="ere-pedit-input ere-pedit-input--pw" type="password" id="ereviewProfilePw2" name="password_confirm" autocomplete="new-password" placeholder="Repeat new password">
                  <button type="button" class="ere-pedit-pw-toggle" data-target="ereviewProfilePw2" aria-label="Show password"><i class="bi bi-eye" aria-hidden="true"></i></button>
                </div>
                <p class="ere-pedit-err" id="ereviewErrPw2" hidden role="alert"></p>
              </div>
            </div>
          </details>
        </section>
      </div>

      <footer class="ere-pedit-foot">
        <button type="button" class="ere-pedit-btn ere-pedit-btn--ghost" data-ereview-profile-request-close>Cancel</button>
        <div class="ere-pedit-foot-actions">
          <span class="ere-pedit-saved-chip" id="erePeditSavedChip" hidden role="status"><i class="bi bi-check2-circle" aria-hidden="true"></i> Saved</span>
          <button type="button" class="ere-pedit-btn ere-pedit-btn--outline" id="ereviewProfileSaveStay"<?php echo $ereviewPeditSaveStayOpen ? '' : ' hidden'; ?>>
            <i class="bi bi-pin-angle" aria-hidden="true"></i> Save &amp; stay open
          </button>
          <button type="submit" class="ere-pedit-btn ere-pedit-btn--primary" id="ereviewProfileSubmit">
            <span class="ere-pedit-submit-label"><i class="bi bi-check2-circle" aria-hidden="true"></i> Save changes</span>
            <span class="ere-pedit-submit-loading" hidden><i class="bi bi-arrow-repeat ere-pedit-spin" aria-hidden="true"></i> Saving…</span>
          </button>
        </div>
      </footer>
    </form>
  </div>
</div>

<div id="ereviewAppToastHost" class="ere-pedit-toast-host" aria-live="polite"></div>

<style>
/* —— Edit profile modal —— */
.sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0,0,0,0); white-space: nowrap; border: 0; }

.ere-pedit-overlay {
  position: fixed;
  inset: 0;
  z-index: 10050;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1rem;
  overflow-y: auto;
  -webkit-overflow-scrolling: touch;
}
.ere-pedit-overlay[hidden] { display: none !important; }

.ere-pedit-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(15, 23, 42, 0.55);
  backdrop-filter: blur(6px);
  -webkit-backdrop-filter: blur(6px);
}

.ere-pedit-shell {
  position: relative;
  width: 100%;
  max-width: 40rem;
  max-height: min(92vh, 44rem);
  display: flex;
  flex-direction: column;
  margin: auto;
  background: #fff;
  border-radius: 1.25rem;
  border: 1px solid rgba(22, 101, 160, 0.12);
  box-shadow:
    0 0 0 1px rgba(255,255,255,0.6) inset,
    0 25px 50px -12px rgba(20, 61, 89, 0.35),
    0 12px 24px -16px rgba(15, 23, 42, 0.2);
  overflow: hidden;
}

@keyframes erePeditShellIn {
  from {
    opacity: 0;
    transform: scale(0.97) translateY(10px);
  }
  to {
    opacity: 1;
    transform: none;
  }
}
.ere-pedit-overlay:not([hidden]) .ere-pedit-shell {
  animation: erePeditShellIn 0.32s cubic-bezier(0.22, 1, 0.36, 1) both;
}
.ere-pedit-overlay:not([hidden]) .ere-pedit-backdrop {
  animation: erePeditBackdropIn 0.22s ease-out both;
}
@keyframes erePeditBackdropIn {
  from { opacity: 0; }
  to { opacity: 1; }
}

.ere-pedit-chips {
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem;
  padding: 0.15rem 0 0.85rem;
  margin: 0 0 0.15rem;
  position: sticky;
  top: 0;
  z-index: 4;
  background: linear-gradient(180deg, #fff 70%, rgba(255,255,255,0.92) 100%);
  border-bottom: 1px solid #e8f0f8;
}
.ere-pedit-chip {
  border: 1px solid #c5ddf0;
  background: #f8fafc;
  color: #475569;
  font-size: 0.78rem;
  font-weight: 700;
  padding: 0.45rem 0.85rem;
  border-radius: 999px;
  cursor: pointer;
  transition: background 0.15s ease, color 0.15s ease, border-color 0.15s ease, box-shadow 0.15s ease;
}
.ere-pedit-chip:hover {
  background: #f0f7ff;
  border-color: #9dc4e8;
  color: #143D59;
}
.ere-pedit-chip.is-active {
  background: linear-gradient(180deg, #1a6fb0 0%, #1665A0 100%);
  border-color: #124e7a;
  color: #fff;
  box-shadow: 0 4px 12px -4px rgba(22, 101, 160, 0.45);
}
.ere-pedit-chip:focus-visible {
  outline: none;
  box-shadow: 0 0 0 3px rgba(22, 101, 160, 0.35);
}

.ere-pedit-hero-title {
  margin: 0 0 0.5rem;
  font-size: 0.6875rem;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: #1665A0;
}
.ere-pedit-drop-hint--hero {
  margin-bottom: 0.85rem;
}
.ere-pedit-card--hero {
  padding: 1.15rem 1.1rem 1.1rem;
  background: linear-gradient(180deg, #f4f9ff 0%, #ffffff 55%);
  border-color: #c5ddf0;
  box-shadow: 0 4px 24px -16px rgba(22, 101, 160, 0.25);
}
.ere-pedit-hero-layout {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 1rem;
}
@media (min-width: 640px) {
  .ere-pedit-hero-layout {
    flex-direction: row;
    align-items: center;
    justify-content: center;
    gap: 1.35rem;
  }
}
.ere-pedit-hero-actions {
  display: flex;
  flex-direction: column;
  align-items: stretch;
  gap: 0.45rem;
  width: 100%;
  max-width: 16rem;
}
@media (min-width: 640px) {
  .ere-pedit-hero-actions {
    align-items: stretch;
    max-width: 14rem;
  }
}
.ere-pedit-btn--change-photo {
  justify-content: center;
  width: 100%;
}
.ere-pedit-avatar-btn--hero {
  width: 9.5rem;
  height: 9.5rem;
  border-radius: 1.35rem;
}
@media (min-width: 640px) {
  .ere-pedit-avatar-btn--hero {
    width: 10.5rem;
    height: 10.5rem;
  }
}
.ere-pedit-avatar-initial {
  font-size: 2.35rem;
  font-weight: 800;
  color: #1665A0;
  line-height: 1;
}
.ere-pedit-avatar-empty-msg {
  font-size: 0.68rem;
  font-weight: 700;
  color: #64748b;
  text-transform: none;
  letter-spacing: 0.02em;
  max-width: 6.5rem;
  text-align: center;
  line-height: 1.25;
}
.ere-pedit-security-heading {
  margin: 0 0 0.35rem;
  font-size: 0.6875rem;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: #1665A0;
}
.ere-pedit-security-lead {
  margin: 0 0 0.75rem;
  font-size: 0.78rem;
  color: #64748b;
  font-weight: 600;
  line-height: 1.45;
}
.ere-pedit-section--security {
  padding-bottom: 0.25rem;
}

.ere-pedit-saved-chip {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  padding: 0.35rem 0.65rem;
  border-radius: 999px;
  font-size: 0.78rem;
  font-weight: 800;
  color: #065f46;
  background: #d1fae5;
  border: 1px solid #6ee7b7;
  animation: erePeditChipIn 0.28s ease-out;
}
.ere-pedit-saved-chip[hidden] {
  display: none !important;
}
.ere-pedit-saved-chip i {
  font-size: 1rem;
}
@keyframes erePeditChipIn {
  from { opacity: 0; transform: scale(0.92); }
  to { opacity: 1; transform: none; }
}

/* —— Student (reviewee) theme: blue-forward —— */
.ere-pedit-overlay[data-ere-pedit-theme="student"] .ere-pedit-top--branded {
  background: linear-gradient(135deg, #1665A0 0%, #143D59 48%, #1a4d6e 100%);
  border-bottom: 1px solid rgba(255, 255, 255, 0.12);
}
.ere-pedit-overlay[data-ere-pedit-theme="student"] .ere-pedit-top-icon {
  background: rgba(255, 255, 255, 0.18);
  border: 1px solid rgba(255, 255, 255, 0.28);
  box-shadow: none;
  color: #fff;
}
.ere-pedit-overlay[data-ere-pedit-theme="student"] .ere-pedit-title {
  color: #fff;
}
.ere-pedit-overlay[data-ere-pedit-theme="student"] .ere-pedit-lead {
  color: rgba(255, 255, 255, 0.88);
}
.ere-pedit-overlay[data-ere-pedit-theme="student"] .ere-pedit-lead-privacy {
  color: rgba(255, 255, 255, 0.72);
}
.ere-pedit-overlay[data-ere-pedit-theme="student"] .ere-pedit-close {
  background: rgba(255, 255, 255, 0.15);
  border-color: rgba(255, 255, 255, 0.35);
  color: #fff;
}
.ere-pedit-overlay[data-ere-pedit-theme="student"] .ere-pedit-close:hover {
  background: rgba(255, 255, 255, 0.28);
  color: #fff;
  border-color: rgba(255, 255, 255, 0.5);
}
.ere-pedit-overlay[data-ere-pedit-theme="student"] .ere-pedit-shell {
  border-color: rgba(22, 101, 160, 0.2);
  box-shadow:
    0 0 0 1px rgba(255,255,255,0.5) inset,
    0 28px 60px -20px rgba(20, 61, 89, 0.45);
}
.ere-pedit-overlay[data-ere-pedit-theme="student"] .ere-pedit-chips {
  background: linear-gradient(180deg, #fff 65%, rgba(248, 252, 255, 0.95) 100%);
  border-bottom-color: #d6e8f7;
}

/* —— Staff / admin theme: dark, neutral —— */
.ere-pedit-overlay[data-ere-pedit-theme="staff"] .ere-pedit-top--branded {
  background: linear-gradient(135deg, #0f172a 0%, #1e293b 55%, #0f172a 100%);
  border-bottom: 1px solid rgba(255, 255, 255, 0.08);
}
.ere-pedit-overlay[data-ere-pedit-theme="staff"] .ere-pedit-top-icon {
  background: linear-gradient(145deg, #334155, #1e293b);
  border: 1px solid rgba(255, 255, 255, 0.12);
  box-shadow: 0 8px 24px -10px rgba(0, 0, 0, 0.5);
  color: #e2e8f0;
}
.ere-pedit-overlay[data-ere-pedit-theme="staff"] .ere-pedit-title {
  color: #f8fafc;
}
.ere-pedit-overlay[data-ere-pedit-theme="staff"] .ere-pedit-lead {
  color: #94a3b8;
}
.ere-pedit-overlay[data-ere-pedit-theme="staff"] .ere-pedit-lead-privacy {
  color: #cbd5e1;
}
.ere-pedit-overlay[data-ere-pedit-theme="staff"] .ere-pedit-close {
  background: rgba(255, 255, 255, 0.08);
  border-color: rgba(255, 255, 255, 0.15);
  color: #e2e8f0;
}
.ere-pedit-overlay[data-ere-pedit-theme="staff"] .ere-pedit-close:hover {
  background: rgba(255, 255, 255, 0.14);
  color: #fff;
}
.ere-pedit-overlay[data-ere-pedit-theme="staff"] .ere-pedit-shell {
  border-color: #334155;
  box-shadow:
    0 0 0 1px rgba(255,255,255,0.04) inset,
    0 28px 60px -18px rgba(0, 0, 0, 0.55);
}
.ere-pedit-overlay[data-ere-pedit-theme="staff"] .ere-pedit-chips {
  background: linear-gradient(180deg, #fff 65%, #f8fafc 100%);
  border-bottom-color: #e2e8f0;
}
.ere-pedit-overlay[data-ere-pedit-theme="staff"] .ere-pedit-chip {
  border-color: #cbd5e1;
  background: #f1f5f9;
  color: #475569;
}
.ere-pedit-overlay[data-ere-pedit-theme="staff"] .ere-pedit-chip:hover {
  background: #e2e8f0;
  border-color: #94a3b8;
  color: #0f172a;
}
.ere-pedit-overlay[data-ere-pedit-theme="staff"] .ere-pedit-chip.is-active {
  background: linear-gradient(180deg, #1e293b 0%, #0f172a 100%);
  border-color: #020617;
  color: #f8fafc;
  box-shadow: 0 4px 14px -4px rgba(0, 0, 0, 0.45);
}
.ere-pedit-overlay[data-ere-pedit-theme="staff"] .ere-pedit-chip:focus-visible {
  box-shadow: 0 0 0 3px rgba(51, 65, 85, 0.45);
}
.ere-pedit-overlay[data-ere-pedit-theme="staff"] .ere-pedit-card-title,
.ere-pedit-overlay[data-ere-pedit-theme="staff"] .ere-pedit-hero-title,
.ere-pedit-overlay[data-ere-pedit-theme="staff"] .ere-pedit-security-heading {
  color: #0f172a;
}
.ere-pedit-overlay[data-ere-pedit-theme="staff"] .ere-pedit-card--hero {
  background: linear-gradient(180deg, #f8fafc 0%, #fff 60%);
  border-color: #e2e8f0;
  box-shadow: 0 4px 24px -16px rgba(15, 23, 42, 0.2);
}
.ere-pedit-overlay[data-ere-pedit-theme="staff"] .ere-pedit-avatar-btn--hero {
  box-shadow: 0 0 0 2px rgba(51, 65, 85, 0.2), 0 10px 28px -14px rgba(15, 23, 42, 0.35);
}
.ere-pedit-overlay[data-ere-pedit-theme="staff"] .ere-pedit-avatar-initial {
  color: #334155;
}
.ere-pedit-overlay[data-ere-pedit-theme="staff"] .ere-pedit-drop-hint i {
  color: #475569;
}
.ere-pedit-overlay[data-ere-pedit-theme="staff"] .ere-pedit-label {
  color: #0f172a;
}
.ere-pedit-overlay[data-ere-pedit-theme="staff"] .ere-pedit-input:focus,
.ere-pedit-overlay[data-ere-pedit-theme="staff"] .ere-pedit-textarea:focus {
  border-color: #334155;
  box-shadow: 0 0 0 3px rgba(51, 65, 85, 0.2);
}
.ere-pedit-overlay[data-ere-pedit-theme="staff"] .ere-pedit-btn--primary {
  background: linear-gradient(180deg, #1e293b 0%, #0f172a 100%);
  box-shadow: 0 4px 14px -4px rgba(0, 0, 0, 0.4);
}
.ere-pedit-overlay[data-ere-pedit-theme="staff"] .ere-pedit-btn--primary:hover:not(:disabled) {
  background: linear-gradient(180deg, #334155 0%, #1e293b 100%);
}
.ere-pedit-overlay[data-ere-pedit-theme="staff"] .ere-pedit-btn--outline {
  border-color: #cbd5e1;
  color: #0f172a;
}
.ere-pedit-overlay[data-ere-pedit-theme="staff"] .ere-pedit-btn--outline:hover {
  border-color: #64748b;
  background: #f8fafc;
}
.ere-pedit-overlay[data-ere-pedit-theme="staff"] .ere-pedit-inline-link {
  color: #1e40af;
}
.ere-pedit-overlay[data-ere-pedit-theme="staff"] .ere-pedit-inline-link:hover {
  color: #1e3a8a;
}
.ere-pedit-overlay[data-ere-pedit-theme="staff"] .ere-pedit-pw-details {
  border-color: #e2e8f0;
  background: #f8fafc;
}
.ere-pedit-overlay[data-ere-pedit-theme="staff"] .ere-pedit-pw-summary {
  color: #0f172a;
}
.ere-pedit-overlay[data-ere-pedit-theme="staff"] .ere-pedit-discard-title {
  color: #0f172a;
}

.ere-pedit-discard-overlay {
  position: absolute;
  inset: 0;
  z-index: 50;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1rem;
  background: rgba(15, 23, 42, 0.35);
  backdrop-filter: blur(2px);
}
.ere-pedit-discard-overlay[hidden] { display: none !important; }
.ere-pedit-discard-card {
  max-width: 22rem;
  width: 100%;
  background: #fff;
  border-radius: 1rem;
  padding: 1.25rem 1.35rem;
  border: 1px solid #e2e8f0;
  box-shadow: 0 20px 40px -20px rgba(0,0,0,0.25);
}
.ere-pedit-discard-title { margin: 0 0 0.5rem; font-size: 1.1rem; font-weight: 800; color: #143D59; }
.ere-pedit-discard-desc { margin: 0 0 1.1rem; font-size: 0.875rem; color: #64748b; line-height: 1.45; }
.ere-pedit-discard-actions { display: flex; flex-wrap: wrap; gap: 0.5rem; justify-content: flex-end; }
.ere-pedit-btn--danger-outline { border-color: #fecaca; color: #b91c1c; }
.ere-pedit-btn--danger-outline:hover { background: #fef2f2; border-color: #f87171; }

.ere-pedit-top {
  display: flex;
  align-items: flex-start;
  gap: 0.85rem;
  padding: 1.15rem 1.15rem 1rem;
  background: linear-gradient(135deg, #f0f7ff 0%, #ffffff 55%, #f8fbff 100%);
  border-bottom: 1px solid #e2eef8;
  flex-shrink: 0;
}
.ere-pedit-top-icon {
  width: 2.75rem;
  height: 2.75rem;
  border-radius: 0.85rem;
  display: flex;
  align-items: center;
  justify-content: center;
  background: linear-gradient(145deg, #1665A0, #143D59);
  color: #fff;
  font-size: 1.35rem;
  flex-shrink: 0;
  box-shadow: 0 8px 20px -8px rgba(22, 101, 160, 0.55);
}
.ere-pedit-top-text { min-width: 0; flex: 1; }
.ere-pedit-title {
  margin: 0;
  font-size: 1.28rem;
  font-weight: 800;
  color: #143D59;
  letter-spacing: -0.02em;
  line-height: 1.2;
}
.ere-pedit-lead {
  margin: 0.3rem 0 0;
  font-size: 0.8125rem;
  color: #64748b;
  line-height: 1.45;
}
.ere-pedit-lead-privacy {
  display: block;
  margin-top: 0.35rem;
  font-size: 0.75rem;
  font-weight: 600;
  color: #94a3b8;
}
.ere-pedit-close {
  border: none;
  background: rgba(255,255,255,0.9);
  color: #475569;
  width: 2.5rem;
  height: 2.5rem;
  border-radius: 0.75rem;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  border: 1px solid #e2e8f0;
  transition: background 0.15s ease, color 0.15s ease, border-color 0.15s ease;
}
.ere-pedit-close:hover {
  background: #fff;
  color: #143D59;
  border-color: #cbd5e1;
}

.ere-pedit-form { display: flex; flex-direction: column; flex: 1; min-height: 0; }
.ere-pedit-scroll {
  flex: 1;
  overflow-y: auto;
  overflow-x: hidden;
  padding: 1rem 1.15rem 0.75rem;
  min-height: 0;
}

.ere-pedit-section {
  scroll-margin-top: 5rem;
}
.ere-pedit-section + .ere-pedit-section {
  margin-top: 0.85rem;
}
@media (min-width: 640px) {
  .ere-pedit-section + .ere-pedit-section {
    margin-top: 0.65rem;
  }
}
.ere-pedit-section--contact .ere-pedit-card--stack + .ere-pedit-card--stack {
  margin-top: 0.75rem;
}
@media (min-width: 640px) {
  .ere-pedit-section--contact .ere-pedit-card--stack + .ere-pedit-card--stack {
    margin-top: 0.65rem;
  }
}

.ere-pedit-card {
  background: linear-gradient(180deg, #fbfdff 0%, #ffffff 100%);
  border: 1px solid #d6e8f7;
  border-radius: 1rem;
  padding: 1rem 1rem 0.95rem;
  box-shadow: 0 1px 0 rgba(255,255,255,0.9) inset;
  transition: border-color 0.2s ease, box-shadow 0.2s ease;
}
.ere-pedit-photo-dropzone.ere-pedit--drag {
  border-color: #1665A0;
  box-shadow: 0 0 0 3px rgba(22, 101, 160, 0.2);
}
.ere-pedit-card--full { margin-top: 0.85rem; }
.ere-pedit-card-title {
  margin: 0 0 0.75rem;
  font-size: 0.6875rem;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: #1665A0;
}
.ere-pedit-drop-hint {
  margin: 0 0 0.65rem;
  font-size: 0.72rem;
  font-weight: 600;
  color: #94a3b8;
  display: flex;
  align-items: center;
  gap: 0.35rem;
}
.ere-pedit-drop-hint i { color: #1665A0; }

.ere-pedit-photo-block {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.75rem;
}
@media (min-width: 640px) {
  .ere-pedit-photo-block { align-items: stretch; }
}

.ere-pedit-avatar-btn {
  position: relative;
  width: 7rem;
  height: 7rem;
  padding: 0;
  border: none;
  border-radius: 1.15rem;
  cursor: pointer;
  background: linear-gradient(145deg, #e8f2fa, #f0f7ff);
  box-shadow: 0 0 0 2px rgba(22, 101, 160, 0.15), 0 8px 24px -12px rgba(20, 61, 89, 0.25);
  overflow: hidden;
  transition: transform 0.18s ease, box-shadow 0.18s ease;
}
.ere-pedit-avatar-btn:hover {
  transform: translateY(-1px);
  box-shadow: 0 0 0 2px rgba(22, 101, 160, 0.28), 0 12px 28px -14px rgba(20, 61, 89, 0.3);
}
.ere-pedit-avatar-btn:focus-visible {
  outline: none;
  box-shadow: 0 0 0 3px #fff, 0 0 0 5px #1665A0;
}

.ere-pedit-avatar-placeholder {
  position: absolute;
  inset: 0;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 0.35rem;
  z-index: 1;
  pointer-events: none;
  padding: 0.5rem;
}
.ere-pedit-avatar-placeholder[hidden] { display: none !important; }

.ere-pedit-avatar-img {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  object-fit: cover;
  z-index: 2;
  opacity: 0;
  transition: opacity 0.2s ease;
  pointer-events: none;
}
.ere-pedit-avatar-img.is-visible { opacity: 1; }

.ere-pedit-avatar-shade {
  position: absolute;
  inset: 0;
  z-index: 3;
  background: linear-gradient(180deg, transparent 35%, rgba(20, 61, 89, 0.82));
  color: #fff;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: flex-end;
  padding-bottom: 0.55rem;
  gap: 0.1rem;
  font-size: 0.65rem;
  font-weight: 700;
  opacity: 0;
  transition: opacity 0.2s ease;
  pointer-events: none;
}
.ere-pedit-avatar-btn:hover .ere-pedit-avatar-shade { opacity: 1; }

.ere-pedit-photo-meta {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.4rem;
  width: 100%;
}
@media (min-width: 640px) {
  .ere-pedit-photo-meta { align-items: stretch; }
}

.ere-pedit-micro {
  margin: 0;
  font-size: 0.6875rem;
  color: #94a3b8;
  text-align: center;
  line-height: 1.35;
}
@media (min-width: 640px) {
  .ere-pedit-micro { text-align: left; }
}
.ere-pedit-compress-hint {
  margin: 0.15rem 0 0;
  font-size: 0.7rem;
  font-weight: 700;
  color: #1665A0;
  text-align: center;
}
@media (min-width: 640px) {
  .ere-pedit-compress-hint { text-align: left; }
}
.ere-pedit-compress-hint[hidden] { display: none !important; }

.ere-pedit-crop-stage {
  margin-top: 0.85rem;
  padding-top: 0.85rem;
  border-top: 1px dashed #c5ddf0;
}
.ere-pedit-crop-stage[hidden] { display: none !important; }
.ere-pedit-crop-head { margin-bottom: 0.5rem; }
.ere-pedit-crop-title {
  display: flex;
  align-items: center;
  gap: 0.35rem;
  font-size: 0.8rem;
  font-weight: 800;
  color: #143D59;
}
.ere-pedit-crop-sub {
  display: block;
  margin-top: 0.15rem;
  font-size: 0.68rem;
  color: #94a3b8;
  font-weight: 600;
}
.ere-pedit-crop-frame {
  max-height: 220px;
  background: #0f172a;
  border-radius: 0.75rem;
  overflow: hidden;
  margin-bottom: 0.65rem;
}
.ere-pedit-crop-img {
  display: block;
  max-width: 100%;
}
.ere-pedit-crop-actions {
  display: flex;
  flex-wrap: wrap;
  justify-content: flex-end;
  gap: 0.45rem;
}

.ere-pedit-link-danger {
  border: none;
  background: none;
  padding: 0.15rem;
  font-size: 0.78rem;
  font-weight: 600;
  color: #94a3b8;
  cursor: pointer;
  text-decoration: underline;
  text-underline-offset: 2px;
}
.ere-pedit-link-danger:hover { color: #b91c1c; }

.ere-pedit-field { margin-bottom: 0.8rem; }
.ere-pedit-field:last-child { margin-bottom: 0; }

.ere-pedit-label-row {
  display: flex;
  justify-content: space-between;
  align-items: baseline;
  gap: 0.5rem;
  margin-bottom: 0.35rem;
}
.ere-pedit-label {
  display: block;
  font-size: 0.78rem;
  font-weight: 700;
  color: #143D59;
}
.ere-pedit-card--full .ere-pedit-label { margin-bottom: 0; }
.ere-pedit-opt {
  font-weight: 500;
  color: #94a3b8;
  text-transform: lowercase;
}
.ere-pedit-count {
  font-size: 0.6875rem;
  font-weight: 600;
  color: #94a3b8;
  font-variant-numeric: tabular-nums;
}

.ere-pedit-email-status {
  margin-top: 0.35rem;
  min-height: 1.1rem;
}
.ere-pedit-email-status[hidden] { display: none !important; }
.ere-pedit-email-status-inner {
  font-size: 0.72rem;
  font-weight: 600;
}
.ere-pedit-email-status-inner.is-ok { color: #059669; }
.ere-pedit-email-status-inner.is-warn { color: #b45309; }
.ere-pedit-email-status-inner.is-bad { color: #b91c1c; }
.ere-pedit-email-status-inner.is-muted { color: #94a3b8; }

.ere-pedit-input,
.ere-pedit-textarea {
  width: 100%;
  border: 1px solid #c5ddf0;
  border-radius: 0.7rem;
  padding: 0.58rem 0.85rem;
  font-size: 0.9rem;
  color: #0f172a;
  background: #fff;
  transition: border-color 0.15s ease, box-shadow 0.15s ease, background 0.15s ease;
  scroll-margin-top: 1.25rem;
}
.ere-pedit-input::placeholder,
.ere-pedit-textarea::placeholder { color: #94a3b8; }
.ere-pedit-input:hover,
.ere-pedit-textarea:hover { border-color: #9dc4e8; }
.ere-pedit-input:focus,
.ere-pedit-textarea:focus {
  outline: none;
  border-color: #1665A0;
  box-shadow: 0 0 0 3px rgba(22, 101, 160, 0.18);
  background: #fbfdff;
}
.ere-pedit-input.is-invalid,
.ere-pedit-textarea.is-invalid {
  border-color: #f87171;
  box-shadow: 0 0 0 3px rgba(248, 113, 113, 0.2);
}
.ere-pedit-input--readonly,
.ere-pedit-input[readonly] {
  background: #f1f5f9;
  color: #475569;
  cursor: default;
  border-color: #e2e8f0;
}
.ere-pedit-input--readonly:focus,
.ere-pedit-input[readonly]:focus {
  box-shadow: none;
  border-color: #cbd5e1;
  background: #f1f5f9;
}
.ere-pedit-textarea {
  resize: vertical;
  min-height: 5rem;
  line-height: 1.5;
}

.ere-pedit-err {
  margin: 0.35rem 0 0;
  font-size: 0.75rem;
  color: #b91c1c;
  font-weight: 600;
  display: flex;
  align-items: flex-start;
  gap: 0.35rem;
}
/* display:flex above overrides the native [hidden] rule in some browsers — keep errors truly hidden until shown */
.ere-pedit-err[hidden] {
  display: none !important;
}
.ere-pedit-err::before {
  content: '';
  flex-shrink: 0;
  width: 0.35rem;
  height: 0.35rem;
  margin-top: 0.28rem;
  border-radius: 50%;
  background: #dc2626;
}

.ere-pedit-hint {
  margin: 0 0 0.75rem;
  font-size: 0.75rem;
  color: #64748b;
  line-height: 1.45;
}
.ere-pedit-future-hint {
  margin: 0 0 0.75rem;
  font-size: 0.7rem;
  font-weight: 600;
  color: #94a3b8;
  line-height: 1.45;
}
.ere-pedit-inline-link {
  color: #1665A0;
  text-decoration: underline;
  text-underline-offset: 2px;
  font-weight: 700;
}
.ere-pedit-inline-link:hover { color: #143D59; }

.ere-pedit-pw-tools {
  display: flex;
  flex-wrap: wrap;
  gap: 0.45rem;
  margin-bottom: 0.75rem;
}
.ere-pedit-btn--sm {
  padding: 0.45rem 0.75rem;
  font-size: 0.78rem;
}

.ere-pedit-pw-details {
  margin-top: 0.85rem;
  border: 1px solid #d6e8f7;
  border-radius: 1rem;
  background: #fafcff;
  overflow: hidden;
}
.ere-pedit-pw-summary {
  list-style: none;
  cursor: pointer;
  padding: 0.85rem 1rem;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  font-weight: 700;
  color: #143D59;
  user-select: none;
  transition: background 0.15s ease;
}
.ere-pedit-pw-summary::-webkit-details-marker { display: none; }
.ere-pedit-pw-summary:hover { background: rgba(22, 101, 160, 0.04); }
.ere-pedit-pw-summary-inner {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 0.15rem;
  min-width: 0;
}
.ere-pedit-pw-summary-inner > i { display: none; }
@media (min-width: 480px) {
  .ere-pedit-pw-summary-inner {
    flex-direction: row;
    align-items: center;
    gap: 0.5rem;
  }
  .ere-pedit-pw-summary-inner > i { display: inline; color: #1665A0; }
}
.ere-pedit-pw-summary-text { font-size: 0.9rem; }
.ere-pedit-pw-summary-hint {
  font-size: 0.7rem;
  font-weight: 600;
  color: #94a3b8;
}
.ere-pedit-pw-chev {
  flex-shrink: 0;
  color: #94a3b8;
  transition: transform 0.2s ease;
}
.ere-pedit-pw-details[open] .ere-pedit-pw-chev { transform: rotate(180deg); }
.ere-pedit-pw-panel {
  padding: 0 1rem 1rem;
  border-top: 1px solid #e8f0f8;
  background: #fff;
}

.ere-pedit-pw-wrap { position: relative; }
.ere-pedit-input--pw { padding-right: 2.85rem; }
.ere-pedit-pw-toggle {
  position: absolute;
  right: 0.4rem;
  top: 50%;
  transform: translateY(-50%);
  border: none;
  background: #f1f5f9;
  color: #64748b;
  width: 2.1rem;
  height: 2.1rem;
  border-radius: 0.45rem;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  transition: background 0.15s ease, color 0.15s ease;
}
.ere-pedit-pw-toggle:hover { background: #e2e8f0; color: #1665A0; }

.ere-pedit-strength {
  display: flex;
  gap: 0.25rem;
  margin-top: 0.45rem;
}
.ere-pedit-strength-bar {
  flex: 1;
  height: 0.22rem;
  border-radius: 999px;
  background: #e2e8f0;
  transition: background 0.2s ease;
}
.ere-pedit-strength.is-1 .ere-pedit-strength-bar:nth-child(-n+1) { background: #f87171; }
.ere-pedit-strength.is-2 .ere-pedit-strength-bar:nth-child(-n+2) { background: #fbbf24; }
.ere-pedit-strength.is-3 .ere-pedit-strength-bar:nth-child(-n+3) { background: #34d399; }
.ere-pedit-strength.is-4 .ere-pedit-strength-bar:nth-child(-n+4) { background: #059669; }

.ere-pedit-foot {
  flex-shrink: 0;
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
  align-items: center;
  gap: 0.5rem;
  padding: 0.85rem 1.15rem 1.1rem;
  border-top: 1px solid #e2eef8;
  background: linear-gradient(180deg, #fbfdff 0%, #ffffff 100%);
}
.ere-pedit-foot-actions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: flex-end;
  gap: 0.45rem;
}

.ere-pedit-draft-banner {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.65rem;
  margin-bottom: 0.85rem;
  padding: 0.65rem 0.85rem;
  border-radius: 0.75rem;
  border: 1px solid #bfdbfe;
  background: linear-gradient(135deg, #eff6ff 0%, #f8fafc 100%);
  font-size: 0.78rem;
  font-weight: 600;
  color: #1e3a5f;
}
.ere-pedit-draft-banner[hidden] { display: none !important; }
.ere-pedit-draft-banner__text {
  display: flex;
  align-items: flex-start;
  gap: 0.45rem;
  min-width: 0;
  line-height: 1.4;
}
.ere-pedit-draft-banner__text i { color: #2563eb; flex-shrink: 0; margin-top: 0.1rem; }
.ere-pedit-draft-banner__actions { display: flex; flex-wrap: wrap; gap: 0.35rem; }

.ere-pedit-input-row {
  display: flex;
  align-items: center;
  gap: 0.35rem;
}
.ere-pedit-input--grow {
  flex: 1;
  min-width: 0;
}
.ere-pedit-valid-badge {
  flex-shrink: 0;
  width: 1.85rem;
  height: 1.85rem;
  border-radius: 0.5rem;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 1rem;
  color: #059669;
  background: #ecfdf5;
  border: 1px solid #a7f3d0;
}
.ere-pedit-valid-badge[hidden] { display: none !important; }

.ere-pedit-trust {
  margin: 0.35rem 0 0;
  font-size: 0.7rem;
  font-weight: 600;
  color: #64748b;
  line-height: 1.4;
}

.ere-pedit-shake {
  animation: erePeditShake 0.45s ease;
}
@keyframes erePeditShake {
  0%, 100% { transform: translateX(0); }
  18%, 54%, 90% { transform: translateX(-5px); }
  36%, 72% { transform: translateX(5px); }
}

.ere-pedit-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 0.4rem;
  padding: 0.6rem 1.15rem;
  border-radius: 0.7rem;
  font-size: 0.875rem;
  font-weight: 700;
  cursor: pointer;
  border: none;
  transition: background 0.15s ease, transform 0.1s ease, box-shadow 0.15s ease;
}
.ere-pedit-btn--primary {
  background: linear-gradient(180deg, #1a6fb0 0%, #1665A0 100%);
  color: #fff;
  box-shadow: 0 4px 14px -4px rgba(22, 101, 160, 0.55);
}
.ere-pedit-btn--primary:hover:not(:disabled) {
  background: linear-gradient(180deg, #145a8f 0%, #124e7a 100%);
  transform: translateY(-1px);
}
.ere-pedit-btn--primary:disabled {
  opacity: 0.55;
  cursor: not-allowed;
  transform: none;
}
.ere-pedit-btn--outline {
  background: #fff;
  color: #143D59;
  border: 1px solid #c5ddf0;
}
.ere-pedit-btn--outline:hover { background: #f0f7ff; border-color: #1665A0; }
.ere-pedit-btn--ghost {
  background: transparent;
  color: #64748b;
}
.ere-pedit-btn--ghost:hover { background: #f1f5f9; color: #143D59; }

.ere-pedit-submit-loading[hidden],
.ere-pedit-submit-label[hidden] { display: none !important; }

.ere-pedit-spin {
  display: inline-block;
  animation: erePeditSpin 0.8s linear infinite;
}
@keyframes erePeditSpin { to { transform: rotate(360deg); } }

.ere-pedit-skel { padding: 1rem 1.15rem 1.25rem; }
.ere-pedit-skel-grid {
  display: grid;
  grid-template-columns: 6rem 1fr;
  gap: 1rem;
  margin-bottom: 1rem;
}
.ere-pedit-skel-ph {
  height: 6rem;
  border-radius: 1rem;
  background: linear-gradient(90deg, #f1f5f9 0%, #e8f2fa 50%, #f1f5f9 100%);
  background-size: 200% 100%;
  animation: erePeditSkel 1.15s ease-in-out infinite;
}
.ere-pedit-skel-lines { display: flex; flex-direction: column; gap: 0.6rem; justify-content: center; }
.ere-pedit-skel-line {
  height: 0.8rem;
  border-radius: 0.35rem;
  background: linear-gradient(90deg, #f1f5f9 0%, #e8f2fa 50%, #f1f5f9 100%);
  background-size: 200% 100%;
  animation: erePeditSkel 1.15s ease-in-out infinite;
}
.ere-pedit-skel-line--short { width: 55%; }
.ere-pedit-skel-line--mid { width: 72%; }
@keyframes erePeditSkel {
  0% { background-position: 200% 0; }
  100% { background-position: -200% 0; }
}

.ere-pedit-toast-host {
  position: fixed;
  bottom: 1.25rem;
  right: 1.25rem;
  z-index: 10060;
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  max-width: min(22rem, calc(100vw - 2rem));
  pointer-events: none;
}
.ere-pedit-toast {
  pointer-events: auto;
  padding: 0.8rem 1rem;
  border-radius: 0.85rem;
  font-size: 0.875rem;
  font-weight: 600;
  box-shadow: 0 14px 36px -12px rgba(15, 23, 42, 0.35);
  border: 1px solid transparent;
  display: flex;
  align-items: flex-start;
  gap: 0.55rem;
  animation: erePeditToastIn 0.28s ease-out;
}
.ere-pedit-toast--ok {
  background: #ecfdf5;
  border-color: #a7f3d0;
  color: #065f46;
}
.ere-pedit-toast--err {
  background: #fef2f2;
  border-color: #fecaca;
  color: #991b1b;
}
@keyframes erePeditToastIn {
  from { opacity: 0; transform: translateY(10px); }
  to { opacity: 1; transform: translateY(0); }
}
@media (prefers-reduced-motion: reduce) {
  .ere-pedit-toast {
    animation: none;
  }
}

@media (max-width: 639px) {
  .ere-pedit-shell { max-height: 95vh; border-radius: 1rem 1rem 0 0; }
  .ere-pedit-overlay { align-items: flex-end; padding: 0; }
  .ere-pedit-toast-host { left: 1rem; right: 1rem; max-width: none; }
  .ere-pedit-foot .ere-pedit-btn {
    min-height: 2.75rem;
    padding-top: 0.65rem;
    padding-bottom: 0.65rem;
  }
  .ere-pedit-crop-actions .ere-pedit-btn {
    min-height: 2.75rem;
  }
  .ere-pedit-crop-frame {
    max-height: none;
    min-height: 220px;
    border-radius: 0;
    margin-left: -1.15rem;
    margin-right: -1.15rem;
    width: calc(100% + 2.3rem);
  }
  .ere-pedit-crop-stage {
    margin-left: -1.15rem;
    margin-right: -1.15rem;
    padding-left: 1.15rem;
    padding-right: 1.15rem;
  }
}
@media (min-width: 640px) {
  .ere-pedit-scroll {
    padding-top: 0.65rem;
    padding-left: 1rem;
    padding-right: 1rem;
  }
  .ere-pedit-field {
    margin-bottom: 0.65rem;
  }
  .ere-pedit-card {
    padding: 0.85rem 0.9rem 0.8rem;
  }
}

@media (prefers-reduced-motion: reduce) {
  .ere-pedit-spin { animation: none; }
  .ere-pedit-avatar-btn,
  .ere-pedit-btn--primary { transition: none; }
  .ere-pedit-shake { animation: none; }
  .ere-pedit-overlay:not([hidden]) .ere-pedit-shell,
  .ere-pedit-overlay:not([hidden]) .ere-pedit-backdrop {
    animation: none;
  }
  .ere-pedit-saved-chip {
    animation: none;
  }
}
</style>

<script>
(function () {
  var CROPPER_CSS = 'https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.2/cropper.min.css';
  var CROPPER_JS = 'https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.2/cropper.min.js';

  var overlay = document.getElementById('ereviewProfileEditOverlay');
  var shell = overlay ? overlay.querySelector('.ere-pedit-shell') : null;
  var form = document.getElementById('ereviewProfileEditForm');
  var skel = document.getElementById('ereviewProfileEditSkeleton');
  var fileInput = document.getElementById('ereviewProfileFileInput');
  var previewBtn = document.getElementById('ereviewProfilePhotoPreview');
  var imgEl = document.getElementById('ereviewProfilePhotoImg');
  var phEl = document.getElementById('ereviewProfilePhotoPlaceholder');
  var avatarInitialEl = document.getElementById('erePeditAvatarInitial');
  var scrollRegion = document.getElementById('erePeditScrollRegion');
  var savedChip = document.getElementById('erePeditSavedChip');
  var removeFlag = document.getElementById('ereviewProfileRemoveAvatar');
  var submitBtn = document.getElementById('ereviewProfileSubmit');
  var toastHost = document.getElementById('ereviewAppToastHost');
  var bioTa = document.getElementById('ereviewProfileBio');
  var bioCount = document.getElementById('ereviewProfileBioCount');
  var pwInput = document.getElementById('ereviewProfilePw');
  var pwStrength = document.getElementById('ereviewPwStrength');
  var pwDetails = document.getElementById('ereEditPwDetails');
  var liveRegion = document.getElementById('erePeditLiveRegion');
  var discardOverlay = document.getElementById('erePeditDiscardOverlay');
  var photoZone = document.getElementById('erePeditPhotoZone');
  var cropStage = document.getElementById('erePeditCropStage');
  var cropImg = document.getElementById('erePeditCropImg');
  var compressHint = document.getElementById('erePeditCompressHint');
  var emailRow = document.getElementById('ereviewEmailAvailRow');
  var emailText = document.getElementById('ereviewEmailAvailText');
  if (!overlay || !form || !shell) return;

  var boot = { userId: 0, saveStayOpen: false, emailLocked: false, uiTheme: 'student', csrf: '', analytics: true };
  try {
    var be = document.getElementById('ereviewPeditBoot');
    if (be && be.textContent) {
      var parsed = JSON.parse(be.textContent);
      if (parsed && typeof parsed === 'object') boot = Object.assign(boot, parsed);
    }
  } catch (eBoot) {}

  var draftBanner = document.getElementById('erePeditDraftBanner');
  var saveStayBtn = document.getElementById('ereviewProfileSaveStay');
  if (saveStayBtn && !boot.saveStayOpen) {
    saveStayBtn.hidden = true;
    saveStayBtn.setAttribute('hidden', 'hidden');
  }
  var validNameBadge = document.getElementById('erePeditValidName');
  var validEmailBadge = document.getElementById('erePeditValidEmail');
  var phoneInp = document.getElementById('ereviewProfilePhone');
  var draftUseServerBtn = document.getElementById('erePeditDraftUseServer');
  var draftDismissBtn = document.getElementById('erePeditDraftDismiss');

  var apiGet = 'api/profile/get_profile.php';
  var apiPost = 'api/profile/update_profile.php';
  var apiEmail = 'api/profile/check_email.php';
  var apiLog = 'api/profile/log_event.php';

  var lastLoadedUser = null;
  var formSnapshot = null;
  var croppedProfileFile = null;
  var cropperInstance = null;
  var cropObjectUrl = null;
  var previewObjectUrl = null;
  var cropperLoaded = false;
  var cropperLoading = false;
  var emailCheckTimer = null;
  var emailAvailability = null;
  var trapHandler = null;
  var preModalFocus = null;
  var draftTimer = null;
  var saveStayPending = false;
  var savedChipTimer = null;
  var sectionSpyObserver = null;

  function showSavedChip() {
    if (!savedChip) return;
    clearTimeout(savedChipTimer);
    savedChip.hidden = false;
    announce('Saved.');
    savedChipTimer = setTimeout(function () {
      savedChip.hidden = true;
    }, 2800);
  }

  function hideSavedChip() {
    clearTimeout(savedChipTimer);
    if (savedChip) savedChip.hidden = true;
  }

  function setChipActive(id) {
    document.querySelectorAll('[data-ere-pedit-chip]').forEach(function (b) {
      var on = b.id === id;
      b.classList.toggle('is-active', on);
      b.setAttribute('aria-current', on ? 'true' : 'false');
    });
  }

  function scrollToSectionId(elId) {
    var el = document.getElementById(elId);
    if (!el || !scrollRegion) return;
    var chips = document.getElementById('erePeditChips');
    var chipH = chips ? chips.offsetHeight + 6 : 8;
    var sr = scrollRegion.getBoundingClientRect();
    var er = el.getBoundingClientRect();
    var nextTop = scrollRegion.scrollTop + (er.top - sr.top) - chipH;
    scrollRegion.scrollTo({ top: Math.max(0, nextTop), behavior: 'smooth' });
  }

  function installSectionScrollSpy() {
    if (!scrollRegion || !window.IntersectionObserver) return;
    if (sectionSpyObserver) {
      sectionSpyObserver.disconnect();
      sectionSpyObserver = null;
    }
    var map = [
      { id: 'erePeditPhotoZone', chip: 'erePeditChipProfile' },
      { id: 'erePeditSectionContact', chip: 'erePeditChipContact' },
      { id: 'erePeditSectionSecurity', chip: 'erePeditChipSecurity' }
    ];
    var ratios = { erePeditPhotoZone: 0, erePeditSectionContact: 0, erePeditSectionSecurity: 0 };
    sectionSpyObserver = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) {
        var tid = en.target.id;
        if (Object.prototype.hasOwnProperty.call(ratios, tid)) {
          ratios[tid] = en.isIntersecting ? en.intersectionRatio : 0;
        }
      });
      var bestChip = 'erePeditChipProfile';
      var bestVal = -1;
      map.forEach(function (m) {
        if (ratios[m.id] > bestVal) {
          bestVal = ratios[m.id];
          bestChip = m.chip;
        }
      });
      if (bestVal > 0.04) setChipActive(bestChip);
    }, { root: scrollRegion, threshold: [0, 0.08, 0.2, 0.35, 0.55, 0.75, 1] });
    map.forEach(function (m) {
      var el = document.getElementById(m.id);
      if (el) sectionSpyObserver.observe(el);
    });
  }

  document.querySelectorAll('[data-ere-pedit-chip]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var tid = btn.getAttribute('data-ere-pedit-target');
      if (tid) {
        setChipActive(btn.id);
        scrollToSectionId(tid);
      }
    });
  });

  function announce(msg) {
    if (!liveRegion || !msg) return;
    liveRegion.textContent = '';
    liveRegion.textContent = msg;
  }

  function peditLog(event, meta) {
    if (!boot.analytics || !boot.csrf || !event) return;
    var fd = new FormData();
    fd.append('csrf_token', boot.csrf);
    fd.append('event', event);
    if (meta) fd.append('meta', meta);
    fetch(apiLog, { method: 'POST', body: fd, credentials: 'same-origin', keepalive: true }).catch(function () {});

  }

  function draftStorageKey() {
    if (!boot.userId) return null;
    return 'ereview_pedit_draft_v1_' + boot.userId;
  }

  function readDraft() {
    var k = draftStorageKey();
    if (!k || !window.localStorage) return null;
    try {
      var raw = localStorage.getItem(k);
      if (!raw) return null;
      var o = JSON.parse(raw);
      return o && typeof o === 'object' ? o : null;
    } catch (e1) {
      return null;
    }
  }

  function writeDraftToStorage() {
    var k = draftStorageKey();
    if (!k || !window.localStorage || !phoneInp || !bioTa) return;
    try {
      localStorage.setItem(k, JSON.stringify({
        phone: phoneInp.value,
        profile_bio: bioTa.value,
        savedAt: Date.now()
      }));
    } catch (e2) {}
  }

  function clearDraftLocal() {
    var k = draftStorageKey();
    if (k && window.localStorage) {
      try { localStorage.removeItem(k); } catch (e3) {}
    }
  }

  function scheduleDraftSave() {
    clearTimeout(draftTimer);
    draftTimer = setTimeout(writeDraftToStorage, 450);
  }

  function hideDraftBanner() {
    if (draftBanner) draftBanner.hidden = true;
  }

  function tryRestoreDraftFromStorage(serverPhone, serverBio) {
    hideDraftBanner();
    var d = readDraft();
    if (!d) return;
    var dp = String(d.phone != null ? d.phone : '');
    var db = String(d.profile_bio != null ? d.profile_bio : '');
    var sp = String(serverPhone != null ? serverPhone : '');
    var sb = String(serverBio != null ? serverBio : '');
    if (dp === sp && db === sb) return;
    if (phoneInp) phoneInp.value = dp;
    if (bioTa) bioTa.value = db;
    updateBioCount();
    if (draftBanner) draftBanner.hidden = false;
    announce('Restored unsaved phone and bio draft from this browser.');
    peditLog('draft_restore');
  }

  function updateFieldValidBadges() {
    var fnEl = document.getElementById('ereviewProfileFullName');
    var emEl = document.getElementById('ereviewProfileEmail');
    if (validNameBadge && fnEl) {
      var fnOk = fnEl.value.trim().length >= 1;
      validNameBadge.hidden = !fnOk;
    }
    if (validEmailBadge && emEl) {
      var em = emEl.value.trim();
      var emOk = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(em);
      validEmailBadge.hidden = !emOk;
    }
  }

  function clearShake() {
    form.querySelectorAll('.ere-pedit-shake').forEach(function (el) { el.classList.remove('ere-pedit-shake'); });
  }

  function showToast(msg, type) {
    if (!toastHost || !msg) return;
    var el = document.createElement('div');
    el.className = 'ere-pedit-toast ere-pedit-toast--' + (type === 'error' ? 'err' : 'ok');
    el.setAttribute('role', 'status');
    el.innerHTML = '<i class="bi ' + (type === 'error' ? 'bi-exclamation-circle' : 'bi-check-circle-fill') + '" aria-hidden="true"></i><span></span>';
    el.querySelector('span').textContent = msg;
    toastHost.appendChild(el);
    setTimeout(function () {
      if (el.parentNode) el.parentNode.removeChild(el);
    }, 5200);
  }

  function formatBytes(n) {
    if (n < 1024) return n + ' B';
    if (n < 1048576) return (n / 1024).toFixed(1) + ' KB';
    return (n / 1048576).toFixed(2) + ' MB';
  }

  function updateCompressHint(bytes) {
    if (!compressHint) return;
    if (!bytes || bytes <= 0) {
      compressHint.hidden = true;
      compressHint.textContent = '';
      return;
    }
    compressHint.hidden = false;
    compressHint.textContent = 'Estimated upload size: ' + formatBytes(bytes) + ' (JPEG, optimized)';
  }

  function clearFieldInvalid() {
    form.querySelectorAll('.is-invalid').forEach(function (el) {
      el.classList.remove('is-invalid');
      el.removeAttribute('aria-invalid');
    });
  }

  function clearErrors() {
    ['ereviewErrFullName','ereviewErrEmail','ereviewErrPhone','ereviewErrBio','ereviewErrPw','ereviewErrPw2'].forEach(function (id) {
      var n = document.getElementById(id);
      if (n) { n.hidden = true; n.textContent = ''; }
    });
    clearFieldInvalid();
  }

  function setLoading(on) {
    var label = submitBtn ? submitBtn.querySelector('.ere-pedit-submit-label') : null;
    var loading = submitBtn ? submitBtn.querySelector('.ere-pedit-submit-loading') : null;
    if (submitBtn) submitBtn.disabled = !!on;
    if (saveStayBtn) saveStayBtn.disabled = !!on;
    if (label) label.hidden = !!on;
    if (loading) loading.hidden = !on;
  }

  function destroyCropper() {
    if (cropperInstance && typeof cropperInstance.destroy === 'function') {
      try { cropperInstance.destroy(); } catch (e) {}
    }
    cropperInstance = null;
    if (cropObjectUrl) {
      try { URL.revokeObjectURL(cropObjectUrl); } catch (e2) {}
    }
    cropObjectUrl = null;
    if (cropImg) {
      cropImg.removeAttribute('src');
      cropImg.alt = '';
    }
    if (cropStage) cropStage.hidden = true;
  }

  function ensureCropper(cb) {
    if (cropperLoaded && window.Cropper) return cb();
    if (cropperLoading) {
      var t = setInterval(function () {
        if (cropperLoaded) {
          clearInterval(t);
          cb();
        }
      }, 40);
      setTimeout(function () { clearInterval(t); }, 15000);
      return;
    }
    cropperLoading = true;
    var link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = CROPPER_CSS;
    link.crossOrigin = 'anonymous';
    document.head.appendChild(link);
    var s = document.createElement('script');
    s.src = CROPPER_JS;
    s.crossOrigin = 'anonymous';
    s.onload = function () {
      cropperLoaded = true;
      cropperLoading = false;
      cb();
    };
    s.onerror = function () {
      cropperLoading = false;
      showToast('Could not load image tools. Using photo without crop.', 'error');
      cb(new Error('cropper'));
    };
    document.head.appendChild(s);
  }

  function setPreviewFromUser(u) {
    if (previewObjectUrl) {
      try { URL.revokeObjectURL(previewObjectUrl); } catch (e) {}
      previewObjectUrl = null;
    }
    var url = u && u.avatar_url ? String(u.avatar_url).trim() : '';
    var name = u && u.full_name ? u.full_name : '?';
    var initial = (name.trim().charAt(0) || '?').toUpperCase();

    imgEl.onload = function () {
      imgEl.classList.add('is-visible');
      phEl.setAttribute('hidden', 'hidden');
    };
    imgEl.onerror = function () {
      imgEl.onerror = null;
      imgEl.onload = null;
      imgEl.removeAttribute('src');
      imgEl.classList.remove('is-visible');
      phEl.removeAttribute('hidden');
      if (avatarInitialEl) avatarInitialEl.textContent = initial;
    };

    if (url) {
      phEl.setAttribute('hidden', 'hidden');
      imgEl.classList.remove('is-visible');
      imgEl.src = url;
    } else {
      imgEl.onload = null;
      imgEl.onerror = null;
      imgEl.removeAttribute('src');
      imgEl.classList.remove('is-visible');
      phEl.removeAttribute('hidden');
      if (avatarInitialEl) avatarInitialEl.textContent = initial;
    }
    updateCompressHint(0);
  }

  function captureSnapshot() {
    return {
      full_name: document.getElementById('ereviewProfileFullName').value,
      email: document.getElementById('ereviewProfileEmail').value,
      phone: document.getElementById('ereviewProfilePhone').value,
      profile_bio: document.getElementById('ereviewProfileBio').value,
      hadAvatar: !!(lastLoadedUser && lastLoadedUser.avatar_url),
      remove_avatar: removeFlag.value
    };
  }

  function isDirty() {
    if (!formSnapshot) return false;
    if (croppedProfileFile) return true;
    if (removeFlag.value === '1' && formSnapshot.hadAvatar) return true;
    if (fileInput && fileInput.files && fileInput.files.length) return true;
    if (cropStage && !cropStage.hidden) return true;
    var p1 = document.getElementById('ereviewProfilePw').value;
    var p2 = document.getElementById('ereviewProfilePw2').value;
    if (p1 || p2) return true;
    if (document.getElementById('ereviewProfileFullName').value !== formSnapshot.full_name) return true;
    if (document.getElementById('ereviewProfileEmail').value !== formSnapshot.email) return true;
    if (document.getElementById('ereviewProfilePhone').value !== formSnapshot.phone) return true;
    if (document.getElementById('ereviewProfileBio').value !== formSnapshot.profile_bio) return true;
    if (removeFlag.value !== formSnapshot.remove_avatar) return true;
    return false;
  }

  function getFocusable(root) {
    if (!root) return [];
    var sel = 'button:not([disabled]), [href], input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), summary, [tabindex]:not([tabindex="-1"])';
    return Array.prototype.slice.call(root.querySelectorAll(sel)).filter(function (el) {
      if (el.closest('[hidden]')) return false;
      return el.getClientRects().length > 0;
    });
  }

  function installFocusTrap() {
    removeFocusTrap();
    trapHandler = function (ev) {
      if (ev.key !== 'Tab' || overlay.hidden) return;
      var root = discardOverlay && !discardOverlay.hidden ? discardOverlay : shell;
      var list = getFocusable(root);
      if (!list.length) return;
      var first = list[0];
      var last = list[list.length - 1];
      if (ev.shiftKey) {
        if (document.activeElement === first) {
          ev.preventDefault();
          last.focus();
        }
      } else {
        if (document.activeElement === last) {
          ev.preventDefault();
          first.focus();
        }
      }
    };
    document.addEventListener('keydown', trapHandler, true);
  }

  function removeFocusTrap() {
    if (trapHandler) {
      document.removeEventListener('keydown', trapHandler, true);
      trapHandler = null;
    }
  }

  function showDiscardPrompt() {
    if (!discardOverlay) return;
    discardOverlay.hidden = false;
    var stay = document.getElementById('erePeditDiscardStay');
    if (stay) stay.focus();
    announce('Unsaved changes. Choose to keep editing or discard.');
  }

  function hideDiscardPrompt() {
    if (discardOverlay) discardOverlay.hidden = true;
  }

  function requestClose() {
    if (discardOverlay && !discardOverlay.hidden) return;
    if (isDirty()) {
      showDiscardPrompt();
      return;
    }
    closeModal(true);
  }

  function closeModal(force) {
    if (!force && isDirty()) {
      showDiscardPrompt();
      return;
    }
    hideDiscardPrompt();
    destroyCropper();
    if (sectionSpyObserver) {
      try { sectionSpyObserver.disconnect(); } catch (e) {}
      sectionSpyObserver = null;
    }
    removeFocusTrap();
    overlay.hidden = true;
    overlay.setAttribute('aria-hidden', 'true');
    if (preModalFocus && typeof preModalFocus.focus === 'function') {
      try { preModalFocus.focus(); } catch (e) {}
    }
    preModalFocus = null;
    hideSavedChip();
    announce('Edit profile closed.');
  }

  function beginCropFromFile(file) {
    if (!file) return;
    var name = (file.name || '').toLowerCase();
    if (/\.(heic|heif)$/i.test(name) || /heic|heif/i.test(file.type || '')) {
      showToast('iPhone HEIC/HEIF is not supported here. Export or convert the photo to JPEG first.', 'error');
      announce('HEIC format not supported. Use JPEG or PNG.');
      return;
    }
    if (!/^image\/(jpeg|png|webp|gif)$/i.test(file.type)) {
      showToast('Please choose a JPG, PNG, WebP, or GIF image.', 'error');
      return;
    }
    if (file.size > 2 * 1024 * 1024) {
      showToast('Image must be 2 MB or smaller.', 'error');
      return;
    }
    fileInput.value = '';
    ensureCropper(function (err) {
      destroyCropper();
      cropObjectUrl = URL.createObjectURL(file);
      cropImg.src = cropObjectUrl;
      cropStage.hidden = false;
      if (err || !window.Cropper) {
        croppedProfileFile = file;
        var rd = new FileReader();
        rd.onload = function () {
          imgEl.onerror = null;
          imgEl.onload = function () {
            imgEl.classList.add('is-visible');
            phEl.setAttribute('hidden', 'hidden');
          };
          imgEl.src = rd.result;
        };
        rd.readAsDataURL(file);
        removeFlag.value = '0';
        updateCompressHint(file.size);
        announce('Photo selected. Crop tool unavailable; original file will be uploaded.');
        return;
      }
      cropperInstance = new window.Cropper(cropImg, {
        aspectRatio: 1,
        viewMode: 1,
        dragMode: 'move',
        autoCropArea: 0.9,
        responsive: true,
        background: false
      });
      announce('Adjust your photo inside the square. Apply crop when ready.');
    });
  }

  document.getElementById('erePeditCropCancel').addEventListener('click', function () {
    destroyCropper();
    fileInput.value = '';
    croppedProfileFile = null;
    if (lastLoadedUser) setPreviewFromUser(lastLoadedUser);
    announce('Crop cancelled.');
  });

  document.getElementById('erePeditCropApply').addEventListener('click', function () {
    if (!cropperInstance || !window.Cropper) {
      destroyCropper();
      return;
    }
    var canvas = cropperInstance.getCroppedCanvas({ width: 512, height: 512, imageSmoothingQuality: 'high' });
    if (!canvas) {
      showToast('Could not read image.', 'error');
      return;
    }
    canvas.toBlob(function (blob) {
      if (!blob) {
        showToast('Could not process image.', 'error');
        return;
      }
      if (blob.size > 2 * 1024 * 1024) {
        showToast('Result still too large. Zoom in on your face and try again.', 'error');
        return;
      }
      croppedProfileFile = new File([blob], 'profile.jpg', { type: 'image/jpeg' });
      if (previewObjectUrl) try { URL.revokeObjectURL(previewObjectUrl); } catch (e) {}
      previewObjectUrl = URL.createObjectURL(blob);
      imgEl.onerror = null;
      imgEl.onload = function () {
        imgEl.classList.add('is-visible');
        phEl.setAttribute('hidden', 'hidden');
      };
      imgEl.src = previewObjectUrl;
      removeFlag.value = '0';
      updateCompressHint(blob.size);
      destroyCropper();
      announce('Crop applied. Estimated size ' + formatBytes(blob.size));
    }, 'image/jpeg', 0.88);
  });

  overlay.querySelectorAll('[data-ereview-profile-request-close]').forEach(function (el) {
    el.addEventListener('click', function () { requestClose(); });
  });

  document.getElementById('erePeditDiscardStay').addEventListener('click', function () {
    hideDiscardPrompt();
    announce('Continuing to edit profile.');
    var fe = document.getElementById('ereviewProfileFullName');
    if (fe) fe.focus();
  });
  document.getElementById('erePeditDiscardConfirm').addEventListener('click', function () {
    hideDiscardPrompt();
    closeModal(true);
  });

  if (draftUseServerBtn) {
    draftUseServerBtn.addEventListener('click', function () {
      if (!lastLoadedUser) {
        hideDraftBanner();
        return;
      }
      if (phoneInp) phoneInp.value = lastLoadedUser.phone || '';
      if (bioTa) bioTa.value = lastLoadedUser.profile_bio || '';
      updateBioCount();
      clearDraftLocal();
      hideDraftBanner();
      formSnapshot = captureSnapshot();
      announce('Reverted phone and bio to saved profile values.');
    });
  }
  if (draftDismissBtn) {
    draftDismissBtn.addEventListener('click', function () {
      hideDraftBanner();
    });
  }

  if (saveStayBtn) {
    saveStayBtn.addEventListener('click', function () {
      saveStayPending = true;
      if (typeof form.requestSubmit === 'function') {
        try {
          form.requestSubmit(saveStayBtn);
        } catch (eRs) {
          form.requestSubmit();
        }
      } else {
        form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
      }
    });
  }

  function updateBioCount() {
    if (!bioTa || !bioCount) return;
    bioCount.textContent = bioTa.value.length + ' / 500';
  }

  function passwordScore(pw) {
    if (!pw) return 0;
    var s = 0;
    if (pw.length >= 8) s++;
    if (/[A-Z]/.test(pw) && /[a-z]/.test(pw)) s++;
    if (/[0-9]/.test(pw)) s++;
    if (/[^A-Za-z0-9]/.test(pw) || pw.length >= 14) s++;
    return Math.min(4, s);
  }

  function updatePwStrength() {
    if (!pwInput || !pwStrength) return;
    var pw = pwInput.value;
    var sc = passwordScore(pw);
    pwStrength.className = 'ere-pedit-strength' + (pw ? ' is-' + sc : '');
  }

  function generatePassword() {
    var upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    var lower = 'abcdefghijkmnopqrstuvwxyz';
    var num = '23456789';
    var sym = '!@#$%&*';
    var all = upper + lower + num + sym;
    var chars = [];
    chars.push(upper.charAt(Math.floor(Math.random() * upper.length)));
    chars.push(lower.charAt(Math.floor(Math.random() * lower.length)));
    chars.push(num.charAt(Math.floor(Math.random() * num.length)));
    chars.push(sym.charAt(Math.floor(Math.random() * sym.length)));
    while (chars.length < 16) {
      chars.push(all.charAt(Math.floor(Math.random() * all.length)));
    }
    for (var i = chars.length - 1; i > 0; i--) {
      var j = Math.floor(Math.random() * (i + 1));
      var t = chars[i];
      chars[i] = chars[j];
      chars[j] = t;
    }
    return chars.join('');
  }

  document.getElementById('erePeditPwGenerate').addEventListener('click', function () {
    if (pwDetails) pwDetails.open = true;
    var pw = generatePassword();
    var p1 = document.getElementById('ereviewProfilePw');
    var p2 = document.getElementById('ereviewProfilePw2');
    if (p1) p1.value = pw;
    if (p2) p2.value = pw;
    updatePwStrength();
    announce('Suggested password filled in both fields. Use Copy to save it somewhere safe.');
  });

  document.getElementById('erePeditPwCopy').addEventListener('click', function () {
    var pw = document.getElementById('ereviewProfilePw').value;
    if (!pw) {
      showToast('Generate or type a password first.', 'error');
      return;
    }
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(pw).then(function () {
        announce('Password copied to clipboard.');
        showToast('Password copied to clipboard.', 'ok');
      }).catch(function () {
        showToast('Could not copy. Select the password and copy manually.', 'error');
      });
    } else {
      showToast('Copy not supported in this browser.', 'error');
    }
  });

  function applyServerFieldError(j) {
    var msg = (j && j.error) ? String(j.error) : 'Could not save profile.';
    var field = j && j.field ? String(j.field) : '';
    clearErrors();
    clearShake();
    showToast(msg, 'error');
    announce(msg);
    var map = {
      full_name: { el: 'ereviewProfileFullName', err: 'ereviewErrFullName' },
      email: { el: 'ereviewProfileEmail', err: 'ereviewErrEmail' },
      phone: { el: 'ereviewProfilePhone', err: 'ereviewErrPhone' },
      profile_bio: { el: 'ereviewProfileBio', err: 'ereviewErrBio' },
      password: { el: 'ereviewProfilePw', err: 'ereviewErrPw' },
      password_confirm: { el: 'ereviewProfilePw2', err: 'ereviewErrPw2' }
    };
    var m = map[field];
    if (m) {
      var inp = document.getElementById(m.el);
      var er = document.getElementById(m.err);
      if (inp) {
        inp.classList.add('is-invalid');
        inp.setAttribute('aria-invalid', 'true');
      }
      if (er) {
        er.textContent = msg;
        er.hidden = false;
      }
      if (inp) {
        try { inp.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); } catch (eSv) {}
        inp.classList.add('ere-pedit-shake');
        setTimeout(function () {
          try { inp.focus(); } catch (eF) {}
        }, 80);
        setTimeout(function () {
          inp.classList.remove('ere-pedit-shake');
        }, 600);
      }
      return;
    }
    if (field === 'profile_image' && photoZone) {
      try { photoZone.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); } catch (eZ) {}
      photoZone.classList.add('ere-pedit-shake');
      setTimeout(function () {
        photoZone.classList.remove('ere-pedit-shake');
      }, 600);
      return;
    }
    var fn = document.getElementById('ereviewProfileFullName');
    if (fn) {
      fn.classList.add('ere-pedit-shake');
      setTimeout(function () { fn.classList.remove('ere-pedit-shake'); }, 600);
    }
  }

  function setEmailStatus(text, kind) {
    if (!emailRow || !emailText) return;
    if (emailRow.getAttribute('data-ere-pedit-skip-email-status')) return;
    if (!text) {
      emailRow.hidden = true;
      emailText.textContent = '';
      emailText.className = 'ere-pedit-email-status-inner';
      return;
    }
    emailRow.hidden = false;
    emailText.textContent = text;
    emailText.className = 'ere-pedit-email-status-inner' + (kind ? ' ' + kind : '');
  }

  function runEmailCheck() {
    if (boot.emailLocked) return;
    var em = document.getElementById('ereviewProfileEmail').value.trim();
    if (!formSnapshot) return;
    if (em === formSnapshot.email) {
      emailAvailability = true;
      setEmailStatus('Current email on your account.', 'is-ok');
      return;
    }
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(em)) {
      emailAvailability = null;
      setEmailStatus('');
      return;
    }
    setEmailStatus('Checking…', 'is-muted');
    emailAvailability = null;
    var url = apiEmail + '?email=' + encodeURIComponent(em);
    fetch(url, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data || !data.ok) {
          setEmailStatus('');
          return;
        }
        if (data.yours) {
          emailAvailability = true;
          setEmailStatus(data.message || 'This is your current email.', 'is-ok');
          return;
        }
        if (data.invalid) {
          emailAvailability = false;
          setEmailStatus(data.message || 'Invalid email.', 'is-bad');
          return;
        }
        emailAvailability = !!data.available;
        if (data.available) {
          setEmailStatus(data.message || 'Email is available.', 'is-ok');
        } else {
          setEmailStatus(data.message || 'Already taken.', 'is-bad');
        }
      })
      .catch(function () {
        setEmailStatus('');
      });
  }

  var emailInput = document.getElementById('ereviewProfileEmail');
  if (emailInput) {
    if (boot.emailLocked) {
      emailInput.addEventListener('blur', updateFieldValidBadges);
    } else {
      emailInput.addEventListener('blur', function () {
        updateFieldValidBadges();
        clearTimeout(emailCheckTimer);
        emailCheckTimer = setTimeout(runEmailCheck, 350);
      });
      emailInput.addEventListener('input', function () {
        updateFieldValidBadges();
        emailAvailability = null;
        if (formSnapshot && emailInput.value.trim() === formSnapshot.email) {
          setEmailStatus('Current email on your account.', 'is-ok');
          emailAvailability = true;
        } else {
          setEmailStatus('');
        }
      });
    }
  }

  function openModal() {
    peditLog('modal_open');
    hideSavedChip();
    setChipActive('erePeditChipProfile');
    if (scrollRegion) scrollRegion.scrollTop = 0;
    preModalFocus = document.activeElement;
    overlay.hidden = false;
    overlay.setAttribute('aria-hidden', 'false');
    skel.hidden = false;
    form.hidden = true;
    hideDiscardPrompt();
    hideDraftBanner();
    clearErrors();
    setLoading(false);
    removeFlag.value = '0';
    fileInput.value = '';
    croppedProfileFile = null;
    lastLoadedUser = null;
    formSnapshot = null;
    emailAvailability = null;
    setEmailStatus('');
    if (pwInput) pwInput.value = '';
    var p2 = document.getElementById('ereviewProfilePw2');
    if (p2) p2.value = '';
    if (pwDetails) pwDetails.open = false;
    updatePwStrength();
    if (pwStrength) pwStrength.className = 'ere-pedit-strength';
    destroyCropper();

    installFocusTrap();

    fetch(apiGet, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        skel.hidden = true;
        form.hidden = false;
        if (!data || !data.ok || !data.user) {
          announce('Could not load profile.');
          showToast('Could not load your profile.', 'error');
          closeModal(true);
          return;
        }
        var u = data.user;
        if (u && u.email_locked) boot.emailLocked = true;
        lastLoadedUser = u;
        document.getElementById('ereviewProfileFullName').value = u.full_name || '';
        document.getElementById('ereviewProfileEmail').value = u.email || '';
        document.getElementById('ereviewProfilePhone').value = u.phone || '';
        document.getElementById('ereviewProfileBio').value = u.profile_bio || '';
        updateBioCount();
        setPreviewFromUser(u);
        if (!boot.emailLocked) {
          setEmailStatus('Current email on your account.', 'is-ok');
        }
        emailAvailability = true;
        tryRestoreDraftFromStorage(u.phone || '', u.profile_bio || '');
        formSnapshot = captureSnapshot();
        updateFieldValidBadges();
        installSectionScrollSpy();
        announce('Profile loaded. Edit your details, then save.');
        setTimeout(function () {
          var fe = document.getElementById('ereviewProfileFullName');
          if (fe) fe.focus();
        }, 50);
      })
      .catch(function () {
        skel.hidden = true;
        announce('Network error loading profile.');
        showToast('Network error loading profile.', 'error');
        closeModal(true);
      });
  }

  document.getElementById('ereviewProfilePickPhoto').addEventListener('click', function () {
    fileInput.click();
  });
  previewBtn.addEventListener('click', function () {
    fileInput.click();
  });

  document.getElementById('ereviewProfileClearPhoto').addEventListener('click', function () {
    fileInput.value = '';
    croppedProfileFile = null;
    destroyCropper();
    removeFlag.value = '1';
    var u = { full_name: document.getElementById('ereviewProfileFullName').value };
    setPreviewFromUser(u);
    updateCompressHint(0);
    announce('Photo will be removed when you save.');
  });

  fileInput.addEventListener('change', function () {
    var f = fileInput.files && fileInput.files[0];
    if (!f) return;
    beginCropFromFile(f);
  });

  ['dragenter', 'dragover'].forEach(function (evName) {
    photoZone.addEventListener(evName, function (e) {
      e.preventDefault();
      e.stopPropagation();
      photoZone.classList.add('ere-pedit--drag');
    });
  });
  photoZone.addEventListener('dragleave', function (e) {
    e.preventDefault();
    if (!photoZone.contains(e.relatedTarget)) photoZone.classList.remove('ere-pedit--drag');
  });
  photoZone.addEventListener('drop', function (e) {
    e.preventDefault();
    e.stopPropagation();
    photoZone.classList.remove('ere-pedit--drag');
    var f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
    if (f) beginCropFromFile(f);
  });

  if (bioTa) {
    bioTa.addEventListener('input', function () {
      updateBioCount();
      scheduleDraftSave();
    });
  }
  if (phoneInp) {
    phoneInp.addEventListener('input', scheduleDraftSave);
  }
  if (pwInput) pwInput.addEventListener('input', updatePwStrength);

  var fnInput = document.getElementById('ereviewProfileFullName');
  if (fnInput) {
    fnInput.addEventListener('input', updateFieldValidBadges);
    fnInput.addEventListener('blur', updateFieldValidBadges);
  }

  document.querySelectorAll('.ere-pedit-pw-toggle').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var id = btn.getAttribute('data-target');
      var inp = id ? document.getElementById(id) : null;
      if (!inp) return;
      var show = inp.type === 'password';
      inp.type = show ? 'text' : 'password';
      btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
      var ic = btn.querySelector('i');
      if (ic) ic.className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
    });
  });

  function validateClient() {
    clearErrors();
    clearShake();
    var ok = true;
    var firstShakeEl = null;
    var firstField = '';

    function markFirst(el, key) {
      if (el && !firstShakeEl) {
        firstShakeEl = el;
        firstField = key;
      }
    }

    var fn = document.getElementById('ereviewProfileFullName').value.trim();
    var fnEl = document.getElementById('ereviewProfileFullName');
    if (fn.length < 1) {
      var ef = document.getElementById('ereviewErrFullName');
      ef.textContent = 'Please enter your name.';
      ef.hidden = false;
      if (fnEl) fnEl.classList.add('is-invalid');
      markFirst(fnEl, 'full_name');
      ok = false;
    }
    if (!boot.emailLocked) {
      var em = document.getElementById('ereviewProfileEmail').value.trim();
      var emEl = document.getElementById('ereviewProfileEmail');
      if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(em)) {
        var e1 = document.getElementById('ereviewErrEmail');
        e1.textContent = 'Enter a valid email address.';
        e1.hidden = false;
        if (emEl) emEl.classList.add('is-invalid');
        markFirst(emEl, 'email');
        ok = false;
      } else if (formSnapshot && em !== formSnapshot.email) {
        if (emailAvailability === false) {
          var e1b = document.getElementById('ereviewErrEmail');
          e1b.textContent = 'That email is already in use. Choose another.';
          e1b.hidden = false;
          if (emEl) emEl.classList.add('is-invalid');
          markFirst(emEl, 'email_taken');
          ok = false;
        } else if (emailAvailability !== true) {
          var e1c = document.getElementById('ereviewErrEmail');
          e1c.textContent = 'Verifying email… try Save again in a moment.';
          e1c.hidden = false;
          if (emEl) emEl.classList.add('is-invalid');
          markFirst(emEl, 'email_pending');
          ok = false;
        }
      }
    }
    var p1 = document.getElementById('ereviewProfilePw').value;
    var p2 = document.getElementById('ereviewProfilePw2').value;
    var pw1El = document.getElementById('ereviewProfilePw');
    var pw2El = document.getElementById('ereviewProfilePw2');
    if (p1 || p2) {
      if (p1.length < 8) {
        var e2 = document.getElementById('ereviewErrPw');
        e2.textContent = 'Use at least 8 characters.';
        e2.hidden = false;
        if (pw1El) pw1El.classList.add('is-invalid');
        markFirst(pw1El, 'password');
        ok = false;
      }
      if (p1 !== p2) {
        var e3 = document.getElementById('ereviewErrPw2');
        e3.textContent = 'Passwords do not match.';
        e3.hidden = false;
        if (pw2El) pw2El.classList.add('is-invalid');
        markFirst(pw2El, 'password_confirm');
        ok = false;
      }
      if (p1.length >= 8 && (!/[A-Z]/.test(p1) || !/[a-z]/.test(p1) || !/[0-9]/.test(p1))) {
        var e4 = document.getElementById('ereviewErrPw');
        e4.textContent = 'Include uppercase, lowercase, and a number.';
        e4.hidden = false;
        if (pw1El) pw1El.classList.add('is-invalid');
        markFirst(pw1El, 'password_policy');
        ok = false;
      }
    }

    if (!ok) {
      form.querySelectorAll('.is-invalid').forEach(function (el) {
        el.setAttribute('aria-invalid', 'true');
      });
      announce('Please fix the errors before saving.');
      peditLog('validation_fail', JSON.stringify({ field: firstField || 'unknown' }));
      if (firstShakeEl) {
        try { firstShakeEl.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); } catch (eScroll) {}
        firstShakeEl.classList.add('ere-pedit-shake');
        setTimeout(function () {
          try { firstShakeEl.focus(); } catch (eF) {}
        }, 80);
        setTimeout(function () {
          firstShakeEl.classList.remove('ere-pedit-shake');
        }, 600);
      }
    }
    return ok;
  }

  ['ereviewProfileFullName','ereviewProfileEmail','ereviewProfilePhone','ereviewProfileBio','ereviewProfilePw','ereviewProfilePw2'].forEach(function (id) {
    var el = document.getElementById(id);
    if (!el) return;
    el.addEventListener('input', function () {
      el.classList.remove('is-invalid');
      el.removeAttribute('aria-invalid');
      var errId = id === 'ereviewProfileFullName' ? 'ereviewErrFullName'
        : (id === 'ereviewProfileEmail' ? 'ereviewErrEmail'
          : (id === 'ereviewProfilePhone' ? 'ereviewErrPhone'
            : (id === 'ereviewProfileBio' ? 'ereviewErrBio'
              : (id === 'ereviewProfilePw' ? 'ereviewErrPw' : 'ereviewErrPw2'))));
      var err = document.getElementById(errId);
      if (err) { err.hidden = true; err.textContent = ''; }
    });
  });

  function ensureEmailResolved() {
    return new Promise(function (resolve) {
      if (boot.emailLocked) {
        resolve();
        return;
      }
      var em = document.getElementById('ereviewProfileEmail').value.trim();
      if (!formSnapshot || em === formSnapshot.email) {
        resolve();
        return;
      }
      fetch(apiEmail + '?email=' + encodeURIComponent(em), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (d && d.ok) {
            emailAvailability = d.yours ? true : !!d.available;
            if (d.invalid) {
              setEmailStatus(d.message || 'Invalid email.', 'is-bad');
            } else if (d.yours) {
              setEmailStatus(d.message || 'Current email.', 'is-ok');
            } else if (d.available) {
              setEmailStatus(d.message || 'Email is available.', 'is-ok');
            } else {
              setEmailStatus(d.message || 'Already taken.', 'is-bad');
            }
          } else {
            emailAvailability = null;
            setEmailStatus('Could not verify email. Try Save again.', 'is-bad');
            announce('Email check failed.');
          }
          resolve();
        })
        .catch(function () {
          emailAvailability = null;
          setEmailStatus('Could not verify email. Try Save again.', 'is-bad');
          announce('Email check failed.');
          resolve();
        });
    });
  }

  form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    var sub = ev.submitter;
    var stayAfterSave = !!(sub && sub.id === 'ereviewProfileSaveStay') || saveStayPending;
    saveStayPending = false;
    ensureEmailResolved().then(function () {
      if (!validateClient()) {
        return;
      }
      setLoading(true);
      var fd = new FormData();
      fd.append('csrf_token', document.querySelector('#ereviewProfileEditForm input[name="csrf_token"]').value);
      fd.append('remove_avatar', removeFlag.value);
      fd.append('full_name', document.getElementById('ereviewProfileFullName').value.trim());
      fd.append('email', document.getElementById('ereviewProfileEmail').value.trim());
      fd.append('phone', document.getElementById('ereviewProfilePhone').value);
      fd.append('profile_bio', document.getElementById('ereviewProfileBio').value);
      fd.append('password', document.getElementById('ereviewProfilePw').value);
      fd.append('password_confirm', document.getElementById('ereviewProfilePw2').value);
      if (croppedProfileFile) {
        fd.append('profile_image', croppedProfileFile, croppedProfileFile.name || 'profile.jpg');
      } else if (fileInput.files && fileInput.files[0]) {
        fd.append('profile_image', fileInput.files[0]);
      }

      fetch(apiPost, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
        .then(function (pack) {
          setLoading(false);
          if (!pack.j || !pack.j.ok) {
            applyServerFieldError(pack.j);
            peditLog('save_fail', JSON.stringify({
              error: (pack.j && pack.j.error) ? String(pack.j.error).slice(0, 120) : 'unknown',
              field: (pack.j && pack.j.field) ? String(pack.j.field) : ''
            }));
            return;
          }

          clearDraftLocal();
          hideDraftBanner();
          announce('Profile saved successfully.');
          showToast('Profile updated successfully.', 'ok');
          if (!boot.emailLocked) {
            if (pack.j.verification_email_sent) {
              setTimeout(function () { showToast('Verification email sent. Check your inbox.', 'ok'); }, 400);
            } else if (pack.j.email_changed) {
              setTimeout(function () { showToast('Your sign-in email is updated.', 'ok'); }, 400);
            }
          }
          if (pack.j.password_changed) {
            setTimeout(function () {
              showToast('Other devices may stay signed in until those sessions expire.', 'ok');
            }, 450);
          }

          var u = pack.j.user;
          if (u && u.full_name) {
            var fn = u.full_name;
            var short = fn.length > 22 ? fn.slice(0, 20) + '…' : fn;
            document.querySelectorAll('.student-topbar-name, .admin-topbar-name').forEach(function (el) {
              el.textContent = short;
              el.setAttribute('title', fn);
            });
          }

          function patchStudentMenuAvatars(user) {
            var studentBar = document.querySelector('.student-topbar');
            if (!studentBar || !user) return;
            var url = user.avatar_url || '';
            if (!url) return;
            document.querySelectorAll('.student-topbar .student-topbar-avatar, .student-topbar .ereview-profile-menu__hero-avatar').forEach(function (box) {
              var im = box.querySelector('img');
              if (!im) {
                im = document.createElement('img');
                im.setAttribute('alt', '');
                im.className = 'w-full h-full object-cover';
                im.setAttribute('loading', 'lazy');
                box.textContent = '';
                box.appendChild(im);
              }
              im.src = url;
              im.removeAttribute('hidden');
            });
          }

          function finishClosedSaveReload() {
            var studentBar = document.querySelector('.student-topbar');
            if (studentBar && u) {
              var url = u.avatar_url || '';
              if (url) {
                patchStudentMenuAvatars(u);
              } else {
                window.location.reload();
              }
            } else {
              window.location.reload();
            }
          }

          if (stayAfterSave) {
            peditLog('save_success_stay');
            lastLoadedUser = u;
            document.getElementById('ereviewProfileFullName').value = u ? (u.full_name || '') : '';
            document.getElementById('ereviewProfileEmail').value = u ? (u.email || '') : '';
            if (phoneInp) phoneInp.value = u ? (u.phone || '') : '';
            if (bioTa) bioTa.value = u ? (u.profile_bio || '') : '';
            updateBioCount();
            if (pwInput) pwInput.value = '';
            var p2c = document.getElementById('ereviewProfilePw2');
            if (p2c) p2c.value = '';
            if (pwDetails) pwDetails.open = false;
            updatePwStrength();
            if (pwStrength) pwStrength.className = 'ere-pedit-strength';
            croppedProfileFile = null;
            fileInput.value = '';
            removeFlag.value = '0';
            if (u) setPreviewFromUser(u);
            formSnapshot = captureSnapshot();
            emailAvailability = true;
            if (!boot.emailLocked) {
              setEmailStatus('Current email on your account.', 'is-ok');
            }
            updateFieldValidBadges();
            announce('Profile saved. You can keep editing.');
            showSavedChip();
            window.dispatchEvent(new CustomEvent('ereview-profile-updated', {
              detail: Object.assign({}, pack.j, { ereviewStayOpen: true })
            }));
            patchStudentMenuAvatars(u);
            return;
          }

          peditLog('save_success');
          closeModal(true);
          window.dispatchEvent(new CustomEvent('ereview-profile-updated', { detail: pack.j }));
          finishClosedSaveReload();
        })
        .catch(function () {
          setLoading(false);
          announce('Network error while saving.');
          showToast('Network error while saving.', 'error');
        });
    });
  });

  document.addEventListener('keydown', function (ev) {
    if (ev.key !== 'Escape' || overlay.hidden) return;
    if (discardOverlay && !discardOverlay.hidden) {
      hideDiscardPrompt();
      announce('Continuing to edit profile.');
      return;
    }
    requestClose();
  });

  window.ereviewOpenProfileEditModal = openModal;
  window.ereviewProfileToast = showToast;
})();
</script>
