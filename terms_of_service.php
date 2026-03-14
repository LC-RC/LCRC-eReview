<?php
$pageTitle = 'Terms of Service';
if (!function_exists('h')) { function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_public.php'; ?>
</head>
<body class="min-h-screen bg-slate-50 font-sans antialiased">
  <div class="max-w-3xl mx-auto px-4 py-12">
    <h1 class="text-2xl font-bold text-slate-900 mb-4">Terms of Service</h1>
    <p class="text-slate-600 mb-4">Welcome to LCRC eReview. By using our service, you agree to these terms.</p>
    <p class="text-slate-600 mb-6">This page will be updated with full terms. Please contact us for the complete document.</p>
    <a href="registration.php" class="text-[#1F58C3] font-semibold hover:underline">← Back to Registration</a>
  </div>
</body>
</html>
