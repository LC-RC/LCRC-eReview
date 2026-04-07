<?php
/**
 * Items inside a pre-week (DB: preweek_topics) — student picks one, then opens viewer.
 */
require_once 'auth.php';
requireRole('student');
require_once __DIR__ . '/includes/preweek_migrate.php';

$legacySubject = sanitizeInt($_GET['subject_id'] ?? 0);
if ($legacySubject > 0) {
    header('Location: student_preweek.php');
    exit;
}

$unitId = (int)($_GET['preweek_unit_id'] ?? 0);
if ($unitId <= 0) {
    header('Location: student_preweek.php');
    exit;
}

$unitRes = mysqli_query($conn, 'SELECT preweek_unit_id, title FROM preweek_units WHERE preweek_unit_id=' . $unitId . ' AND subject_id=0 LIMIT 1');
$unit = $unitRes ? mysqli_fetch_assoc($unitRes) : null;
if (!$unit) {
    header('Location: student_preweek.php');
    exit;
}

$unitTitle = trim((string)($unit['title'] ?? 'Preweek')) ?: 'Preweek';

$listQ = mysqli_query($conn, '
  SELECT t.preweek_topic_id, t.title, t.description,
    (SELECT COUNT(*) FROM preweek_videos v WHERE v.preweek_topic_id = t.preweek_topic_id) AS videos_cnt,
    (SELECT COUNT(*) FROM preweek_handouts h WHERE h.preweek_topic_id = t.preweek_topic_id) AS handouts_cnt
  FROM preweek_topics t
  WHERE t.preweek_unit_id=' . (int)$unitId . '
  ORDER BY t.sort_order ASC, t.preweek_topic_id DESC
');
$topicRows = [];
if ($listQ) {
    while ($row = mysqli_fetch_assoc($listQ)) {
        $topicRows[] = $row;
    }
}

$itemsForAlpine = [];
foreach ($topicRows as $row) {
    $tid = (int)$row['preweek_topic_id'];
    $itemsForAlpine[] = [
        'id' => $tid,
        'title' => trim((string)($row['title'] ?? '')) ?: 'Untitled',
        'desc' => trim((string)($row['description'] ?? '')),
        'href' => 'student_preweek_viewer.php?preweek_topic_id=' . $tid,
        'videos' => (int)($row['videos_cnt'] ?? 0),
        'handouts' => (int)($row['handouts_cnt'] ?? 0),
    ];
}

$rowTotal = count($itemsForAlpine);
$pageTitle = $unitTitle . ' — Materials';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_app.php'; ?>
  <?php require_once __DIR__ . '/includes/student_materials_list_styles.php'; ?>
  <style>
    .preweek-inner-page { background: linear-gradient(180deg, #eef5fc 0%, #e4f0fa 45%, #ebf4fc 100%); }
    .student-hero-pw {
      border-radius: 0.75rem;
      border: 1px solid rgba(255,255,255,0.28);
      background: linear-gradient(130deg, #1665A0 0%, #145a8f 38%, #143D59 100%);
      box-shadow: 0 14px 34px -20px rgba(20, 61, 89, 0.85), inset 0 1px 0 rgba(255,255,255,0.22);
    }
    .hero-strip-pw { background: rgba(255,255,255,0.14); border: 1px solid rgba(255,255,255,0.24); border-radius: .62rem; }
    .dash-anim { opacity: 0; transform: translateY(10px); animation: dashFadeUp .55s ease-out forwards; }
    .delay-1 { animation-delay: .05s; } .delay-2 { animation-delay: .12s; }
    @keyframes dashFadeUp { to { opacity: 1; transform: translateY(0); } }
  </style>
  <script>
  window.__EREVIEW_PREWEEK_ITEMS__ = <?php echo json_encode($itemsForAlpine, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;
  </script>
  <script>
  document.addEventListener('alpine:init', function() {
    Alpine.data('preweekMaterialsPage', function() {
      return {
        materialsView: 'cards',
        materialsItems: [],
        materialsSearch: '',
        materialsSort: 'id_desc',
        get materialsFiltered() {
          var list = this.materialsItems.slice();
          var q = (this.materialsSearch || '').trim().toLowerCase();
          if (q) {
            list = list.filter(function(it) {
              var t = (it.title || '').toLowerCase();
              var d = (it.desc || '').toLowerCase();
              return t.indexOf(q) !== -1 || d.indexOf(q) !== -1;
            });
          }
          var sort = this.materialsSort;
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
            if (window.__EREVIEW_PREWEEK_ITEMS__ && Array.isArray(window.__EREVIEW_PREWEEK_ITEMS__)) {
              this.materialsItems = window.__EREVIEW_PREWEEK_ITEMS__.slice();
            }
          } catch (e) {}
          try {
            var v = localStorage.getItem('ereview_preweek_materials_view');
            if (v === 'list' || v === 'cards') this.materialsView = v;
          } catch (e) {}
          try {
            var ms = localStorage.getItem('ereview_preweek_materials_sort');
            if (ms === 'id_asc' || ms === 'id_desc' || ms === 'title_asc' || ms === 'title_desc') {
              this.materialsSort = ms;
            }
          } catch (e) {}
        }
      };
    });
  });
  </script>
</head>
<body class="font-sans antialiased preweek-inner-page student-protected student-shell-page" x-data="preweekMaterialsPage()">
  <?php include 'student_sidebar.php'; ?>
  <?php include 'student_topbar.php'; ?>

  <div class="student-dashboard-page min-h-full pb-8">
    <section class="student-hero-pw dash-anim delay-1 relative overflow-hidden mb-5 px-6 py-7 text-white">
      <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="min-w-0">
          <a href="student_preweek.php" class="inline-flex items-center gap-2 text-white/90 hover:text-white text-sm font-semibold mb-3 no-underline"><i class="bi bi-arrow-left"></i> All pre-weeks</a>
          <h1 class="text-2xl sm:text-3xl font-bold m-0 flex items-center gap-3">
            <span class="flex h-12 w-12 items-center justify-center rounded-xl bg-white/20 border border-white/30"><i class="bi bi-collection-play"></i></span>
            <?php echo h($unitTitle); ?>
          </h1>
          <p class="text-white/90 mt-2 mb-0 max-w-2xl">Pick a pre-week lecture below to open its videos and handouts. Use cards or list — same as your subject materials.</p>
        </div>
      </div>
      <div class="hero-strip-pw mt-4 px-4 py-2.5 text-sm flex flex-wrap gap-x-3 gap-y-1">
        <span class="font-semibold"><?php echo (int)$rowTotal; ?> lecture<?php echo $rowTotal === 1 ? '' : 's'; ?></span>
      </div>
    </section>

    <div class="materials-section dash-anim delay-2 rounded-2xl border border-[#1665A0]/15 shadow-[0_2px_8px_rgba(20,61,89,0.1),0_4px_16px_rgba(20,61,89,0.06)] overflow-hidden mb-5 bg-gradient-to-b from-[#f0f7fc] to-white border-l-4 border-l-[#1665A0]">
      <div class="px-4 sm:px-6 py-4 border-b border-[#1665A0]/10 bg-[#e8f2fa]/50 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex items-center gap-3 min-w-0">
          <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-[#1665A0] text-white shadow-lg shadow-[#1665A0]/25">
            <i class="bi bi-play-circle-fill text-lg" aria-hidden="true"></i>
          </span>
          <div class="min-w-0">
            <h2 class="text-lg font-bold text-[#143D59] m-0">Materials</h2>
            <p class="text-sm text-[#143D59]/70 mt-0.5 mb-0">Videos and handouts for this pre-week. Click to open.</p>
          </div>
        </div>
        <?php if ($rowTotal > 0): ?>
        <div class="materials-view-toggle" role="group" aria-label="Layout">
          <span class="materials-view-toggle__label">View</span>
          <div class="materials-view-seg">
            <button type="button"
              @click="materialsView = 'cards'; try { localStorage.setItem('ereview_preweek_materials_view', 'cards'); } catch (e) {}"
              :class="materialsView === 'cards' ? 'is-active' : ''"
              :aria-pressed="materialsView === 'cards'"
              aria-label="Card view">
              <i class="bi bi-grid-3x3-gap" aria-hidden="true"></i><span class="hidden sm:inline">Cards</span>
            </button>
            <button type="button"
              @click="materialsView = 'list'; try { localStorage.setItem('ereview_preweek_materials_view', 'list'); } catch (e) {}"
              :class="materialsView === 'list' ? 'is-active' : ''"
              :aria-pressed="materialsView === 'list'"
              aria-label="List view">
              <i class="bi bi-list-ul" aria-hidden="true"></i><span class="hidden sm:inline">List</span>
            </button>
          </div>
        </div>
        <?php endif; ?>
      </div>

      <?php if ($rowTotal === 0): ?>
      <div class="lesson-cards-wrap">
        <div class="lesson-empty-state" role="status">
          <i class="bi bi-inbox" aria-hidden="true"></i>
          No materials yet. Your school may add lectures here later.
        </div>
      </div>
      <?php else: ?>
      <div class="materials-toolbar" role="search">
        <div class="materials-search-wrap">
          <i class="bi bi-search materials-search-icon" aria-hidden="true"></i>
          <label for="preweek-mat-search" class="sr-only">Search materials</label>
          <input
            id="preweek-mat-search"
            type="search"
            class="materials-search-input"
            placeholder="Search by title…"
            autocomplete="off"
            x-model.debounce.300ms="materialsSearch"
          >
          <button
            type="button"
            class="materials-search-clear"
            x-show="(materialsSearch || '').trim().length > 0"
            x-cloak
            @click="materialsSearch = ''"
            aria-label="Clear search"
          >
            <i class="bi bi-x-lg" aria-hidden="true"></i>
          </button>
        </div>
        <div class="materials-sort-group">
          <span class="materials-sort-group__label" id="preweek-sort-lbl">Sort</span>
          <select
            id="preweek-sort-select"
            class="materials-sort-select"
            aria-labelledby="preweek-sort-lbl"
            x-model="materialsSort"
            @change="try { localStorage.setItem('ereview_preweek_materials_sort', materialsSort); } catch (e) {}"
          >
            <option value="id_desc">Newest first</option>
            <option value="id_asc">Oldest first</option>
            <option value="title_asc">Title A–Z</option>
            <option value="title_desc">Title Z–A</option>
          </select>
        </div>
      </div>
      <p class="materials-results-hint" x-show="materialsItems.length > 0" x-cloak>
        <span x-show="(materialsSearch || '').trim().length > 0">
          Showing <strong x-text="materialsFiltered.length"></strong> of <strong x-text="materialsItems.length"></strong>
        </span>
        <span x-show="(materialsSearch || '').trim().length === 0" class="text-[#143D59]/60">
          <strong x-text="materialsItems.length"></strong> lecture<span x-show="materialsItems.length !== 1">s</span>
          <span class="text-[#143D59]/45"> · Search or sort to find what you need.</span>
        </span>
      </p>

      <div x-show="materialsView === 'cards'" x-cloak class="lesson-cards-wrap">
        <div class="lesson-cards-grid" role="list">
          <template x-for="(it, idx) in materialsFiltered" :key="it.id">
            <a :href="it.href" class="lesson-card" role="listitem">
              <div class="lesson-card__top">
                <span class="lesson-card__badge" aria-hidden="true" x-text="idx + 1"></span>
                <span class="lesson-card__icon" aria-hidden="true"><i class="bi bi-play-fill"></i></span>
              </div>
              <h3 class="lesson-card__title" x-text="it.title"></h3>
              <p class="text-xs text-[#143D59]/65 mt-1 mb-0 line-clamp-2" x-show="it.desc" x-text="it.desc"></p>
              <p class="text-sm text-[#143D59]/75 mt-2 mb-0">
                <span class="inline-flex items-center gap-3 flex-wrap">
                  <span><i class="bi bi-play-circle"></i> <span x-text="it.videos"></span> video<span x-show="it.videos !== 1">s</span></span>
                  <span><i class="bi bi-file-earmark-pdf"></i> <span x-text="it.handouts"></span> handout<span x-show="it.handouts !== 1">s</span></span>
                </span>
              </p>
              <div class="lesson-card__meta">
                <span class="lesson-card__hint">Lecture</span>
                <span class="lesson-card__cta">Open <i class="bi bi-arrow-right-short" aria-hidden="true"></i></span>
              </div>
            </a>
          </template>
        </div>
      </div>

      <div x-show="materialsView === 'list'" x-cloak class="lesson-list-wrap">
        <ul class="lesson-list" role="list">
          <template x-for="(it, idx) in materialsFiltered" :key="it.id">
            <li class="lesson-list__item" role="listitem">
              <a :href="it.href" class="lesson-list__link">
                <span class="lesson-list__idx" aria-hidden="true" x-text="idx + 1"></span>
                <div class="lesson-list__body">
                  <h3 class="lesson-list__title" x-text="it.title"></h3>
                  <span class="lesson-list__sub">Lecture · <span x-text="it.videos"></span> video<span x-show="it.videos !== 1">s</span> · <span x-text="it.handouts"></span> handout<span x-show="it.handouts !== 1">s</span></span>
                </div>
                <span class="lesson-list__action">
                  <span class="lesson-list__pill">Open <i class="bi bi-arrow-right-short" aria-hidden="true"></i></span>
                </span>
              </a>
            </li>
          </template>
        </ul>
      </div>

      <div class="lesson-no-match" role="status" x-show="materialsFiltered.length === 0 && materialsItems.length > 0" x-cloak>
        <i class="bi bi-search" aria-hidden="true"></i>
        No lectures match your search. Try different keywords or clear the search box.
      </div>
      <?php endif; ?>
    </div>
  </div>
</main>
</body>
</html>
