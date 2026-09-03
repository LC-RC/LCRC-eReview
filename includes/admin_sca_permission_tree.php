<?php
/** Permission tree markup - requires Alpine parent: isChecked(), toggle(), hasFullLms, toggleFullLms(), setSubjectMode(), subjectMode(), catalog */
$scaTreeScope = $scaTreeScope ?? 'tree';
$scope = preg_replace('/[^a-z0-9_-]/i', '', (string) $scaTreeScope) ?: 'tree';
?>
<label class="flex items-center gap-2.5 font-semibold text-gray-100 mb-3 cursor-pointer p-3 rounded-lg border-2 border-emerald-500/30 bg-emerald-500/10 hover:bg-emerald-500/15 transition">
  <input type="checkbox" class="rounded border-gray-500 w-4 h-4" :checked="hasFullLms" @change="toggleFullLms($event.target.checked)">
  <span>
    <span class="block text-gray-100">Full LMS access</span>
    <span class="block text-xs font-normal text-gray-500">Unlocks all subjects, topics, preboards, pre-week, and test bank</span>
  </span>
</label>

<div class="sca-tree" x-show="!hasFullLms">
  <p class="text-xs text-amber-600 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2 mb-3 m-0"
     x-show="!loadingCatalog && catalog.subjects.length === 0 && catalog.preboard_subjects.length === 0 && catalog.preweek_units.length === 0 && catalog.test_bank.length === 0">
  No LMS content found to assign. Add active subjects, preboards, pre-week units, or test bank items first.
  </p>

  <p class="sca-tree-hint text-xs text-gray-500 m-0 mb-2">
    Per subject: choose <strong>Full Subject Access</strong> (all topics) or <strong>Selected Topics</strong> (only checked topics).
    Granting a subject does <em>not</em> unlock other subjects.
  </p>

  <template x-for="sub in catalog.subjects" :key="'<?php echo $scope; ?>-sub-'+sub.id">
    <details class="sca-subject-block" :open="subjectMode(sub.id) !== 'none'">
      <summary class="sca-subject-summary">
        <span class="sca-chevron" aria-hidden="true"></span>
        <span class="sca-subject-summary__label" x-text="sub.label"></span>
        <span class="sca-subject-summary__meta" x-text="(sub.lessons?.length || 0) + ' topic' + ((sub.lessons?.length || 0) === 1 ? '' : 's')"></span>
        <span class="sca-subject-summary__badge text-[10px] uppercase tracking-wide px-1.5 py-0.5 rounded ml-auto"
              x-show="subjectMode(sub.id) === 'full'"
              style="background:#dcfce7;color:#166534;">Full</span>
        <span class="sca-subject-summary__badge text-[10px] uppercase tracking-wide px-1.5 py-0.5 rounded ml-auto"
              x-show="subjectMode(sub.id) === 'selected'"
              style="background:#ffedd5;color:#9a3412;">Topics</span>
      </summary>

      <div class="sca-subject-modes px-3 pb-2 space-y-2">
        <label class="sca-grant-all" style="cursor:pointer;">
          <input type="radio"
                 :name="'<?php echo $scope; ?>-mode-'+sub.id"
                 :checked="subjectMode(sub.id) === 'full'"
                 @change="setSubjectMode(sub, 'full')">
          <span>
            <span class="sca-grant-all__title">Full Subject Access</span>
            <span class="sca-grant-all__sub">All topics, quizzes, videos, and handouts under this subject</span>
          </span>
        </label>
        <label class="sca-grant-all" style="cursor:pointer;">
          <input type="radio"
                 :name="'<?php echo $scope; ?>-mode-'+sub.id"
                 :checked="subjectMode(sub.id) === 'selected'"
                 @change="setSubjectMode(sub, 'selected')">
          <span>
            <span class="sca-grant-all__title">Selected Topics Only</span>
            <span class="sca-grant-all__sub">Student can open the subject page, but only checked topics are unlocked</span>
          </span>
        </label>
        <button type="button"
                class="text-xs text-gray-500 underline px-1"
                x-show="subjectMode(sub.id) !== 'none'"
                @click.prevent="setSubjectMode(sub, 'none')">Clear this subject</button>
      </div>

      <div class="sca-topic-list" x-show="subjectMode(sub.id) === 'selected'">
        <p class="sca-topic-list__head">Topics</p>
        <p class="sca-topic-list__empty text-xs text-gray-500 m-0 mb-2" x-show="!(sub.lessons && sub.lessons.length)">
          No topics yet for this subject. Add lessons/topics in Subjects admin first.
        </p>
        <template x-for="les in sub.lessons" :key="'<?php echo $scope; ?>-les-'+les.id">
          <div class="sca-topic-item">
            <label class="sca-topic-check">
              <input type="checkbox" :checked="isChecked('lesson', les.id)" @change="toggle('lesson', les.id, $event.target.checked)">
              <span>Topic: <span x-text="les.label"></span></span>
            </label>
            <details class="sca-topic-assets" x-show="(les.videos?.length || 0) + (les.handouts?.length || 0) > 0 && !isChecked('lesson', les.id)">
              <summary>Videos &amp; handouts</summary>
              <template x-for="v in les.videos" :key="'<?php echo $scope; ?>-vid-'+v.id">
                <label><input type="checkbox" :checked="isChecked('video', v.id)" @change="toggle('video', v.id, $event.target.checked)"> Video: <span x-text="v.label"></span></label>
              </template>
              <template x-for="h in les.handouts" :key="'<?php echo $scope; ?>-ho-'+h.id">
                <label><input type="checkbox" :checked="isChecked('handout', h.id)" @change="toggle('handout', h.id, $event.target.checked)"> Handout: <span x-text="h.label"></span></label>
              </template>
            </details>
          </div>
        </template>

        <template x-if="sub.quizzes && sub.quizzes.length">
          <div class="sca-quiz-list">
            <p class="sca-topic-list__head">Quizzes (optional)</p>
            <p class="text-xs text-gray-500 m-0 mb-2">Under Selected Topics, quizzes stay locked unless checked (Full Subject unlocks all).</p>
            <template x-for="qz in sub.quizzes" :key="'<?php echo $scope; ?>-qz-'+qz.id">
              <label><input type="checkbox" :checked="isChecked('quiz', qz.id)" @change="toggle('quiz', qz.id, $event.target.checked)"> Quiz: <span x-text="qz.label"></span></label>
            </template>
          </div>
        </template>
      </div>
    </details>
  </template>

  <details x-show="catalog.preboard_subjects.length">
    <summary class="sca-subject-summary"><span class="sca-chevron" aria-hidden="true"></span> Preboards</summary>
    <template x-for="pbs in catalog.preboard_subjects" :key="'<?php echo $scope; ?>-pbs-'+pbs.id">
      <details>
        <summary x-text="pbs.label"></summary>
        <label><input type="checkbox" :checked="isChecked('preboard_subject', pbs.id)" @change="toggle('preboard_subject', pbs.id, $event.target.checked)"> Entire preboard subject</label>
        <p class="sca-pb-hint text-xs text-gray-500 m-0 mb-2 pl-1">Permission to view sets - each set still follows admin open/schedule or student request.</p>
        <template x-for="st in pbs.sets" :key="'<?php echo $scope; ?>-set-'+st.id">
          <label class="sca-pb-set-label">
            <input type="checkbox" :checked="isChecked('preboard_set', st.id)" @change="toggle('preboard_set', st.id, $event.target.checked)">
            <span class="sca-pb-set-label__text">
              <span>Set: <span x-text="st.label"></span></span>
              <span class="sca-pb-status" :class="'sca-pb-status--' + (st.access_key || 'locked')" x-text="st.access_label || 'Locked'"></span>
            </span>
          </label>
        </template>
      </details>
    </template>
  </details>

  <details x-show="catalog.preweek_units.length">
    <summary class="sca-subject-summary"><span class="sca-chevron" aria-hidden="true"></span> Pre-week</summary>
    <template x-for="pu in catalog.preweek_units" :key="'<?php echo $scope; ?>-pu-'+pu.id">
      <details>
        <summary x-text="pu.label"></summary>
        <label><input type="checkbox" :checked="isChecked('preweek_unit', pu.id)" @change="toggle('preweek_unit', pu.id, $event.target.checked)"> Entire unit</label>
        <template x-for="tp in pu.topics" :key="'<?php echo $scope; ?>-tp-'+tp.id">
          <label><input type="checkbox" :checked="isChecked('preweek_topic', tp.id)" @change="toggle('preweek_topic', tp.id, $event.target.checked)"> Topic: <span x-text="tp.label"></span></label>
        </template>
      </details>
    </template>
  </details>

  <details x-show="catalog.test_bank.length">
    <summary class="sca-subject-summary"><span class="sca-chevron" aria-hidden="true"></span> Test Bank</summary>
    <template x-for="tb in catalog.test_bank" :key="'<?php echo $scope; ?>-tb-'+tb.id">
      <label><input type="checkbox" :checked="isChecked('test_bank', tb.id)" @change="toggle('test_bank', tb.id, $event.target.checked)"> <span x-text="tb.label"></span></label>
    </template>
  </details>
</div>
