<?php

/** @var string|null $error */
/** @var string $csrf */
/** @var bool $isNew */
/** @var string $examType */
/** @var int $sourceId */
/** @var array|null $record */
/** @var array $extras */
/** @var array $examineeSearchResults */

$examinationEditRenderMode = 'modal';
require __DIR__ . '/examination_edit_config_prepare.php';

header('Content-Type: text/html; charset=UTF-8');

?>
<div class="admin-modal admin-modal--approve admin-modal--form admin-modal--examination-edit" role="dialog" aria-modal="true" aria-labelledby="examinationEditModalTitle">
  <div class="admin-modal__hero examination-edit-modal__hero">
    <span class="admin-modal__hero-icon admin-modal__hero-icon--approve"><i class="bi bi-journal-text"></i></span>
    <div>
      <h3 id="examinationEditModalTitle" class="admin-modal__title"><?php echo h($modalTitle); ?></h3>
      <p class="admin-modal__desc"><?php echo h($modalSubtitle); ?></p>
    </div>
  </div>
  <?php require __DIR__ . '/examination_edit_config_form.php'; ?>
</div>
