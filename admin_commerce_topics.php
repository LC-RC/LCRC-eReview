<?php
/**
 * Admin Commerce — By Topic pricing on existing LMS lessons (Phase 3 + Phase 7 bulk).
 * Source of truth: lessons table. No duplicate topic/product catalog.
 */
require_once 'auth.php';
requireRole('admin');
require_once __DIR__ . '/includes/commerce_catalog.php';
require_once __DIR__ . '/includes/url_helpers.php';

if (!commerce_schema_ready($conn)) {
    $_SESSION['error'] = 'Commerce schema is not installed.';
    header('Location: admin_dashboard');
    exit;
}

$csrf = generateCSRFToken();
$pageTitle = 'Commerce — By Topic Pricing';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($token)) {
        $_SESSION['error'] = 'Invalid request.';
        header('Location: admin_commerce_topics');
        exit;
    }

    $action = (string) ($_POST['action'] ?? 'save_one');

    if ($action === 'bulk_update') {
        $rawIds = $_POST['lesson_ids'] ?? [];
        if (!is_array($rawIds)) {
            $rawIds = [];
        }
        $lessonIds = [];
        foreach ($rawIds as $id) {
            $lid = (int) $id;
            if ($lid > 0) {
                $lessonIds[$lid] = $lid;
            }
        }
        $lessonIds = array_values($lessonIds);
        $priceCentavos = commerce_pesos_to_centavos($_POST['price_pesos'] ?? '');
        $durationValue = (int) ($_POST['access_duration_value'] ?? 0);
        $durationUnit = ($_POST['access_duration_unit'] ?? 'day') === 'month' ? 'month' : 'day';

        if ($lessonIds === []) {
            $_SESSION['error'] = 'Select at least one lesson.';
            header('Location: admin_commerce_topics');
            exit;
        }
        if ($priceCentavos <= 0 || $durationValue <= 0) {
            $_SESSION['error'] = 'Bulk update requires a price greater than ₱0 and a positive duration.';
            header('Location: admin_commerce_topics');
            exit;
        }

        $updated = 0;
        $stmt = mysqli_prepare(
            $conn,
            'UPDATE lessons SET price_centavos = ?, access_duration_value = ?, access_duration_unit = ?,
             is_purchasable = 1, purchasable_updated_at = NOW()
             WHERE lesson_id = ? LIMIT 1'
        );
        if (!$stmt) {
            $_SESSION['error'] = 'Could not prepare bulk update.';
            header('Location: admin_commerce_topics');
            exit;
        }
        foreach ($lessonIds as $lid) {
            // Ensure lesson exists (no inserts).
            $chk = mysqli_prepare($conn, 'SELECT lesson_id FROM lessons WHERE lesson_id = ? LIMIT 1');
            if (!$chk) {
                continue;
            }
            mysqli_stmt_bind_param($chk, 'i', $lid);
            mysqli_stmt_execute($chk);
            $cr = mysqli_stmt_get_result($chk);
            $exists = $cr && mysqli_fetch_assoc($cr);
            mysqli_stmt_close($chk);
            if (!$exists) {
                continue;
            }
            mysqli_stmt_bind_param($stmt, 'iisi', $priceCentavos, $durationValue, $durationUnit, $lid);
            if (mysqli_stmt_execute($stmt) && mysqli_stmt_affected_rows($stmt) >= 0) {
                $updated++;
            }
        }
        mysqli_stmt_close($stmt);

        $_SESSION['message'] = 'Bulk updated ' . $updated . ' existing lesson(s). No new topic/product rows created.';
        $retQ = urlencode((string) ($_POST['return_q'] ?? ''));
        $retF = urlencode((string) ($_POST['return_filter'] ?? 'all'));
        $retS = (int) ($_POST['return_subject_id'] ?? 0);
        header('Location: admin_commerce_topics?q=' . $retQ . '&filter=' . $retF . ($retS > 0 ? '&subject_id=' . $retS : ''));
        exit;
    }

    // Single-row save (existing behavior)
    $lessonId = (int) ($_POST['lesson_id'] ?? 0);
    $priceCentavos = commerce_pesos_to_centavos($_POST['price_pesos'] ?? '0');
    $durationValue = (int) ($_POST['access_duration_value'] ?? 0);
    $durationUnit = ($_POST['access_duration_unit'] ?? 'day') === 'month' ? 'month' : 'day';
    $isPurchasable = !empty($_POST['is_purchasable']) ? 1 : 0;

    if ($lessonId <= 0) {
        $_SESSION['error'] = 'Invalid topic.';
        header('Location: admin_commerce_topics');
        exit;
    }

    if ($isPurchasable && ($priceCentavos < 0 || $durationValue <= 0)) {
        $_SESSION['error'] = 'Purchasable topics need a price (≥ 0) and positive duration.';
        header('Location: admin_commerce_topics?edit=' . $lessonId);
        exit;
    }

    if (!$isPurchasable) {
        $stmt = mysqli_prepare(
            $conn,
            'UPDATE lessons SET price_centavos = NULL, access_duration_value = NULL, access_duration_unit = NULL,
             is_purchasable = 0, purchasable_updated_at = NOW() WHERE lesson_id = ? LIMIT 1'
        );
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'i', $lessonId);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
    } else {
        $stmt = mysqli_prepare(
            $conn,
            'UPDATE lessons SET price_centavos = ?, access_duration_value = ?, access_duration_unit = ?,
             is_purchasable = 1, purchasable_updated_at = NOW() WHERE lesson_id = ? LIMIT 1'
        );
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'iisi', $priceCentavos, $durationValue, $durationUnit, $lessonId);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
    }

    $_SESSION['message'] = 'Topic pricing saved.';
    header('Location: admin_commerce_topics?q=' . urlencode((string) ($_POST['return_q'] ?? '')));
    exit;
}

