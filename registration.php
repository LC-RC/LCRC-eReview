<?php
require_once 'session_config.php';
require_once 'auth.php';
require_once __DIR__ . '/includes/registration_school_options.php';

$schoolDropdownOptions = ereview_get_registration_school_dropdown_options($conn);

if (isLoggedIn() && verifySession()) {
    header('Location: ' . dashboardUrlForRole(getCurrentUserRole()));
    exit;
}

$pageTitle = 'Register';
$error = $_SESSION['error'] ?? null;
unset($_SESSION['error']);
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
  <?php require_once __DIR__ . '/includes/head_public.php'; ?>
  <style>
    /* === Modern design tokens (registration) === */
    body.registration-prototype {
      --reg-bg: #0b0f1a;
      --reg-surface: #111827;
      --reg-surface-elevated: #1a2332;
      --reg-input-bg: rgba(26, 35, 50, 0.9);
      --reg-border: rgba(255, 255, 255, 0.08);
      --reg-border-focus: #1F58C3;
      --reg-primary: #1F58C3;
      --reg-primary-hover: #2563eb;
      --reg-accent: #F59E0B;
      --reg-text: #f1f5f9;
      --reg-text-label: #e2e8f0;
      --reg-text-muted: #94a3b8;
      --reg-radius: 0.75rem;
      --reg-radius-lg: 1rem;
      --reg-space: 1.5rem;
      --reg-input-height: 2.875rem;
      --reg-shadow-glow: 0 0 40px rgba(31, 88, 195, 0.12);
    }
    /* === Frame 8: split-panel layout, scrollable form === */
    body.registration-prototype {
      margin: 0;
      min-height: 100vh;
      color: #e5e7eb;
      display: flex;
      overflow: hidden;
    }
    .reg-frame8-layout {
      display: flex;
      width: 100%;
      min-height: 100vh;
    }
    .reg-frame8-left {
      flex: 0 0 50%;
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 2.5rem 3rem;
      position: relative;
      overflow: hidden;
      background: linear-gradient(155deg, #0a0d14 0%, #0f1419 25%, #131922 50%, #0d1117 75%, #06080c 100%);
    }
    .reg-frame8-left::before {
      content: '';
      position: absolute;
      inset: -30%;
      background:
        radial-gradient(ellipse 80% 60% at 85% 10%, rgba(59, 130, 246, 0.12) 0%, transparent 45%),
        radial-gradient(ellipse 70% 80% at 90% 55%, rgba(30, 64, 175, 0.08) 0%, transparent 50%),
        radial-gradient(ellipse 60% 70% at 15% 85%, rgba(31, 88, 195, 0.06) 0%, transparent 45%);
      animation: reg-left-glow-drift 20s ease-in-out infinite alternate;
      pointer-events: none;
      z-index: 0;
    }
    .reg-frame8-left::after {
      content: '';
      position: absolute;
      top: -40%;
      right: -30%;
      width: 100%;
      height: 100%;
      background: radial-gradient(circle at 70% 30%, rgba(255, 255, 255, 0.04) 0%, transparent 50%);
      border-radius: 50%;
      filter: blur(80px);
      animation: reg-left-shine 25s ease-in-out infinite alternate;
      pointer-events: none;
      z-index: 0;
    }
    .reg-grok-blob {
      position: absolute;
      border-radius: 50%;
      filter: blur(70px);
      pointer-events: none;
      z-index: 0;
    }
    .reg-grok-blob-1 {
      width: 55%;
      height: 55%;
      top: 10%;
      right: -15%;
      background: radial-gradient(circle, rgba(71, 85, 105, 0.25) 0%, rgba(30, 41, 59, 0.1) 40%, transparent 70%);
      animation: reg-grok-float-1 28s ease-in-out infinite;
    }
    .reg-grok-blob-2 {
      width: 45%;
      height: 45%;
      bottom: 5%;
      left: -10%;
      background: radial-gradient(circle, rgba(59, 130, 246, 0.08) 0%, rgba(30, 64, 175, 0.04) 50%, transparent 70%);
      animation: reg-grok-float-2 32s ease-in-out infinite;
    }
    .reg-grok-blob-3 {
      width: 40%;
      height: 40%;
      top: 50%;
      left: 20%;
      background: radial-gradient(circle, rgba(148, 163, 184, 0.06) 0%, transparent 60%);
      animation: reg-grok-float-3 24s ease-in-out infinite;
    }
    @keyframes reg-left-glow-drift {
      0% { opacity: 0.9; transform: scale(1) translate(0, 0); }
      100% { opacity: 1; transform: scale(1.1) translate(-3%, -2%); }
    }
    @keyframes reg-left-shine {
      0% { opacity: 0.6; transform: translate(4%, 2%) scale(1); }
      100% { opacity: 1; transform: translate(-4%, -4%) scale(1.08); }
    }
    @keyframes reg-grok-float-1 {
      0%, 100% { transform: translate(0, 0) scale(1); opacity: 0.9; }
      33% { transform: translate(-8%, 5%) scale(1.05); opacity: 1; }
      66% { transform: translate(5%, -6%) scale(0.98); opacity: 0.85; }
    }
    @keyframes reg-grok-float-2 {
      0%, 100% { transform: translate(0, 0) scale(1); opacity: 0.85; }
      50% { transform: translate(6%, -8%) scale(1.08); opacity: 1; }
    }
    @keyframes reg-grok-float-3 {
      0%, 100% { transform: translate(0, 0) scale(1); opacity: 0.8; }
      50% { transform: translate(-5%, 4%) scale(1.06); opacity: 1; }
    }
    .reg-frame8-left-bg-shape {
      position: absolute;
      top: 50%;
      left: 50%;
      width: 72%;
      max-width: 420px;
      pointer-events: none;
      z-index: 0;
      animation: reg-left-logo-float 45s ease-in-out infinite;
    }
    .reg-frame8-left-bg-shape .reg-left-logo-svg {
      width: 100%;
      height: auto;
      display: block;
      filter: drop-shadow(0 0 24px rgba(147, 197, 253, 0.12));
    }
    .reg-frame8-left-bg-shape .reg-left-logo-g {
      animation: reg-left-logo-pulse 6s ease-in-out infinite;
    }
    .reg-frame8-left-bg-shape .reg-paper-body {
      stroke-dasharray: 200;
      stroke-dashoffset: 200;
      animation: reg-paper-draw 10s ease-in-out infinite;
    }
    .reg-frame8-left-bg-shape .reg-paper-fold {
      stroke-dasharray: 60;
      stroke-dashoffset: 60;
      animation: reg-paper-fold-draw 10s ease-in-out 0.3s infinite;
    }
    @keyframes reg-paper-draw {
      0% { stroke-dashoffset: 200; }
      25% { stroke-dashoffset: 0; }
      75% { stroke-dashoffset: 0; }
      100% { stroke-dashoffset: 200; }
    }
    @keyframes reg-paper-fold-draw {
      0% { stroke-dashoffset: 60; }
      25% { stroke-dashoffset: 0; }
      75% { stroke-dashoffset: 0; }
      100% { stroke-dashoffset: 60; }
    }
    @keyframes reg-left-logo-float {
      0%, 100% { transform: translate(-50%, -50%) scale(0.98) rotate(0deg); opacity: 0.5; }
      12.5%    { transform: translate(-75%, -65%) scale(1) rotate(-3deg); opacity: 0.52; }
      25%      { transform: translate(-80%, -50%) scale(1.02) rotate(2deg); opacity: 0.55; }
      37.5%    { transform: translate(-70%, -30%) scale(0.98) rotate(-2deg); opacity: 0.52; }
      50%      { transform: translate(-50%, -45%) scale(1.04) rotate(1deg); opacity: 0.5; }
      62.5%    { transform: translate(-25%, -35%) scale(1) rotate(-1deg); opacity: 0.52; }
      75%      { transform: translate(-20%, -55%) scale(0.98) rotate(3deg); opacity: 0.55; }
      87.5%    { transform: translate(-35%, -70%) scale(1.02) rotate(-2deg); opacity: 0.52; }
    }
    @keyframes reg-left-logo-pulse {
      0%, 100% { opacity: 0.9; }
      50% { opacity: 1; }
    }
    .reg-frame8-left .reg-frame8-hero {
      position: relative;
      z-index: 1;
    }
    .reg-frame8-hero {
      max-width: 420px;
      color: #e2e8f0;
    }
    .reg-left-statement {
      width: 100%;
      max-width: 380px;
    }
    .reg-left-statement-inner {
      padding: 1.75rem 1.5rem;
      background: rgba(15, 23, 42, 0.35);
      backdrop-filter: blur(20px);
      -webkit-backdrop-filter: blur(20px);
      border: 1px solid rgba(255, 255, 255, 0.05);
      border-radius: 0.875rem;
      box-shadow: 0 8px 32px -16px rgba(0, 0, 0, 0.35);
    }
    .reg-left-statement-headline {
      margin: 0 0 1rem;
      font-size: 1.0625rem;
      font-weight: 600;
      line-height: 1.5;
      color: rgba(241, 245, 249, 0.95);
      letter-spacing: -0.015em;
    }
    .reg-left-statement-metrics {
      margin: 0 0 0.5rem;
      font-size: 0.9375rem;
      font-weight: 600;
      letter-spacing: 0.01em;
      color: rgba(125, 211, 252, 0.9);
      line-height: 1.4;
      min-height: 1.5em;
    }
    .reg-left-statement-blurb {
      margin: 0;
      font-size: 0.8125rem;
      font-weight: 500;
      color: rgba(148, 163, 184, 0.85);
      line-height: 1.5;
      min-height: 1.5em;
    }
    @media (max-width: 768px) {
      .reg-frame8-left {
        padding: 1.5rem 1.25rem;
        align-items: center;
        justify-content: center;
      }
      .reg-left-statement-inner { padding: 1.5rem 1.25rem; }
      .reg-left-statement-headline { font-size: 1rem; margin-bottom: 1rem; }
      .reg-left-statement-metrics { font-size: 0.9375rem; }
      .reg-left-statement-blurb { font-size: 0.8125rem; }
    }
    .reg-frame8-right {
      flex: 0 0 50%;
      background: var(--reg-bg);
      background-image: radial-gradient(ellipse 120% 80% at 50% -20%, rgba(31, 88, 195, 0.15) 0%, transparent 50%),
                        linear-gradient(180deg, var(--reg-bg) 0%, #0f172a 100%);
      min-height: 100vh;
      display: flex;
      flex-direction: column;
      overflow-y: auto;
      overflow-x: hidden;
      position: relative;
    }
    .reg-frame8-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 1rem 1.5rem 0.6rem;
      flex-shrink: 0;
    }
    .reg-frame8-brand {
      font-size: 0.8125rem;
      font-weight: 600;
      letter-spacing: 0.02em;
      color: var(--reg-text-muted);
    }
    .reg-frame8-brand .blue { color: #1F58C3; }
    .reg-frame8-brand .amber { color: #F59E0B; }
    @media (max-width: 480px) {
      .reg-frame8-brand { font-size: 0.875rem; font-weight: 700; }
    }
    .reg-frame8-logo-right {
      height: 28px;
      width: auto;
      max-width: 110px;
      object-fit: contain;
      object-position: right center;
    }
    .reg-frame8-main {
      flex: 1;
      display: flex;
      flex-direction: column;
      min-height: 0;
      padding: 0 1.5rem 0.75rem;
      max-width: 100%;
      width: 100%;
    }
    .reg-frame8-title {
      font-size: clamp(1.4rem, 3.5vw, 1.75rem);
      font-weight: 800;
      color: var(--reg-text);
      margin: 0 0 0.35rem;
      letter-spacing: -0.025em;
      line-height: 1.25;
      text-align: center;
    }
    .reg-frame8-subtitle {
      font-size: 0.8125rem;
      color: var(--reg-text-muted);
      margin: 0 0 1.5rem;
      text-align: center;
      line-height: 1.4;
    }
    .reg-info-rotating {
      transition: opacity 0.45s cubic-bezier(0.4, 0, 0.2, 1);
    }
    .reg-frame8-info-block {
      flex-shrink: 0;
      padding: 0.75rem 1rem 0.75rem 1.25rem;
      margin-bottom: 1rem;
      background: rgba(31, 88, 195, 0.06);
      border: 1px solid rgba(31, 88, 195, 0.18);
      border-left: 3px solid var(--reg-primary);
      border-radius: var(--reg-radius);
      text-align: center;
      backdrop-filter: blur(8px);
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 0.5rem;
    }
    .reg-frame8-info-block .reg-frame8-info-icon {
      flex-shrink: 0;
      font-size: 1rem;
      color: var(--reg-primary);
    }
    .reg-frame8-info-block .reg-frame8-info-line { margin: 0; font-size: 0.75rem; color: var(--reg-text-muted); line-height: 1.4; text-align: center; }
    .reg-frame8-info-block .reg-frame8-info-metrics { margin: 0.35rem 0 0; font-size: 0.75rem; color: #7dd3fc; font-weight: 600; letter-spacing: 0.02em; text-align: center; }
    /* Info block below submit: compact so form content area gets more height */
    .reg-frame8-info-block.reg-info-below-submit {
      margin-top: 0.5rem;
      margin-bottom: 0;
      padding: 0.35rem 0.5rem 0.35rem 0.75rem;
      background: rgba(31, 88, 195, 0.04);
      border: 1px solid rgba(31, 88, 195, 0.1);
      border-left: 2px solid rgba(31, 88, 195, 0.25);
    }
    .reg-frame8-info-block.reg-info-below-submit .reg-frame8-info-icon { font-size: 0.75rem; color: rgba(31, 88, 195, 0.6); }
    .reg-frame8-info-block.reg-info-below-submit .reg-frame8-info-line { font-size: 0.625rem; color: var(--reg-text-muted); opacity: 0.85; line-height: 1.3; }
    .reg-frame8-info-block.reg-info-below-submit .reg-frame8-info-metrics { font-size: 0.625rem; color: rgba(125, 211, 252, 0.8); }
    #reg-form {
      display: flex;
      flex-direction: column;
      flex: 1;
      min-height: 0;
    }
    #reg-form .reg-form-progress { flex-shrink: 0; }
    .reg-frame8-form-fixed-bottom {
      flex-shrink: 0;
      padding-top: 0.5rem;
      margin-top: 0.25rem;
    }
    .reg-frame8-form-fixed-bottom .reg-submit { margin-top: 0.75rem; }
    .reg-frame8-form-scroll {
      flex: 1 1 0;
      min-height: 0;
      overflow-y: auto;
      overflow-x: hidden;
      padding: 1.75rem 0.5rem 0.25rem 0;
      -webkit-overflow-scrolling: touch;
      background: rgba(11, 15, 26, 0.35);
      -webkit-backdrop-filter: blur(8px);
      backdrop-filter: blur(8px);
      border-radius: var(--reg-radius-lg);
      border: 1px solid rgba(255, 255, 255, 0.03);
    }
    .reg-frame8-form-scroll::-webkit-scrollbar { width: 6px; }
    .reg-frame8-form-scroll::-webkit-scrollbar-track { background: rgba(31, 88, 195, 0.1); border-radius: 3px; }
    .reg-frame8-form-scroll::-webkit-scrollbar-thumb { background: rgba(31, 88, 195, 0.4); border-radius: 3px; }
    .reg-frame8-form-scroll::-webkit-scrollbar-thumb:hover { background: rgba(31, 88, 195, 0.6); }
    .reg-section-label {
      font-size: 0.75rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.06em;
      color: var(--reg-text-muted);
      margin: 0 0 0.5rem;
      padding: 0.4rem 0 0.4rem 0.75rem;
      border-left: 3px solid rgba(31, 88, 195, 0.4);
      display: flex;
      align-items: center;
      gap: 0.5rem;
      line-height: 1.4;
      transition: color 0.3s ease, border-left-color 0.3s ease, background 0.3s ease, box-shadow 0.3s ease;
      border-radius: 0 0.375rem 0.375rem 0;
    }
    .reg-section-label:not(:first-child) { margin-top: 2rem; }
    #reg-label-account { margin-bottom: 1.5rem; }
    .reg-section-label--complete {
      color: #86efac;
      border-left-color: #22c55e;
      background: rgba(34, 197, 94, 0.08);
      box-shadow: 0 0 0 1px rgba(34, 197, 94, 0.15);
    }
    .reg-section-label--complete .reg-section-label-check {
      opacity: 1;
      color: #22c55e;
    }
    .reg-section-label-check {
      font-size: 0.875rem;
      opacity: 0;
      color: #22c55e;
      transition: opacity 0.3s ease;
    }
    /* Unified top labels for selects and file upload (not float labels) */
    .reg-top-label {
      display: block;
      font-size: 0.875rem;
      font-weight: 500;
      color: var(--reg-text-label);
      margin-bottom: 0.35rem;
      line-height: 1.4;
    }
    .reg-inline-error {
      display: block;
      font-size: 0.75rem;
      color: #f87171;
      margin-top: 0.25rem;
      min-height: 1.25rem;
    }
    .reg-inline-error:empty { display: none; }
    .reg-confirm-success {
      display: inline-flex;
      align-items: center;
      gap: 0.35rem;
      font-size: 0.75rem;
      font-weight: 500;
      color: #22c55e;
      margin-top: 0.25rem;
    }
    .reg-confirm-success.hidden { display: none !important; }
    .reg-confirm-success i { font-size: 0.875rem; flex-shrink: 0; }
    .reg-security-subheading {
      font-size: 0.8125rem;
      color: var(--reg-text-muted);
      margin: 0 0 0.5rem;
      font-weight: 500;
      line-height: 1.5;
    }
    .reg-security-hint {
      font-size: 0.75rem;
      color: var(--reg-text-muted);
      margin: 0 0 0.75rem;
      opacity: 0.9;
      line-height: 1.5;
    }
    .reg-frame8-grid { display: grid; gap: var(--reg-space); }
    .reg-frame8-grid-2 { grid-template-columns: 1fr 1fr; }
    .reg-frame8-grid-2 .reg-frame8-full { grid-column: 1 / -1; }
    @media (max-width: 640px) {
      .reg-frame8-grid-2 { grid-template-columns: 1fr; }
    }
    .reg-frame8-footer {
      flex-shrink: 0;
      display: flex;
      flex-direction: row;
      flex-wrap: nowrap;
      align-items: center;
      justify-content: space-between;
      gap: 0.75rem;
      width: 100%;
      padding: 0.5rem 1.5rem 0.75rem;
    }
    .reg-frame8-footer-terms {
      font-size: 0.5625rem;
      color: #94a3b8;
      line-height: 1.4;
      margin: 0;
      text-align: left;
      flex-shrink: 0;
      white-space: nowrap;
    }
    body.registration-prototype .reg-frame8-footer-terms a {
      color: #F59E0B;
      text-decoration: none;
      transition: color 0.2s ease, text-decoration 0.2s ease, opacity 0.2s ease;
      font-weight: 700;
    }
    body.registration-prototype .reg-frame8-footer-terms a:hover {
      text-decoration: underline;
      color: #FCD34D;
      opacity: 1;
    }
    body.registration-prototype .reg-frame8-footer-terms a:focus-visible {
      outline: 2px solid #F59E0B;
      outline-offset: 2px;
    }
    .reg-frame8-footer-copy strong { font-weight: 700; }
    .reg-frame8-footer-copy {
      font-size: 0.5625rem;
      color: #94a3b8;
      margin: 0;
      text-align: right;
      flex-shrink: 0;
      white-space: nowrap;
    }
    @media (max-width: 900px) {
      .reg-frame8-footer { flex-wrap: wrap; }
      .reg-frame8-footer-terms, .reg-frame8-footer-copy { white-space: normal; }
    }
    @media (max-width: 640px) {
      .reg-frame8-footer { flex-direction: column; align-items: flex-start; padding: 0.5rem 1rem 0.75rem; gap: 0.25rem; }
    }
    @media (max-width: 768px) {
      .reg-frame8-layout { flex-direction: column; }
      .reg-frame8-left { flex: 0 0 100px; min-height: 100px; }
      .reg-frame8-right { flex: 1; min-height: 0; }
      .reg-frame8-main { padding: 0 1.25rem 1rem 11rem 1rem; }
      .reg-frame8-form-scroll { max-height: 65vh; padding-bottom: 1.5rem; }
      .reg-frame8-form-fixed-bottom {
        position: fixed;
        bottom: 0;
        left: 0;
        right: 0;
        z-index: 40;
        background: var(--reg-bg);
        padding: 0.5rem 1rem 0.75rem;
        margin: 0;
        border-top: 1px solid rgba(255, 255, 255, 0.06);
        box-shadow: 0 -4px 20px rgba(0, 0, 0, 0.35);
      }
    }
    /* === Form card & inputs (keep existing styles, scope to right panel) === */
    body.registration-prototype .animated-bg { background: #0b1220 !important; }
    body.registration-prototype .animated-bg::before,
    body.registration-prototype .animated-bg::after { display: none !important; }
    body.registration-prototype .auth-corner-decor::before,
    body.registration-prototype .auth-corner-decor::after {
      width: 80px; height: 52px;
      background: rgba(15, 23, 42, 0.9);
      border: 1px solid rgba(31, 88, 195, 0.2);
      border-radius: 6px;
    }
    body.registration-prototype .auth-corner-dot {
      width: 4px; height: 4px;
      background: rgba(245, 158, 11, 0.9);
      box-shadow: 0 0 8px rgba(245, 158, 11, 0.5);
    }
    body.registration-prototype .auth-corner-dot.blue {
      background: rgba(31, 88, 195, 0.9);
      box-shadow: 0 0 8px rgba(31, 88, 195, 0.5);
    }
    body.registration-prototype .circuit-bg {
      position: fixed; inset: 0; z-index: 0; pointer-events: none;
      background-image:
        linear-gradient(rgba(31, 88, 195, 0.05) 1px, transparent 1px),
        linear-gradient(90deg, rgba(31, 88, 195, 0.05) 1px, transparent 1px);
      background-size: 32px 32px;
      animation: reg-bg-grid-pulse 12s ease-in-out infinite;
    }
    @keyframes reg-bg-grid-pulse { 0%, 100% { opacity: 0.6; } 50% { opacity: 1; } }
    body.registration-prototype .login-bg-animation {
      position: fixed; inset: 0; z-index: 0; pointer-events: none; overflow: hidden;
    }
    .login-bg-node {
      position: absolute; width: 6px; height: 6px; border-radius: 50%;
      animation: reg-bg-float 24s ease-in-out infinite;
    }
    .login-bg-node--blue {
      background: rgba(31, 88, 195, 0.35);
      box-shadow: 0 0 12px rgba(31, 88, 195, 0.25);
      left: var(--x); top: var(--y);
      animation-delay: var(--delay); animation-duration: var(--dur);
    }
    .login-bg-node--gold {
      background: rgba(245, 158, 11, 0.3);
      box-shadow: 0 0 10px rgba(245, 158, 11, 0.2);
      left: var(--x); top: var(--y);
      animation-delay: var(--delay); animation-duration: var(--dur);
    }
    .login-bg-node--white {
      background: rgba(255, 255, 255, 0.08);
      box-shadow: 0 0 8px rgba(255, 255, 255, 0.06);
      left: var(--x); top: var(--y);
      animation-delay: var(--delay); animation-duration: var(--dur);
    }
    @keyframes reg-bg-float {
      0%, 100% { transform: translate(0, 0) scale(1); opacity: 0.7; }
      25% { transform: translate(8px, -12px) scale(1.05); opacity: 1; }
      50% { transform: translate(-5px, 6px) scale(0.95); opacity: 0.8; }
      75% { transform: translate(-10px, -5px) scale(1.02); opacity: 0.9; }
    }
    .login-bg-lines { position: absolute; inset: 0; opacity: 0.4; }
    .login-bg-lines svg { width: 100%; height: 100%; }
    .login-bg-lines .line {
      fill: none; stroke-width: 0.5; stroke-linecap: round;
      animation: reg-bg-line-flow 20s linear infinite;
    }
    .login-bg-lines .line--blue { stroke: rgba(31, 88, 195, 0.2); }
    .login-bg-lines .line--gold { stroke: rgba(245, 158, 11, 0.15); animation-delay: -5s; }
    .login-bg-lines .line--white { stroke: rgba(255, 255, 255, 0.06); animation-delay: -10s; }
    @keyframes reg-bg-line-flow { 0% { stroke-dashoffset: 0; } 100% { stroke-dashoffset: -200; } }
    .login-bg-blob {
      position: fixed; left: 50%; top: 50%; transform: translate(-50%, -50%);
      width: min(120vw, 680px); height: min(80vw, 520px);
      border-radius: 50% 40% 60% 50% / 50% 60% 40% 50%;
      background: radial-gradient(ellipse at 30% 20%, rgba(31, 88, 195, 0.18) 0%, transparent 50%),
                  radial-gradient(ellipse at 70% 80%, rgba(245, 158, 11, 0.08) 0%, transparent 45%),
                  radial-gradient(ellipse at 50% 50%, rgba(30, 58, 138, 0.12) 0%, transparent 55%);
      filter: blur(48px); z-index: 0; pointer-events: none;
      animation: login-blob-drift 20s ease-in-out infinite;
    }
    @keyframes login-blob-drift {
      0%, 100% { transform: translate(-50%, -50%) scale(1) rotate(0deg); }
      33% { transform: translate(-52%, -48%) scale(1.05) rotate(2deg); }
      66% { transform: translate(-48%, -52%) scale(0.98) rotate(-1deg); }
    }
    .login-cpa-visual { position: fixed; inset: 0; z-index: 0; pointer-events: none; opacity: 0.12; }
    .login-cpa-visual svg { width: 100%; height: 100%; object-fit: cover; }
    .login-cpa-visual .cpa-ring { fill: none; stroke-width: 1.5; stroke-linecap: round; stroke: rgba(31, 88, 195, 0.5); animation: cpa-ring-pulse 8s ease-in-out infinite; }
    .login-cpa-visual .cpa-line { fill: none; stroke: rgba(31, 88, 195, 0.35); stroke-width: 0.8; stroke-dasharray: 4 6; animation: cpa-line-flow 25s linear infinite; }
    @keyframes cpa-ring-pulse { 0%, 100% { opacity: 0.6; stroke-dashoffset: 0; } 50% { opacity: 1; stroke-dashoffset: -30; } }
    @keyframes cpa-line-flow { 0% { stroke-dashoffset: 0; } 100% { stroke-dashoffset: -200; } }
    .login-cashflow-path { position: fixed; inset: 0; z-index: 0; pointer-events: none; opacity: 0.2; }
    .login-cashflow-path svg { width: 100%; height: 100%; }
    .login-cashflow-path .path { fill: none; stroke: rgba(245, 158, 11, 0.4); stroke-width: 1; stroke-dasharray: 120 80; animation: login-cashflow-draw 18s linear infinite; }
    @keyframes login-cashflow-draw { 0% { stroke-dashoffset: 0; } 100% { stroke-dashoffset: -400; } }
    /* Staggered form entrance */
    body.registration-prototype .reg-frame8-form-scroll .reg-section {
      opacity: 0;
      transform: translateY(12px);
      animation: reg-section-in 0.45s cubic-bezier(0.22, 1, 0.36, 1) forwards;
    }
    body.registration-prototype .reg-frame8-form-scroll .reg-section:nth-child(1) { animation-delay: 0.05s; }
    body.registration-prototype .reg-frame8-form-scroll .reg-section:nth-child(2) { animation-delay: 0.1s; }
    body.registration-prototype .reg-frame8-form-scroll .reg-section:nth-child(3) { animation-delay: 0.15s; }
    body.registration-prototype .reg-frame8-form-scroll .reg-section:nth-child(4) { animation-delay: 0.2s; }
    body.registration-prototype .reg-frame8-form-scroll .reg-section:nth-child(5) { animation-delay: 0.25s; }
    body.registration-prototype .reg-frame8-form-scroll .reg-section:nth-child(6) { animation-delay: 0.3s; }
    body.registration-prototype .reg-frame8-form-scroll .reg-section:nth-child(7) { animation-delay: 0.35s; }
    body.registration-prototype .reg-frame8-form-scroll .reg-section:nth-child(8) { animation-delay: 0.4s; }
    body.registration-prototype .reg-frame8-form-scroll .reg-section:nth-child(9) { animation-delay: 0.45s; }
    body.registration-prototype .reg-frame8-form-scroll .reg-section:nth-child(10) { animation-delay: 0.5s; }
    @keyframes reg-section-in {
      to { opacity: 1; transform: translateY(0); }
    }
    @media (prefers-reduced-motion: reduce) {
      body.registration-prototype .reg-frame8-form-scroll .reg-section {
        opacity: 1;
        transform: none;
        animation: none;
      }
      body.registration-prototype .circuit-bg { animation: none; opacity: 0.7; }
      .login-bg-node { animation: none; }
      .login-bg-lines .line { animation: none; }
      .login-bg-blob { animation: none; }
      .login-cpa-visual .cpa-ring, .login-cpa-visual .cpa-line { animation: none; }
      .login-cashflow-path .path { animation: none; }
      body.registration-prototype .login-card .reg-submit:hover,
      body.registration-prototype .login-card .reg-submit:active { transform: none; }
    }
    body.registration-prototype .auth-card-wrap { max-width: 500px !important; }
    body.registration-prototype .login-card {
      background: linear-gradient(180deg, #111827 0%, #0f172a 100%) !important;
      border: 1px solid rgba(255, 255, 255, 0.06) !important;
      box-shadow: 0 24px 48px -20px rgba(0,0,0,0.55), 0 0 0 1px rgba(31, 88, 195, 0.1), 0 1px 0 rgba(255,255,255,0.03) inset !important;
      border-radius: 1rem !important;
      padding: 0.9rem 1.5rem 1.1rem !important;
      transition: box-shadow 0.25s ease;
    }
    body.registration-prototype .login-card:focus-within {
      box-shadow: 0 24px 48px -20px rgba(0,0,0,0.55), 0 0 0 1px rgba(31, 88, 195, 0.18), 0 0 40px rgba(31, 88, 195, 0.12), 0 1px 0 rgba(255,255,255,0.03) inset !important;
    }
    body.registration-prototype .reg-section { margin-top: 1.25rem !important; }
    body.registration-prototype .reg-section:first-of-type { margin-top: 0 !important; }
    .reg-section-divider {
      height: 1px;
      background: rgba(255, 255, 255, 0.06);
      margin: 1.5rem 0 0;
    }
    body.registration-prototype .login-card .reg-intro { color: #94a3b8; font-size: 0.8125rem; margin-top: 0.375rem; }
    body.registration-prototype .login-header { margin-bottom: 1rem !important; }
    body.registration-prototype .login-logo-wrap { margin-bottom: 0.5rem !important; }
    body.registration-prototype .login-logo-hover { transition: transform 0.2s ease, filter 0.2s ease; }
    body.registration-prototype .login-logo-hover:hover { transform: scale(1.03); filter: drop-shadow(0 0 8px rgba(31, 88, 195, 0.3)); }
    body.registration-prototype .login-logo-img {
      height: 2.5rem; width: auto; max-width: 120px;
      object-fit: contain; object-position: center; display: block;
    }
    body.registration-prototype .login-card .brand-text {
      color: #fff; font-size: 1rem; font-weight: 700;
    }
    body.registration-prototype .login-card .brand-text .blue { color: #1F58C3; }
    body.registration-prototype .login-card .brand-text .amber { color: #F59E0B; }
    body.registration-prototype .login-welcome { margin-bottom: 1rem !important; }
    body.registration-prototype .login-card .subtext { color: #94a3b8; font-size: 0.8125rem; }
    body.registration-prototype .login-card .subtext a { color: #F59E0B !important; }
    body.registration-prototype .login-card .subtext a:hover { color: #FCD34D !important; }
    body.registration-prototype .login-card h1 {
      color: #fff !important; font-size: 1.25rem !important; font-weight: 700;
      letter-spacing: -0.025em;
    }
    body.registration-prototype .login-card label { color: var(--reg-text-label) !important; font-weight: 500; }
    /* Registration form fields: apply to both .login-card (legacy) and .reg-frame8-main (split layout) so content is always readable */
    body.registration-prototype .login-card .auth-input,
    body.registration-prototype .reg-frame8-main .auth-input {
      background: #1a2332 !important;
      background-image: linear-gradient(180deg, rgba(255,255,255,0.04) 0%, transparent 100%) !important;
      border: 1px solid rgba(255, 255, 255, 0.12) !important;
      color: #f1f5f9 !important;
      -webkit-text-fill-color: #f1f5f9 !important;
      caret-color: #60a5fa !important;
      border-radius: var(--reg-radius) !important;
      padding: 0.75rem 1.25rem !important;
      min-height: var(--reg-input-height) !important;
      font-size: 0.9375rem !important;
      font-weight: 500 !important;
      letter-spacing: 0.01em !important;
      line-height: 1.45 !important;
      box-shadow: 0 1px 3px rgba(0,0,0,0.35) inset, 0 1px 0 rgba(255,255,255,0.03) !important;
      transition: border-color 0.2s ease, box-shadow 0.2s ease, background 0.2s ease !important;
    }
    body.registration-prototype .login-card .auth-input::placeholder,
    body.registration-prototype .reg-frame8-main .auth-input::placeholder {
      color: #94a3b8 !important;
      -webkit-text-fill-color: #94a3b8 !important;
      opacity: 1;
    }
    /* Autofill: keep dark background and light text */
    body.registration-prototype .login-card .auth-input:-webkit-autofill,
    body.registration-prototype .reg-frame8-main .auth-input:-webkit-autofill,
    body.registration-prototype .login-card .auth-input:-webkit-autofill:hover,
    body.registration-prototype .reg-frame8-main .auth-input:-webkit-autofill:hover,
    body.registration-prototype .login-card .auth-input:-webkit-autofill:focus,
    body.registration-prototype .reg-frame8-main .auth-input:-webkit-autofill:focus {
      -webkit-text-fill-color: #f1f5f9 !important;
      -webkit-box-shadow: 0 0 0 1000px #1a2332 inset !important;
      box-shadow: 0 0 0 1000px #1a2332 inset, 0 1px 3px rgba(0,0,0,0.35) inset !important;
      transition: background-color 5000s ease-in-out 0s !important;
    }
    body.registration-prototype .login-card .auth-input:hover,
    body.registration-prototype .reg-frame8-main .auth-input:hover {
      border-color: rgba(31, 88, 195, 0.4) !important;
      box-shadow: 0 1px 3px rgba(0,0,0,0.35) inset, 0 0 0 1px rgba(31, 88, 195, 0.15) !important;
    }
    body.registration-prototype .login-card .auth-input:focus,
    body.registration-prototype .reg-frame8-main .auth-input:focus {
      border-color: var(--reg-border-focus) !important;
      outline: none !important;
      box-shadow: 0 0 0 3px rgba(31, 88, 195, 0.25), 0 0 16px rgba(31, 88, 195, 0.18), 0 1px 3px rgba(0,0,0,0.3) inset !important;
    }
    body.registration-prototype .login-card .auth-input[aria-invalid="true"],
    body.registration-prototype .login-card .auth-input.is-invalid,
    body.registration-prototype .reg-frame8-main .auth-input[aria-invalid="true"],
    body.registration-prototype .reg-frame8-main .auth-input.is-invalid {
      border-color: rgba(239, 68, 68, 0.7) !important;
      background: rgba(239, 68, 68, 0.08) !important;
      box-shadow: 0 0 0 2px rgba(239, 68, 68, 0.2), 0 1px 3px rgba(0,0,0,0.25) inset !important;
    }
    body.registration-prototype .login-card .auth-input[aria-invalid="true"]:focus,
    body.registration-prototype .login-card .auth-input.is-invalid:focus,
    body.registration-prototype .reg-frame8-main .auth-input[aria-invalid="true"]:focus,
    body.registration-prototype .reg-frame8-main .auth-input.is-invalid:focus {
      border-color: #ef4444 !important;
      box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.3), 0 1px 3px rgba(0,0,0,0.25) inset !important;
    }
    body.registration-prototype .login-card #reg-password-confirm-wrap:has(~ .reg-confirm-success:not(.hidden)) .auth-input,
    body.registration-prototype .reg-frame8-main #reg-password-confirm-wrap:has(~ .reg-confirm-success:not(.hidden)) .auth-input {
      border-color: rgba(34, 197, 94, 0.5) !important;
      background: rgba(34, 197, 94, 0.08) !important;
      box-shadow: 0 0 0 1px rgba(34, 197, 94, 0.2), 0 1px 3px rgba(0,0,0,0.2) inset !important;
    }
    body.registration-prototype .login-card select.auth-input,
    body.registration-prototype .reg-frame8-main select.auth-input {
      appearance: none;
      -webkit-appearance: none;
      cursor: pointer;
      padding-right: 2.75rem;
      padding-left: 1.25rem;
      padding-top: 0.75rem;
      padding-bottom: 0.75rem;
      min-height: var(--reg-input-height) !important;
      background-color: #1a2332 !important;
      background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='%2394a3b8' viewBox='0 0 16 16'%3E%3Cpath d='M8 11L3 6h10l-5 5z'/%3E%3C/svg%3E") !important;
      background-repeat: no-repeat !important;
      background-position: right 1rem center !important;
      background-size: 1rem !important;
    }
    body.registration-prototype .login-card select.auth-input:hover,
    body.registration-prototype .reg-frame8-main select.auth-input:hover {
      border-color: rgba(31, 88, 195, 0.4) !important;
    }
    body.registration-prototype .login-card select.auth-input:focus,
    body.registration-prototype .reg-frame8-main select.auth-input:focus {
      border-color: var(--reg-border-focus) !important;
      box-shadow: 0 0 0 3px rgba(31, 88, 195, 0.25), 0 1px 3px rgba(0,0,0,0.3) inset !important;
    }
    body.registration-prototype .login-card select.auth-input[aria-invalid="true"],
    body.registration-prototype .login-card select.auth-input.is-invalid,
    body.registration-prototype .reg-frame8-main select.auth-input[aria-invalid="true"],
    body.registration-prototype .reg-frame8-main select.auth-input.is-invalid {
      border-color: rgba(239, 68, 68, 0.7) !important;
      box-shadow: 0 0 0 2px rgba(239, 68, 68, 0.2), 0 1px 3px rgba(0,0,0,0.25) inset !important;
    }
    body.registration-prototype .login-card select.auth-input option,
    body.registration-prototype .reg-frame8-main select.auth-input option {
      background: #1a2332;
      color: #f1f5f9;
    }
    body.registration-prototype .login-card .auth-password-wrap { position: relative; }
    body.registration-prototype .reg-frame8-main .auth-password-wrap { position: relative; }
    body.registration-prototype .login-card .auth-password-wrap .auth-input,
    body.registration-prototype .reg-frame8-main .auth-password-wrap .auth-input { padding-right: 2.75rem !important; }
    body.registration-prototype .login-card #toggle-register-password,
    body.registration-prototype .reg-frame8-main #toggle-register-password {
      position: absolute !important; right: 0.5rem !important; top: 50% !important;
      transform: translateY(-50%) !important;
      width: 2rem; height: 2rem; padding: 0 !important;
      display: inline-flex; align-items: center; justify-content: center;
      color: #94a3b8 !important; background: transparent !important; border: none !important;
    }
    body.registration-prototype .login-card #toggle-register-password:hover,
    body.registration-prototype .reg-frame8-main #toggle-register-password:hover { color: #F59E0B !important; }
    body.registration-prototype .login-card .auth-input:focus-visible,
    body.registration-prototype .login-card .reg-submit:focus-visible,
    body.registration-prototype .login-card .subtext a:focus-visible,
    body.registration-prototype .reg-frame8-main .auth-input:focus-visible {
      outline: none;
      box-shadow: 0 0 0 2px #0f172a, 0 0 0 4px #1F58C3 !important;
    }
    body.registration-prototype .login-card #toggle-register-password-confirm,
    body.registration-prototype .reg-frame8-main #toggle-register-password-confirm {
      position: absolute !important; right: 0.5rem !important; top: 50% !important;
      transform: translateY(-50%) !important;
      width: 2rem; height: 2rem; padding: 0 !important;
      display: inline-flex; align-items: center; justify-content: center;
      color: #94a3b8 !important; background: transparent !important; border: none !important;
    }
    body.registration-prototype .login-card #toggle-register-password-confirm:hover,
    body.registration-prototype .reg-frame8-main #toggle-register-password-confirm:hover { color: #F59E0B !important; }
    body.registration-prototype .login-card #toggle-register-password:focus-visible,
    body.registration-prototype .login-card #toggle-register-password-confirm:focus-visible,
    body.registration-prototype .reg-frame8-main #toggle-register-password:focus-visible,
    body.registration-prototype .reg-frame8-main #toggle-register-password-confirm:focus-visible {
      outline: none;
      box-shadow: 0 0 0 2px #0f172a, 0 0 0 4px #F59E0B !important;
    }
    /* Success/error alerts: dark-theme, subtle animation */
    body.registration-prototype .login-card .auth-alert {
      display: flex;
      align-items: center;
      gap: 0.75rem;
      padding: 0.875rem 1rem;
      border-radius: 0.75rem;
      font-size: 0.875rem;
      margin-bottom: 1rem;
      border: 1px solid transparent;
      border-left: 3px solid;
      animation: reg-alert-in 0.35s ease-out;
    }
    body.registration-prototype .login-card .auth-alert-icon { font-size: 1.25rem; flex-shrink: 0; }
    body.registration-prototype .login-card .auth-alert-text { font-weight: 500; }
    body.registration-prototype .login-card .auth-alert--error {
      background: rgba(239, 68, 68, 0.12);
      border-color: rgba(239, 68, 68, 0.35);
      border-left-color: #ef4444;
      color: #fca5a5;
    }
    body.registration-prototype .login-card .auth-alert--error .auth-alert-icon { color: #f87171; }
    @keyframes reg-alert-in {
      from { opacity: 0; transform: translateY(-6px); }
      to { opacity: 1; transform: translateY(0); }
    }
    body.registration-prototype .login-card .reg-submit,
    body.registration-prototype #reg-submit-btn,
    body.registration-prototype .reg-frame8-form-fixed-bottom .reg-submit {
      background: linear-gradient(180deg, rgba(255,255,255,0.08) 0%, transparent 50%), linear-gradient(180deg, var(--reg-primary) 0%, #1E40AF 100%) !important;
      color: #fff !important;
      padding: 0.75rem 1.25rem !important;
      border-radius: var(--reg-radius);
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.06em;
      font-size: 0.8125rem;
      min-height: var(--reg-input-height) !important;
      border: none;
      box-shadow: 0 4px 16px rgba(31, 88, 195, 0.4), 0 1px 0 rgba(255,255,255,0.1) inset;
      transition: filter 0.2s ease, transform 0.2s ease, box-shadow 0.2s ease;
    }
    body.registration-prototype .reg-frame8-form-fixed-bottom .reg-submit {
      min-height: 2.75rem !important;
      padding: 0.5rem 1rem !important;
      font-size: 0.75rem !important;
    }
    body.registration-prototype .login-card .reg-submit:hover,
    body.registration-prototype #reg-submit-btn:hover,
    body.registration-prototype .reg-frame8-form-fixed-bottom .reg-submit:hover {
      filter: brightness(1.08);
      transform: translateY(-2px);
      box-shadow: var(--reg-shadow-glow), 0 6px 24px rgba(31, 88, 195, 0.45), 0 1px 0 rgba(255,255,255,0.12) inset;
    }
    body.registration-prototype .login-card .reg-submit:active,
    body.registration-prototype #reg-submit-btn:active,
    body.registration-prototype .reg-frame8-form-fixed-bottom .reg-submit:active {
      transform: translateY(0) scale(0.98);
      transition-duration: 0.1s;
    }
    body.registration-prototype #reg-submit-btn:disabled:active,
    body.registration-prototype .reg-frame8-form-fixed-bottom .reg-submit:disabled:active {
      transform: none;
    }
    body.registration-prototype #reg-submit-btn:focus-visible,
    body.registration-prototype .reg-frame8-form-fixed-bottom .reg-submit:focus-visible {
      outline: none;
      box-shadow: 0 0 0 2px #0f172a, 0 0 0 4px #1F58C3 !important;
    }
    body.registration-prototype #reg-submit-btn:disabled,
    body.registration-prototype .reg-frame8-form-fixed-bottom .reg-submit:disabled {
      cursor: not-allowed;
      filter: none;
      transform: none;
      box-shadow: 0 2px 8px rgba(31, 88, 195, 0.25), 0 1px 0 rgba(255,255,255,0.06) inset;
    }
    body.registration-prototype #reg-submit-btn:disabled:hover,
    body.registration-prototype .reg-frame8-form-fixed-bottom .reg-submit:disabled:hover {
      filter: none;
      transform: none;
    }
    .reg-submit-hint {
      font-size: 0.75rem;
      color: #94a3b8;
      margin: 0 0 0.5rem;
      text-align: center;
      min-height: 1.25rem;
      line-height: 1.45;
      padding: 0.5rem 0.85rem;
      background: rgba(31, 88, 195, 0.08);
      border: 1px solid rgba(31, 88, 195, 0.15);
      border-radius: 0.5rem;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 0.35rem;
      flex-wrap: wrap;
    }
    .reg-submit-hint .reg-hint-count { font-weight: 700; color: #7dd3fc; }
    .reg-submit-hint.reg-hint-complete { background: rgba(34, 197, 94, 0.08); border-color: rgba(34, 197, 94, 0.2); }
    .reg-submit-hint.reg-hint-complete .reg-hint-count { color: #6ee7b7; }
    .reg-submit-hint.hidden { display: none !important; }
    .reg-frame8-form-fixed-bottom .login-blurbs {
      margin-top: 0.5rem !important;
      padding-top: 0.5rem !important;
      border-top-color: rgba(255, 255, 255, 0.06);
    }
    .reg-frame8-form-fixed-bottom .login-blurb {
      font-size: 0.6875rem !important;
      min-height: 1.25rem !important;
      color: #64748b;
    }
    .reg-frame8-form-fixed-bottom .subtext {
      margin-top: 0.5rem !important;
      font-size: 0.75rem !important;
      color: #64748b;
    }
    .reg-frame8-form-fixed-bottom .subtext a { color: #7dd3fc !important; }
    body.registration-prototype .login-card .subtext a {
      transition: color 0.2s ease, text-decoration 0.2s ease;
      text-decoration: none;
    }
    body.registration-prototype .login-card .subtext a:hover {
      text-decoration: underline;
      color: #FCD34D !important;
    }
    body.registration-prototype .login-card .subtext a:focus-visible {
      outline: 2px solid #F59E0B;
      outline-offset: 2px;
    }
    @media (max-width: 640px) {
      body.registration-prototype .login-card .auth-input,
      body.registration-prototype .reg-frame8-main .auth-input { min-height: var(--reg-input-height) !important; }
      body.registration-prototype .login-card .reg-submit,
      body.registration-prototype #reg-submit-btn { min-height: var(--reg-input-height) !important; }
      body.registration-prototype .reg-frame8-form-fixed-bottom .reg-submit { min-height: 2.75rem !important; }
      body.registration-prototype .login-card #toggle-register-password,
      body.registration-prototype .login-card #toggle-register-password-confirm,
      body.registration-prototype .reg-frame8-main #toggle-register-password,
      body.registration-prototype .reg-frame8-main #toggle-register-password-confirm { min-width: 2.75rem !important; min-height: 2.75rem !important; }
    }
    @media (hover: none) and (pointer: coarse) {
      body.registration-prototype .login-card .auth-input,
      body.registration-prototype .reg-frame8-main .auth-input { min-height: var(--reg-input-height) !important; }
      body.registration-prototype .login-card .reg-submit,
      body.registration-prototype #reg-submit-btn { min-height: var(--reg-input-height) !important; }
      body.registration-prototype .login-card #toggle-register-password,
      body.registration-prototype .login-card #toggle-register-password-confirm,
      body.registration-prototype .reg-frame8-main #toggle-register-password,
      body.registration-prototype .reg-frame8-main #toggle-register-password-confirm { min-width: 2.75rem !important; min-height: 2.75rem !important; }
    }
    body.registration-prototype .login-footer-copy {
      color: #64748b !important; font-size: 0.6875rem !important;
      margin-top: 1rem; padding: 0.75rem 1rem; position: relative; z-index: 10;
    }
    body.registration-prototype .login-card .login-value-statement { font-size: 0.8125rem; color: #94a3b8; margin-bottom: 0.5rem; line-height: 1.4; }
    body.registration-prototype .login-card .reg-dashboard-preview {
      display: flex; align-items: center; justify-content: center; gap: 0.75rem;
      margin-top: 0.75rem; padding: 0.5rem 0.75rem;
      background: rgba(31, 88, 195, 0.08); border: 1px solid rgba(31, 88, 195, 0.2);
      border-radius: 0.75rem; font-size: 0.6875rem; color: #94a3b8;
    }
    body.registration-prototype .login-card .reg-dashboard-preview span { display: inline-flex; align-items: center; gap: 0.25rem; }
    body.registration-prototype .login-card .reg-dashboard-preview .score { color: #7dd3fc; font-weight: 600; }
    body.registration-prototype .login-card .float-label-wrap { position: relative; }
    body.registration-prototype .reg-frame8-main .float-label-wrap { position: relative; }
    body.registration-prototype .login-card .float-label-wrap .float-label,
    body.registration-prototype .reg-frame8-main .float-label-wrap .float-label {
      position: absolute; left: 1rem; top: 50%; transform: translateY(-50%);
      font-size: 0.875rem; font-weight: 500; color: #94a3b8; pointer-events: none;
      transition: top 0.2s ease, font-size 0.2s ease, color 0.2s ease; z-index: 1;
    }
    body.registration-prototype .login-card .float-label-wrap.focused .float-label,
    body.registration-prototype .login-card .float-label-wrap.has-value .float-label,
    body.registration-prototype .reg-frame8-main .float-label-wrap.focused .float-label,
    body.registration-prototype .reg-frame8-main .float-label-wrap.has-value .float-label {
      top: -0.35rem; font-size: 0.75rem; color: #7dd3fc;
    }
    body.registration-prototype .login-card .reg-pw-strength-wrap,
    body.registration-prototype .reg-frame8-main .reg-pw-strength-wrap {
      margin-top: 0.5rem;
      display: flex;
      align-items: center;
      gap: 0.75rem;
      flex-wrap: wrap;
    }
    body.registration-prototype .login-card .reg-pw-strength-bar,
    body.registration-prototype .reg-frame8-main .reg-pw-strength-bar {
      flex: 1;
      min-width: 80px;
      height: 6px;
      border-radius: 999px;
      background: rgba(30, 41, 59, 0.8);
      overflow: hidden;
      transition: background 0.2s ease;
    }
    body.registration-prototype .reg-frame8-main .reg-pw-strength-bar {
      height: 8px;
      background: rgba(255, 255, 255, 0.06);
    }
    body.registration-prototype .login-card .reg-pw-strength-fill,
    body.registration-prototype .reg-frame8-main .reg-pw-strength-fill {
      height: 100%;
      border-radius: 999px;
      width: 0;
      transition: width 0.35s ease, background 0.35s ease;
    }
    body.registration-prototype .login-card .reg-pw-strength-fill.weak,
    body.registration-prototype .reg-frame8-main .reg-pw-strength-fill.weak { width: 20%; background: linear-gradient(90deg, #ef4444, #f87171); }
    body.registration-prototype .login-card .reg-pw-strength-fill.fair,
    body.registration-prototype .reg-frame8-main .reg-pw-strength-fill.fair { width: 40%; background: linear-gradient(90deg, #f97316, #fb923c); }
    body.registration-prototype .login-card .reg-pw-strength-fill.good,
    body.registration-prototype .reg-frame8-main .reg-pw-strength-fill.good { width: 60%; background: linear-gradient(90deg, #eab308, #facc15); }
    body.registration-prototype .login-card .reg-pw-strength-fill.strong,
    body.registration-prototype .reg-frame8-main .reg-pw-strength-fill.strong { width: 85%; background: linear-gradient(90deg, #22c55e, #4ade80); }
    body.registration-prototype .login-card .reg-pw-strength-fill.very-strong,
    body.registration-prototype .reg-frame8-main .reg-pw-strength-fill.very-strong { width: 100%; background: linear-gradient(90deg, #16a34a, #22c55e); }
    body.registration-prototype .login-card .reg-pw-strength-label,
    body.registration-prototype .reg-frame8-main .reg-pw-strength-label {
      font-size: 0.6875rem;
      font-weight: 700;
      letter-spacing: 0.05em;
      text-transform: uppercase;
      min-width: 4.5rem;
      padding: 0.35rem 0.6rem;
      border-radius: 999px;
      text-align: center;
      color: #64748b;
      background: rgba(30, 41, 59, 0.6);
      border: 1px solid rgba(255, 255, 255, 0.04);
      transition: color 0.25s ease, background 0.25s ease, border-color 0.25s ease;
    }
    body.registration-prototype .login-card .reg-pw-strength-label.weak,
    body.registration-prototype .reg-frame8-main .reg-pw-strength-label.weak { color: #fca5a5; background: rgba(239, 68, 68, 0.15); border-color: rgba(239, 68, 68, 0.25); }
    body.registration-prototype .login-card .reg-pw-strength-label.fair,
    body.registration-prototype .reg-frame8-main .reg-pw-strength-label.fair { color: #fdba74; background: rgba(249, 115, 22, 0.15); border-color: rgba(249, 115, 22, 0.25); }
    body.registration-prototype .login-card .reg-pw-strength-label.good,
    body.registration-prototype .reg-frame8-main .reg-pw-strength-label.good { color: #fde047; background: rgba(234, 179, 8, 0.15); border-color: rgba(234, 179, 8, 0.25); }
    body.registration-prototype .login-card .reg-pw-strength-label.strong,
    body.registration-prototype .reg-frame8-main .reg-pw-strength-label.strong { color: #86efac; background: rgba(34, 197, 94, 0.15); border-color: rgba(34, 197, 94, 0.25); }
    body.registration-prototype .login-card .reg-pw-strength-label.very-strong,
    body.registration-prototype .reg-frame8-main .reg-pw-strength-label.very-strong { color: #86efac; background: rgba(34, 197, 94, 0.2); border-color: rgba(34, 197, 94, 0.35); }
    body.registration-prototype .reg-frame8-main .reg-pw-checklist-heading {
      margin: 0.75rem 0 0.5rem;
      font-size: 0.6875rem;
      font-weight: 600;
      letter-spacing: 0.06em;
      text-transform: uppercase;
      color: rgba(148, 163, 184, 0.8);
    }
    body.registration-prototype .login-card .reg-pw-checklist,
    body.registration-prototype .reg-frame8-main .reg-pw-checklist {
      margin-top: 0.5rem;
      margin-bottom: 1.25rem;
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 0.5rem;
    }
    @media (max-width: 480px) {
      body.registration-prototype .login-card .reg-pw-checklist,
      body.registration-prototype .reg-frame8-main .reg-pw-checklist { grid-template-columns: 1fr; }
    }
    body.registration-prototype .login-card .reg-pw-check-item,
    body.registration-prototype .reg-frame8-main .reg-pw-check-item {
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
      font-size: 0.75rem;
      color: #64748b;
      padding: 0.5rem 0.65rem;
      border-radius: 0.5rem;
      background: rgba(30, 41, 59, 0.4);
      border: 1px solid rgba(255, 255, 255, 0.04);
      transition: color 0.25s ease, background 0.25s ease, border-color 0.25s ease, transform 0.2s ease;
    }
    body.registration-prototype .reg-frame8-main .reg-pw-check-item:hover {
      background: rgba(30, 41, 59, 0.6);
    }
    body.registration-prototype .login-card .reg-pw-check-item i,
    body.registration-prototype .reg-frame8-main .reg-pw-check-item i {
      font-size: 0.875rem;
      flex-shrink: 0;
      transition: color 0.25s ease, transform 0.2s ease;
      color: #475569;
    }
    body.registration-prototype .login-card .reg-pw-check-item.met,
    body.registration-prototype .reg-frame8-main .reg-pw-check-item.met {
      color: #86efac;
      background: rgba(34, 197, 94, 0.08);
      border-color: rgba(34, 197, 94, 0.2);
    }
    body.registration-prototype .login-card .reg-pw-check-item.met i,
    body.registration-prototype .reg-frame8-main .reg-pw-check-item.met i {
      color: #22c55e;
      transform: scale(1.05);
    }
    body.registration-prototype .login-card .reg-confirm-error {
      font-size: 0.75rem;
      color: #f87171;
      margin-top: 0.25rem;
    }
    body.registration-prototype .login-card .reg-confirm-error.hidden { display: none !important; }
    body.registration-prototype .login-card .login-security-hint { color: #94a3b8 !important; }
    body.registration-prototype .login-card .file-hint { color: #94a3b8; font-size: 0.6875rem; }
    body.registration-prototype .login-card .file-feedback { color: #94a3b8; font-size: 0.75rem; margin-top: 0.25rem; }
    body.registration-prototype .login-card input[type="file"] {
      color: #e2e8f0;
    }
    body.registration-prototype .login-card .reg-file-zone,
    body.registration-prototype .reg-frame8-main .reg-file-zone {
      min-height: 11rem;
      padding: 2rem 1.5rem;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      background: rgba(31, 88, 195, 0.06);
      border: 2px dashed rgba(31, 88, 195, 0.25);
      border-radius: var(--reg-radius);
      transition: border-color 0.2s ease, background 0.2s ease, box-shadow 0.2s ease;
      cursor: pointer;
    }
    body.registration-prototype .login-card .reg-file-zone.dragover,
    body.registration-prototype .reg-frame8-main .reg-file-zone.dragover {
      border-color: #1F58C3;
      background: rgba(31, 88, 195, 0.12);
      box-shadow: 0 0 0 3px rgba(31, 88, 195, 0.2);
    }
    body.registration-prototype .login-card .reg-file-zone.has-file,
    body.registration-prototype .reg-frame8-main .reg-file-zone.has-file {
      border-color: rgba(34, 197, 94, 0.4);
      background: rgba(34, 197, 94, 0.06);
      box-shadow: 0 0 0 1px rgba(34, 197, 94, 0.2);
      min-height: 6rem;
      padding: 1.25rem 1.5rem;
    }
    body.registration-prototype .login-card .reg-file-zone.is-invalid,
    body.registration-prototype .reg-frame8-main .reg-file-zone.is-invalid {
      border-color: rgba(239, 68, 68, 0.6) !important;
      background: rgba(239, 68, 68, 0.06);
    }
    body.registration-prototype .reg-frame8-main .reg-file-zone #reg-file-placeholder,
    body.registration-prototype .login-card .reg-file-zone #reg-file-placeholder {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 0.25rem;
    }
    body.registration-prototype .reg-frame8-main .reg-file-zone #reg-file-placeholder p,
    body.registration-prototype .login-card .reg-file-zone #reg-file-placeholder p {
      font-size: 0.8125rem;
      margin: 0;
      color: #94a3b8;
      letter-spacing: 0.02em;
      font-weight: 500;
    }
    body.registration-prototype .reg-frame8-main .reg-file-zone #reg-file-placeholder .bi,
    body.registration-prototype .login-card .reg-file-zone #reg-file-placeholder .bi {
      font-size: 0.875rem;
      color: #7dd3fc;
      margin-right: 0.375rem;
    }
    body.registration-prototype .reg-frame8-main .reg-file-zone #reg-file-placeholder #reg-file-browse,
    body.registration-prototype .login-card .reg-file-zone #reg-file-placeholder .text-\[\#7dd3fc\] {
      font-size: inherit;
      color: #7dd3fc;
      font-weight: 600;
    }
    body.registration-prototype .reg-frame8-main .reg-file-zone .file-hint,
    body.registration-prototype .login-card .reg-file-zone .file-hint {
      font-size: 0.6875rem;
      color: #64748b;
      letter-spacing: 0.03em;
      line-height: 1.4;
      text-transform: uppercase;
    }
    .reg-upload-box-wrap {
      display: flex;
      flex-direction: column;
      gap: 0.5rem;
    }
    .reg-avatar-default-toggle {
      justify-content: flex-end;
    }
    #reg-file-preview .text-left,
    #reg-avatar-preview .text-left {
      max-width: min(100%, 320px);
      min-width: 0;
    }
    #reg-file-name,
    #reg-avatar-name {
      word-break: break-all;
      overflow-wrap: anywhere;
      line-height: 1.25;
    }
    body.registration-prototype .login-card .login-blurbs {
      margin-top: 1.5rem !important;
      padding-top: 1.1rem !important;
    }
    body.registration-prototype .login-card .login-blurb { transition: opacity 0.35s ease; }
    body.registration-prototype .login-card .reg-form-progress { height: 3px; background: rgba(31, 88, 195, 0.2); border-radius: 2px; overflow: hidden; margin-bottom: 1rem; }
    body.registration-prototype .login-card .reg-form-progress-bar { height: 100%; background: #1F58C3; border-radius: 2px; transition: width 0.35s ease; width: 0; }
    .reg-modal-backdrop {
      position: fixed; inset: 0; z-index: 80;
      display: flex; align-items: center; justify-content: center; padding: 1.5rem;
      background: rgba(11, 18, 32, 0.75); backdrop-filter: blur(12px);
      opacity: 0; pointer-events: none; transition: opacity 0.3s ease;
    }
    .reg-modal-backdrop.is-active { opacity: 1; pointer-events: auto; }
    .reg-modal-card {
      max-width: 400px; width: 100%;
      background: linear-gradient(180deg, #111827 0%, #0f172a 100%);
      border: 1px solid rgba(255,255,255,0.06); border-radius: 1rem;
      padding: 1.75rem; text-align: center;
      box-shadow: 0 24px 48px rgba(0,0,0,0.4);
      transform: translateY(12px) scale(0.98); opacity: 0;
      transition: transform 0.3s ease, opacity 0.3s ease;
    }
    .reg-modal-backdrop.is-active .reg-modal-card { transform: translateY(0) scale(1); opacity: 1; }
    .reg-modal-card h2 { color: #fff; font-size: 1.125rem; margin: 0 0 0.75rem; font-weight: 700; }
    .reg-modal-card p { color: #94a3b8; font-size: 0.875rem; line-height: 1.6; margin: 0 0 1.25rem; }
    .reg-modal-card .btn-primary {
      display: inline-block; background: #1F58C3; color: #fff; padding: 0.625rem 1.25rem;
      border-radius: 0.75rem; font-weight: 600; font-size: 0.875rem; border: none; cursor: pointer;
      transition: background 0.2s, transform 0.2s; text-decoration: none;
    }
    .reg-modal-card .btn-primary:hover { background: #1E40AF; transform: translateY(-2px); }
    .reg-loading-orb {
      width: 56px; height: 56px; margin: 0 auto 1rem; border-radius: 50%;
      background: conic-gradient(from 200deg, #f59e0b, #1f58c3, #f59e0b);
      padding: 3px; animation: reg-orb-spin 900ms linear infinite;
    }
    .reg-loading-orb-inner {
      width: 100%; height: 100%; border-radius: inherit;
      background: #111827; display: flex; align-items: center; justify-content: center;
    }
    .reg-loading-orb-inner span {
      width: 10px; height: 10px; border-radius: 50%;
      background: linear-gradient(135deg, #f59e0b, #f97316);
      animation: reg-orb-pulse 1.1s ease-out infinite;
    }
    @keyframes reg-orb-spin { to { transform: rotate(360deg); } }
    @keyframes reg-orb-pulse {
      0%, 100% { transform: scale(0.9); opacity: 0.8; }
      50% { transform: scale(1.15); opacity: 1; }
    }
    .reg-success-check {
      width: 64px; height: 64px; margin: 0 auto 1rem; border-radius: 50%;
      background: rgba(34, 197, 94, 0.2); border: 2px solid #22c55e;
      display: flex; align-items: center; justify-content: center;
      animation: reg-check-pop 0.45s cubic-bezier(0.22, 1, 0.36, 1) forwards;
    }
    .reg-success-check i { font-size: 2rem; color: #22c55e; }
    @keyframes reg-check-pop {
      0% { transform: scale(0.5); opacity: 0; }
      70% { transform: scale(1.08); opacity: 1; }
      100% { transform: scale(1); opacity: 1; }
    }
    /* Validation error modal (matches login error modal style) */
    .reg-error-backdrop {
      position: fixed; inset: 0; z-index: 90;
      display: flex; align-items: center; justify-content: center; padding: 1.5rem;
      background: rgba(11, 18, 32, 0.75); backdrop-filter: blur(18px);
      opacity: 0; pointer-events: none; transition: opacity 0.22s ease-out;
    }
    .reg-error-backdrop.is-active { opacity: 1; pointer-events: auto; }
    .reg-error-card {
      max-width: 380px; width: 100%; border-radius: 1rem;
      padding: 1.75rem 1.5rem; text-align: center;
      background: linear-gradient(180deg, #111827 0%, #0f172a 100%);
      border: 1px solid rgba(255,255,255,0.06);
      box-shadow: 0 24px 48px rgba(0,0,0,0.5);
      transform: translateY(12px) scale(0.98); opacity: 0;
      transition: transform 0.22s ease-out, opacity 0.22s ease-out;
    }
    .reg-error-backdrop.is-active .reg-error-card {
      transform: translateY(0) scale(1); opacity: 1;
    }
    .reg-error-icon {
      width: 56px; height: 56px; margin: 0 auto 1rem; border-radius: 50%;
      border: 2px solid rgba(248, 250, 252, 0.3);
      background: rgba(239, 68, 68, 0.15);
      display: flex; align-items: center; justify-content: center;
    }
    .reg-error-icon i { font-size: 1.5rem; color: #f87171; }
    .reg-error-card h2 { color: #fff; font-size: 1.125rem; margin: 0 0 0.5rem; font-weight: 700; }
    .reg-error-card .reg-error-message { color: #94a3b8; font-size: 0.875rem; line-height: 1.5; margin: 0 0 1.25rem; text-align: left; }
    .reg-error-card .reg-error-list { list-style: none; padding: 0; margin: 0 0 1.25rem; }
    .reg-error-card .reg-error-list li { padding: 0.35rem 0 0.35rem 1.25rem; position: relative; }
    .reg-error-card .reg-error-list li::before { content: ''; position: absolute; left: 0; top: 0.55rem; width: 4px; height: 4px; border-radius: 50%; background: #f87171; }
    .reg-error-card .btn-primary { cursor: pointer; border: none; }
  </style>
</head>
<body class="auth-page registration-prototype min-h-screen font-sans antialiased" x-data="{ school: '' }">
  <div class="reg-frame8-layout">
    <div class="reg-frame8-left" aria-hidden="true">
        <div class="reg-grok-blob reg-grok-blob-1" aria-hidden="true"></div>
        <div class="reg-grok-blob reg-grok-blob-2" aria-hidden="true"></div>
        <div class="reg-grok-blob reg-grok-blob-3" aria-hidden="true"></div>
        <div class="reg-frame8-left-bg-shape" aria-hidden="true">
          <svg class="reg-left-logo-svg" viewBox="0 0 80 100" xmlns="http://www.w3.org/2000/svg" focusable="false" aria-hidden="true">
            <defs>
              <linearGradient id="reg-left-logo-grad" x1="0%" y1="0%" x2="100%" y2="100%">
                <stop offset="0%" stop-color="rgba(191, 219, 254, 0.75)"/>
                <stop offset="100%" stop-color="rgba(96, 165, 250, 0.6)"/>
              </linearGradient>
            </defs>
            <g class="reg-left-logo-g" fill="none" stroke="url(#reg-left-logo-grad)" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
              <!-- Paper / document shape: body + folded corner -->
              <path class="reg-paper-body" d="M 12 8 L 12 88 L 68 88 L 68 28 L 42 28 L 42 8 Z"/>
              <path class="reg-paper-fold" d="M 42 8 L 42 28 L 68 28 Z"/>
            </g>
          </svg>
        </div>
        <div class="reg-frame8-hero reg-left-statement" aria-live="polite">
          <div class="reg-left-statement-inner">
            <p class="reg-left-statement-headline">Track your scores, drills, and mock exams in one place.</p>
            <p class="reg-left-statement-metrics reg-info-rotating" id="reg-left-metrics">Mock exam cohort 1 71% · Drill sets 69%</p>
            <p class="reg-left-statement-blurb reg-info-rotating" id="reg-left-blurb">Benchmarked vs national pass rates.</p>
          </div>
        </div>
      </div>
    <div class="reg-frame8-right">
      <header class="reg-frame8-header">
        <span class="reg-frame8-brand"><span class="blue">LCRC</span> <span class="amber">eReview</span></span>
        <img src="image%20assets/lcrc-logo-reg.png" alt="LCRC Review School &amp; Training Center" class="reg-frame8-logo-right" width="140" height="36" loading="eager" decoding="async">
      </header>
      <main class="reg-frame8-main">
        <h1 class="reg-frame8-title">Create your Account</h1>
        <p class="reg-frame8-subtitle">One step closer to your CPA journey.</p>

        <form action="register_process.php" method="POST" enctype="multipart/form-data" novalidate id="reg-form">
          <div class="reg-form-progress" aria-hidden="true" id="reg-form-progress">
            <div class="reg-form-progress-bar" id="reg-form-progress-bar"></div>
          </div>

          <div class="reg-frame8-form-scroll" id="reg-form-scroll">
            <?php if ($error): ?>
              <div class="auth-alert auth-alert--error" role="alert" aria-live="assertive" id="reg-error-alert">
                <i class="auth-alert-icon bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
                <span class="auth-alert-text"><?php echo h($error); ?></span>
              </div>
            <?php endif; ?>

            <span class="reg-section-label" id="reg-label-account"><i class="bi bi-check-circle-fill reg-section-label-check" aria-hidden="true"></i>Account</span>
            <div class="reg-section reg-frame8-grid reg-frame8-grid-2">
              <div class="space-y-1">
                <div class="float-label-wrap" data-float-wrap>
                  <label class="float-label" for="reg-full_name">Your full name</label>
                  <input type="text" name="full_name" id="reg-full_name" required placeholder=" " class="auth-input w-full rounded-xl border px-4 py-3 text-sm" aria-describedby="reg-error-full_name">
                </div>
                <span id="reg-error-full_name" class="reg-inline-error" role="alert" aria-live="polite"></span>
              </div>
              <div class="space-y-1">
                <div class="float-label-wrap" data-float-wrap>
                  <label class="float-label" for="reg-email">Email address</label>
                  <input type="email" name="email" id="reg-email" required placeholder=" " class="auth-input w-full rounded-xl border px-4 py-3 text-sm" aria-describedby="reg-error-email">
                </div>
                <span id="reg-error-email" class="reg-inline-error" role="alert" aria-live="polite"></span>
              </div>
            </div>

            <div class="reg-section reg-frame8-grid reg-frame8-grid-2">
              <div class="space-y-1">
                <label class="reg-top-label" for="reg-school">School</label>
                <select name="school" id="reg-school" x-model="school" required class="auth-input w-full rounded-xl border px-4 py-3 text-sm" aria-describedby="reg-error-school" data-school-dynamic="1">
                  <option value="" selected disabled>Select school</option>
                  <?php foreach ($schoolDropdownOptions as $schoolOpt): ?>
                    <?php if ($schoolOpt === 'Other') { continue; } ?>
                    <option value="<?php echo h($schoolOpt); ?>"><?php echo h($schoolOpt); ?></option>
                  <?php endforeach; ?>
                  <option value="Other">Other</option>
                </select>
                <span id="reg-error-school" class="reg-inline-error" role="alert" aria-live="polite"></span>
              </div>
              <div class="space-y-1">
                <label class="reg-top-label" for="reg-review_type">Review Type</label>
                <select name="review_type" id="reg-review_type" required class="auth-input w-full rounded-xl border px-4 py-3 text-sm" aria-describedby="reg-error-review_type">
                  <option value="" selected disabled>Select type</option>
                  <option value="reviewee">Reviewee</option>
                  <option value="undergrad">Undergrad</option>
                </select>
                <span id="reg-error-review_type" class="reg-inline-error" role="alert" aria-live="polite"></span>
              </div>
            </div>

            <div class="reg-section reg-frame8-full" x-show="school === 'Other'" x-cloak x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 -translate-y-1" x-transition:enter-end="opacity-100 translate-y-0">
              <div class="float-label-wrap" data-float-wrap>
                <label class="float-label" for="reg-school_other">Your school name</label>
                <input type="text" name="school_other" id="reg-school_other" placeholder=" " class="auth-input w-full rounded-xl border px-4 py-3 text-sm">
              </div>
            </div>

            <span class="reg-section-label" id="reg-label-security"><i class="bi bi-check-circle-fill reg-section-label-check" aria-hidden="true"></i>Security</span>
            <p class="reg-security-subheading" id="reg-security-subheading">Choose a strong password.</p>
            <p class="reg-security-hint" id="reg-security-hint">Scroll down to see password and confirm fields.</p>
            <div class="reg-section reg-frame8-full space-y-2">
              <div class="float-label-wrap auth-password-wrap" data-float-wrap id="reg-password-wrap">
                <label class="float-label" for="register-password">Password</label>
                <input type="password" name="password" id="register-password" required minlength="8" placeholder=" " class="auth-input w-full rounded-xl border px-4 pr-12 py-3 text-sm" autocomplete="new-password" aria-describedby="reg-error-password reg-pw-strength-label reg-pw-checklist">
                <button type="button" id="toggle-register-password" class="absolute right-3 top-1/2 -translate-y-1/2 rounded-lg p-1.5 transition-colors" aria-label="Show password">
                  <i id="toggle-register-password-icon" class="bi bi-eye-fill text-lg"></i>
                </button>
              </div>
              <span id="reg-error-password" class="reg-inline-error" role="alert" aria-live="polite"></span>
              <div class="reg-pw-strength-wrap" id="reg-pw-strength-wrap" aria-live="polite">
                <div class="reg-pw-strength-bar" role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="5" aria-label="Password strength">
                  <div class="reg-pw-strength-fill" id="reg-pw-strength-fill"></div>
                </div>
                <p class="reg-pw-strength-label" id="reg-pw-strength-label">—</p>
              </div>
              <p class="reg-pw-checklist-heading" id="reg-pw-checklist-heading">Password requirements</p>
              <div class="reg-pw-checklist" id="reg-pw-checklist" aria-live="polite">
                <div class="reg-pw-check-item" id="reg-pw-check-length" data-check="length">
                  <i class="bi bi-circle" aria-hidden="true"></i>
                  <span>At least 8 characters</span>
                </div>
                <div class="reg-pw-check-item" id="reg-pw-check-number" data-check="number">
                  <i class="bi bi-circle" aria-hidden="true"></i>
                  <span>One number</span>
                </div>
                <div class="reg-pw-check-item" id="reg-pw-check-upper" data-check="upper">
                  <i class="bi bi-circle" aria-hidden="true"></i>
                  <span>One uppercase letter</span>
                </div>
                <div class="reg-pw-check-item" id="reg-pw-check-lower" data-check="lower">
                  <i class="bi bi-circle" aria-hidden="true"></i>
                  <span>One lowercase letter</span>
                </div>
                <div class="reg-pw-check-item" id="reg-pw-check-symbol" data-check="symbol">
                  <i class="bi bi-circle" aria-hidden="true"></i>
                  <span>One symbol (e.g. !@#$%)</span>
                </div>
              </div>
              <div class="float-label-wrap auth-password-wrap" data-float-wrap id="reg-password-confirm-wrap">
                <label class="float-label" for="register-password-confirm">Confirm password</label>
                <input type="password" name="password_confirm" id="register-password-confirm" required minlength="8" placeholder=" " class="auth-input w-full rounded-xl border px-4 pr-12 py-3 text-sm" autocomplete="new-password" aria-describedby="reg-confirm-error reg-confirm-success">
                <button type="button" id="toggle-register-password-confirm" class="absolute right-3 top-1/2 -translate-y-1/2 rounded-lg p-1.5 transition-colors" aria-label="Show confirm password">
                  <i id="toggle-register-password-confirm-icon" class="bi bi-eye-fill text-lg"></i>
                </button>
              </div>
              <p class="reg-confirm-error hidden" id="reg-confirm-error" role="alert"></p>
              <p class="reg-confirm-success hidden" id="reg-confirm-success" role="status" aria-live="polite"><i class="bi bi-check-circle-fill" aria-hidden="true"></i> Passwords match</p>
              <p class="login-security-hint text-xs">Secure sign-in. We never share your data.</p>
            </div>

            <span class="reg-section-label" id="reg-label-payment"><i class="bi bi-check-circle-fill reg-section-label-check" aria-hidden="true"></i>Payment and profile</span>
            <div class="reg-section reg-frame8-full grid grid-cols-1 md:grid-cols-2 gap-4 items-start">
              <div class="reg-upload-box-wrap">
                <label class="reg-top-label" for="register-payment-proof">Upload Payment Proof</label>
                <div class="reg-file-zone border-2 border-dashed rounded-xl p-4 text-center transition-colors" id="reg-file-zone" aria-describedby="reg-error-payment_proof">
                  <input type="file" name="payment_proof" id="register-payment-proof" required accept="image/*,application/pdf" class="hidden" aria-describedby="reg-error-payment_proof">
                  <div id="reg-file-placeholder">
                    <p class="text-sm text-slate-400 mb-2"><i class="bi bi-cloud-arrow-up text-lg text-[#7dd3fc] mr-1.5" aria-hidden="true"></i>Drag file here or <button type="button" class="text-[#7dd3fc] hover:underline" id="reg-file-browse">browse</button></p>
                    <p class="file-hint text-xs">Accepted: images, PDF. We use this to verify your payment.</p>
                  </div>
                  <div id="reg-file-preview" class="hidden">
                    <div class="flex items-center gap-3 justify-center flex-wrap">
                      <img id="reg-file-thumb" src="" alt="" class="w-14 h-14 object-cover rounded-lg border border-slate-600 hidden">
                      <div class="text-left">
                        <p class="text-sm font-medium text-slate-200" id="reg-file-name"></p>
                        <p class="text-xs text-slate-500" id="reg-file-size"></p>
                        <div class="mt-1 h-1.5 bg-slate-700 rounded-full overflow-hidden" id="reg-upload-progress-wrap">
                          <div class="h-full bg-[#1F58C3] rounded-full transition-all duration-300" id="reg-upload-progress" style="width: 0%"></div>
                        </div>
                      </div>
                      <button type="button" id="reg-file-clear" class="text-slate-400 hover:text-white text-sm" aria-label="Remove file">Remove</button>
                    </div>
                  </div>
                </div>
                <span id="reg-error-payment_proof" class="reg-inline-error" role="alert" aria-live="polite"></span>
              </div>

              <div class="reg-upload-box-wrap">
                <label class="reg-top-label" for="register-profile-picture">Profile Picture</label>
                <div class="reg-file-zone border-2 border-dashed rounded-xl p-4 text-center transition-colors" id="reg-avatar-zone" aria-describedby="reg-error-profile_picture">
                  <input type="file" name="profile_picture" id="register-profile-picture" required accept="image/jpeg,image/png,image/webp,image/gif" class="hidden" aria-describedby="reg-error-profile_picture">
                  <div id="reg-avatar-placeholder">
                    <p class="text-sm text-slate-400 mb-2"><i class="bi bi-person-circle text-lg text-[#7dd3fc] mr-1.5" aria-hidden="true"></i>Upload your photo or <button type="button" class="text-[#7dd3fc] hover:underline" id="reg-avatar-browse">browse</button></p>
                    <p class="file-hint text-xs">Accepted: JPG, PNG, WEBP, GIF only. Videos and audio are not allowed.</p>
                  </div>
                  <div id="reg-avatar-preview" class="hidden">
                    <div class="flex items-center gap-3 justify-center flex-wrap">
                      <img id="reg-avatar-thumb" src="" alt="" class="w-14 h-14 object-cover rounded-full border border-slate-600 hidden">
                      <div class="text-left">
                        <p class="text-sm font-medium text-slate-200" id="reg-avatar-name"></p>
                        <p class="text-xs text-slate-500" id="reg-avatar-size"></p>
                      </div>
                      <button type="button" id="reg-avatar-clear" class="text-slate-400 hover:text-white text-sm" aria-label="Remove image">Remove</button>
                    </div>
                  </div>
                </div>
                <span id="reg-error-profile_picture" class="reg-inline-error" role="alert" aria-live="polite"></span>
              </div>
            </div>
          </div>

          <div class="reg-frame8-form-fixed-bottom">
            <p class="reg-submit-hint" id="reg-submit-hint" aria-live="polite">Complete the fields above to continue.</p>
            <button type="submit" class="reg-submit btn-shine w-full inline-flex items-center justify-center gap-2 rounded-xl px-6 py-3.5 text-sm transition-all duration-200 hover:-translate-y-0.5 active:translate-y-0 focus:outline-none focus-visible:ring-2 focus-visible:ring-[#1F58C3] focus-visible:ring-offset-2 focus-visible:ring-offset-[#0f172a] disabled:opacity-70 disabled:cursor-not-allowed" id="reg-submit-btn">
              <span id="reg-submit-text">Submit registration</span>
              <span id="reg-submit-spinner" class="hidden" aria-hidden="true"><i class="bi bi-arrow-repeat animate-spin text-lg"></i></span>
              <i class="bi bi-arrow-right text-lg" id="reg-submit-arrow" aria-hidden="true"></i>
            </button>
            <p class="text-center text-xs subtext mt-3">
              Already have an account? <a href="login.php">Login</a>
            </p>
          </div>
        </form>
      </main>
      <footer class="reg-frame8-footer">
        <p class="reg-frame8-footer-terms">By continuing, you agree to LCRC <a href="terms_of_service.php" target="_blank" rel="noopener noreferrer">Terms of Service</a> and <a href="privacy_policy.php" target="_blank" rel="noopener noreferrer">Privacy Policy</a>.</p>
        <p class="reg-frame8-footer-copy">© Copyright 2026 <strong>LCRC eReview</strong>. All rights reserved. Built for aspiring CPAs</p>
      </footer>
    </div>
  </div>

  <div id="reg-modal-loading" class="reg-modal-backdrop" aria-hidden="true">
    <div class="reg-modal-card">
      <div class="reg-loading-orb">
        <div class="reg-loading-orb-inner"><span></span></div>
      </div>
      <p style="margin:0;color:#e2e8f0;">Creating your account. Please wait...</p>
    </div>
  </div>
  <div id="reg-modal-email-sent" class="reg-modal-backdrop" aria-hidden="true">
    <div class="reg-modal-card">
      <div style="width:56px;height:56px;margin:0 auto 1rem;border-radius:50%;background:rgba(31,88,195,0.2);display:flex;align-items:center;justify-content:center;">
        <i class="bi bi-envelope-check-fill" style="font-size:1.75rem;color:#1F58C3;"></i>
      </div>
      <h2>Verification Email Sent</h2>
      <p>We have sent a confirmation email to your registered email address. Please open your email and click the verification button to confirm and create your account.</p>
      <button type="button" id="reg-modal-email-sent-ok" class="btn-primary">OK</button>
    </div>
  </div>
  <div id="reg-modal-waiting" class="reg-modal-backdrop" aria-hidden="true">
    <div class="reg-modal-card">
      <div class="reg-loading-orb">
        <div class="reg-loading-orb-inner"><span></span></div>
      </div>
      <h2>Waiting for email verification</h2>
      <p>Please confirm your account through the email we sent. This page will update automatically when verification is complete.</p>
    </div>
  </div>
  <div id="reg-modal-success" class="reg-modal-backdrop" aria-hidden="true">
    <div class="reg-modal-card">
      <div class="reg-success-check"><i class="bi bi-check-lg" aria-hidden="true"></i></div>
      <h2>Account Created Successfully</h2>
      <p>Your LCRC eReview account has been successfully verified and created. You may now sign in to access the system.</p>
      <a href="login.php" class="btn-primary">Sign in</a>
    </div>
  </div>

  <div id="reg-modal-error" class="reg-error-backdrop" aria-hidden="true">
    <div class="reg-error-card">
      <div class="reg-error-icon">
        <i class="bi bi-exclamation-circle-fill" aria-hidden="true"></i>
      </div>
      <h2 id="reg-error-title">Registration issue</h2>
      <p id="reg-error-message" class="reg-error-message" role="alert"></p>
      <button type="button" id="reg-error-close" class="btn-primary">OK, try again</button>
    </div>
  </div>

  <script>
    document.addEventListener('DOMContentLoaded', function () {
      var passwordInput = document.getElementById('register-password');
      var togglePasswordButton = document.getElementById('toggle-register-password');
      var togglePasswordIcon = document.getElementById('toggle-register-password-icon');
      var fileInput = document.getElementById('register-payment-proof');
      var fileZone = document.getElementById('reg-file-zone');
      var filePlaceholder = document.getElementById('reg-file-placeholder');
      var filePreview = document.getElementById('reg-file-preview');
      var fileThumb = document.getElementById('reg-file-thumb');
      var fileNameEl = document.getElementById('reg-file-name');
      var fileSizeEl = document.getElementById('reg-file-size');
      var fileProgress = document.getElementById('reg-upload-progress');
      var fileProgressWrap = document.getElementById('reg-upload-progress-wrap');
      var fileBrowse = document.getElementById('reg-file-browse');
      var fileClear = document.getElementById('reg-file-clear');
      var avatarInput = document.getElementById('register-profile-picture');
      var avatarZone = document.getElementById('reg-avatar-zone');
      var avatarPlaceholder = document.getElementById('reg-avatar-placeholder');
      var avatarPreview = document.getElementById('reg-avatar-preview');
      var avatarThumb = document.getElementById('reg-avatar-thumb');
      var avatarNameEl = document.getElementById('reg-avatar-name');
      var avatarSizeEl = document.getElementById('reg-avatar-size');
      var avatarBrowse = document.getElementById('reg-avatar-browse');
      var avatarClear = document.getElementById('reg-avatar-clear');
      var regForm = document.getElementById('reg-form');
      var regSubmitBtn = document.getElementById('reg-submit-btn');
      var regSubmitText = document.getElementById('reg-submit-text');
      var regSubmitSpinner = document.getElementById('reg-submit-spinner');
      var regSubmitArrow = document.getElementById('reg-submit-arrow');
      var confirmInput = document.getElementById('register-password-confirm');
      var toggleConfirmBtn = document.getElementById('toggle-register-password-confirm');
      var toggleConfirmIcon = document.getElementById('toggle-register-password-confirm-icon');
      var confirmErrorEl = document.getElementById('reg-confirm-error');

      function updateFloatLabel(wrap, input) {
        if (!wrap || !input) return;
        var hasVal = input.value.trim() !== '';
        var isFocused = document.activeElement === input;
        wrap.classList.toggle('has-value', hasVal);
        wrap.classList.toggle('focused', isFocused);
      }
      var inlineErrorIds = {
        full_name: 'reg-error-full_name',
        email: 'reg-error-email',
        school: 'reg-error-school',
        review_type: 'reg-error-review_type',
        password: 'reg-error-password',
        payment_proof: 'reg-error-payment_proof',
        profile_picture: 'reg-error-profile_picture'
      };
      function setInlineError(fieldKey, message) {
        var id = inlineErrorIds[fieldKey];
        var control = null;
        if (fieldKey === 'full_name') control = document.getElementById('reg-full_name');
        else if (fieldKey === 'email') control = document.getElementById('reg-email');
        else if (fieldKey === 'school') control = document.getElementById('reg-school');
        else if (fieldKey === 'review_type') control = document.getElementById('reg-review_type');
        else if (fieldKey === 'password') control = document.getElementById('register-password');
        else if (fieldKey === 'payment_proof') control = document.getElementById('register-payment-proof');
        else if (fieldKey === 'profile_picture') control = document.getElementById('register-profile-picture');
        var el = id ? document.getElementById(id) : null;
        if (el) {
          el.textContent = message || '';
        }
        if (fieldKey === 'payment_proof') {
          var zone = document.getElementById('reg-file-zone');
          if (zone) zone.classList.toggle('is-invalid', !!message);
        }
        if (fieldKey === 'profile_picture') {
          var avatarZoneEl = document.getElementById('reg-avatar-zone');
          if (avatarZoneEl) avatarZoneEl.classList.toggle('is-invalid', !!message);
        }
        if (control) {
          if (message) {
            control.setAttribute('aria-invalid', 'true');
            control.classList.add('is-invalid');
          } else {
            control.removeAttribute('aria-invalid');
            control.classList.remove('is-invalid');
          }
        }
        if (fieldKey === 'password_confirm' || fieldKey === 'confirm') {
          var errConfirm = document.getElementById('reg-confirm-error');
          if (errConfirm) {
            errConfirm.textContent = message || '';
            errConfirm.classList.toggle('hidden', !message);
          }
          var confirmControl = document.getElementById('register-password-confirm');
          if (confirmControl) {
            if (message) {
              confirmControl.setAttribute('aria-invalid', 'true');
              confirmControl.classList.add('is-invalid');
            } else {
              confirmControl.removeAttribute('aria-invalid');
              confirmControl.classList.remove('is-invalid');
            }
          }
        }
      }
      function clearAllInlineErrors() {
        Object.keys(inlineErrorIds).forEach(function (k) { setInlineError(k, ''); });
        setInlineError('confirm', '');
        var confirmErr = document.getElementById('reg-confirm-error');
        if (confirmErr) { confirmErr.textContent = ''; confirmErr.classList.add('hidden'); }
        document.querySelectorAll('.auth-input, select.auth-input').forEach(function (c) {
          c.removeAttribute('aria-invalid');
          c.classList.remove('is-invalid');
        });
        var fileZone = document.getElementById('reg-file-zone');
        if (fileZone) fileZone.classList.remove('is-invalid');
        var avatarZoneEl = document.getElementById('reg-avatar-zone');
        if (avatarZoneEl) avatarZoneEl.classList.remove('is-invalid');
      }
      function normalizeFullNameInput(val) {
        if (!val) return '';
        var s = val.replace(/[^A-Za-z.\s]/g, '').replace(/\s+/g, ' ').replace(/\.\.+/g, '.').replace(/^\s+/, '');
        return s;
      }
      function formatFullNameCapitalize(val) {
        if (!val) return '';
        return val.split(/\s+/).map(function (word) {
          if (!word) return '';
          return word.charAt(0).toUpperCase() + word.slice(1).toLowerCase();
        }).join(' ');
      }
      function validateFullNameValue(name) {
        var t = name.trim().replace(/\s+/g, ' ');
        if (!t) return { valid: false, msg: 'Enter your full name.' };
        if (/^\s/.test(name) || /\s\s+/.test(name)) return { valid: false, msg: 'No leading or double spaces allowed.' };
        if (!/^[A-Za-z.\s]+$/.test(t)) return { valid: false, msg: 'Full name can only contain letters, spaces, and single dots.' };
        if (/\.\./.test(t)) return { valid: false, msg: 'Do not use multiple dots in a row.' };
        return { valid: true };
      }
      function validateEmailValue(emailVal) {
        var t = (emailVal || '').trim();
        if (!t) return { valid: false, msg: 'Enter your email address.' };
        var at = t.indexOf('@');
        var local = at >= 0 ? t.substring(0, at) : '';
        if (!local || /^\s+$/.test(local)) return { valid: false, msg: 'Enter a valid email address.' };
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(t)) return { valid: false, msg: 'Enter a valid email address.' };
        return { valid: true };
      }
      function validateForm() {
        clearAllInlineErrors();
        var fullName = document.getElementById('reg-full_name');
        var email = document.getElementById('reg-email');
        var school = document.getElementById('reg-school');
        var reviewType = document.getElementById('reg-review_type');
        var pwRaw = passwordInput ? passwordInput.value : '';
        var pw = (pwRaw || '').trim();
        var confirmRaw = confirmInput ? confirmInput.value : '';
        var confirm = (confirmRaw || '').trim();
        var fileInputEl = document.getElementById('register-payment-proof');
        var avatarInputEl = document.getElementById('register-profile-picture');
        var firstInvalid = null;
        var messages = [];
        var fnVal = validateFullNameValue(fullName ? fullName.value : '');
        if (!fnVal.valid) {
          setInlineError('full_name', fnVal.msg);
          messages.push(fnVal.msg);
          if (!firstInvalid) firstInvalid = fullName;
        }
        var emVal = validateEmailValue(email ? email.value : '');
        if (!emVal.valid) {
          setInlineError('email', emVal.msg);
          messages.push(emVal.msg);
          if (!firstInvalid) firstInvalid = email;
        }
        if (!school || !school.value) {
          setInlineError('school', 'Select your school.');
          messages.push('Select your school.');
          if (!firstInvalid) firstInvalid = school;
        }
        if (!reviewType || !reviewType.value) {
          setInlineError('review_type', 'Select review type.');
          messages.push('Select review type.');
          if (!firstInvalid) firstInvalid = reviewType;
        }
        if (!pwRaw || !pwRaw.trim()) {
          setInlineError('password', 'Enter a password.');
          messages.push('Enter a password.');
          if (!firstInvalid) firstInvalid = passwordInput;
        } else if (/^\s+$/.test(pwRaw)) {
          setInlineError('password', 'Password cannot be only spaces.');
          messages.push('Password cannot be only spaces.');
          if (!firstInvalid) firstInvalid = passwordInput;
        } else if (!allPasswordChecksMet(pw)) {
          setInlineError('password', 'Password must meet all requirements above.');
          messages.push('Password must meet all requirements above.');
          if (!firstInvalid) firstInvalid = passwordInput;
        }
        if (pw !== confirm) {
          setInlineError('confirm', 'Passwords do not match.');
          messages.push('Passwords do not match.');
          if (!firstInvalid) firstInvalid = confirmInput;
        } else if (confirm && !allPasswordChecksMet(pw)) {
          setInlineError('confirm', 'Complete all password requirements first.');
          messages.push('Complete all password requirements first.');
          if (!firstInvalid) firstInvalid = confirmInput;
        }
        var fileInvalidType = false;
        if (fileInputEl && fileInputEl.files && fileInputEl.files.length > 0) {
          var f = fileInputEl.files[0];
          var allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'application/pdf'];
          var ext = (f.name.split('.').pop() || '').toLowerCase();
          var allowedExt = ['jpg', 'jpeg', 'png', 'pdf'];
          if (!allowedTypes.includes(f.type) || !allowedExt.includes(ext)) {
            fileInvalidType = true;
            setInlineError('payment_proof', 'Please upload an image (JPG, PNG) or PDF only.');
            messages.push('Invalid file type. Please upload an image or PDF for payment verification.');
            if (fileZone) fileZone.classList.add('is-invalid');
            if (!firstInvalid) firstInvalid = fileZone;
          }
        }
        if (!fileInputEl || !fileInputEl.files || fileInputEl.files.length === 0) {
          setInlineError('payment_proof', 'Upload a payment proof file.');
          messages.push('Upload a payment proof file.');
          if (fileZone) fileZone.classList.add('is-invalid');
          if (!firstInvalid) firstInvalid = fileZone;
        }
        if (avatarInputEl && avatarInputEl.files && avatarInputEl.files.length > 0) {
          var af = avatarInputEl.files[0];
          var avatarAllowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp', 'image/gif'];
          var avatarExt = (af.name.split('.').pop() || '').toLowerCase();
          var avatarAllowedExt = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
          if (!avatarAllowedTypes.includes(af.type) || !avatarAllowedExt.includes(avatarExt)) {
            setInlineError('profile_picture', 'Please upload JPG, PNG, WEBP, or GIF only.');
            messages.push('Invalid profile picture type. Upload image files only.');
            if (avatarZone) avatarZone.classList.add('is-invalid');
            if (!firstInvalid) firstInvalid = avatarZone || avatarInputEl;
          }
        } else {
          setInlineError('profile_picture', 'Upload a profile picture to continue registration.');
          messages.push('Profile picture is required. Please upload JPG, PNG, WEBP, or GIF.');
          if (avatarZone) avatarZone.classList.add('is-invalid');
          if (!firstInvalid) firstInvalid = avatarZone || avatarInputEl;
        }
        return { valid: !firstInvalid, firstInvalid: firstInvalid, messages: messages };
      }
      function scrollToFirstError(el) {
        if (!el) return;
        var scrollEl = document.getElementById('reg-form-scroll');
        if (scrollEl) {
          el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        } else {
          el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
      }
      function isFormValid() {
        var fullName = document.getElementById('reg-full_name');
        var email = document.getElementById('reg-email');
        var school = document.getElementById('reg-school');
        var reviewType = document.getElementById('reg-review_type');
        var pw = (passwordInput ? passwordInput.value : '').trim();
        var confirm = (confirmInput ? confirmInput.value : '').trim();
        var fileInputEl = document.getElementById('register-payment-proof');
        var avatarInputEl = document.getElementById('register-profile-picture');
        if (!validateFullNameValue(fullName ? fullName.value : '').valid) return false;
        if (!validateEmailValue(email ? email.value : '').valid) return false;
        if (!school || !school.value) return false;
        if (!reviewType || !reviewType.value) return false;
        if (!pw || /^\s+$/.test(passwordInput ? passwordInput.value : '')) return false;
        if (!allPasswordChecksMet(pw)) return false;
        if (pw !== confirm) return false;
        if (!fileInputEl || !fileInputEl.files || fileInputEl.files.length === 0) return false;
        var f = fileInputEl.files[0];
        var allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'application/pdf'];
        var ext = (f.name.split('.').pop() || '').toLowerCase();
        if (!allowedTypes.includes(f.type) || !['jpg', 'jpeg', 'png', 'pdf'].includes(ext)) return false;
        if (avatarInputEl && avatarInputEl.files && avatarInputEl.files.length > 0) {
          var af = avatarInputEl.files[0];
          var avatarAllowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp', 'image/gif'];
          var avatarExt = (af.name.split('.').pop() || '').toLowerCase();
          if (!avatarAllowedTypes.includes(af.type) || !['jpg', 'jpeg', 'png', 'webp', 'gif'].includes(avatarExt)) return false;
        } else {
          return false;
        }
        return true;
      }
      function getFormCompleteCount() {
        var fullName = document.getElementById('reg-full_name');
        var email = document.getElementById('reg-email');
        var school = document.getElementById('reg-school');
        var reviewType = document.getElementById('reg-review_type');
        var pw = passwordInput ? passwordInput.value : '';
        var confirm = confirmInput ? confirmInput.value : '';
        var fileInputEl = document.getElementById('register-payment-proof');
        var avatarInputEl = document.getElementById('register-profile-picture');
        var n = 0;
        if (fullName && fullName.value.trim()) n++;
        if (email && email.value.trim()) n++;
        if (school && school.value) n++;
        if (reviewType && reviewType.value) n++;
        if (pw && pw.length) n++;
        if (confirm && confirm.length) n++;
        if (fileInputEl && fileInputEl.files && fileInputEl.files.length) n++;
        if (avatarInputEl && avatarInputEl.files && avatarInputEl.files.length) n++;
        return n;
      }
      function updateSubmitState() {
        var valid = isFormValid();
        var hintEl = document.getElementById('reg-submit-hint');
        if (hintEl) {
          if (valid) {
            hintEl.textContent = '';
            hintEl.classList.add('hidden');
          } else {
            var count = getFormCompleteCount();
            if (count === 0) {
              hintEl.textContent = 'Complete the fields above to continue.';
              hintEl.classList.remove('reg-hint-complete');
            } else {
              hintEl.innerHTML = '<span class="reg-hint-count">' + count + '</span> of 8 complete — fill all required fields to submit.';
              hintEl.classList.toggle('reg-hint-complete', count >= 8);
            }
            hintEl.classList.remove('hidden');
          }
        }
      }
      document.querySelectorAll('[data-float-wrap]').forEach(function (wrap) {
        var input = wrap.querySelector('input');
        if (!input) return;
        input.addEventListener('focus', function () { updateFloatLabel(wrap, input); });
        input.addEventListener('blur', function () { updateFloatLabel(wrap, input); });
        input.addEventListener('input', function () {
          updateFloatLabel(wrap, input);
          input.removeAttribute('aria-invalid');
          input.classList.remove('is-invalid');
          var errId = input.id === 'reg-full_name' ? 'reg-error-full_name' : input.id === 'reg-email' ? 'reg-error-email' : input.id === 'register-password' ? 'reg-error-password' : null;
          if (errId) { var e = document.getElementById(errId); if (e) e.textContent = ''; }
        });
        updateFloatLabel(wrap, input);
      });
      var fullNameInput = document.getElementById('reg-full_name');
      if (fullNameInput) {
        fullNameInput.addEventListener('input', function () {
          var v = this.value;
          var normalized = normalizeFullNameInput(v);
          if (normalized.length > 0 && /[a-z]/.test(normalized.charAt(0))) {
            normalized = normalized.charAt(0).toUpperCase() + normalized.slice(1);
          }
          if (normalized !== v) {
            var sel = this.selectionStart;
            this.value = normalized;
            this.setSelectionRange(Math.min(sel, normalized.length), Math.min(sel, normalized.length));
          }
        });
        fullNameInput.addEventListener('blur', function () {
          var v = this.value.trim().replace(/\s+/g, ' ');
          if (v) this.value = formatFullNameCapitalize(v);
        });
      }
      var emailInput = document.getElementById('reg-email');
      var emailCheckTimeout = null;
      if (emailInput) {
        emailInput.addEventListener('input', function () {
          var v = this.value;
          if (/\s/.test(v)) {
            var sel = this.selectionStart;
            this.value = v.replace(/\s/g, '');
            this.setSelectionRange(Math.min(sel, this.value.length), Math.min(sel, this.value.length));
          }
        });
        emailInput.addEventListener('blur', function () {
          var em = (this.value || '').trim();
          if (!em || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(em)) return;
          if (emailCheckTimeout) clearTimeout(emailCheckTimeout);
          emailCheckTimeout = setTimeout(function () {
            fetch('check_email.php?email=' + encodeURIComponent(em))
              .then(function (r) { return r.json(); })
              .then(function (data) {
                if (data.available === false && data.message) {
                  setInlineError('email', data.message);
                  if (emailInput) { emailInput.setAttribute('aria-invalid', 'true'); emailInput.classList.add('is-invalid'); }
                }
              })
              .catch(function () {});
          }, 400);
        });
      }
      document.querySelectorAll('select.auth-input').forEach(function (sel) {
        sel.addEventListener('change', function () {
          sel.removeAttribute('aria-invalid');
          sel.classList.remove('is-invalid');
          var errId = sel.id === 'reg-school' ? 'reg-error-school' : sel.id === 'reg-review_type' ? 'reg-error-review_type' : null;
          if (errId) { var e = document.getElementById(errId); if (e) e.textContent = ''; }
        });
      });
      if (document.getElementById('reg-error-alert')) {
        var firstInput = regForm && regForm.querySelector('.auth-input');
        if (firstInput) {
          firstInput.setAttribute('aria-invalid', 'true');
          firstInput.classList.add('is-invalid');
          firstInput.focus();
        }
      }

      if (togglePasswordButton && togglePasswordIcon && passwordInput) {
        togglePasswordButton.addEventListener('click', function () {
          var isPassword = passwordInput.type === 'password';
          passwordInput.type = isPassword ? 'text' : 'password';
          togglePasswordIcon.classList.remove('bi-eye-fill', 'bi-eye-slash-fill');
          togglePasswordIcon.classList.add(isPassword ? 'bi-eye-fill' : 'bi-eye-slash-fill');
        });
      }
      if (toggleConfirmBtn && toggleConfirmIcon && confirmInput) {
        toggleConfirmBtn.addEventListener('click', function () {
          var isPassword = confirmInput.type === 'password';
          confirmInput.type = isPassword ? 'text' : 'password';
          toggleConfirmIcon.classList.remove('bi-eye-fill', 'bi-eye-slash-fill');
          toggleConfirmIcon.classList.add(isPassword ? 'bi-eye-fill' : 'bi-eye-slash-fill');
        });
      }
      if (passwordInput) {
        passwordInput.addEventListener('input', function () {
          var v = this.value;
          if (/\s/.test(v)) {
            this.value = v.replace(/\s/g, '');
            updateStrengthBar(this.value);
            updateChecklist(this.value);
            updateConfirmFeedback();
          }
        });
      }
      if (confirmInput) {
        confirmInput.addEventListener('input', function () {
          var v = this.value;
          if (/\s/.test(v)) this.value = v.replace(/\s/g, '');
          updateConfirmFeedback();
        });
      }

      function checkPasswordRequirement(pw, key) {
        switch (key) {
          case 'length': return pw.length >= 8;
          case 'number': return /\d/.test(pw);
          case 'upper': return /[A-Z]/.test(pw);
          case 'lower': return /[a-z]/.test(pw);
          case 'symbol': return /[^A-Za-z0-9]/.test(pw);
          default: return false;
        }
      }
      var strengthLevels = ['weak', 'fair', 'good', 'strong', 'very-strong'];
      var strengthLabels = { 'weak': 'Weak', 'fair': 'Fair', 'good': 'Good', 'strong': 'Strong', 'very-strong': 'Very strong' };
      function getPasswordStrengthLevel(pw) {
        if (!pw || pw.length === 0) return { level: '', label: '—' };
        var score = 0;
        if (pw.length >= 8) score++;
        if (pw.length >= 10) score++;
        if (/[A-Z]/.test(pw) && /[a-z]/.test(pw)) score++;
        if (/\d/.test(pw)) score++;
        if (/[^A-Za-z0-9]/.test(pw)) score++;
        if (pw.length >= 12) score++;
        if (score <= 1) return { level: 'weak', label: 'Weak' };
        if (score <= 2) return { level: 'fair', label: 'Fair' };
        if (score <= 4) return { level: 'good', label: 'Good' };
        if (score <= 5) return { level: 'strong', label: 'Strong' };
        return { level: 'very-strong', label: 'Very strong' };
      }
      function updateStrengthBar(pw) {
        var fill = document.getElementById('reg-pw-strength-fill');
        var label = document.getElementById('reg-pw-strength-label');
        var wrap = document.getElementById('reg-pw-strength-wrap');
        if (!fill || !label) return;
        var r = getPasswordStrengthLevel(pw);
        strengthLevels.forEach(function (l) {
          fill.classList.remove(l);
          label.classList.remove(l);
        });
        if (r.level) {
          fill.classList.add(r.level);
          label.classList.add(r.level);
          label.textContent = r.label;
        } else {
          label.textContent = r.label;
        }
        var bar = wrap ? wrap.querySelector('.reg-pw-strength-bar') : null;
        if (bar) {
          var val = r.level === 'weak' ? 1 : r.level === 'fair' ? 2 : r.level === 'good' ? 3 : r.level === 'strong' ? 4 : r.level === 'very-strong' ? 5 : 0;
          bar.setAttribute('aria-valuenow', val);
        }
      }
      function updateChecklist(pw) {
        ['length', 'number', 'upper', 'lower', 'symbol'].forEach(function (key) {
          var el = document.getElementById('reg-pw-check-' + key);
          if (!el) return;
          var met = checkPasswordRequirement(pw, key);
          el.classList.toggle('met', met);
          var icon = el.querySelector('i');
          if (icon) {
            icon.classList.toggle('bi-circle', !met);
            icon.classList.toggle('bi-check-circle-fill', met);
          }
        });
      }
      function allPasswordChecksMet(pw) {
        return ['length', 'number', 'upper', 'lower', 'symbol'].every(function (key) { return checkPasswordRequirement(pw, key); });
      }
      var confirmSuccessEl = document.getElementById('reg-confirm-success');
      function updateConfirmFeedback() {
        var pw = (passwordInput ? passwordInput.value : '').trim();
        var conf = (confirmInput ? confirmInput.value : '').trim();
        if (confirmErrorEl) {
          if (!confirmInput || !confirmInput.value) {
            confirmErrorEl.textContent = '';
            confirmErrorEl.classList.add('hidden');
          } else if (pw !== conf) {
            confirmErrorEl.textContent = 'Passwords do not match.';
            confirmErrorEl.classList.remove('hidden');
          } else {
            confirmErrorEl.textContent = '';
            confirmErrorEl.classList.add('hidden');
          }
        }
        if (confirmSuccessEl) {
          if (conf && pw === conf && allPasswordChecksMet(pw)) {
            confirmSuccessEl.classList.remove('hidden');
          } else {
            confirmSuccessEl.classList.add('hidden');
          }
        }
        updateSubmitState();
      }
      if (passwordInput) {
        passwordInput.addEventListener('input', function () {
          var pw = passwordInput.value;
          updateStrengthBar(pw);
          updateChecklist(pw);
          updateConfirmFeedback();
        });
      }
      if (confirmInput && confirmErrorEl) {
        confirmInput.addEventListener('input', function () {
          updateConfirmFeedback();
        });
      }

      if (fileBrowse && fileInput) fileBrowse.addEventListener('click', function () { fileInput.click(); });
      if (fileZone && fileInput) {
        fileZone.addEventListener('dragover', function (e) { e.preventDefault(); fileZone.classList.add('dragover'); });
        fileZone.addEventListener('dragleave', function () { fileZone.classList.remove('dragover'); });
        fileZone.addEventListener('drop', function (e) {
          e.preventDefault();
          fileZone.classList.remove('dragover');
          if (e.dataTransfer.files.length) fileInput.files = e.dataTransfer.files;
          fileInput.dispatchEvent(new Event('change', { bubbles: true }));
        });
      }
      function formatSize(bytes) {
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / (1024 * 1024)).toFixed(2) + ' MB';
      }
      var allowedFileTypes = ['image/jpeg', 'image/jpg', 'image/png', 'application/pdf'];
      var allowedFileExt = ['jpg', 'jpeg', 'png', 'pdf'];
      var allowedAvatarTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp', 'image/gif'];
      var allowedAvatarExt = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
      function isAllowedPaymentFile(file) {
        if (!file) return false;
        var ext = (file.name.split('.').pop() || '').toLowerCase();
        return allowedFileTypes.indexOf(file.type) !== -1 && allowedFileExt.indexOf(ext) !== -1;
      }
      function showInvalidFileModal() {
        var errModal = document.getElementById('reg-modal-error');
        var errTitle = document.getElementById('reg-error-title');
        var errMsg = document.getElementById('reg-error-message');
        if (errModal && errTitle && errMsg) {
          errTitle.textContent = 'Invalid file type';
          errMsg.innerHTML = 'Please upload an image (JPG, PNG) or PDF file for payment verification.';
          errModal.classList.add('is-active');
        }
      }
      function showInvalidAvatarModal() {
        var errModal = document.getElementById('reg-modal-error');
        var errTitle = document.getElementById('reg-error-title');
        var errMsg = document.getElementById('reg-error-message');
        if (errModal && errTitle && errMsg) {
          errTitle.textContent = 'Invalid profile picture';
          errMsg.innerHTML = 'Please upload JPG, PNG, WEBP, or GIF only. Video, audio, and other file types are not allowed.';
          errModal.classList.add('is-active');
        }
      }
      if (fileInput && filePlaceholder && filePreview && fileThumb && fileNameEl && fileSizeEl && fileProgress && fileClear) {
        fileInput.addEventListener('change', function () {
          var file = this.files[0];
          if (!file) {
            filePlaceholder.classList.remove('hidden');
            filePreview.classList.add('hidden');
            fileProgress.style.width = '0%';
            if (fileZone) fileZone.classList.remove('has-file');
            return;
          }
          if (!isAllowedPaymentFile(file)) {
            this.value = '';
            filePlaceholder.classList.remove('hidden');
            filePreview.classList.add('hidden');
            if (fileZone) fileZone.classList.remove('has-file');
            setInlineError('payment_proof', 'Please upload an image (JPG, PNG) or PDF only.');
            if (fileZone) fileZone.classList.add('is-invalid');
            showInvalidFileModal();
            return;
          }
          setInlineError('payment_proof', '');
          if (fileZone) fileZone.classList.remove('is-invalid');
          if (fileZone) fileZone.classList.add('has-file');
          filePlaceholder.classList.add('hidden');
          filePreview.classList.remove('hidden');
          fileNameEl.textContent = file.name;
          fileSizeEl.textContent = formatSize(file.size);
          fileThumb.classList.add('hidden');
          if (file.type.indexOf('image/') === 0) {
            var url = URL.createObjectURL(file);
            fileThumb.src = url;
            fileThumb.alt = file.name;
            fileThumb.classList.remove('hidden');
          }
          fileProgress.style.width = '0%';
          var step = 0;
          var prog = setInterval(function () {
            step += 10;
            if (step >= 100) { clearInterval(prog); step = 100; }
            fileProgress.style.width = step + '%';
          }, 80);
        });
        fileClear.addEventListener('click', function () {
          fileInput.value = '';
          fileInput.dispatchEvent(new Event('change', { bubbles: true }));
        });
      }
      function syncAvatarZoneState() {
        if (!avatarInput || !avatarZone) return;
        avatarZone.classList.remove('opacity-70');
      }
      if (avatarBrowse && avatarInput) avatarBrowse.addEventListener('click', function () { avatarInput.click(); });
      if (avatarZone && avatarInput) {
        avatarZone.addEventListener('dragover', function (e) { e.preventDefault(); avatarZone.classList.add('dragover'); });
        avatarZone.addEventListener('dragleave', function () { avatarZone.classList.remove('dragover'); });
        avatarZone.addEventListener('drop', function (e) {
          e.preventDefault();
          avatarZone.classList.remove('dragover');
          if (e.dataTransfer.files.length) avatarInput.files = e.dataTransfer.files;
          avatarInput.dispatchEvent(new Event('change', { bubbles: true }));
        });
      }
      if (avatarInput && avatarPlaceholder && avatarPreview && avatarThumb && avatarNameEl && avatarSizeEl && avatarClear) {
        avatarInput.addEventListener('change', function () {
          var file = this.files[0];
          if (!file) {
            avatarPlaceholder.classList.remove('hidden');
            avatarPreview.classList.add('hidden');
            if (avatarZone) avatarZone.classList.remove('has-file');
            setInlineError('profile_picture', '');
            syncAvatarZoneState();
            updateSubmitState();
            return;
          }
          var ext = (file.name.split('.').pop() || '').toLowerCase();
          if (allowedAvatarTypes.indexOf(file.type) === -1 || allowedAvatarExt.indexOf(ext) === -1) {
            this.value = '';
            avatarPlaceholder.classList.remove('hidden');
            avatarPreview.classList.add('hidden');
            if (avatarZone) avatarZone.classList.remove('has-file');
            setInlineError('profile_picture', 'Please upload JPG, PNG, WEBP, or GIF only.');
            if (avatarZone) avatarZone.classList.add('is-invalid');
            showInvalidAvatarModal();
            updateSubmitState();
            return;
          }
          setInlineError('profile_picture', '');
          if (avatarZone) avatarZone.classList.remove('is-invalid');
          if (avatarZone) avatarZone.classList.add('has-file');
          avatarPlaceholder.classList.add('hidden');
          avatarPreview.classList.remove('hidden');
          avatarNameEl.textContent = file.name;
          avatarSizeEl.textContent = formatSize(file.size);
          avatarThumb.classList.add('hidden');
          if (file.type.indexOf('image/') === 0) {
            var aurl = URL.createObjectURL(file);
            avatarThumb.src = aurl;
            avatarThumb.alt = file.name;
            avatarThumb.classList.remove('hidden');
          }
          syncAvatarZoneState();
          updateSubmitState();
        });
        avatarClear.addEventListener('click', function () {
          avatarInput.value = '';
          avatarInput.dispatchEvent(new Event('change', { bubbles: true }));
        });
      }
      syncAvatarZoneState();

      if (regForm && regSubmitBtn && regSubmitText && regSubmitSpinner && regSubmitArrow) {
        regForm.addEventListener('submit', function (e) {
          e.preventDefault();
          var v = validateForm();
          if (!v.valid) {
            var errModal = document.getElementById('reg-modal-error');
            var errTitle = document.getElementById('reg-error-title');
            var errMsg = document.getElementById('reg-error-message');
            if (errModal && errTitle && errMsg) {
              errTitle.textContent = 'Please fix the following';
              errMsg.innerHTML = v.messages && v.messages.length
                ? '<ul class="reg-error-list">' + v.messages.map(function (m) { return '<li>' + (m || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</li>'; }).join('') + '</ul>'
                : 'Please complete all required fields.';
              errModal.classList.add('is-active');
            }
            scrollToFirstError(v.firstInvalid);
            if (v.firstInvalid) {
              if (typeof v.firstInvalid.focus === 'function') v.firstInvalid.focus();
              else if (v.firstInvalid.id === 'reg-file-zone') {
                var browse = document.getElementById('reg-file-browse');
                if (browse) browse.focus();
              }
            }
            return;
          }

          var loadingModal = document.getElementById('reg-modal-loading');
          var emailSentModal = document.getElementById('reg-modal-email-sent');
          var waitingModal = document.getElementById('reg-modal-waiting');
          var successModal = document.getElementById('reg-modal-success');
          var emailSentOk = document.getElementById('reg-modal-email-sent-ok');
          var emailInput = document.getElementById('reg-email');
          var pendingEmail = emailInput ? emailInput.value.trim() : '';

          regSubmitBtn.disabled = true;
          regSubmitText.textContent = 'Submitting…';
          regSubmitSpinner.classList.remove('hidden');
          regSubmitArrow.classList.add('hidden');
          if (loadingModal) loadingModal.classList.add('is-active');

          var formData = new FormData(regForm);
          formData.append('ajax', '1');
          fetch(regForm.action, {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
          }).then(function (r) { return r.json(); }).then(function (data) {
            if (loadingModal) loadingModal.classList.remove('is-active');
            if (data.success) {
              pendingEmail = data.email || pendingEmail;
              if (emailSentModal) emailSentModal.classList.add('is-active');
              if (emailSentOk) emailSentOk.addEventListener('click', function goNext() {
                emailSentOk.removeEventListener('click', goNext);
                if (emailSentModal) emailSentModal.classList.remove('is-active');
                if (waitingModal) waitingModal.classList.add('is-active');
                var pollUrl = 'check_verification.php?email=' + encodeURIComponent(pendingEmail);
                var pollInterval = setInterval(function () {
                  fetch(pollUrl).then(function (r) { return r.json(); }).then(function (res) {
                    if (res.verified) {
                      clearInterval(pollInterval);
                      if (waitingModal) waitingModal.classList.remove('is-active');
                      if (successModal) successModal.classList.add('is-active');
                    }
                  }).catch(function () {});
                }, 2500);
              }, { once: true });
            } else {
              regSubmitText.textContent = 'Submit registration';
              regSubmitSpinner.classList.add('hidden');
              regSubmitArrow.classList.remove('hidden');
              if (regSubmitBtn) regSubmitBtn.disabled = false;
              updateSubmitState();
              var errModal = document.getElementById('reg-modal-error');
              var errTitle = document.getElementById('reg-error-title');
              var errMsg = document.getElementById('reg-error-message');
              if (errModal && errTitle && errMsg) {
                errTitle.textContent = 'Registration issue';
                errMsg.textContent = data.error || 'Registration failed. Please try again.';
                errModal.classList.add('is-active');
              } else {
                alert(data.error || 'Registration failed. Please try again.');
              }
            }
          }).catch(function () {
            if (loadingModal) loadingModal.classList.remove('is-active');
            regSubmitText.textContent = 'Submit registration';
            regSubmitSpinner.classList.add('hidden');
            regSubmitArrow.classList.remove('hidden');
            if (regSubmitBtn) regSubmitBtn.disabled = false;
            updateSubmitState();
            var errModal = document.getElementById('reg-modal-error');
            var errTitle = document.getElementById('reg-error-title');
            var errMsg = document.getElementById('reg-error-message');
            if (errModal && errTitle && errMsg) {
              errTitle.textContent = 'Connection error';
              errMsg.textContent = 'Network error. Please try again.';
              errModal.classList.add('is-active');
            } else {
              alert('Network error. Please try again.');
            }
          });
        });
      }

      var regErrorClose = document.getElementById('reg-error-close');
      var regErrorModal = document.getElementById('reg-modal-error');
      if (regErrorClose && regErrorModal) {
        regErrorClose.addEventListener('click', function () {
          regErrorModal.classList.remove('is-active');
        });
        document.addEventListener('keydown', function closeOnEscape(e) {
          if (e.key === 'Escape' && regErrorModal.classList.contains('is-active')) {
            regErrorModal.classList.remove('is-active');
          }
        });
      }

      function updateSectionLabels() {
        var fullName = document.getElementById('reg-full_name');
        var email = document.getElementById('reg-email');
        var school = document.querySelector('select[name="school"]');
        var reviewType = document.querySelector('select[name="review_type"]');
        var schoolOther = document.getElementById('reg-school_other');
        var pw = passwordInput ? passwordInput.value : '';
        var confirm = confirmInput ? confirmInput.value : '';
        var file = document.getElementById('register-payment-proof');
        var accountComplete = fullName && fullName.value.trim() &&
          email && email.value.trim() && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.value.trim()) &&
          school && school.value && reviewType && reviewType.value &&
          (school.value !== 'Other' || (schoolOther && schoolOther.value.trim()));
        var securityComplete = pw && allPasswordChecksMet(pw) && confirm === pw;
        var paymentComplete = file && file.files && file.files.length > 0;
        var accountEl = document.getElementById('reg-label-account');
        var securityEl = document.getElementById('reg-label-security');
        var paymentEl = document.getElementById('reg-label-payment');
        if (accountEl) accountEl.classList.toggle('reg-section-label--complete', !!accountComplete);
        if (securityEl) securityEl.classList.toggle('reg-section-label--complete', !!securityComplete);
        if (paymentEl) paymentEl.classList.toggle('reg-section-label--complete', !!paymentComplete);
      }
      function updateRegProgress() {
        var bar = document.getElementById('reg-form-progress-bar');
        var fullName = document.getElementById('reg-full_name');
        var email = document.getElementById('reg-email');
        var school = document.querySelector('select[name="school"]');
        var reviewType = document.querySelector('select[name="review_type"]');
        var pw = document.getElementById('register-password');
        var confirm = document.getElementById('register-password-confirm');
        var file = document.getElementById('register-payment-proof');
        var n = 0;
        if (fullName && fullName.value.trim()) n++;
        if (email && email.value.trim()) n++;
        if (school && school.value) n++;
        if (reviewType && reviewType.value) n++;
        if (pw && pw.value) n++;
        if (confirm && confirm.value) n++;
        if (file && file.files && file.files.length) n++;
        var pct = n >= 7 ? 100 : Math.round((n / 7) * 100);
        if (bar) bar.style.width = pct + '%';
        updateSubmitState();
        updateSectionLabels();
      }
      ['reg-full_name', 'reg-email', 'register-password', 'register-password-confirm'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) el.addEventListener('input', updateRegProgress);
      });
      document.querySelectorAll('select[name="school"], select[name="review_type"]').forEach(function (el) {
        el.addEventListener('change', updateRegProgress);
      });
      if (fileInput) fileInput.addEventListener('change', updateRegProgress);
      updateRegProgress();

      var blurbEl = document.getElementById('reg-blurb-text');
      var leftBlurbEl = document.getElementById('reg-left-blurb');
      var blurbs = ['Master simulations with timed drills.', 'Benchmarked vs national pass rates.', 'Track your weakest topics in real time.'];
      if ((blurbEl || leftBlurbEl) && blurbs.length) {
        var blurbIndex = 0;
        setInterval(function () {
          if (blurbEl) blurbEl.style.opacity = '0';
          if (leftBlurbEl) leftBlurbEl.style.opacity = '0';
          setTimeout(function () {
            blurbIndex = (blurbIndex + 1) % blurbs.length;
            var t = blurbs[blurbIndex];
            if (blurbEl) { blurbEl.textContent = t; blurbEl.style.opacity = '1'; }
            if (leftBlurbEl) { leftBlurbEl.textContent = t; leftBlurbEl.style.opacity = '1'; }
          }, 450);
        }, 6000);
      }

      var regMetricsEl = document.getElementById('reg-metrics-text');
      var leftMetricsEl = document.getElementById('reg-left-metrics');
      var regMetricsSlides = [
        'FAR 78% · REG 82% · AUD 65%',
        'Mock exam cohort 1 71% · Drill sets 69%',
        'Tax intensive 74% · Practice quizzes 80%'
      ];
      if ((regMetricsEl || leftMetricsEl) && regMetricsSlides.length) {
        var regMetricsIndex = 0;
        setInterval(function () {
          if (regMetricsEl) regMetricsEl.style.opacity = '0';
          if (leftMetricsEl) leftMetricsEl.style.opacity = '0';
          setTimeout(function () {
            regMetricsIndex = (regMetricsIndex + 1) % regMetricsSlides.length;
            var m = regMetricsSlides[regMetricsIndex];
            if (regMetricsEl) { regMetricsEl.textContent = m; regMetricsEl.style.opacity = '1'; }
            if (leftMetricsEl) { leftMetricsEl.textContent = m; leftMetricsEl.style.opacity = '1'; }
          }, 450);
        }, 7000);
      }
    });
  </script>
</body>
</html>
