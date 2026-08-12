<?php
/**
 * Registration enrollment mode + catalog selection (Phase 4).
 * Expects $regPackages and $regTopicGroups from commerce helpers.
 */
$regPackages = $regPackages ?? [];
$regTopicGroups = $regTopicGroups ?? [];
?>
<span class="reg-section-label" id="reg-label-enrollment"><i class="bi bi-check-circle-fill reg-section-label-check" aria-hidden="true"></i>Enrollment</span>
<p class="text-sm text-slate-500 mb-3">Choose how you want to enroll. Package and topic prices come from the current catalog.</p>

<input type="hidden" name="enrollment_path" id="reg-enrollment_path" :value="enrollmentPath" required>
<input type="hidden" name="package_id" id="reg-package_id" :value="packageId || ''">
<template x-for="id in selectedLessonIds" :key="'hid-'+id">
  <input type="hidden" name="lesson_ids[]" :value="id">
</template>

<div class="reg-section reg-enroll-modes grid grid-cols-1 sm:grid-cols-3 gap-3 mb-4" role="radiogroup" aria-label="Enrollment type">
  <button type="button" class="reg-enroll-card text-left rounded-xl border-2 p-4 transition"
          :class="enrollmentPath === 'package' ? 'reg-enroll-card--active' : ''"
          @click="enrollmentPath = 'package'; packageId = packageId || null"
          aria-pressed="false" :aria-pressed="enrollmentPath === 'package'">
    <span class="block font-bold text-slate-800">Package</span>
    <span class="block text-xs text-slate-500 mt-1">Full catalog packages with duration and features</span>
  </button>
  <button type="button" class="reg-enroll-card text-left rounded-xl border-2 p-4 transition"
          :class="enrollmentPath === 'by_topic' ? 'reg-enroll-card--active' : ''"
          @click="enrollmentPath = 'by_topic'; ensureTopicBrowserOpen()"
          :aria-pressed="enrollmentPath === 'by_topic'">
    <span class="block font-bold text-slate-800">By Topic</span>
    <span class="block text-xs text-slate-500 mt-1">Pick topics organized by subject</span>
  </button>
  <button type="button" class="reg-enroll-card text-left rounded-xl border-2 p-4 transition"
          :class="enrollmentPath === 'free_access' ? 'reg-enroll-card--active' : ''"
          @click="enrollmentPath = 'free_access'; packageId = null; selectedLessons = {}"
          :aria-pressed="enrollmentPath === 'free_access'">
    <span class="block font-bold text-slate-800">Free Access</span>
    <span class="block text-xs text-slate-500 mt-1">Request access - no payment required</span>
  </button>
</div>
<span id="reg-error-enrollment_path" class="reg-inline-error" role="alert" aria-live="polite"></span>

<!-- Package catalog -->
<div class="reg-section space-y-3" x-show="enrollmentPath === 'package'" x-cloak>
  <p class="text-sm text-slate-600" x-show="packages.length === 0">No purchasable packages are available yet. Please check back later or contact support.</p>
  <template x-for="pkg in packages" :key="pkg.package_id">
    <label class="block rounded-xl border-2 p-4 cursor-pointer transition reg-package-card"
           :class="String(packageId) === String(pkg.package_id) ? 'reg-enroll-card--active' : ''">
      <div class="flex gap-3 items-start">
        <input type="radio" class="mt-1" name="package_id_radio" :value="pkg.package_id" x-model.number="packageId">
        <div class="min-w-0 flex-1">
          <div class="flex flex-wrap items-baseline justify-between gap-2">
            <span class="font-bold text-slate-900" x-text="pkg.name"></span>
            <span class="font-extrabold text-[#1F58C3]" x-text="pkg.price_display"></span>
          </div>
          <p class="text-xs text-slate-500 mt-1" x-text="pkg.duration_label + ' · ' + pkg.currency"></p>
          <p class="text-sm text-slate-600 mt-2" x-show="pkg.description" x-text="pkg.description"></p>
          <p class="mt-2 text-xs font-semibold text-emerald-700" x-show="pkg.access_scope === 'full_lms'">
            Full LMS access included
          </p>
          <ul class="mt-2 space-y-1" x-show="pkg.access_scope === 'mapped' && pkg.mapped_content && pkg.mapped_content.length">
            <li class="text-xs font-semibold text-slate-700">Included content:</li>
            <template x-for="(m, mi) in pkg.mapped_content" :key="mi">
              <li class="text-xs text-slate-600" x-text="'• ' + m.label + ' (' + m.content_type + ')'"></li>
            </template>
          </ul>
          <ul class="mt-2 space-y-1" x-show="pkg.features && pkg.features.length">
            <li class="text-xs font-semibold text-slate-700">Package features:</li>
            <template x-for="(f, fi) in pkg.features" :key="fi">
              <li class="text-xs text-slate-600" x-text="'• ' + f.label"></li>
            </template>
          </ul>
        </div>
      </div>
    </label>
  </template>
  <span id="reg-error-package_id" class="reg-inline-error" role="alert" aria-live="polite"></span>
  <p class="text-xs text-slate-500 rounded-lg bg-blue-50 border border-blue-100 px-3 py-2">
    Payment comes after email verification. You'll upload GCash proof only on the payment page-not here.
  </p>
