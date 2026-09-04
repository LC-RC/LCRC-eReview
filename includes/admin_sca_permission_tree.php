<?php
/**
 * SCA permission tree markup.
 * Requires Alpine parent: isChecked(), toggle(), hasFullLms, toggleFullLms(),
 * setSubjectMode(), subjectMode(), selectedTopicCount?(), catalog
 *
 * Optional: $scaTreeCompact = true for denser embeds (default false / workspace).
 */
$scaTreeScope = $scaTreeScope ?? 'tree';
$scope = preg_replace('/[^a-z0-9_-]/i', '', (string) $scaTreeScope) ?: 'tree';
$scaTreeCompact = !empty($scaTreeCompact);
?>
<label class="sca-full-lms-card" :class="hasFullLms ? 'is-on' : ''">
  <input type="checkbox" class="sca-full-lms-card__check" :checked="hasFullLms" @change="toggleFullLms($event.target.checked)">
  <span class="sca-full-lms-card__body">
    <span class="sca-full-lms-card__title">Full LMS access</span>
    <span class="sca-full-lms-card__sub">Unlock all subjects, topics, preboards, pre-week, and test bank.</span>
  </span>
</label>

<div class="sca-tree" x-show="!hasFullLms" x-cloak>
  <p class="sca-tree-empty"
     x-show="!loadingCatalog && catalog.subjects.length === 0 && catalog.preboard_subjects.length === 0 && catalog.preweek_units.length === 0 && catalog.test_bank.length === 0">
    No LMS content found to assign. Add active subjects, preboards, pre-week units, or test bank items first.
  </p>

  <p class="sca-tree-hint">
    Choose <strong>Full Subject Access</strong> or <strong>Selected Topics Only</strong> per subject.
    One subject does not unlock others.
  </p>

  <div class="sca-subject-grid">
    <template x-for="sub in catalog.subjects" :key="'<?php echo $scope; ?>-sub-'+sub.id">
      <details class="sca-subject-card" :open="subjectMode(sub.id) !== 'none'" :class="'mode-' + subjectMode(sub.id)">
        <summary class="sca-subject-card__summary">
          <span class="sca-chevron" aria-hidden="true"></span>
          <span class="sca-subject-card__title" x-text="sub.label"></span>
          <span class="sca-subject-card__meta" x-text="(sub.lessons?.length || 0) + ' topic' + ((sub.lessons?.length || 0) === 1 ? '' : 's')"></span>
          <span class="sca-subject-card__badge sca-subject-card__badge--full" x-show="subjectMode(sub.id) === 'full'">Full</span>
          <span class="sca-subject-card__badge sca-subject-card__badge--topics" x-show="subjectMode(sub.id) === 'selected'">
            Topics
            <span x-text="typeof selectedTopicCount === 'function' ? (' · ' + selectedTopicCount(sub) + '/' + (sub.lessons?.length || 0)) : ''"></span>
          </span>
        </summary>

        <div class="sca-subject-card__body">
          <p class="sca-mode-label">Access mode</p>
          <div class="sca-mode-grid">
            <label class="sca-mode-card" :class="subjectMode(sub.id) === 'full' ? 'is-on' : ''">
              <input type="radio"
                     :name="'<?php echo $scope; ?>-mode-'+sub.id"
                     :checked="subjectMode(sub.id) === 'full'"
                     @change="setSubjectMode(sub, 'full')">
              <span>
                <span class="sca-mode-card__title">Full Subject Access</span>
                <span class="sca-mode-card__sub">All topics, quizzes, videos, and handouts under this subject</span>
              </span>
            </label>
            <label class="sca-mode-card" :class="subjectMode(sub.id) === 'selected' ? 'is-on' : ''">
              <input type="radio"
                     :name="'<?php echo $scope; ?>-mode-'+sub.id"
                     :checked="subjectMode(sub.id) === 'selected'"
                     @change="setSubjectMode(sub, 'selected')">
              <span>
                <span class="sca-mode-card__title">Selected Topics Only</span>
                <span class="sca-mode-card__sub">Subject page opens; only checked topics unlock</span>
              </span>
            </label>
          </div>
          <button type="button"
                  class="sca-subject-clear"
                  x-show="subjectMode(sub.id) !== 'none'"
                  @click.prevent="setSubjectMode(sub, 'none')">Clear this subject</button>

          <div class="sca-full-unlocked" x-show="subjectMode(sub.id) === 'full'">
            <i class="bi bi-unlock-fill" aria-hidden="true"></i>
            All topics unlocked for this subject.
          </div>

          <div class="sca-topic-panel" x-show="subjectMode(sub.id) === 'selected'">
            <div class="sca-topic-panel__head">
              <span>Topics</span>
              <span class="sca-topic-panel__count"
                    x-text="(typeof selectedTopicCount === 'function' ? selectedTopicCount(sub) : 0) + ' of ' + (sub.lessons?.length || 0) + ' selected'"></span>
            </div>
            <p class="sca-topic-panel__empty" x-show="!(sub.lessons && sub.lessons.length)">
              No topics yet for this subject.
            </p>
            <div class="sca-topic-rows">
              <template x-for="les in sub.lessons" :key="'<?php echo $scope; ?>-les-'+les.id">
                <label class="sca-topic-row" :class="isChecked('lesson', les.id) ? 'is-on' : ''">
                  <input type="checkbox" :checked="isChecked('lesson', les.id)" @change="toggle('lesson', les.id, $event.target.checked)">
                  <span class="sca-topic-row__text">
                    <span class="sca-topic-row__title" x-text="les.label"></span>
                    <span class="sca-topic-row__meta"
                          x-show="(les.videos?.length || 0) + (les.handouts?.length || 0) > 0"
                          x-text="((les.videos?.length || 0) ? (les.videos.length + ' video' + (les.videos.length === 1 ? '' : 's')) : '') + ((les.videos?.length || 0) && (les.handouts?.length || 0) ? ' · ' : '') + ((les.handouts?.length || 0) ? (les.handouts.length + ' handout' + (les.handouts.length === 1 ? '' : 's')) : '')"></span>
                  </span>
                </label>
              </template>
            </div>

            <template x-if="sub.quizzes && sub.quizzes.length">
              <div class="sca-quiz-panel">
                <p class="sca-topic-panel__head"><span>Quizzes (optional)</span></p>
                <p class="sca-topic-panel__empty">Under Selected Topics, quizzes stay locked unless checked.</p>
                <div class="sca-topic-rows">
                  <template x-for="qz in sub.quizzes" :key="'<?php echo $scope; ?>-qz-'+qz.id">
                    <label class="sca-topic-row" :class="isChecked('quiz', qz.id) ? 'is-on' : ''">
                      <input type="checkbox" :checked="isChecked('quiz', qz.id)" @change="toggle('quiz', qz.id, $event.target.checked)">
                      <span class="sca-topic-row__text">
                        <span class="sca-topic-row__title">Quiz: <span x-text="qz.label"></span></span>
                      </span>
                    </label>
                  </template>
                </div>
              </div>
            </template>
          </div>
        </div>
      </details>
    </template>
  </div>

  <details class="sca-extra-block" x-show="catalog.preboard_subjects.length">
    <summary class="sca-extra-block__summary"><span class="sca-chevron" aria-hidden="true"></span> Preboards</summary>
    <template x-for="pbs in catalog.preboard_subjects" :key="'<?php echo $scope; ?>-pbs-'+pbs.id">
      <details class="sca-extra-inner">
        <summary x-text="pbs.label"></summary>
        <label class="sca-topic-row"><input type="checkbox" :checked="isChecked('preboard_subject', pbs.id)" @change="toggle('preboard_subject', pbs.id, $event.target.checked)"><span class="sca-topic-row__text"><span class="sca-topic-row__title">Entire preboard subject</span></span></label>
        <template x-for="st in pbs.sets" :key="'<?php echo $scope; ?>-set-'+st.id">
          <label class="sca-topic-row"><input type="checkbox" :checked="isChecked('preboard_set', st.id)" @change="toggle('preboard_set', st.id, $event.target.checked)"><span class="sca-topic-row__text"><span class="sca-topic-row__title">Set: <span x-text="st.label"></span></span></span></label>
        </template>
      </details>
    </template>
  </details>

  <details class="sca-extra-block" x-show="catalog.preweek_units.length">
    <summary class="sca-extra-block__summary"><span class="sca-chevron" aria-hidden="true"></span> Pre-week</summary>
    <template x-for="pu in catalog.preweek_units" :key="'<?php echo $scope; ?>-pu-'+pu.id">
      <details class="sca-extra-inner">
        <summary x-text="pu.label"></summary>
        <label class="sca-topic-row"><input type="checkbox" :checked="isChecked('preweek_unit', pu.id)" @change="toggle('preweek_unit', pu.id, $event.target.checked)"><span class="sca-topic-row__text"><span class="sca-topic-row__title">Entire unit</span></span></label>
        <template x-for="tp in pu.topics" :key="'<?php echo $scope; ?>-tp-'+tp.id">
          <label class="sca-topic-row"><input type="checkbox" :checked="isChecked('preweek_topic', tp.id)" @change="toggle('preweek_topic', tp.id, $event.target.checked)"><span class="sca-topic-row__text"><span class="sca-topic-row__title" x-text="tp.label"></span></span></label>
        </template>
      </details>
    </template>
  </details>

  <details class="sca-extra-block" x-show="catalog.test_bank.length">
    <summary class="sca-extra-block__summary"><span class="sca-chevron" aria-hidden="true"></span> Test Bank</summary>
    <template x-for="tb in catalog.test_bank" :key="'<?php echo $scope; ?>-tb-'+tb.id">
      <label class="sca-topic-row"><input type="checkbox" :checked="isChecked('test_bank', tb.id)" @change="toggle('test_bank', tb.id, $event.target.checked)"><span class="sca-topic-row__text"><span class="sca-topic-row__title" x-text="tb.label"></span></span></label>
    </template>
  </details>
</div>
