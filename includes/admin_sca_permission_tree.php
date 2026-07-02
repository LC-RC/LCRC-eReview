<?php
/** Permission tree markup — requires Alpine parent: isChecked(), toggle(), hasFullLms, toggleFullLms(), catalog */
?>
<label class="flex items-center gap-2.5 font-semibold text-gray-100 mb-3 cursor-pointer p-3 rounded-lg border-2 border-emerald-500/30 bg-emerald-500/10 hover:bg-emerald-500/15 transition">
  <input type="checkbox" class="rounded border-gray-500 w-4 h-4" :checked="hasFullLms" @change="toggleFullLms($event.target.checked)">
  <span>
    <span class="block text-gray-100">Full LMS access</span>
    <span class="block text-xs font-normal text-gray-500">Unlocks all subjects, preboards, pre-week, and test bank</span>
  </span>
</label>

<div class="sca-tree" x-show="!hasFullLms">
  <template x-for="sub in catalog.subjects" :key="'sub-'+sub.id">
    <details>
      <summary x-text="sub.label"></summary>
      <label><input type="checkbox" :checked="isChecked('subject', sub.id)" @change="toggle('subject', sub.id, $event.target.checked)"> Entire subject</label>
      <template x-for="les in sub.lessons" :key="'les-'+les.id">
        <details>
          <summary x-text="'Lesson: ' + les.label"></summary>
          <label><input type="checkbox" :checked="isChecked('lesson', les.id)" @change="toggle('lesson', les.id, $event.target.checked)"> Entire lesson</label>
          <template x-for="v in les.videos" :key="'vid-'+v.id">
            <label><input type="checkbox" :checked="isChecked('video', v.id)" @change="toggle('video', v.id, $event.target.checked)"> Video: <span x-text="v.label"></span></label>
          </template>
          <template x-for="h in les.handouts" :key="'ho-'+h.id">
            <label><input type="checkbox" :checked="isChecked('handout', h.id)" @change="toggle('handout', h.id, $event.target.checked)"> Handout: <span x-text="h.label"></span></label>
          </template>
        </details>
      </template>
      <template x-for="qz in sub.quizzes" :key="'qz-'+qz.id">
        <label><input type="checkbox" :checked="isChecked('quiz', qz.id)" @change="toggle('quiz', qz.id, $event.target.checked)"> Quiz: <span x-text="qz.label"></span></label>
      </template>
    </details>
  </template>

  <details x-show="catalog.preboard_subjects.length">
    <summary>Preboards</summary>
    <template x-for="pbs in catalog.preboard_subjects" :key="'pbs-'+pbs.id">
      <details>
        <summary x-text="pbs.label"></summary>
        <label><input type="checkbox" :checked="isChecked('preboard_subject', pbs.id)" @change="toggle('preboard_subject', pbs.id, $event.target.checked)"> Entire preboard subject</label>
        <p class="sca-pb-hint text-xs text-gray-500 m-0 mb-2 pl-1">Permission to view sets — each set still follows admin open/schedule or student request.</p>
        <template x-for="st in pbs.sets" :key="'set-'+st.id">
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
    <summary>Pre-week</summary>
    <template x-for="pu in catalog.preweek_units" :key="'pu-'+pu.id">
      <details>
        <summary x-text="pu.label"></summary>
        <label><input type="checkbox" :checked="isChecked('preweek_unit', pu.id)" @change="toggle('preweek_unit', pu.id, $event.target.checked)"> Entire unit</label>
        <template x-for="tp in pu.topics" :key="'tp-'+tp.id">
          <label><input type="checkbox" :checked="isChecked('preweek_topic', tp.id)" @change="toggle('preweek_topic', tp.id, $event.target.checked)"> Topic: <span x-text="tp.label"></span></label>
        </template>
      </details>
    </template>
  </details>

  <details x-show="catalog.test_bank.length">
    <summary>Test Bank</summary>
    <template x-for="tb in catalog.test_bank" :key="'tb-'+tb.id">
      <label><input type="checkbox" :checked="isChecked('test_bank', tb.id)" @change="toggle('test_bank', tb.id, $event.target.checked)"> <span x-text="tb.label"></span></label>
    </template>
  </details>
</div>