</div>

<!-- By Topic catalog: organized by subject + search -->
<div class="reg-section space-y-3" x-show="enrollmentPath === 'by_topic'" x-cloak>
  <p class="text-sm text-slate-600" x-show="topicGroups.length === 0">No purchasable topics are available yet.</p>

  <div class="reg-topic-browser" x-show="topicGroups.length > 0">
    <div class="reg-topic-toolbar">
      <label class="sr-only" for="reg-topic-search">Search topics by name or subject</label>
      <div class="reg-topic-search-wrap">
        <i class="bi bi-search" aria-hidden="true"></i>
        <input type="search"
               id="reg-topic-search"
               class="reg-topic-search"
               placeholder="Search topic or subject (e.g. Cash, FAR, Taxation)..."
               autocomplete="off"
               x-model="topicSearch"
               @input="onTopicSearchInput()">
        <button type="button"
                class="reg-topic-search-clear"
                x-show="topicSearch.trim() !== ''"
                @click="topicSearch = ''; onTopicSearchInput()"
                aria-label="Clear search">
          <i class="bi bi-x-lg" aria-hidden="true"></i>
        </button>
      </div>
      <div class="reg-topic-toolbar-meta">
        <span x-text="filteredTopicCount + ' topic' + (filteredTopicCount === 1 ? '' : 's')"></span>
        <span aria-hidden="true">·</span>
        <span x-text="filteredSubjectCount + ' subject' + (filteredSubjectCount === 1 ? '' : 's')"></span>
        <span x-show="selectedLessonIds.length" x-cloak>
          <span aria-hidden="true">·</span>
          <strong x-text="selectedLessonIds.length + ' selected'"></strong>
        </span>
      </div>
    </div>

    <div class="reg-topic-subject-chips" x-show="topicGroups.length > 1" x-cloak>
      <button type="button"
              class="reg-topic-chip"
              :class="topicSubjectFilter === null ? 'reg-topic-chip--active' : ''"
              @click="topicSubjectFilter = null">
        All subjects
      </button>
      <template x-for="group in topicGroups" :key="'chip-'+group.subject_id">
        <button type="button"
                class="reg-topic-chip"
                :class="String(topicSubjectFilter) === String(group.subject_id) ? 'reg-topic-chip--active' : ''"
                @click="topicSubjectFilter = group.subject_id; openSubject(group.subject_id)">
          <span x-text="group.subject_name"></span>
          <span class="reg-topic-chip-count" x-text="(group.topics || []).length"></span>
        </button>
      </template>
    </div>

    <p class="text-sm text-slate-500 px-1" x-show="filteredTopicGroups.length === 0" x-cloak>
      No topics match "<span class="font-semibold text-slate-700" x-text="topicSearch"></span>".
      Try another keyword or clear the search.
    </p>

    <div class="reg-topic-groups">
      <template x-for="group in filteredTopicGroups" :key="'subj-'+group.subject_id">
        <div class="reg-topic-subject"
             :class="isSubjectOpen(group.subject_id) ? 'reg-topic-subject--open' : ''">
          <button type="button"
                  class="reg-topic-subject-head"
                  @click="toggleSubject(group.subject_id)"
                  :aria-expanded="isSubjectOpen(group.subject_id) ? 'true' : 'false'">
            <span class="reg-topic-subject-title">
              <i class="bi" :class="isSubjectOpen(group.subject_id) ? 'bi-chevron-down' : 'bi-chevron-right'" aria-hidden="true"></i>
              <span x-text="group.subject_name"></span>
            </span>
            <span class="reg-topic-subject-meta">
              <span x-text="group.topics.length + ' topic' + (group.topics.length === 1 ? '' : 's')"></span>
              <span class="reg-topic-selected-badge"
                    x-show="selectedCountInGroup(group) > 0"
                    x-text="selectedCountInGroup(group) + ' selected'"
                    x-cloak></span>
            </span>
          </button>
          <div class="reg-topic-subject-body" x-show="isSubjectOpen(group.subject_id)" x-cloak>
            <template x-for="topic in group.topics" :key="topic.lesson_id">
              <label class="reg-topic-row"
                     :class="selectedLessons[topic.lesson_id] ? 'reg-topic-row--selected' : ''">
                <input type="checkbox"
                       class="mt-1"
                       :value="topic.lesson_id"
                       @change="toggleLesson(topic.lesson_id, $event.target.checked)"
                       :checked="!!selectedLessons[topic.lesson_id]">
                <span class="min-w-0 flex-1">
                  <span class="block text-sm font-semibold text-slate-800" x-text="topic.title"></span>
                  <span class="block text-xs text-slate-500" x-text="topic.price_display + ' · ' + topic.duration_label"></span>
                </span>
              </label>
            </template>
          </div>
        </div>
      </template>
    </div>

    <div class="reg-topic-selected-summary" x-show="selectedLessonIds.length > 0" x-cloak>
      <div class="reg-topic-selected-summary__title">Your selected topics</div>
      <template x-for="group in selectedTopicGroups" :key="'sel-'+group.subject_id">
        <div class="reg-topic-selected-group">
          <div class="reg-topic-selected-group__subj" x-text="group.subject_name"></div>
          <ul>
            <template x-for="topic in group.topics" :key="'sel-t-'+topic.lesson_id">
              <li>
                <span x-text="topic.title"></span>
                <span class="reg-topic-selected-price" x-text="topic.price_display"></span>
                <button type="button" class="reg-topic-remove" @click="toggleLesson(topic.lesson_id, false)" aria-label="Remove topic">Remove</button>
              </li>
            </template>
          </ul>
        </div>
      </template>
    </div>
  </div>

  <div class="flex items-center justify-between rounded-xl bg-slate-50 border border-slate-200 px-4 py-3">
    <span class="text-sm font-semibold text-slate-700">Estimated total</span>
    <span class="text-lg font-extrabold text-[#1F58C3]" x-text="topicTotalDisplay"></span>
  </div>
  <p class="text-xs text-slate-500">Browse by subject, then search if you know the topic name. Final amount is always recalculated on the server from current catalog prices.</p>
  <span id="reg-error-lesson_ids" class="reg-inline-error" role="alert" aria-live="polite"></span>
  <p class="text-xs text-slate-500 rounded-lg bg-blue-50 border border-blue-100 px-3 py-2">
    Payment comes after email verification. You'll upload GCash proof only on the payment page-not here.
  </p>
</div>

<!-- Free Access -->
<div class="reg-section space-y-3" x-show="enrollmentPath === 'free_access'" x-cloak>
  <div class="rounded-xl border border-emerald-200 bg-emerald-50/80 px-4 py-3 text-sm text-emerald-900">
    <p class="font-bold mb-1">Free Access request</p>
    <p>No payment, GCash QR, proof of payment, or reference number is required. After you verify your email, an administrator will review your request and choose package or topic access plus duration.</p>
  </div>
  <div>
    <label class="reg-top-label" for="reg-free_access_note">Optional note for admin</label>
    <textarea class="auth-input w-full rounded-xl border px-4 py-3 text-sm" name="free_access_note" id="reg-free_access_note" rows="3" maxlength="1000" placeholder="Tell us briefly why you are requesting free access (optional)" x-model="freeAccessNote"></textarea>
  </div>
</div>