$q = trim((string) ($_GET['q'] ?? ''));
$filter = (string) ($_GET['filter'] ?? 'all'); // all|purchasable|not
$subjectId = (int) ($_GET['subject_id'] ?? 0);
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 40;
$offset = ($page - 1) * $perPage;

$subjects = [];
$sq = mysqli_query($conn, "SELECT subject_id, subject_name FROM subjects WHERE status='active' ORDER BY subject_name");
while ($sq && ($s = mysqli_fetch_assoc($sq))) {
    $subjects[] = $s;
}

$where = ['1=1'];
$types = '';
$params = [];
if ($q !== '') {
    $where[] = '(l.title LIKE ? OR s.subject_name LIKE ?)';
    $like = '%' . $q . '%';
    $types .= 'ss';
    $params[] = $like;
    $params[] = $like;
}
if ($filter === 'purchasable') {
    $where[] = 'l.is_purchasable = 1';
} elseif ($filter === 'not') {
    $where[] = 'l.is_purchasable = 0';
}
if ($subjectId > 0) {
    $where[] = 'l.subject_id = ?';
    $types .= 'i';
    $params[] = $subjectId;
}
$whereSql = implode(' AND ', $where);

$countSql = "SELECT COUNT(*) AS c FROM lessons l INNER JOIN subjects s ON s.subject_id = l.subject_id WHERE $whereSql";
$total = 0;
if ($types !== '') {
    $cst = mysqli_prepare($conn, $countSql);
    mysqli_stmt_bind_param($cst, $types, ...$params);
    mysqli_stmt_execute($cst);
    $cr = mysqli_stmt_get_result($cst);
    $total = (int) (($cr ? mysqli_fetch_assoc($cr)['c'] : 0));
    mysqli_stmt_close($cst);
} else {
    $cr = mysqli_query($conn, $countSql);
    $total = (int) (($cr ? mysqli_fetch_assoc($cr)['c'] : 0));
}

$listSql = "SELECT l.lesson_id, l.title, l.price_centavos, l.access_duration_value, l.access_duration_unit, l.is_purchasable,
                   s.subject_id, s.subject_name
            FROM lessons l
            INNER JOIN subjects s ON s.subject_id = l.subject_id
            WHERE $whereSql
            ORDER BY s.subject_name, l.title
            LIMIT ? OFFSET ?";
