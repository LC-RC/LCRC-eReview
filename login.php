<?php
require_once 'session_config.php';
require_once 'db.php';
require_once 'auth.php';
require_once 'login_rate_limit.php';

if (isLoggedIn() && verifySession()) {
    $role = getCurrentUserRole();
    if ($role === 'admin') {
        header('Location: admin_dashboard.php');
        exit;
    } elseif ($role === 'student') {
        header('Location: student_dashboard.php');
        exit;
    }
}

// Magic link sign-in: validate token and log user in
if (!empty($_GET['magic'])) {
    require_once __DIR__ . '/magic_link.php';
    $magicRaw = $_GET['magic'];
    $magicResult = validateMagicLinkToken($magicRaw);
    if ($magicResult !== null) {
        $stmt = mysqli_prepare($conn, "SELECT user_id, full_name, role, status, access_end FROM users WHERE user_id = ? LIMIT 1");
        mysqli_stmt_bind_param($stmt, 'i', $magicResult['user_id']);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $user = $result ? mysqli_fetch_assoc($result) : null;
        mysqli_stmt_close($stmt);
        if ($user) {
            if ($user['role'] !== 'admin' && strtolower($user['status']) !== 'approved') {
                $_SESSION['error'] = 'Your account is not approved yet.';
                $_SESSION['error_type'] = 'not_approved';
                header('Location: login.php');
                exit;
            }
            if ($user['role'] !== 'admin') {
                $now = new DateTime('now');
                if (!empty($user['access_end'])) {
                    $end = new DateTime($user['access_end']);
                    if ($now > $end) {
                        $_SESSION['error'] = 'Your access has expired.';
                        $_SESSION['error_type'] = 'access_expired';
                        header('Location: login.php');
                        exit;
                    }
                }
            }
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_regenerate_id(true);
            }
            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['created'] = time();
            $_SESSION['last_activity'] = time();
            $uid = (int) $user['user_id'];
            $now = date('Y-m-d H:i:s');
            $ip = function_exists('getLoginClientIp') ? getLoginClientIp() : ($_SERVER['REMOTE_ADDR'] ?? '');
            $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);
            $upd = @mysqli_prepare($conn, 'UPDATE users SET last_login_at = ?, last_login_ip = ?, last_login_user_agent = ? WHERE user_id = ?');
            if ($upd) {
                mysqli_stmt_bind_param($upd, 'sssi', $now, $ip, $ua, $uid);
                @mysqli_stmt_execute($upd);
                mysqli_stmt_close($upd);
            }
            deleteMagicLinkToken($magicResult['token_id']);
            if (verifySession()) {
                $target = ($user['role'] === 'admin') ? 'admin_dashboard.php' : 'student_dashboard.php';
                header('Location: ' . $target);
                exit;
            }
        }
    }
    $_SESSION['error'] = 'This sign-in link is invalid or has expired.';
    $_SESSION['error_type'] = 'invalid_credentials';
    header('Location: login.php');
    exit;
}

$pageTitle = 'Login';
$error = $_SESSION['error'] ?? null;
$errorType = $_SESSION['error_type'] ?? 'invalid_credentials';
$rateLimitUntil = isset($_SESSION['rate_limit_until']) ? (int) $_SESSION['rate_limit_until'] : null;
unset($_SESSION['error'], $_SESSION['error_type']);

// Clear expired rate limit so we don't show the block on next load
if ($rateLimitUntil !== null && time() >= $rateLimitUntil) {
    unset($_SESSION['rate_limit_until']);
    $rateLimitUntil = null;
}

$showRateLimitBlock = $rateLimitUntil !== null && $rateLimitUntil > time();
$csrf = generateCSRFToken();

// CAPTCHA after 2+ failed attempts (when configured)
$loginAttemptCount = $showRateLimitBlock ? 0 : getLoginAttemptCount();
$recaptchaConfig = file_exists(__DIR__ . '/config/recaptcha_config.php') ? require __DIR__ . '/config/recaptcha_config.php' : [];
$recaptchaSiteKey = is_array($recaptchaConfig) ? trim($recaptchaConfig['site_key'] ?? '') : '';
$showRecaptcha = ($loginAttemptCount >= LOGIN_CAPTCHA_AFTER_ATTEMPTS && $recaptchaSiteKey !== '');

