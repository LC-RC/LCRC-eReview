<?php
require_once 'auth.php';
requireAdminPage();
require_once __DIR__ . '/includes/chat_support_helpers.php';

$pageTitle = 'Support Analytics';
$csrf = generateCSRFToken();

$tab = isset($_GET['tab']) ? (string) $_GET['tab'] : 'overview';
if (!in_array($tab, ['overview', 'backlog', 'kb', 'lookup'], true)) {
    $tab = 'overview';
}

require_once __DIR__ . '/includes/admin_support_hub_post.php';

$days = isset($_GET['days']) ? (int) $_GET['days'] : 7;
if ($days < 1) {
    $days = 1;
}
if ($days > 60) {
    $days = 60;
}

$tabQs = static function (string $t) use ($days): string {
    $q = ['tab' => $t];
    if ($t === 'overview') {
        $q['days'] = $days;
    }
    return 'admin_support_analytics?' . http_build_query($q);
};

// --- Overview metrics (tab: overview) ---
$tablesReady = ereview_chat_tables_ready($conn);
$sinceExpr = "DATE_SUB(NOW(), INTERVAL {$days} DAY)";

$sessions = 0;
$messages = 0;
$handoffs = 0;
$unanswered = 0;
$ticketRows = [];
$unansweredRows = [];
$csatAvg = null;
$csatN = 0;
$panelOpens = 0;
$panelCloses = 0;
$dropoffRate = null;
$handoffRate = 0.0;
$topIntents = [];
$topUserMsgs = [];
$backlogPending = 0;

