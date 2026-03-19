/* Exam UI – professional exam-style layout (shared with quiz take page) */
:root {
  /* Aligned with site theme blues (#1665A0 / #143D59) */
  --exam-primary: #1665A0;
  --exam-primary-light: #e8f2fa;
  --exam-success: #059669;
  --exam-warning: #d97706;
  --exam-danger: #dc2626;
  --exam-surface: #ffffff;
  --exam-bg: #f6f9ff;
  --exam-text: #143D59;
  --exam-muted: #64748b;
  --exam-border: rgba(22, 101, 160, 0.16);
  --exam-radius: 14px;
  --exam-radius-sm: 10px;
  --exam-shadow: 0 1px 3px rgba(0,0,0,0.06);
  --exam-shadow-lg: 0 10px 25px -5px rgba(0,0,0,0.08);
  --exam-space: 1rem;
  --exam-space-lg: 1.5rem;
  --exam-space-xl: 2rem;
}
/* Sticky exam header – clear hierarchy, balanced spacing */
.exam-bar {
  position: sticky;
  top: 0;
  z-index: 100;
  background: var(--exam-surface);
  box-shadow: 0 2px 12px rgba(0,0,0,0.06);
  border-bottom: 1px solid var(--exam-border);
}
.exam-header {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: var(--exam-space-lg);
  padding: 1rem 1.75rem;
}
.exam-header-left {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
}
.exam-title {
  font-size: 1.125rem;
  font-weight: 700;
  color: var(--exam-text);
  letter-spacing: -0.02em;
  line-height: 1.3;
}
.exam-subject {
  font-size: 0.8125rem;
  color: var(--exam-muted);
  font-weight: 500;
}
.exam-q-badge {
  font-size: 0.8125rem;
  font-weight: 600;
  color: var(--exam-muted);
  padding: 0.4rem 0.75rem;
  background: var(--exam-bg);
  border-radius: var(--exam-radius-sm);
}
.exam-q-badge strong { color: var(--exam-text); }
.exam-progress-wrap { padding: 0 1.75rem 1rem; }
.exam-progress-bar {
  height: 8px;
  background: var(--exam-border);
  border-radius: 9999px;
  overflow: hidden;
  transition: width 0.4s ease;
}
.exam-progress-fill {
  transition: width 0.4s ease, box-shadow 0.25s ease;
  height: 100%;
  background: linear-gradient(90deg, var(--exam-primary), #3393FF);
  border-radius: 9999px;
}
.exam-progress-wrap:hover .exam-progress-fill {
  box-shadow:
    0 0 0 1px rgba(51, 147, 255, 0.55),
    0 0 20px rgba(51, 147, 255, 0.6);
}
.exam-progress-label { font-size: 0.8125rem; font-weight: 600; color: var(--exam-muted); margin-top: 0.5rem; }
.exam-question-card {
  background: var(--exam-surface);
  border-radius: var(--exam-radius);
  border: 1px solid var(--exam-border);
  box-shadow: 0 2px 12px rgba(0,0,0,0.06), 0 1px 3px rgba(0,0,0,0.04);
  padding: var(--exam-space-xl) 2.25rem;
  margin-bottom: 1.5rem;
  transition: box-shadow 0.25s ease, border-color 0.2s ease, transform 0.18s ease;
  scroll-margin-top: 1.5rem;
}
html { scroll-behavior: smooth; }
.exam-question-card:focus-within {
  box-shadow: 0 8px 24px rgba(65, 84, 241, 0.12), 0 2px 8px rgba(0,0,0,0.06);
  border-color: rgba(65, 84, 241, 0.25);
}
.exam-question-label {
  font-size: 0.6875rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.1em;
  color: var(--exam-muted);
  margin-bottom: 0.75rem;
}
.exam-question-text { font-size: 1.1875rem; line-height: 1.65; color: var(--exam-text); font-weight: 500; margin-bottom: 1.5rem; }
.exam-choices { display: flex; flex-direction: column; gap: 1rem; }
.exam-choice {
  display: flex;
  align-items: center;
  gap: 1.125rem;
  min-height: 3.5rem;
  padding: 1.125rem 1.5rem;
  border-radius: var(--exam-radius);
  border: 2px solid var(--exam-border);
  background: var(--exam-surface);
  cursor: pointer;
  transition: border-color 0.2s, background-color 0.2s, box-shadow 0.2s;
}
.exam-choice:hover {
  border-color: rgba(51, 147, 255, 0.55);
  background: #e8f2fa;
  box-shadow:
    0 0 0 1px rgba(51, 147, 255, 0.55),
    0 8px 20px rgba(51, 147, 255, 0.45);
}
.exam-choice.selected {
  border-color: var(--exam-success);
  background: #f0fdf4;
  box-shadow:
    0 0 0 1px rgba(34, 197, 94, 0.45),
    0 6px 18px rgba(22, 163, 74, 0.35);
}
.exam-choice-letter {
  flex-shrink: 0;
  width: 2.25rem;
  height: 2.25rem;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 50%;
  font-weight: 700;
  font-size: 0.9375rem;
  background: #d4e8f7;
  color: var(--exam-text);
  transition: background 0.2s, color 0.2s;
}
.exam-choice.selected .exam-choice-letter { background: var(--exam-primary); color: white; }
.exam-choice-text { font-size: 1rem; color: var(--exam-text); line-height: 1.55; flex: 1; }
.exam-choice input { position: absolute; opacity: 0; pointer-events: none; }
.exam-nav-card {
  background: var(--exam-surface);
  border-radius: var(--exam-radius);
  border: 1px solid var(--exam-border);
  padding: 1.5rem 1.75rem;
  margin-top: 1.5rem;
  margin-bottom: 1rem;
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: center;
  gap: var(--exam-space-lg);
  box-shadow: 0 2px 12px rgba(0,0,0,0.06), 0 1px 3px rgba(0,0,0,0.04);
}
.exam-btn-submit {
  min-height: 3rem;
  padding: 0.75rem 1.75rem;
  border-radius: var(--exam-radius-sm);
  font-weight: 700;
  font-size: 1rem;
  background: var(--exam-primary);
  color: white;
  border: none;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 0.5rem;
  transition: background 0.2s ease, transform 0.2s ease, opacity 0.2s ease, box-shadow 0.2s ease;
}
.exam-btn-submit:hover:not(:disabled) {
  background: #2563eb;
  transform: translateY(-2px);
  box-shadow:
    0 0 0 1px rgba(51, 147, 255, 0.7),
    0 12px 30px rgba(51, 147, 255, 0.65);
}
.exam-btn-submit:active:not(:disabled) { transform: scale(0.98); }
.exam-btn-submit:disabled { background: #cbd5e1; color: #94a3b8; cursor: not-allowed; opacity: 0.9; }
.sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0,0,0,0); white-space: nowrap; border: 0; }
.exam-page-container { width: 100%; max-width: 100%; margin-left: auto; margin-right: auto; padding-left: 0; padding-right: 0; }
.exam-page-container-result { width: 100%; padding-top: 1.75rem; padding-bottom: 2.5rem; margin-left: auto; margin-right: auto; }
.exam-result-inner { max-width: 100%; margin-left: auto; margin-right: auto; }
.result-card {
  border-width: 1px;
  border-radius: 18px;
  padding: 1.25rem 1.75rem 1.35rem;
  width: 100%;
  position: relative;
  overflow: hidden;
  background: linear-gradient(to bottom right, #d4e8f7, #e8f2fa);
  border-color: rgba(22, 101, 160, 0.18);
  box-shadow:
    0 2px 8px rgba(20, 61, 89, 0.12),
    0 6px 18px rgba(20, 61, 89, 0.08);
  border-left: 4px solid #1665A0;
}
.result-card::before {
  content: "";
  position: absolute;
  inset: -30%;
  background:
    radial-gradient(circle at 0 0, rgba(255,255,255,0.55), transparent 55%),
    radial-gradient(circle at 100% 0, rgba(148, 197, 255,0.3), transparent 60%);
  opacity: 0.5;
  z-index: -1;
}
.result-card-inner { position: relative; z-index: 1; border-radius: inherit; padding: 0.25rem 0 0; }
.result-card.result-pass { border-left-color: #059669; }
.result-card.result-pass .result-score { color: #047857; }
.result-card.result-pass .result-badge { background: #059669; color: white; }
.result-card.result-fail { border-left-color: #dc2626; }
.result-card.result-fail .result-score { color: #b91c1c; }
.result-card.result-fail .result-badge { background: #dc2626; color: white; }
.result-badge {
  display: inline-block;
  padding: 0.25rem 0.8rem;
  border-radius: 9999px;
  font-size: 0.75rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  margin-bottom: 0.5rem;
  background: rgba(255,255,255,0.9);
}
.result-stats-row { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 0.75rem; margin-top: 0.35rem; margin-bottom: 0.35rem; }
.result-stat-card {
  border-radius: 9999px;
  padding: 0.5rem 0.75rem;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0.4rem;
  font-size: 0.8rem;
  font-weight: 600;
  background: rgba(255,255,255,0.78);
  color: #0f172a;
  box-shadow: 0 1px 4px rgba(15,23,42,0.08);
}
.result-stat-correct i { color: #16a34a; }
.result-stat-wrong i { color: #dc2626; }
.result-stat-total i { color: #0ea5e9; }
@media (max-width: 640px) { .result-stats-row { grid-template-columns: 1fr; } }
.result-actions-bar { display: flex; flex-wrap: wrap; justify-content: center; gap: 0.5rem; margin-bottom: 0; }
.result-actions-primary {
  background: #4154f1;
  border: 1px solid #4154f1;
  color: #ffffff;
  font-size: 0.875rem;
  padding: 0.55rem 1.1rem;
  border-radius: 9999px;
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  text-decoration: none;
  transition: background-color 0.18s ease, color 0.18s ease, box-shadow 0.22s ease, transform 0.16s ease;
}
.result-actions-primary:hover {
  background: #2563eb;
  box-shadow:
    0 0 0 1px rgba(51,147,255,0.75),
    0 10px 26px rgba(51,147,255,0.65);
  transform: translateY(-1px);
}

/* Review styles (match quiz review) */
.review-item-correct { background: #f0fdf4 !important; border-color: #059669 !important; padding: 1.25rem !important; }
.review-item-wrong { background: #fef2f2 !important; border-color: #dc2626 !important; padding: 1.25rem !important; }
.review-correct-choice { background: #ecfdf5 !important; border: 2px solid #059669 !important; box-shadow: 0 0 0 2px rgba(5, 150, 105, 0.25); }
.review-correct-choice .review-choice-letter { background: #059669 !important; color: white !important; }
.review-correct-choice .review-correct-label { color: #047857; font-weight: 700; }
.exam-layout { display: flex; flex-wrap: wrap; gap: 2rem; align-items: flex-start; width: 100%; }
.exam-main { flex: 1; min-width: 0; }
.exam-sidebar { flex: 0 0 280px; position: sticky; top: 1.25rem; }
.exam-sidebar-card {
  background: var(--exam-surface);
  border-radius: var(--exam-radius);
  border: 1px solid var(--exam-border);
  box-shadow: 0 2px 12px rgba(0,0,0,0.06), 0 1px 3px rgba(0,0,0,0.04);
  overflow: hidden;
}
.exam-sidebar-title { font-size: 0.875rem; font-weight: 700; color: var(--exam-text); padding: 1.125rem 1.25rem; border-bottom: 1px solid var(--exam-border); }
.exam-timer-circle-wrap {
  width: 140px; height: 140px;
  margin: 1.25rem auto;
  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
  box-shadow:
    0 0 0 1px rgba(51, 147, 255, 0.35),
    0 14px 32px rgba(51, 147, 255, 0.5);
  border-radius: 9999px;
  transition: box-shadow 0.25s ease, transform 0.18s ease;
}
.exam-timer-circle-wrap svg { position: absolute; inset: 0; width: 100%; height: 100%; transform: rotate(-90deg); }
.exam-timer-circle-track { fill: none; stroke: var(--exam-border); stroke-width: 10; }
.exam-timer-circle-progress { fill: none; stroke-width: 10; stroke-linecap: round; stroke: #059669; transition: stroke-dashoffset 0.8s ease-out, stroke 0.3s ease; }
.exam-timer-circle-wrap.warning .exam-timer-circle-progress { stroke: #d97706; }
.exam-timer-circle-wrap.danger .exam-timer-circle-progress { stroke: #dc2626; }
.exam-timer-circle-inner { position: relative; z-index: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; font-variant-numeric: tabular-nums; }
.exam-timer-circle-value { font-size: 1.5rem; font-weight: 800; color: #047857; line-height: 1.2; transition: color 0.3s ease; }
.exam-timer-circle-wrap.warning .exam-timer-circle-value { color: #b45309; }
.exam-timer-circle-wrap.danger .exam-timer-circle-value { color: #b91c1c; }
.exam-timer-circle-wrap.danger .exam-timer-circle-inner { animation: exam-pulse 1s ease-in-out infinite; }
@keyframes exam-pulse { 50% { opacity: 0.92; } }
.exam-timer-circle-label { font-size: 0.65rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: var(--exam-muted); margin-top: 0.25rem; }
.exam-sidebar-section { border-bottom: 1px solid var(--exam-border); }
.exam-sidebar-section:last-child { border-bottom: none; }
.exam-sidebar-section-head { display:flex; align-items:center; justify-content:space-between; width:100%; padding: 1rem 1.25rem; background:transparent; border:none; font-size:0.875rem; font-weight:700; color:var(--exam-text); cursor:pointer; transition: background 0.15s; text-align:left; }
.exam-sidebar-section-head:hover { background:#f1f5ff; box-shadow: 0 0 0 1px rgba(51, 147, 255, 0.55), 0 8px 22px rgba(51, 147, 255, 0.5); }
.exam-sidebar-section-head i.bi-chevron-up { transition: transform 0.2s ease; }
.exam-sidebar-section.collapsed .exam-sidebar-section-head i.bi-chevron-up { transform: rotate(180deg); }
.exam-sidebar-section.collapsed .exam-q-list { display: none; }
.exam-q-list { padding: 0.5rem 0.75rem 1.25rem; max-height: 280px; overflow-y: auto; }
.exam-q-list a { display:flex; align-items:center; gap:0.625rem; padding: 0.65rem 0.875rem; border-radius: var(--exam-radius-sm); text-decoration:none; font-size:0.875rem; color: var(--exam-text); transition: background 0.2s, transform 0.15s ease, box-shadow 0.2s; }
.exam-q-list a:hover { background:#eff6ff; transform: translateX(2px); box-shadow: 0 0 0 1px rgba(51, 147, 255, 0.6), 0 6px 18px rgba(51, 147, 255, 0.55); }
.exam-q-list a .q-num { flex-shrink:0; width:1.5rem; height:1.5rem; display:flex; align-items:center; justify-content:center; border-radius:50%; background: var(--exam-border); color: var(--exam-muted); font-size:0.75rem; font-weight:700; transition: background 0.2s, color 0.2s; }
.exam-q-list a.answered .q-num { background:#059669; color:white; }
.exam-q-list a .q-check { margin-left:auto; color:#059669; font-size:0.875rem; transition: transform 0.2s; }
.exam-choice .exam-choice-check { margin-left:auto; flex-shrink:0; width:1.5rem; height:1.5rem; border-radius:50%; background: var(--exam-success); color:white; display:flex; align-items:center; justify-content:center; font-size:0.75rem; opacity:0; transition: opacity 0.2s; }
.exam-choice.selected .exam-choice-check { opacity: 1; }
.exam-saved-toast { position: fixed; bottom: 1.75rem; left: 50%; transform: translateX(-50%) translateY(100px); padding: 0.625rem 1.25rem; background: #059669; color: white; border-radius: 9999px; font-size: 0.875rem; font-weight: 600; box-shadow: 0 4px 20px rgba(5, 150, 105, 0.4); opacity: 0; transition: transform 0.3s ease, opacity 0.3s ease; z-index: 999; }
.exam-saved-toast.show { transform: translateX(-50%) translateY(0); opacity: 1; }
.exam-time-warning-toast { position: fixed; bottom: 1.75rem; left: 50%; transform: translateX(-50%) translateY(100px); padding: 0.75rem 1.5rem; border-radius: var(--exam-radius); font-size: 0.9375rem; font-weight: 600; box-shadow: 0 8px 24px rgba(0,0,0,0.15); opacity: 0; transition: transform 0.3s ease, opacity 0.3s ease; z-index: 1000; }
.exam-time-warning-toast.show { transform: translateX(-50%) translateY(0); opacity: 1; }
.exam-time-warning-toast.warning { background: #fef3c7; color: #92400e; border: 1px solid #f59e0b; }
.exam-time-warning-toast.danger { background: #fef2f2; color: #b91c1c; border: 1px solid #dc2626; }
/* Exam content protection */
.exam-protected { -webkit-user-select: none; -moz-user-select: none; -ms-user-select: none; user-select: none; }
.exam-protected ::selection { background: transparent; }
.exam-protected * { -webkit-user-drag: none; }

/* Global submit loading overlay (same as quiz) */
.quiz-submit-overlay {
  position: fixed;
  inset: 0;
  z-index: 1400;
  display: flex;
  align-items: center;
  justify-content: center;
  background: radial-gradient(circle at top, rgba(51,147,255,0.18), transparent 55%),
              rgba(15,23,42,0.78);
  backdrop-filter: blur(6px);
  opacity: 0;
  pointer-events: none;
  transition: opacity 0.25s ease;
}
.quiz-submit-overlay.show { opacity: 1; pointer-events: auto; }
.quiz-submit-card {
  background: linear-gradient(135deg, #0f172a 0%, #020617 55%, #020617 100%);
  border-radius: 1rem;
  padding: 1.75rem 2rem;
  max-width: 380px;
  width: 100%;
  text-align: center;
  box-shadow: 0 25px 60px rgba(0,0,0,0.65);
  border: 1px solid rgba(148,163,184,0.35);
  color: #e5e7eb;
}
.quiz-submit-spinner {
  width: 3rem;
  height: 3rem;
  border-radius: 9999px;
  border-width: 3px;
  border-style: solid;
  border-color: #3393ff transparent #60a5fa transparent;
  margin: 0 auto 1.25rem;
  animation: quiz-spin 0.9s linear infinite;
  box-shadow:
    0 0 0 1px rgba(51,147,255,0.55),
    0 0 30px rgba(51,147,255,0.85);
}
@keyframes quiz-spin { to { transform: rotate(360deg); } }
.quiz-submit-title { font-size: 1.1rem; font-weight: 700; margin-bottom: 0.25rem; color: #f9fafb; }
.quiz-submit-text { font-size: 0.9rem; color: #cbd5f5; }

/* Leave confirmation modal (same as quiz) */
.quiz-confirm-overlay {
  position: fixed; inset: 0; z-index: 1200; display: flex; align-items: center; justify-content: center; padding: 1rem;
  background: rgba(0, 0, 0, 0.6); backdrop-filter: blur(6px);
  opacity: 0; visibility: hidden; transition: opacity 0.2s ease, visibility 0.2s ease;
}
.quiz-confirm-overlay.show { opacity: 1; visibility: visible; }
.quiz-confirm-card {
  background: linear-gradient(135deg, #1e293b 0%, #0f172a 50%, #0c0a14 100%);
  border-radius: 1rem; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5);
  max-width: 400px; width: 100%; overflow: hidden; padding: 1.75rem 1.5rem; text-align: center;
  transform: scale(0.95); transition: transform 0.2s ease;
  border: 1px solid rgba(255,255,255,0.08);
}
.quiz-confirm-overlay.show .quiz-confirm-card { transform: scale(1); }
.quiz-confirm-icon-wrap {
  width: 4rem; height: 4rem; margin: 0 auto 1.25rem; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  box-shadow: 0 0 0 4px rgba(255,255,255,0.1), inset 0 0 20px rgba(255,255,255,0.05);
}
.quiz-confirm-icon-wrap.warning { background: rgba(245, 158, 11, 0.25); color: #fcd34d; }
.quiz-confirm-icon-wrap i { font-size: 1.75rem; }
.quiz-confirm-header { font-size: 1.25rem; font-weight: 700; color: #f1f5f9; margin-bottom: 0.5rem; line-height: 1.3; }
.quiz-confirm-body { color: rgba(241, 245, 249, 0.85); font-size: 0.9375rem; line-height: 1.6; margin-bottom: 1.5rem; }
.quiz-confirm-actions { display: flex; justify-content: center; gap: 0.75rem; flex-wrap: wrap; }
.quiz-confirm-btn {
  padding: 0.625rem 1.5rem; border-radius: 0.5rem; font-size: 0.875rem; font-weight: 600; cursor: pointer;
  transition: background 0.2s, color 0.2s, border-color 0.2s, transform 0.15s, opacity 0.2s;
}
.quiz-confirm-btn:active { transform: scale(0.98); }
.quiz-confirm-btn-cancel { background: transparent; color: #94a3b8; border: 1px solid rgba(148, 163, 184, 0.4); }
.quiz-confirm-btn-cancel:hover { background: rgba(255,255,255,0.06); color: #e2e8f0; border-color: rgba(255,255,255,0.2); }
.quiz-confirm-btn-primary { background: transparent; color: #fff; border: 1px solid rgba(255,255,255,0.6); }
.quiz-confirm-btn-primary:hover { background: rgba(255,255,255,0.1); border-color: #fff; }
@media (max-width: 900px) { .exam-sidebar { flex: 0 0 100%; position: static; order: -1; } .exam-layout { flex-direction: column; } }
@media (max-width: 640px) {
  .exam-header { flex-wrap: wrap; justify-content: center; text-align: center; }
  .exam-header-left { align-items: center; }
  .exam-question-card { padding: 1.25rem 1.25rem; margin-bottom: 1.25rem; }
  .exam-choices { gap: 0.75rem; }
  .exam-choice { padding: 1rem 1.25rem; min-height: 3.25rem; }
  .exam-nav-card { flex-direction: column; align-items: stretch; padding: 1.25rem 1.25rem; }
  .exam-nav-card form { display: flex; justify-content: center; width: 100%; }
}