// Modal title by error type (clearer server-side messages)
$errorTitles = [
    'rate_limit' => 'Too many attempts',
    'not_approved' => 'Account not approved',
    'access_expired' => 'Access expired',
    'invalid_credentials' => 'Incorrect credentials',
    'csrf' => 'Invalid request',
    'password_required' => 'Password required',
    'session_failed' => 'Session error',
    'google_no_account' => 'No account found',
    'google_not_verified' => 'Email not verified',
    'google_not_configured' => 'Google Sign-In not set up',
];
$errorModalTitle = $errorTitles[$errorType] ?? 'Incorrect credentials';
$googleRedirectUri = '';
if ($errorType === 'google_not_configured' || (strpos($error ?? '', 'Google') !== false && $errorType === 'invalid_credentials')) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $base = $scheme . '://' . $host . dirname($_SERVER['SCRIPT_NAME'] ?? '');
    $base = str_replace('\\', '/', $base);
    $googleRedirectUri = rtrim($base, '/') . '/google_callback.php';
}
if (isset($_SESSION['google_redirect_uri'])) {
    $googleRedirectUri = $_SESSION['google_redirect_uri'];
    unset($_SESSION['google_redirect_uri']);
}
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
  <?php require_once __DIR__ . '/includes/head_public.php'; ?>
  <style>
    /* === System color theme: blue (#1F58C3), yellow (#F59E0B), white === */
    body.login-prototype {
      background: #0b1220 !important;
      color: #e5e7eb;
    }
    body.login-prototype .animated-bg {
      background: #0b1220 !important;
    }
    body.login-prototype .animated-bg::before,
    body.login-prototype .animated-bg::after {
      display: none !important;
    }
    body.login-prototype .auth-corner-decor::before,
    body.login-prototype .auth-corner-decor::after {
      width: 80px;
      height: 52px;
      background: rgba(15, 23, 42, 0.9);
      border: 1px solid rgba(31, 88, 195, 0.2);
      border-radius: 6px;
      box-shadow: none;
    }
    body.login-prototype .auth-corner-dot {
      width: 4px;
      height: 4px;
      background: rgba(245, 158, 11, 0.9);
      box-shadow: 0 0 8px rgba(245, 158, 11, 0.5);
    }
    body.login-prototype .auth-corner-dot.blue {
      background: rgba(31, 88, 195, 0.9);
      box-shadow: 0 0 8px rgba(31, 88, 195, 0.5);
    }
    /* Ledger-style grid: subtle, slow pulse (accounting theme) */
    body.login-prototype .circuit-bg {
      position: fixed;
      inset: 0;
      z-index: 0;
      pointer-events: none;
      background-image:
        linear-gradient(rgba(31, 88, 195, 0.05) 1px, transparent 1px),
        linear-gradient(90deg, rgba(31, 88, 195, 0.05) 1px, transparent 1px);
      background-size: 32px 32px;
      animation: login-bg-grid-pulse 12s ease-in-out infinite;
    }
    @keyframes login-bg-grid-pulse {
      0%, 100% { opacity: 0.6; }
      50% { opacity: 1; }
    }
    /* Animated background layer: nodes + lines (behind card) */
    body.login-prototype .login-bg-animation {
      position: fixed;
      inset: 0;
      z-index: 0;
      pointer-events: none;
      overflow: hidden;
    }
    /* Floating data nodes (financial/analytics theme) */
    .login-bg-node {
      position: absolute;
      width: 6px;
      height: 6px;
      border-radius: 50%;
      animation: login-bg-float 24s ease-in-out infinite;
    }
    .login-bg-node--blue {
      background: rgba(31, 88, 195, 0.35);
      box-shadow: 0 0 12px rgba(31, 88, 195, 0.25);
      left: var(--x, 15%);
      top: var(--y, 25%);
      animation-delay: var(--delay, 0s);
      animation-duration: var(--dur, 22s);
    }
    .login-bg-node--gold {
      background: rgba(245, 158, 11, 0.3);
      box-shadow: 0 0 10px rgba(245, 158, 11, 0.2);
      left: var(--x, 80%);
      top: var(--y, 70%);
      animation-delay: var(--delay, 2s);
      animation-duration: var(--dur, 26s);
    }
    .login-bg-node--white {
      background: rgba(255, 255, 255, 0.08);
      box-shadow: 0 0 8px rgba(255, 255, 255, 0.06);
      left: var(--x, 70%);
      top: var(--y, 15%);
      animation-delay: var(--delay, 1s);
      animation-duration: var(--dur, 28s);
    }
    @keyframes login-bg-float {
      0%, 100% { transform: translate(0, 0) scale(1); opacity: 0.7; }
      25% { transform: translate(8px, -12px) scale(1.05); opacity: 1; }
      50% { transform: translate(-5px, 6px) scale(0.95); opacity: 0.8; }
      75% { transform: translate(-10px, -5px) scale(1.02); opacity: 0.9; }
    }
    /* Graph/network lines SVG container */
    .login-bg-lines {
      position: absolute;
      inset: 0;
      opacity: 0.4;
    }
    .login-bg-lines svg {
      width: 100%;
      height: 100%;
    }
    .login-bg-lines .line {
      fill: none;
      stroke-width: 0.5;
      stroke-linecap: round;
      animation: login-bg-line-flow 20s linear infinite;
    }
    .login-bg-lines .line--blue { stroke: rgba(31, 88, 195, 0.2); }
    .login-bg-lines .line--gold { stroke: rgba(245, 158, 11, 0.15); animation-delay: -5s; }
    .login-bg-lines .line--white { stroke: rgba(255, 255, 255, 0.06); animation-delay: -10s; }
    @keyframes login-bg-line-flow {
      0% { stroke-dashoffset: 0; }
      100% { stroke-dashoffset: -200; }
    }
    /* Gradient blob behind card (CPA / premium feel) */
    .login-bg-blob {
      position: fixed;
      left: 50%;
      top: 50%;
      transform: translate(-50%, -50%);
      width: min(120vw, 680px);
      height: min(80vw, 520px);
      border-radius: 50% 40% 60% 50% / 50% 60% 40% 50%;
      background: radial-gradient(ellipse at 30% 20%, rgba(31, 88, 195, 0.18) 0%, transparent 50%),
                  radial-gradient(ellipse at 70% 80%, rgba(245, 158, 11, 0.08) 0%, transparent 45%),
                  radial-gradient(ellipse at 50% 50%, rgba(30, 58, 138, 0.12) 0%, transparent 55%);
      filter: blur(48px);
      z-index: 0;
      pointer-events: none;
      animation: login-blob-drift 20s ease-in-out infinite;
    }
    @keyframes login-blob-drift {
      0%, 100% { transform: translate(-50%, -50%) scale(1) rotate(0deg); }
      33% { transform: translate(-52%, -48%) scale(1.05) rotate(2deg); }
      66% { transform: translate(-48%, -52%) scale(0.98) rotate(-1deg); }
    }
    /* CPA data-visual layer: progress rings & line silhouettes (very low opacity) */
    .login-cpa-visual {
      position: fixed;
      inset: 0;
      z-index: 0;
      pointer-events: none;
      opacity: 0.12;
    }
    .login-cpa-visual svg {
      width: 100%;
      height: 100%;
      object-fit: cover;
    }
    .login-cpa-visual .cpa-ring {
      fill: none;
      stroke-width: 1.5;
      stroke-linecap: round;
      stroke: rgba(31, 88, 195, 0.5);
      animation: cpa-ring-pulse 8s ease-in-out infinite;
    }
    .login-cpa-visual .cpa-line {
      fill: none;
      stroke: rgba(31, 88, 195, 0.35);
      stroke-width: 0.8;
      stroke-dasharray: 4 6;
      animation: cpa-line-flow 25s linear infinite;
    }
    @keyframes cpa-ring-pulse {
      0%, 100% { opacity: 0.6; stroke-dashoffset: 0; }
      50% { opacity: 1; stroke-dashoffset: -30; }
    }
    @keyframes cpa-line-flow {
      0% { stroke-dashoffset: 0; }
      100% { stroke-dashoffset: -200; }
    }
    /* Cashflow-style path (bottom-left to top-right) */
    .login-cashflow-path {
      position: fixed;
      inset: 0;
      z-index: 0;
      pointer-events: none;
      opacity: 0.2;
    }
    .login-cashflow-path svg {
      width: 100%;
      height: 100%;
    }
    .login-cashflow-path .path {
      fill: none;
      stroke: rgba(245, 158, 11, 0.4);
      stroke-width: 1;
      stroke-dasharray: 120 80;
      animation: login-cashflow-draw 18s linear infinite;
    }
    @keyframes login-cashflow-draw {
      0% { stroke-dashoffset: 0; }
      100% { stroke-dashoffset: -400; }
    }
    @media (prefers-reduced-motion: reduce) {
      .login-bg-blob { animation: none; }
      .login-cpa-visual .cpa-ring,
      .login-cpa-visual .cpa-line { animation: none; }
      .login-cashflow-path .path { animation: none; }
      body.login-prototype .circuit-bg { animation: none; opacity: 0.7; }
      .login-bg-node { animation: none; }
      .login-bg-lines .line { animation: none; }
      body.login-prototype .login-card #login-submit:hover,
      body.login-prototype .login-card .login-google-btn:hover { transform: none; }
      body.login-prototype .login-card #login-submit:active,
      body.login-prototype .login-card .login-google-btn:active { transform: none; }
      body.login-prototype .login-logo-hover:hover { transform: none; filter: none; }
    }
    /* Touch targets: at least 44px for tap (mobile) */
    @media (hover: none) and (pointer: coarse) {
      body.login-prototype .login-card .auth-input { min-height: 2.75rem !important; }
      body.login-prototype .login-card #login-submit,
      body.login-prototype .login-card .login-google-btn { min-height: 2.75rem !important; }
      body.login-prototype .login-card #toggle-password {
        min-width: 2.75rem !important; min-height: 2.75rem !important;
      }
      body.login-prototype .login-card [for="login-remember"] { min-height: 2.75rem; display: inline-flex; align-items: center; }
    }
    /* Card container: slightly wider + more compact vertically */
    body.login-prototype .login-card-wrap {
      max-width: 460px !important;
    }
    /* Card: floating depth, subtle light border, focus glow */
    body.login-prototype .login-card {
      background: linear-gradient(180deg, #111827 0%, #0f172a 100%) !important;
      border: 1px solid rgba(255, 255, 255, 0.06) !important;
      box-shadow: 0 24px 48px -20px rgba(0,0,0,0.55), 0 0 0 1px rgba(31, 88, 195, 0.1), 0 1px 0 rgba(255,255,255,0.03) inset !important;
      border-radius: 1rem !important;
      padding: 1.1rem 1.75rem 1.3rem !important;
      transition: box-shadow 0.25s ease, transform 0.25s ease;
    }
    body.login-prototype .login-card:focus-within {
      box-shadow: 0 24px 48px -20px rgba(0,0,0,0.55), 0 0 0 1px rgba(31, 88, 195, 0.18), 0 0 40px rgba(31, 88, 195, 0.12), 0 1px 0 rgba(255,255,255,0.03) inset !important;
    }
    /* Header: tighter vertical spacing to reduce height */
    body.login-prototype .login-header {
      margin-bottom: 1rem !important;
    }
    body.login-prototype .login-logo-wrap {
      margin-bottom: 0.5rem !important;
    }
    body.login-prototype .login-logo-hover {
      transition: transform 0.2s ease, filter 0.2s ease;
    }
    body.login-prototype .login-logo-hover:hover {
      transform: scale(1.03);
      filter: drop-shadow(0 0 8px rgba(31, 88, 195, 0.3));
    }
    body.login-prototype .login-logo-img {
      height: 2.5rem;
      width: auto;
      max-width: 120px;
      object-fit: contain;
      object-position: center;
      display: block;
    }
    body.login-prototype .login-card .brand-text {
      color: #fff;
      font-size: 1rem;
      font-weight: 700;
      letter-spacing: 0.01em;
    }
    body.login-prototype .login-card .brand-text .blue { color: #1F58C3; }
    body.login-prototype .login-card .brand-text .amber { color: #F59E0B; }
    body.login-prototype .login-welcome {
      margin-bottom: 1rem !important;
    }
    body.login-prototype .login-value-statement {
      font-size: 0.8125rem; color: #94a3b8; margin-bottom: 0.5rem; line-height: 1.4;
    }
    body.login-prototype .login-dashboard-preview {
      display: flex; align-items: center; justify-content: center; gap: 0.75rem;
      margin-top: 0.75rem; padding: 0.5rem 0.75rem;
      background: rgba(31, 88, 195, 0.08); border: 1px solid rgba(31, 88, 195, 0.2);
      border-radius: 0.75rem; font-size: 0.6875rem; color: #94a3b8;
    }
    body.login-prototype .login-dashboard-preview span { display: inline-flex; align-items: center; gap: 0.25rem; }
    body.login-prototype .login-dashboard-preview .score { color: #7dd3fc; font-weight: 600; }
    body.login-prototype .login-signup-line { margin-top: 0.375rem !important; }
    body.login-prototype .login-card h1 {
      color: #fff !important;
      font-size: 1.25rem !important;
      font-weight: 700;
      letter-spacing: -0.025em;
    }
    /* Form: tighter spacing so form + footer fit on one screen */
    body.login-prototype .login-form-fields.space-y-4 > * + * { margin-top: 0.65rem !important; }
    body.login-prototype .login-card .space-y-2 > * + * { margin-top: 0.25rem !important; }
    body.login-prototype .login-card .login-piece-5b { margin-top: 1.25rem !important; }
    /* Floating label wrapper */
    body.login-prototype .login-card .float-label-wrap {
      position: relative;
    }
    body.login-prototype .login-card .float-label-wrap .float-label {
      position: absolute;
      left: 3rem;
      top: 50%;
      transform: translateY(-50%);
      font-size: 0.875rem;
      color: #94a3b8;
      pointer-events: none;
      transition: top 0.2s ease, font-size 0.2s ease, color 0.2s ease;
      z-index: 1;
    }
    body.login-prototype .login-card .auth-input {
      box-shadow: 0 1px 2px rgba(0,0,0,0.2) inset !important;
    }
    body.login-prototype .login-card .float-label-wrap.focused .float-label,
    body.login-prototype .login-card .float-label-wrap.has-value .float-label {
      top: -0.35rem;
      font-size: 0.75rem;
      color: #7dd3fc;
    }
    /* Input: enough left padding so placeholder never overlaps icon */
    body.login-prototype .login-card .auth-input {
      padding-left: 3rem !important;
      padding-top: 0.45rem !important;
      padding-bottom: 0.45rem !important;
      min-height: 2.35rem;
      border-radius: 0.75rem !important;
    }
    /* Icon wrap: fixed width, vertically centered in the input row */
    body.login-prototype .login-card .auth-input-icon-wrap {
      width: 3rem;
      left: 0;
      top: 0;
      bottom: 0;
      height: 100%;
      pointer-events: none;
      display: flex !important;
      align-items: center;
      justify-content: center;
    }
    body.login-prototype .login-card .auth-input-icon-wrap .bi {
      line-height: 1;
      display: block;
    }
    /* Password field: eye toggle inside the field (right side) */
    body.login-prototype .login-card .auth-password-wrap {
      position: relative;
    }
    body.login-prototype .login-card .auth-password-wrap .auth-input {
      padding-right: 2.75rem !important;
    }
    body.login-prototype .login-card #toggle-password {
      position: absolute !important;
      right: 0.5rem !important;
      top: 50% !important;
      transform: translateY(-50%) !important;
      width: 2rem;
      height: 2rem;
      min-width: 2rem;
      min-height: 2rem;
      padding: 0 !important;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      color: #9ca3af !important;
      background: transparent !important;
      border: none !important;
    }
    body.login-prototype .login-card #toggle-password:hover { color: #F59E0B !important; }
    /* Primary CTA */
    body.login-prototype .login-card #login-submit {
      padding-top: 0.625rem !important;
      padding-bottom: 0.625rem !important;
      margin-top: 0.25rem !important;
      border-radius: 0.75rem !important;
    }
    body.login-prototype .login-card .login-piece-6 { margin-top: 0.75rem !important; }
    body.login-prototype .login-card .or-divider {
      margin-top: 0.75rem !important;
      margin-bottom: 0.65rem !important;
    }
    /* Google: same size as Login button */
    body.login-prototype .login-card .login-google-btn {
      width: 100%;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 0.5rem;
      padding: 0.625rem 1rem;
      min-height: 2.5rem;
      border-radius: 0.75rem;
      font-size: 0.875rem;
      font-weight: 500;
    }
    body.login-prototype .login-card .login-google-btn img,
    body.login-prototype .login-card .login-google-icon {
      width: 1rem !important;
      height: 1rem !important;
      flex-shrink: 0;
    }
    body.login-prototype .login-card .login-piece-8 {
      margin-bottom: 0 !important;
    }
    body.login-prototype .login-card .login-blurbs {
      margin-top: 1.5rem !important;
      padding-top: 1.1rem !important;
    }
    body.login-prototype .login-card .login-blurb {
      transition: opacity 0.35s ease;
    }
    body.login-prototype .login-card .subtext { color: #94a3b8; font-size: 0.8125rem; }
    body.login-prototype .login-card .subtext a { color: #F59E0B !important; }
    body.login-prototype .login-card .subtext a:hover { color: #FCD34D !important; text-decoration: underline; }
    body.login-prototype .login-card .login-forgot-link:hover { text-decoration: underline; }
    body.login-prototype .login-card label { color: #fff !important; font-weight: 500; }
    body.login-prototype .login-card .auth-input {
      background: linear-gradient(180deg, #1e293b 0%, #1a2332 100%) !important;
      border: 1px solid rgba(31, 88, 195, 0.25) !important;
      color: #fff !important;
      box-shadow: 0 1px 2px rgba(0,0,0,0.25) inset !important;
    }
    body.login-prototype .login-card .auth-input::placeholder { color: #94a3b8; }
    body.login-prototype .login-card .auth-input:hover { border-color: rgba(31, 88, 195, 0.4) !important; }
    body.login-prototype .login-card .auth-input:focus {
      border-color: #1F58C3 !important;
      box-shadow: 0 0 0 2px rgba(31, 88, 195, 0.35), 0 1px 2px rgba(0,0,0,0.2) inset !important;
    }
    body.login-prototype .login-card .auth-input:focus-visible,
    body.login-prototype .login-card #login-submit:focus-visible,
    body.login-prototype .login-card .login-google-btn:focus-visible,
    body.login-prototype .login-card .subtext a:focus-visible,
    body.login-prototype .login-card .login-forgot-link:focus-visible {
      outline: none;
      box-shadow: 0 0 0 2px #fff, 0 0 0 4px #1F58C3 !important;
    }
    body.login-prototype .login-card #toggle-password:focus-visible {
      outline: none;
      box-shadow: 0 0 0 2px #fff, 0 0 0 4px #F59E0B !important;
    }
    body.login-prototype .login-card .login-security-hint { color: #94a3b8 !important; }
    /* Success/info alert: dark-theme, subtle animation */
    body.login-prototype .login-card .auth-alert {
      display: flex;
      align-items: center;
      gap: 0.75rem;
      padding: 0.875rem 1rem;
      border-radius: 0.75rem;
      font-size: 0.875rem;
      margin-bottom: 1rem;
      border: 1px solid transparent;
      border-left: 3px solid;
      animation: auth-alert-in 0.35s ease-out;
    }
    body.login-prototype .login-card .auth-alert-icon { font-size: 1.25rem; flex-shrink: 0; }
    body.login-prototype .login-card .auth-alert-text { font-weight: 500; }
    @keyframes auth-alert-in {
      from { opacity: 0; transform: translateY(-6px); }
      to { opacity: 1; transform: translateY(0); }
    }
    body.login-prototype .login-card #login-remember:focus-visible {
      outline: none;
      box-shadow: 0 0 0 2px #0f172a, 0 0 0 4px #1F58C3;
    }
    body.login-prototype .login-card .input-icon { color: #F59E0B; }
    body.login-prototype .login-card #login-submit {
      background: #1F58C3 !important;
      color: #fff !important;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      font-weight: 600;
      transition: background 0.2s ease, transform 0.2s ease, box-shadow 0.2s ease;
    }
    body.login-prototype .login-card #login-submit:hover { background: #1E40AF !important; transform: translateY(-2px); }
    body.login-prototype .login-card #login-submit:active { transform: translateY(0) scale(0.98); }
    body.login-prototype .login-card .login-google-btn { transition: background 0.2s ease, border-color 0.2s ease, transform 0.2s ease; }
    body.login-prototype .login-card .login-google-btn:hover { transform: translateY(-2px); }
    body.login-prototype .login-card .login-google-btn:active { transform: translateY(0) scale(0.98); }
    body.login-prototype .login-card .or-divider { color: #64748b; font-size: 0.75rem; font-weight: 600; letter-spacing: 0.05em; }
    body.login-prototype .login-card .or-divider span:first-child,
    body.login-prototype .login-card .or-divider span:last-child {
      background: linear-gradient(90deg, transparent 0%, rgba(31, 88, 195, 0.15) 20%, rgba(31, 88, 195, 0.15) 80%, transparent 100%) !important;
      height: 1px;
    }
    body.login-prototype .login-card .social-btn,
    body.login-prototype .login-card .login-google-btn {
      background: #1e293b !important;
      border: 1px solid rgba(31, 88, 195, 0.3) !important;
      color: #fff;
    }
    body.login-prototype .login-card .social-btn:hover,
    body.login-prototype .login-card .login-google-btn:hover {
      background: #334155 !important;
      border-color: rgba(245, 158, 11, 0.4) !important;
    }
    body.login-prototype .login-card label[for="login-remember"] { color: #fff !important; }
    body.login-prototype .login-card label[for="login-remember"] span:first-child { color: #fff !important; font-weight: 500; }
    body.login-prototype .login-card #login-remember-hint { color: #94a3b8 !important; font-size: 0.75rem; }
    body.login-prototype .login-card .login-forgot-link { color: #F59E0B !important; }
    body.login-prototype .login-card .login-forgot-link:hover { color: #FCD34D !important; }
    body.login-prototype .login-footer-copy {
      color: #64748b !important;
      font-size: 0.6875rem !important;
      line-height: 1.5;
      margin-top: 1rem;
      padding: 0.75rem 1rem 1rem;
      position: relative;
      z-index: 10;
    }
    body.login-prototype .login-ratelimit-block {
      background: rgba(245, 158, 11, 0.12);
      border-color: rgba(245, 158, 11, 0.35);
    }
    body.login-prototype .login-ratelimit-block-title { color: #F59E0B !important; }
    body.login-prototype .login-ratelimit-block-desc { color: #e2e8f0 !important; }
    body.login-prototype .login-ratelimit-countdown { color: #F59E0B !important; background: rgba(15, 23, 42, 0.5) !important; }
    body.login-prototype #email-error,
    body.login-prototype #password-error { color: #f87171 !important; }

    .login-loading-backdrop,
    .login-error-backdrop {
      position: fixed;
      inset: 0;
      z-index: 80;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 1.5rem;
      background: radial-gradient(circle at 12% 5%, rgba(254, 243, 199, 0.35), transparent 50%), rgba(15, 23, 42, 0.6);
      backdrop-filter: blur(18px);
      opacity: 0;
      pointer-events: none;
      transition: opacity 220ms ease-out;
    }
    .login-loading-backdrop.is-active,
    .login-error-backdrop.is-active {
      opacity: 1;
      pointer-events: auto;
    }
    .login-loading-stack {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 1rem;
    }
    .login-loading-orb {
      width: 72px;
      height: 72px;
      border-radius: 9999px;
      background: conic-gradient(from 200deg, #f59e0b, #1f58c3, #f59e0b);
      padding: 3px;
      animation: login-orb-spin 900ms linear infinite;
    }
    .login-loading-orb-inner {
      width: 100%;
      height: 100%;
      border-radius: inherit;
      background: radial-gradient(circle at 20% 0%, rgba(255, 255, 255, 0.96), rgba(248, 250, 252, 0.98));
      display: flex;
      align-items: center;
      justify-content: center;
      box-shadow: 0 18px 40px rgba(15, 23, 42, 0.35);
    }
    .login-loading-orb-inner span {
      width: 12px;
      height: 12px;
      border-radius: 9999px;
      background: linear-gradient(135deg, #f59e0b, #f97316);
      box-shadow: 0 0 0 0 rgba(245, 158, 11, 0.55);
      animation: login-orb-pulse 1100ms ease-out infinite;
    }
    .login-loading-label {
      font-size: 0.85rem;
      font-weight: 600;
      color: #e2e8f0;
      letter-spacing: 0.01em;
    }
    @keyframes login-orb-spin {
      to {
        transform: rotate(360deg);
      }
    }
    @keyframes login-orb-pulse {
      0% {
        transform: scale(0.92);
        box-shadow: 0 0 0 0 rgba(245, 158, 11, 0.55);
      }
      70% {
        transform: scale(1.1);
        box-shadow: 0 0 0 18px rgba(245, 158, 11, 0);
      }
      100% {
        transform: scale(0.92);
        box-shadow: 0 0 0 0 rgba(245, 158, 11, 0);
      }
    }
    .login-error-card {
      max-width: 380px;
      width: 100%;
      border-radius: 1.5rem;
      padding: 1.9rem 2rem 1.85rem;
      background:
        radial-gradient(circle at 0 0, rgba(245, 158, 11, 0.16), transparent 55%),
        radial-gradient(circle at 100% 100%, rgba(31, 88, 195, 0.24), transparent 55%),
        linear-gradient(145deg, #020617, #020617 35%, #0b1847 70%, #020617 100%);
      box-shadow: 0 32px 80px rgba(15, 23, 42, 0.7);
      color: #e5e7eb;
      transform-origin: 50% 60%;
      transform: translateY(18px) scale(0.94);
      opacity: 0;
      border: 1px solid rgba(15, 23, 42, 0.9);
      transition: opacity 220ms ease-out, transform 220ms ease-out, box-shadow 220ms ease-out;
      display: flex;
      flex-direction: column;
      align-items: center;
      text-align: center;
    }
    .login-error-backdrop.is-active .login-error-card {
      opacity: 1;
      transform: translateY(0) scale(1);
      box-shadow: 0 40px 90px rgba(15, 23, 42, 0.85);
      transition-delay: 70ms;
    }
    .login-error-backdrop {
      transition: opacity 200ms ease-out;
    }
    .login-error-icon {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      margin-bottom: 1rem;
    }
    .login-error-circle {
      width: 56px;
      height: 56px;
      border-radius: 9999px;
      border: 1px solid rgba(148, 163, 184, 0.55);
      display: flex;
      align-items: center;
      justify-content: center;
      position: relative;
      background:
        radial-gradient(circle at 15% 0, rgba(245, 250, 255, 0.22), transparent 55%),
        radial-gradient(circle at 50% 115%, rgba(248, 113, 113, 0.9), rgba(127, 29, 29, 1));
      box-shadow: 0 18px 40px rgba(15, 23, 42, 0.9);
      animation: login-error-pop 260ms cubic-bezier(0.22, 1, 0.36, 1) forwards;
      overflow: hidden;
    }
    .login-error-circle::before {
      content: "";
      position: absolute;
      inset: 4px;
      border-radius: inherit;
      border: 1px solid rgba(248, 250, 252, 0.18);
      box-shadow: 0 0 0 0 rgba(248, 250, 252, 0.4);
      animation: login-error-ring 900ms ease-out forwards;
    }
    .login-error-circle::after {
      content: "";
      position: absolute;
      width: 7px;
      height: 7px;
      border-radius: 9999px;
      background: linear-gradient(135deg, #fee2e2, #f97373);
      top: 16%;
      right: 18%;
      box-shadow: 0 0 0 0 rgba(248, 113, 113, 0.7);
      animation: login-error-orbit 1.3s ease-out forwards;
    }
    .login-error-line {
      position: absolute;
      width: 26px;
      height: 3px;
      border-radius: 9999px;
      background: linear-gradient(90deg, #fecaca, #f97373);
      transform-origin: center;
      opacity: 0;
    }
    .login-error-line-1 {
      transform: rotate(45deg) scaleX(0);
      animation: login-error-line 260ms 120ms cubic-bezier(0.22, 1, 0.36, 1) forwards;
    }
    .login-error-line-2 {
      transform: rotate(-45deg) scaleX(0);
      animation: login-error-line 260ms 190ms cubic-bezier(0.22, 1, 0.36, 1) forwards;
    }
    @keyframes login-error-pop {
      0% {
        transform: scale(0.7);
        opacity: 0;
      }
      70% {
        transform: scale(1.12);
        opacity: 1;
      }
      100% {
        transform: scale(1);
        opacity: 1;
      }
    }
    @keyframes login-error-line {
      0% {
        opacity: 0;
        transform: scaleX(0);
      }
      100% {
        opacity: 1;
        transform: scaleX(1);
      }
    }
    @keyframes login-error-ring {
      0% {
        box-shadow: 0 0 0 0 rgba(248, 250, 252, 0.45);
        opacity: 1;
      }
      100% {
        box-shadow: 0 0 0 14px rgba(248, 250, 252, 0);
        opacity: 0.4;
      }
    }
    @keyframes login-error-orbit {
      0% {
        transform: translate(0, 0) scale(0.6);
        box-shadow: 0 0 0 0 rgba(248, 113, 113, 0.7);
      }
      70% {
        transform: translate(-4px, 4px) scale(1.05);
        box-shadow: 0 0 0 10px rgba(248, 113, 113, 0);
      }
      100% {
        transform: translate(-2px, 2px) scale(1);
        box-shadow: 0 0 0 0 rgba(248, 113, 113, 0);
      }
    }
    .login-error-title {
      margin-bottom: 0.35rem;
      letter-spacing: 0.01em;
      animation: login-error-text-pulse 1.7s ease-in-out infinite;
      color: #fee2e2;
    }
    .login-error-text {
      font-size: 0.76rem;
      line-height: 1.6;
      max-width: 17rem;
      margin-bottom: 1.1rem;
      color: #e5e7eb;
      animation: login-error-text-pulse 1.7s ease-in-out infinite;
      animation-delay: 120ms;
    }
    @keyframes login-error-text-pulse {
      0% {
        text-shadow: 0 0 0 rgba(248, 113, 113, 0);
      }
      40% {
        text-shadow: 0 0 12px rgba(248, 113, 113, 0.9);
      }
      100% {
        text-shadow: 0 0 0 rgba(248, 113, 113, 0);
      }
    }
    .login-card--shake {
      animation: login-card-shake 420ms cubic-bezier(0.36, 0.07, 0.19, 0.97);
    }
    @keyframes login-card-shake {
      0% { transform: translateX(0); }
      15% { transform: translateX(-6px); }
      30% { transform: translateX(6px); }
      45% { transform: translateX(-4px); }
      60% { transform: translateX(4px); }
      75% { transform: translateX(-2px); }
      100% { transform: translateX(0); }
    }
    .login-piece-5b { animation-delay: 360ms; }
    /* Rate limit block – in-card, matches system design */
    .login-ratelimit-block {
      display: flex;
      flex-direction: column;
      align-items: center;
      text-align: center;
      padding: 1.75rem 1.5rem;
      border-radius: 1.25rem;
      background: linear-gradient(145deg, rgba(254, 243, 199, 0.5) 0%, rgba(255, 251, 235, 0.6) 50%, rgba(254, 249, 195, 0.4) 100%);
      border: 1px solid rgba(245, 158, 11, 0.35);
      box-shadow: 0 4px 24px rgba(245, 158, 11, 0.12);
    }
    .login-ratelimit-block-icon-wrap {
      width: 56px;
      height: 56px;
      border-radius: 50%;
      background: linear-gradient(135deg, rgba(245, 158, 11, 0.2), rgba(251, 191, 36, 0.25));
      border: 1px solid rgba(245, 158, 11, 0.4);
      display: flex;
      align-items: center;
      justify-content: center;
      margin-bottom: 1rem;
    }
    .login-ratelimit-block-icon-wrap i {
      font-size: 1.5rem;
      color: #b45309;
    }
    .login-ratelimit-block-title {
      font-size: 1.125rem;
      font-weight: 800;
      color: #92400e;
      letter-spacing: -0.02em;
      margin-bottom: 0.35rem;
    }
    .login-ratelimit-block-desc {
      font-size: 0.8125rem;
      color: #a16207;
      line-height: 1.5;
      margin-bottom: 1rem;
      max-width: 18rem;
    }
    .login-ratelimit-countdown {
      font-variant-numeric: tabular-nums;
      font-size: 1.25rem;
      font-weight: 700;
      color: #b45309;
      padding: 0.5rem 1rem;
      border-radius: 0.75rem;
      background: rgba(255, 255, 255, 0.7);
      border: 1px solid rgba(245, 158, 11, 0.3);
      min-width: 8rem;
    }
    .login-ratelimit-block.form-hidden ~ .login-form-wrap,
    .login-form-wrap.visually-hidden {
      display: none !important;
    }
  </style>
</head>
<body class="auth-page login-prototype min-h-screen font-sans antialiased" data-login-error="<?php echo ($error && !$showRateLimitBlock) ? '1' : '0'; ?>" data-login-error-message="<?php echo ($error && !$showRateLimitBlock) ? h($error) : ''; ?>" data-login-error-title="<?php echo ($error && !$showRateLimitBlock) ? h($errorModalTitle) : ''; ?>" data-login-error-type="<?php echo ($error && !$showRateLimitBlock) ? h($errorType) : ''; ?>" data-rate-limit-until="<?php echo $showRateLimitBlock ? $rateLimitUntil : ''; ?>">
  <div class="animated-bg"></div>
  <div class="circuit-bg" aria-hidden="true"></div>
  <div class="login-bg-blob" aria-hidden="true"></div>
  <div class="login-cpa-visual" aria-hidden="true">
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1200 800" preserveAspectRatio="xMidYMid slice">
      <circle class="cpa-ring" cx="200" cy="200" r="80" stroke-dasharray="80 200" />
      <circle class="cpa-ring" cx="1000" cy="600" r="100" stroke-dasharray="100 250" style="animation-delay: -2s" />
      <path class="cpa-line" d="M0 400 Q300 350 600 380 T1200 360" />
      <path class="cpa-line" d="M0 550 L400 500 L800 520 L1200 480" style="animation-delay: -8s" />
    </svg>
  </div>
  <div class="login-cashflow-path" aria-hidden="true">
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1200 800" preserveAspectRatio="xMidYMid slice">
      <path class="path" d="M0 750 Q200 600 400 550 T800 350 T1200 100" stroke="rgba(245,158,11,0.35)" />
    </svg>
  </div>
  <div class="login-bg-animation" aria-hidden="true">
    <div class="login-bg-nodes absolute inset-0">
      <span class="login-bg-node login-bg-node--blue" style="--x:12%;--y:18%;--delay:0s;--dur:22s"></span>
      <span class="login-bg-node login-bg-node--blue" style="--x:88%;--y:22%;--delay:3s;--dur:25s"></span>
      <span class="login-bg-node login-bg-node--blue" style="--x:25%;--y:75%;--delay:6s;--dur:24s"></span>
      <span class="login-bg-node login-bg-node--blue" style="--x:75%;--y:80%;--delay:2s;--dur:26s"></span>
      <span class="login-bg-node login-bg-node--gold" style="--x:8%;--y:55%;--delay:1s;--dur:28s"></span>
      <span class="login-bg-node login-bg-node--gold" style="--x:92%;--y:45%;--delay:4s;--dur:23s"></span>
      <span class="login-bg-node login-bg-node--gold" style="--x:50%;--y:12%;--delay:5s;--dur:27s"></span>
      <span class="login-bg-node login-bg-node--white" style="--x:35%;--y:35%;--delay:2s;--dur:24s"></span>
      <span class="login-bg-node login-bg-node--white" style="--x:65%;--y:60%;--delay:7s;--dur:22s"></span>
    </div>
    <div class="login-bg-lines">
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1200 800" preserveAspectRatio="xMidYMid slice">
        <path class="line line--blue" stroke-dasharray="120 80" d="M0 200 Q300 150 600 200 T1200 200" />
        <path class="line line--blue" stroke-dasharray="100 60" d="M0 500 Q400 450 800 500 T1200 480" />
        <path class="line line--gold" stroke-dasharray="80 100" d="M100 0 V800 M500 0 V800 M900 0 V800" />
        <path class="line line--white" stroke-dasharray="150 100" d="M0 400 L1200 400 M0 600 L1200 600" />
      </svg>
    </div>
  </div>
  <div class="auth-corner-decor" aria-hidden="true">
    <span class="auth-corner-dot tl"></span><span class="auth-corner-dot tr blue"></span>
    <span class="auth-corner-dot bl blue"></span><span class="auth-corner-dot br"></span>
  </div>
  <div class="login-page-layout min-h-screen flex flex-col relative z-10">
    <div class="flex-1 flex items-center justify-center p-4">
    <div class="login-card-wrap w-full max-w-[400px] mx-auto">
      <div class="login-card">
          <div class="flex flex-col items-center login-piece login-piece-1 login-header">
          <div class="login-logo-wrap flex items-center justify-center login-logo-hover">
            <img src="image%20assets/lms-logo.png" alt="LCRC eReview" class="login-logo-img" width="120" height="48" loading="eager" decoding="async">
          </div>
          <span class="brand-text"><span class="blue">LCRC</span> <span class="amber">eReview</span></span>
        </div>

        <div class="text-center login-piece login-piece-2 login-welcome">
          <p class="login-value-statement">Track your scores, drills, and mock exams in one place.</p>
          <h1 class="text-xl font-bold tracking-tight">Welcome Back</h1>
          <p class="subtext login-signup-line">Don't have an account yet? <a href="registration.php">Sign up</a></p>
        </div>

        <?php if ($showRateLimitBlock): ?>
        <div class="login-ratelimit-block login-piece login-piece-3" id="login-ratelimit-block" data-until="<?php echo (int) $rateLimitUntil; ?>">
          <div class="login-ratelimit-block-icon-wrap">
            <i class="bi bi-shield-lock-fill" aria-hidden="true"></i>
          </div>
          <h2 class="login-ratelimit-block-title">Too many login attempts</h2>
          <p class="login-ratelimit-block-desc">For your security, we've temporarily limited sign-in from this device. You can try again when the timer below reaches zero.</p>
          <div class="login-ratelimit-countdown" id="login-ratelimit-countdown" role="timer" aria-live="polite">—</div>
          <p class="mt-3 text-xs text-amber-700/80">Attempts are limited to 5 in 15 minutes. Lockout lasts 15 minutes.</p>
          <p class="mt-2 text-xs"><a href="forgot_password.php" class="text-amber-600 hover:underline font-medium">Reset your password</a> to unlock sooner.</p>
        </div>
        <?php endif; ?>

        <div class="login-form-wrap" id="login-form-wrap"<?php if ($showRateLimitBlock): ?> style="display: none;"<?php endif; ?>>
        <form action="login_process.php" method="POST" class="login-form-fields space-y-4" novalidate id="login-form"<?php if ($showRecaptcha): ?> data-recaptcha="1" data-recaptcha-key="<?php echo h($recaptchaSiteKey); ?>"<?php endif; ?>>
          <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
          <?php if ($showRecaptcha): ?>
          <input type="hidden" name="g-recaptcha-response" id="g-recaptcha-response" value="">
          <?php endif; ?>
          <div class="space-y-2 login-piece login-piece-4">
            <div class="relative float-label-wrap" id="login-email-wrap">
              <label for="login-email" class="float-label">Email Address</label>
              <span class="auth-input-icon-wrap absolute left-0 top-0 bottom-0 flex items-center justify-center input-icon">
                <i class="bi bi-envelope-fill text-lg" aria-hidden="true"></i>
              </span>
              <input
                id="login-email"
                name="email"
                type="email"
                inputmode="email"
                autocomplete="email"
                required
                placeholder=" "
                class="auth-input w-full rounded-xl border pl-11 pr-4 py-3 text-sm transition-all duration-200 focus:ring-2 focus:ring-blue-500/30" aria-describedby="email-error"
              >
            </div>
            <p id="email-error" class="text-sm mt-1 min-h-[1.25rem]" role="alert" aria-live="polite"></p>
          </div>

          <div class="space-y-2 login-piece login-piece-5">
            <div class="flex items-center justify-end gap-3 flex-wrap">
              <a href="forgot_password.php" class="login-forgot-link text-xs font-medium hover:underline">Forgot password?</a>
              <span class="text-gray-400">·</span>
              <a href="request_magic_link.php" class="text-xs font-medium hover:underline text-slate-400 hover:text-slate-300">Email me a sign-in link</a>
            </div>
            <div class="relative float-label-wrap auth-password-wrap" id="login-password-wrap">
              <label for="login-password" class="float-label">Password</label>
              <span class="auth-input-icon-wrap absolute left-0 top-0 bottom-0 flex items-center justify-center input-icon">
                <i class="bi bi-lock-fill text-lg" aria-hidden="true"></i>
              </span>
              <input
                id="login-password"
                name="password"
                type="password"
                autocomplete="current-password"
                required
                placeholder=" "
                class="auth-input w-full rounded-xl border pl-11 pr-12 py-3 text-sm transition-all duration-200 focus:ring-2 focus:ring-blue-500/30" aria-describedby="password-error"
              >
              <button
                type="button"
                id="toggle-password"
                class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#1F58C3]/40 rounded-lg p-1.5 transition-colors auth-toggle-password"
                aria-label="Show password"
                aria-pressed="false"
                title="Show password"
              >
                <i id="toggle-password-icon" class="bi bi-eye-fill text-lg" aria-hidden="true"></i>
              </button>
            </div>
            <p id="password-error" class="text-red-600 text-sm mt-1 min-h-[1.25rem]" role="alert" aria-live="polite"></p>
            <p class="login-security-hint text-xs mt-1 text-slate-400 flex items-center gap-1.5">
              <i class="bi bi-shield-lock text-slate-500" aria-hidden="true"></i>
              <span>Secure sign-in. We never share your data.</span>
              <span id="login-secure-connection" class="text-slate-500" style="display:none;">· Secure connection</span>
            </p>
          </div>

          <div class="flex items-start gap-3 login-piece login-piece-5b">
            <input type="checkbox" id="login-remember" name="remember_me" value="1" class="mt-0.5 h-4 w-4 rounded border-gray-300 text-[#1F58C3] focus:ring-[#1F58C3]/50" aria-describedby="login-remember-hint">
            <label for="login-remember" class="flex flex-col gap-0.5 cursor-pointer">
              <span class="text-sm font-semibold text-gray-700">Remember me</span>
              <span id="login-remember-hint" class="text-xs text-gray-500">Keep me signed in for 30 days</span>
            </label>
          </div>

          <button
            type="submit"
            name="login"
            id="login-submit"
            class="btn-shine w-full inline-flex items-center justify-center gap-2 rounded-xl px-6 py-3.5 text-sm transition-all duration-200 disabled:opacity-70 disabled:cursor-not-allowed login-piece login-piece-6"
          >
            <span id="login-submit-text">Login</span>
            <span id="login-submit-spinner" class="hidden" aria-hidden="true"><i class="bi bi-arrow-repeat animate-spin text-lg"></i></span>
            <i id="login-submit-arrow" class="hidden" aria-hidden="true"></i>
          </button>

          <div class="flex items-center gap-3 or-divider login-piece login-piece-7">
            <span class="h-px flex-1"></span>
            <span>OR</span>
            <span class="h-px flex-1"></span>
          </div>

          <div class="login-piece login-piece-8">
            <a href="google_auth.php" class="login-google-btn w-full inline-flex items-center justify-center gap-2 no-underline" aria-label="Continue with Google">
              <img src="https://www.gstatic.com/firebasejs/ui/2.0.0/images/auth/google.svg" alt="" class="login-google-icon w-4 h-4" aria-hidden="true">
              <span>Continue with Google</span>
            </a>
          </div>
        </form>
        </div>
      </div>
    </div>
    </div>
    <footer class="login-footer-copy text-center shrink-0">
      © Copyright 2026 LCRC eReview. All rights reserved. · Built for aspiring CPAs
    </footer>
  </div>
  <div id="login-loading" class="login-loading-backdrop">
    <div class="login-loading-stack">
      <div class="login-loading-orb">
        <div class="login-loading-orb-inner">
          <span></span>
        </div>
      </div>
      <div class="login-loading-label">Signing you in...</div>
    </div>
  </div>
  <div id="login-error-modal" class="login-error-backdrop">
    <div class="login-error-card">
      <div class="login-error-icon">
        <div class="login-error-circle">
          <span class="login-error-line login-error-line-1"></span>
          <span class="login-error-line login-error-line-2"></span>
        </div>
      </div>
      <h2 id="login-error-title" class="text-base font-semibold mb-1.5 text-gray-100 login-error-title">Incorrect credentials</h2>
      <p class="text-xs text-gray-300 mb-2 login-error-text" id="login-error-message" role="alert" aria-live="assertive">
        The email or password you entered is incorrect. Please check your credentials and try again.
      </p>
      <div id="login-error-hint" class="text-xs text-gray-400 mb-4">Check your email and password, or <a href="forgot_password.php" id="login-error-forgot-link" class="text-amber-400 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-400 rounded">reset your password</a>.</div>
      <button id="login-error-close" class="btn-shine inline-flex items-center justify-center px-5 py-2.5 rounded-xl text-sm font-semibold text-white bg-[#1F58C3] shadow-md shadow-[#1F58C3]/30 transition-all duration-200 hover:bg-[#1E40AF] hover:-translate-y-0.5 active:translate-y-0">
        <span>OK, try again</span>
      </button>
    </div>
  </div>
  <?php if ($showRecaptcha): ?>
  <script src="https://www.google.com/recaptcha/api.js?render=<?php echo h($recaptchaSiteKey); ?>" async defer></script>
  <?php endif; ?>
  <script>
    document.addEventListener('DOMContentLoaded', function () {
      const form = document.getElementById('login-form');
      const emailInput = document.getElementById('login-email');
      const passwordInput = document.getElementById('login-password');
      const emailError = document.getElementById('email-error');
      const passwordError = document.getElementById('password-error');
      const togglePasswordButton = document.getElementById('toggle-password');
      const togglePasswordIcon = document.getElementById('toggle-password-icon');
      const loadingOverlay = document.getElementById('login-loading');
      const errorModal = document.getElementById('login-error-modal');
      const errorClose = document.getElementById('login-error-close');
      const errorMessage = document.getElementById('login-error-message');
      const loginCard = document.querySelector('.login-card');
      const submitBtn = document.getElementById('login-submit');
      const submitText = document.getElementById('login-submit-text');
      const submitSpinner = document.getElementById('login-submit-spinner');
      const submitArrow = document.getElementById('login-submit-arrow');

      function setInputState(input, isValid, errorEl, message) {
        if (isValid) {
          input.classList.remove('border-red-500', 'focus:ring-red-500/40');
          input.classList.add('border-gray-200', 'focus:border-[#1F58C3]', 'focus:ring-[#1F58C3]/20');
          input.setAttribute('aria-invalid', 'false');
          if (errorEl) errorEl.textContent = '';
        } else {
          input.classList.add('border-red-500', 'focus:ring-red-500/40');
          input.classList.remove('border-gray-200', 'focus:border-[#1F58C3]', 'focus:ring-[#1F58C3]/20');
          input.setAttribute('aria-invalid', 'true');
          if (errorEl) errorEl.textContent = message || '';
        }
      }

      function validateEmail() {
        const email = emailInput.value.trim();
        if (!email) {
          setInputState(emailInput, false, emailError, 'Please enter your email.');
          return false;
        }
        var re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!re.test(email)) {
          setInputState(emailInput, false, emailError, 'Please enter a valid email address.');
          return false;
        }
        setInputState(emailInput, true, emailError);
        return true;
      }

      function validatePassword() {
        const password = passwordInput.value;
        if (!password) {
          setInputState(passwordInput, false, passwordError, 'Please enter your password.');
          return false;
        }
        setInputState(passwordInput, true, passwordError);
        return true;
      }

      function updateFloatLabel(wrap, input) {
        if (!wrap || !input) return;
        var hasVal = input.value.trim() !== '';
        var isFocused = document.activeElement === input;
        wrap.classList.toggle('has-value', hasVal);
        wrap.classList.toggle('focused', isFocused);
      }
      var emailWrap = document.getElementById('login-email-wrap');
      var passwordWrap = document.getElementById('login-password-wrap');
      [emailInput, passwordInput].forEach(function (input) {
        var wrap = input.id === 'login-email' ? emailWrap : passwordWrap;
        input.addEventListener('focus', function () { updateFloatLabel(wrap, input); });
        input.addEventListener('blur', function () { updateFloatLabel(wrap, input); });
        input.addEventListener('input', function () { updateFloatLabel(wrap, input); });
      });
      updateFloatLabel(emailWrap, emailInput);
      updateFloatLabel(passwordWrap, passwordInput);

      try {
        var lastEmail = localStorage.getItem('lcreview_last_email');
        if (lastEmail && emailInput && !emailInput.value.trim()) {
          emailInput.value = lastEmail;
          updateFloatLabel(emailWrap, emailInput);
        }
      } catch (err) {}

      emailInput.addEventListener('input', function () {
        if (emailError && emailError.textContent) validateEmail();
      });
      emailInput.addEventListener('blur', function () {
        if (emailInput.value.trim()) validateEmail();
      });
      passwordInput.addEventListener('input', function () {
        if (passwordError && passwordError.textContent) validatePassword();
      });

      if (togglePasswordButton && togglePasswordIcon && passwordInput) {
        function updateToggleState() {
          const isPassword = passwordInput.type === 'password';
          togglePasswordIcon.classList.toggle('bi-eye-fill', !isPassword);
          togglePasswordIcon.classList.toggle('bi-eye-slash-fill', isPassword);
          togglePasswordButton.setAttribute('aria-label', isPassword ? 'Show password' : 'Hide password');
          togglePasswordButton.setAttribute('aria-pressed', isPassword ? 'false' : 'true');
          togglePasswordButton.setAttribute('title', isPassword ? 'Show password' : 'Hide password');
        }
        togglePasswordButton.addEventListener('click', function () {
          const isPassword = passwordInput.type === 'password';
          passwordInput.type = isPassword ? 'text' : 'password';
          updateToggleState();
        });
        togglePasswordButton.addEventListener('keydown', function (e) {
          if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            togglePasswordButton.click();
          }
        });
      }

      form.addEventListener('submit', function (event) {
        var isEmailValid = validateEmail();
        var isPasswordValid = validatePassword();
        if (!isEmailValid || !isPasswordValid) {
          event.preventDefault();
          (emailInput.value.trim() ? passwordInput : emailInput).focus();
          return;
        }
        var recaptchaRequired = form.dataset.recaptcha === '1';
        var recaptchaInput = document.getElementById('g-recaptcha-response');
        if (recaptchaRequired && recaptchaInput && !recaptchaInput.value) {
          event.preventDefault();
          var siteKey = form.dataset.recaptchaKey || '';
          function doSubmitWithToken() {
            if (typeof grecaptcha === 'undefined' || !grecaptcha.execute) {
              if (submitText) submitText.textContent = 'Login';
              alert('Security check is loading. Please wait a moment and try again.');
              return;
            }
            grecaptcha.execute(siteKey, { action: 'login' }).then(function (token) {
              recaptchaInput.value = token;
              submitBtn.disabled = true;
              submitBtn.setAttribute('aria-busy', 'true');
              if (submitText) submitText.textContent = 'Signing in…';
              if (submitSpinner) submitSpinner.classList.remove('hidden');
              if (submitArrow) submitArrow.classList.add('hidden');
              if (loadingOverlay) loadingOverlay.classList.add('is-active');
              try { var e = emailInput.value.trim(); if (e) localStorage.setItem('lcreview_last_email', e); } catch (err) {}
              form.submit();
            }, function () {
              if (submitText) submitText.textContent = 'Login';
              alert('Security check failed. Please try again.');
            });
          }
          if (typeof grecaptcha !== 'undefined' && grecaptcha.ready) {
            grecaptcha.ready(doSubmitWithToken);
          } else {
            window.addEventListener('load', function () { doSubmitWithToken(); });
          }
          return;
        }
        submitBtn.disabled = true;
        submitBtn.setAttribute('aria-busy', 'true');
        if (submitText) submitText.textContent = 'Signing in…';
        if (submitSpinner) submitSpinner.classList.remove('hidden');
        if (submitArrow) submitArrow.classList.add('hidden');
        if (loadingOverlay) loadingOverlay.classList.add('is-active');
        try {
          var e = emailInput.value.trim();
          if (e) localStorage.setItem('lcreview_last_email', e);
        } catch (err) {}
      });

      var hasServerError = document.body.dataset.loginError === '1';
      var serverErrorMessage = document.body.dataset.loginErrorMessage || '';
      var serverErrorTitle = document.body.dataset.loginErrorTitle || 'Incorrect credentials';
      var serverErrorType = (document.body.dataset.loginErrorType || '').trim();
      var defaultErrorMessage = errorMessage ? errorMessage.textContent.trim() : '';
      var errorHint = document.getElementById('login-error-hint');

      window.loginDebug = function () {
        var m = document.getElementById('login-error-modal');
        var backdrop = document.querySelector('.login-error-backdrop');
        var card = document.querySelector('.login-error-card');
        var info = {
          page: 'login.php',
          bodyData: {
            loginError: document.body.dataset.loginError,
            loginErrorMessage: (document.body.dataset.loginErrorMessage || '').substring(0, 80),
            loginErrorTitle: document.body.dataset.loginErrorTitle || '',
            loginErrorType: document.body.dataset.loginErrorType || '',
            rateLimitUntil: document.body.dataset.rateLimitUntil || ''
          },
          shouldShowModal: hasServerError,
          modal: {
            found: !!m,
            hasActiveClass: m ? m.classList.contains('is-active') : false,
            display: m ? (window.getComputedStyle(m).display) : 'N/A',
            visibility: m ? (window.getComputedStyle(m).visibility) : 'N/A',
            opacity: backdrop ? (window.getComputedStyle(backdrop).opacity) : 'N/A'
          },
          referrer: document.referrer || '(none)'
        };
        console.group('LCRC Login Debug');
        console.log('Summary:', info);
        console.log('Paste this in console to re-run: loginDebug()');
        console.groupEnd();
        return info;
      };

      if (hasServerError) {
        var titleEl = document.getElementById('login-error-title');
        if (titleEl) titleEl.textContent = serverErrorTitle;
        if (errorMessage) {
          var normalizedMessage = serverErrorMessage.trim();
          errorMessage.textContent = normalizedMessage || defaultErrorMessage || 'The email or password you entered is incorrect. Please check your credentials and try again.';
        }
        if (errorHint) {
          if (serverErrorType === 'google_no_account') {
            errorHint.innerHTML = 'Create an account with your email first, then you can <a href="google_auth.php" class="text-amber-400 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-400 rounded">sign in with Google</a>. <a href="registration.php" class="text-amber-400 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-400 rounded ml-1">Register here</a>.';
          } else if (serverErrorType === 'not_approved') {
            errorHint.textContent = 'An admin must approve your account before you can sign in. Please try again later or contact support.';
          } else {
            errorHint.innerHTML = 'Check your email and password, or <a href="forgot_password.php" id="login-error-forgot-link" class="text-amber-400 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-400 rounded">reset your password</a>.';
          }
        }
        if (errorModal) errorModal.classList.add('is-active');
        if (loginCard) {
          loginCard.classList.remove('login-card--shake');
          void loginCard.offsetWidth;
          loginCard.classList.add('login-card--shake');
          setTimeout(function () { loginCard.classList.remove('login-card--shake'); }, 460);
        }
        setTimeout(function () {
          if (errorClose) errorClose.focus();
        }, 120);
      }

      if (typeof window.loginDebug === 'function') {
        setTimeout(window.loginDebug, 200);
      }

      if (errorClose && errorModal) {
        errorClose.addEventListener('click', function () {
          errorModal.classList.remove('is-active');
          if (loadingOverlay) loadingOverlay.classList.remove('is-active');
          if (emailInput) emailInput.focus();
        });
        var modalFocusables = errorModal ? [].slice.call(errorModal.querySelectorAll('button, [href]')).filter(function (el) { return el.getAttribute('tabindex') !== '-1' && !el.disabled; }) : [];
        errorModal.addEventListener('keydown', function (e) {
          if (e.key !== 'Tab' || !errorModal.classList.contains('is-active')) return;
          var first = modalFocusables[0];
          var last = modalFocusables[modalFocusables.length - 1];
          if (e.shiftKey) {
            if (document.activeElement === first) { e.preventDefault(); last.focus(); }
          } else {
            if (document.activeElement === last) { e.preventDefault(); first.focus(); }
          }
        });
      }

      if (location.protocol === 'https:') {
        var secureEl = document.getElementById('login-secure-connection');
        if (secureEl) secureEl.style.display = 'inline';
      }

      // Rate limit countdown
      var rateLimitUntil = document.body.getAttribute('data-rate-limit-until');
      if (rateLimitUntil) {
        var untilTs = parseInt(rateLimitUntil, 10) * 1000;
        var countdownEl = document.getElementById('login-ratelimit-countdown');
        function updateCountdown() {
          var now = Date.now();
          var rem = Math.max(0, Math.floor((untilTs - now) / 1000));
          if (rem <= 0) {
            if (window.loginRateLimitTimer) clearInterval(window.loginRateLimitTimer);
            if (countdownEl) countdownEl.textContent = 'You can try again now';
            setTimeout(function () { window.location.reload(); }, 800);
            return;
          }
          var m = Math.floor(rem / 60);
          var s = rem % 60;
          if (countdownEl) countdownEl.textContent = m + ' min ' + s + ' sec';
        }
        updateCountdown();
        window.loginRateLimitTimer = setInterval(updateCountdown, 1000);
      }
    });
  </script>
</body>
</html>
