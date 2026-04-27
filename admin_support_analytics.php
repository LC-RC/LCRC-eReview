<?php
require_once 'auth.php';
requireRole('admin');
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
    return 'admin_support_analytics.php?' . http_build_query($q);
};

// ——— Overview metrics (tab: overview) ———
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

// ——— KB backlog (tab: backlog) ———
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

// ——— Knowledge base (tab: kb) ———
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

// ——— Lookup (tab: lookup) ———
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php require_once __DIR__ . '/includes/head_admin.php'; ?>
  <style>
    .admin-support-hub-tabs-wrap { border-bottom: 1px solid #e5e7eb; margin-top: 0.75rem; }
    .admin-support-hub-tabs { display: flex; flex-wrap: wrap; gap: 0.25rem; align-items: flex-end; }
    .admin-support-hub-tab {
      display: inline-flex; align-items: center; gap: 0.35rem;
      padding: 0.65rem 1rem; font-size: 0.875rem; font-weight: 600;
      color: #64748b; text-decoration: none; border-radius: 0.5rem 0.5rem 0 0;
      border: 1px solid transparent; border-bottom: none;
      transition: color .15s ease, background .15s ease, border-color .15s ease;
    }
    .admin-support-hub-tab:hover { color: #012970; background: rgba(1,41,112,.06); }
    .admin-support-hub-tab.is-active {
      color: #012970; background: #fff; border-color: #e5e7eb;
      box-shadow: 0 1px 0 #fff; position: relative; z-index: 1; margin-bottom: -1px;
    }
    .admin-support-hub-tab .bi { font-size: 1rem; opacity: .85; }
  </style>
</head>
<body class="font-sans antialiased admin-app admin-support-hub-page">
  <?php include __DIR__ . '/admin_sidebar.php'; ?>

  <div class="bg-white rounded-xl shadow-card px-5 pt-5 pb-0 mb-5 border border-gray-100">
    <?php
    $adminBreadcrumbs = [['Dashboard', 'admin_dashboard.php'], ['Support Analytics']];
    include __DIR__ . '/includes/admin_breadcrumb.php';
    ?>
    <h1 class="text-2xl font-bold text-[#012970] m-0 flex items-center gap-2">
      <i class="bi bi-robot"></i> Support Analytics
    </h1>
    <p class="text-gray-500 mt-1 mb-0">Use the tabs below for overview metrics, KB backlog, the knowledge base editor, and staff enrollment lookup.</p>

    <div class="admin-support-hub-tabs-wrap">
      <nav class="admin-support-hub-tabs" aria-label="Support sections">
        <a href="<?php echo h($tabQs('overview')); ?>" class="admin-support-hub-tab <?php echo $tab === 'overview' ? 'is-active' : ''; ?>">
          <i class="bi bi-graph-up-arrow"></i> Analytics
        </a>
        <a href="<?php echo h($tabQs('backlog')); ?>" class="admin-support-hub-tab <?php echo $tab === 'backlog' ? 'is-active' : ''; ?>">
          <i class="bi bi-inboxes"></i> KB backlog
        </a>
        <a href="<?php echo h($tabQs('kb')); ?>" class="admin-support-hub-tab <?php echo $tab === 'kb' ? 'is-active' : ''; ?>">
          <i class="bi bi-journal-text"></i> Knowledge base
        </a>
        <a href="<?php echo h($tabQs('lookup')); ?>" class="admin-support-hub-tab <?php echo $tab === 'lookup' ? 'is-active' : ''; ?>">
          <i class="bi bi-search"></i> Enrollment lookup
        </a>
      </nav>
    </div>
  </div>

  <?php if (!empty($_GET['err']) && $_GET['err'] === 'csrf'): ?>
    <div class="mb-4 p-3 rounded-lg bg-red-50 border border-red-200 text-red-900 text-sm">Invalid security token. Please try again.</div>
  <?php endif; ?>

  <?php if ($tab === 'overview'): ?>

  <div class="bg-white rounded-xl shadow-card border border-gray-100 p-5 mb-5">
    <form method="GET" class="flex flex-wrap items-center gap-3">
      <input type="hidden" name="tab" value="overview">
      <label for="days" class="text-sm font-semibold text-gray-700">Date range</label>
      <select id="days" name="days" class="input-custom" style="max-width: 180px;">
        <?php foreach ([7, 14, 30, 60] as $d): ?>
          <option value="<?php echo (int) $d; ?>" <?php echo $days === $d ? 'selected' : ''; ?>>Last <?php echo (int) $d; ?> days</option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="px-4 py-2 rounded-lg border-2 border-primary text-primary hover:bg-primary hover:text-white transition font-semibold">Apply</button>
    </form>
  </div>
  <?php if (!$tablesReady): ?>
    <div class="mb-5 p-4 rounded-xl bg-amber-50 border border-amber-200 text-amber-900">
      Support analytics tables are not available yet. Run <code class="text-sm">migrations/015_support_chat_rag_and_ticketing.sql</code> to enable full chatbot analytics and ticketing.
    </div>
  <?php endif; ?>

  <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4 mb-5">
    <div class="bg-white border border-gray-100 rounded-xl shadow-card p-4">
      <p class="text-gray-500 text-sm m-0">Chat sessions</p>
      <p class="text-3xl font-bold text-[#012970] mt-1"><?php echo (int) $sessions; ?></p>
    </div>
    <div class="bg-white border border-gray-100 rounded-xl shadow-card p-4">
      <p class="text-gray-500 text-sm m-0">Messages</p>
      <p class="text-3xl font-bold text-[#012970] mt-1"><?php echo (int) $messages; ?></p>
    </div>
    <div class="bg-white border border-gray-100 rounded-xl shadow-card p-4">
      <p class="text-gray-500 text-sm m-0">Handoff rate (handoffs ÷ sessions)</p>
      <p class="text-3xl font-bold text-[#012970] mt-1"><?php echo h((string) $handoffRate); ?></p>
    </div>
    <div class="bg-white border border-gray-100 rounded-xl shadow-card p-4">
      <p class="text-gray-500 text-sm m-0">Unanswered detections</p>
      <p class="text-3xl font-bold text-[#012970] mt-1"><?php echo (int) $unanswered; ?></p>
    </div>
  </div>

  <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4 mb-5">
    <div class="bg-white border border-gray-100 rounded-xl shadow-card p-4">
      <p class="text-gray-500 text-sm m-0">CSAT average (1–5)</p>
      <p class="text-3xl font-bold text-[#012970] mt-1"><?php echo $csatAvg !== null ? h((string) $csatAvg) : '—'; ?></p>
      <p class="text-xs text-gray-500 m-0 mt-1"><?php echo (int) $csatN; ?> responses</p>
    </div>
    <div class="bg-white border border-gray-100 rounded-xl shadow-card p-4">
      <p class="text-gray-500 text-sm m-0">Panel opens / closes</p>
      <p class="text-3xl font-bold text-[#012970] mt-1"><?php echo (int) $panelOpens; ?> / <?php echo (int) $panelCloses; ?></p>
      <p class="text-xs text-gray-500 m-0 mt-1">Logged from the homepage widget</p>
    </div>
    <div class="bg-white border border-gray-100 rounded-xl shadow-card p-4">
      <p class="text-gray-500 text-sm m-0">Drop-off estimate</p>
      <p class="text-3xl font-bold text-[#012970] mt-1"><?php echo $dropoffRate !== null ? h((string) $dropoffRate) : '—'; ?></p>
      <p class="text-xs text-gray-500 m-0 mt-1">(opens − closes) ÷ opens</p>
    </div>
    <div class="bg-white border border-gray-100 rounded-xl shadow-card p-4">
      <p class="text-gray-500 text-sm m-0">KB backlog (pending)</p>
      <p class="text-3xl font-bold text-[#012970] mt-1"><?php echo (int) $backlogPending; ?></p>
      <p class="text-xs m-0 mt-1"><a href="<?php echo h($tabQs('backlog')); ?>" class="text-primary font-semibold underline">Open backlog tab</a></p>
    </div>
  </div>

  <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-5">
    <div class="bg-white rounded-xl shadow-card border border-gray-100 p-4">
      <h2 class="m-0 text-lg font-semibold text-gray-800 mb-3">Top assistant intents</h2>
      <?php if (!$topIntents): ?>
        <p class="text-gray-500 text-sm m-0">No data for this range.</p>
      <?php else: ?>
        <ul class="m-0 pl-5 text-sm space-y-1">
          <?php foreach ($topIntents as $ti): ?>
            <li><strong><?php echo h((string) ($ti['intent'] ?? '')); ?></strong> — <?php echo (int) ($ti['c'] ?? 0); ?></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
    <div class="bg-white rounded-xl shadow-card border border-gray-100 p-4">
      <h2 class="m-0 text-lg font-semibold text-gray-800 mb-3">Top repeated user messages</h2>
      <?php if (!$topUserMsgs): ?>
        <p class="text-gray-500 text-sm m-0">No data for this range.</p>
      <?php else: ?>
        <ul class="m-0 pl-5 text-sm space-y-2">
          <?php foreach ($topUserMsgs as $tu): ?>
            <li class="text-gray-700"><?php echo h(mb_substr((string) ($tu['message_text'] ?? ''), 0, 160)); ?><?php echo mb_strlen((string) ($tu['message_text'] ?? '')) > 160 ? '…' : ''; ?> <span class="text-gray-500">(×<?php echo (int) ($tu['c'] ?? 0); ?>)</span></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  </div>

  <div class="bg-white rounded-xl shadow-card border border-gray-100 overflow-hidden mb-5">
    <div class="px-5 py-4 border-b border-gray-100">
      <h2 class="m-0 text-lg font-semibold text-gray-800">Latest support tickets</h2>
    </div>
    <div class="overflow-x-auto p-4">
      <table class="w-full text-left">
        <thead>
          <tr class="text-sm text-gray-500 border-b">
            <th class="py-2">Ticket</th>
            <th class="py-2">Requester</th>
            <th class="py-2">Subject</th>
            <th class="py-2">Status</th>
            <th class="py-2">Created</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$ticketRows): ?>
            <tr><td colspan="5" class="py-4 text-gray-500">No tickets yet.</td></tr>
          <?php else: ?>
            <?php foreach ($ticketRows as $t): ?>
              <tr class="border-b border-gray-100">
                <td class="py-2 font-semibold">#<?php echo (int) $t['ticket_id']; ?></td>
                <td class="py-2"><?php echo h(($t['requester_name'] ?: 'Unknown') . ' · ' . ($t['requester_email'] ?: '-')); ?></td>
                <td class="py-2"><?php echo h($t['subject'] ?? ''); ?></td>
                <td class="py-2"><?php echo h(ucfirst((string) ($t['status'] ?? 'open'))); ?></td>
                <td class="py-2"><?php echo h((string) ($t['created_at'] ?? '')); ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="bg-white rounded-xl shadow-card border border-gray-100 overflow-hidden">
    <div class="px-5 py-4 border-b border-gray-100">
      <h2 class="m-0 text-lg font-semibold text-gray-800">Unanswered question report</h2>
    </div>
    <div class="p-4 space-y-3">
      <?php if (!$unansweredRows): ?>
        <p class="text-gray-500 m-0">No unresolved bot responses for this range.</p>
      <?php else: ?>
        <?php foreach ($unansweredRows as $u): ?>
          <div class="rounded-xl border border-amber-200 bg-amber-50 p-3">
            <p class="m-0 text-sm text-gray-700"><?php echo h($u['message_text'] ?? ''); ?></p>
            <p class="m-0 mt-1 text-xs text-gray-500">
              Session: <?php echo h($u['session_id'] ?? ''); ?> · Intent: <?php echo h($u['intent'] ?? 'unknown'); ?> · Confidence: <?php echo h((string) $u['confidence_score']); ?> · <?php echo h($u['created_at'] ?? ''); ?>
            </p>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <?php elseif ($tab === 'backlog'): ?>

    <p class="text-sm text-gray-600 mb-4 m-0">Low-confidence or unanswered chat samples—use this to grow the knowledge base weekly.</p>
    <?php if (!$backlogReady): ?>
      <div class="mb-5 p-4 rounded-xl bg-amber-50 border border-amber-200 text-amber-900">
        Run <code class="text-sm">migrations/017_support_chat_ux_analytics.sql</code> to enable the backlog table.
      </div>
    <?php else: ?>
      <?php if (!empty($_GET['saved'])): ?>
        <div class="mb-4 p-3 rounded-lg bg-green-50 border border-green-200 text-green-900 text-sm">Saved.</div>
      <?php endif; ?>

      <div class="bg-white rounded-xl shadow-card border border-gray-100 overflow-hidden">
        <div class="overflow-x-auto p-4">
          <table class="w-full text-left text-sm">
            <thead>
              <tr class="text-gray-500 border-b">
                <th class="py-2 pr-2">Question sample</th>
                <th class="py-2 pr-2">Intent</th>
                <th class="py-2 pr-2">Conf.</th>
                <th class="py-2 pr-2">Status</th>
                <th class="py-2 pr-2">Session</th>
                <th class="py-2">Action</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$backlogRows): ?>
                <tr><td colspan="6" class="py-6 text-gray-500">No backlog items yet.</td></tr>
              <?php else: ?>
                <?php foreach ($backlogRows as $row): ?>
                  <tr class="border-b border-gray-100 align-top">
                    <td class="py-3 pr-2 max-w-md"><?php echo h($row['sample_question'] ?? ''); ?></td>
                    <td class="py-3 pr-2 whitespace-nowrap"><?php echo h($row['intent'] ?? ''); ?></td>
                    <td class="py-3 pr-2"><?php echo h((string) ($row['confidence'] ?? '')); ?></td>
                    <td class="py-3 pr-2"><?php echo h($row['status'] ?? ''); ?></td>
                    <td class="py-3 pr-2 font-mono text-xs"><?php echo h(substr((string) ($row['session_id'] ?? ''), 0, 12)); ?>…</td>
                    <td class="py-3">
                      <form method="post" action="admin_support_analytics.php" class="flex flex-col gap-2 items-start">
                        <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
                        <input type="hidden" name="hub_tab" value="backlog">
                        <input type="hidden" name="backlog_id" value="<?php echo (int) $row['backlog_id']; ?>">
                        <select name="status" class="input-custom text-sm" style="max-width: 160px;">
                          <?php foreach (['pending', 'reviewed', 'added_to_kb', 'dismissed'] as $s): ?>
                            <option value="<?php echo h($s); ?>" <?php echo ($row['status'] ?? '') === $s ? 'selected' : ''; ?>><?php echo h($s); ?></option>
                          <?php endforeach; ?>
                        </select>
                        <input type="text" name="notes" value="<?php echo h($row['notes'] ?? ''); ?>" placeholder="Notes" class="input-custom text-sm w-full max-w-xs" maxlength="500">
                        <button type="submit" class="px-3 py-1 rounded-lg border-2 border-primary text-primary text-sm font-semibold hover:bg-primary hover:text-white">Save</button>
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

    <p class="text-sm text-gray-600 mb-4 m-0">Content used for chatbot grounding (RAG). Edit without deploying code.</p>

    <?php if (!$v2): ?>
      <div class="mb-5 p-4 rounded-xl bg-amber-50 border border-amber-200 text-amber-900">
        Run migration <code class="text-sm">migrations/016_support_chat_ai_v2.sql</code> to enable KB fields, session memory, and settings.
      </div>
    <?php endif; ?>

    <?php if ($flashOk): ?>
      <div class="mb-5 p-4 rounded-xl bg-green-50 border border-green-200 text-green-800"><?php echo h($flashOk); ?></div>
    <?php endif; ?>
    <?php if ($flashErr): ?>
      <div class="mb-5 p-4 rounded-xl bg-red-50 border border-red-200 text-red-800"><?php echo h($flashErr); ?></div>
    <?php endif; ?>

    <?php if ($v2): ?>
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-5 mb-8">
      <div class="bg-white rounded-xl shadow-card border border-gray-100 p-5">
        <h2 class="text-lg font-semibold text-gray-800 m-0 mb-3">Global banned topics</h2>
        <p class="text-sm text-gray-500 m-0 mb-3">If a user message contains these phrases (one per line), the bot will refuse and direct to staff.</p>
        <form method="post" action="admin_support_analytics.php">
          <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
          <input type="hidden" name="hub_tab" value="kb">
          <input type="hidden" name="action" value="save_settings">
          <textarea name="global_banned_topics" rows="6" class="w-full border rounded-xl p-3 text-sm font-mono"><?php echo h($globalBanned); ?></textarea>
          <button type="submit" class="mt-3 px-4 py-2 rounded-lg bg-primary text-white font-semibold">Save global settings</button>
        </form>
      </div>
      <div class="bg-white rounded-xl shadow-card border border-gray-100 p-5">
        <h2 class="text-lg font-semibold text-gray-800 m-0 mb-2">Articles</h2>
        <p class="text-sm text-gray-500 m-0 mb-3">
          <a href="<?php echo h($tabQs('kb') . '&edit=0'); ?>" class="text-primary font-semibold">+ New article</a>
        </p>
        <ul class="space-y-2 max-h-80 overflow-y-auto">
          <?php foreach ($articles as $a): ?>
            <li class="flex justify-between items-center gap-2 text-sm border-b border-gray-100 pb-2">
              <span class="truncate"><?php echo h($a['title'] ?? ''); ?></span>
              <a href="<?php echo h($tabQs('kb') . '&edit=' . (int) $a['article_id']); ?>" class="shrink-0 text-primary font-medium">Edit</a>
            </li>
          <?php endforeach; ?>
          <?php if (!$articles): ?>
            <li class="text-gray-500">No articles yet.</li>
          <?php endif; ?>
        </ul>
      </div>
    </div>

    <div class="bg-white rounded-xl shadow-card border border-gray-100 p-5 mb-8">
      <h2 class="text-lg font-semibold text-gray-800 m-0 mb-4"><?php echo $editRow ? 'Edit article' : 'New article'; ?></h2>
      <form method="post" action="admin_support_analytics.php" class="space-y-4">
        <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
        <input type="hidden" name="hub_tab" value="kb">
        <input type="hidden" name="action" value="save_article">
        <input type="hidden" name="article_id" value="<?php echo (int) ($editRow['article_id'] ?? 0); ?>">

        <div>
          <label class="block text-sm font-semibold text-gray-700 mb-1">Title</label>
          <input type="text" name="title" required class="w-full border rounded-xl px-3 py-2" value="<?php echo h($editRow['title'] ?? ''); ?>">
        </div>
        <div>
          <label class="block text-sm font-semibold text-gray-700 mb-1">Main content (facts for RAG)</label>
          <textarea name="content" rows="8" required class="w-full border rounded-xl px-3 py-2 text-sm"><?php echo h($editRow['content'] ?? ''); ?></textarea>
        </div>
        <div>
          <label class="block text-sm font-semibold text-gray-700 mb-1">Short answer (optional summary for the model)</label>
          <textarea name="short_answer" rows="3" class="w-full border rounded-xl px-3 py-2 text-sm"><?php echo h($editRow['short_answer'] ?? ''); ?></textarea>
        </div>
        <div>
          <label class="block text-sm font-semibold text-gray-700 mb-1">Keywords (comma-separated)</label>
          <input type="text" name="keywords" class="w-full border rounded-xl px-3 py-2 text-sm" value="<?php echo h($editRow['keywords'] ?? ''); ?>">
        </div>
        <div>
          <label class="block text-sm font-semibold text-gray-700 mb-1">Approved phrases (one per line; model may quote verbatim)</label>
          <textarea name="approved_phrases" rows="4" class="w-full border rounded-xl px-3 py-2 text-sm font-mono"><?php echo h($editRow['approved_phrases'] ?? ''); ?></textarea>
        </div>
        <div>
          <label class="block text-sm font-semibold text-gray-700 mb-1">Article-level banned subtopics (comma-separated)</label>
          <input type="text" name="article_banned_topics" class="w-full border rounded-xl px-3 py-2 text-sm" placeholder="e.g. refund guarantee, job placement" value="<?php echo h($editRow['article_banned_topics'] ?? ''); ?>">
        </div>
        <div class="flex flex-wrap gap-4 items-center">
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
        <button type="submit" class="px-5 py-2.5 rounded-xl bg-primary text-white font-semibold">Save article</button>
      </form>

      <?php if ($editId > 0 && $versions): ?>
        <div class="mt-8 pt-6 border-t border-gray-200">
          <h3 class="text-md font-semibold text-gray-800 m-0 mb-2">Recent versions (history)</h3>
          <ul class="text-sm text-gray-600 space-y-1">
            <?php foreach ($versions as $v): ?>
              <li>#<?php echo (int) $v['version_id']; ?> — <?php echo h($v['created_at'] ?? ''); ?> — <?php echo h($v['title'] ?? ''); ?></li>
            <?php endforeach; ?>
          </ul>
          <p class="text-xs text-gray-400 mt-2">Full snapshots are stored for compliance; restore from DB if needed.</p>
        </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

  <?php elseif ($tab === 'lookup'): ?>

    <p class="text-sm text-gray-600 mb-4 m-0">Look up a student by email (admin only). Searches are logged with a hash of the email, not the raw address.</p>

    <?php if ($lookupError !== ''): ?>
      <div class="mb-4 p-3 rounded-lg bg-red-50 border border-red-200 text-red-900 text-sm"><?php echo h($lookupError); ?></div>
    <?php endif; ?>

    <div class="bg-white rounded-xl shadow-card border border-gray-100 p-5 mb-5 max-w-xl">
      <form method="post" action="admin_support_analytics.php" class="space-y-3">
        <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
        <input type="hidden" name="hub_tab" value="lookup">
        <label for="lookup-email" class="block text-sm font-semibold text-gray-700">Email</label>
        <input type="email" id="lookup-email" name="email" required class="input-custom w-full" placeholder="student@example.com" value="<?php echo h($lookupEmail); ?>">
        <button type="submit" class="px-4 py-2 rounded-lg border-2 border-primary text-primary font-semibold hover:bg-primary hover:text-white">Lookup</button>
      </form>
    </div>

    <?php if ($lookupError === '' && $lookupEmail !== '' && $lookupResult === null): ?>
      <div class="p-4 rounded-xl bg-gray-50 border border-gray-200 text-gray-700">No user found with that email.</div>
    <?php elseif ($lookupResult): ?>
      <div class="bg-white rounded-xl shadow-card border border-gray-100 p-5 max-w-2xl">
        <h2 class="text-lg font-semibold text-gray-800 m-0 mb-3">Match</h2>
        <dl class="grid grid-cols-1 sm:grid-cols-2 gap-2 text-sm">
          <dt class="text-gray-500">User ID</dt><dd class="font-mono"><?php echo (int) $lookupResult['user_id']; ?></dd>
          <dt class="text-gray-500">Name</dt><dd><?php echo h($lookupResult['full_name'] ?? ''); ?></dd>
          <dt class="text-gray-500">Email</dt><dd><?php echo h($lookupResult['email'] ?? ''); ?></dd>
          <dt class="text-gray-500">Role</dt><dd><?php echo h($lookupResult['role'] ?? ''); ?></dd>
          <dt class="text-gray-500">Status</dt><dd><?php echo h($lookupResult['status'] ?? ''); ?></dd>
          <dt class="text-gray-500">Access</dt><dd><?php echo h(trim((string) ($lookupResult['access_start'] ?? '') . ' → ' . (string) ($lookupResult['access_end'] ?? ''))); ?></dd>
          <dt class="text-gray-500">Months</dt><dd><?php echo h((string) ($lookupResult['access_months'] ?? '')); ?></dd>
          <dt class="text-gray-500">Registered</dt><dd><?php echo h((string) ($lookupResult['created_at'] ?? '')); ?></dd>
        </dl>
        <p class="text-xs text-gray-500 mt-4 m-0">Helpdesk / CRM links can be wired in <code>includes/chat_integrations.php</code> when you use an external tool.</p>
      </div>
    <?php endif; ?>

  <?php endif; ?>

</div>
</main>
</body>
</html>
