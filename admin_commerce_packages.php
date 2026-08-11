<?php
/**
 * Admin Commerce â€” Packages catalog (Phase 3 foundations).
 * DB-driven only; no hardcoded package products or prices.
 */
require_once 'auth.php';
requireAdminPage();
require_once __DIR__ . '/includes/commerce_catalog.php';
require_once __DIR__ . '/includes/student_content_access.php';

if (!commerce_schema_ready($conn)) {
    $_SESSION['error'] = 'Commerce schema is not installed. Run migrations/026_commerce_catalog_gcash_access.sql';
    header('Location: admin_dashboard');
    exit;
}

$csrf = generateCSRFToken();
$pageTitle = 'Commerce â€” Packages';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($token)) {
        $_SESSION['error'] = 'Invalid request. Please try again.';
        header('Location: admin_commerce_packages');
        exit;
    }

    $action = (string) ($_POST['action'] ?? 'save');

    if ($action === 'delete') {
        $pid = (int) ($_POST['package_id'] ?? 0);
        if ($pid > 0) {
            $stmt = mysqli_prepare($conn, 'DELETE FROM sellable_packages WHERE package_id = ? LIMIT 1');
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'i', $pid);
                if (mysqli_stmt_execute($stmt)) {
                    $_SESSION['message'] = 'Package deleted.';
                } else {
                    $_SESSION['error'] = 'Could not delete package (it may be referenced by payment history).';
                }
                mysqli_stmt_close($stmt);
            }
        }
        header('Location: admin_commerce_packages');
        exit;
    }

    $packageId = (int) ($_POST['package_id'] ?? 0);
    $code = trim((string) ($_POST['code'] ?? ''));
    $name = trim((string) ($_POST['name'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));
    $pricePesos = $_POST['price_pesos'] ?? '0';
    $priceCentavos = commerce_pesos_to_centavos($pricePesos);
    $durationValue = (int) ($_POST['duration_value'] ?? 0);
    $durationUnit = ($_POST['duration_unit'] ?? 'month') === 'day' ? 'day' : 'month';
    $accessScope = ($_POST['access_scope'] ?? 'full_lms') === 'mapped' ? 'mapped' : 'full_lms';
    $isActive = !empty($_POST['is_active']) ? 1 : 0;
    $isPurchasable = !empty($_POST['is_purchasable']) ? 1 : 0;
    $sortOrder = (int) ($_POST['sort_order'] ?? 0);

    $code = preg_replace('/[^a-z0-9_\-]+/i', '_', strtolower($code)) ?: '';

    if ($code === '' || $name === '' || $priceCentavos < 0 || $durationValue <= 0) {
        $_SESSION['error'] = 'Name, code, non-negative price, and positive duration are required.';
        header('Location: admin_commerce_packages' . ($packageId ? '?edit=' . $packageId : '?new=1'));
        exit;
    }

    if ($packageId > 0) {
        $stmt = mysqli_prepare(
            $conn,
            'UPDATE sellable_packages SET code=?, name=?, description=?, price_centavos=?, duration_value=?, duration_unit=?,
             access_scope=?, is_active=?, is_purchasable=?, sort_order=? WHERE package_id=? LIMIT 1'
        );
        if (!$stmt) {
            $_SESSION['error'] = 'Could not update package.';
            header('Location: admin_commerce_packages?edit=' . $packageId);
            exit;
        }
        mysqli_stmt_bind_param(
            $stmt,
            'sssiissiiii',
            $code,
            $name,
            $description,
            $priceCentavos,
            $durationValue,
            $durationUnit,
            $accessScope,
            $isActive,
            $isPurchasable,
            $sortOrder,
            $packageId
        );
        $ok = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        if (!$ok) {
            $_SESSION['error'] = 'Update failed (duplicate code?).';
            header('Location: admin_commerce_packages?edit=' . $packageId);
            exit;
        }
    } else {
        $stmt = mysqli_prepare(
            $conn,
            'INSERT INTO sellable_packages
             (code, name, description, price_centavos, currency, duration_value, duration_unit, access_scope, is_active, is_purchasable, sort_order)
             VALUES (?, ?, ?, ?, \'PHP\', ?, ?, ?, ?, ?, ?)'
        );
        if (!$stmt) {
            $_SESSION['error'] = 'Could not create package.';
            header('Location: admin_commerce_packages?new=1');
            exit;
        }
        mysqli_stmt_bind_param(
            $stmt,
            'sssiissiii',
            $code,
            $name,
            $description,
            $priceCentavos,
            $durationValue,
            $durationUnit,
            $accessScope,
            $isActive,
            $isPurchasable,
            $sortOrder
        );
        $ok = mysqli_stmt_execute($stmt);
        $packageId = $ok ? (int) mysqli_insert_id($conn) : 0;
        mysqli_stmt_close($stmt);
        if (!$ok || $packageId <= 0) {
            $_SESSION['error'] = 'Create failed (duplicate code?).';
            header('Location: admin_commerce_packages?new=1');
            exit;
        }
    }

    // Content map: only meaningful for mapped; clear when full_lms
    $contentItems = [];
    if ($accessScope === 'mapped') {
        $subjectIds = $_POST['map_subject'] ?? [];
        $lessonIds = $_POST['map_lesson'] ?? [];
        if (is_array($subjectIds)) {
            foreach ($subjectIds as $sid) {
                $sid = (int) $sid;
                if ($sid > 0) {
                    $contentItems[] = ['content_type' => 'subject', 'content_id' => $sid];
                }
            }
        }
        if (is_array($lessonIds)) {
            foreach ($lessonIds as $lid) {
                $lid = (int) $lid;
                if ($lid > 0) {
                    $contentItems[] = ['content_type' => 'lesson', 'content_id' => $lid];
                }
            }
        }
        if ($contentItems === []) {
            $_SESSION['error'] = 'Mapped packages require at least one subject or topic.';
            header('Location: admin_commerce_packages?edit=' . $packageId);
            exit;
        }
    }
    commerce_save_package_content($conn, $packageId, $contentItems);

    $featKeys = $_POST['feature_key'] ?? [];
    $featLabels = $_POST['feature_label'] ?? [];
    $featDescs = $_POST['feature_description'] ?? [];
    $features = [];
    if (is_array($featKeys) && is_array($featLabels)) {
        foreach ($featKeys as $i => $fk) {
            $features[] = [
                'feature_key' => (string) $fk,
                'feature_label' => (string) ($featLabels[$i] ?? ''),
                'feature_description' => (string) ($featDescs[$i] ?? ''),
                'is_included' => 1,
            ];
        }
    }
    commerce_save_package_features($conn, $packageId, $features);

    $_SESSION['message'] = 'Package saved.';
    header('Location: admin_commerce_packages?edit=' . $packageId);
    exit;
}

$packages = commerce_list_packages($conn, false);
$editId = (int) ($_GET['edit'] ?? 0);
$isNew = isset($_GET['new']);
$edit = $editId > 0 ? commerce_get_package($conn, $editId) : null;
if ($isNew) {
    $edit = null;
    $editId = 0;
}
$editContent = $editId > 0 ? commerce_get_package_content($conn, $editId) : [];
$editFeatures = $editId > 0 ? commerce_get_package_features($conn, $editId) : [];
$mapSubjects = [];
$mapLessons = [];
foreach ($editContent as $c) {
    if ($c['content_type'] === 'subject') {
        $mapSubjects[(int) $c['content_id']] = true;
    }
    if ($c['content_type'] === 'lesson') {
        $mapLessons[(int) $c['content_id']] = true;
    }
}
$picker = commerce_subject_lesson_picker($conn);

$adminBreadcrumbs = [
    ['Dashboard', 'admin_dashboard'],
    ['Commerce'],
    ['Packages'],
];
$adminHeroIcon = 'box-seam';
$adminHeroTitle = 'Packages';
$adminHeroSubtitle = 'Database-driven sellable packages. Full LMS packages do not need content maps; mapped packages do.';
$adminHeroActions = '<a href="admin_commerce_packages?new=1" class="admin-btn admin-btn--primary px-4 py-2.5 rounded-xl font-semibold inline-flex items-center gap-2"><i class="bi bi-plus-lg"></i> New package</a>';
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

    <div class="grid grid-cols-1 xl:grid-cols-12 gap-5">
      <div class="xl:col-span-5">
        <div class="quiz-admin-table-shell rounded-2xl overflow-hidden">
          <div class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead>
                <tr class="text-left text-xs uppercase tracking-wide opacity-70">
                  <th class="px-4 py-3">Package</th>
                  <th class="px-3 py-3 whitespace-nowrap">Price</th>
                  <th class="px-3 py-3">Flags</th>
                  <th class="px-3 py-3"></th>
                </tr>
              </thead>
              <tbody>
                <?php if ($packages === []): ?>
                  <tr><td colspan="4" class="px-4 py-8 text-center opacity-60">No packages yet. Create one â€” nothing is hardcoded.</td></tr>
                <?php else: ?>
                  <?php foreach ($packages as $p): ?>
                    <tr class="border-t border-white/5 <?php echo ((int)$p['package_id'] === $editId) ? 'bg-white/5' : ''; ?>">
                      <td class="px-4 py-3 min-w-0">
                        <div class="font-semibold truncate"><?php echo h($p['name']); ?></div>
                        <div class="text-xs opacity-60 truncate"><?php echo h($p['code']); ?> Â· <?php echo h($p['access_scope']); ?> Â· <?php echo (int)$p['duration_value']; ?> <?php echo h($p['duration_unit']); ?>(s)</div>
                      </td>
                      <td class="px-3 py-3 whitespace-nowrap font-semibold">â‚±<?php echo h(commerce_centavos_to_pesos_display((int)$p['price_centavos'])); ?></td>
                      <td class="px-3 py-3">
                        <div class="flex flex-wrap gap-1">
                          <span class="admin-badge <?php echo !empty($p['is_active']) ? 'admin-badge--success' : 'admin-badge--neutral'; ?>"><?php echo !empty($p['is_active']) ? 'Active' : 'Off'; ?></span>
                          <span class="admin-badge <?php echo !empty($p['is_purchasable']) ? 'admin-badge--info' : 'admin-badge--neutral'; ?>"><?php echo !empty($p['is_purchasable']) ? 'Buy' : 'Hidden'; ?></span>
                        </div>
                      </td>
                      <td class="px-3 py-3 text-right whitespace-nowrap">
                        <a class="text-sm font-semibold underline-offset-2 hover:underline" href="admin_commerce_packages?edit=<?php echo (int)$p['package_id']; ?>">Edit</a>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <div class="xl:col-span-7">
        <?php if ($isNew || $edit): ?>
          <?php
            $form = $edit ?: [
              'package_id' => 0,
              'code' => '',
              'name' => '',
              'description' => '',
              'price_centavos' => 0,
              'duration_value' => 6,
              'duration_unit' => 'month',
              'access_scope' => 'full_lms',
              'is_active' => 1,
              'is_purchasable' => 1,
              'sort_order' => 0,
            ];
            $scope = $form['access_scope'] ?? 'full_lms';
          ?>
          <form method="post" class="quiz-admin-table-shell rounded-2xl p-5 sm:p-6 space-y-5" x-data="{ scope: '<?php echo h($scope); ?>', features: <?php
            $fj = [];
            foreach ($editFeatures as $f) {
                $fj[] = [
                    'key' => $f['feature_key'],
                    'label' => $f['feature_label'],
                    'description' => $f['feature_description'] ?? '',
                ];
            }
            echo htmlspecialchars(json_encode($fj ?: [['key' => '', 'label' => '', 'description' => '']], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
          ?> }">
            <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="package_id" value="<?php echo (int)($form['package_id'] ?? 0); ?>">

            <div>
              <h2 class="text-lg font-bold"><?php echo $edit ? 'Edit package' : 'New package'; ?></h2>
              <p class="text-sm opacity-60 mt-1">Self-Paced / Pure Online / Hybrid typically use <strong>Full LMS</strong> + optional features (Live Zoom, Onsite). Use <strong>Mapped</strong> only for custom content bundles.</p>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div>
                <label class="block text-xs font-semibold uppercase tracking-wide opacity-70 mb-1">Name</label>
                <input class="input-custom w-full" name="name" required value="<?php echo h($form['name']); ?>" placeholder="e.g. Self-Paced 6 Months">
              </div>
              <div>
                <label class="block text-xs font-semibold uppercase tracking-wide opacity-70 mb-1">Code</label>
                <input class="input-custom w-full" name="code" required value="<?php echo h($form['code']); ?>" placeholder="self_paced_6">
              </div>
              <div class="sm:col-span-2">
                <label class="block text-xs font-semibold uppercase tracking-wide opacity-70 mb-1">Description</label>
                <textarea class="input-custom w-full" name="description" rows="2"><?php echo h($form['description'] ?? ''); ?></textarea>
              </div>
              <div>
                <label class="block text-xs font-semibold uppercase tracking-wide opacity-70 mb-1">Price (â‚±)</label>
                <input class="input-custom w-full" name="price_pesos" type="number" min="0" step="0.01" required value="<?php echo h(number_format(((int)$form['price_centavos']) / 100, 2, '.', '')); ?>">
              </div>
              <div class="grid grid-cols-2 gap-2">
                <div>
                  <label class="block text-xs font-semibold uppercase tracking-wide opacity-70 mb-1">Duration</label>
                  <input class="input-custom w-full" name="duration_value" type="number" min="1" required value="<?php echo (int)$form['duration_value']; ?>">
                </div>
                <div>
                  <label class="block text-xs font-semibold uppercase tracking-wide opacity-70 mb-1">Unit</label>
                  <select class="input-custom w-full" name="duration_unit">
                    <option value="month" <?php echo ($form['duration_unit'] ?? '') === 'month' ? 'selected' : ''; ?>>Months</option>
                    <option value="day" <?php echo ($form['duration_unit'] ?? '') === 'day' ? 'selected' : ''; ?>>Days</option>
                  </select>
                </div>
              </div>
              <div>
                <label class="block text-xs font-semibold uppercase tracking-wide opacity-70 mb-1">Access scope</label>
                <select class="input-custom w-full" name="access_scope" x-model="scope">
                  <option value="full_lms">Full LMS (no content map required)</option>
                  <option value="mapped">Mapped content (select below)</option>
                </select>
              </div>
              <div>
                <label class="block text-xs font-semibold uppercase tracking-wide opacity-70 mb-1">Sort order</label>
                <input class="input-custom w-full" name="sort_order" type="number" value="<?php echo (int)$form['sort_order']; ?>">
              </div>
            </div>

            <div class="flex flex-wrap gap-4">
              <label class="inline-flex items-center gap-2 text-sm font-semibold cursor-pointer">
                <input type="checkbox" name="is_active" value="1" <?php echo !empty($form['is_active']) ? 'checked' : ''; ?>> Active
              </label>
              <label class="inline-flex items-center gap-2 text-sm font-semibold cursor-pointer">
                <input type="checkbox" name="is_purchasable" value="1" <?php echo !empty($form['is_purchasable']) ? 'checked' : ''; ?>> Purchasable
              </label>
            </div>

            <div class="rounded-xl border border-white/10 p-4" x-show="scope === 'mapped'" x-cloak>
              <h3 class="font-bold text-sm mb-2">Included LMS content</h3>
              <p class="text-xs opacity-60 mb-3">References existing subjects / topics (lessons). Required when scope is Mapped.</p>
              <div class="max-h-64 overflow-y-auto space-y-3 pr-1">
                <?php foreach ($picker as $sub): ?>
                  <div class="rounded-lg bg-black/10 dark:bg-white/5 p-3">
                    <label class="flex items-center gap-2 font-semibold text-sm cursor-pointer">
                      <input type="checkbox" name="map_subject[]" value="<?php echo (int)$sub['subject_id']; ?>" <?php echo isset($mapSubjects[(int)$sub['subject_id']]) ? 'checked' : ''; ?>>
                      <?php echo h($sub['subject_name']); ?>
                    </label>
                    <div class="mt-2 ml-6 space-y-1">
                      <?php foreach ($sub['lessons'] as $les): ?>
                        <label class="flex items-center gap-2 text-xs cursor-pointer opacity-90">
                          <input type="checkbox" name="map_lesson[]" value="<?php echo (int)$les['lesson_id']; ?>" <?php echo isset($mapLessons[(int)$les['lesson_id']]) ? 'checked' : ''; ?>>
                          <?php echo h($les['title']); ?>
                        </label>
                      <?php endforeach; ?>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>

            <div class="rounded-xl border border-white/10 p-4">
              <div class="flex items-center justify-between gap-2 mb-2">
                <div>
                  <h3 class="font-bold text-sm">Package features (non-LMS)</h3>
                  <p class="text-xs opacity-60">e.g. Live Zoom, Onsite, Coaching â€” not fake LMS content.</p>
                </div>
                <button type="button" class="admin-outline-btn px-3 py-1.5 rounded-lg text-xs font-semibold" @click="features.push({key:'',label:'',description:''})">Add</button>
              </div>
              <template x-for="(f, i) in features" :key="i">
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-2 mb-2">
                  <input class="input-custom" :name="'feature_key['+i+']'" x-model="f.key" placeholder="key e.g. live_zoom">
                  <input class="input-custom" :name="'feature_label['+i+']'" x-model="f.label" placeholder="Label e.g. Live / Zoom">
                  <div class="flex gap-2">
                    <input class="input-custom flex-1" :name="'feature_description['+i+']'" x-model="f.description" placeholder="Optional note">
                    <button type="button" class="admin-outline-btn px-2 rounded-lg" @click="features.splice(i,1)" aria-label="Remove">&times;</button>
                  </div>
                </div>
              </template>
            </div>

            <div class="flex flex-wrap gap-2">
              <button type="submit" class="admin-btn admin-btn--primary px-4 py-2.5 rounded-xl font-semibold inline-flex items-center gap-2"><i class="bi bi-check-lg"></i> Save package</button>
              <a href="admin_commerce_packages" class="admin-outline-btn px-4 py-2.5 rounded-xl font-semibold">Cancel</a>
            </div>
          </form>

          <?php if ($edit && (int)$form['package_id'] > 0): ?>
            <form method="post" class="mt-3" onsubmit="return confirm('Delete this package? Content/feature maps will be removed. Blocked if payment history references it.');">
              <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="package_id" value="<?php echo (int)$form['package_id']; ?>">
              <button type="submit" class="text-sm font-semibold text-red-400 hover:underline">Delete package</button>
            </form>
          <?php endif; ?>
        <?php else: ?>
          <div class="quiz-admin-table-shell rounded-2xl p-8 text-center opacity-70">
            <p>Select a package to edit, or create a new one.</p>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
</main>
</body>
</html>
