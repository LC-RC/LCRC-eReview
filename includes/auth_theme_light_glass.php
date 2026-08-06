<?php
/**
 * Premium SaaS glassmorphism auth theme (login, forgot/reset/magic).
 * Brand: LCRC Blue #1F58C3, Gold #F59E0B — translucent glass surfaces.
 */
?>
<style>
  :root {
    --auth-blue: #1F58C3;
    --auth-blue-dark: #1a4ba8;
    --auth-blue-deep: #153d8a;
    --auth-amber: #F59E0B;
    --auth-amber-soft: #FCD34D;
    --auth-ink: #0f172a;
    --auth-muted: #64748b;
    --auth-soft: #94a3b8;
    --auth-input-icon: #003865;
    --auth-input-icon-hover: #002844;
    --auth-glass: rgba(255, 255, 255, 0.52);
    --auth-glass-strong: rgba(255, 255, 255, 0.72);
    --auth-space-1: 8px;
    --auth-space-2: 16px;
    --auth-space-3: 24px;
    --auth-radius-card: 22px;
    --auth-radius-ctl: 14px;
    --auth-ease: cubic-bezier(0.22, 1, 0.36, 1);
    --auth-dur: 230ms;
  }

  body.login-prototype {
    background:
      radial-gradient(120% 90% at 0% 0%, rgba(31, 88, 195, 0.16) 0%, transparent 62%),
      radial-gradient(100% 80% at 100% 0%, rgba(245, 158, 11, 0.12) 0%, transparent 58%),
      radial-gradient(90% 70% at 50% 100%, rgba(31, 88, 195, 0.08) 0%, transparent 60%),
      radial-gradient(70% 55% at 70% 45%, rgba(147, 197, 253, 0.1) 0%, transparent 65%),
      radial-gradient(60% 50% at 25% 60%, rgba(245, 158, 11, 0.06) 0%, transparent 70%),
      linear-gradient(168deg, #f5f8fd 0%, #eef3fa 42%, #f8fafc 100%) !important;
    color: var(--auth-ink);
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
    text-rendering: optimizeLegibility;
    font-feature-settings: "kern" 1, "liga" 1;
    min-height: 100vh;
    min-height: 100dvh;
    overflow-x: hidden;
  }
  body.login-prototype .animated-bg {
    background: transparent !important;
  }
  body.login-prototype .animated-bg::before,
  body.login-prototype .animated-bg::after {
    display: none !important;
  }

  /* Soften legacy corner chips — keep presence, reduce visual noise */
  body.login-prototype .auth-corner-decor::before,
  body.login-prototype .auth-corner-decor::after {
    width: 64px;
    height: 40px;
    background: rgba(255, 255, 255, 0.35);
    border: 1px solid rgba(255, 255, 255, 0.45);
    border-radius: 12px;
    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);
    box-shadow: 0 12px 32px -20px rgba(15, 23, 42, 0.25);
    opacity: 0.55;
  }
  body.login-prototype .auth-corner-dot {
    width: 4px;
    height: 4px;
    background: rgba(245, 158, 11, 0.75);
    box-shadow: 0 0 12px rgba(245, 158, 11, 0.35);
  }
  body.login-prototype .auth-corner-dot.blue {
    background: rgba(31, 88, 195, 0.75);
    box-shadow: 0 0 12px rgba(31, 88, 195, 0.35);
  }

  body.login-prototype .circuit-bg {
    position: fixed;
    inset: 0;
    z-index: 0;
    pointer-events: none;
    background-image:
      linear-gradient(rgba(31, 88, 195, 0.035) 1px, transparent 1px),
      linear-gradient(90deg, rgba(31, 88, 195, 0.035) 1px, transparent 1px);
    background-size: 48px 48px;
    mask-image: radial-gradient(ellipse 80% 70% at 50% 45%, #000 20%, transparent 75%);
    -webkit-mask-image: radial-gradient(ellipse 80% 70% at 50% 45%, #000 20%, transparent 75%);
    animation: login-bg-grid-pulse 14s ease-in-out infinite;
    opacity: 0.7;
  }
  @keyframes login-bg-grid-pulse {
    0%, 100% { opacity: 0.4; }
    50% { opacity: 0.75; }
  }

  body.login-prototype .login-bg-animation {
    position: fixed;
    inset: 0;
    z-index: 0;
    pointer-events: none;
    overflow: hidden;
  }
  .login-bg-node {
    position: absolute;
    width: 6px;
    height: 6px;
    border-radius: 50%;
    animation: login-bg-float 26s ease-in-out infinite;
  }
  .login-bg-node--blue {
    background: rgba(31, 88, 195, 0.4);
    box-shadow: 0 0 20px rgba(31, 88, 195, 0.35);
    left: var(--x, 15%);
    top: var(--y, 25%);
    animation-delay: var(--delay, 0s);
    animation-duration: var(--dur, 22s);
  }
  .login-bg-node--gold {
    background: rgba(245, 158, 11, 0.45);
    box-shadow: 0 0 18px rgba(245, 158, 11, 0.3);
    left: var(--x, 80%);
    top: var(--y, 70%);
    animation-delay: var(--delay, 2s);
    animation-duration: var(--dur, 26s);
  }
  .login-bg-node--white {
    background: rgba(255, 255, 255, 0.85);
    box-shadow: 0 0 14px rgba(148, 163, 184, 0.4);
    left: var(--x, 70%);
    top: var(--y, 15%);
    animation-delay: var(--delay, 1s);
    animation-duration: var(--dur, 28s);
  }
  @keyframes login-bg-float {
    0%, 100% { transform: translate(0, 0) scale(1); opacity: 0.55; }
    25% { transform: translate(10px, -14px) scale(1.08); opacity: 0.9; }
    50% { transform: translate(-6px, 8px) scale(0.94); opacity: 0.65; }
    75% { transform: translate(-12px, -6px) scale(1.04); opacity: 0.8; }
  }
  .login-bg-lines { position: absolute; inset: 0; opacity: 0.4; }
  .login-bg-lines svg { width: 100%; height: 100%; }
  .login-bg-lines .line {
    fill: none;
    stroke-width: 0.6;
    stroke-linecap: round;
    animation: login-bg-line-flow 22s linear infinite;
  }
  .login-bg-lines .line--blue { stroke: rgba(31, 88, 195, 0.18); }
  .login-bg-lines .line--gold { stroke: rgba(245, 158, 11, 0.16); animation-delay: -5s; }
  .login-bg-lines .line--white { stroke: rgba(148, 163, 184, 0.14); animation-delay: -10s; }
  @keyframes login-bg-line-flow {
    0% { stroke-dashoffset: 0; }
    100% { stroke-dashoffset: -200; }
  }

  /* Soft atmospheric wash — organic, not hard circular discs */
  .login-bg-blob {
    position: fixed;
    left: 50%;
    top: 46%;
    transform: translate(-50%, -50%);
    width: min(140vw, 960px);
    height: min(95vw, 700px);
    border-radius: 62% 38% 48% 52% / 42% 58% 42% 58%;
    background:
      radial-gradient(ellipse 70% 55% at 30% 30%, rgba(31, 88, 195, 0.22) 0%, transparent 70%),
      radial-gradient(ellipse 60% 50% at 72% 68%, rgba(245, 158, 11, 0.14) 0%, transparent 72%),
      radial-gradient(ellipse 55% 45% at 55% 40%, rgba(186, 214, 255, 0.18) 0%, transparent 68%);
    filter: blur(72px) saturate(1.05);
    z-index: 0;
    pointer-events: none;
    opacity: 0.75;
    animation: login-blob-drift 28s ease-in-out infinite;
  }
  @keyframes login-blob-drift {
    0%, 100% { transform: translate(-50%, -50%) scale(1) rotate(0deg); }
    40% { transform: translate(-51.5%, -48%) scale(1.03) rotate(1.2deg); }
    70% { transform: translate(-48.5%, -51%) scale(0.99) rotate(-0.8deg); }
  }

  .login-cpa-visual {
    position: fixed;
    inset: 0;
    z-index: 0;
    pointer-events: none;
    opacity: 0.12;
  }
  .login-cpa-visual svg { width: 100%; height: 100%; object-fit: cover; }
  .login-cpa-visual .cpa-ring {
    fill: none;
    stroke-width: 1.5;
    stroke-linecap: round;
    stroke: rgba(31, 88, 195, 0.45);
    animation: cpa-ring-pulse 10s ease-in-out infinite;
  }
  .login-cpa-visual .cpa-line {
    fill: none;
    stroke: rgba(31, 88, 195, 0.25);
    stroke-width: 0.7;
    stroke-dasharray: 4 6;
    animation: cpa-line-flow 28s linear infinite;
  }
  @keyframes cpa-ring-pulse {
    0%, 100% { opacity: 0.45; stroke-dashoffset: 0; }
    50% { opacity: 0.9; stroke-dashoffset: -30; }
  }
  @keyframes cpa-line-flow {
    0% { stroke-dashoffset: 0; }
    100% { stroke-dashoffset: -200; }
  }
  .login-cashflow-path {
    position: fixed;
    inset: 0;
    z-index: 0;
    pointer-events: none;
    opacity: 0.18;
  }
  .login-cashflow-path svg { width: 100%; height: 100%; }
  .login-cashflow-path .path {
    fill: none;
    stroke: rgba(245, 158, 11, 0.4);
    stroke-width: 1;
    stroke-dasharray: 120 80;
    animation: login-cashflow-draw 20s linear infinite;
  }
  @keyframes login-cashflow-draw {
    0% { stroke-dashoffset: 0; }
    100% { stroke-dashoffset: -400; }
  }

  @media (prefers-reduced-motion: reduce) {
    .login-bg-blob,
    .login-cpa-visual .cpa-ring,
    .login-cpa-visual .cpa-line,
    .login-cashflow-path .path,
    .login-bg-node,
    .login-bg-lines .line { animation: none; }
    body.login-prototype .circuit-bg { animation: none; opacity: 0.5; }
    body.login-prototype .login-card,
    body.login-prototype .login-card-wrap { animation: none !important; }
    body.login-prototype .login-card #login-submit:hover,
    body.login-prototype .login-card .login-google-btn:hover,
    body.login-prototype .login-card .login-magic-btn:hover,
    body.login-prototype .login-card .auth-submit-btn:hover { transform: none; }
    body.login-prototype .login-logo-hover:hover { transform: none; filter: none; }
    body.login-prototype .login-card .login-submit-spinner .bi { animation: none; }
  }

  @media (hover: none) and (pointer: coarse) {
    body.login-prototype .login-card .auth-input { min-height: 52px !important; }
    body.login-prototype .login-card #login-submit,
    body.login-prototype .login-card .login-google-btn,
    body.login-prototype .login-card .login-magic-btn,
    body.login-prototype .login-card .auth-submit-btn { min-height: 48px !important; }
    body.login-prototype .login-card #toggle-password {
      min-width: 44px !important;
      min-height: 44px !important;
    }
  }

  /* Centered viewport stage — footer overlays so it never pushes the card */
  body.login-prototype .login-page-layout {
    min-height: 100vh;
    min-height: 100dvh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px 24px 48px;
    box-sizing: border-box;
  }
  body.login-prototype .login-card-wrap {
    max-width: 520px !important;
    width: 100%;
    animation: login-card-enter 640ms var(--auth-ease) both;
  }
  @keyframes login-card-enter {
    from {
      opacity: 0;
      transform: translateY(16px) scale(0.985);
    }
    to {
      opacity: 1;
      transform: translateY(0) scale(1);
    }
  }

  /* Frosted glass card — proportions locked; finish materials only */
  body.login-prototype .login-card {
    position: relative;
    overflow: hidden;
    isolation: isolate;
    background: rgba(255, 255, 255, 0.36) !important;
    border: 1px solid rgba(255, 255, 255, 0.52) !important;
    box-shadow:
      inset 0 1px 0 rgba(255, 255, 255, 0.88),
      inset 0 -1px 0 rgba(255, 255, 255, 0.1),
      inset 0 0 0 1px rgba(255, 255, 255, 0.16),
      0 1px 2px rgba(15, 23, 42, 0.035),
      0 8px 16px -6px rgba(15, 23, 42, 0.055),
      0 24px 48px -20px rgba(15, 23, 42, 0.13),
      0 40px 80px -36px rgba(31, 88, 195, 0.2) !important;
    border-radius: var(--auth-radius-card) !important;
    padding: 44px 40px 40px !important;
    backdrop-filter: blur(22px) saturate(1.65);
    -webkit-backdrop-filter: blur(22px) saturate(1.65);
    transition: box-shadow var(--auth-dur) var(--auth-ease);
  }
  /* Soft upper-left glass reflection */
  body.login-prototype .login-card::before {
    content: '';
    position: absolute;
    inset: 0;
    border-radius: inherit;
    pointer-events: none;
    z-index: 0;
    background:
      radial-gradient(ellipse 85% 55% at 8% 0%, rgba(255, 255, 255, 0.55) 0%, transparent 55%),
      linear-gradient(128deg, rgba(255, 255, 255, 0.42) 0%, rgba(255, 255, 255, 0.08) 28%, transparent 52%),
      linear-gradient(180deg, rgba(255, 255, 255, 0.14) 0%, transparent 32%);
    opacity: 1;
  }
  body.login-prototype .login-card::after {
    content: '';
    position: absolute;
    inset: 0;
    border-radius: inherit;
    pointer-events: none;
    z-index: 0;
    box-shadow:
      inset 0 0 0 1px rgba(255, 255, 255, 0.42),
      inset 0 12px 28px -20px rgba(255, 255, 255, 0.55);
  }
  body.login-prototype .login-card > * {
    position: relative;
    z-index: 1;
  }
  body.login-prototype .login-card:focus-within {
    box-shadow:
      inset 0 1px 0 rgba(255, 255, 255, 0.9),
      inset 0 -1px 0 rgba(255, 255, 255, 0.14),
      inset 0 0 0 1px rgba(255, 255, 255, 0.22),
      0 1px 2px rgba(15, 23, 42, 0.04),
      0 10px 20px -8px rgba(15, 23, 42, 0.07),
      0 28px 56px -22px rgba(31, 88, 195, 0.2),
      0 0 0 1px rgba(31, 88, 195, 0.08) !important;
  }

  /* Hierarchy — intentional major/minor gaps (card size unchanged) */
  body.login-prototype .login-header {
    margin-bottom: 20px !important;
  }
  body.login-prototype .login-logo-wrap {
    margin-bottom: 10px !important;
  }
  body.login-prototype .login-logo-hover {
    transition: transform var(--auth-dur) var(--auth-ease), filter var(--auth-dur) var(--auth-ease);
  }
  body.login-prototype .login-logo-hover:hover {
    transform: scale(1.03);
    filter: drop-shadow(0 8px 18px rgba(31, 88, 195, 0.24));
  }
  body.login-prototype .login-logo-img {
    height: 3.5rem; /* ~8% larger */
    width: auto;
    max-width: 160px;
    object-fit: contain;
    object-position: center;
    display: block;
  }
  body.login-prototype .login-card .brand-text {
    font-size: 1.25rem;
    font-weight: 800;
    letter-spacing: -0.03em;
    line-height: 1.15;
  }
  body.login-prototype .login-card .brand-text .blue { color: var(--auth-blue); }
  body.login-prototype .login-card .brand-text .amber { color: var(--auth-amber); }

  body.login-prototype .login-welcome {
    margin-bottom: 32px !important; /* Header → Form (major) */
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
  }
  body.login-prototype .login-card h1 {
    color: var(--auth-ink) !important;
    font-size: 1.45rem !important;
    font-weight: 800 !important;
    letter-spacing: -0.035em;
    margin: 0 !important;
    line-height: 1.2;
  }
  body.login-prototype .login-value-statement {
    font-size: 0.875rem;
    font-weight: 500;
    color: #556274;
    margin: 0 !important;
    line-height: 1.45;
    max-width: 24rem;
  }
  body.login-prototype .login-card .subtext,
  body.login-prototype .login-card .login-signup-line {
    color: #556274;
    font-size: 0.8125rem;
    margin: 0 !important;
    font-weight: 500;
  }
  body.login-prototype .login-card .subtext a,
  body.login-prototype .login-card .auth-back-link,
  body.login-prototype .login-card .login-forgot-link {
    color: var(--auth-amber) !important;
    font-weight: 700;
    text-decoration: none;
    transition: color var(--auth-dur) var(--auth-ease), opacity var(--auth-dur) var(--auth-ease);
  }
  body.login-prototype .login-card .subtext a:hover,
  body.login-prototype .login-card .auth-back-link:hover,
  body.login-prototype .login-card .login-forgot-link:hover {
    color: #d97706 !important;
    text-decoration: underline;
    text-underline-offset: 3px;
  }
  body.login-prototype .login-card label:not(.float-label) {
    color: var(--auth-ink) !important;
    font-weight: 600;
  }

  /*
   * Intentional hierarchy (not equal gaps):
   * related fields tighter · major section breaks wider
   */
  body.login-prototype .login-form-fields {
    display: flex;
    flex-direction: column;
    gap: 0;
  }
  body.login-prototype .login-card .login-field + .login-field { margin-top: 28px; } /* Email → Password */
  body.login-prototype .login-card .login-meta-row { margin-top: 20px; } /* Password → Remember */
  body.login-prototype .login-card .login-piece-6 { margin-top: 32px !important; } /* Form → Login (major) */
  body.login-prototype .login-card #login-submit {
    display: inline-flex;
    width: 100%;
    align-items: center;
    justify-content: center;
    gap: 8px;
    cursor: pointer;
  }
  body.login-prototype .sr-only {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border: 0;
  }

  /* ── Glass inputs ── */
  body.login-prototype .login-card .float-label-wrap {
    position: relative;
  }
  body.login-prototype .login-card .float-label-wrap .float-label {
    position: absolute;
    left: 48px; /* matches icon column — equal left alignment resting + floated */
    top: 50%;
    transform: translateY(-50%);
    font-size: 0.9375rem;
    font-weight: 500;
    color: #64748b !important;
    pointer-events: none;
    transition:
      top var(--auth-dur) var(--auth-ease),
      font-size var(--auth-dur) var(--auth-ease),
      color var(--auth-dur) var(--auth-ease),
      transform var(--auth-dur) var(--auth-ease),
      background var(--auth-dur) var(--auth-ease),
      padding var(--auth-dur) var(--auth-ease),
      letter-spacing var(--auth-dur) var(--auth-ease),
      box-shadow var(--auth-dur) var(--auth-ease);
    z-index: 2;
    max-width: calc(100% - 5.5rem);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }
  /* Floated label clears the border — soft glass chip, same left edge */
  body.login-prototype .login-card .float-label-wrap.focused .float-label,
  body.login-prototype .login-card .float-label-wrap.has-value .float-label,
  body.login-prototype .login-card .float-label-wrap:has(.auth-input:focus) .float-label,
  body.login-prototype .login-card .float-label-wrap:has(.auth-input:not(:placeholder-shown)) .float-label,
  body.login-prototype .login-card .float-label-wrap:has(.auth-input:-webkit-autofill) .float-label {
    top: 0;
    transform: translateY(-70%);
    font-size: 0.6875rem;
    font-weight: 700;
    letter-spacing: 0.03em;
    color: var(--auth-blue) !important;
    background:
      linear-gradient(180deg, rgba(255, 255, 255, 0.15) 0%, rgba(255, 255, 255, 0.78) 42%, rgba(255, 255, 255, 0.78) 100%);
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
    padding: 0 8px;
    border-radius: 6px;
    left: 48px;
    z-index: 5;
    box-shadow: none;
  }

  body.login-prototype .login-card .auth-input {
    width: 100%;
    padding: 14px 16px 14px 48px !important;
    min-height: 54px;
    height: 54px;
    border-radius: var(--auth-radius-ctl) !important;
    background: rgba(255, 255, 255, 0.28) !important;
    border: 1px solid rgba(255, 255, 255, 0.5) !important;
    outline: none !important;
    color: var(--auth-ink) !important;
    font-size: 0.9375rem !important;
    font-weight: 500;
    letter-spacing: -0.01em;
    box-shadow:
      inset 0 1px 0 rgba(255, 255, 255, 0.55),
      inset 0 1px 2px rgba(15, 23, 42, 0.04),
      0 1px 1px rgba(15, 23, 42, 0.02) !important;
    backdrop-filter: blur(16px);
    -webkit-backdrop-filter: blur(16px);
    transition:
      border-color var(--auth-dur) var(--auth-ease),
      box-shadow var(--auth-dur) var(--auth-ease),
      background var(--auth-dur) var(--auth-ease);
    -webkit-appearance: none;
    appearance: none;
  }
  @keyframes login-autofill-on {
    from { opacity: 0.99; }
    to { opacity: 1; }
  }
  body.login-prototype .login-card .auth-input:-webkit-autofill,
  body.login-prototype .login-card .auth-input:-webkit-autofill:hover,
  body.login-prototype .login-card .auth-input:-webkit-autofill:focus {
    -webkit-text-fill-color: var(--auth-ink) !important;
    box-shadow: 0 0 0 1000px rgba(255, 255, 255, 0.75) inset !important;
    transition: background-color 99999s ease-out;
    animation-name: login-autofill-on;
    animation-duration: 0.001s;
    border-radius: var(--auth-radius-ctl);
  }
  body.login-prototype .login-card .auth-input::placeholder { color: transparent; }
  body.login-prototype .login-card .auth-input:hover {
    background: rgba(255, 255, 255, 0.4) !important;
    border-color: rgba(255, 255, 255, 0.72) !important;
    box-shadow:
      inset 0 1px 0 rgba(255, 255, 255, 0.65),
      inset 0 1px 2px rgba(15, 23, 42, 0.03),
      0 2px 10px rgba(31, 88, 195, 0.05) !important;
  }
  body.login-prototype .login-card .auth-input:focus {
    background: rgba(255, 255, 255, 0.48) !important;
    border-color: rgba(31, 88, 195, 0.42) !important;
    box-shadow:
      0 0 0 3px rgba(31, 88, 195, 0.1),
      0 0 16px rgba(31, 88, 195, 0.14),
      inset 0 1px 0 rgba(255, 255, 255, 0.7),
      inset 0 1px 2px rgba(15, 23, 42, 0.025) !important;
  }
  body.login-prototype .login-card .auth-input.border-red-500,
  body.login-prototype .login-card .auth-input[aria-invalid="true"] {
    border-color: rgba(239, 68, 68, 0.55) !important;
    box-shadow:
      0 0 0 4px rgba(239, 68, 68, 0.1),
      0 0 0 1px rgba(239, 68, 68, 0.35) !important;
  }
  body.login-prototype .login-card .auth-input:focus-visible,
  body.login-prototype .login-card #login-submit:focus-visible,
  body.login-prototype .login-card .login-google-btn:focus-visible,
  body.login-prototype .login-card .login-magic-btn:focus-visible,
  body.login-prototype .login-card .auth-submit-btn:focus-visible,
  body.login-prototype .login-card .subtext a:focus-visible,
  body.login-prototype .login-card .login-forgot-link:focus-visible {
    outline: none;
    box-shadow: 0 0 0 2px #fff, 0 0 0 4px var(--auth-blue) !important;
  }

  body.login-prototype .login-card .auth-input-icon-wrap {
    width: 48px;
    left: 0;
    top: 0;
    bottom: 0;
    height: 100%;
    pointer-events: none;
    display: flex !important;
    align-items: center;
    justify-content: center;
    padding: 0;
    z-index: 4 !important;
    opacity: 1 !important;
    color: #003865 !important;
  }
  body.login-prototype .login-card .auth-input-icon-wrap .bi,
  body.login-prototype .login-card .auth-input-icon-wrap .bi::before,
  body.login-prototype .login-card .input-icon .bi,
  body.login-prototype .login-card .input-icon .bi::before,
  body.login-prototype .login-card #toggle-password .bi,
  body.login-prototype .login-card #toggle-password .bi::before,
  body.login-prototype .login-card .auth-toggle-password .bi,
  body.login-prototype .login-card .auth-toggle-password .bi::before {
    display: block;
    line-height: 1;
    font-size: 1.125rem;
    opacity: 1 !important;
    color: #003865 !important;
    -webkit-text-fill-color: #003865 !important;
    filter: none !important;
  }
  body.login-prototype .login-card .float-label-wrap .auth-input {
    position: relative;
    z-index: 1;
  }

  body.login-prototype .login-card .auth-password-wrap { position: relative; }
  body.login-prototype .login-card .auth-password-wrap .auth-input {
    padding-right: 48px !important;
  }
  body.login-prototype .login-card #toggle-password,
  body.login-prototype .login-card .auth-toggle-password {
    position: absolute !important;
    right: 8px !important;
    top: 50% !important;
    transform: translateY(-50%) !important;
    width: 36px;
    height: 36px;
    min-width: 36px;
    min-height: 36px;
    padding: 0 !important;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    z-index: 4 !important;
    color: #003865 !important;
    background: transparent !important;
    border: none !important;
    border-radius: 10px;
    transition: color var(--auth-dur) ease, background var(--auth-dur) ease;
  }
  body.login-prototype .login-card #toggle-password:hover,
  body.login-prototype .login-card .auth-toggle-password:hover {
    color: #002844 !important;
    background: rgba(31, 88, 195, 0.08) !important;
  }
  body.login-prototype .login-card #toggle-password:focus-visible,
  body.login-prototype .login-card .auth-toggle-password:focus-visible {
    outline: none;
    box-shadow: 0 0 0 2px #fff, 0 0 0 4px var(--auth-blue) !important;
  }

  body.login-prototype .login-card #email-error,
  body.login-prototype .login-card #password-error,
  body.login-prototype .login-card .login-field-error {
    color: #dc2626 !important;
    font-size: 0.78rem !important;
    font-weight: 500;
    line-height: 1.35;
    min-height: 0;
    margin: 6px 0 0 !important;
    padding-left: 2px;
  }
  body.login-prototype .login-card .login-field-error:empty {
    display: none;
  }

  /* Remember me + Forgot — vertically centered row */
  body.login-prototype .login-card .login-meta-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    min-height: 32px;
  }
  body.login-prototype .login-card .login-remember-row {
    display: flex;
    align-items: center;
    gap: 10px;
    margin: 0 !important;
    padding: 0;
    min-height: 32px;
  }
  body.login-prototype .login-card .login-remember-check {
    appearance: none;
    -webkit-appearance: none;
    width: 17px;
    height: 17px;
    margin: 0;
    flex-shrink: 0;
    border: 1.5px solid rgba(31, 88, 195, 0.32);
    border-radius: 5px;
    background: rgba(255, 255, 255, 0.45);
    cursor: pointer;
    transition:
      border-color var(--auth-dur) var(--auth-ease),
      background var(--auth-dur) var(--auth-ease),
      box-shadow var(--auth-dur) var(--auth-ease);
    position: relative;
  }
  body.login-prototype .login-card .login-remember-check:checked {
    background: var(--auth-blue);
    border-color: var(--auth-blue);
  }
  body.login-prototype .login-card .login-remember-check:checked::after {
    content: '';
    position: absolute;
    left: 4.5px;
    top: 1.5px;
    width: 4.5px;
    height: 8.5px;
    border: solid #fff;
    border-width: 0 2px 2px 0;
    transform: rotate(45deg);
  }
  body.login-prototype .login-card .login-remember-check:hover {
    border-color: var(--auth-blue);
    box-shadow: 0 0 0 3px rgba(31, 88, 195, 0.1);
  }
  body.login-prototype .login-card .login-remember-check:focus-visible {
    outline: none;
    box-shadow: 0 0 0 2px #fff, 0 0 0 4px var(--auth-blue);
  }
  body.login-prototype .login-card .login-remember-label {
    display: inline-flex;
    align-items: center;
    color: var(--auth-ink) !important;
    line-height: 1;
    min-width: 0;
    margin: 0;
  }
  body.login-prototype .login-card .login-remember-title {
    color: var(--auth-ink) !important;
    font-size: 0.875rem;
    font-weight: 600;
    line-height: 1;
  }
  body.login-prototype .login-card .login-meta-row .login-forgot-link {
    font-size: 0.8125rem;
    font-weight: 700;
    white-space: nowrap;
    padding: 0 6px 0 0;
    margin: 0;
    line-height: 1;
    display: inline-flex;
    align-items: center;
    height: 32px;
  }

  /* ── Primary CTA — soft layered shadow, subtle lift ── */
  body.login-prototype .login-card #login-submit,
  body.login-prototype .login-card .auth-submit-btn {
    position: relative;
    overflow: hidden;
    background:
      linear-gradient(180deg, rgba(255, 255, 255, 0.18) 0%, rgba(255, 255, 255, 0) 42%),
      linear-gradient(180deg, #4a86f0 0%, var(--auth-blue) 52%, var(--auth-blue-dark) 100%) !important;
    color: #fff !important;
    text-transform: none;
    letter-spacing: -0.01em;
    font-size: 0.9375rem !important;
    font-weight: 700;
    border-radius: var(--auth-radius-ctl) !important;
    min-height: 52px;
    height: 52px;
    padding: 0 24px !important;
    margin-top: 0 !important;
    border: 1px solid rgba(255, 255, 255, 0.24);
    box-shadow:
      0 1px 0 rgba(255, 255, 255, 0.3) inset,
      0 1px 2px rgba(15, 23, 42, 0.04),
      0 6px 14px -4px rgba(31, 88, 195, 0.35),
      0 14px 28px -12px rgba(31, 88, 195, 0.28);
    transition:
      background var(--auth-dur) var(--auth-ease),
      transform var(--auth-dur) var(--auth-ease),
      box-shadow var(--auth-dur) var(--auth-ease),
      opacity var(--auth-dur) var(--auth-ease);
  }
  body.login-prototype .login-card #login-submit:hover:not(:disabled),
  body.login-prototype .login-card .auth-submit-btn:hover:not(:disabled) {
    background:
      linear-gradient(180deg, rgba(255, 255, 255, 0.22) 0%, rgba(255, 255, 255, 0) 45%),
      linear-gradient(180deg, #5b93f5 0%, #2563eb 50%, var(--auth-blue-deep) 100%) !important;
    transform: translateY(-1.5px);
    box-shadow:
      0 1px 0 rgba(255, 255, 255, 0.36) inset,
      0 2px 4px rgba(15, 23, 42, 0.05),
      0 10px 20px -6px rgba(31, 88, 195, 0.4),
      0 18px 36px -14px rgba(31, 88, 195, 0.28);
  }
  body.login-prototype .login-card #login-submit:active:not(:disabled),
  body.login-prototype .login-card .auth-submit-btn:active:not(:disabled) {
    transform: translateY(1px);
    box-shadow:
      0 1px 0 rgba(255, 255, 255, 0.16) inset,
      0 2px 6px rgba(15, 23, 42, 0.1),
      0 6px 12px -6px rgba(31, 88, 195, 0.32);
  }
  body.login-prototype .login-card #login-submit.is-loading,
  body.login-prototype .login-card #login-submit:disabled {
    cursor: wait;
    opacity: 0.88;
  }
  body.login-prototype .login-card .login-submit-spinner {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    line-height: 1;
  }
  body.login-prototype .login-card .login-submit-spinner.hidden { display: none !important; }
  body.login-prototype .login-card .login-submit-spinner .bi {
    font-size: 1.1rem;
    animation: login-spin 0.7s linear infinite;
  }
  @keyframes login-spin {
    to { transform: rotate(360deg); }
  }

  /* OR divider — Actions → Social (major break) */
  body.login-prototype .login-card .or-divider {
    margin: 28px 0 28px !important;
    gap: 14px !important;
    color: #8b9aab;
    font-size: 0.625rem;
    font-weight: 600;
    letter-spacing: 0.14em;
    text-transform: uppercase;
    opacity: 0.6;
    align-items: center;
    justify-content: center;
  }
  body.login-prototype .login-card .or-divider span:first-child,
  body.login-prototype .login-card .or-divider span:last-child {
    background: linear-gradient(90deg, transparent 0%, rgba(148, 163, 184, 0.22) 25%, rgba(148, 163, 184, 0.22) 75%, transparent 100%) !important;
    height: 1px !important;
    opacity: 0.55;
  }

  /* Secondary glass buttons — matched height, padding, icon, type */
  body.login-prototype .login-card .login-social-actions {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
    align-items: stretch;
  }
  body.login-prototype .login-card .login-social-actions .login-google-btn,
  body.login-prototype .login-card .login-social-actions .login-magic-btn,
  body.login-prototype .login-card .login-google-btn,
  body.login-prototype .login-card .login-magic-btn,
  body.login-prototype .login-card .auth-secondary-btn {
    min-height: 48px;
    height: 48px;
    box-sizing: border-box;
    border-radius: 12px;
    font-size: 0.8125rem;
    font-weight: 600;
    letter-spacing: -0.01em;
    line-height: 1;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 0 14px;
    background: rgba(255, 255, 255, 0.4) !important;
    border: 1px solid rgba(255, 255, 255, 0.7) !important;
    color: var(--auth-ink) !important;
    box-shadow:
      inset 0 1px 0 rgba(255, 255, 255, 0.7),
      0 0 0 1px rgba(15, 23, 42, 0.04),
      0 4px 12px rgba(15, 23, 42, 0.05);
    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);
    transition:
      background var(--auth-dur) var(--auth-ease),
      border-color var(--auth-dur) var(--auth-ease),
      transform var(--auth-dur) var(--auth-ease),
      box-shadow var(--auth-dur) var(--auth-ease);
  }
  body.login-prototype .login-card .login-google-btn:hover,
  body.login-prototype .login-card .login-magic-btn:hover,
  body.login-prototype .login-card .auth-secondary-btn:hover {
    transform: translateY(-2px);
    background: rgba(255, 255, 255, 0.55) !important;
    border-color: rgba(255, 255, 255, 0.88) !important;
    box-shadow:
      inset 0 1px 0 rgba(255, 255, 255, 0.88),
      0 2px 4px rgba(15, 23, 42, 0.04),
      0 12px 24px -10px rgba(31, 88, 195, 0.22);
  }
  body.login-prototype .login-card .login-google-btn:active,
  body.login-prototype .login-card .login-magic-btn:active {
    transform: translateY(0);
  }
  body.login-prototype .login-card .login-google-btn img,
  body.login-prototype .login-card .login-google-icon {
    width: 18px !important;
    height: 18px !important;
    flex-shrink: 0;
    display: block;
  }
  body.login-prototype .login-card .login-google-btn span,
  body.login-prototype .login-card .login-magic-btn span {
    line-height: 1;
    display: inline-block;
  }
  body.login-prototype .login-card .login-magic-btn .bi {
    font-size: 1.125rem;
    width: 18px;
    height: 18px;
    line-height: 18px;
    text-align: center;
    color: #475569;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
  }

  body.login-prototype .login-footer-copy {
    position: absolute;
    left: 0;
    right: 0;
    bottom: 10px;
    color: #3f4d5f !important;
    font-size: 0.6875rem !important;
    font-weight: 500;
    line-height: 1.4;
    margin: 0;
    padding: 0 16px;
    z-index: 10;
    letter-spacing: 0.01em;
    pointer-events: none;
    opacity: 0.95;
  }

  body.login-prototype .login-card .auth-alert {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 14px 16px;
    border-radius: 14px;
    font-size: 0.875rem;
    margin-bottom: 16px;
    border: 1px solid transparent;
    border-left: 3px solid;
    background: rgba(255, 255, 255, 0.55);
    backdrop-filter: blur(12px);
    animation: auth-alert-in 0.35s var(--auth-ease);
  }
  body.login-prototype .login-card .auth-alert.auth-alert-error {
    background: rgba(254, 226, 226, 0.65);
    border-left-color: #ef4444;
    color: #991b1b;
  }
  body.login-prototype .login-card .auth-alert-icon { font-size: 1.25rem; flex-shrink: 0; }
  body.login-prototype .login-card .auth-alert-text { font-weight: 600; }
  body.login-prototype .login-card .auth-success-msg { color: var(--auth-muted); font-size: 0.875rem; }
  @keyframes auth-alert-in {
    from { opacity: 0; transform: translateY(-6px); }
    to { opacity: 1; transform: translateY(0); }
  }

  /* Rate limit */
  body.login-prototype .login-ratelimit-block {
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    padding: 32px 24px;
    border-radius: 20px;
    background: linear-gradient(145deg, rgba(255, 251, 235, 0.75) 0%, rgba(254, 243, 199, 0.55) 100%);
    border: 1px solid rgba(245, 158, 11, 0.3);
    box-shadow: 0 16px 40px -20px rgba(245, 158, 11, 0.35);
    backdrop-filter: blur(16px);
  }
  body.login-prototype .login-ratelimit-block-title { color: #92400e !important; }
  body.login-prototype .login-ratelimit-block-desc { color: #a16207 !important; }
  body.login-prototype .login-ratelimit-countdown {
    color: #b45309 !important;
    background: rgba(255, 255, 255, 0.65) !important;
  }
  .login-ratelimit-block-icon-wrap {
    width: 56px;
    height: 56px;
    border-radius: 50%;
    background: linear-gradient(135deg, rgba(245, 158, 11, 0.2), rgba(251, 191, 36, 0.25));
    border: 1px solid rgba(245, 158, 11, 0.35);
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 16px;
  }
  .login-ratelimit-block-icon-wrap i { font-size: 1.5rem; color: #b45309; }
  .login-ratelimit-block-title {
    font-size: 1.125rem;
    font-weight: 800;
    letter-spacing: -0.02em;
    margin-bottom: 8px;
  }
  .login-ratelimit-block-desc {
    font-size: 0.875rem;
    line-height: 1.55;
    margin-bottom: 16px;
    max-width: 18rem;
  }
  .login-ratelimit-countdown {
    font-variant-numeric: tabular-nums;
    font-size: 1.25rem;
    font-weight: 700;
    padding: 10px 16px;
    border-radius: 12px;
    border: 1px solid rgba(245, 158, 11, 0.28);
    min-width: 8rem;
  }
  .login-ratelimit-block.form-hidden ~ .login-form-wrap,
  .login-form-wrap.visually-hidden { display: none !important; }

  /* Loading / error overlays */
  .login-loading-backdrop,
  .login-error-backdrop {
    position: fixed;
    inset: 0;
    z-index: 80;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px;
    background: radial-gradient(circle at 20% 10%, rgba(254, 243, 199, 0.35), transparent 50%), rgba(15, 23, 42, 0.32);
    backdrop-filter: blur(18px);
    -webkit-backdrop-filter: blur(18px);
    opacity: 0;
    pointer-events: none;
    transition: opacity 240ms var(--auth-ease);
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
    gap: 16px;
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
    box-shadow: 0 18px 40px rgba(15, 23, 42, 0.2);
  }
  .login-loading-orb-inner span {
    width: 12px;
    height: 12px;
    border-radius: 9999px;
    background: linear-gradient(135deg, #f59e0b, #f97316);
    animation: login-orb-pulse 1100ms ease-out infinite;
  }
  .login-loading-label {
    font-size: 0.875rem;
    font-weight: 700;
    color: #0f172a;
    letter-spacing: -0.01em;
    padding: 10px 16px;
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.75);
    border: 1px solid rgba(255, 255, 255, 0.8);
    backdrop-filter: blur(12px);
    box-shadow: 0 8px 24px rgba(15, 23, 42, 0.1);
  }
  @keyframes login-orb-spin { to { transform: rotate(360deg); } }
  @keyframes login-orb-pulse {
    0% { transform: scale(0.92); box-shadow: 0 0 0 0 rgba(245, 158, 11, 0.55); }
    70% { transform: scale(1.1); box-shadow: 0 0 0 18px rgba(245, 158, 11, 0); }
    100% { transform: scale(0.92); box-shadow: 0 0 0 0 rgba(245, 158, 11, 0); }
  }
  .login-error-card {
    max-width: 380px;
    width: 100%;
    border-radius: 24px;
    padding: 32px 28px 28px;
    background:
      radial-gradient(circle at 0 0, rgba(245, 158, 11, 0.12), transparent 55%),
      radial-gradient(circle at 100% 100%, rgba(31, 88, 195, 0.1), transparent 55%),
      rgba(255, 255, 255, 0.78);
    box-shadow:
      0 1px 0 rgba(255, 255, 255, 0.85) inset,
      0 32px 80px rgba(15, 23, 42, 0.2);
    color: #0f172a;
    border: 1px solid rgba(255, 255, 255, 0.75);
    backdrop-filter: blur(24px);
    -webkit-backdrop-filter: blur(24px);
    transform: translateY(18px) scale(0.96);
    opacity: 0;
    transition: opacity 240ms var(--auth-ease), transform 240ms var(--auth-ease);
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
  }
  .login-error-backdrop.is-active .login-error-card {
    opacity: 1;
    transform: translateY(0) scale(1);
    transition-delay: 60ms;
  }
  .login-error-icon { display: inline-flex; align-items: center; justify-content: center; margin-bottom: 16px; }
  .login-error-circle {
    width: 56px;
    height: 56px;
    border-radius: 9999px;
    border: 1px solid rgba(248, 113, 113, 0.45);
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
    background: radial-gradient(circle at 15% 0, rgba(255,255,255,0.5), transparent 55%),
      radial-gradient(circle at 50% 115%, rgba(248, 113, 113, 0.95), rgba(185, 28, 28, 1));
    box-shadow: 0 14px 30px rgba(185, 28, 28, 0.25);
    animation: login-error-pop 260ms var(--auth-ease) forwards;
    overflow: hidden;
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
    animation: login-error-line 260ms 120ms var(--auth-ease) forwards;
  }
  .login-error-line-2 {
    transform: rotate(-45deg) scaleX(0);
    animation: login-error-line 260ms 190ms var(--auth-ease) forwards;
  }
  @keyframes login-error-pop {
    0% { transform: scale(0.7); opacity: 0; }
    70% { transform: scale(1.1); opacity: 1; }
    100% { transform: scale(1); opacity: 1; }
  }
  @keyframes login-error-line {
    0% { opacity: 0; transform: scaleX(0); }
    100% { opacity: 1; transform: scaleX(1); }
  }
  .login-error-title {
    margin-bottom: 8px;
    color: #991b1b;
    font-weight: 800;
    letter-spacing: -0.02em;
  }
  .login-error-text {
    font-size: 0.875rem;
    line-height: 1.55;
    max-width: 17rem;
    margin-bottom: 16px;
    color: #334155;
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

  @media (max-width: 560px) {
    body.login-prototype .login-card {
      padding: 28px 22px 24px !important;
      border-radius: 20px !important;
    }
    body.login-prototype .login-card-wrap { max-width: 100% !important; }
    body.login-prototype .login-card .login-meta-row {
      flex-wrap: wrap;
      row-gap: 8px;
    }
    body.login-prototype .login-card .login-social-actions { grid-template-columns: 1fr; }
    body.login-prototype .login-card h1 { font-size: 1.3rem !important; }
  }

  /* Short laptops only — gentle trim so the card still fits */
  @media (max-height: 820px) {
    body.login-prototype .login-card {
      padding: 36px 36px 32px !important;
    }
    body.login-prototype .login-header { margin-bottom: 16px !important; }
    body.login-prototype .login-welcome { margin-bottom: 26px !important; }
    body.login-prototype .login-card .login-field + .login-field { margin-top: 24px; }
    body.login-prototype .login-card .login-meta-row { margin-top: 18px; }
    body.login-prototype .login-card .login-piece-6 { margin-top: 26px !important; }
    body.login-prototype .login-card .or-divider { margin: 22px 0 22px !important; }
    body.login-prototype .login-page-layout { padding: 16px 16px 40px; }
  }
</style>
