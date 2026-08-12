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
$adminHeroTitle = 'Live Activity';
$adminHeroSubtitle = 'Near real-time LMS monitoring — video position, watch time, and recent progress history. College exams are separate.';
$adminHeroActions = '<a class="admin-btn admin-btn--secondary" href="admin_quiz_monitor"><i class="bi bi-bar-chart-line"></i> Quiz Monitor</a>';
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

  <form method="get" class="mb-4 flex flex-wrap gap-3 items-end" id="live-filter-form">
    <div>
      <label class="block text-xs font-semibold uppercase opacity-70 mb-1" for="within">Active within</label>
      <select id="within" name="within" class="input-custom">
        <option value="120" <?php echo $within === 120 ? 'selected' : ''; ?>>2 minutes</option>
        <option value="180" <?php echo $within === 180 ? 'selected' : ''; ?>>3 minutes</option>
        <option value="300" <?php echo $within === 300 ? 'selected' : ''; ?>>5 minutes</option>
        <option value="600" <?php echo $within === 600 ? 'selected' : ''; ?>>10 minutes</option>
      </select>
    </div>
    <div class="text-sm opacity-70 pb-2" id="live-status">Loading… · polling every 3s</div>
  </form>

  <div class="rounded-xl border overflow-hidden page-table mb-6">
    <div class="px-4 py-3 border-b font-semibold flex items-center gap-2">
      <span class="inline-block w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
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
          <tr><td colspan="7" class="text-center py-10 opacity-60">Loading live sessions…</td></tr>
        </tbody>
      </table>
    </div>
  </div>

  <div class="rounded-xl border overflow-hidden page-table">
    <div class="px-4 py-3 border-b font-semibold">Recent video watches by student (last 72 hours)</div>
    <div id="recent-by-student" class="p-3 space-y-3">
      <div class="text-center py-8 opacity-60 text-sm">Loading watch history…</div>
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
      liveBody.innerHTML = '<tr><td colspan="7" class="text-center py-10 opacity-60">No students active in this window.</td></tr>';
      return;
    }
    liveBody.innerHTML = rows.map(function (row) {
      var videoHtml = '<span class="opacity-50">-</span>';
      if (row.video_id) {
        var pct = Number(row.video_percent) || 0;
        var bar = row.video_duration_sec
          ? '<div class="mt-2 h-1.5 rounded-full bg-gray-200 overflow-hidden max-w-[180px]"><div class="h-full bg-[#1665A0]" style="width:' + Math.min(100, Math.max(0, pct)) + '%"></div></div>'
          : '';
        var progressBlock = row.has_progress
          ? '<div class="mt-1"><span class="acl-pill">' + (row.video_is_playing ? 'Playing' : 'Paused / idle') + '</span></div>' +
            '<div class="text-sm mt-1">At <strong>' + esc(fmt(row.video_position_sec)) + '</strong>' +
            (row.video_duration_sec ? (' / ' + esc(fmt(row.video_duration_sec)) + ' <span class="opacity-70">(' + Math.round(pct) + '%)</span>') : '') +
            '</div><div class="text-xs opacity-70 mt-0.5">Watched ' + esc(fmt(row.video_watch_seconds)) + ' total</div>' + bar
          : '<div class="text-xs opacity-60 mt-1">On video page — waiting for playback ping</div>';
        videoHtml = '<div class="font-semibold">' + esc(row.video_title || ('Video #' + row.video_id)) + '</div>' + progressBlock;
      }
      return '<tr>' +
        '<td><div class="font-semibold">' + esc(row.full_name) + '</div><div class="text-xs opacity-60">' + esc(row.email) + '</div></td>' +
        '<td><div class="font-semibold">' + esc(whereLabel(row)) + '</div>' +
          (row.page_url ? '<div class="text-xs opacity-50 truncate max-w-xs">' + esc(row.page_url) + '</div>' : '') + '</td>' +
        '<td>' + videoHtml + '</td>' +
        '<td>' + esc(row.subject_name || '-') +
          (row.lesson_title ? '<div class="text-xs opacity-60">' + esc(row.lesson_title) + '</div>' : '') + '</td>' +
        '<td class="whitespace-nowrap">' + esc(fmt(row.session_seconds)) + '</td>' +
        '<td class="whitespace-nowrap">' + esc(row.last_seen_label) + '</td>' +
        '<td><a class="admin-btn admin-btn--secondary admin-btn--sm" href="admin_student_view?id=' + row.user_id + '#student-activity">Open</a></td>' +
        '</tr>';
    }).join('');
  }

  function renderRecent(rows) {
    if (!rows.length) {
      recentWrap.innerHTML = '<div class="text-center py-8 opacity-60 text-sm">No saved video watches yet. Progress appears when students play lesson videos.</div>';
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
          '<td><div class="font-semibold">' + esc(row.video_title) + '</div>' +
            '<div class="text-xs opacity-60">' + esc(row.subject_name || '') + (row.lesson_title ? (' / ' + esc(row.lesson_title)) : '') + '</div></td>' +
          '<td class="whitespace-nowrap">' + esc(fmt(row.position_sec)) +
            (row.duration_sec ? (' / ' + esc(fmt(row.duration_sec))) : '') + '</td>' +
          '<td class="whitespace-nowrap">' + esc(fmt(row.watch_seconds)) + '</td>' +
          '<td>' + pct + '%</td>' +
          '<td class="whitespace-nowrap text-xs">' + esc(row.updated_label) + '</td>' +
          '</tr>';
      }).join('');
      return '<details class="rounded-xl border page-table open:shadow-sm" open>' +
        '<summary class="cursor-pointer px-4 py-3 flex flex-wrap items-center justify-between gap-2 list-none">' +
          '<span><span class="font-semibold">' + esc(g.user.full_name) + '</span>' +
          '<span class="text-xs opacity-60 ml-2">' + esc(g.user.email) + '</span></span>' +
          '<span class="flex items-center gap-2">' +
            '<span class="text-xs opacity-70">' + g.items.length + ' video(s)</span>' +
            '<a class="admin-btn admin-btn--secondary admin-btn--sm" href="admin_student_view?id=' + g.user.user_id + '#student-activity" onclick="event.stopPropagation()">Open folder</a>' +
          '</span></summary>' +
        '<div class="overflow-x-auto border-t">' +
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
