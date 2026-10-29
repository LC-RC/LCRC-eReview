<?php
/**
 * Portal selector for learners with both eReview and College Examination access.
 */
require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/college_schema.php';
require_once __DIR__ . '/includes/platform_access.php';

if (!isLoggedIn() || !verifySession()) {
    header('Location: ' . ereview_url('login'));
    exit;
}

$userId = (int) getCurrentUserId();
$portals = ereview_user_available_portals($conn, $userId);
$enterable = ereview_user_enterable_portals($conn, $userId);

if (count($portals) === 0 || $enterable === []) {
    $preferCollege = in_array('college_examination', $portals, true);
    ereview_session_end_no_enterable_portal($preferCollege);
}

if (count($portals) === 1) {
    if (ereview_session_can_enter_portal($conn, $portals[0], $userId)) {
        $_SESSION['active_portal'] = $portals[0];
        header('Location: ' . ereview_portal_dashboard_url($portals[0]));
        exit;
    }
    ereview_session_end_no_enterable_portal($portals[0] === 'college_examination');
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($token)) {
        $error = 'Invalid request. Please try again.';
    } else {
        $choice = trim((string) ($_POST['portal'] ?? ''));
        if ($choice === 'ereview' && ereview_session_can_enter_portal($conn, 'ereview', $userId)) {
            $_SESSION['active_portal'] = 'ereview';
            if (!empty($_SESSION['pending_remember_me'])) {
                unset($_SESSION['pending_remember_me']);
                require_once __DIR__ . '/remember_me.php';
                setRememberMeCookie($userId, 'lms');
            }
            header('Location: ' . ereview_portal_dashboard_url('ereview'));
            exit;
        }
        if ($choice === 'college_examination' && ereview_session_can_enter_portal($conn, 'college_examination', $userId)) {
            $_SESSION['active_portal'] = 'college_examination';
            if (!empty($_SESSION['pending_remember_me'])) {
                unset($_SESSION['pending_remember_me']);
                require_once __DIR__ . '/remember_me.php';
                setRememberMeCookie($userId, 'college');
            }
            header('Location: ' . ereview_portal_dashboard_url('college_examination'));
            exit;
        }
        if (in_array($choice, $portals, true)) {
            $error = 'That portal needs its own password.';
        } else {
            $error = 'Please choose a valid portal.';
        }
    }
}

$fullName = trim((string) ($_SESSION['full_name'] ?? 'Student'));
$csrf = generateCSRFToken();
$pageTitle = 'Choose Portal';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo h($pageTitle); ?> - LCRC eReview</title>
  <link rel="stylesheet" href="assets/css/fonts-nunito.css">
  <link rel="stylesheet" href="assets/vendor/bootstrap-icons/bootstrap-icons.min.css">
  <style>
    *, *::before, *::after { box-sizing: border-box; }
    body {
      margin: 0;
      min-height: 100vh;
      font-family: 'Nunito', Segoe UI, sans-serif;
      background: #0b1220;
      color: #e5e7eb;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 24px;
    }
    .portal-card {
      width: 100%;
      max-width: 520px;
      background: linear-gradient(180deg, #111827 0%, #0f172a 100%);
      border: 1px solid rgba(255,255,255,0.08);
      border-radius: 1rem;
      padding: 2rem 1.75rem;
      box-shadow: 0 24px 48px -20px rgba(0,0,0,0.55);
    }
    .portal-card h1 { margin: 0 0 0.35rem; font-size: 1.5rem; color: #fff; }
    .portal-card p.lead { margin: 0 0 1.5rem; color: #94a3b8; font-size: 0.95rem; }
    .portal-options { display: grid; gap: 1rem; }
    .portal-option {
      display: block;
      width: 100%;
      text-align: left;
      border: 1px solid rgba(255,255,255,0.12);
      background: rgba(15,23,42,0.85);
      border-radius: 0.75rem;
      padding: 1rem 1.1rem;
      color: inherit;
      cursor: pointer;
      transition: border-color 0.15s, background 0.15s;
    }
    .portal-option:hover { border-color: rgba(31,88,195,0.55); background: rgba(31,88,195,0.08); }
    .portal-option strong { display: block; font-size: 1.05rem; color: #fff; margin-bottom: 0.25rem; }
    .portal-option span { font-size: 0.875rem; color: #94a3b8; }
    .portal-error { color: #f87171; font-size: 0.875rem; margin-bottom: 1rem; }
  </style>
</head>
<body>
  <div class="portal-card">
    <h1>Welcome, <?php echo h($fullName); ?></h1>
    <p class="lead">Choose a portal you are authorized to use.</p>
    <?php if ($error): ?>
      <p class="portal-error"><?php echo h($error); ?></p>
    <?php endif; ?>
    <form method="post" class="portal-options">
      <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
      <?php if (in_array('ereview', $portals, true)): ?>
      <button type="submit" name="portal" value="ereview" class="portal-option">
        <strong><i class="bi bi-book"></i> eReview LMS</strong>
        <span>Review your learning materials, lectures, handouts, and quizzes.</span>
      </button>
      <?php endif; ?>
      <?php if (in_array('college_examination', $portals, true)): ?>
      <button type="submit" name="portal" value="college_examination" class="portal-option">
        <strong><i class="bi bi-clipboard-check"></i> College Examination</strong>
        <span>Take assigned examinations and view examination results.</span>
      </button>
      <?php endif; ?>
    </form>
  </div>
</body>
</html>
