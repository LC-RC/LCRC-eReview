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
          @click="enrollmentPath = 'by_topic'"
          :aria-pressed="enrollmentPath === 'by_topic'">
    <span class="block font-bold text-slate-800">By Topic</span>
    <span class="block text-xs text-slate-500 mt-1">Select one or more purchasable topics</span>
  </button>
  <button type="button" class="reg-enroll-card text-left rounded-xl border-2 p-4 transition"
          :class="enrollmentPath === 'free_access' ? 'reg-enroll-card--active' : ''"
          @click="enrollmentPath = 'free_access'; packageId = null; selectedLessons = {}"
          :aria-pressed="enrollmentPath === 'free_access'">
    <span class="block font-bold text-slate-800">Free Access</span>
    <span class="block text-xs text-slate-500 mt-1">Request access — no payment required</span>
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
    Payment comes after email verification. You’ll upload GCash proof only on the payment page—not here.
  </p>
</div>

<!-- By Topic catalog -->
<div class="reg-section space-y-3" x-show="enrollmentPath === 'by_topic'" x-cloak>
  <p class="text-sm text-slate-600" x-show="topicGroups.length === 0">No purchasable topics are available yet.</p>
  <template x-for="group in topicGroups" :key="group.subject_id">
    <div class="rounded-xl border border-slate-200 overflow-hidden">
      <div class="px-3 py-2 bg-slate-50 font-bold text-sm text-slate-800" x-text="group.subject_name"></div>
      <div class="divide-y divide-slate-100">
        <template x-for="topic in group.topics" :key="topic.lesson_id">
          <label class="flex items-start gap-3 px-3 py-3 cursor-pointer hover:bg-slate-50/80">
            <input type="checkbox" class="mt-1" :value="topic.lesson_id" @change="toggleLesson(topic.lesson_id, $event.target.checked)" :checked="!!selectedLessons[topic.lesson_id]">
            <span class="min-w-0 flex-1">
              <span class="block text-sm font-semibold text-slate-800" x-text="topic.title"></span>
              <span class="block text-xs text-slate-500" x-text="topic.price_display + ' · ' + topic.duration_label"></span>
            </span>
          </label>
        </template>
      </div>
    </div>
  </template>
  <div class="flex items-center justify-between rounded-xl bg-slate-50 border border-slate-200 px-4 py-3">
    <span class="text-sm font-semibold text-slate-700">Estimated total</span>
    <span class="text-lg font-extrabold text-[#1F58C3]" x-text="topicTotalDisplay"></span>
  </div>
  <p class="text-xs text-slate-500">Total shown is for your convenience. Final amount is always recalculated on the server from current catalog prices.</p>
  <span id="reg-error-lesson_ids" class="reg-inline-error" role="alert" aria-live="polite"></span>
  <p class="text-xs text-slate-500 rounded-lg bg-blue-50 border border-blue-100 px-3 py-2">
    Payment comes after email verification. You’ll upload GCash proof only on the payment page—not here.
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
