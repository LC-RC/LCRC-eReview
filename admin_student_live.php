<?php
require_once 'auth.php';
requireAdminPage('student_activity');
require_once __DIR__ . '/includes/student_activity.php';

student_activity_ensure_schema($conn);
$within = sanitizeInt($_GET['within'] ?? 180, 180);
$within = max(60, min(3600, $within));

$pageTitle = 'Live Student Activity';
$adminBreadcrumbs = [['Dashboard', 'admin_dashboard'], ['Live Activity']];
$adminHeroIcon = 'broadcast';
$adminHeroEyebrow = 'Live activity';
$adminHeroTitle = 'Live Learning Activity';
$adminHeroSubtitle = 'Console view of near real-time LMS presence — video position, watch time, and recent progress. College exams stay separate.';
$adminHeroActions = '<a class="admin-btn admin-btn--secondary admin-btn--sm inline-flex h-10 items-center gap-2 rounded-xl px-3.5 text-sm font-semibold" href="admin_quiz_monitor"><i class="bi bi-bar-chart-line"></i> Quiz Monitor</a>';
$liveApi = function_exists('ereview_url') ? ereview_url('admin_student_live_api') : 'admin_student_live_api';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_admin.php'; ?>
</head>
<body class="font-sans antialiased admin-app admin-student-live-page">
  <?php include 'admin_sidebar.php'; ?>
  <?php include __DIR__ . '/includes/components/admin_page_hero.php'; ?>

  <div class="space-y-4">
    <form method="get" class="edupro-toolbar admin-data-toolbar mb-0 flex flex-wrap items-end gap-2 rounded-2xl border border-white/80 bg-white/85 px-3 py-2.5" id="live-filter-form">
      <div>
        <label class="mb-0.5 block text-[10px] font-bold uppercase tracking-wide text-slate-500" for="within">Active within</label>
        <select id="within" name="within" class="input-custom min-h-[2.25rem] rounded-lg border border-slate-200 bg-white px-3 text-sm text-slate-800">
          <option value="120" <?php echo $within === 120 ? 'selected' : ''; ?>>2 minutes</option>
          <option value="180" <?php echo $within === 180 ? 'selected' : ''; ?>>3 minutes</option>
          <option value="300" <?php echo $within === 300 ? 'selected' : ''; ?>>5 minutes</option>
          <option value="600" <?php echo $within === 600 ? 'selected' : ''; ?>>10 minutes</option>
        </select>
      </div>
      <div class="pb-1.5 text-xs font-medium text-slate-500" id="live-status">Loading… · polling every 3s</div>
      <div class="inline-flex items-center gap-2 rounded-lg border border-emerald-100 bg-emerald-50/80 px-2.5 py-1.5 text-xs font-semibold text-emerald-700 sm:ml-auto">
        <span class="inline-block h-2 w-2 rounded-full bg-emerald-500" aria-hidden="true"></span>
        Live console
      </div>
    </form>

    <div id="live-empty" class="live-empty hidden rounded-2xl border border-dashed border-slate-200/90 bg-white/88 px-4 py-4 text-center">
      <span class="mx-auto mb-2 inline-flex h-9 w-9 items-center justify-center rounded-xl bg-slate-100 text-slate-500"><i class="bi bi-moon-stars"></i></span>
      <div class="text-sm font-semibold text-slate-800">No active sessions right now</div>
      <p class="mx-auto mt-1 mb-0 max-w-md text-xs text-slate-500">Widen the activity window or wait for learners to open LMS content. Recent watches still appear below.</p>
    </div>

    <div id="live-table-wrap" class="mb-0 overflow-hidden rounded-2xl border border-white/80 bg-white/80 shadow-[0_10px_30px_rgba(15,23,42,.07),0_2px_10px_rgba(37,99,235,.05)] backdrop-blur-xl page-table admin-data-surface">
      <div class="flex items-center gap-2 border-b border-slate-100 bg-gradient-to-r from-blue-50/70 via-indigo-50/40 to-violet-50/50 px-4 py-3 text-sm font-bold tracking-tight text-slate-900">
        <span class="inline-block h-2 w-2 animate-pulse rounded-full bg-emerald-500" aria-hidden="true"></span>
        Live now
      </div>
      <div class="overflow-x-auto">
        <table class="admin-table w-full text-sm">
          <thead>
            <tr>
              <th>Student</th>
              <th>Where now</th>
              <th>Video progress</th>
              <th>Subject / Lesson</th>
              <th>Session</th>
              <th>Last seen</th>
              <th></th>
            </tr>
          </thead>
          <tbody id="live-tbody">
            <tr>
              <td colspan="7" class="px-4 py-5 text-center">
                <div class="mx-auto flex max-w-sm flex-col items-center gap-2">
                  <span class="inline-flex h-11 w-11 items-center justify-center rounded-xl bg-blue-50 text-blue-600 ring-1 ring-white/80 shadow-sm"><i class="bi bi-broadcast"></i></span>
                  <div class="text-sm font-semibold text-slate-700">Loading live sessions…</div>
                  <p class="mb-0 text-xs text-slate-400">Polling student presence every 3 seconds.</p>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <div class="overflow-hidden rounded-2xl border border-white/80 bg-white/80 shadow-[0_8px_28px_rgba(15,23,42,0.05)] backdrop-blur page-table">
      <div class="border-b border-slate-100 bg-gradient-to-r from-slate-50/90 to-indigo-50/40 px-4 py-3 text-sm font-bold tracking-tight text-slate-900">Recent video watches by student (last 72 hours)</div>
      <div id="recent-by-student" class="space-y-3 p-3">
        <div class="px-2 py-4 text-center">
          <div class="mx-auto flex max-w-sm flex-col items-center gap-2">
            <span class="inline-flex h-11 w-11 items-center justify-center rounded-xl bg-indigo-50 text-indigo-600 ring-1 ring-white/80 shadow-sm"><i class="bi bi-clock-history"></i></span>
            <div class="text-sm font-semibold text-slate-700">Loading watch history…</div>
            <p class="mb-0 text-xs text-slate-400">Recent lesson video progress will appear here.</p>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