if ($tablesReady && $tab === 'overview') {
    $q1 = @mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM support_chat_sessions WHERE created_at >= $sinceExpr");
    if ($q1 && ($r = mysqli_fetch_assoc($q1))) {
        $sessions = (int) $r['cnt'];
    }
    $q2 = @mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM support_chat_messages WHERE created_at >= $sinceExpr");
    if ($q2 && ($r = mysqli_fetch_assoc($q2))) {
        $messages = (int) $r['cnt'];
    }
    $q3 = @mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM support_analytics_events WHERE event_type='handoff_requested' AND created_at >= $sinceExpr");
    if ($q3 && ($r = mysqli_fetch_assoc($q3))) {
        $handoffs = (int) $r['cnt'];
    }
    $q4 = @mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM support_analytics_events WHERE event_type='unanswered_detected' AND created_at >= $sinceExpr");
    if ($q4 && ($r = mysqli_fetch_assoc($q4))) {
        $unanswered = (int) $r['cnt'];
    }

    $ticketRes = @mysqli_query($conn, "
      SELECT ticket_id, requester_name, requester_email, subject, status, priority, created_at
      FROM support_tickets
      ORDER BY created_at DESC
      LIMIT 20
    ");
    if ($ticketRes) {
        while ($row = mysqli_fetch_assoc($ticketRes)) {
            $ticketRows[] = $row;
        }
    }

    $unRes = @mysqli_query($conn, "
      SELECT session_id, message_text, intent, confidence_score, created_at
      FROM support_chat_messages
      WHERE role='assistant' AND needs_human = 1 AND created_at >= $sinceExpr
      ORDER BY created_at DESC
      LIMIT 30
    ");
    if ($unRes) {
        while ($row = mysqli_fetch_assoc($unRes)) {
            $unansweredRows[] = $row;
        }
    }

    $handoffRate = $sessions > 0 ? round($handoffs / $sessions, 4) : 0.0;

    $cs = @mysqli_query(
        $conn,
        "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'support_chat_csat' LIMIT 1"
    );
    if ($cs && mysqli_fetch_row($cs)) {
        mysqli_free_result($cs);
        $cq = @mysqli_query($conn, "SELECT AVG(rating) AS a, COUNT(*) AS n FROM support_chat_csat WHERE created_at >= $sinceExpr");
        if ($cq && ($cr = mysqli_fetch_assoc($cq))) {
            $csatAvg = isset($cr['a']) && $cr['a'] !== null ? round((float) $cr['a'], 2) : null;
            $csatN = (int) ($cr['n'] ?? 0);
        }
    } elseif ($cs) {
        mysqli_free_result($cs);
    }

    $po = @mysqli_query($conn, "SELECT COUNT(*) AS c FROM support_analytics_events WHERE event_type='chat_panel_open' AND created_at >= $sinceExpr");
    if ($po && ($pr = mysqli_fetch_assoc($po))) {
        $panelOpens = (int) ($pr['c'] ?? 0);
    }
    $pc = @mysqli_query($conn, "SELECT COUNT(*) AS c FROM support_analytics_events WHERE event_type='chat_panel_close' AND created_at >= $sinceExpr");
    if ($pc && ($pr = mysqli_fetch_assoc($pc))) {
        $panelCloses = (int) ($pr['c'] ?? 0);
    }
    if ($panelOpens > 0) {
        $dropoffRate = round(max(0, $panelOpens - $panelCloses) / $panelOpens, 4);
    }

    $iq = @mysqli_query(
        $conn,
        "SELECT intent, COUNT(*) AS c FROM support_chat_messages WHERE role='assistant' AND created_at >= $sinceExpr GROUP BY intent ORDER BY c DESC LIMIT 12"
    );
    if ($iq) {
        while ($row = mysqli_fetch_assoc($iq)) {
            $topIntents[] = $row;
        }
    }

    $uq = @mysqli_query(
        $conn,
        "SELECT message_text, COUNT(*) AS c FROM support_chat_messages WHERE role='user' AND created_at >= $sinceExpr GROUP BY message_text ORDER BY c DESC LIMIT 15"
    );
    if ($uq) {
        while ($row = mysqli_fetch_assoc($uq)) {
            $topUserMsgs[] = $row;
        }
    }

    $bk = @mysqli_query(
        $conn,
        "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'support_kb_backlog' LIMIT 1"
    );
    if ($bk && mysqli_fetch_row($bk)) {
        mysqli_free_result($bk);
        $bp = @mysqli_query($conn, "SELECT COUNT(*) AS c FROM support_kb_backlog WHERE status='pending'");
        if ($bp && ($br = mysqli_fetch_assoc($bp))) {
            $backlogPending = (int) ($br['c'] ?? 0);
        }
    } elseif ($bk) {
        mysqli_free_result($bk);
    }
}

// --- KB backlog (tab: backlog) ---
$backlogReady = false;
$backlogRows = [];
if ($tab === 'backlog') {
    $r = @mysqli_query(
        $conn,
        "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'support_kb_backlog' LIMIT 1"
    );
    if ($r && mysqli_fetch_row($r)) {
        $backlogReady = true;
    }
    if ($r) {
        mysqli_free_result($r);
    }
    if ($backlogReady) {
        $res = @mysqli_query(
            $conn,
            'SELECT backlog_id, session_id, sample_question, intent, confidence, status, notes, created_at FROM support_kb_backlog ORDER BY created_at DESC LIMIT 200'
        );
        if ($res) {
            while ($row = mysqli_fetch_assoc($res)) {
                $backlogRows[] = $row;
            }
        }
    }
}

// --- Knowledge base (tab: kb) ---
$v2 = ereview_chat_v2_ready($conn);
$articles = [];
$globalBanned = '';
$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$editRow = null;
$versions = [];
$flashOk = null;
$flashErr = null;

if ($tab === 'kb') {
    $flashOk = $_SESSION['support_hub_kb_flash_ok'] ?? null;
    $flashErr = $_SESSION['support_hub_kb_flash_err'] ?? null;
    unset($_SESSION['support_hub_kb_flash_ok'], $_SESSION['support_hub_kb_flash_err']);

    if ($v2) {
        $res = @mysqli_query($conn, "SELECT article_id, title, content, short_answer, keywords, approved_phrases, article_banned_topics, status, last_reviewed_at FROM support_kb_articles ORDER BY article_id DESC");
        if ($res) {
            while ($r = mysqli_fetch_assoc($res)) {
                $articles[] = $r;
            }
            mysqli_free_result($res);
        }
        $globalBanned = ereview_chat_get_setting($conn, 'global_banned_topics', '');
    }
    if ($editId > 0 && $v2) {
        $stmt = mysqli_prepare($conn, 'SELECT * FROM support_kb_articles WHERE article_id = ? LIMIT 1');
        mysqli_stmt_bind_param($stmt, 'i', $editId);
        mysqli_stmt_execute($stmt);
        $er = mysqli_stmt_get_result($stmt);
        $editRow = $er ? mysqli_fetch_assoc($er) : null;
        mysqli_stmt_close($stmt);
    }
    if ($editId > 0 && $v2) {
        $stmt = mysqli_prepare(
            $conn,
            'SELECT version_id, title, created_at, edited_by_user_id FROM support_kb_article_versions WHERE article_id = ? ORDER BY version_id DESC LIMIT 15'
        );
        mysqli_stmt_bind_param($stmt, 'i', $editId);
        mysqli_stmt_execute($stmt);
        $vr = mysqli_stmt_get_result($stmt);
        if ($vr) {
            while ($row = mysqli_fetch_assoc($vr)) {
                $versions[] = $row;
            }
            mysqli_free_result($vr);
        }
        mysqli_stmt_close($stmt);
    }
}

// --- Lookup (tab: lookup) ---
$lookupResult = null;
$lookupError = '';
$lookupEmail = '';
if ($tab === 'lookup' && isset($_SESSION['support_lookup_state'])) {
    $ls = $_SESSION['support_lookup_state'];
    $lookupResult = $ls['result'] ?? null;
    $lookupError = (string) ($ls['error'] ?? '');
    $lookupEmail = (string) ($ls['email'] ?? '');
    unset($_SESSION['support_lookup_state']);
}
$adminBreadcrumbs = [['Dashboard', 'admin_dashboard'], ['Support Analytics']];
$adminHeroIcon = 'headset';
$adminHeroEyebrow = 'Support';
$adminHeroTitle = 'Support Analytics';
$adminHeroSubtitle = 'Insight console — sessions, unanswered signals, repeated questions, and support activity.';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php require_once __DIR__ . '/includes/head_admin.php'; ?>
</head>
<body class="font-sans antialiased admin-app admin-support-hub-page">
  <?php include __DIR__ . '/admin_sidebar.php'; ?>

  <?php include __DIR__ . '/includes/components/admin_page_hero.php'; ?>

  <div class="admin-support-hub-tabs-wrap mb-4">
      <nav class="admin-support-hub-tabs inline-flex flex-wrap gap-1 rounded-2xl border border-white/80 bg-white/75 p-1.5 shadow-[0_10px_30px_rgba(15,23,42,.07),0_2px_10px_rgba(37,99,235,.05)] backdrop-blur-xl" aria-label="Support sections">
        <a href="<?php echo h($tabQs('overview')); ?>" class="admin-support-hub-tab inline-flex items-center gap-1.5 rounded-xl px-3 py-2 text-sm font-semibold text-slate-600 transition hover:bg-slate-100 hover:text-slate-900 <?php echo $tab === 'overview' ? 'is-active bg-gradient-to-r from-blue-600 to-indigo-600 text-white shadow-[0_6px_16px_rgba(37,99,235,0.25)] hover:text-white' : ''; ?>">
          <i class="bi bi-graph-up-arrow"></i> Analytics
        </a>
        <a href="<?php echo h($tabQs('backlog')); ?>" class="admin-support-hub-tab inline-flex items-center gap-1.5 rounded-xl px-3 py-2 text-sm font-semibold text-slate-600 transition hover:bg-slate-100 hover:text-slate-900 <?php echo $tab === 'backlog' ? 'is-active bg-gradient-to-r from-blue-600 to-indigo-600 text-white shadow-[0_6px_16px_rgba(37,99,235,0.25)] hover:text-white' : ''; ?>">
          <i class="bi bi-inboxes"></i> KB backlog
        </a>
        <a href="<?php echo h($tabQs('kb')); ?>" class="admin-support-hub-tab inline-flex items-center gap-1.5 rounded-xl px-3 py-2 text-sm font-semibold text-slate-600 transition hover:bg-slate-100 hover:text-slate-900 <?php echo $tab === 'kb' ? 'is-active bg-gradient-to-r from-blue-600 to-indigo-600 text-white shadow-[0_6px_16px_rgba(37,99,235,0.25)] hover:text-white' : ''; ?>">
          <i class="bi bi-journal-text"></i> Knowledge base
        </a>
        <a href="<?php echo h($tabQs('lookup')); ?>" class="admin-support-hub-tab inline-flex items-center gap-1.5 rounded-xl px-3 py-2 text-sm font-semibold text-slate-600 transition hover:bg-slate-100 hover:text-slate-900 <?php echo $tab === 'lookup' ? 'is-active bg-gradient-to-r from-blue-600 to-indigo-600 text-white shadow-[0_6px_16px_rgba(37,99,235,0.25)] hover:text-white' : ''; ?>">
          <i class="bi bi-search"></i> Enrollment lookup
        </a>
      </nav>
    </div>

  <?php if (!empty($_GET['err']) && $_GET['err'] === 'csrf'): ?>
    <div class="mb-4 rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-900">Invalid security token. Please try again.</div>
  <?php endif; ?>

  <?php if ($tab === 'overview'): ?>

  <div class="mb-4 rounded-2xl border border-white/80 bg-white/75 p-3.5 shadow-[0_10px_30px_rgba(15,23,42,0.07),0_2px_10px_rgba(37,99,235,0.05)] backdrop-blur-xl ring-1 ring-white/50 page-filter">
    <form method="GET" class="flex flex-wrap items-center gap-3">
      <input type="hidden" name="tab" value="overview">
      <label for="days" class="text-xs font-bold uppercase tracking-wide text-slate-500">Date range</label>
      <select id="days" name="days" class="input-custom" style="max-width: 180px;">
        <?php foreach ([7, 14, 30, 60] as $d): ?>
          <option value="<?php echo (int) $d; ?>" <?php echo $days === $d ? 'selected' : ''; ?>>Last <?php echo (int) $d; ?> days</option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="admin-btn admin-btn--secondary admin-btn--sm inline-flex h-10 items-center rounded-xl border border-white/90 bg-white/80 px-4 text-sm font-semibold text-slate-700 shadow-sm backdrop-blur-lg hover:bg-white">Apply</button>
    </form>
  </div>
  <?php if (!$tablesReady): ?>
    <div class="mb-4 rounded-2xl border border-amber-200/70 bg-amber-50/80 p-4 text-amber-900">
      Support analytics tables are not available yet. Run <code class="text-sm">migrations/015_support_chat_rag_and_ticketing.sql</code> to enable full chatbot analytics and ticketing.
    </div>
  <?php endif; ?>

  <div class="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
    <div class="admin-stat-card relative overflow-hidden rounded-2xl border border-blue-100/90 bg-gradient-to-br from-blue-50/70 via-white/85 to-white/75 p-4 shadow-[0_12px_32px_rgba(15,23,42,.08),0_3px_14px_rgba(37,99,235,.06)] backdrop-blur-xl ring-1 ring-white/70">
      <div class="pointer-events-none absolute -right-6 -top-6 h-20 w-20 rounded-full bg-blue-400/20 blur-2xl" aria-hidden="true"></div>
      <div class="relative z-[1] flex items-start gap-3">
        <span class="lms-icon-tile bg-gradient-to-br from-blue-100 to-blue-50 text-blue-600 shadow-sm ring-1 ring-white"><i class="bi bi-chat-dots"></i></span>
        <div class="min-w-0">
          <p class="admin-stat-card__label m-0 text-[11px] font-bold uppercase tracking-wide text-slate-500">Chat sessions</p>
          <p class="admin-stat-card__value m-0 mt-0.5 text-xl font-bold tracking-tight text-slate-900"><?php echo (int) $sessions; ?></p>
        </div>
      </div>
    </div>
    <div class="admin-stat-card relative overflow-hidden rounded-2xl border border-sky-100/90 bg-gradient-to-br from-sky-50/70 via-white/85 to-white/75 p-4 shadow-[0_12px_32px_rgba(15,23,42,.08),0_3px_14px_rgba(14,165,233,.06)] backdrop-blur-xl ring-1 ring-white/70">
      <div class="pointer-events-none absolute -right-6 -top-6 h-20 w-20 rounded-full bg-sky-400/20 blur-2xl" aria-hidden="true"></div>
      <div class="relative z-[1] flex items-start gap-3">
        <span class="lms-icon-tile bg-gradient-to-br from-sky-100 to-sky-50 text-sky-600 shadow-sm ring-1 ring-white"><i class="bi bi-chat-left-text"></i></span>
        <div class="min-w-0">
          <p class="admin-stat-card__label m-0 text-[11px] font-bold uppercase tracking-wide text-slate-500">Messages</p>
          <p class="admin-stat-card__value m-0 mt-0.5 text-xl font-bold tracking-tight text-slate-900"><?php echo (int) $messages; ?></p>
        </div>
      </div>
    </div>
    <div class="admin-stat-card relative overflow-hidden rounded-2xl border border-amber-100/90 bg-gradient-to-br from-amber-50/70 via-white/85 to-white/75 p-4 shadow-[0_12px_32px_rgba(15,23,42,.08),0_3px_14px_rgba(245,158,11,.06)] backdrop-blur-xl ring-1 ring-white/70">
      <div class="pointer-events-none absolute -right-6 -top-6 h-20 w-20 rounded-full bg-amber-400/20 blur-2xl" aria-hidden="true"></div>
      <div class="relative z-[1] flex items-start gap-3">
        <span class="lms-icon-tile bg-gradient-to-br from-amber-100 to-amber-50 text-amber-600 shadow-sm ring-1 ring-white"><i class="bi bi-question-octagon"></i></span>
        <div class="min-w-0">
          <p class="admin-stat-card__label m-0 text-[11px] font-bold uppercase tracking-wide text-slate-500">Unanswered</p>
          <p class="admin-stat-card__value m-0 mt-0.5 text-xl font-bold tracking-tight text-slate-900"><?php echo (int) $unanswered; ?></p>
        </div>
      </div>
    </div>
    <div class="admin-stat-card relative overflow-hidden rounded-2xl border border-orange-100/90 bg-gradient-to-br from-amber-50/70 via-white/85 to-white/75 p-4 shadow-[0_12px_32px_rgba(15,23,42,.08),0_3px_14px_rgba(249,115,22,.06)] backdrop-blur-xl ring-1 ring-white/70">
      <div class="pointer-events-none absolute -right-6 -top-6 h-20 w-20 rounded-full bg-orange-400/20 blur-2xl" aria-hidden="true"></div>
      <div class="relative z-[1] flex items-start gap-3">
        <span class="lms-icon-tile bg-gradient-to-br from-orange-100 to-orange-50 text-orange-600 shadow-sm ring-1 ring-white"><i class="bi bi-inboxes"></i></span>
        <div class="min-w-0">
          <p class="admin-stat-card__label m-0 text-[11px] font-bold uppercase tracking-wide text-slate-500">KB backlog</p>
          <p class="admin-stat-card__value m-0 mt-0.5 text-xl font-bold tracking-tight text-slate-900"><?php echo (int) $backlogPending; ?></p>
          <p class="admin-stat-card__meta m-0 mt-0.5 text-xs text-slate-600"><a href="<?php echo h($tabQs('backlog')); ?>" class="font-semibold text-blue-600 underline">Open backlog</a></p>
        </div>
      </div>
    </div>
  </div>

  <div class="mb-4 grid grid-cols-2 gap-2 lg:grid-cols-4">
    <div class="flex items-center gap-2.5 rounded-xl border border-white/80 bg-white/70 px-3 py-2.5 shadow-sm backdrop-blur-lg ring-1 ring-white/60">
      <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-violet-50 text-violet-600"><i class="bi bi-person-raised-hand"></i></span>
      <div class="min-w-0">
        <p class="m-0 text-[10px] font-bold uppercase tracking-wide text-slate-500">Handoff</p>
        <p class="m-0 text-sm font-bold text-slate-900"><?php echo h((string) $handoffRate); ?></p>
      </div>
    </div>
    <div class="flex items-center gap-2.5 rounded-xl border border-white/80 bg-white/70 px-3 py-2.5 shadow-sm backdrop-blur-lg ring-1 ring-white/60">
      <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-emerald-50 text-emerald-600"><i class="bi bi-star"></i></span>
      <div class="min-w-0">
        <p class="m-0 text-[10px] font-bold uppercase tracking-wide text-slate-500">CSAT</p>
        <p class="m-0 text-sm font-bold text-slate-900"><?php echo $csatAvg !== null ? h((string) $csatAvg) : '-'; ?> <span class="text-xs font-medium text-slate-500"><?php echo (int) $csatN; ?></span></p>
      </div>
    </div>
    <div class="flex items-center gap-2.5 rounded-xl border border-white/80 bg-white/70 px-3 py-2.5 shadow-sm backdrop-blur-lg ring-1 ring-white/60">
      <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-indigo-50 text-indigo-600"><i class="bi bi-window-sidebar"></i></span>
      <div class="min-w-0">
        <p class="m-0 text-[10px] font-bold uppercase tracking-wide text-slate-500">Panel</p>
        <p class="m-0 text-sm font-bold text-slate-900"><?php echo (int) $panelOpens; ?> / <?php echo (int) $panelCloses; ?></p>
      </div>
    </div>
    <div class="flex items-center gap-2.5 rounded-xl border border-white/80 bg-white/70 px-3 py-2.5 shadow-sm backdrop-blur-lg ring-1 ring-white/60">
      <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-rose-50 text-rose-600"><i class="bi bi-graph-down-arrow"></i></span>
      <div class="min-w-0">
        <p class="m-0 text-[10px] font-bold uppercase tracking-wide text-slate-500">Drop-off</p>
        <p class="m-0 text-sm font-bold text-slate-900"><?php echo $dropoffRate !== null ? h((string) $dropoffRate) : '-'; ?></p>
      </div>
    </div>
  </div>

  <div class="mb-4 grid grid-cols-1 gap-3 lg:grid-cols-2">
    <div class="rounded-2xl border border-white/80 bg-white/75 p-4 shadow-[0_10px_30px_rgba(15,23,42,0.07),0_2px_10px_rgba(139,92,246,0.06)] backdrop-blur-xl ring-1 ring-white/50 page-table">
      <h2 class="mb-3 flex items-center gap-2 text-sm font-bold text-slate-900 m-0">
        <span class="lms-icon-tile bg-violet-50 text-violet-600" style="width:1.85rem;height:1.85rem;border-radius:0.55rem;font-size:0.85rem"><i class="bi bi-bullseye"></i></span>
        Top assistant intents
      </h2>
      <?php if (!$topIntents): ?>
        <div class="support-empty rounded-xl border border-dashed border-violet-200/80 bg-violet-50/40 px-3 py-3 text-center">
          <i class="bi bi-inbox block text-2xl text-violet-300 mb-2"></i>
          <p class="m-0 text-sm font-medium text-slate-600">No intent data for this range</p>
          <p class="m-0 mt-1 text-xs text-slate-500">Insights appear once chatbot traffic is recorded.</p>
        </div>
      <?php else: ?>
        <ul class="m-0 list-none space-y-1.5 p-0">
          <?php foreach ($topIntents as $ti): ?>
            <li class="flex items-center justify-between gap-3 rounded-xl border border-slate-200/70 bg-slate-50/80 px-3 py-2.5 shadow-sm">
              <span class="min-w-0 truncate text-sm font-medium text-slate-800"><?php echo h((string) ($ti['intent'] ?? '')); ?></span>
              <span class="shrink-0 inline-flex items-center rounded-full bg-violet-50 px-2.5 py-0.5 text-xs font-bold tabular-nums text-violet-700"><?php echo (int) ($ti['c'] ?? 0); ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
    <div class="rounded-2xl border border-white/80 bg-white/75 p-4 shadow-[0_10px_30px_rgba(15,23,42,0.07),0_2px_10px_rgba(14,165,233,0.06)] backdrop-blur-xl ring-1 ring-white/50 page-table">
      <h2 class="mb-3 flex items-center gap-2 text-sm font-bold text-slate-900 m-0">
        <span class="lms-icon-tile bg-sky-50 text-sky-600" style="width:1.85rem;height:1.85rem;border-radius:0.55rem;font-size:0.85rem"><i class="bi bi-chat-quote"></i></span>
        Top repeated user messages
      </h2>
      <?php if (!$topUserMsgs): ?>
        <div class="support-empty rounded-xl border border-dashed border-sky-200/80 bg-sky-50/40 px-3 py-3 text-center">
          <i class="bi bi-chat-square-text block text-2xl text-sky-300 mb-2"></i>
          <p class="m-0 text-sm font-medium text-slate-600">No repeated messages for this range</p>
          <p class="m-0 mt-1 text-xs text-slate-500">Unresolved / repeated questions will surface here.</p>
        </div>
      <?php else: ?>
        <ul class="m-0 list-none space-y-1.5 p-0">
          <?php foreach ($topUserMsgs as $tu): ?>
            <li class="flex items-start justify-between gap-3 rounded-xl border border-slate-200/70 bg-slate-50/80 px-3 py-2.5 shadow-sm">
              <span class="min-w-0 text-sm leading-snug text-slate-700"><?php echo h(mb_substr((string) ($tu['message_text'] ?? ''), 0, 160)); ?><?php echo mb_strlen((string) ($tu['message_text'] ?? '')) > 160 ? '...' : ''; ?></span>
              <span class="shrink-0 inline-flex items-center rounded-full bg-sky-50 px-2.5 py-0.5 text-xs font-bold tabular-nums text-sky-700">x<?php echo (int) ($tu['c'] ?? 0); ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  </div>

  <div class="mb-4 overflow-hidden rounded-2xl border border-white/80 bg-white/75 shadow-[0_10px_30px_rgba(15,23,42,0.07),0_2px_10px_rgba(37,99,235,0.05)] backdrop-blur-lg page-table admin-data-surface">
    <div class="border-b border-slate-100/80 px-4 py-3">
      <h2 class="m-0 flex items-center gap-2 text-sm font-bold text-slate-900">
        <span class="lms-icon-tile bg-indigo-50 text-indigo-600" style="width:1.85rem;height:1.85rem;border-radius:0.55rem;font-size:0.85rem"><i class="bi bi-ticket-detailed"></i></span>
        Latest support tickets
      </h2>
    </div>
    <div class="overflow-x-auto p-3">
      <table class="admin-table w-full text-left text-sm">
        <thead>
          <tr>
            <th>Ticket</th>
            <th>Requester</th>
            <th>Subject</th>
            <th>Status</th>
            <th>Created</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$ticketRows): ?>
            <tr>
              <td colspan="5" class="py-8 text-center">
                <div class="inline-flex flex-col items-center gap-1.5 text-slate-500">
                  <span class="lms-icon-tile bg-slate-100 text-slate-500" style="width:2rem;height:2rem;font-size:0.9rem"><i class="bi bi-inbox"></i></span>
                  <span class="text-sm">No tickets yet.</span>
                </div>
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($ticketRows as $t): ?>
              <?php
                $ticketStatus = strtolower((string) ($t['status'] ?? 'open'));
                $ticketBadge = 'bg-slate-100 text-slate-600';
                if ($ticketStatus === 'open') {
                    $ticketBadge = 'bg-amber-50 text-amber-700';
                } elseif ($ticketStatus === 'closed' || $ticketStatus === 'resolved') {
                    $ticketBadge = 'bg-emerald-50 text-emerald-700';
                }
              ?>
              <tr>
                <td class="font-semibold tabular-nums text-slate-900">#<?php echo (int) $t['ticket_id']; ?></td>
                <td class="text-slate-700"><?php echo h(($t['requester_name'] ?: 'Unknown') . ' · ' . ($t['requester_email'] ?: '-')); ?></td>
                <td class="text-slate-700"><?php echo h($t['subject'] ?? ''); ?></td>
                <td><span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold <?php echo h($ticketBadge); ?>"><?php echo h(ucfirst((string) ($t['status'] ?? 'open'))); ?></span></td>
                <td class="whitespace-nowrap text-slate-500"><?php echo h((string) ($t['created_at'] ?? '')); ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="overflow-hidden rounded-2xl border border-white/80 bg-white/75 shadow-[0_10px_30px_rgba(15,23,42,0.07),0_2px_10px_rgba(37,99,235,0.05)] backdrop-blur-lg page-table">
    <div class="border-b border-slate-100/80 px-4 py-3">
      <h2 class="m-0 flex items-center gap-2 text-sm font-bold text-slate-900">
        <span class="lms-icon-tile bg-amber-50 text-amber-600" style="width:1.85rem;height:1.85rem;border-radius:0.55rem;font-size:0.85rem"><i class="bi bi-question-circle"></i></span>
        Unanswered question report
      </h2>
    </div>
    <div class="space-y-2 p-3">
      <?php if (!$unansweredRows): ?>
        <div class="support-empty rounded-xl border border-dashed border-slate-200 bg-amber-50/40 px-3 py-3 text-center">
          <span class="mx-auto mb-2 inline-flex h-10 w-10 items-center justify-center rounded-xl bg-amber-50 text-amber-600 shadow-sm ring-1 ring-white"><i class="bi bi-check2-circle"></i></span>
          <p class="m-0 text-sm font-semibold text-slate-700">No unresolved bot responses</p>
          <p class="m-0 mt-1 text-xs text-slate-500">Unanswered questions for this range will appear here.</p>
        </div>
      <?php else: ?>
        <?php foreach ($unansweredRows as $u): ?>
          <div class="rounded-xl border border-amber-200/70 bg-amber-50/60 p-3">
            <p class="m-0 text-sm text-slate-700"><?php echo h($u['message_text'] ?? ''); ?></p>
            <p class="m-0 mt-1 text-xs text-slate-500">
              Session: <?php echo h($u['session_id'] ?? ''); ?> · Intent: <?php echo h($u['intent'] ?? 'unknown'); ?> · Confidence: <?php echo h((string) $u['confidence_score']); ?> · <?php echo h($u['created_at'] ?? ''); ?>
            </p>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <?php elseif ($tab === 'backlog'): ?>

    <p class="mb-3 text-sm text-slate-600 m-0">Low-confidence or unanswered chat samples — use this to grow the knowledge base weekly.</p>
    <?php if (!$backlogReady): ?>
      <div class="mb-4 rounded-2xl border border-amber-200/70 bg-amber-50/80 p-4 text-amber-900">
        Run <code class="text-sm">migrations/017_support_chat_ux_analytics.sql</code> to enable the backlog table.
      </div>
    <?php else: ?>
      <?php if (!empty($_GET['saved'])): ?>
        <div class="mb-3 rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-900">Saved.</div>
      <?php endif; ?>

      <div class="overflow-hidden rounded-2xl border border-white/80 bg-white/75 shadow-[0_10px_30px_rgba(15,23,42,0.07),0_2px_10px_rgba(37,99,235,0.05)] backdrop-blur-lg page-table">
        <div class="overflow-x-auto p-3">
          <table class="admin-table w-full text-left text-sm">
            <thead>
              <tr>
                <th>Question sample</th>
                <th>Intent</th>
                <th>Conf.</th>
                <th>Status</th>
                <th>Session</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$backlogRows): ?>
                <tr>
                  <td colspan="6" class="py-8 text-center">
                    <div class="inline-flex flex-col items-center gap-1.5 text-slate-500">
                      <span class="lms-icon-tile bg-slate-100 text-slate-500" style="width:2rem;height:2rem;font-size:0.9rem"><i class="bi bi-inbox"></i></span>
                      <span class="text-sm">No backlog items yet.</span>
                    </div>
                  </td>
                </tr>
              <?php else: ?>
                <?php foreach ($backlogRows as $row): ?>
                  <tr class="align-top">
                    <td class="max-w-md text-slate-700"><?php echo h($row['sample_question'] ?? ''); ?></td>
                    <td class="whitespace-nowrap text-slate-600"><?php echo h($row['intent'] ?? ''); ?></td>
                    <td class="tabular-nums text-slate-600"><?php echo h((string) ($row['confidence'] ?? '')); ?></td>
                    <td><span class="inline-flex rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-semibold text-slate-600"><?php echo h($row['status'] ?? ''); ?></span></td>
                    <td class="font-mono text-xs text-slate-500"><?php echo h(substr((string) ($row['session_id'] ?? ''), 0, 12)); ?>...</td>
                    <td>
                      <form method="post" action="admin_support_analytics" class="flex flex-col items-start gap-2">
                        <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
                        <input type="hidden" name="hub_tab" value="backlog">
                        <input type="hidden" name="backlog_id" value="<?php echo (int) $row['backlog_id']; ?>">
                        <select name="status" class="input-custom text-sm" style="max-width: 160px;">
                          <?php foreach (['pending', 'reviewed', 'added_to_kb', 'dismissed'] as $s): ?>
                            <option value="<?php echo h($s); ?>" <?php echo ($row['status'] ?? '') === $s ? 'selected' : ''; ?>><?php echo h($s); ?></option>
                          <?php endforeach; ?>
                        </select>
                        <input type="text" name="notes" value="<?php echo h($row['notes'] ?? ''); ?>" placeholder="Notes" class="input-custom w-full max-w-xs text-sm" maxlength="500">
                        <button type="submit" class="admin-btn admin-btn--secondary admin-btn--sm rounded-xl">Save</button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>

  <?php elseif ($tab === 'kb'): ?>

    <p class="mb-3 text-sm text-slate-600 m-0">Content used for chatbot grounding (RAG). Edit without deploying code.</p>

    <?php if (!$v2): ?>
      <div class="mb-4 rounded-2xl border border-amber-200/70 bg-amber-50/80 p-4 text-amber-900">
        Run migration <code class="text-sm">migrations/016_support_chat_ai_v2.sql</code> to enable KB fields, session memory, and settings.
      </div>
    <?php endif; ?>

    <?php if ($flashOk): ?>
      <div class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-emerald-800"><?php echo h($flashOk); ?></div>
    <?php endif; ?>
    <?php if ($flashErr): ?>
      <div class="mb-4 rounded-xl border border-rose-200 bg-rose-50 p-3 text-rose-800"><?php echo h($flashErr); ?></div>
    <?php endif; ?>

    <?php if ($v2): ?>
    <div class="mb-5 grid grid-cols-1 gap-3 lg:grid-cols-2">
      <div class="rounded-2xl border border-slate-200/70 bg-white/85 p-4 shadow-sm backdrop-blur-lg page-table">
        <h2 class="m-0 mb-2 text-sm font-bold text-slate-900">Global banned topics</h2>
        <p class="m-0 mb-3 text-sm text-slate-500">If a user message contains these phrases (one per line), the bot will refuse and direct to staff.</p>
        <form method="post" action="admin_support_analytics">
          <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
          <input type="hidden" name="hub_tab" value="kb">
          <input type="hidden" name="action" value="save_settings">
          <textarea name="global_banned_topics" rows="6" class="w-full rounded-xl border border-slate-200 bg-white/90 p-3 font-mono text-sm"><?php echo h($globalBanned); ?></textarea>
          <button type="submit" class="admin-btn admin-btn--primary mt-3 rounded-xl">Save global settings</button>
        </form>
      </div>
      <div class="rounded-2xl border border-slate-200/70 bg-white/85 p-4 shadow-sm backdrop-blur-lg page-table">
        <h2 class="m-0 mb-2 text-sm font-bold text-slate-900">Articles</h2>
        <p class="m-0 mb-3 text-sm text-slate-500">
          <a href="<?php echo h($tabQs('kb') . '&edit=0'); ?>" class="font-semibold text-blue-600">+ New article</a>
        </p>
        <ul class="max-h-80 space-y-2 overflow-y-auto">
          <?php foreach ($articles as $a): ?>
            <li class="flex items-center justify-between gap-2 border-b border-slate-100 pb-2 text-sm">
              <span class="truncate text-slate-800"><?php echo h($a['title'] ?? ''); ?></span>
              <a href="<?php echo h($tabQs('kb') . '&edit=' . (int) $a['article_id']); ?>" class="shrink-0 font-medium text-blue-600">Edit</a>
            </li>
          <?php endforeach; ?>
          <?php if (!$articles): ?>
            <li class="support-empty rounded-xl border border-dashed border-slate-200 bg-slate-50 px-3 py-3 text-center text-sm text-slate-500">No articles yet.</li>
          <?php endif; ?>
        </ul>
      </div>
    </div>

    <div class="mb-5 rounded-2xl border border-slate-200/70 bg-white/85 p-4 shadow-sm backdrop-blur-lg page-table">
      <h2 class="m-0 mb-4 text-sm font-bold text-slate-900"><?php echo $editRow ? 'Edit article' : 'New article'; ?></h2>
      <form method="post" action="admin_support_analytics" class="space-y-4">
        <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
        <input type="hidden" name="hub_tab" value="kb">
        <input type="hidden" name="action" value="save_article">
        <input type="hidden" name="article_id" value="<?php echo (int) ($editRow['article_id'] ?? 0); ?>">

        <div>
          <label class="mb-1 block text-xs font-bold uppercase tracking-wide text-slate-500">Title</label>
          <input type="text" name="title" required class="w-full rounded-xl border border-slate-200 px-3 py-2" value="<?php echo h($editRow['title'] ?? ''); ?>">
        </div>
        <div>
          <label class="mb-1 block text-xs font-bold uppercase tracking-wide text-slate-500">Main content (facts for RAG)</label>
          <textarea name="content" rows="8" required class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm"><?php echo h($editRow['content'] ?? ''); ?></textarea>
        </div>
        <div>
          <label class="mb-1 block text-xs font-bold uppercase tracking-wide text-slate-500">Short answer (optional summary for the model)</label>
          <textarea name="short_answer" rows="3" class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm"><?php echo h($editRow['short_answer'] ?? ''); ?></textarea>
        </div>
        <div>
          <label class="mb-1 block text-xs font-bold uppercase tracking-wide text-slate-500">Keywords (comma-separated)</label>
          <input type="text" name="keywords" class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm" value="<?php echo h($editRow['keywords'] ?? ''); ?>">
        </div>
        <div>
          <label class="mb-1 block text-xs font-bold uppercase tracking-wide text-slate-500">Approved phrases (one per line; model may quote verbatim)</label>
          <textarea name="approved_phrases" rows="4" class="w-full rounded-xl border border-slate-200 px-3 py-2 font-mono text-sm"><?php echo h($editRow['approved_phrases'] ?? ''); ?></textarea>
        </div>
        <div>
          <label class="mb-1 block text-xs font-bold uppercase tracking-wide text-slate-500">Article-level banned subtopics (comma-separated)</label>
          <input type="text" name="article_banned_topics" class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm" placeholder="e.g. refund guarantee, job placement" value="<?php echo h($editRow['article_banned_topics'] ?? ''); ?>">
        </div>
        <div class="flex flex-wrap items-center gap-4">
          <label class="inline-flex items-center gap-2 text-sm">
            <input type="radio" name="status" value="active" <?php echo (($editRow['status'] ?? 'active') === 'active') ? 'checked' : ''; ?>> Active
          </label>
          <label class="inline-flex items-center gap-2 text-sm">
            <input type="radio" name="status" value="inactive" <?php echo (($editRow['status'] ?? '') === 'inactive') ? 'checked' : ''; ?>> Inactive
          </label>
          <label class="inline-flex items-center gap-2 text-sm">
            <input type="checkbox" name="mark_reviewed" value="1"> Mark reviewed now
          </label>
        </div>
        <button type="submit" class="admin-btn admin-btn--primary rounded-xl">Save article</button>
      </form>

      <?php if ($editId > 0 && $versions): ?>
        <div class="mt-6 border-t border-slate-100 pt-5">
          <h3 class="m-0 mb-2 text-sm font-bold text-slate-900">Recent versions (history)</h3>
          <ul class="space-y-1 text-sm text-slate-600">
            <?php foreach ($versions as $v): ?>
              <li>#<?php echo (int) $v['version_id']; ?> - <?php echo h($v['created_at'] ?? ''); ?> - <?php echo h($v['title'] ?? ''); ?></li>
            <?php endforeach; ?>
          </ul>
          <p class="mt-2 text-xs text-slate-500">Full snapshots are stored for compliance; restore from DB if needed.</p>
        </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

  <?php elseif ($tab === 'lookup'): ?>

    <p class="mb-3 text-sm text-slate-600 m-0">Look up a student by email (admin only). Searches are logged with a hash of the email, not the raw address.</p>

    <?php if ($lookupError !== ''): ?>
      <div class="mb-3 rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-900"><?php echo h($lookupError); ?></div>
    <?php endif; ?>

    <div class="mb-4 max-w-xl rounded-2xl border border-slate-200/70 bg-white/85 p-4 shadow-sm backdrop-blur-lg page-table">
      <form method="post" action="admin_support_analytics" class="space-y-3">
        <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
        <input type="hidden" name="hub_tab" value="lookup">
        <label for="lookup-email" class="block text-xs font-bold uppercase tracking-wide text-slate-500">Email</label>
        <input type="email" id="lookup-email" name="email" required class="input-custom w-full" placeholder="student@example.com" value="<?php echo h($lookupEmail); ?>">
        <button type="submit" class="admin-btn admin-btn--secondary rounded-xl">Lookup</button>
      </form>
    </div>

    <?php if ($lookupError === '' && $lookupEmail !== '' && $lookupResult === null): ?>
      <div class="max-w-xl rounded-xl border border-dashed border-slate-200 bg-slate-50 p-4 text-slate-600">No user found with that email.</div>
    <?php elseif ($lookupResult): ?>
      <div class="max-w-2xl rounded-2xl border border-slate-200/70 bg-white/85 p-4 shadow-sm backdrop-blur-lg page-table">
        <h2 class="m-0 mb-3 text-sm font-bold text-slate-900">Match</h2>
        <dl class="grid grid-cols-1 gap-2 text-sm sm:grid-cols-2">
          <dt class="text-slate-500">User ID</dt><dd class="font-mono"><?php echo (int) $lookupResult['user_id']; ?></dd>
          <dt class="text-slate-500">Name</dt><dd><?php echo h($lookupResult['full_name'] ?? ''); ?></dd>
          <dt class="text-slate-500">Email</dt><dd><?php echo h($lookupResult['email'] ?? ''); ?></dd>
          <dt class="text-slate-500">Role</dt><dd><?php echo h($lookupResult['role'] ?? ''); ?></dd>
          <dt class="text-slate-500">Status</dt><dd><?php echo h($lookupResult['status'] ?? ''); ?></dd>
          <dt class="text-slate-500">Access</dt><dd><?php echo h(trim((string) ($lookupResult['access_start'] ?? '') . ' → ' . (string) ($lookupResult['access_end'] ?? ''))); ?></dd>
          <dt class="text-slate-500">Months</dt><dd><?php echo h((string) ($lookupResult['access_months'] ?? '')); ?></dd>
          <dt class="text-slate-500">Registered</dt><dd><?php echo h((string) ($lookupResult['created_at'] ?? '')); ?></dd>
        </dl>
        <p class="m-0 mt-4 text-xs text-slate-500">Helpdesk / CRM links can be wired in <code>includes/chat_integrations.php</code> when you use an external tool.</p>
      </div>
    <?php endif; ?>

  <?php endif; ?>

</div>
</main>
</body>
</html>
