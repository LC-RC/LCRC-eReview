<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo isset($pageTitle) ? h($pageTitle) . ' - ' : ''; ?>LCRC eReview</title>
<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<?php
$tailwindFile = __DIR__ . '/../assets/css/tailwind.css';
$useBuiltCss = file_exists($tailwindFile) && filesize($tailwindFile) > 1000;
if ($useBuiltCss): ?>
<link rel="stylesheet" href="assets/css/tailwind.css?v=<?php echo filemtime($tailwindFile); ?>">
<?php else: ?>
<script src="https://cdn.tailwindcss.com"></script>
<script>
  tailwind.config = {
    theme: {
      extend: {
        colors: {
          brand: { navy: '#0e3a55', 'navy-dark': '#0d2b55', gold: '#f2b01e', 'gold-light': '#ffd166' },
          primary: { DEFAULT: '#4154f1', dark: '#2d3fc7' },
          accent: { orange: '#F59E0B', 'orange-dark': '#D97706', 'orange-light': '#FCD34D', blue: '#1F58C3', 'blue-dark': '#1E40AF', 'blue-light': '#3B82F6' }
        },
        fontFamily: { sans: ['Nunito', 'Segoe UI', 'sans-serif'] },
        boxShadow: { card: '0 2px 10px rgba(0,0,0,0.05)', 'card-lg': '0 10px 24px rgba(0,0,0,0.06)', modal: '0 20px 50px rgba(0,0,0,0.12)', 'modal-xl': '0 25px 60px rgba(0,0,0,0.15), 0 0 0 1px rgba(0,0,0,0.04)', glass: '0 25px 60px rgba(0,0,0,0.25), 0 0 0 1px rgba(255,255,255,0.06), inset 0 1px 0 0 rgba(255,255,255,0.12)' },
        keyframes: { 'slide-up-fade': { '0%': { opacity: '0', transform: 'translateY(14px)' }, '100%': { opacity: '1', transform: 'translateY(0)' } } },
        animation: { 'slide-up-fade': 'slide-up-fade 0.45s ease-out both', 'slide-up-fade-1': 'slide-up-fade 0.45s ease-out 0.05s both', 'slide-up-fade-2': 'slide-up-fade 0.45s ease-out 0.10s both', 'slide-up-fade-3': 'slide-up-fade 0.45s ease-out 0.15s both', 'slide-up-fade-4': 'slide-up-fade 0.45s ease-out 0.20s both', 'slide-up-fade-5': 'slide-up-fade 0.45s ease-out 0.25s both', 'slide-up-fade-6': 'slide-up-fade 0.45s ease-out 0.30s both', 'slide-up-fade-7': 'slide-up-fade 0.45s ease-out 0.35s both', 'slide-up-fade-8': 'slide-up-fade 0.45s ease-out 0.40s both', 'slide-up-fade-9': 'slide-up-fade 0.45s ease-out 0.45s both', 'slide-up-fade-10': 'slide-up-fade 0.45s ease-out 0.50s both' }
      }
    }
  };
