<?php
/**
 * Shared auth theme: dark login-prototype style (background, card, inputs, buttons, links).
 * Include this on request_magic_link, forgot_password, reset_password for UI uniformity with login.php.
 */
?>
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
  .login-bg-lines { position: absolute; inset: 0; opacity: 0.4; }
  .login-bg-lines svg { width: 100%; height: 100%; }
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
  .login-cashflow-path {
    position: fixed;
    inset: 0;
    z-index: 0;
    pointer-events: none;
    opacity: 0.2;
  }
  .login-cashflow-path svg { width: 100%; height: 100%; }
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
    body.login-prototype .login-card .auth-submit-btn:hover { transform: none; }
    body.login-prototype .login-card .auth-submit-btn:active { transform: none; }
    body.login-prototype .login-logo-hover:hover { transform: none; filter: none; }
  }
  @media (hover: none) and (pointer: coarse) {
    body.login-prototype .login-card .auth-input { min-height: 2.75rem !important; }
    body.login-prototype .login-card .auth-submit-btn { min-height: 2.75rem !important; }
  }
  body.login-prototype .login-card-wrap { max-width: 460px !important; }
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
  body.login-prototype .login-header { margin-bottom: 1rem !important; }
  body.login-prototype .login-logo-wrap { margin-bottom: 0.5rem !important; }
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
  body.login-prototype .login-welcome { margin-bottom: 1rem !important; }
  body.login-prototype .login-value-statement {
    font-size: 0.8125rem;
    color: #94a3b8;
    margin-bottom: 0.5rem;
    line-height: 1.4;
  }
  body.login-prototype .login-card h1 {
    color: #fff !important;
    font-size: 1.25rem !important;
    font-weight: 700;
    letter-spacing: -0.025em;
  }
  body.login-prototype .login-card .space-y-2 > * + * { margin-top: 0.25rem !important; }
  body.login-prototype .login-card .auth-input {
    box-shadow: 0 1px 2px rgba(0,0,0,0.2) inset !important;
    padding-left: 3rem !important;
    padding-top: 0.45rem !important;
    padding-bottom: 0.45rem !important;
    min-height: 2.35rem;
    border-radius: 0.75rem !important;
    background: linear-gradient(180deg, #1e293b 0%, #1a2332 100%) !important;
    border: 1px solid rgba(31, 88, 195, 0.25) !important;
    color: #fff !important;
  }
  body.login-prototype .login-card .auth-input::placeholder { color: #94a3b8; }
  body.login-prototype .login-card .auth-input:hover { border-color: rgba(31, 88, 195, 0.4) !important; }
  body.login-prototype .login-card .auth-input:focus {
    border-color: #1F58C3 !important;
    box-shadow: 0 0 0 2px rgba(31, 88, 195, 0.35), 0 1px 2px rgba(0,0,0,0.2) inset !important;
  }
  body.login-prototype .login-card .auth-input:focus-visible,
  body.login-prototype .login-card .auth-submit-btn:focus-visible,
  body.login-prototype .login-card .auth-back-link:focus-visible {
    outline: none;
    box-shadow: 0 0 0 2px #fff, 0 0 0 4px #1F58C3 !important;
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
  body.login-prototype .login-card .input-icon { color: #F59E0B; }
  body.login-prototype .login-card .subtext { color: #94a3b8; font-size: 0.8125rem; }
  body.login-prototype .login-card .subtext a,
  body.login-prototype .login-card .auth-back-link {
    color: #F59E0B !important;
  }
  body.login-prototype .login-card .subtext a:hover,
  body.login-prototype .login-card .auth-back-link:hover {
    color: #FCD34D !important;
    text-decoration: underline;
  }
  body.login-prototype .login-card label { color: #fff !important; font-weight: 500; }
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
  body.login-prototype .login-card .auth-alert.auth-alert-error {
    background: rgba(248, 113, 113, 0.1);
    border-left-color: #f87171;
    color: #fecaca;
  }
  body.login-prototype .login-card .auth-alert-icon { font-size: 1.25rem; flex-shrink: 0; }
  body.login-prototype .login-card .auth-alert-text { font-weight: 500; }
  @keyframes auth-alert-in {
    from { opacity: 0; transform: translateY(-6px); }
    to { opacity: 1; transform: translateY(0); }
  }
  body.login-prototype .login-card .auth-submit-btn {
    width: 100%;
    padding: 0.625rem 1rem;
    margin-top: 1.5rem;
    border-radius: 0.75rem;
    background: #1F58C3 !important;
    color: #fff !important;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    font-weight: 600;
    font-size: 0.875rem;
    transition: background 0.2s ease, transform 0.2s ease, box-shadow 0.2s ease;
    border: none;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    min-height: 2.5rem;
  }
  body.login-prototype .login-card .auth-submit-btn:hover {
    background: #1E40AF !important;
    transform: translateY(-2px);
  }
  body.login-prototype .login-card .auth-submit-btn:active {
    transform: translateY(0) scale(0.98);
  }
  body.login-prototype .login-card .auth-secondary-btn {
    width: 100%;
    padding: 0.625rem 1rem;
    margin-top: 1rem;
    border-radius: 0.75rem;
    background: #1e293b !important;
    border: 1px solid rgba(31, 88, 195, 0.3) !important;
    color: #fff !important;
    font-size: 0.875rem;
    font-weight: 500;
    transition: background 0.2s ease, border-color 0.2s ease, transform 0.2s ease;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 2.5rem;
    text-decoration: none;
  }
  body.login-prototype .login-card .auth-secondary-btn:hover {
    background: #334155 !important;
    border-color: rgba(245, 158, 11, 0.4) !important;
    transform: translateY(-2px);
    color: #fff;
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
  body.login-prototype .login-card .auth-success-msg { color: #94a3b8; font-size: 0.875rem; }
</style>
