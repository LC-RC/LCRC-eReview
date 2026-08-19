<?php
/**
 * Flash message alerts for College Portal pages.
 *
 * @var string|null $cpFlashMessage
 * @var string|null $cpFlashError
 */
if (!empty($cpFlashMessage)): ?>
  <div class="cp-alert cp-alert--success cp-anim delay-1" role="status"><?php echo h((string)$cpFlashMessage); ?></div>
<?php endif; ?>
<?php if (!empty($cpFlashError)): ?>
  <div class="cp-alert cp-alert--error cp-anim delay-1" role="alert"><?php echo h((string)$cpFlashError); ?></div>
<?php endif; ?>