$types2 = $types . 'ii';
$params2 = array_merge($params, [$perPage, $offset]);
$stmt = mysqli_prepare($conn, $listSql);
mysqli_stmt_bind_param($stmt, $types2, ...$params2);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$rows = [];
while ($res && $row = mysqli_fetch_assoc($res)) {
    $rows[] = $row;
}
mysqli_stmt_close($stmt);
$totalPages = max(1, (int) ceil($total / $perPage));

$editId = (int) ($_GET['edit'] ?? 0);
$editRow = null;
if ($editId > 0) {
    foreach ($rows as $r) {
        if ((int) $r['lesson_id'] === $editId) {
            $editRow = $r;
            break;
        }
    }
    if (!$editRow) {
        $es = mysqli_prepare(
            $conn,
            'SELECT l.*, s.subject_name FROM lessons l INNER JOIN subjects s ON s.subject_id = l.subject_id WHERE l.lesson_id = ? LIMIT 1'
        );
        mysqli_stmt_bind_param($es, 'i', $editId);
        mysqli_stmt_execute($es);
        $er = mysqli_stmt_get_result($es);
        $editRow = $er ? mysqli_fetch_assoc($er) : null;
        mysqli_stmt_close($es);
    }
}

$adminBreadcrumbs = [['Dashboard', 'admin_dashboard'], ['Commerce'], ['By Topic Pricing']];
$adminHeroIcon = 'list-check';
$adminHeroTitle = 'By Topic Pricing';
$adminHeroSubtitle = 'Configure purchasable / price / duration on existing LMS lessons. New lessons appear here automatically.';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_admin.php'; ?>
</head>
<body class="font-sans antialiased admin-app">
  <?php include 'admin_sidebar.php'; ?>
  <div class="p-4 sm:p-6 lg:p-8 max-w-7xl mx-auto w-full">
    <?php include __DIR__ . '/includes/components/admin_page_hero.php'; ?>

    <?php if (!empty($_SESSION['message'])): ?>
      <div class="admin-alert admin-alert--success mb-4"><?php echo h($_SESSION['message']); unset($_SESSION['message']); ?></div>
    <?php endif; ?>
    <?php if (!empty($_SESSION['error'])): ?>
      <div class="admin-alert admin-alert--error mb-4"><?php echo h($_SESSION['error']); unset($_SESSION['error']); ?></div>
    <?php endif; ?>

    <form method="get" class="quiz-admin-table-shell rounded-2xl p-4 mb-5 flex flex-wrap gap-3 items-end">
      <div class="flex-1 min-w-[180px]">
        <label class="block text-xs font-semibold uppercase opacity-70 mb-1">Search</label>
        <input class="input-custom w-full" type="search" name="q" value="<?php echo h($q); ?>" placeholder="Subject or topic">
      </div>
      <div>
        <label class="block text-xs font-semibold uppercase opacity-70 mb-1">Subject</label>
        <select class="input-custom" name="subject_id">
          <option value="0">All subjects</option>
          <?php foreach ($subjects as $s): ?>
            <option value="<?php echo (int) $s['subject_id']; ?>" <?php echo $subjectId === (int) $s['subject_id'] ? 'selected' : ''; ?>><?php echo h((string) $s['subject_name']); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="block text-xs font-semibold uppercase opacity-70 mb-1">Purchasable</label>
        <select class="input-custom" name="filter">
          <option value="all" <?php echo $filter === 'all' ? 'selected' : ''; ?>>All</option>
          <option value="purchasable" <?php echo $filter === 'purchasable' ? 'selected' : ''; ?>>Purchasable</option>
          <option value="not" <?php echo $filter === 'not' ? 'selected' : ''; ?>>Not purchasable</option>
        </select>
      </div>
      <button class="admin-btn admin-btn--primary px-4 py-2.5 rounded-xl font-semibold" type="submit">Apply</button>
    </form>

    <form method="post" id="bulk-topics-form" class="space-y-4">
      <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
      <input type="hidden" name="action" value="bulk_update">
      <input type="hidden" name="return_q" value="<?php echo h($q); ?>">
      <input type="hidden" name="return_filter" value="<?php echo h($filter); ?>">
      <input type="hidden" name="return_subject_id" value="<?php echo (int) $subjectId; ?>">

      <div class="quiz-admin-table-shell rounded-2xl p-4 flex flex-wrap gap-3 items-end">
        <div>
          <label class="block text-xs font-semibold uppercase opacity-70 mb-1">Bulk price (₱)</label>
          <input class="input-custom w-36" type="number" min="0.01" step="0.01" name="price_pesos" placeholder="200.00">
        </div>
        <div>
          <label class="block text-xs font-semibold uppercase opacity-70 mb-1">Duration</label>
          <input class="input-custom w-24" type="number" min="1" name="access_duration_value" value="30">
        </div>
        <div>
          <label class="block text-xs font-semibold uppercase opacity-70 mb-1">Unit</label>
          <select class="input-custom" name="access_duration_unit">
            <option value="day">Days</option>
            <option value="month" selected>Months</option>
          </select>
        </div>
        <button type="submit" class="admin-btn admin-btn--primary px-4 py-2.5 rounded-xl font-semibold" onclick="return confirm('Apply price/duration to selected existing lessons?');">Apply to Selected</button>
        <p class="text-xs opacity-60 w-full">Updates existing <code>lessons</code> only (sets purchasable). Does not create products or packages.</p>
      </div>

      <div class="grid grid-cols-1 <?php echo $editRow ? 'xl:grid-cols-12 gap-5' : ''; ?>">
        <div class="<?php echo $editRow ? 'xl:col-span-7' : ''; ?>">
          <div class="quiz-admin-table-shell rounded-2xl overflow-hidden">
            <div class="overflow-x-auto">
              <table class="w-full text-sm">
                <thead>
                  <tr class="text-left text-xs uppercase tracking-wide opacity-70">
                    <th class="px-3 py-3"><input type="checkbox" id="select-all-lessons" class="admin-bulk-check" title="Select all topics on this page" aria-label="Select all topics on this page"></th>
                    <th class="px-3 py-3">Topic</th>
                    <th class="px-3 py-3 whitespace-nowrap">Price</th>
                    <th class="px-3 py-3">Duration</th>
                    <th class="px-3 py-3">Buy</th>
                    <th class="px-3 py-3"></th>
                  </tr>
                </thead>
                <tbody>
                  <?php if ($rows === []): ?>
                    <tr><td colspan="6" class="px-4 py-8 text-center opacity-60">No lessons found.</td></tr>
                  <?php else: ?>
                    <?php foreach ($rows as $r): ?>
                      <tr class="border-t border-white/5">
                        <td class="px-3 py-3">
                          <input type="checkbox" class="lesson-check admin-bulk-check" name="lesson_ids[]" value="<?php echo (int) $r['lesson_id']; ?>">
                        </td>
                        <td class="px-3 py-3 min-w-0">
                          <div class="font-semibold truncate"><?php echo h($r['title']); ?></div>
                          <div class="text-xs opacity-60 truncate"><?php echo h($r['subject_name']); ?></div>
                        </td>
                        <td class="px-3 py-3 whitespace-nowrap">
                          <?php echo $r['price_centavos'] !== null ? '₱' . h(commerce_centavos_to_pesos_display((int)$r['price_centavos'])) : '—'; ?>
                        </td>
                        <td class="px-3 py-3 whitespace-nowrap text-xs">
                          <?php
                            if (!empty($r['access_duration_value'])) {
                                echo (int)$r['access_duration_value'] . ' ' . h($r['access_duration_unit'] ?? 'day') . '(s)';
                            } else {
                                echo '—';
                            }
                          ?>
                        </td>
                        <td class="px-3 py-3">
                          <span class="admin-badge <?php echo !empty($r['is_purchasable']) ? 'admin-badge--success' : 'admin-badge--neutral'; ?>">
                            <?php echo !empty($r['is_purchasable']) ? 'Yes' : 'No'; ?>
                          </span>
                        </td>
                        <td class="px-3 py-3 text-right">
                          <a class="font-semibold text-sm hover:underline" href="admin_commerce_topics?edit=<?php echo (int)$r['lesson_id']; ?>&q=<?php echo urlencode($q); ?>&filter=<?php echo urlencode($filter); ?>&subject_id=<?php echo (int)$subjectId; ?>&page=<?php echo (int)$page; ?>">Edit</a>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
            <?php if ($totalPages > 1): ?>
              <div class="px-4 py-3 flex gap-2 text-sm border-t border-white/5">
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                  <a class="px-2 py-1 rounded <?php echo $i === $page ? 'bg-white/10 font-bold' : 'opacity-70'; ?>" href="admin_commerce_topics?page=<?php echo $i; ?>&q=<?php echo urlencode($q); ?>&filter=<?php echo urlencode($filter); ?>&subject_id=<?php echo (int)$subjectId; ?>"><?php echo $i; ?></a>
                <?php endfor; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </form>

    <?php if ($editRow): ?>
      <div class="mt-5 max-w-xl">
        <form method="post" class="quiz-admin-table-shell rounded-2xl p-5 space-y-4">
          <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
          <input type="hidden" name="action" value="save_one">
          <input type="hidden" name="lesson_id" value="<?php echo (int)$editRow['lesson_id']; ?>">
          <input type="hidden" name="return_q" value="<?php echo h($q); ?>">
          <h2 class="font-bold text-lg"><?php echo h($editRow['title']); ?></h2>
          <p class="text-sm opacity-60"><?php echo h($editRow['subject_name']); ?></p>
          <label class="inline-flex items-center gap-2 text-sm font-semibold">
            <input type="checkbox" name="is_purchasable" value="1" <?php echo !empty($editRow['is_purchasable']) ? 'checked' : ''; ?>> Purchasable in By Topic catalog
          </label>
          <div>
            <label class="block text-xs font-semibold uppercase opacity-70 mb-1">Price (₱)</label>
            <input class="input-custom w-full" type="number" min="0" step="0.01" name="price_pesos" value="<?php echo h($editRow['price_centavos'] !== null ? number_format(((int)$editRow['price_centavos']) / 100, 2, '.', '') : '0'); ?>">
          </div>
          <div class="grid grid-cols-2 gap-2">
            <div>
              <label class="block text-xs font-semibold uppercase opacity-70 mb-1">Duration</label>
              <input class="input-custom w-full" type="number" min="1" name="access_duration_value" value="<?php echo (int)($editRow['access_duration_value'] ?: 30); ?>">
            </div>
            <div>
              <label class="block text-xs font-semibold uppercase opacity-70 mb-1">Unit</label>
              <select class="input-custom w-full" name="access_duration_unit">
                <option value="day" <?php echo ($editRow['access_duration_unit'] ?? 'day') === 'day' ? 'selected' : ''; ?>>Days</option>
                <option value="month" <?php echo ($editRow['access_duration_unit'] ?? '') === 'month' ? 'selected' : ''; ?>>Months</option>
              </select>
            </div>
          </div>
          <div class="flex gap-2">
            <button type="submit" class="admin-btn admin-btn--primary px-4 py-2.5 rounded-xl font-semibold">Save</button>
            <a href="admin_commerce_topics?q=<?php echo urlencode($q); ?>&filter=<?php echo urlencode($filter); ?>&subject_id=<?php echo (int)$subjectId; ?>" class="admin-outline-btn px-4 py-2.5 rounded-xl font-semibold">Close</a>
          </div>
        </form>
      </div>
    <?php endif; ?>
  </div>
  <script>
    (function () {
      var all = document.getElementById('select-all-lessons');
      if (!all) return;
      function boxes() { return Array.prototype.slice.call(document.querySelectorAll('.lesson-check')); }
      function sync() {
        var list = boxes();
        var selected = list.filter(function (cb) { return cb.checked; });
        all.disabled = list.length === 0;
        all.checked = list.length > 0 && selected.length === list.length;
        all.indeterminate = selected.length > 0 && selected.length < list.length;
      }
      all.addEventListener('change', function () {
        var on = !!all.checked;
        boxes().forEach(function (cb) { cb.checked = on; });
        all.indeterminate = false;
        sync();
      });
      boxes().forEach(function (cb) { cb.addEventListener('change', sync); });
      sync();
    })();
  </script>
</div>
</main>
</body>
</html>
