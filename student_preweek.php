<?php
require_once 'auth.php';
requireRole('student');
require_once __DIR__ . '/includes/preweek_migrate.php';

$legacySubject = sanitizeInt($_GET['subject_id'] ?? 0);
if ($legacySubject > 0) {
    header('Location: student_preweek.php');
    exit;
}

$listQ = mysqli_query($conn, 'SELECT u.preweek_unit_id, u.title, u.created_at,
  (SELECT COUNT(*) FROM preweek_topics t WHERE t.preweek_unit_id = u.preweek_unit_id) AS topics_cnt,
  (SELECT COUNT(*) FROM preweek_videos v INNER JOIN preweek_topics t ON v.preweek_topic_id = t.preweek_topic_id WHERE t.preweek_unit_id = u.preweek_unit_id) AS videos_cnt,
  (SELECT COUNT(*) FROM preweek_handouts h INNER JOIN preweek_topics t ON h.preweek_topic_id = t.preweek_topic_id WHERE t.preweek_unit_id = u.preweek_unit_id) AS handouts_cnt
  FROM preweek_units u
  WHERE u.subject_id = 0
  ORDER BY u.created_at DESC');
$preweekRows = [];
if ($listQ) {
    while ($row = mysqli_fetch_assoc($listQ)) {
        $preweekRows[] = $row;
    }
}
$rowTotal = count($preweekRows);

$itemsForAlpine = [];
foreach ($preweekRows as $row) {
    $pid = (int)$row['preweek_unit_id'];
    $itemsForAlpine[] = [
        'id' => $pid,
        'title' => trim((string)($row['title'] ?? '')) ?: 'Pre-week',
        'href' => 'student_preweek_topics.php?preweek_unit_id=' . $pid,
        'topics' => (int)($row['topics_cnt'] ?? 0),
        'videos' => (int)($row['videos_cnt'] ?? 0),
        'handouts' => (int)($row['handouts_cnt'] ?? 0),
    ];
}

$pageTitle = 'Pre-week';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_app.php'; ?>
  <?php require_once __DIR__ . '/includes/student_materials_list_styles.php'; ?>
  <style>
    .preweek-page { background: linear-gradient(180deg, #eef5fc 0%, #e4f0fa 45%, #ebf4fc 100%); }
    .student-hero {
      border-radius: 0.75rem;
      border: 1px solid rgba(255,255,255,0.28);
      background: linear-gradient(130deg, #1665A0 0%, #145a8f 38%, #143D59 100%);
      box-shadow: 0 14px 34px -20px rgba(20, 61, 89, 0.85), inset 0 1px 0 rgba(255,255,255,0.22);
    }
    .hero-strip { background: rgba(255,255,255,0.14); border: 1px solid rgba(255,255,255,0.24); border-radius: .62rem; }
    .section-title {
      display: flex; align-items: center; gap: .5rem; margin: 0 0 .85rem; padding: .45rem .65rem;
      border: 1px solid #d8e8f6; border-radius: .62rem; background: linear-gradient(180deg,#f4f9fe 0%,#fff 100%);
      color: #143D59; font-size: 1.03rem; font-weight: 800;
    }
    .section-title i {
      width: 1.55rem; height: 1.55rem; border-radius: .45rem; display: inline-flex; align-items: center; justify-content: center;
      border: 1px solid #b9daf2; background: #e8f2fa; color: #1665A0; font-size: .83rem;
    }
    .dash-anim { opacity: 0; transform: translateY(10px); animation: dashFadeUp .55s ease-out forwards; }
    .delay-1 { animation-delay: .05s; } .delay-2 { animation-delay: .12s; }
    @keyframes dashFadeUp { to { opacity: 1; transform: translateY(0); } }
    [x-cloak] { display: none !important; }
  </style>
  <script>
  window.__EREVIEW_PREWEEK_HOME_ITEMS__ = <?php echo json_encode($itemsForAlpine, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;
  </script>
  <script>
  document.addEventListener('alpine:init', function() {
    Alpine.data('preweekHomePage', function() {
      return {
        preweekView: 'cards',
        preweekItems: [],
        preweekSearch: '',
        preweekSort: 'id_desc',
        get preweekFiltered() {
          var list = this.preweekItems.slice();
          var q = (this.preweekSearch || '').trim().toLowerCase();
          if (q) {
            list = list.filter(function(it) {
              return (it.title || '').toLowerCase().indexOf(q) !== -1;
            });
          }
          var sort = this.preweekSort;
          if (sort === 'title_asc') {
            list.sort(function(a, b) { return (a.title || '').localeCompare(b.title || '', undefined, { sensitivity: 'base' }); });
          } else if (sort === 'title_desc') {
            list.sort(function(a, b) { return (b.title || '').localeCompare(a.title || '', undefined, { sensitivity: 'base' }); });
          } else if (sort === 'id_asc') {
            list.sort(function(a, b) { return a.id - b.id; });
          } else {
            list.sort(function(a, b) { return b.id - a.id; });
          }
          return list;
        },
        init: function() {
          try {
            if (window.__EREVIEW_PREWEEK_HOME_ITEMS__ && Array.isArray(window.__EREVIEW_PREWEEK_HOME_ITEMS__)) {
              this.preweekItems = window.__EREVIEW_PREWEEK_HOME_ITEMS__.slice();
            }
          } catch (e) {}
          try {
            var v = localStorage.getItem('ereview_preweek_home_view');
            if (v === 'list' || v === 'cards') this.preweekView = v;
          } catch (e) {}
          try {
            var s = localStorage.getItem('ereview_preweek_home_sort');
            if (s === 'id_asc' || s === 'id_desc' || s === 'title_asc' || s === 'title_desc') this.preweekSort = s;
          } catch (e) {}
        }
      };
    });
  });
  </script>
</head>
<body class="font-sans antialiased preweek-page student-protected student-shell-page" x-data="preweekHomePage()">
  <?php include 'student_sidebar.php'; ?>
  <?php include 'student_topbar.php'; ?>

  <div class="student-dashboard-page min-h-full pb-8">
    <section class="student-hero dash-anim delay-1 relative overflow-hidden mb-6 px-6 py-7 text-white">
      <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 class="text-2xl sm:text-3xl font-bold m-0 flex items-center gap-3">
            <span class="flex h-12 w-12 items-center justify-center rounded-xl bg-white/20 border border-white/30"><i class="bi bi-lightning-charge"></i></span>
            Pre-week
          </h1>
          <p class="text-white/90 mt-2 mb-0 max-w-2xl">Open a pre-week, then choose a lecture to view videos and handouts.</p>
        </div>
      </div>
      <div class="hero-strip mt-4 px-4 py-2.5 text-sm flex flex-wrap gap-x-3 gap-y-1">
        <span class="font-semibold"><?php echo (int)$rowTotal; ?> pre-week<?php echo $rowTotal === 1 ? '' : 's'; ?> available</span>
      </div>
    </section>

    <h2 class="section-title dash-anim delay-2"><i class="bi bi-collection-play"></i> Your pre-weeks</h2>

    <?php if ($rowTotal === 0): ?>
      <div class="dash-anim delay-2 rounded-2xl border border-[#1665A0]/15 bg-white px-6 py-12 text-center text-[#143D59]/70">No pre-week entries yet. Check back later.</div>
    <?php else: ?>
    <div class="materials-section dash-anim delay-2 rounded-2xl border border-[#1665A0]/15 shadow-[0_2px_8px_rgba(20,61,89,0.1),0_4px_16px_rgba(20,61,89,0.06)] overflow-hidden mb-5 bg-gradient-to-b from-[#f0f7fc] to-white border-l-4 border-l-[#1665A0]">
      <div class="px-4 sm:px-6 py-4 border-b border-[#1665A0]/10 bg-[#e8f2fa]/50 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex items-center gap-3 min-w-0">
          <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-[#1665A0] text-white shadow-lg shadow-[#1665A0]/25">
            <i class="bi bi-folder2-open text-lg" aria-hidden="true"></i>
          </span>
          <div class="min-w-0">
            <h3 class="text-lg font-bold text-[#143D59] m-0">Pre-week list</h3>
            <p class="text-sm text-[#143D59]/70 mt-0.5 mb-0">Cards or list — same as your subject materials.</p>
          </div>
        </div>
        <div class="materials-view-toggle" role="group" aria-label="Layout">
          <span class="materials-view-toggle__label">View</span>
          <div class="materials-view-seg">
            <button type="button"
              @click="preweekView = 'cards'; try { localStorage.setItem('ereview_preweek_home_view', 'cards'); } catch (e) {}"
              :class="preweekView === 'cards' ? 'is-active' : ''"
              :aria-pressed="preweekView === 'cards'"
              aria-label="Card view">
              <i class="bi bi-grid-3x3-gap" aria-hidden="true"></i><span class="hidden sm:inline">Cards</span>
            </button>
            <button type="button"
              @click="preweekView = 'list'; try { localStorage.setItem('ereview_preweek_home_view', 'list'); } catch (e) {}"
              :class="preweekView === 'list' ? 'is-active' : ''"
              :aria-pressed="preweekView === 'list'"
              aria-label="List view">
              <i class="bi bi-list-ul" aria-hidden="true"></i><span class="hidden sm:inline">List</span>
            </button>
          </div>
        </div>
      </div>

      <div class="materials-toolbar" role="search">
        <div class="materials-search-wrap">
          <i class="bi bi-search materials-search-icon" aria-hidden="true"></i>
          <label for="preweek-home-search" class="sr-only">Search pre-weeks</label>
          <input
            id="preweek-home-search"
            type="search"
            class="materials-search-input"
            placeholder="Search by name…"
            autocomplete="off"
            x-model.debounce.300ms="preweekSearch"
          >
          <button
            type="button"
            class="materials-search-clear"
            x-show="(preweekSearch || '').trim().length > 0"
            x-cloak
            @click="preweekSearch = ''"
            aria-label="Clear search"
          >
            <i class="bi bi-x-lg" aria-hidden="true"></i>
          </button>
        </div>
        <div class="materials-sort-group">
          <span class="materials-sort-group__label" id="preweek-home-sort-lbl">Sort</span>
          <select
            id="preweek-home-sort"
            class="materials-sort-select"
            aria-labelledby="preweek-home-sort-lbl"
            x-model="preweekSort"
            @change="try { localStorage.setItem('ereview_preweek_home_sort', preweekSort); } catch (e) {}"
          >
            <option value="id_desc">Newest first</option>
            <option value="id_asc">Oldest first</option>
            <option value="title_asc">Title A–Z</option>
            <option value="title_desc">Title Z–A</option>
          </select>
        </div>
      </div>
      <p class="materials-results-hint" x-show="preweekItems.length > 0" x-cloak>
        <span x-show="(preweekSearch || '').trim().length > 0">
          Showing <strong x-text="preweekFiltered.length"></strong> of <strong x-text="preweekItems.length"></strong>
        </span>
        <span x-show="(preweekSearch || '').trim().length === 0" class="text-[#143D59]/60">
          <strong x-text="preweekItems.length"></strong> pre-week<span x-show="preweekItems.length !== 1">s</span>
        </span>
      </p>

      <div x-show="preweekView === 'cards'" x-cloak class="lesson-cards-wrap">
        <div class="lesson-cards-grid" role="list">
          <template x-for="(it, idx) in preweekFiltered" :key="it.id">
            <a :href="it.href" class="lesson-card" role="listitem">
              <div class="lesson-card__top">
                <span class="lesson-card__badge" aria-hidden="true" x-text="idx + 1"></span>
                <span class="lesson-card__icon" aria-hidden="true"><i class="bi bi-lightning-charge-fill"></i></span>
              </div>
              <h3 class="lesson-card__title" x-text="it.title"></h3>
              <p class="text-sm text-[#143D59]/75 mt-2 mb-0">
                <span class="inline-flex items-center gap-3 flex-wrap">
                  <span><i class="bi bi-journal-text"></i> <span x-text="it.topics"></span> lecture<span x-show="it.topics !== 1">s</span></span>
                  <span><i class="bi bi-play-circle"></i> <span x-text="it.videos"></span> video<span x-show="it.videos !== 1">s</span></span>
                  <span><i class="bi bi-file-earmark"></i> <span x-text="it.handouts"></span> handout<span x-show="it.handouts !== 1">s</span></span>
                </span>
              </p>
              <div class="lesson-card__meta">
                <span class="lesson-card__hint">Pre-week</span>
                <span class="lesson-card__cta">Open <i class="bi bi-arrow-right-short" aria-hidden="true"></i></span>
              </div>
            </a>
          </template>
        </div>
      </div>

      <div x-show="preweekView === 'list'" x-cloak class="lesson-list-wrap">
        <ul class="lesson-list" role="list">
          <template x-for="(it, idx) in preweekFiltered" :key="it.id">
            <li class="lesson-list__item" role="listitem">
              <a :href="it.href" class="lesson-list__link">
                <span class="lesson-list__idx" aria-hidden="true" x-text="idx + 1"></span>
                <div class="lesson-list__body">
                  <h3 class="lesson-list__title" x-text="it.title"></h3>
                  <span class="lesson-list__sub">
                    <span x-text="it.topics"></span> lecture<span x-show="it.topics !== 1">s</span>
                    · <span x-text="it.videos"></span> video<span x-show="it.videos !== 1">s</span>
                    · <span x-text="it.handouts"></span> handout<span x-show="it.handouts !== 1">s</span>
                  </span>
                </div>
                <span class="lesson-list__action">
                  <span class="lesson-list__pill">Open <i class="bi bi-arrow-right-short" aria-hidden="true"></i></span>
                </span>
              </a>
            </li>
          </template>
        </ul>
      </div>

      <div class="lesson-no-match" role="status" x-show="preweekFiltered.length === 0 && preweekItems.length > 0" x-cloak>
        <i class="bi bi-search" aria-hidden="true"></i>
        No pre-weeks match your search. Clear the search box or try other keywords.
      </div>
    </div>
    <?php endif; ?>
  </div>
</main>
</body>
</html>