</script>
<?php endif; ?>
<style>
  /* Add the new styles here */
  .animated-bg {
    position: fixed;
    inset: 0;
    z-index: 0;
    overflow: hidden;
    background:
      radial-gradient(circle at 0% 0%, #fef3c7 0, #fef3c7 11%, transparent 48%),
      radial-gradient(circle at 100% 100%, #dbeafe 0, #dbeafe 14%, transparent 55%),
      linear-gradient(135deg, #f9fafb 0%, #eef2ff 35%, #e0f2fe 100%);
  }
  /* On auth pages, hide large floating shapes so the card is the focus */
  body.auth-page .animated-bg::before,
  body.auth-page .animated-bg::after {
    display: none;
  }
  .animated-bg::before,
  .animated-bg::after {
    content: '';
    position: absolute;
    border-radius: 20px;
    background-color: #fefefe;
    box-shadow: 0 24px 60px rgba(15, 23, 42, 0.16);
  }
  /* Floating exam sheet on the left */
  .animated-bg::before {
    width: 360px;
    height: 230px;
    top: 6%;
    left: -90px;
    background:
      /* header band for exam title */
      linear-gradient(to right, rgba(31, 88, 195, 0.12), rgba(249, 250, 251, 0.0) 38%),
      /* subtle question bullets on the left margin */
      radial-gradient(circle at 8% 28%, rgba(251, 191, 36, 0.7) 0, rgba(251, 191, 36, 0.0) 9px),
      radial-gradient(circle at 8% 52%, rgba(59, 130, 246, 0.65) 0, rgba(59, 130, 246, 0.0) 9px),
      radial-gradient(circle at 8% 76%, rgba(251, 113, 133, 0.65) 0, rgba(251, 113, 133, 0.0) 9px),
      /* lined paper body */
      linear-gradient(to bottom, rgba(249, 250, 251, 0.97), rgba(248, 250, 252, 0.98)),
      repeating-linear-gradient(
        to bottom,
        rgba(148, 163, 184, 0.22) 34px,
        rgba(148, 163, 184, 0.22) 35px,
        transparent 35px,
        transparent 52px
      );
    background-blend-mode: normal, normal, normal, normal, normal, multiply;
    border: 1px solid rgba(148, 163, 184, 0.22);
    transform: rotate(-9deg);
    animation: bg-card-left 26s ease-in-out infinite alternate;
  }
  /* Floating study dashboard / video card on the right */
  .animated-bg::after {
    width: 420px;
    height: 260px;
    bottom: -130px;
    right: -40px;
    background:
      /* card base */
      linear-gradient(to bottom right, rgba(239, 246, 255, 0.98), rgba(219, 234, 254, 0.98)),
      /* video thumbnail */
      linear-gradient(
        to bottom,
        rgba(15, 23, 42, 0.92) 0,
        rgba(15, 23, 42, 0.92) 40%,
        transparent 40%,
        transparent 100%
      ),
      radial-gradient(circle at 26% 18%, rgba(248, 250, 252, 0.15) 0, transparent 55%),
      /* progress bars / score chips */
      linear-gradient(to right, rgba(59, 130, 246, 0.65) 0, rgba(59, 130, 246, 0.65) 48%, transparent 48%),
      linear-gradient(to right, rgba(251, 191, 36, 0.9) 0, rgba(251, 191, 36, 0.9) 32%, transparent 32%),
      linear-gradient(to right, rgba(16, 185, 129, 0.9) 0, rgba(16, 185, 129, 0.9) 68%, transparent 68%),
      /* overlay positioning those bars */
      linear-gradient(to bottom, transparent 44%, rgba(15, 23, 42, 0.03) 44%, rgba(15, 23, 42, 0.03) 100%);
    background-size:
      100% 100%,
      100% 58%,
      100% 58%,
      58% 9px,
      42% 9px,
      72% 9px,
      100% 100%;
    background-position:
      0 0,
      0 0,
      0 0,
      12% 66%,
      12% 76%,
      12% 86%,
      0 0;
    background-repeat: no-repeat;
    border: 1px solid rgba(148, 163, 184, 0.2);
    transform: rotate(11deg);
    animation: bg-card-right 30s ease-in-out infinite alternate;
  }
  @keyframes bg-card-left {
    0% {
      transform: translate3d(0, 0, 0) rotate(-8deg);
    }
    50% {
      transform: translate3d(18px, 18px, 0) rotate(-5deg);
    }
    100% {
      transform: translate3d(6px, -10px, 0) rotate(-7deg);
    }
  }
  @keyframes bg-card-right {
    0% {
      transform: translate3d(0, 0, 0) rotate(10deg);
    }
    50% {
      transform: translate3d(-20px, -18px, 0) rotate(7deg);
    }
    100% {
      transform: translate3d(-6px, 10px, 0) rotate(9deg);
    }
  }
  .login-grid {
    align-items: center;
    gap: 4rem;
  }
  .login-image {
    border-radius: 1.5rem;
    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
    width: 100%;
    height: 100%;
    object-fit: cover;
  }
  .login-form-container {
    background-color: white;
    padding: 2rem;
    border-radius: 1.5rem;
    box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
  }
  /* Scroll reveal: content stays hidden until section is in view; once revealed it stays visible (no Alpine state reset) */
  .scroll-reveal .reveal-content { opacity: 0; transform: translateY(24px); transition: opacity 0.7s ease-out, transform 0.7s ease-out; }
  .scroll-reveal.revealed .reveal-content { opacity: 1; transform: translateY(0); }
  .scroll-reveal.revealed .reveal-delay-0 { transition-delay: 0ms; }
  .scroll-reveal.revealed .reveal-delay-1 { transition-delay: 100ms; }
  .scroll-reveal.revealed .reveal-delay-2 { transition-delay: 150ms; }
  .scroll-reveal.revealed .reveal-delay-3 { transition-delay: 200ms; }
  .scroll-reveal.revealed .reveal-delay-4 { transition-delay: 300ms; }
  
  /* Chatbot visibility - ensure it's always visible */
  #chatbot-container { position: fixed !important; bottom: 1.5rem !important; right: 1.5rem !important; z-index: 9999 !important; }
  #chatbot-container button { display: flex !important; visibility: visible !important; opacity: 1 !important; }
  
  /* Chatbox positioning and size - properly big but constrained height */
  #chatbot-container > div[x-show] { 
    height: 600px !important;
    max-height: min(600px, calc(100vh - 8rem)) !important;
    min-height: 420px !important;
    top: auto !important;
    bottom: calc(100% + 1rem) !important;
    width: min(420px, calc(100vw - 2rem)) !important;
  }
  @media (min-width: 640px) {
    #chatbot-container > div[x-show] { 
      width: 460px !important;
    }
  }
  @media (max-height: 700px) {
    #chatbot-container > div[x-show] { 
      height: auto !important;
      max-height: calc(100vh - 7rem) !important;
      min-height: 380px !important;
    }
  }
  @media (max-height: 600px) {
    #chatbot-container > div[x-show] { 
      height: auto !important;
      max-height: calc(100vh - 6rem) !important;
      min-height: 340px !important;
    }
  }
  
  /* Chatbot floating button color theme */
  .chatbot-btn-open {
    background: linear-gradient(135deg, #F59E0B 0%, #D97706 100%) !important;
    box-shadow: 0 20px 50px -10px rgba(245, 158, 11, 0.45) !important;
  }
  .chatbot-btn-open:hover {
    box-shadow: 0 24px 56px -10px rgba(245, 158, 11, 0.5) !important;
  }
  .chatbot-btn-open:focus {
    --tw-ring-color: rgba(245, 158, 11, 0.5);
  }
  .chatbot-btn-close {
    background: linear-gradient(135deg, #1F58C3 0%, #1E40AF 100%) !important;
    box-shadow: 0 20px 40px -10px rgba(31, 88, 195, 0.4) !important;
  }
  .chatbot-btn-close:hover {
    box-shadow: 0 24px 48px -10px rgba(31, 88, 195, 0.5) !important;
  }
  .chatbot-btn-close:focus {
    --tw-ring-color: rgba(31, 88, 195, 0.5);
  }
  
  /* Enhanced Chatbot Animations & Styles */
  @keyframes pulse-slow {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.05); }
  }
  .animate-pulse-slow {
    animation: pulse-slow 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
  }
  
  @keyframes chat-open {
    0% { opacity: 0; transform: translateY(22px) scale(0.94) rotate(1deg); }
    60% { opacity: 1; transform: translateY(0) scale(1.02) rotate(0); }
    100% { opacity: 1; transform: translateY(0) scale(1) rotate(0); }
  }
  @keyframes chat-close {
    0% { opacity: 1; transform: translateY(0) scale(1) rotate(0); }
    100% { opacity: 0; transform: translateY(12px) scale(0.98) rotate(0.5deg); }
  }
  .anim-chat-open {
    animation: chat-open 420ms cubic-bezier(0.22, 1, 0.36, 1);
  }
  .anim-chat-close {
    animation: chat-close 220ms cubic-bezier(0.4, 0, 1, 1);
  }

  /* Chatbot Panel Enhancements */
  .chatbot-panel {
    transform-origin: 100% 100%;
    backdrop-filter: blur(10px);
    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25), 0 0 0 1px rgba(255, 255, 255, 0.1);
  }
  
  .chatbot-header {
    position: relative;
  }
  .chatbot-header::before {
    content: '';
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
    animation: shimmer 3s infinite;
  }
  @keyframes shimmer {
    0% { left: -100%; }
    100% { left: 100%; }
  }
  
  /* Enhanced Messages Area - Constrained height with scrolling */
  .chatbot-messages {
    flex: 1 1 0;
    min-height: 0;
    max-height: 100%;
    overflow-y: auto;
    overflow-x: hidden;
    scrollbar-width: thin;
    scrollbar-color: rgba(31, 88, 195, 0.3) transparent;
  }
  .chatbot-messages::-webkit-scrollbar {
    width: 6px;
  }
  .chatbot-messages::-webkit-scrollbar-track {
    background: transparent;
  }
  .chatbot-messages::-webkit-scrollbar-thumb {
    background: linear-gradient(to bottom, rgba(31, 88, 195, 0.3), rgba(245, 158, 11, 0.3));
    border-radius: 10px;
  }
  .chatbot-messages::-webkit-scrollbar-thumb:hover {
    background: linear-gradient(to bottom, rgba(31, 88, 195, 0.5), rgba(245, 158, 11, 0.5));
  }
  
  /* Ensure chatbot panel uses flexbox when visible; Alpine controls visibility */
  .chatbot-panel {
    display: flex;
    flex-direction: column;
  }
  
  /* Hide panel when x-cloak is present (before Alpine loads) */
  [x-cloak] {
    display: none !important;
  }
  
  /* Allow Alpine to manage display without CSS overrides */
  
  /* Header and input should not shrink */
  .chatbot-header,
  .chatbot-input {
    flex-shrink: 0;
  }
  
  /* Message Bubbles */
  .message-wrapper > div {
    position: relative;
    animation: messageSlideIn 0.3s ease-out;
  }
  @keyframes messageSlideIn {
    from {
      opacity: 0;
      transform: translateY(10px) scale(0.95);
    }
    to {
      opacity: 1;
      transform: translateY(0) scale(1);
    }
  }
  
  /* Enhanced Chatbot Button */
  .chatbot-button {
    position: relative;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    transform: translateZ(0);
  }
  .chatbot-button::after {
    content: '';
    position: absolute;
    inset: -2px;
    border-radius: 1rem;
    padding: 2px;
    background: linear-gradient(45deg, rgba(245, 158, 11, 0.5), rgba(31, 88, 195, 0.5));
    -webkit-mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
    -webkit-mask-composite: xor;
    mask-composite: exclude;
    opacity: 0;
    transition: opacity 0.3s;
  }
  .chatbot-button:hover::after {
    opacity: 1;
    animation: rotate-border 3s linear infinite;
  }
  @keyframes rotate-border {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
  }
  .chatbot-button:hover {
    transform: translateY(-6px) scale(1.06);
  }
  .chatbot-button:active {
    transform: translateY(-2px) scale(0.98);
  }
  .chatbot-button .icon {
    transition: transform 0.45s cubic-bezier(0.22, 1, 0.36, 1);
    will-change: transform;
  }
  .chatbot-button.is-open .icon {
    transform: rotate(-180deg) scale(1.05);
  }
  .chatbot-button.is-closed .icon {
    transform: rotate(0deg) scale(1);
  }
  .chatbot-button:not(.is-open) .notify-dot {
    animation: dot-pulse 2.5s ease-in-out infinite;
  }
  @keyframes dot-pulse {
    0%, 100% { transform: scale(1); opacity: 1; }
    50% { transform: scale(1.3); opacity: 0.75; }
  }
  
  /* Input Focus Effects */
  .chatbot-input input:focus {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(245, 158, 11, 0.15);
  }
  
  /* Send Button Enhancements - Properly structured square button, not thin */
  .chatbot-input button.group\/send {
    position: relative;
    flex-shrink: 0;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    border-radius: 1rem;
    box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3);
    width: 4rem !important;
    height: 4rem !important;
    min-width: 4rem !important;
    min-height: 4rem !important;
  }
  .chatbot-input button.group\/send:hover {
    box-shadow: 0 6px 16px rgba(245, 158, 11, 0.4);
  }
  .chatbot-input button.group\/send i {
    font-size: 1.25rem !important;
    line-height: 1;
  }
  
  /* Typing Indicator Animation */
  @keyframes typing-bounce {
    0%, 60%, 100% {
      transform: translateY(0);
      opacity: 0.7;
    }
    30% {
      transform: translateY(-10px);
      opacity: 1;
    }
  }
  .typing-dot {
    animation: typing-bounce 1.4s infinite ease-in-out;
  }
  
  /* Message bubble tail for bot messages */
  .group\/bot::before {
    content: '';
    position: absolute;
    left: -8px;
    top: 0;
    width: 0;
    height: 0;
    border-top: 8px solid transparent;
    border-right: 8px solid white;
    border-bottom: 8px solid transparent;
  }
  
  /* Responsive adjustments - keep panel properly big on small screens */
  @media (max-width: 480px) {
    #chatbot-container > div[x-show] {
      width: calc(100vw - 1.5rem) !important;
      min-height: 380px !important;
    }
    .chatbot-button {
      width: 4rem !important;
      height: 4rem !important;
    }
  }
  .login-card,
  .auth-card {
    opacity: 0;
    transform: translateY(18px) scale(0.98);
    animation: login-card-in 560ms cubic-bezier(0.22, 1, 0.36, 1) forwards;
    background: rgba(255, 255, 255, 0.96);
    border-radius: 1.5rem;
    border: 1px solid rgba(255, 255, 255, 0.9);
    box-shadow:
      0 24px 60px rgba(15, 23, 42, 0.14),
      0 0 0 1px rgba(148, 163, 184, 0.08);
    backdrop-filter: blur(20px);
  }
  /* Auth page corner decor (modern tech-style, brand colors) */
  .auth-corner-decor {
    position: fixed;
    inset: 0;
    z-index: 0;
    pointer-events: none;
    overflow: hidden;
  }
  .auth-corner-decor::before,
  .auth-corner-decor::after {
    content: '';
    position: absolute;
    width: 120px;
    height: 80px;
    border: 1px solid rgba(245, 158, 11, 0.2);
    border-radius: 8px;
    background: linear-gradient(135deg, rgba(254, 243, 199, 0.25), transparent);
  }
  .auth-corner-decor::before {
    top: 24px;
    left: 24px;
    box-shadow: 2px 2px 0 rgba(31, 88, 195, 0.12);
  }
  .auth-corner-decor::after {
    bottom: 24px;
    right: 24px;
    transform: rotate(180deg);
    border-color: rgba(31, 88, 195, 0.2);
    background: linear-gradient(135deg, rgba(219, 234, 254, 0.3), transparent);
    box-shadow: -2px -2px 0 rgba(245, 158, 11, 0.1);
  }
  .auth-corner-dot {
    position: absolute;
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: rgba(245, 158, 11, 0.5);
  }
  .auth-corner-dot.blue { background: rgba(31, 88, 195, 0.5); }
  .auth-corner-dot.tl { top: 42px; left: 42px; }
  .auth-corner-dot.tr { top: 42px; right: 42px; }
  .auth-corner-dot.bl { bottom: 42px; left: 42px; }
  .auth-corner-dot.br { bottom: 42px; right: 42px; }
  .login-piece {
    opacity: 0;
    transform: translateY(20px);
    animation: login-piece-in 520ms cubic-bezier(0.22, 1, 0.36, 1) forwards;
  }
  .login-piece-1 { animation-delay: 80ms; }
  .login-piece-2 { animation-delay: 140ms; }
  .login-piece-3 { animation-delay: 200ms; }
  .login-piece-4 { animation-delay: 260ms; }
  .login-piece-5 { animation-delay: 320ms; }
  .login-piece-6 { animation-delay: 380ms; }
  .login-piece-7 { animation-delay: 440ms; }
  .login-piece-8 { animation-delay: 500ms; }
  .login-piece-9 { animation-delay: 560ms; }
  @keyframes login-card-in {
    0% {
      opacity: 0;
      transform: translateY(22px) scale(0.96);
    }
    60% {
      opacity: 1;
      transform: translateY(0) scale(1.01);
    }
    100% {
      opacity: 1;
      transform: translateY(0) scale(1);
    }
  }
  @keyframes login-piece-in {
    0% {
      opacity: 0;
      transform: translateY(22px);
    }
    100% {
      opacity: 1;
      transform: translateY(0);
    }
  }
  .register-piece {
    opacity: 0;
    transform: translateY(26px);
    animation: register-piece-in 620ms cubic-bezier(0.22, 1, 0.36, 1) forwards;
  }
  .register-piece-1 { animation-delay: 80ms; }
  .register-piece-2 { animation-delay: 140ms; }
  .register-piece-3 { animation-delay: 200ms; }
  .register-piece-4 { animation-delay: 260ms; }
  .register-piece-5 { animation-delay: 320ms; }
  .register-piece-6 { animation-delay: 380ms; }
  .register-piece-7 { animation-delay: 440ms; }
  .register-piece-8 { animation-delay: 500ms; }
  @keyframes register-piece-in {
    0% {
      opacity: 0;
      transform: translateY(28px);
    }
    100% {
      opacity: 1;
      transform: translateY(0);
    }
  }
  .btn-shine {
    position: relative;
    overflow: hidden;
  }
  .btn-shine::before {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(90deg, rgba(255, 255, 255, 0), rgba(255, 255, 255, 0.55), rgba(255, 255, 255, 0));
    transform: translateX(-120%);
    transition: transform 0.55s ease-out;
    pointer-events: none;
  }
  .btn-shine:hover::before {
    transform: translateX(120%);
  }
  .btn-shine > * {
    position: relative;
    z-index: 1;
  }
  .login-google-button-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 30px;
    height: 30px;
    border-radius: 9999px;
    background-color: #ffffff;
    box-shadow: 0 6px 14px rgba(15, 23, 42, 0.18);
    flex-shrink: 0;
  }
  .login-google-button-icon img {
    width: 18px;
    height: 18px;
    display: block;
  }
  @media (max-width: 640px) {
    .login-google-button-icon {
      width: 26px;
      height: 26px;
    }
    .login-google-button-icon img {
      width: 16px;
      height: 16px;
      display: block;
    }
  }
  .login-footer-signup {
    font-size: 0.875rem;
    color: #4b5563;
  }
  .login-footer-copy {
    position: fixed;
    left: 50%;
    bottom: 16px;
    transform: translateX(-50%);
    font-size: 10px;
    line-height: 1.4;
    color: #9ca3af;
  }
  .login-loading-backdrop,
  .login-error-backdrop {
    position: fixed;
    inset: 0;
    z-index: 60;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 1.5rem;
    background: radial-gradient(circle at 10% 0%, rgba(250, 250, 250, 0.18), transparent 55%), rgba(15, 23, 42, 0.55);
    backdrop-filter: blur(18px);
    opacity: 0;
    pointer-events: none;
    transition: opacity 200ms ease-out;
  }
  .login-loading-backdrop.is-active,
  .login-error-backdrop.is-active {
    opacity: 1;
    pointer-events: auto;
  }
  .login-loading-orb {
    width: 64px;
    height: 64px;
    border-radius: 9999px;
    background: conic-gradient(from 180deg, #f59e0b, #1f58c3, #f59e0b);
    padding: 3px;
    animation: login-orb-spin 900ms linear infinite;
  }
  .login-loading-orb-inner {
    width: 100%;
    height: 100%;
    border-radius: inherit;
    background: radial-gradient(circle at 30% 0%, rgba(255, 255, 255, 0.95), rgba(248, 250, 252, 0.98));
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 12px 30px rgba(15, 23, 42, 0.3);
  }
  .login-loading-orb-inner span {
    width: 12px;
    height: 12px;
    border-radius: 9999px;
    background: linear-gradient(135deg, #f59e0b, #f97316);
    box-shadow: 0 0 0 0 rgba(245, 158, 11, 0.6);
    animation: login-orb-pulse 1100ms ease-out infinite;
  }
  @keyframes login-orb-spin {
    to {
      transform: rotate(360deg);
    }
  }
  @keyframes login-orb-pulse {
    0% {
      transform: scale(0.9);
      box-shadow: 0 0 0 0 rgba(245, 158, 11, 0.6);
    }
    70% {
      transform: scale(1.1);
      box-shadow: 0 0 0 16px rgba(245, 158, 11, 0);
    }
    100% {
      transform: scale(0.9);
      box-shadow: 0 0 0 0 rgba(245, 158, 11, 0);
    }
  }
  .login-error-card {
    max-width: 360px;
    width: 100%;
    border-radius: 1.25rem;
    padding: 1.5rem 1.75rem 1.75rem;
    background: linear-gradient(135deg, rgba(15, 23, 42, 0.95), rgba(15, 23, 42, 0.92));
    box-shadow: 0 24px 60px rgba(15, 23, 42, 0.6);
    color: #e5e7eb;
    transform: translateY(12px) scale(0.96);
    opacity: 0;
    transition: opacity 220ms ease-out, transform 220ms ease-out;
  }
  .login-error-backdrop.is-active .login-error-card {
    opacity: 1;
    transform: translateY(0) scale(1);
  }
  .login-error-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 0.75rem;
  }
  .login-error-circle {
    width: 52px;
    height: 52px;
    border-radius: 9999px;
    border: 2px solid rgba(248, 250, 252, 0.9);
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
    box-shadow: 0 14px 30px rgba(0, 0, 0, 0.45);
    animation: login-error-pop 260ms cubic-bezier(0.22, 1, 0.36, 1) forwards;
  }
  .login-error-line {
    position: absolute;
    width: 24px;
    height: 3px;
    border-radius: 9999px;
    background: linear-gradient(90deg, #fecaca, #f97373);
    transform-origin: center;
    opacity: 0;
  }
  .login-error-line-1 {
    transform: rotate(45deg) scaleX(0);
    animation: login-error-line 260ms 90ms cubic-bezier(0.22, 1, 0.36, 1) forwards;
  }
  .login-error-line-2 {
    transform: rotate(-45deg) scaleX(0);
    animation: login-error-line 260ms 150ms cubic-bezier(0.22, 1, 0.36, 1) forwards;
  }
  @keyframes login-error-pop {
    0% {
      transform: scale(0.7);
      opacity: 0;
    }
    80% {
      transform: scale(1.1);
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
  #login-password::-ms-reveal,
  #login-password::-ms-clear {
    display: none;
  }
</style>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<script defer src="https://cdn.jsdelivr.net/npm/@alpinejs/intersect@3.13.3/cdn.min.js"></script>
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.13.3/dist/cdn.min.js"></script>
<script>
  // Reveal sections when they scroll into view (does not depend on Alpine so content always shows)
  document.addEventListener('DOMContentLoaded', function() {
    var sections = document.querySelectorAll('.scroll-reveal');
    if (!sections.length) return;
    var observer = new IntersectionObserver(function(entries) {
      entries.forEach(function(entry) {
        if (entry.isIntersecting) {
          entry.target.classList.add('revealed');
          observer.unobserve(entry.target);
        }
      });
    }, { threshold: 0 });
    sections.forEach(function(el) { observer.observe(el); });
  });
</script>
