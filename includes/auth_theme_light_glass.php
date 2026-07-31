<?php
/**
 * Shared light glass auth theme (login, forgot/reset/magic, registration overrides).
 * Brand: blue #1F58C3, amber #F59E0B — light frosted glass cards.
 */
?>
<style>
  :root {
    --auth-blue: #1F58C3;
    --auth-blue-dark: #1E40AF;
    --auth-amber: #F59E0B;
    --auth-amber-soft: #FCD34D;
    --auth-ink: #0f172a;
    --auth-muted: #64748b;
    --auth-line: rgba(31, 88, 195, 0.14);
    --auth-glass: rgba(255, 255, 255, 0.72);
    --auth-glass-strong: rgba(255, 255, 255, 0.86);
  }

  body.login-prototype {
    background:
      radial-gradient(ellipse 90% 60% at 10% -10%, rgba(31, 88, 195, 0.18), transparent 55%),
      radial-gradient(ellipse 70% 50% at 100% 0%, rgba(245, 158, 11, 0.14), transparent 50%),
      radial-gradient(ellipse 60% 40% at 50% 100%, rgba(31, 88, 195, 0.08), transparent 55%),
      linear-gradient(180deg, #f8fbff 0%, #eef4fb 45%, #f7fafc 100%) !important;
    color: var(--auth-ink);
  }
  body.login-prototype .animated-bg {
    background: transparent !important;
  }
  body.login-prototype .animated-bg::before,
  body.login-prototype .animated-bg::after {
    display: none !important;
  }
  body.login-prototype .auth-corner-decor::before,
  body.login-prototype .auth-corner-decor::after {
    width: 80px;
    height: 52px;
    background: rgba(255, 255, 255, 0.55);
    border: 1px solid rgba(31, 88, 195, 0.16);
    border-radius: 10px;
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
    box-shadow: 0 8px 24px -18px rgba(15, 23, 42, 0.35);
  }
  body.login-prototype .auth-corner-dot {
    width: 5px;
    height: 5px;
    background: rgba(245, 158, 11, 0.95);
    box-shadow: 0 0 10px rgba(245, 158, 11, 0.45);
  }
  body.login-prototype .auth-corner-dot.blue {
    background: rgba(31, 88, 195, 0.95);
    box-shadow: 0 0 10px rgba(31, 88, 195, 0.4);
  }

  body.login-prototype .circuit-bg {
    position: fixed;
    inset: 0;
    z-index: 0;
    pointer-events: none;
    background-image:
      linear-gradient(rgba(31, 88, 195, 0.045) 1px, transparent 1px),
      linear-gradient(90deg, rgba(31, 88, 195, 0.045) 1px, transparent 1px);
    background-size: 36px 36px;
    animation: login-bg-grid-pulse 12s ease-in-out infinite;
  }
  @keyframes login-bg-grid-pulse {
    0%, 100% { opacity: 0.45; }
    50% { opacity: 0.85; }
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
    width: 7px;
    height: 7px;
    border-radius: 50%;
    animation: login-bg-float 24s ease-in-out infinite;
  }
  .login-bg-node--blue {
    background: rgba(31, 88, 195, 0.35);
    box-shadow: 0 0 14px rgba(31, 88, 195, 0.25);
    left: var(--x, 15%);
    top: var(--y, 25%);
    animation-delay: var(--delay, 0s);
    animation-duration: var(--dur, 22s);
  }
  .login-bg-node--gold {
    background: rgba(245, 158, 11, 0.4);
    box-shadow: 0 0 12px rgba(245, 158, 11, 0.25);
    left: var(--x, 80%);
    top: var(--y, 70%);
    animation-delay: var(--delay, 2s);
    animation-duration: var(--dur, 26s);
  }
  .login-bg-node--white {
    background: rgba(255, 255, 255, 0.9);
    box-shadow: 0 0 10px rgba(148, 163, 184, 0.35);
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
  .login-bg-lines { position: absolute; inset: 0; opacity: 0.55; }
  .login-bg-lines svg { width: 100%; height: 100%; }
  .login-bg-lines .line {
    fill: none;
    stroke-width: 0.7;
    stroke-linecap: round;
    animation: login-bg-line-flow 20s linear infinite;
  }
  .login-bg-lines .line--blue { stroke: rgba(31, 88, 195, 0.22); }
  .login-bg-lines .line--gold { stroke: rgba(245, 158, 11, 0.2); animation-delay: -5s; }
  .login-bg-lines .line--white { stroke: rgba(148, 163, 184, 0.18); animation-delay: -10s; }
  @keyframes login-bg-line-flow {
    0% { stroke-dashoffset: 0; }
    100% { stroke-dashoffset: -200; }
  }

  .login-bg-blob {
    position: fixed;
    left: 50%;
    top: 50%;
    transform: translate(-50%, -50%);
    width: min(120vw, 720px);
    height: min(80vw, 540px);
    border-radius: 50% 40% 60% 50% / 50% 60% 40% 50%;
    background:
      radial-gradient(ellipse at 30% 20%, rgba(31, 88, 195, 0.28) 0%, transparent 52%),
      radial-gradient(ellipse at 70% 80%, rgba(245, 158, 11, 0.18) 0%, transparent 48%),
      radial-gradient(ellipse at 50% 50%, rgba(147, 197, 253, 0.2) 0%, transparent 55%);
    filter: blur(52px);
    z-index: 0;
    pointer-events: none;
    animation: login-blob-drift 20s ease-in-out infinite;
  }
  @keyframes login-blob-drift {
    0%, 100% { transform: translate(-50%, -50%) scale(1) rotate(0deg); }
    33% { transform: translate(-52%, -48%) scale(1.05) rotate(2deg); }
    66% { transform: translate(-48%, -52%) scale(0.98) rotate(-1deg); }
  }

  .login-cpa-visual {
    position: fixed;
    inset: 0;
    z-index: 0;
    pointer-events: none;
    opacity: 0.16;
  }
  .login-cpa-visual svg { width: 100%; height: 100%; object-fit: cover; }
  .login-cpa-visual .cpa-ring {
    fill: none;
    stroke-width: 1.5;
    stroke-linecap: round;
    stroke: rgba(31, 88, 195, 0.45);
    animation: cpa-ring-pulse 8s ease-in-out infinite;
  }
  .login-cpa-visual .cpa-line {
    fill: none;
    stroke: rgba(31, 88, 195, 0.28);
    stroke-width: 0.8;
    stroke-dasharray: 4 6;
    animation: cpa-line-flow 25s linear infinite;
  }
  @keyframes cpa-ring-pulse {
    0%, 100% { opacity: 0.55; stroke-dashoffset: 0; }
    50% { opacity: 1; stroke-dashoffset: -30; }
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
    opacity: 0.22;
  }
  .login-cashflow-path svg { width: 100%; height: 100%; }
  .login-cashflow-path .path {
    fill: none;
    stroke: rgba(245, 158, 11, 0.45);
    stroke-width: 1;
    stroke-dasharray: 120 80;
    animation: login-cashflow-draw 18s linear infinite;
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
    body.login-prototype .circuit-bg { animation: none; opacity: 0.55; }
    body.login-prototype .login-card #login-submit:hover,
    body.login-prototype .login-card .login-google-btn:hover,
    body.login-prototype .login-card .auth-submit-btn:hover { transform: none; }
    body.login-prototype .login-logo-hover:hover { transform: none; filter: none; }
  }

  @media (hover: none) and (pointer: coarse) {
    body.login-prototype .login-card .auth-input { min-height: 2.75rem !important; }
    body.login-prototype .login-card #login-submit,
    body.login-prototype .login-card .login-google-btn,
    body.login-prototype .login-card .auth-submit-btn { min-height: 2.75rem !important; }
    body.login-prototype .login-card #toggle-password {
      min-width: 2.75rem !important;
      min-height: 2.75rem !important;
    }
    body.login-prototype .login-card [for="login-remember"] {
      min-height: 2.75rem;
      display: inline-flex;
      align-items: center;
    }
  }

  body.login-prototype .login-card-wrap { max-width: 520px !important; }

  /* Frosted glass card */
  body.login-prototype .login-card {
    background: linear-gradient(165deg, rgba(255,255,255,0.82) 0%, rgba(255,255,255,0.68) 100%) !important;
    border: 1px solid rgba(255, 255, 255, 0.65) !important;
    box-shadow:
      0 28px 60px -28px rgba(15, 23, 42, 0.28),
      0 0 0 1px rgba(31, 88, 195, 0.08),
      inset 0 1px 0 rgba(255, 255, 255, 0.9) !important;
    border-radius: 1.25rem !important;
    padding: 1.75rem 2.25rem 1.85rem !important;
    backdrop-filter: blur(22px) saturate(1.35);
    -webkit-backdrop-filter: blur(22px) saturate(1.35);
    transition: box-shadow 0.25s ease, transform 0.25s ease;
  }
  body.login-prototype .login-card:focus-within {
    box-shadow:
      0 32px 70px -28px rgba(31, 88, 195, 0.28),
      0 0 0 1px rgba(31, 88, 195, 0.16),
      0 0 40px rgba(31, 88, 195, 0.1),
      inset 0 1px 0 rgba(255, 255, 255, 0.95) !important;
  }

  body.login-prototype .login-header { margin-bottom: 1.25rem !important; }
  body.login-prototype .login-logo-wrap { margin-bottom: 0.75rem !important; }
  body.login-prototype .login-logo-hover {
    transition: transform 0.2s ease, filter 0.2s ease;
  }
  body.login-prototype .login-logo-hover:hover {
    transform: scale(1.03);
    filter: drop-shadow(0 0 10px rgba(31, 88, 195, 0.28));
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
    color: var(--auth-ink);
    font-size: 1rem;
    font-weight: 700;
    letter-spacing: 0.01em;
  }
  body.login-prototype .login-card .brand-text .blue { color: var(--auth-blue); }
  body.login-prototype .login-card .brand-text .amber { color: var(--auth-amber); }
  body.login-prototype .login-welcome { margin-bottom: 1.35rem !important; }
  body.login-prototype .login-value-statement {
    font-size: 0.8125rem;
    color: var(--auth-muted);
    margin-bottom: 0.65rem;
    line-height: 1.4;
  }
  body.login-prototype .login-card h1 {
    color: var(--auth-ink) !important;
    font-size: 1.35rem !important;
    font-weight: 800;
    letter-spacing: -0.03em;
  }
  body.login-prototype .login-card .subtext { color: var(--auth-muted); font-size: 0.8125rem; }
  body.login-prototype .login-card .subtext a,
  body.login-prototype .login-card .auth-back-link,
  body.login-prototype .login-card .login-forgot-link {
    color: var(--auth-amber) !important;
    font-weight: 600;
  }
  body.login-prototype .login-card .subtext a:hover,
  body.login-prototype .login-card .auth-back-link:hover,
  body.login-prototype .login-card .login-forgot-link:hover {
    color: #d97706 !important;
    text-decoration: underline;
  }
  body.login-prototype .login-card label:not(.float-label) { color: var(--auth-ink) !important; font-weight: 600; }

  body.login-prototype .login-form-fields.space-y-4 > * + * { margin-top: 1rem !important; }
  body.login-prototype .login-card .space-y-2 > * + * { margin-top: 0.5rem !important; }
  body.login-prototype .login-card .login-piece-5b { margin-top: 1.5rem !important; }
  body.login-prototype .login-card .login-piece-5 > * + * { margin-top: 0.15rem !important; }

  body.login-prototype .login-card .float-label-wrap { position: relative; }
  body.login-prototype .login-card .float-label-wrap .float-label {
    position: absolute;
    left: 2.75rem;
    top: 50%;
    transform: translateY(-50%);
    font-size: 0.875rem;
    font-weight: 500;
    color: #64748b !important;
    pointer-events: none;
    transition: top 0.2s ease, font-size 0.2s ease, color 0.2s ease, transform 0.2s ease, background 0.2s ease, padding 0.2s ease;
    z-index: 2;
    max-width: calc(100% - 5.5rem);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }
  /* JS classes + CSS fallbacks (autofill often skips input events) */
  body.login-prototype .login-card .float-label-wrap.focused .float-label,
  body.login-prototype .login-card .float-label-wrap.has-value .float-label,
  body.login-prototype .login-card .float-label-wrap:has(.auth-input:focus) .float-label,
  body.login-prototype .login-card .float-label-wrap:has(.auth-input:not(:placeholder-shown)) .float-label,
  body.login-prototype .login-card .float-label-wrap:has(.auth-input:-webkit-autofill) .float-label {
    top: 0;
    transform: translateY(-50%);
    font-size: 0.7rem;
    font-weight: 700;
    color: var(--auth-blue) !important;
    background: linear-gradient(180deg, rgba(255,255,255,0.98) 40%, rgba(255,255,255,0.92) 100%);
    padding: 0 0.35rem;
    border-radius: 0.25rem;
    left: 2.55rem;
    z-index: 3;
  }

  body.login-prototype .login-card .auth-input {
    padding-left: 3rem !important;
    padding-top: 0.55rem !important;
    padding-bottom: 0.55rem !important;
    min-height: 2.5rem;
    border-radius: 0.85rem !important;
    background: rgba(255, 255, 255, 0.78) !important;
    border: 1px solid rgba(31, 88, 195, 0.18) !important;
    color: var(--auth-ink) !important;
    box-shadow: inset 0 1px 2px rgba(15, 23, 42, 0.04) !important;
    backdrop-filter: blur(8px);
    -webkit-backdrop-filter: blur(8px);
  }
  /* Detect Chrome autofill so float labels can rise even without input events */
  @keyframes login-autofill-on {
    from { opacity: 0.99; }
    to { opacity: 1; }
  }
  body.login-prototype .login-card .auth-input:-webkit-autofill,
  body.login-prototype .login-card .auth-input:-webkit-autofill:hover,
  body.login-prototype .login-card .auth-input:-webkit-autofill:focus {
    -webkit-text-fill-color: var(--auth-ink) !important;
    box-shadow: 0 0 0 1000px rgba(255, 255, 255, 0.95) inset !important;
    transition: background-color 99999s ease-out;
    animation-name: login-autofill-on;
    animation-duration: 0.001s;
  }
  body.login-prototype .login-card .auth-input::placeholder { color: #94a3b8; }
  body.login-prototype .login-card .auth-input:hover { border-color: rgba(31, 88, 195, 0.35) !important; }
  body.login-prototype .login-card .auth-input:focus {
    border-color: var(--auth-blue) !important;
    box-shadow: 0 0 0 3px rgba(31, 88, 195, 0.16), inset 0 1px 2px rgba(15, 23, 42, 0.03) !important;
    background: rgba(255, 255, 255, 0.95) !important;
  }
  body.login-prototype .login-card .auth-input:focus-visible,
  body.login-prototype .login-card #login-submit:focus-visible,
  body.login-prototype .login-card .login-google-btn:focus-visible,
  body.login-prototype .login-card .auth-submit-btn:focus-visible,
  body.login-prototype .login-card .subtext a:focus-visible,
  body.login-prototype .login-card .login-forgot-link:focus-visible {
    outline: none;
    box-shadow: 0 0 0 2px #fff, 0 0 0 4px var(--auth-blue) !important;
  }

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
  body.login-prototype .login-card .auth-input-icon-wrap .bi { line-height: 1; display: block; }
  body.login-prototype .login-card .input-icon { color: var(--auth-amber); }

  body.login-prototype .login-card .auth-password-wrap { position: relative; }
  body.login-prototype .login-card .auth-password-wrap .auth-input { padding-right: 2.75rem !important; }
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
    color: #94a3b8 !important;
    background: transparent !important;
    border: none !important;
  }
  body.login-prototype .login-card #toggle-password:hover { color: var(--auth-amber) !important; }
  body.login-prototype .login-card #toggle-password:focus-visible {
    outline: none;
    box-shadow: 0 0 0 2px #fff, 0 0 0 4px var(--auth-amber) !important;
  }

  body.login-prototype .login-card .login-password-actions {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.5rem;
    margin-top: -0.05rem;
    padding-top: 0;
  }
  body.login-prototype .login-card #password-error,
  body.login-prototype #email-error { color: #dc2626 !important; }
  body.login-prototype .login-card .login-security-hint {
    margin: 0;
    color: var(--auth-muted) !important;
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    white-space: nowrap;
    min-width: 0;
    font-size: 0.75rem;
  }
  body.login-prototype .login-card .login-password-actions .login-forgot-link {
    margin-left: auto;
    font-size: 0.75rem;
    font-weight: 600;
    white-space: nowrap;
    padding: 0.2rem 0.55rem;
    border-radius: 0.6rem;
    border: 1px solid transparent;
    transition: transform 0.2s ease, background 0.2s ease, border-color 0.2s ease, box-shadow 0.2s ease;
  }
  body.login-prototype .login-card .login-password-actions .login-forgot-link:hover {
    background: rgba(245, 158, 11, 0.12) !important;
    border-color: rgba(245, 158, 11, 0.4) !important;
    box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.1);
    transform: translateY(-1px);
    text-decoration: none !important;
  }

  body.login-prototype .login-card label[for="login-remember"] { color: var(--auth-ink) !important; }
  body.login-prototype .login-card label[for="login-remember"] span:first-child {
    color: var(--auth-ink) !important;
    font-weight: 600;
  }
  body.login-prototype .login-card #login-remember-hint {
    color: var(--auth-muted) !important;
    font-size: 0.75rem;
  }
  body.login-prototype .login-card #login-remember:focus-visible {
    outline: none;
    box-shadow: 0 0 0 2px #fff, 0 0 0 4px var(--auth-blue);
  }

  body.login-prototype .login-card #login-submit,
  body.login-prototype .login-card .auth-submit-btn {
    background: linear-gradient(180deg, #2563eb 0%, var(--auth-blue) 100%) !important;
    color: #fff !important;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    font-weight: 700;
    border-radius: 0.85rem !important;
    padding-top: 0.7rem !important;
    padding-bottom: 0.7rem !important;
    margin-top: 0.25rem !important;
    border: none;
    box-shadow: 0 12px 28px -14px rgba(31, 88, 195, 0.65);
    transition: background 0.2s ease, transform 0.2s ease, box-shadow 0.2s ease;
  }
  body.login-prototype .login-card #login-submit:hover,
  body.login-prototype .login-card .auth-submit-btn:hover {
    background: linear-gradient(180deg, var(--auth-blue) 0%, var(--auth-blue-dark) 100%) !important;
    transform: translateY(-2px);
    box-shadow: 0 16px 34px -14px rgba(31, 88, 195, 0.7);
  }
  body.login-prototype .login-card #login-submit:active,
  body.login-prototype .login-card .auth-submit-btn:active {
    transform: translateY(0) scale(0.98);
  }

  body.login-prototype .login-card .login-piece-6 { margin-top: 1.1rem !important; }
  body.login-prototype .login-card .or-divider {
    margin-top: 1rem !important;
    margin-bottom: 0.65rem !important;
    color: #94a3b8;
    font-size: 0.75rem;
    font-weight: 700;
    letter-spacing: 0.06em;
  }
  body.login-prototype .login-card .or-divider span:first-child,
  body.login-prototype .login-card .or-divider span:last-child {
    background: linear-gradient(90deg, transparent 0%, rgba(31, 88, 195, 0.18) 20%, rgba(31, 88, 195, 0.18) 80%, transparent 100%) !important;
    height: 1px;
  }

  body.login-prototype .login-card .login-social-actions {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.65rem;
    align-items: stretch;
  }
  body.login-prototype .login-card .login-social-actions .login-google-btn,
  body.login-prototype .login-card .login-social-actions .login-magic-btn,
  body.login-prototype .login-card .login-google-btn,
  body.login-prototype .login-card .auth-secondary-btn {
    min-height: 2.55rem;
    border-radius: 0.85rem;
    font-size: 0.8125rem;
    font-weight: 600;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.45rem;
    padding: 0.625rem 0.8rem;
    background: rgba(255, 255, 255, 0.7) !important;
    border: 1px solid rgba(31, 88, 195, 0.2) !important;
    color: var(--auth-ink) !important;
    box-shadow: 0 8px 20px -16px rgba(15, 23, 42, 0.35);
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
    transition: background 0.2s ease, border-color 0.2s ease, transform 0.2s ease, box-shadow 0.2s ease;
  }
  body.login-prototype .login-card .login-social-actions .login-magic-btn {
    border-color: rgba(245, 158, 11, 0.35) !important;
  }
  body.login-prototype .login-card .login-google-btn:hover,
  body.login-prototype .login-card .login-magic-btn:hover,
  body.login-prototype .login-card .auth-secondary-btn:hover {
    transform: translateY(-2px);
    background: rgba(255, 255, 255, 0.95) !important;
    border-color: rgba(31, 88, 195, 0.4) !important;
    box-shadow: 0 14px 28px -16px rgba(31, 88, 195, 0.35);
  }
  body.login-prototype .login-card .login-magic-btn:hover {
    border-color: rgba(245, 158, 11, 0.55) !important;
    box-shadow: 0 14px 28px -16px rgba(245, 158, 11, 0.35);
  }
  body.login-prototype .login-card .login-google-btn img,
  body.login-prototype .login-card .login-google-icon {
    width: 1rem !important;
    height: 1rem !important;
    flex-shrink: 0;
  }

  body.login-prototype .login-footer-copy {
    color: #64748b !important;
    font-size: 0.6875rem !important;
    line-height: 1.5;
    margin-top: 1rem;
    padding: 0.75rem 1rem 1rem;
    position: relative;
    z-index: 10;
  }

  body.login-prototype .login-card .auth-alert {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.875rem 1rem;
    border-radius: 0.85rem;
    font-size: 0.875rem;
    margin-bottom: 1rem;
    border: 1px solid transparent;
    border-left: 3px solid;
    background: rgba(255, 255, 255, 0.7);
    animation: auth-alert-in 0.35s ease-out;
  }
  body.login-prototype .login-card .auth-alert.auth-alert-error {
    background: rgba(254, 226, 226, 0.85);
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

  /* Rate limit — light glass */
  body.login-prototype .login-ratelimit-block {
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    padding: 1.75rem 1.5rem;
    border-radius: 1.15rem;
    background: linear-gradient(145deg, rgba(255, 251, 235, 0.9) 0%, rgba(254, 243, 199, 0.75) 100%);
    border: 1px solid rgba(245, 158, 11, 0.35);
    box-shadow: 0 10px 30px -18px rgba(245, 158, 11, 0.4);
    backdrop-filter: blur(12px);
  }
  body.login-prototype .login-ratelimit-block-title { color: #92400e !important; }
  body.login-prototype .login-ratelimit-block-desc { color: #a16207 !important; }
  body.login-prototype .login-ratelimit-countdown {
    color: #b45309 !important;
    background: rgba(255, 255, 255, 0.75) !important;
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
  .login-ratelimit-block-icon-wrap i { font-size: 1.5rem; color: #b45309; }
  .login-ratelimit-block-title {
    font-size: 1.125rem;
    font-weight: 800;
    letter-spacing: -0.02em;
    margin-bottom: 0.35rem;
  }
  .login-ratelimit-block-desc {
    font-size: 0.8125rem;
    line-height: 1.5;
    margin-bottom: 1rem;
    max-width: 18rem;
  }
  .login-ratelimit-countdown {
    font-variant-numeric: tabular-nums;
    font-size: 1.25rem;
    font-weight: 700;
    padding: 0.5rem 1rem;
    border-radius: 0.75rem;
    border: 1px solid rgba(245, 158, 11, 0.3);
    min-width: 8rem;
  }
  .login-ratelimit-block.form-hidden ~ .login-form-wrap,
  .login-form-wrap.visually-hidden { display: none !important; }

  /* Loading / error overlays stay readable on light UI */
  .login-loading-backdrop,
  .login-error-backdrop {
    position: fixed;
    inset: 0;
    z-index: 80;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 1.5rem;
    background: radial-gradient(circle at 12% 5%, rgba(254, 243, 199, 0.4), transparent 50%), rgba(15, 23, 42, 0.35);
    backdrop-filter: blur(16px);
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
    font-size: 0.85rem;
    font-weight: 700;
    color: #0f172a;
    letter-spacing: 0.01em;
    padding: 0.45rem 0.85rem;
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.8);
    border: 1px solid rgba(255, 255, 255, 0.7);
    backdrop-filter: blur(10px);
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
    border-radius: 1.5rem;
    padding: 1.9rem 2rem 1.85rem;
    background:
      radial-gradient(circle at 0 0, rgba(245, 158, 11, 0.12), transparent 55%),
      radial-gradient(circle at 100% 100%, rgba(31, 88, 195, 0.12), transparent 55%),
      rgba(255, 255, 255, 0.88);
    box-shadow: 0 32px 80px rgba(15, 23, 42, 0.22);
    color: #0f172a;
    border: 1px solid rgba(255, 255, 255, 0.8);
    backdrop-filter: blur(20px);
    -webkit-backdrop-filter: blur(20px);
    transform: translateY(18px) scale(0.94);
    opacity: 0;
    transition: opacity 220ms ease-out, transform 220ms ease-out, box-shadow 220ms ease-out;
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
  }
  .login-error-backdrop.is-active .login-error-card {
    opacity: 1;
    transform: translateY(0) scale(1);
    transition-delay: 70ms;
  }
  .login-error-icon { display: inline-flex; align-items: center; justify-content: center; margin-bottom: 1rem; }
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
    animation: login-error-pop 260ms cubic-bezier(0.22, 1, 0.36, 1) forwards;
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
    animation: login-error-line 260ms 120ms cubic-bezier(0.22, 1, 0.36, 1) forwards;
  }
  .login-error-line-2 {
    transform: rotate(-45deg) scaleX(0);
    animation: login-error-line 260ms 190ms cubic-bezier(0.22, 1, 0.36, 1) forwards;
  }
  @keyframes login-error-pop {
    0% { transform: scale(0.7); opacity: 0; }
    70% { transform: scale(1.12); opacity: 1; }
    100% { transform: scale(1); opacity: 1; }
  }
  @keyframes login-error-line {
    0% { opacity: 0; transform: scaleX(0); }
    100% { opacity: 1; transform: scaleX(1); }
  }
  .login-error-title {
    margin-bottom: 0.35rem;
    color: #991b1b;
    font-weight: 800;
  }
  .login-error-text {
    font-size: 0.8rem;
    line-height: 1.6;
    max-width: 17rem;
    margin-bottom: 1.1rem;
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
    body.login-prototype .login-card .login-password-actions { flex-wrap: wrap; row-gap: 0.4rem; }
    body.login-prototype .login-card .login-password-actions .login-forgot-link { margin-left: 0; }
    body.login-prototype .login-card .login-social-actions { grid-template-columns: 1fr; }
  }
</style>