</main>
<script>
(function () {
  var apiBase = <?php echo json_encode($liveApi, JSON_UNESCAPED_SLASHES); ?>;
  var withinEl = document.getElementById('within');
  var statusEl = document.getElementById('live-status');
  var liveBody = document.getElementById('live-tbody');
  var liveEmpty = document.getElementById('live-empty');
  var liveTableWrap = document.getElementById('live-table-wrap');
  var recentWrap = document.getElementById('recent-by-student');

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }
  function fmt(sec) {
    sec = Math.max(0, Math.round(Number(sec) || 0));
    var h = Math.floor(sec / 3600);
    var m = Math.floor((sec % 3600) / 60);
    var s = sec % 60;
    if (h > 0) return h + ':' + String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
    return m + ':' + String(s).padStart(2, '0');
  }

  function whereLabel(row) {
    if (row.quiz_title) return 'Quiz: ' + row.quiz_title;
    if (row.video_title) return (row.video_is_playing ? 'Playing: ' : 'Video: ') + row.video_title;
    if (row.lesson_title) return 'Lesson: ' + row.lesson_title;
    return row.where || 'LMS';
  }

  function renderLive(rows) {
    if (!rows.length) {
      if (liveEmpty) liveEmpty.classList.remove('hidden');
      if (liveTableWrap) liveTableWrap.classList.add('hidden');
      return;
    }
    if (liveEmpty) liveEmpty.classList.add('hidden');
    if (liveTableWrap) liveTableWrap.classList.remove('hidden');
    liveBody.innerHTML = rows.map(function (row) {
      var videoHtml = '<span class="text-slate-400">-</span>';
      if (row.video_id) {
        var pct = Number(row.video_percent) || 0;
        var bar = row.video_duration_sec
          ? '<div class="mt-2 h-1.5 max-w-[180px] overflow-hidden rounded-full bg-slate-100"><div class="h-full bg-gradient-to-r from-blue-600 to-indigo-500" style="width:' + Math.min(100, Math.max(0, pct)) + '%"></div></div>'
          : '';
        var progressBlock = row.has_progress
          ? '<div class="mt-1"><span class="acl-pill">' + (row.video_is_playing ? 'Playing' : 'Paused / idle') + '</span></div>' +
            '<div class="mt-1 text-sm text-slate-700">At <strong class="text-slate-900">' + esc(fmt(row.video_position_sec)) + '</strong>' +
            (row.video_duration_sec ? (' / ' + esc(fmt(row.video_duration_sec)) + ' <span class="text-slate-400">(' + Math.round(pct) + '%)</span>') : '') +
            '</div><div class="mt-0.5 text-xs text-slate-400">Watched ' + esc(fmt(row.video_watch_seconds)) + ' total</div>' + bar
          : '<div class="mt-1 text-xs text-slate-400">On video page — waiting for playback ping</div>';
        videoHtml = '<div class="font-semibold text-slate-900">' + esc(row.video_title || ('Video #' + row.video_id)) + '</div>' + progressBlock;
      }
      return '<tr>' +
        '<td><div class="font-bold tracking-tight text-slate-900">' + esc(row.full_name) + '</div><div class="text-xs font-medium text-slate-400">' + esc(row.email) + '</div></td>' +
        '<td><div class="font-semibold text-slate-800">' + esc(whereLabel(row)) + '</div>' +
          (row.page_url ? '<div class="max-w-xs truncate text-xs text-slate-400">' + esc(row.page_url) + '</div>' : '') + '</td>' +
        '<td>' + videoHtml + '</td>' +
        '<td class="text-slate-600">' + esc(row.subject_name || '-') +
          (row.lesson_title ? '<div class="text-xs text-slate-400">' + esc(row.lesson_title) + '</div>' : '') + '</td>' +
        '<td class="whitespace-nowrap text-slate-600">' + esc(fmt(row.session_seconds)) + '</td>' +
        '<td class="whitespace-nowrap text-xs text-slate-500">' + esc(row.last_seen_label) + '</td>' +
        '<td><a class="admin-btn admin-btn--secondary admin-btn--sm" href="admin_student_view?id=' + row.user_id + '#student-activity">Open</a></td>' +
        '</tr>';
    }).join('');
  }

  function renderRecent(rows) {
    if (!rows.length) {
      recentWrap.innerHTML = '<div class="live-empty px-2 py-4 text-center">' +
        '<div class="mx-auto flex max-w-sm flex-col items-center gap-2">' +
          '<span class="inline-flex h-11 w-11 items-center justify-center rounded-xl bg-violet-50 text-violet-500 ring-1 ring-white/80 shadow-sm"><i class="bi bi-camera-video"></i></span>' +
          '<div class="text-sm font-semibold text-slate-700">No saved video watches yet</div>' +
          '<p class="mb-0 text-xs text-slate-400">Progress appears when students play lesson videos.</p>' +
        '</div></div>';
      return;
    }
    var groups = {};
    var order = [];
    rows.forEach(function (row) {
      var uid = String(row.user_id);
      if (!groups[uid]) {
        groups[uid] = { user: row, items: [] };
        order.push(uid);
      }
      groups[uid].items.push(row);
    });
    recentWrap.innerHTML = order.map(function (uid) {
      var g = groups[uid];
      var rowsHtml = g.items.map(function (row) {
        var pct = Math.round(Number(row.percent) || 0);
        return '<tr>' +
          '<td><div class="font-semibold text-slate-900">' + esc(row.video_title) + '</div>' +
            '<div class="text-xs text-slate-400">' + esc(row.subject_name || '') + (row.lesson_title ? (' / ' + esc(row.lesson_title)) : '') + '</div></td>' +
          '<td class="whitespace-nowrap text-slate-600">' + esc(fmt(row.position_sec)) +
            (row.duration_sec ? (' / ' + esc(fmt(row.duration_sec))) : '') + '</td>' +
          '<td class="whitespace-nowrap text-slate-600">' + esc(fmt(row.watch_seconds)) + '</td>' +
          '<td class="text-slate-600">' + pct + '%</td>' +
          '<td class="whitespace-nowrap text-xs text-slate-400">' + esc(row.updated_label) + '</td>' +
          '</tr>';
      }).join('');
      return '<details class="overflow-hidden rounded-xl border border-slate-200/80 bg-white/90 open:shadow-sm page-table" open>' +
        '<summary class="flex cursor-pointer list-none flex-wrap items-center justify-between gap-2 px-4 py-3">' +
          '<span><span class="font-bold tracking-tight text-slate-900">' + esc(g.user.full_name) + '</span>' +
          '<span class="ml-2 text-xs font-medium text-slate-400">' + esc(g.user.email) + '</span></span>' +
          '<span class="flex items-center gap-2">' +
            '<span class="text-xs font-medium text-slate-500">' + g.items.length + ' video(s)</span>' +
            '<a class="admin-btn admin-btn--secondary admin-btn--sm" href="admin_student_view?id=' + g.user.user_id + '#student-activity" onclick="event.stopPropagation()">Open folder</a>' +
          '</span></summary>' +
        '<div class="overflow-x-auto border-t border-slate-100">' +
          '<table class="admin-table w-full text-sm"><thead><tr><th>Video / folder</th><th>Stopped at</th><th>Watched</th><th>%</th><th>Updated</th></tr></thead>' +
          '<tbody>' + rowsHtml + '</tbody></table></div></details>';
    }).join('');
  }

  var inflight = false;
  function tick() {
    if (inflight) return;
    inflight = true;
    var within = withinEl.value || '180';
    fetch(apiBase + '?within=' + encodeURIComponent(within) + '&_=' + Date.now(), {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
      cache: 'no-store'
    }).then(function (r) { return r.json(); }).then(function (data) {
      if (!data || !data.ok) throw new Error('bad response');
      renderLive(data.live || []);
      renderRecent(data.recent_watches || []);
      statusEl.textContent = (data.live || []).length + ' active · ' +
        (data.recent_watches || []).length + ' recent watches · live poll 3s · ' +
        new Date().toLocaleTimeString();
    }).catch(function () {
      statusEl.textContent = 'Poll failed — retrying…';
    }).finally(function () { inflight = false; });
  }

  withinEl.addEventListener('change', function () {
    var u = new URL(window.location.href);
    u.searchParams.set('within', withinEl.value);
    history.replaceState({}, '', u.toString());
    tick();
  });

  tick();
  setInterval(tick, 3000);
})();
</script>
</body>
</html>
