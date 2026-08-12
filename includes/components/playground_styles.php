<?php /** CPA Playground — full-width game mode theme (scoped to .pg-theme). */ ?>
<style>
  .pg-theme {
    --pg-bg: #070b16;
    --pg-bg-2: #0d1428;
    --pg-panel: #121a33;
    --pg-panel-2: #182240;
    --pg-ink: #ffffff;
    --pg-muted: #cbd5e1; /* high-contrast secondary (WCAG-friendly on navy) */
    --pg-muted-2: #94a3b8;
    --pg-blue: #4db3ef;
    --pg-lcrc: #1665A0;
    --pg-purple: #8b6cff;
    --pg-indigo: #6366f1;
    --pg-gold: #fbbf24;
    --pg-mint: #34d399;
    --pg-rose: #fb7185;
    --pg-orange: #fb923c;
    --pg-glow: 0 0 28px rgba(139, 108, 255, 0.28);
  }

  /* —— Full-bleed game canvas —— */
  body.app-shell--student.pg-theme .student-dashboard-page.pg-page {
    background:
      radial-gradient(1200px 520px at 12% -8%, rgba(139, 108, 255, 0.22), transparent 55%),
      radial-gradient(900px 480px at 100% 0%, rgba(22, 101, 160, 0.28), transparent 52%),
      radial-gradient(700px 360px at 50% 110%, rgba(251, 191, 36, 0.07), transparent 55%),
      linear-gradient(180deg, #070b16 0%, #0d1428 45%, #070b16 100%) !important;
    color: var(--pg-ink) !important;
    min-height: calc(100vh - 4.5rem);
    margin: 0 !important;
    padding: 1.25rem clamp(1rem, 2.5vw, 2.5rem) 2.5rem !important;
    border-radius: 0;
    width: 100%;
    max-width: none !important;
    box-sizing: border-box;
  }

  /* Hide left sidebar during play/result — hamburger still opens as overlay */
  body.pg-game-mode.app-shell--student #app-sidebar.app-shell-sidebar--student {
    transform: translateX(calc(-100% - 1.5rem));
    opacity: 0;
    pointer-events: none;
    transition: transform .25s ease, opacity .2s ease;
  }
  body.pg-game-mode.app-shell--student.sidebar-expanded #app-sidebar.app-shell-sidebar--student {
    transform: none;
    opacity: 1;
    pointer-events: auto;
    z-index: 60;
    box-shadow: 0 20px 50px rgba(0,0,0,.55);
  }
  body.pg-game-mode.app-shell--student #main.app-shell-main--student,
  body.pg-game-mode.app-shell--student.sidebar-expanded #main.app-shell-main--student {
    margin-left: 0 !important;
    padding-left: 0 !important;
    padding-right: 0 !important;
    max-width: none !important;
  }
  body.pg-game-mode.app-shell--student #sidebar-backdrop.app-shell-backdrop {
    /* keep backdrop working when overlay sidebar opens */
  }

  /* Lobby: wide game lobby canvas (sidebar stays for navigation) */
  body.pg-theme:not(.pg-game-mode) .pg-page {
    max-width: 1480px;
    margin-left: auto;
    margin-right: auto;
  }
  body.pg-theme.pg-lobby-mode .pg-lobby-page {
    padding-top: .85rem !important;
    padding-bottom: 2rem !important;
  }

  /* —— Lobby (above-the-fold game lobby) —— */
  .pg-lobby-fold {
    display: flex; flex-direction: column; gap: .75rem;
    margin-bottom: 1.1rem;
  }
  .pg-lobby-hero {
    display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center;
    gap: .65rem 1rem;
    padding: .85rem 1.15rem;
    border-radius: 1rem;
    background: linear-gradient(135deg, rgba(22,101,160,.5) 0%, rgba(20,61,89,.9) 45%, rgba(7,11,22,.98) 100%);
    border: 1px solid rgba(139, 108, 255, 0.35);
    box-shadow: var(--pg-glow), 0 10px 28px rgba(0,0,0,.28);
  }
  .pg-lobby-kicker {
    margin: 0 0 .15rem; font-size: .68rem; font-weight: 800; letter-spacing: .14em;
    text-transform: uppercase; color: #e2e8f0;
  }
  .pg-lobby-title {
    margin: 0; font-size: clamp(1.35rem, 2.4vw, 1.75rem); font-weight: 900;
    letter-spacing: -.02em; color: #fff; line-height: 1.15;
  }
  .pg-lobby-sub {
    margin: .25rem 0 0; max-width: 42rem; font-size: .9rem; color: #e2e8f0; line-height: 1.35;
  }
  .pg-lobby-meta {
    display: inline-flex; flex-wrap: wrap; align-items: center; gap: .55rem .75rem;
  }
  .pg-lobby-sound {
    display: inline-flex; align-items: center; flex-wrap: wrap; gap: .35rem;
  }
  .pg-music-hint {
    font-size: .68rem; font-weight: 700; color: #fde68a;
    letter-spacing: .01em; white-space: nowrap;
  }
  .pg-lobby-pts {
    display: inline-flex; align-items: center; gap: .4rem;
    padding: .55rem .9rem; border-radius: .8rem;
    background: rgba(251,191,36,.16); border: 1px solid rgba(251,191,36,.5);
    color: #fde68a; font-weight: 900; font-size: 1rem;
  }

  .pg-modes {
    display: grid; grid-template-columns: repeat(2, 1fr); gap: .65rem;
  }
  @media (min-width: 960px) {
    .pg-modes { grid-template-columns: repeat(3, 1fr); gap: .75rem; }
  }
  @media (min-width: 1200px) {
    .pg-modes { grid-template-columns: repeat(5, 1fr); gap: .7rem; }
  }
  a.pg-mode { text-decoration: none; color: inherit; display: block; }
  .pg-mode-battle { --pg-mode-accent: rgba(251,191,36,.4); --pg-mode-border: rgba(251,191,36,.65); }
  .pg-mode {
    position: relative; text-align: left; border: 1px solid rgba(255,255,255,.12);
    border-radius: .95rem; padding: .85rem .9rem .9rem; cursor: pointer;
    background: var(--pg-panel); color: #fff;
    box-shadow: 0 6px 18px rgba(0,0,0,.22);
    transition: transform .15s ease, box-shadow .15s ease, border-color .15s ease;
    overflow: hidden;
  }
  .pg-mode::before {
    content: ''; position: absolute; inset: 0; opacity: .45; pointer-events: none;
    background: linear-gradient(160deg, var(--pg-mode-accent, rgba(139,108,255,.4)), transparent 58%);
  }
  .pg-mode:hover {
    transform: translateY(-2px);
    border-color: var(--pg-mode-border, rgba(139,108,255,.6));
    box-shadow: 0 10px 24px rgba(0,0,0,.3);
  }
  .pg-mode:focus-visible {
    outline: 2px solid rgba(196,181,253,.85);
    outline-offset: 2px;
  }
  .pg-mode.is-selected {
    border-color: var(--pg-mode-border, rgba(139,108,255,.75));
    box-shadow: 0 0 0 2px rgba(139,108,255,.28), 0 10px 24px rgba(0,0,0,.3);
  }
  .pg-mode.is-selected::after {
    content: 'Selected';
    position: absolute; top: .55rem; right: .6rem; z-index: 1;
    font-size: .62rem; font-weight: 800; letter-spacing: .06em; text-transform: uppercase;
    color: #e9d5ff; background: rgba(91,63,212,.55);
    border: 1px solid rgba(196,181,253,.45);
    border-radius: .4rem; padding: .15rem .4rem;
  }
  .pg-mode-quick { --pg-mode-accent: rgba(139,108,255,.45); --pg-mode-border: rgba(139,108,255,.6); }
  .pg-mode-subject { --pg-mode-accent: rgba(77,179,239,.4); --pg-mode-border: rgba(77,179,239,.6); }
  .pg-mode-mixed { --pg-mode-accent: rgba(52,211,153,.35); --pg-mode-border: rgba(52,211,153,.55); }
  .pg-mode-daily { --pg-mode-accent: rgba(251,146,60,.4); --pg-mode-border: rgba(251,146,60,.6); }
  .pg-mode-icon {
    position: relative; width: 2.15rem; height: 2.15rem; border-radius: .65rem;
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 1.1rem; background: rgba(255,255,255,.12); margin-bottom: .45rem;
  }
  .pg-mode h3 { position: relative; margin: 0 0 .2rem; font-size: .95rem; font-weight: 900; color: #fff; }
  .pg-mode p {
    position: relative; margin: 0; font-size: .78rem; color: #e2e8f0; line-height: 1.35;
    min-height: 2.1em; padding-right: .25rem;
  }

  /* Main interaction: Start Your Game */
  .pg-setup {
    width: 100%; max-width: none;
    border-radius: 1.05rem; padding: 1rem 1.15rem 1.1rem;
    background: linear-gradient(180deg, rgba(24,34,64,.98), rgba(18,26,51,.98));
    border: 1px solid rgba(139,108,255,.4);
    box-shadow: 0 12px 32px rgba(0,0,0,.28);
  }
  .pg-setup-head {
    display: flex; flex-wrap: wrap; align-items: flex-start; justify-content: space-between;
    gap: .5rem .85rem; margin-bottom: .75rem;
  }
  .pg-setup h2 {
    margin: 0; font-size: 1.05rem; font-weight: 900; color: #fff;
    letter-spacing: .01em;
  }
  .pg-setup-lead {
    margin: .2rem 0 0; font-size: .82rem; color: #e2e8f0; line-height: 1.35;
  }
  .pg-setup-mode-chip {
    display: inline-flex; align-items: center; gap: .35rem;
    padding: .4rem .75rem; border-radius: .65rem;
    background: rgba(139,108,255,.22); border: 1px solid rgba(196,181,253,.45);
    color: #f5f3ff; font-weight: 800; font-size: .8rem; white-space: nowrap;
  }
  .pg-setup-grid {
    display: flex;
    flex-wrap: wrap;
    gap: .7rem 1.15rem;
    align-items: flex-end;
  }
  .pg-setup-field {
    flex: 1 1 220px;
    min-width: min(100%, 220px);
  }
  .pg-setup-field#pg-subject-wrap {
    flex: 1 1 100%;
  }
  .pg-setup-field label {
    display: block; font-size: .68rem; font-weight: 800; text-transform: uppercase;
    letter-spacing: .08em; color: #e2e8f0; margin-bottom: .35rem;
  }
  .pg-opt-row { display: flex; flex-wrap: wrap; gap: .35rem; }
  .pg-setup .pg-opt {
    display: inline-flex; align-items: center; justify-content: center;
    margin: 0; padding: .42rem .7rem; border-radius: .55rem;
    border: 1px solid rgba(255,255,255,.16);
    background: rgba(255,255,255,.06); cursor: pointer;
    font-size: .82rem; font-weight: 700; color: #fff;
  }
  .pg-setup .pg-opt:has(input:checked) {
    background: var(--pg-purple); border-color: var(--pg-purple); color: #fff;
  }
  .pg-setup select {
    width: 100%; max-width: 28rem; border-radius: .65rem;
    border: 1px solid rgba(255,255,255,.18);
    background: #070b16; color: #fff; padding: .55rem .75rem; font-size: .9rem;
  }
  .pg-setup-field-time { flex: 1 1 280px; min-width: min(100%, 260px); }
  .pg-duration {
    display: inline-flex; align-items: stretch;
    border-radius: .7rem; overflow: hidden;
    border: 1px solid rgba(255,255,255,.22);
    background: #070b16;
    box-shadow: inset 0 0 0 1px rgba(255,255,255,.03);
  }
  .pg-duration-value {
    width: 5.25rem; border: 0; border-right: 1px solid rgba(255,255,255,.16);
    background: transparent; color: #fff;
    padding: .55rem .65rem; font-size: 1rem; font-weight: 800;
    font-variant-numeric: tabular-nums; text-align: center;
  }
  .pg-duration-value:focus {
    outline: none; background: rgba(139,108,255,.12);
  }
  .pg-duration-unit {
    border: 0; background: rgba(255,255,255,.06); color: #fff;
    padding: .55rem .7rem; font-size: .85rem; font-weight: 700;
    cursor: pointer; min-width: 7.25rem;
  }
  .pg-duration-unit:focus {
    outline: none; background: rgba(139,108,255,.18);
  }
  .pg-duration-unit option { background: #0d1428; color: #fff; }
  .pg-time-presets { margin-top: .45rem; }
  .pg-time-preset {
    appearance: none; -webkit-appearance: none;
  }
  .pg-setup .pg-time-preset.is-active,
  .pg-setup .pg-opt.pg-time-preset.is-active {
    background: var(--pg-purple); border-color: var(--pg-purple); color: #fff;
  }
  .pg-rec-hint { margin: .4rem 0 0; font-size: .78rem; color: #e2e8f0; font-weight: 600; }
  .pg-setup-warn { margin: .35rem 0 0; font-size: .78rem; color: #fda4af; font-weight: 600; }
  .pg-setup-error {
    margin: 0 0 .45rem; font-size: .82rem; color: #fda4af; font-weight: 700;
  }
  .pg-setup-error.hidden { display: none; }
  .pg-setup-cta {
    display: flex; flex-direction: column; align-items: stretch; justify-content: flex-end;
    flex: 0 0 auto; margin-left: auto; min-width: 12.5rem;
  }
  @media (max-width: 699px) {
    .pg-setup-cta {
      flex: 1 1 100%;
      margin-left: 0;
    }
  }
  .pg-start-btn {
    display: inline-flex; align-items: center; justify-content: center; gap: .45rem;
    width: 100%; min-width: 12.5rem; padding: .95rem 1.25rem; border: 0; border-radius: .85rem;
    background: linear-gradient(180deg, #9b7cff, #5b3fd4); color: #fff;
    font-weight: 900; font-size: 1.05rem; cursor: pointer;
    box-shadow: 0 8px 22px rgba(139,108,255,.45);
  }
  .pg-start-btn:hover { filter: brightness(1.08); }
  .pg-start-btn:focus-visible { outline: 2px solid #c4b5fd; outline-offset: 2px; }
  .pg-start-btn:disabled { opacity: .55; cursor: wait; filter: none; }

  .pg-lobby-secondary {
    display: grid; gap: .85rem;
  }
  @media (min-width: 1100px) {
    .pg-lobby-secondary {
      grid-template-columns: minmax(0, 1.1fr) minmax(0, 1fr);
      align-items: start;
    }
  }
  .pg-stats-panel, .pg-recent {
    border-radius: 1rem; padding: 1rem 1.1rem; margin-bottom: 0;
    background: var(--pg-panel); border: 1px solid rgba(255,255,255,.1);
  }
  .pg-stats-panel h2, .pg-recent h2 {
    margin: 0 0 .7rem; font-size: .72rem; font-weight: 800; letter-spacing: .14em;
    text-transform: uppercase; color: #e2e8f0;
  }
  .pg-stats-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: .55rem; }
  @media (min-width: 640px) { .pg-stats-grid { grid-template-columns: repeat(4, 1fr); } }
  .pg-stat-tile {
    text-align: center; padding: .7rem .4rem; border-radius: .8rem;
    background: rgba(255,255,255,.06); border: 1px solid rgba(255,255,255,.1);
  }
  .pg-stat-tile .ico { display: block; font-size: 1.05rem; margin-bottom: .15rem; }
  .pg-stat-tile .lbl {
    display: block; font-size: .65rem; font-weight: 800; letter-spacing: .06em;
    text-transform: uppercase; color: #e2e8f0; margin-bottom: .15rem;
  }
  .pg-stat-tile .val { font-size: 1.2rem; font-weight: 900; color: #fff; font-variant-numeric: tabular-nums; }
  .pg-recent-list { list-style: none; margin: 0; padding: 0; display: grid; gap: .4rem; }
  .pg-recent-item {
    display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .45rem;
    padding: .6rem .75rem; border-radius: .7rem;
    background: rgba(255,255,255,.05); border: 1px solid rgba(255,255,255,.08);
    text-decoration: none; color: inherit;
  }
  .pg-recent-item:hover { background: rgba(139,108,255,.16); }
  .pg-recent-mode { font-weight: 800; color: #fff; font-size: .85rem; }
  .pg-recent-meta { font-size: .76rem; color: #e2e8f0; }
  .pg-recent-score { font-weight: 900; color: var(--pg-gold); }

  /* —— PLAY: full-width arena —— */
  .pg-arena {
    width: 100%;
    max-width: min(1680px, 100%);
    margin: 0 auto;
  }
  .pg-play-page .pg-arena { max-width: min(1680px, 100%); }

  .pg-hud {
    display: grid;
    grid-template-columns: 1fr auto;
    gap: .85rem 1.25rem;
    align-items: center;
    margin-bottom: 1.1rem;
    padding: 1rem 1.15rem;
    border-radius: 1.15rem;
    background: rgba(18, 26, 51, 0.92);
    border: 1px solid rgba(255,255,255,.12);
    box-shadow: 0 10px 30px rgba(0,0,0,.28);
  }
  @media (min-width: 900px) {
    .pg-hud {
      grid-template-columns: auto 1fr auto;
      align-items: center;
    }
  }
  .pg-hud-left {
    display: flex; flex-wrap: wrap; align-items: center; gap: .65rem .85rem;
  }
  .pg-hud-brand {
    display: inline-flex; align-items: center; gap: .55rem;
    font-weight: 900; font-size: 1.15rem; color: #fff;
  }
  .pg-hud-brand .ico {
    width: 2.4rem; height: 2.4rem; border-radius: .75rem;
    display: inline-flex; align-items: center; justify-content: center;
    background: linear-gradient(145deg, var(--pg-purple), var(--pg-lcrc));
    box-shadow: 0 0 18px rgba(139,108,255,.5); font-size: 1.15rem;
  }
  .pg-subject-pill {
    display: inline-flex; align-items: center; padding: .4rem .85rem;
    border-radius: .55rem; font-size: .78rem; font-weight: 900; letter-spacing: .08em;
    text-transform: uppercase; color: #fff;
    background: #1665A0; border: 1px solid #4db3ef;
  }
  .pg-hud-center {
    display: flex; flex-wrap: wrap; justify-content: center; gap: .65rem;
  }
  .pg-hud-chip {
    display: inline-flex; align-items: center; gap: .4rem;
    padding: .6rem 1.05rem; border-radius: .85rem; font-weight: 900;
    font-variant-numeric: tabular-nums; color: #fff; font-size: 1.05rem;
    background: rgba(255,255,255,.08); border: 1px solid rgba(255,255,255,.16);
    min-width: 7.5rem; justify-content: center;
  }
  .pg-hud-chip.score {
    background: rgba(251,191,36,.18); border-color: rgba(251,191,36,.55);
  }
  .pg-hud-chip.streak {
    background: rgba(251,146,60,.18); border-color: rgba(251,146,60,.55);
  }
  .pg-hud-chip.timer-chip {
    background: rgba(139,108,255,.18); border-color: rgba(139,108,255,.5);
    min-width: 5.5rem;
  }
  .pg-hud-chip .chip-lbl {
    font-size: .68rem; font-weight: 800; letter-spacing: .08em; color: #e2e8f0;
    text-transform: uppercase;
  }
  .pg-hud-chip strong { color: #fff; font-size: 1.2rem; }
  .pg-hud-chip.is-bump { animation: pg-chip-bump .35s ease; }
  @keyframes pg-chip-bump {
    0% { transform: scale(1); }
    40% { transform: scale(1.08); }
    100% { transform: scale(1); }
  }
  .pg-hud-right { display: flex; align-items: center; gap: .5rem; justify-self: end; }
  .pg-audio-controls {
    display: inline-flex; flex-wrap: wrap; gap: .3rem;
    padding: .25rem; border-radius: .7rem;
    background: rgba(0,0,0,.18); border: 1px solid rgba(255,255,255,.08);
  }
  .pg-sound-btn {
    display: inline-flex; align-items: center; gap: .3rem;
    padding: .35rem .55rem; border-radius: .55rem;
    border: 1px solid rgba(255,255,255,.16); background: transparent;
    color: #cbd5e1; font-weight: 700; font-size: .7rem; cursor: pointer;
    letter-spacing: .01em;
  }
  .pg-sound-btn:hover,
  .pg-sound-btn:focus-visible {
    background: rgba(255,255,255,.1);
    color: #fff;
    border-color: rgba(255,255,255,.28);
    outline: none;
  }
  .pg-sound-btn.is-off { opacity: .55; color: #94a3b8; }
  .pg-q-inline {
    font-weight: 800; color: #fff; font-size: .95rem;
    padding: .3rem .65rem; border-radius: .5rem;
    background: rgba(255,255,255,.08); border: 1px solid rgba(255,255,255,.12);
  }
  .pg-custom-input {
    width: 6rem; border-radius: .6rem; border: 1px solid rgba(255,255,255,.2);
    background: #070b16; color: #fff; padding: .5rem .65rem; font-weight: 700;
  }

  /* Total exam timer */
  .pg-exam-timer {
    text-align: center; margin: .25rem 0 1rem;
    padding: 1rem 1.25rem; border-radius: 1.15rem;
    background: rgba(18, 26, 51, 0.95);
    border: 2px solid rgba(139,108,255,.45);
    box-shadow: 0 0 28px rgba(139,108,255,.2);
  }
  .pg-exam-timer-label {
    font-size: .72rem; font-weight: 800; letter-spacing: .14em;
    text-transform: uppercase; color: #e2e8f0;
  }
  .pg-exam-timer-value {
    font-size: clamp(2.4rem, 5vw, 3.25rem); font-weight: 900; color: #fff;
    font-variant-numeric: tabular-nums; line-height: 1.1; margin-top: .2rem;
  }
  .pg-exam-timer-warn {
    margin-top: .45rem; font-weight: 800; font-size: .95rem; color: #fde68a;
  }
  .pg-exam-timer[data-state="warn5"] {
    border-color: rgba(251,146,60,.65);
    box-shadow: 0 0 28px rgba(251,146,60,.25);
  }
  .pg-exam-timer[data-state="warn2"] {
    border-color: rgba(251,113,133,.7);
    box-shadow: 0 0 32px rgba(251,113,133,.3);
  }
  .pg-exam-timer[data-state="urgent"] {
    border-color: #fb7185;
    box-shadow: 0 0 36px rgba(251,113,133,.45);
    animation: pg-timer-pulse .8s ease-in-out infinite;
  }
  .pg-exam-timer[data-state="urgent"] .pg-exam-timer-value { color: #fecdd3; }

  /* Question navigator + finish control */
  .pg-nav-row {
    display: flex; flex-wrap: wrap; align-items: center; gap: .65rem .85rem;
    margin: 0 0 1rem;
  }
  .pg-nav {
    display: flex; flex-wrap: wrap; gap: .4rem;
    flex: 1 1 auto; margin: 0; padding: .75rem .85rem;
    border-radius: .9rem; background: rgba(255,255,255,.04);
    border: 1px solid rgba(255,255,255,.1);
  }
  .pg-nav-btn {
    min-width: 2.35rem; height: 2.35rem; padding: 0 .45rem;
    border-radius: .55rem; border: 1px solid rgba(255,255,255,.18);
    background: rgba(255,255,255,.06); color: #fff; font-weight: 800; font-size: .8rem;
    cursor: pointer;
  }
  .pg-nav-btn.is-answered { background: rgba(52,211,153,.2); border-color: rgba(52,211,153,.55); }
  .pg-nav-btn.is-current {
    background: rgba(139,108,255,.35); border-color: #c4b5fd;
    box-shadow: 0 0 0 2px rgba(139,108,255,.35);
  }
  .pg-nav-btn.is-unanswered { color: #e2e8f0; }
  .pg-nav-btn:hover { filter: brightness(1.1); }
  .pg-finish-link {
    flex: 0 0 auto;
    padding: .55rem .9rem; border-radius: .7rem;
    border: 1px solid rgba(255,255,255,.22);
    background: rgba(255,255,255,.06);
    color: #e2e8f0; font-weight: 800; font-size: .8rem;
    letter-spacing: .02em; cursor: pointer;
  }
  .pg-finish-link:hover,
  .pg-finish-link:focus-visible {
    background: rgba(255,255,255,.12);
    color: #fff; border-color: rgba(196,181,253,.55);
    outline: none;
  }

  .pg-play-actions {
    margin-top: 1.35rem; display: flex; flex-wrap: wrap; gap: .65rem;
    justify-content: center; align-items: center;
  }
  .pg-skip-btn {
    display: inline-flex; align-items: center; justify-content: center; gap: .4rem;
    min-width: 11.5rem; padding: .75rem 1.25rem;
    border-radius: .85rem;
    border: 1px solid rgba(255,255,255,.28);
    background: rgba(255,255,255,.07);
    color: #f8fafc; font-weight: 800; font-size: .95rem;
    letter-spacing: .01em; cursor: pointer;
    box-shadow: 0 4px 14px rgba(0,0,0,.18);
    transition: background .15s ease, border-color .15s ease, transform .12s ease;
  }
  .pg-skip-btn:hover {
    background: rgba(255,255,255,.12);
    border-color: rgba(77,179,239,.55);
    transform: translateY(-1px);
  }
  .pg-skip-btn:focus-visible {
    outline: 2px solid rgba(77,179,239,.75);
    outline-offset: 2px;
  }
  .pg-skip-btn:active { transform: translateY(0); }
  .pg-btn-secondary {
    display: inline-flex; align-items: center; justify-content: center;
    padding: .7rem 1rem; border-radius: .75rem;
    border: 1px solid rgba(255,255,255,.24);
    background: rgba(255,255,255,.07);
    color: #f1f5f9; font-weight: 800; font-size: .9rem; cursor: pointer;
  }
  .pg-btn-secondary:hover,
  .pg-btn-secondary:focus-visible {
    background: rgba(255,255,255,.12);
    border-color: rgba(255,255,255,.4);
    outline: none;
  }

  .pg-modal {
    position: fixed; inset: 0; z-index: 90;
    display: flex; align-items: center; justify-content: center;
    background: rgba(0,0,0,.55); padding: 1rem;
  }
  .pg-modal[hidden] { display: none !important; }
  .pg-modal-card {
    width: min(480px, 100%);
    background: #151d36; border: 1px solid rgba(139,108,255,.4);
    border-radius: 1.15rem; padding: 1.5rem 1.35rem;
    box-shadow: 0 20px 50px rgba(0,0,0,.45); color: #fff;
  }
  .pg-modal-card h2 { margin: 0 0 .5rem; font-size: 1.25rem; font-weight: 900; }
  .pg-modal-card p { margin: 0; color: #e2e8f0; font-weight: 600; line-height: 1.45; }
  .pg-modal-actions {
    display: flex; flex-wrap: wrap; gap: .55rem; margin-top: 1.15rem;
  }
  .pg-modal-actions .pg-next-btn,
  .pg-modal-actions .pg-btn-secondary,
  .pg-modal-actions .cpa-toolbar-btn { flex: 1 1 auto; justify-content: center; }

  .pg-score-float {
    position: fixed; z-index: 80; pointer-events: none;
    font-weight: 900; font-size: 1.25rem; color: var(--pg-gold);
    text-shadow: 0 0 14px rgba(251,191,36,.75);
    animation: pg-float-up .9s ease forwards;
  }
  @keyframes pg-float-up {
    0% { opacity: 1; transform: translateY(0) scale(1); }
    100% { opacity: 0; transform: translateY(-56px) scale(1.15); }
  }

  .pg-progress-block { margin-bottom: 1rem; }
  .pg-q-count {
    display: block; margin-bottom: .5rem;
    font-size: 1rem; font-weight: 800; color: #fff;
  }
  .pg-progress-track {
    height: .65rem; border-radius: 999px; background: rgba(255,255,255,.14); overflow: hidden;
    width: 100%;
  }
  .pg-progress-fill {
    height: 100%; width: 0; border-radius: inherit;
    background: linear-gradient(90deg, var(--pg-purple), var(--pg-blue), var(--pg-gold));
    transition: width .4s ease;
    box-shadow: 0 0 14px rgba(139,108,255,.55);
  }

  /* Large timer */
  .pg-timer-wrap { display: flex; justify-content: center; margin: .25rem 0 1.25rem; }
  .pg-timer-ring {
    --pg-timer-pct: 100;
    --pg-timer-color: #8b6cff;
    width: 8.5rem; height: 8.5rem; border-radius: 50%;
    display: grid; place-items: center;
    background: conic-gradient(var(--pg-timer-color) calc(var(--pg-timer-pct) * 1%), rgba(255,255,255,.12) 0);
    box-shadow: 0 0 36px rgba(139,108,255,.4), inset 0 0 20px rgba(0,0,0,.35);
  }
  .pg-timer-ring[data-state="warning"] {
    --pg-timer-color: #fb923c;
    box-shadow: 0 0 36px rgba(251,146,60,.45);
  }
  .pg-timer-ring[data-state="urgent"] {
    --pg-timer-color: #fb7185;
    box-shadow: 0 0 40px rgba(251,113,133,.55);
    animation: pg-timer-pulse .65s ease-in-out infinite;
  }
  .pg-timer-ring[data-state="up"] { --pg-timer-color: #94a3b8; }
  .pg-timer-inner {
    width: 6.7rem; height: 6.7rem; border-radius: 50%;
    background: radial-gradient(circle at 40% 30%, #243056, #0a1020 72%);
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    border: 2px solid rgba(255,255,255,.1);
  }
  .pg-timer-icon { font-size: 1rem; line-height: 1; color: #fff; }
  .pg-timer-value {
    font-size: 2.15rem; font-weight: 900; color: #fff;
    font-variant-numeric: tabular-nums; line-height: 1; margin: .1rem 0;
  }
  .pg-timer-unit {
    font-size: .65rem; font-weight: 800; letter-spacing: .14em; color: #e2e8f0;
  }
  .pg-timer-ring[data-state="urgent"] .pg-timer-value { color: #fecdd3; }
  @keyframes pg-timer-pulse {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.045); }
  }

  /* Stage — wide, not a floating LMS card */
  .pg-stage {
    position: relative;
    width: 100%;
    border-radius: 1.35rem;
    padding: clamp(1.15rem, 2vw, 1.75rem);
    background: rgba(15, 22, 42, 0.72);
    border: 1px solid rgba(255,255,255,.12);
    box-shadow: 0 18px 44px rgba(0,0,0,.35);
  }
  .pg-q-panel { margin-bottom: 1.35rem; text-align: center; }
  .pg-q-text {
    font-size: clamp(1.2rem, 2.4vw, 1.55rem); font-weight: 700;
    color: #fff !important; line-height: 1.5; margin: 0;
  }
  .pg-q-text * { color: inherit !important; }

  /* Answer grid */
  .pg-choices {
    display: grid; grid-template-columns: 1fr; gap: .85rem;
  }
  @media (min-width: 700px) {
    .pg-choices { grid-template-columns: 1fr 1fr; gap: 1rem; }
  }
  .pg-choice {
    display: flex; align-items: flex-start; gap: 1rem; width: 100%; text-align: left;
    border: 2px solid var(--pg-choice-border, rgba(255,255,255,.22));
    background: rgba(255,255,255,.07);
    color: #fff; border-radius: 1.1rem; padding: 1.15rem 1.15rem;
    font-size: 1.05rem; font-weight: 650; cursor: pointer; min-height: 5rem;
    transition: transform .12s ease, border-color .15s ease, background .15s ease, box-shadow .15s ease;
  }
  .pg-choice:nth-child(1) { --pg-choice-border: #8b6cff; --pg-choice-letter-bg: #7c5cff; }
  .pg-choice:nth-child(2) { --pg-choice-border: #4db3ef; --pg-choice-letter-bg: #1665A0; }
  .pg-choice:nth-child(3) { --pg-choice-border: #34d399; --pg-choice-letter-bg: #059669; }
  .pg-choice:nth-child(4) { --pg-choice-border: #fb923c; --pg-choice-letter-bg: #ea580c; }
  .pg-choice:hover:not(:disabled):not(.is-locked-out) {
    transform: translateY(-2px);
    background: rgba(255,255,255,.12);
    box-shadow: 0 10px 24px rgba(0,0,0,.28);
  }
  .pg-choice:disabled { cursor: default; }
  .pg-choice.is-selected {
    background: rgba(139,108,255,.28);
    border-color: #c4b5fd;
    box-shadow: 0 0 0 3px rgba(139,108,255,.4), 0 0 28px rgba(139,108,255,.3);
  }
  .pg-choice.is-correct {
    border-color: var(--pg-mint) !important;
    background: rgba(52,211,153,.22);
    box-shadow: 0 0 0 3px rgba(52,211,153,.35), 0 0 30px rgba(52,211,153,.28);
  }
  .pg-choice.is-wrong {
    border-color: var(--pg-rose) !important;
    background: rgba(251,113,133,.2);
  }
  .pg-choice.is-locked-out { opacity: .45; }
  .pg-choice-letter {
    flex-shrink: 0; width: 3rem; height: 3rem; border-radius: .85rem;
    display: inline-flex; align-items: center; justify-content: center;
    background: var(--pg-choice-letter-bg, var(--pg-purple)); color: #fff !important;
    font-weight: 900; font-size: 1.25rem;
    box-shadow: 0 4px 14px rgba(0,0,0,.35);
    border: 2px solid rgba(255,255,255,.35);
  }
  .pg-choice-text {
    flex: 1; padding-top: .35rem; line-height: 1.45;
    color: #fff !important; font-weight: 650; font-size: 1.05rem;
  }
  .pg-choice-text * { color: inherit !important; }

  .pg-lock-row {
    margin-top: 1.35rem; text-align: center;
    position: sticky; bottom: .75rem; z-index: 5;
  }
  .pg-lock-btn {
    display: inline-flex; align-items: center; justify-content: center; gap: .5rem;
    width: 100%; max-width: 420px; padding: 1.1rem 1.4rem; border: 0; border-radius: 999px;
    background: linear-gradient(90deg, #8b6cff, #1665A0); color: #fff;
    font-weight: 900; font-size: 1.1rem; cursor: pointer;
    box-shadow: 0 12px 30px rgba(139,108,255,.45);
  }
  .pg-lock-btn:hover:not(:disabled) { filter: brightness(1.08); transform: translateY(-1px); }
  .pg-lock-btn:disabled {
    opacity: .45; cursor: not-allowed; transform: none;
    background: #475569; box-shadow: none;
  }
  .pg-lock-btn.is-busy { opacity: .7; cursor: wait; }
  .pg-lock-hint { margin: .6rem 0 0; font-size: .85rem; font-weight: 650; color: #e2e8f0; }

  /* Reveal (brief flash before auto-advance) */
  .pg-reveal {
    margin-top: 1.15rem; text-align: center;
    padding: 1.15rem 1rem 1rem;
    border-radius: 1.15rem;
    animation: pg-reveal-in .28s ease;
    border: 1px solid transparent;
  }
  .pg-reveal-flash { animation: pg-reveal-in .28s ease, pg-reveal-pulse .9s ease; }
  @keyframes pg-reveal-in {
    from { opacity: 0; transform: translateY(8px) scale(.98); }
    to { opacity: 1; transform: translateY(0) scale(1); }
  }
  @keyframes pg-reveal-pulse {
    0% { box-shadow: 0 0 0 rgba(255,255,255,0); }
    40% { box-shadow: 0 0 28px rgba(139,108,255,.25); }
    100% { box-shadow: 0 0 0 rgba(255,255,255,0); }
  }
  .pg-reveal.is-correct {
    background: radial-gradient(circle at 50% 0%, rgba(52,211,153,.28), rgba(15,22,42,.95) 55%);
    border-color: rgba(52,211,153,.5);
  }
  .pg-reveal.is-wrong {
    background: radial-gradient(circle at 50% 0%, rgba(251,113,133,.25), rgba(15,22,42,.95) 55%);
    border-color: rgba(251,113,133,.45);
  }
  .pg-reveal.is-timeout {
    background: radial-gradient(circle at 50% 0%, rgba(251,146,60,.25), rgba(15,22,42,.95) 55%);
    border-color: rgba(251,146,60,.5);
  }
  .pg-reveal-icon {
    width: 3.75rem; height: 3.75rem; border-radius: 50%;
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 1.75rem; font-weight: 900; margin: 0 auto .7rem;
    background: rgba(255,255,255,.12); color: #fff;
  }
  .pg-reveal.is-correct .pg-reveal-icon {
    background: #059669; color: #fff; box-shadow: 0 0 30px rgba(52,211,153,.55);
  }
  .pg-reveal.is-wrong .pg-reveal-icon {
    background: #e11d48; color: #fff; box-shadow: 0 0 28px rgba(251,113,133,.5);
  }
  .pg-reveal.is-timeout .pg-reveal-icon {
    background: #ea580c; color: #fff; box-shadow: 0 0 28px rgba(251,146,60,.5);
  }
  .pg-reveal-title {
    margin: 0; font-size: clamp(1.5rem, 3vw, 1.85rem); font-weight: 900; color: #fff;
  }
  .pg-reveal-sub {
    margin: .4rem 0 0; font-size: 1.05rem; font-weight: 700; color: #e2e8f0;
  }
  .pg-reveal-metrics {
    display: flex; flex-wrap: wrap; gap: .55rem; justify-content: center; margin-top: .95rem;
  }
  .pg-chip {
    display: inline-flex; align-items: center; gap: .35rem;
    padding: .5rem .95rem; border-radius: 999px;
    font-size: .95rem; font-weight: 900; background: rgba(255,255,255,.1); color: #fff;
  }
  .pg-chip.points {
    background: rgba(251,191,36,.2); color: #fde68a; border: 1px solid rgba(251,191,36,.5);
  }
  .pg-chip.streak {
    background: rgba(251,146,60,.2); color: #fdba74; border: 1px solid rgba(251,146,60,.5);
  }
  .pg-chip.zero { color: #e2e8f0; }

  .pg-tip {
    margin: 1rem auto 0; max-width: 40rem; text-align: left;
    padding: 1rem 1.1rem; border-radius: .95rem;
    background: rgba(255,255,255,.06); border: 1px solid rgba(77,179,239,.45);
  }
  .pg-tip-label {
    margin: 0 0 .4rem; font-size: .75rem; font-weight: 800;
    text-transform: uppercase; letter-spacing: .08em; color: #7dd3fc;
  }
  .pg-tip-body { font-size: .95rem; color: #f1f5f9; line-height: 1.5; }
  .pg-tip-body * { color: inherit !important; }

  .pg-milestone {
    margin: .8rem auto 0; max-width: 24rem;
    padding: .7rem 1rem; border-radius: .8rem;
    background: linear-gradient(90deg, rgba(251,191,36,.3), rgba(139,108,255,.25));
    font-weight: 900; color: #fff; font-size: 1rem;
    animation: pg-milestone-pop .4s ease;
  }
  @keyframes pg-milestone-pop {
    0% { transform: scale(.9); opacity: 0; }
    60% { transform: scale(1.05); }
    100% { transform: scale(1); opacity: 1; }
  }

  .pg-confetti {
    position: absolute; inset: 0; pointer-events: none; overflow: hidden; border-radius: inherit;
  }
  .pg-confetti i {
    position: absolute; top: -8px; width: 7px; height: 10px; border-radius: 2px;
    opacity: .9; animation: pg-confetti-fall .9s ease-out forwards;
  }
  @keyframes pg-confetti-fall {
    to { transform: translateY(160px) rotate(220deg); opacity: 0; }
  }

  .pg-actions {
    margin-top: 1.25rem; display: flex; flex-wrap: wrap; gap: .7rem;
    justify-content: center; align-items: center;
  }
  .pg-theme .cpa-toolbar-btn {
    background: rgba(255,255,255,.08) !important;
    border: 1px solid rgba(255,255,255,.2) !important;
    color: #fff !important;
  }
  .pg-theme .cpa-toolbar-btn.is-active,
  .pg-theme .cpa-toolbar-btn:hover {
    border-color: rgba(139,108,255,.55) !important;
    color: #fff !important;
  }
  .pg-next-btn {
    display: inline-flex; align-items: center; justify-content: center; gap: .4rem;
    padding: 1rem 1.6rem; border: 0; border-radius: 999px;
    background: linear-gradient(90deg, #8b6cff, #1665A0); color: #fff;
    font-weight: 900; font-size: 1rem; cursor: pointer;
    box-shadow: 0 8px 22px rgba(139,108,255,.4);
  }
  .pg-next-btn:hover { filter: brightness(1.08); }

  .pg-exit { text-align: center; margin: 1.25rem 0 0; font-size: .875rem; }
  .pg-exit a { font-weight: 700; color: #e2e8f0; text-decoration: none; }
  .pg-exit a:hover { color: #fff; text-decoration: underline; }

  /* —— RESULTS DASHBOARD (true full-width) —— */
  .pg-result-page { max-width: none !important; width: 100%; }
  .pg-result-shell {
    width: 100%;
    max-width: none;
    margin: 0;
    display: flex;
    flex-direction: column;
    gap: 1.15rem;
  }
  .pg-result-topbar {
    display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between;
    gap: .75rem 1rem;
  }
  .pg-back-link {
    font-weight: 700; color: #e2e8f0; text-decoration: none; font-size: .9rem;
  }
  .pg-back-link:hover { color: #fff; text-decoration: underline; }
  .pg-result-top-actions {
    display: flex; flex-wrap: wrap; gap: .55rem; align-items: center;
  }
  .pg-btn-inline {
    width: auto !important; max-width: none !important;
    padding: .7rem 1.1rem !important; font-size: .9rem !important;
  }

  .pg-result-hero {
    display: grid;
    grid-template-columns: 1fr;
    gap: 1.25rem 1.75rem;
    align-items: center;
    border-radius: 1.35rem;
    padding: 1.75rem 1.5rem 1.6rem;
    background:
      radial-gradient(640px 240px at 20% 0%, rgba(251,191,36,.22), transparent 55%),
      linear-gradient(135deg, #1a2748 0%, #10182e 55%, #0f1a33 100%);
    border: 1px solid rgba(251,191,36,.35);
    box-shadow: 0 16px 40px rgba(0,0,0,.35);
  }
  @media (min-width: 960px) {
    .pg-result-hero {
      grid-template-columns: minmax(280px, 1fr) minmax(320px, 1.2fr);
      text-align: left;
      padding: 2rem 2rem 1.85rem;
    }
    .pg-result-hero-main { text-align: left; }
  }
  .pg-result-hero-main { text-align: center; }
  .pg-result-trophy { font-size: 2.75rem; line-height: 1; margin-bottom: .25rem; }
  .pg-result-kicker {
    margin: 0; font-size: .75rem; font-weight: 800; letter-spacing: .14em;
    text-transform: uppercase; color: #e2e8f0;
  }
  .pg-result-headline {
    margin: .35rem 0 0; font-size: clamp(1.65rem, 2.8vw, 2.1rem); font-weight: 900; color: #fff;
  }
  .pg-result-score {
    font-size: clamp(2.5rem, 4.5vw, 3.35rem); font-weight: 900; color: #fff; line-height: 1; margin-top: .65rem;
  }
  .pg-result-acc {
    margin: .35rem 0 0; font-size: 1.25rem; font-weight: 800; color: #7dd3fc;
  }
  .pg-result-points {
    margin: .7rem 0 0; font-size: 1.4rem; font-weight: 900; color: var(--pg-gold);
  }
  .pg-result-rank {
    display: inline-flex; margin-top: .45rem; padding: .35rem .85rem; border-radius: 999px;
    background: rgba(139,108,255,.25); border: 1px solid rgba(139,108,255,.5);
    font-weight: 900; font-size: .9rem; color: #e9d5ff;
  }
  .pg-result-grid {
    display: grid; grid-template-columns: repeat(2, 1fr); gap: .7rem;
  }
  @media (min-width: 700px) {
    .pg-result-grid { grid-template-columns: repeat(4, 1fr); }
  }
  .pg-result-tile {
    padding: 1rem .75rem; border-radius: .95rem; text-align: center;
    background: rgba(255,255,255,.08); border: 1px solid rgba(255,255,255,.14);
  }
  .pg-result-tile span {
    display: block; font-size: .7rem; font-weight: 800; text-transform: uppercase;
    letter-spacing: .06em; color: #e2e8f0; margin-bottom: .3rem;
  }
  .pg-result-tile strong { font-size: 1.3rem; font-weight: 900; color: #fff; }

  .pg-result-body {
    display: grid;
    grid-template-columns: 1fr;
    gap: 1.15rem;
    width: 100%;
  }
  @media (min-width: 1024px) {
    .pg-result-body {
      grid-template-columns: minmax(0, 1.65fr) minmax(280px, .9fr);
      align-items: start;
    }
  }
  .pg-result-aside { display: grid; gap: 1.15rem; }

  .pg-panel-card {
    border-radius: 1.15rem; padding: 1.25rem 1.4rem; margin-bottom: 0;
    background: var(--pg-panel); border: 1px solid rgba(255,255,255,.1);
    width: 100%;
    box-sizing: border-box;
  }
  .pg-panel-card h2 {
    margin: 0 0 .95rem; font-size: .78rem; font-weight: 800; letter-spacing: .12em;
    text-transform: uppercase; color: #e2e8f0;
  }
  .pg-empty-note { margin: 0; color: #e2e8f0; font-size: .9rem; }
  .pg-subj-grid { display: grid; gap: .55rem; }
  .pg-subj-row {
    display: grid; grid-template-columns: minmax(4.5rem, 7rem) 1fr auto; align-items: center;
    gap: .75rem 1rem; padding: .55rem .65rem; border-radius: .75rem;
    background: rgba(255,255,255,.04); border: 1px solid rgba(255,255,255,.06);
  }
  .pg-subj-row.is-weak-row { border-color: rgba(251,146,60,.35); background: rgba(251,146,60,.08); }
  .pg-subj-name { font-weight: 800; color: #fff; font-size: .95rem; }
  .pg-subj-bar {
    height: .6rem; border-radius: 999px; background: rgba(255,255,255,.14); overflow: hidden;
  }
  .pg-subj-bar > i {
    display: block; height: 100%; border-radius: inherit;
    background: linear-gradient(90deg, var(--pg-purple), var(--pg-blue));
  }
  .pg-subj-bar.is-weak > i { background: linear-gradient(90deg, var(--pg-rose), var(--pg-orange)); }
  .pg-subj-meta { font-size: .875rem; font-weight: 700; color: #e2e8f0; white-space: nowrap; }

  .pg-weak-card {
    padding: 1.15rem 1.2rem; border-radius: .95rem;
    background: rgba(251,146,60,.12); border: 1px solid rgba(251,146,60,.45);
  }
  .pg-weak-card--ok {
    background: rgba(52,211,153,.1); border-color: rgba(52,211,153,.4);
  }
  .pg-weak-label { margin: 0; color: #fdba74; font-weight: 800; font-size: .85rem; text-transform: uppercase; letter-spacing: .06em; }
  .pg-weak-card--ok .pg-weak-label { color: #6ee7b7; }
  .pg-weak-value { margin: .4rem 0 0; font-size: 1.25rem; font-weight: 900; color: #fff; }
  .pg-weak-note { margin: .4rem 0 0; font-size: .875rem; color: #e2e8f0; font-weight: 600; }
  .pg-weak-card a {
    display: inline-flex; margin-top: .75rem; font-weight: 800; color: #fff; text-decoration: none;
    padding: .55rem 1rem; border-radius: .65rem; background: rgba(251,146,60,.4);
  }

  .pg-result-actions {
    display: flex; flex-direction: column; gap: .6rem; margin-top: .15rem;
  }
  .pg-result-actions .pg-start-btn,
  .pg-result-actions .cpa-toolbar-btn {
    width: 100%; max-width: none; justify-content: center;
  }

  .pg-wrong-section { width: 100%; }
  .pg-wrong-head { margin-bottom: .85rem; }
  .pg-wrong-head h2 { margin-bottom: .35rem; }
  .pg-wrong-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: .75rem;
  }
  @media (min-width: 900px) {
    .pg-wrong-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  }
  @media (min-width: 1280px) {
    .pg-wrong-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
  }
  .pg-wrong-item {
    border-radius: .9rem; padding: 1rem 1.1rem;
    background: rgba(251,113,133,.1); border: 1px solid rgba(251,113,133,.3);
    display: flex; flex-direction: column; gap: .45rem;
  }
  .pg-wrong-meta { font-size: .75rem; font-weight: 800; color: #fda4af; }
  .pg-wrong-q { margin: 0; font-size: .9rem; color: #f1f5f9; line-height: 1.4; }
  .pg-wrong-ans { font-size: .8rem; color: #e2e8f0; font-weight: 600; }

  @media (max-width: 699px) {
    body.app-shell--student.pg-theme .student-dashboard-page.pg-page { padding: 1rem .75rem 2.5rem !important; }
    .pg-hud { grid-template-columns: 1fr; }
    .pg-hud-right { justify-self: start; }
    .pg-timer-ring { width: 7.25rem; height: 7.25rem; }
    .pg-timer-inner { width: 5.7rem; height: 5.7rem; }
    .pg-timer-value { font-size: 1.75rem; }
    .pg-choice { min-height: 4.25rem; padding: 1rem; }
    .pg-choice-letter { width: 2.65rem; height: 2.65rem; font-size: 1.1rem; }
    .pg-subj-row { grid-template-columns: 1fr; gap: .35rem; }
    .pg-result-top-actions { width: 100%; }
    .pg-result-top-actions .pg-start-btn,
    .pg-result-top-actions .cpa-toolbar-btn { flex: 1 1 auto; justify-content: center; }
    .pg-battle-layout { grid-template-columns: 1fr; }
  }

  /* —— CPA Battle —— */
  .pg-battle-panel {
    border-radius: 1.05rem; padding: 1.1rem 1.2rem; margin-bottom: .85rem;
    background: var(--pg-panel-2); border: 1px solid rgba(139,108,255,.35);
  }
  .pg-battle-panel h2 { margin: 0 0 .5rem; font-size: 1.15rem; font-weight: 900; color: #fff; }
  .pg-battle-actions {
    display: grid; grid-template-columns: 1fr; gap: .75rem; margin-bottom: .85rem;
  }
  @media (min-width: 720px) {
    .pg-battle-actions { grid-template-columns: 1fr 1fr; }
  }
  .pg-battle-cta {
    text-align: left; border-radius: 1rem; padding: 1.15rem 1.2rem; cursor: pointer;
    border: 1px solid rgba(255,255,255,.16); background: var(--pg-panel); color: #fff;
  }
  .pg-battle-cta .ico { font-size: 1.4rem; display: block; margin-bottom: .35rem; }
  .pg-battle-cta strong { display: block; font-size: 1.1rem; font-weight: 900; }
  .pg-battle-cta span:last-child { display: block; margin-top: .25rem; font-size: .85rem; color: #e2e8f0; }
  .pg-battle-cta-create { border-color: rgba(251,191,36,.45); }
  .pg-battle-cta-join { border-color: rgba(77,179,239,.45); }
  .pg-battle-cta:hover { filter: brightness(1.08); }
  .pg-battle-nick-row { display: flex; flex-wrap: wrap; gap: .65rem; align-items: center; }
  .pg-battle-nick-row input,
  .pg-battle-text {
    flex: 1 1 220px; border-radius: .7rem; border: 1px solid rgba(255,255,255,.2);
    background: #070b16; color: #fff; padding: .7rem .85rem; font-weight: 700; font-size: 1rem;
  }
  .pg-battle-code-input {
    text-transform: uppercase; letter-spacing: .18em; font-size: 1.35rem; text-align: center;
  }
  .pg-battle-nick-line { color: #e2e8f0; font-weight: 600; margin: 0 0 1rem; }
  .pg-battle-subjects { display: flex; flex-wrap: wrap; gap: .4rem; }
  .pg-battle-lobby-hero { text-align: center; margin-bottom: 1rem; }
  .pg-battle-lobby-hero h1 { margin: .25rem 0; font-size: 1.5rem; font-weight: 900; color: #fff; }
  .pg-battle-room-code {
    display: inline-block; margin: .65rem 0; padding: .65rem 1.25rem;
    border-radius: .9rem; font-size: clamp(1.8rem, 4vw, 2.4rem); font-weight: 900;
    letter-spacing: .2em; color: #fff;
    background: rgba(139,108,255,.25); border: 2px solid rgba(196,181,253,.55);
  }
  .pg-battle-invite { display: flex; flex-wrap: wrap; gap: .5rem; justify-content: center; margin-bottom: 1rem; }
  .pg-battle-lobby-head {
    display: flex; justify-content: space-between; align-items: center; margin-bottom: .65rem;
  }
  .pg-battle-lobby-head span { font-weight: 800; color: #e2e8f0; }
  .pg-battle-player-list { list-style: none; margin: 0; padding: 0; display: grid; gap: .45rem; }
  .pg-battle-player-row {
    display: flex; flex-wrap: wrap; align-items: center; gap: .55rem;
    padding: .65rem .8rem; border-radius: .75rem;
    background: rgba(255,255,255,.05); border: 1px solid rgba(255,255,255,.1);
  }
  .pg-battle-player-row .av {
    width: 2rem; height: 2rem; border-radius: .55rem;
    background: linear-gradient(135deg, #8b6cff, #1665A0);
  }
  .pg-battle-player-row .nick { flex: 1; font-weight: 800; color: #fff; }
  .pg-battle-player-row .st { font-size: .72rem; font-weight: 800; letter-spacing: .06em; color: #cbd5e1; }
  .pg-battle-player-row.is-ready .st { color: #6ee7b7; }
  .pg-battle-kick {
    border: 1px solid rgba(251,113,133,.4); background: rgba(251,113,133,.12);
    color: #fecdd3; border-radius: .5rem; padding: .3rem .55rem; font-size: .72rem; font-weight: 800; cursor: pointer;
  }
  .pg-battle-lobby-actions {
    display: flex; flex-wrap: wrap; gap: .55rem; margin-top: 1rem; justify-content: center;
  }
  .pg-battle-layout {
    display: grid; grid-template-columns: minmax(0, 1fr) 240px; gap: 1rem; align-items: start;
  }
  .pg-battle-board {
    border-radius: 1rem; padding: .9rem; background: rgba(18,26,51,.95);
    border: 1px solid rgba(255,255,255,.12); position: sticky; top: .75rem;
  }
  .pg-battle-board h2 { margin: 0 0 .65rem; font-size: .78rem; letter-spacing: .1em; text-transform: uppercase; color: #e2e8f0; }
  .pg-battle-rank-list { list-style: none; margin: 0; padding: 0; display: grid; gap: .4rem; }
  .pg-battle-rank-list li {
    display: grid; grid-template-columns: 2rem 1fr auto; gap: .4rem; align-items: center;
    padding: .45rem .5rem; border-radius: .55rem; background: rgba(255,255,255,.05);
    font-weight: 800; color: #fff; font-size: .85rem;
  }
  .pg-battle-rank-list .rank { color: #fde68a; }
  .pg-battle-rank-list .pts { color: #c4b5fd; font-variant-numeric: tabular-nums; }
  .pg-battle-countdown {
    text-align: center; padding: 2rem 1rem; margin-bottom: 1rem;
    border-radius: 1.15rem; background: rgba(139,108,255,.18); border: 1px solid rgba(196,181,253,.4);
  }
  .pg-battle-countdown-num {
    font-size: clamp(3.5rem, 10vw, 5.5rem); font-weight: 900; color: #fff; line-height: 1;
  }
  .pg-battle-locked {
    text-align: center; margin-top: 1rem; padding: 1.1rem;
    border-radius: .9rem; background: rgba(52,211,153,.12); border: 1px solid rgba(52,211,153,.4);
    color: #fff; font-weight: 800;
  }
  .pg-battle-locked p { margin: .35rem 0 0; color: #e2e8f0; font-weight: 600; }
  .pg-battle-final-board li { font-size: .95rem; padding: .55rem .65rem; }
  .pg-wrong-list { list-style: none; margin: 0; padding: 0; display: grid; gap: .65rem; }
  .pg-wrong-preview { color: #f1f5f9; font-size: .9rem; line-height: 1.4; }
  .pg-perf-list { list-style: none; margin: 0; padding: 0; display: grid; gap: .4rem; }
  .pg-perf-list li {
    display: flex; justify-content: space-between; padding: .5rem .65rem;
    border-radius: .55rem; background: rgba(255,255,255,.05); color: #fff; font-weight: 700;
  }
</style>
