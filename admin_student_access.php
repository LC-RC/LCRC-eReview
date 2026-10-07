<?php
require_once 'auth.php';
requireAdminPage();
require_once __DIR__ . '/includes/student_content_access.php';

sca_ensure_schema($conn);
$csrf = generateCSRFToken();
$preselectId = (int) ($_GET['user_id'] ?? 0);
$pageTitle = 'Student Access Management';
$adminBreadcrumbs = [
    ['Dashboard', 'admin_dashboard'],
    ['Students', 'admin_students'],
    ['Student Access'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/head_admin.php'; ?>
  <style>
    .sca-layout { display: flex; flex-direction: column; gap: 16px; align-items: stretch; }
    .sca-layout > * { min-width: 0; }
    .sca-student-item {
      display: flex; align-items: flex-start; gap: 0.5rem; width: 100%; text-align: left;
      border: 1px solid rgba(226,232,240,.85); border-radius: 0.75rem;
      padding: 0.55rem 0.7rem; margin-bottom: 0.4rem;
      background: rgba(255,255,255,.78); backdrop-filter: blur(8px); transition: all .18s ease;
    }
    .sca-student-item:hover { border-color: rgba(147,197,253,.8); background: rgba(239,246,255,.9); transform: translateY(-4px); box-shadow: 0 16px 36px rgba(37,99,235,.12); }
    .sca-student-item.active { border-color: rgba(96,165,250,.45); background: linear-gradient(to bottom right, rgb(239 246 255 / 0.8), rgb(238 242 255 / 0.6)); box-shadow: 0 0 0 2px rgb(96 165 250 / 0.4); }
    .sca-student-item--picked { border-color: #6ee7b7; background: linear-gradient(135deg, #ecfdf5 0%, #f0fdf4 100%); }
    .sca-student-item--picked.active { border-color: rgba(96,165,250,.45); background: linear-gradient(to bottom right, rgb(239 246 255 / 0.8), rgb(238 242 255 / 0.6)); }
    .sca-student-item__main {
      display: flex; align-items: flex-start; gap: 0.65rem; flex: 1; min-width: 0;
      background: none; border: none; padding: 0; cursor: pointer; text-align: left;
    }
    .sca-student-check {
      width: 1rem; height: 1rem; margin-top: 0.35rem; flex-shrink: 0;
      accent-color: #10b981; cursor: pointer;
    }
    .sca-bulk-bar {
      display: flex; flex-wrap: wrap; align-items: center; gap: 0.5rem;
      padding: 0.65rem 0.75rem; margin-bottom: 0.65rem;
      border: 1px solid rgba(167,243,208,.7); border-radius: 0.75rem;
      background: linear-gradient(135deg, rgba(236,253,245,.9) 0%, rgba(240,253,244,.85) 100%);
      backdrop-filter: blur(8px);
    }
    .sca-bulk-bar__count { font-size: 0.78rem; font-weight: 800; color: #047857; }
    .sca-bulk-chips {
      display: flex; flex-wrap: wrap; gap: 0.45rem; max-height: 7rem; overflow-y: auto;
      padding: 0.15rem 0;
    }
    .sca-bulk-chip {
      display: inline-flex; align-items: center; gap: 0.35rem;
      padding: 0.3rem 0.55rem; border-radius: 999px;
      background: #eef2ff; color: #3730a3; font-size: 0.75rem; font-weight: 700;
    }
    .sca-bulk-chip button {
      background: none; border: none; color: #6366f1; cursor: pointer; padding: 0; line-height: 1;
      font-size: 0.95rem;
    }
    .sca-btn--sm { padding: 0.45rem 0.75rem; font-size: 0.78rem; }
    .sca-select-all {
      display: flex; align-items: center; justify-content: space-between; gap: 0.5rem;
      padding: 0.55rem 0.65rem; margin-bottom: 0.5rem;
      border: 1px solid rgba(226,232,240,.85); border-radius: 0.625rem; background: rgba(248,250,252,.75);
    }
    .sca-select-all__label {
      display: inline-flex; align-items: center; gap: 0.5rem;
      font-size: 0.8rem; font-weight: 700; color: #334155; cursor: pointer; margin: 0;
    }
    .sca-select-all__hint { font-size: 0.7rem; color: #94a3b8; white-space: nowrap; }
    .sca-student-item__avatar {
      width: 2.25rem; height: 2.25rem; border-radius: 0.625rem; flex-shrink: 0;
      background: linear-gradient(135deg, #4154f1, #6d7bf7); color: #fff;
      display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 0.85rem;
    }
    .sca-student-item.active .sca-student-item__avatar { background: linear-gradient(135deg, #012970, #4154f1); }

    .sca-panel { position: relative; min-height: 0; }
    .sca-panel-loading {
      position: absolute; inset: 0; z-index: 20; border-radius: 1rem;
      background: rgba(255,255,255,.78); backdrop-filter: blur(6px);
      display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 0.75rem;
    }
    .sca-spinner {
      width: 2.5rem; height: 2.5rem; border-radius: 999px;
      border: 3px solid rgba(65,84,241,.15); border-top-color: #4154f1;
      animation: scaSpin .7s linear infinite;
    }
    @keyframes scaSpin { to { transform: rotate(360deg); } }

    .sca-section {
      border: 1px solid rgba(255,255,255,.8); border-radius: 1rem;
      background: rgba(255,255,255,.72); padding: 1rem; margin-bottom: 1rem;
      box-shadow: 0 8px 28px rgba(15,23,42,.05); backdrop-filter: blur(12px);
    }
    .sca-section__head {
      display: flex; align-items: center; gap: 0.65rem; margin-bottom: 0.85rem;
      font-size: 0.95rem; font-weight: 800; color: #0f172a;
    }
    .sca-section__head i { color: #2563eb; }
    .sca-access-card-grid {
      margin-bottom: 1rem;
    }
    .sca-access-card {
      display: flex; flex-direction: column; gap: 0.35rem; min-width: 0;
      padding: 0.7rem 0.75rem; border-radius: 0.85rem; cursor: pointer;
      border: 1px solid rgba(255,255,255,.8); background: linear-gradient(to bottom right, rgb(239 246 255 / 0.7), rgb(255 255 255 / 0.85), rgb(255 255 255 / 0.75));
      box-shadow: 0 10px 30px rgba(15,23,42,.07), 0 2px 10px rgba(37,99,235,.05);
      transition: transform .2s ease, box-shadow .2s ease, border-color .2s ease;
    }
    .sca-access-card:hover { transform: translateY(-4px); border-color: rgba(191,219,254,.8); box-shadow: 0 16px 36px rgba(37,99,235,.12); }
    .sca-access-card__top { display: flex; align-items: center; gap: 0.45rem; }
    .sca-access-card__title { font-size: 0.78rem; font-weight: 800; color: #0f172a; line-height: 1.25; }
    .sca-access-card__status {
      display: inline-flex; width: fit-content; margin-top: 0.1rem;
      padding: 0.12rem 0.45rem; border-radius: 999px; font-size: 0.65rem; font-weight: 800;
      background: #f1f5f9; color: #64748b;
    }
    .sca-access-card__status.is-on { background: #dcfce7; color: #166534; }
    .sca-access-card__meta { font-size: 0.7rem; color: #64748b; line-height: 1.35; }
    .sca-access-card__action { font-size: 0.68rem; font-weight: 700; color: #2563eb; }
    .sca-student-item__avatar--lg {
      width: 3.25rem; height: 3.25rem; border-radius: 0.9rem; font-size: 1.1rem;
    }

    .sca-tree { padding-right: 0.25rem; }
    .sca-full-lms-card {
      display: flex; gap: 0.65rem; align-items: flex-start; cursor: pointer;
      padding: 0.75rem 0.85rem; margin-bottom: 0.75rem;
      border: 1px solid var(--glass-border, rgba(129,140,248,.35)); border-radius: 0.85rem;
      background: var(--glass-surface-inner, rgba(42,54,72,.6));
      backdrop-filter: blur(8px);
    }
    .sca-full-lms-card.is-on {
      border-color: rgba(129,140,248,.55);
      background: rgba(59,130,246,.16);
      box-shadow: inset 0 0 0 1px rgba(99,102,241,.25);
    }
    .sca-full-lms-card__title { display: block; font-weight: 800; color: var(--text-primary, #F4F7FC); font-size: 0.9rem; }
    .sca-full-lms-card__sub { display: block; margin-top: 0.12rem; font-size: 0.74rem; color: var(--text-secondary, #B8C4D6); line-height: 1.35; }
    html[data-admin-theme="light"] .sca-full-lms-card {
      border-color: rgba(199,210,254,.85);
      background: linear-gradient(135deg, rgba(238,242,255,.92), rgba(245,243,255,.85));
    }
    html[data-admin-theme="light"] .sca-full-lms-card.is-on {
      border-color: #818cf8;
      background: linear-gradient(135deg, rgba(224,231,255,.95), rgba(237,233,254,.9));
    }
    html[data-admin-theme="light"] .sca-full-lms-card__title { color: #312e81; }
    html[data-admin-theme="light"] .sca-full-lms-card__sub { color: #4f46e5; }
    .sca-tree-hint { line-height: 1.4; font-size: 0.8rem; color: #64748b; margin: 0 0 0.65rem; }
    .sca-tree-empty { font-size: 0.82rem; color: #64748b; }
    .sca-subject-grid { display: grid; gap: 0.55rem; }
    .sca-subject-card {
      border: 1px solid rgba(226,232,240,.85); border-radius: 0.85rem;
      background: rgba(255,255,255,.8); overflow: hidden; backdrop-filter: blur(6px);
    }
    .sca-subject-card__summary {
      list-style: none; cursor: pointer; display: flex; flex-wrap: wrap; align-items: center;
      gap: 0.4rem 0.55rem; padding: 0.7rem 0.85rem; font-weight: 700; color: #012970; font-size: 0.88rem;
    }
    .sca-subject-card__summary::-webkit-details-marker { display: none; }
    .sca-subject-card__meta { font-size: 0.72rem; font-weight: 600; color: #64748b; }
    .sca-subject-card__badge {
      margin-left: auto; font-size: 0.65rem; text-transform: uppercase; letter-spacing: 0.04em;
      padding: 0.15rem 0.45rem; border-radius: 999px; font-weight: 800;
    }
    .sca-subject-card__badge--full { background: #dcfce7; color: #166534; }
    .sca-subject-card__badge--topics { background: #ffedd5; color: #9a3412; }
    .sca-subject-card__body { padding: 0 0.85rem 0.85rem; border-top: 1px solid #eef2ff; }
    .sca-mode-label {
      margin: 0.65rem 0 0.4rem; font-size: 0.7rem; font-weight: 800;
      text-transform: uppercase; letter-spacing: 0.04em; color: #64748b;
    }
    .sca-mode-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0.45rem; }
    @media (max-width: 640px) { .sca-mode-grid { grid-template-columns: 1fr; } }
    .sca-mode-card {
      display: flex; gap: 0.5rem; align-items: flex-start; padding: 0.6rem 0.65rem;
      border-radius: 0.65rem; border: 1px solid #e5e7eb; background: #f8fafc; cursor: pointer;
    }
    .sca-mode-card.is-on { border-color: #818cf8; background: #eef2ff; }
    .sca-mode-card__title { display: block; font-size: 0.82rem; font-weight: 800; color: #1e293b; }
    .sca-mode-card__sub { display: block; margin-top: 0.12rem; font-size: 0.72rem; color: #64748b; line-height: 1.35; white-space: normal; }
    .sca-subject-clear {
      margin-top: 0.45rem; background: none; border: 0; color: #4154f1; font-size: 0.76rem;
      text-decoration: underline; cursor: pointer; padding: 0;
    }
    .sca-full-unlocked {
      margin-top: 0.55rem; padding: 0.55rem 0.65rem; border-radius: 0.55rem;
      background: #ecfdf5; color: #166534; font-size: 0.78rem; font-weight: 700;
    }
    .sca-topic-panel { margin-top: 0.6rem; }
    .sca-topic-panel__head {
      display: flex; justify-content: space-between; gap: 0.5rem;
      font-size: 0.76rem; font-weight: 800; color: #334155; margin-bottom: 0.35rem;
    }
    .sca-topic-panel__count { color: #4154f1; font-weight: 700; }
    .sca-topic-panel__empty { font-size: 0.76rem; color: #64748b; }
    .sca-topic-rows { display: grid; gap: 0.35rem; }
    .sca-topic-row {
      display: flex; gap: 0.55rem; align-items: flex-start; padding: 0.6rem 0.65rem;
      border-radius: 0.55rem; border: 1px solid #e5e7eb; background: #fff; cursor: pointer;
    }
    .sca-topic-row:hover { border-color: #c7d2fe; background: #f8faff; }
    .sca-topic-row.is-on { border-color: #86efac; background: #f0fdf4; }
    .sca-topic-row__title {
      display: block; font-size: 0.84rem; font-weight: 700; color: #1e293b;
      white-space: normal; line-height: 1.4; word-break: break-word;
    }
    .sca-topic-row__meta { display: block; margin-top: 0.12rem; font-size: 0.72rem; color: #64748b; }
    .sca-tree input[type=checkbox],
    .sca-tree input[type=radio],
    .sca-full-lms-card input[type=checkbox] { accent-color: #4154f1; width: 1rem; height: 1rem; margin-top: 0.15rem; }
    .sca-chevron {
      width: 0.55rem; height: 0.55rem; border-right: 2px solid #64748b; border-bottom: 2px solid #64748b;
      transform: rotate(-45deg); transition: transform .15s ease; flex-shrink: 0; margin-right: 0.15rem;
    }
    details[open] > summary .sca-chevron { transform: rotate(45deg); }
    .sca-extra-block {
      margin-top: 0.65rem; border: 1px solid rgba(226,232,240,.85); border-radius: 0.85rem;
      padding: 0.25rem 0.65rem 0.65rem; background: rgba(255,255,255,.78);
      backdrop-filter: blur(6px); box-shadow: 0 4px 14px rgba(15,23,42,.03);
    }
    .sca-extra-block__summary {
      list-style: none; cursor: pointer; padding: 0.45rem 0.15rem; color: #012970; font-weight: 700;
    }
    .sca-extra-block__summary::-webkit-details-marker { display: none; }
    .sca-quiz-list { margin-top: 0.35rem; }
    .sca-pb-hint { padding-left: 1rem; line-height: 1.4; }
    .sca-pb-set-label { align-items: flex-start !important; }
    .sca-pb-set-label__text { display: flex; flex-direction: column; gap: 0.2rem; min-width: 0; }
    .sca-pb-status {
      display: inline-flex; align-items: center; width: fit-content;
      padding: 0.15rem 0.5rem; border-radius: 999px; font-size: 0.68rem; font-weight: 700; line-height: 1.3;
      border: 1px solid transparent;
    }
    .sca-pb-status--open { background: #dcfce7; color: #166534; border-color: #86efac; }
    .sca-pb-status--upcoming { background: #e0f2fe; color: #075985; border-color: #7dd3fc; }
    .sca-pb-status--closed { background: #f3f4f6; color: #4b5563; border-color: #d1d5db; }
    .sca-pb-status--locked { background: #fef3c7; color: #92400e; border-color: #fcd34d; }

    .sca-badge { display: inline-flex; align-items: center; padding: 0.25rem 0.6rem; border-radius: 999px; font-size: 0.7rem; font-weight: 800; letter-spacing: 0.02em; }
    .sca-badge--approved { background: #dcfce7; color: #166534; }
    .sca-badge--pending { background: #fef9c3; color: #854d0e; }
    .sca-badge--rejected { background: #fee2e2; color: #991b1b; }

    .sca-form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0.85rem; }
    @media (max-width: 640px) { .sca-form-grid { grid-template-columns: 1fr; } }
    .sca-form-grid label.field-label { display: block; font-size: 0.78rem; font-weight: 700; color: #64748b; margin-bottom: 0.35rem; text-transform: uppercase; letter-spacing: 0.04em; }

    .sca-access-pill {
      display: inline-flex; align-items: center; gap: 0.35rem; padding: 0.35rem 0.65rem;
      border-radius: 999px; background: #eef2ff; color: #3730a3; font-size: 0.78rem; font-weight: 700;
    }

    .sca-sticky-actions {
      flex: 0 0 auto;
      margin: 0;
      padding: 0.9rem 1.15rem;
      border-top: 1px solid var(--glass-border, rgba(226,232,240,.85));
      background: var(--glass-surface-inner, rgba(255,255,255,.92));
      backdrop-filter: blur(10px);
      display: flex; flex-wrap: wrap; gap: 0.65rem; align-items: center;
      position: static;
    }
    .sca-sticky-flash {
      flex: 1 1 12rem; min-width: 0; font-size: 0.84rem; font-weight: 700; line-height: 1.35;
      padding: 0.45rem 0.7rem; border-radius: 0.55rem;
    }
    .sca-sticky-flash--ok {
      color: #166534; background: #dcfce7; border: 1px solid #86efac;
    }
    .sca-sticky-flash--err {
      color: #991b1b; background: #fee2e2; border: 1px solid #fca5a5;
    }

    .sca-btn {
      display: inline-flex; align-items: center; justify-content: center; gap: 0.45rem;
      padding: 0.62rem 1.1rem; border-radius: 0.625rem; font-weight: 700; font-size: 0.875rem;
      border: 2px solid transparent; transition: all .15s ease; cursor: pointer;
    }
    .sca-btn:disabled { opacity: 0.65; cursor: not-allowed; transform: none !important; }
    .sca-btn--primary { background: linear-gradient(90deg, #2563eb, #6366f1); color: #fff; box-shadow: 0 4px 14px rgba(37,99,235,.25); border-color: transparent; }
    .sca-btn--primary:hover:not(:disabled) { filter: brightness(1.04); transform: translateY(-1px); }
    .sca-btn--outline { background: rgba(255,255,255,.9); border-color: #e5e7eb; color: #475569; }
    .sca-btn--outline:hover:not(:disabled) { border-color: #2563eb; color: #2563eb; background: #f8faff; }
    .sca-btn--ghost { background: transparent; color: #64748b; border-color: transparent; }
    .sca-btn--ghost:hover:not(:disabled) { color: #334155; background: #f1f5f9; }
    .sca-btn__spin { animation: scaSpin .7s linear infinite; }

    .sca-idle {
      text-align: center;
      padding: 1.25rem 1rem;
      display: flex;
      align-items: center;
      justify-content: center;
      min-height: 16rem;
    }
    .sca-idle__icon {
      width: 2.75rem; height: 2.75rem; margin: 0 auto 0.5rem; border-radius: 0.85rem;
      background: linear-gradient(135deg, rgba(238,242,255,.95), rgba(245,243,255,.9)); color: #2563eb;
      display: flex; align-items: center; justify-content: center; font-size: 1.15rem;
      box-shadow: 0 4px 12px rgba(37,99,235,.08);
    }

    /* Toast stack - near sticky Save actions (bottom), always viewport-visible */
    .sca-toast-stack {
      position: fixed; bottom: 5.25rem; right: 1.25rem; top: auto; z-index: 10050;
      display: flex; flex-direction: column-reverse; gap: 0.65rem; width: min(100vw - 2rem, 22rem);
      pointer-events: none;
    }
    .sca-toast {
      pointer-events: auto; display: flex; gap: 0.75rem; align-items: flex-start;
      padding: 0.9rem 1rem; border-radius: 0.875rem;
      box-shadow: 0 12px 40px rgba(15,23,42,.28); border: 1px solid transparent;
      animation: scaToastIn .35s ease;
      background: #fff;
    }
    @keyframes scaToastIn {
      from { opacity: 0; transform: translateY(0.85rem); }
      to { opacity: 1; transform: translateY(0); }
    }
    .sca-toast--ok { background: #fff; border-color: #bbf7d0; }
    .sca-toast--err { background: #fff; border-color: #fecaca; }
    .sca-toast__icon {
      width: 2.25rem; height: 2.25rem; border-radius: 0.625rem; flex-shrink: 0;
      display: flex; align-items: center; justify-content: center; font-size: 1.1rem;
    }
    .sca-toast--ok .sca-toast__icon { background: #dcfce7; color: #16a34a; }
    .sca-toast--err .sca-toast__icon { background: #fee2e2; color: #dc2626; }
    .sca-toast__title { font-weight: 800; font-size: 0.9rem; color: #0f172a; margin: 0 0 0.15rem; }
    .sca-toast__msg { font-size: 0.82rem; color: #64748b; margin: 0; line-height: 1.4; }
    .sca-toast__close {
      margin-left: auto; background: none; border: none; color: #94a3b8; cursor: pointer;
      padding: 0.15rem; font-size: 1.1rem; line-height: 1;
    }
    .sca-toast__close:hover { color: #475569; }

    .sca-perm-count {
      font-size: 0.75rem; font-weight: 700; color: #6366f1; background: #eef2ff;
      padding: 0.2rem 0.55rem; border-radius: 999px;
    }

    .sca-kpis {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 12px;
    }
    @media (max-width: 1024px) { .sca-kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
    @media (max-width: 640px) { .sca-kpis { grid-template-columns: 1fr; } }
    .sca-kpi {
      min-height: 96px;
      padding: 14px 16px;
      border-radius: 16px;
      border: 1px solid var(--glass-border);
      background: var(--glass-surface-inner);
      box-shadow: var(--shadow-card, none);
      backdrop-filter: blur(16px);
    }
    .sca-kpi__label {
      margin: 0;
      font-size: 0.72rem;
      font-weight: 700;
      letter-spacing: 0.04em;
      text-transform: uppercase;
      color: var(--text-muted);
    }
    .sca-kpi__value {
      margin: 0.35rem 0 0;
      font-size: 1.45rem;
      font-weight: 800;
      color: var(--text-primary);
      letter-spacing: -0.03em;
    }

    .sca-directory {
      border-radius: 20px;
      border: 1px solid var(--glass-border);
      background: var(--glass-surface-fill, var(--glass-surface-strong));
      box-shadow: var(--shadow-card, none);
      backdrop-filter: blur(18px);
      overflow: hidden;
    }
    .sca-directory__head {
      display: flex;
      flex-wrap: wrap;
      align-items: flex-start;
      justify-content: space-between;
      gap: 12px;
      padding: 14px 16px;
      border-bottom: 1px solid var(--glass-border);
    }
    .sca-directory__title { margin: 0; font-size: 1rem; font-weight: 750; color: var(--text-primary); }
    .sca-directory__sub { margin: 0.2rem 0 0; font-size: 0.8rem; color: var(--text-secondary); }
    .sca-directory__tools {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      justify-content: flex-end;
      gap: 8px;
    }
    .sca-directory__search { position: relative; min-width: min(16rem, 100%); flex: 1 1 14rem; max-width: 22rem; }
    .sca-directory__search i { position: absolute; left: 0.7rem; top: 50%; transform: translateY(-50%); color: var(--text-muted); }
    .sca-directory__search input { width: 100%; padding-left: 2rem; }
    .student-access-table-scroll { width: 100%; overflow-x: auto; }
    .sca-access-table { width: 100%; min-width: 720px; border-collapse: collapse; }
    .sca-access-table th, .sca-access-table td {
      padding: 0.7rem 0.85rem; text-align: left; vertical-align: middle;
      border-bottom: 1px solid color-mix(in srgb, var(--glass-border) 60%, transparent);
    }
    .sca-access-table th {
      font-size: 0.68rem; letter-spacing: 0.05em; text-transform: uppercase;
      color: var(--text-muted); font-weight: 700; white-space: nowrap;
    }
    .sca-access-table td { color: var(--text-primary); font-size: 0.84rem; }
    .sca-access-table tbody tr:hover td { background: rgba(59,130,246,.06); }
    .sca-access-table .col-check { width: 44px; }
    .sca-access-table .col-actions { white-space: nowrap; text-align: right; }
    .sca-student-cell { display: flex; align-items: center; gap: 0.65rem; min-width: 0; }
    .sca-student-cell__text { min-width: 0; }
    .sca-student-cell__name { display: block; font-weight: 700; color: var(--text-primary); }
    .sca-student-cell__email { display: block; font-size: 0.75rem; color: var(--text-muted); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 18rem; }
    .sca-empty {
      padding: 2.5rem 1.25rem; text-align: center; color: var(--text-secondary);
    }

    html.sca-modal-open, body.sca-modal-open { overflow: hidden; }
    .sca-workspace-overlay {
      position: fixed; inset: 0; z-index: 1400;
      display: none; align-items: center; justify-content: center;
      padding: 24px; overflow: hidden;
      background: rgba(2, 6, 15, 0.62);
      backdrop-filter: blur(8px);
    }
    .sca-workspace-overlay.is-open { display: grid; place-items: center; }
    .sca-workspace-dialog {
      width: min(1100px, calc(100vw - 48px));
      max-height: calc(100dvh - 48px);
      display: flex; flex-direction: column; overflow: hidden;
      border-radius: 20px;
      border: 1px solid var(--glass-border);
      background: var(--glass-surface-strong);
      box-shadow: 0 30px 80px rgba(0,0,0,.4), inset 0 1px 0 var(--glass-highlight, rgba(255,255,255,.06));
      color: var(--text-primary);
    }
    .sca-workspace-dialog--create { width: min(960px, calc(100vw - 48px)); }
    .sca-workspace-head {
      flex: 0 0 auto;
      display: flex; align-items: flex-start; justify-content: space-between; gap: 0.75rem;
      padding: 1rem 1.15rem;
      border-bottom: 1px solid var(--glass-border);
      background: var(--glass-surface-inner);
    }
    .sca-workspace-head h2 { margin: 0; font-size: 1.05rem; font-weight: 780; color: var(--text-primary); }
    .sca-workspace-head p { margin: 0.2rem 0 0; font-size: 0.8rem; color: var(--text-secondary); }
    .sca-workspace-body {
      flex: 1 1 auto; min-height: 0;
      overflow-x: hidden; overflow-y: auto;
      overscroll-behavior: contain;
      padding: 1.05rem 1.15rem;
    }
    .sca-workspace-dialog .sca-panel { min-height: 0; }
    .sca-workspace-dialog .sca-panel-loading { border-radius: 0; }
    @media (max-width: 768px) {
      .sca-workspace-overlay { padding: 0; }
      .sca-workspace-dialog, .sca-workspace-dialog--create {
        width: 100%; height: 100dvh; max-height: 100dvh; border-radius: 0;
      }
    }

    html[data-admin-theme="dark"] .sca-workspace-dialog .sca-section,
    html[data-admin-theme="dark"] .sca-workspace-dialog .sca-access-card,
    html[data-admin-theme="dark"] .sca-workspace-dialog .sca-subject-card,
    html[data-admin-theme="dark"] .sca-workspace-dialog .sca-extra-block,
    html[data-admin-theme="dark"] .sca-workspace-dialog .sca-topic-row,
    html[data-admin-theme="dark"] .sca-workspace-dialog .sca-mode-card {
      background: var(--glass-surface-inner);
      border-color: var(--glass-border);
      box-shadow: none;
    }
    html[data-admin-theme="dark"] .sca-workspace-dialog .sca-section__head,
    html[data-admin-theme="dark"] .sca-workspace-dialog .sca-access-card__title,
    html[data-admin-theme="dark"] .sca-workspace-dialog .sca-subject-card__summary,
    html[data-admin-theme="dark"] .sca-workspace-dialog .text-slate-900,
    html[data-admin-theme="dark"] .sca-workspace-dialog .text-xl {
      color: var(--text-primary);
    }
    html[data-admin-theme="dark"] .sca-workspace-dialog .text-slate-600,
    html[data-admin-theme="dark"] .sca-workspace-dialog .text-gray-500,
    html[data-admin-theme="dark"] .sca-workspace-dialog .text-gray-400,
    html[data-admin-theme="dark"] .sca-workspace-dialog .text-gray-600,
    html[data-admin-theme="dark"] .sca-workspace-dialog .sca-access-card__meta {
      color: var(--text-secondary);
    }
  </style>
  <script src="<?php echo h(function_exists('ereview_url') ? ereview_url('assets/js/admin_sca_picker.js') : 'assets/js/admin_sca_picker.js'); ?>"></script>
  <script>
  document.addEventListener('alpine:init', function () {
    Alpine.data('studentAccessAdmin', function () {
      return {
        csrf: <?php echo json_encode($csrf); ?>,
        preselectId: <?php echo (int) $preselectId; ?>,
        panelMode: <?php echo $preselectId > 0 ? "'edit'" : "'idle'"; ?>,
        lastTrigger: null,
        searchQ: '',
        searchResults: [],
        searchTotal: 0,
        selectedId: <?php echo (int) $preselectId; ?>,
        selectedIds: [],
        selectedStudentsMeta: {},
        student: null,
        edit: { full_name: '', email: '', school: '', status: 'approved', password: '', extend_months: 0 },
        catalog: { subjects: [], preboard_subjects: [], preweek_units: [], test_bank: [] },
        permissions: [],
        newPermissions: [],
        bulkPermissions: [],
        subjectModes: {},
        toasts: [],
        toastSeq: 0,
        stickyFlash: '',
        stickyFlashType: 'ok',
        stickyFlashTimer: null,
        loadingSearch: false,
        loadingStudent: false,
        loadingCatalog: false,
        saveAction: null,
        newStudent: { full_name: '', email: '', password: '', school: 'Manual enrollment', months: 6 },

        get permissionListKey() {
          if (this.panelMode === 'create') return 'newPermissions';
          if (this.panelMode === 'bulk') return 'bulkPermissions';
          return 'permissions';
        },
        get activePermissionList() {
          return this[this.permissionListKey] || [];
        },
        get hasFullLms() {
          return this.activePermissionList.some(p => p.content_type === 'full_lms' && Number(p.content_id) === 0);
        },
        get activePermCount() {
          if (this.activePermissionList.some(p => p.content_type === 'full_lms')) return 'Full LMS';
          const n = this.activePermissionList.length;
          return n === 0 ? 'None selected' : n + ' item' + (n === 1 ? '' : 's');
        },
        get selectedStudents() {
          return this.selectedIds.map(id => ({
            user_id: id,
            full_name: this.selectedStudentsMeta[id]?.full_name || ('Student #' + id),
            email: this.selectedStudentsMeta[id]?.email || ''
          }));
        },
        get allMatchingSelected() {
          if (this.searchTotal <= 0) return false;
          return this.selectedInFilterCount === this.searchTotal;
        },
        get someMatchingSelected() {
          return this.selectedInFilterCount > 0 && !this.allMatchingSelected;
        },
        get selectedInFilterCount() {
          if (!this.searchResults.length || !this.selectedIds.length) return 0;
          const filterIds = new Set(this.searchResults.map(s => Number(s.user_id)));
          return this.selectedIds.filter(id => filterIds.has(id)).length;
        },
        get panelLoading() {
          return this.loadingStudent || this.saveAction !== null;
        },
        get panelLoadingLabel() {
          if (this.saveAction === 'create') return 'Creating student account...';
          if (this.saveAction === 'account') return 'Saving account details...';
          if (this.saveAction === 'permissions') return 'Saving content access...';
          if (this.saveAction === 'bulk') return 'Applying access to selected students...';
          if (this.loadingStudent) return 'Loading student data...';
          return 'Please wait...';
        },

        get kpiTotal() { return Number(this.searchTotal) || 0; },
        get kpiActive() {
          return this.searchResults.filter(s => (s.status || '') === 'approved').length;
        },
        get kpiPending() {
          return this.searchResults.filter(s => (s.status || '') === 'pending').length;
        },
        get kpiExpiring() {
          const now = Date.now();
          const soon = now + (30 * 86400000);
          return this.searchResults.filter(s => {
            if ((s.status || '') !== 'approved' || !s.access_end) return false;
            const t = Date.parse(s.access_end);
            return !Number.isNaN(t) && t >= now && t <= soon;
          }).length;
        },

        formatAccessEnd(value) {
          if (!value) return '—';
          const t = Date.parse(value);
          if (Number.isNaN(t)) return value;
          return new Date(t).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
        },
        accountLabel(status) {
          const s = String(status || 'pending');
          if (s === 'approved') return 'Active';
          if (s === 'rejected') return 'Deactivated';
          return s.charAt(0).toUpperCase() + s.slice(1);
        },

        syncWorkspaceLock(mode) {
          const open = mode === 'create' || mode === 'edit' || mode === 'bulk';
          document.body.classList.toggle('sca-modal-open', open);
          document.documentElement.classList.toggle('sca-modal-open', open);
        },
        closeWorkspace() {
          if (this.saveAction !== null) return;
          if (this.panelMode === 'create') this.cancelCreate();
          else if (this.panelMode === 'bulk') this.cancelBulk();
          else {
            this.panelMode = 'idle';
            this.selectedId = 0;
            this.student = null;
          }
          this.restoreFocus();
        },
        restoreFocus() {
          const el = this.lastTrigger;
          this.lastTrigger = null;
          if (el && typeof el.focus === 'function') {
            this.$nextTick(() => el.focus());
          }
        },
        focusWorkspace() {
          this.$nextTick(() => {
            const box = document.querySelector('.sca-workspace-overlay.is-open');
            const focusEl = box && box.querySelector('input, select, button, textarea');
            if (focusEl) focusEl.focus();
          });
        },

        async init() {
          this.$watch('panelMode', (mode) => this.syncWorkspaceLock(mode));
          this.syncWorkspaceLock(this.panelMode);
          this.loadingCatalog = true;
          await this.loadCatalog();
          this.loadingCatalog = false;
          await this.searchStudents();
          if (this.preselectId > 0) await this.loadStudent(this.preselectId);
        },

        showToast(title, message, type) {
          const id = ++this.toastSeq;
          const kind = type || 'ok';
          this.toasts.push({ id, title, message, type: kind });
          this.stickyFlash = (title ? title + ' - ' : '') + (message || '');
          this.stickyFlashType = kind;
          if (this.stickyFlashTimer) clearTimeout(this.stickyFlashTimer);
          this.stickyFlashTimer = setTimeout(() => {
            this.stickyFlash = '';
          }, kind === 'err' ? 7000 : 4500);
          // Keep confirmation near the Save buttons the admin just clicked.
          this.$nextTick(() => {
            const bar = document.querySelector('.sca-sticky-actions');
            if (bar) bar.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
          });
          setTimeout(() => this.dismissToast(id), kind === 'err' ? 7000 : 4500);
        },
        dismissToast(id) {
          this.toasts = this.toasts.filter(t => t.id !== id);
        },
        initials(name) {
          const p = (name || '?').trim().split(/\s+/);
          return ((p[0]?.[0] || '') + (p[1]?.[0] || '')).toUpperCase() || '?';
        },

        async apiGet(action, params) {
          const qs = new URLSearchParams({ action, ...(params || {}) });
          const res = await fetch('admin_student_access_api?' + qs.toString());
          const data = await res.json().catch(() => ({}));
          if (!res.ok || !data.ok) throw new Error(data.error || 'Request failed');
          return data;
        },
        async apiPost(action, fields) {
          const fd = new FormData();
          fd.append('action', action);
          fd.append('csrf_token', this.csrf);
          Object.entries(fields || {}).forEach(([k, v]) => fd.append(k, v == null ? '' : v));
          const res = await fetch('admin_student_access_api', { method: 'POST', body: fd });
          const data = await res.json().catch(() => ({}));
          if (!res.ok || !data.ok) throw new Error(data.error || 'Request failed');
          return data;
        },

        startCreate(ev) {
          this.lastTrigger = ev && ev.currentTarget ? ev.currentTarget : null;
          this.panelMode = 'create';
          this.selectedId = 0;
          this.selectedIds = [];
          this.selectedStudentsMeta = {};
          this.student = null;
          this.newPermissions = [];
          this.newStudent = { full_name: '', email: '', password: '', school: 'Manual enrollment', months: 6 };
          this.focusWorkspace();
        },
        cancelCreate() {
          if (this.saveAction !== null) return;
          this.panelMode = 'idle';
          this.newPermissions = [];
          this.restoreFocus();
        },
        isSelected(id) {
          return this.selectedIds.includes(Number(id));
        },
        toggleSelected(id) {
          const num = Number(id);
          if (this.isSelected(num)) {
            this.selectedIds = this.selectedIds.filter(v => v !== num);
            delete this.selectedStudentsMeta[num];
          } else {
            const row = this.searchResults.find(s => Number(s.user_id) === num);
            if (row) {
              this.selectedStudentsMeta[num] = { full_name: row.full_name, email: row.email };
            }
            this.selectedIds = [...this.selectedIds, num];
          }
          this.$nextTick(() => this.syncSelectAllIndeterminate());
        },
        clearSelection() {
          this.selectedIds = [];
          this.selectedStudentsMeta = {};
          if (this.panelMode === 'bulk') {
            this.panelMode = 'idle';
            this.bulkPermissions = [];
          }
          this.$nextTick(() => this.syncSelectAllIndeterminate());
        },
        selectAllMatching() {
          const filterSet = new Set(this.searchResults.map(s => Number(s.user_id)));
          const keptIds = this.selectedIds.filter(id => !filterSet.has(id));
          const keptMeta = {};
          keptIds.forEach(id => {
            if (this.selectedStudentsMeta[id]) keptMeta[id] = this.selectedStudentsMeta[id];
          });
          const newIds = this.searchResults.map(s => Number(s.user_id));
          const newMeta = { ...keptMeta };
          this.searchResults.forEach(s => {
            const num = Number(s.user_id);
            newMeta[num] = { full_name: s.full_name, email: s.email };
          });
          this.selectedIds = [...keptIds, ...newIds];
          this.selectedStudentsMeta = newMeta;
          this.$nextTick(() => this.syncSelectAllIndeterminate());
        },
        deselectAllMatching() {
          const filterSet = new Set(this.searchResults.map(s => Number(s.user_id)));
          this.selectedIds = this.selectedIds.filter(id => !filterSet.has(id));
          filterSet.forEach(id => delete this.selectedStudentsMeta[id]);
          if (this.selectedIds.length === 0 && this.panelMode === 'bulk') {
            this.panelMode = 'idle';
            this.bulkPermissions = [];
          }
          this.$nextTick(() => this.syncSelectAllIndeterminate());
        },
        toggleSelectAllMatching(on) {
          if (on) {
            this.selectAllMatching();
            return;
          }
          this.deselectAllMatching();
        },
        syncSelectAllIndeterminate() {
          const el = this.$refs.selectAllCheckbox;
          if (el) {
            el.indeterminate = this.someMatchingSelected;
          }
        },
        openBulkAssign(ev) {
          if (this.selectedIds.length === 0) {
            this.showToast('No students selected', 'Check at least one student from the list.', 'err');
            return;
          }
          this.lastTrigger = ev && ev.currentTarget ? ev.currentTarget : null;
          this.panelMode = 'bulk';
          this.selectedId = 0;
          this.student = null;
          this.bulkPermissions = [];
          this.focusWorkspace();
        },
        cancelBulk() {
          if (this.saveAction !== null) return;
          this.panelMode = 'idle';
          this.bulkPermissions = [];
          this.restoreFocus();
        },
        removeFromBulk(id) {
          const num = Number(id);
          this.selectedIds = this.selectedIds.filter(v => v !== num);
          delete this.selectedStudentsMeta[num];
          if (this.selectedIds.length === 0) {
            this.cancelBulk();
          }
        },

        async loadCatalog() {
          try {
            const data = await this.apiGet('catalog');
            this.catalog = data.catalog;
          } catch (e) {
            this.showToast('Load failed', e.message, 'err');
          }
        },
        async searchStudents() {
          this.loadingSearch = true;
          try {
            const data = await this.apiGet('search', { q: this.searchQ });
            this.searchResults = data.students || [];
            this.searchTotal = Number(data.total ?? this.searchResults.length);
          } catch (e) {
            this.showToast('Search failed', e.message, 'err');
          } finally {
            this.loadingSearch = false;
            this.$nextTick(() => this.syncSelectAllIndeterminate());
          }
        },
        async loadStudent(id, ev) {
          if (ev && ev.currentTarget) this.lastTrigger = ev.currentTarget;
          this.panelMode = 'edit';
          this.selectedId = id;
          this.selectedIds = [];
          this.selectedStudentsMeta = {};
          this.bulkPermissions = [];
          this.loadingStudent = true;
          this.student = null;
          this.focusWorkspace();
          try {
            const data = await this.apiGet('student', { user_id: id });
            this.student = data.user;
            this.permissions = data.permissions || [];
            this.inferSubjectModesFromPermissions();
            this.edit = {
              full_name: data.user.full_name || '',
              email: data.user.email || '',
              school: data.user.school || '',
              status: data.user.status || 'approved',
              password: '',
              extend_months: 0
            };
          } catch (e) {
            this.showToast('Could not load student', e.message, 'err');
          } finally {
            this.loadingStudent = false;
          }
        },

        async savePermissions() {
          this.saveAction = 'permissions';
          try {
            const data = await this.apiPost('save_permissions', {
              user_id: this.selectedId,
              permissions: JSON.stringify(this.permissions)
            });
            this.permissions = data.permissions || [];
            this.inferSubjectModesFromPermissions();
            this.showToast('Saved!', 'Content access permissions were updated successfully.', 'ok');
          } catch (e) {
            this.showToast('Save failed', e.message, 'err');
          } finally {
            this.saveAction = null;
          }
        },
        async saveStudent() {
          this.saveAction = 'account';
          try {
            const payload = {
              user_id: this.selectedId,
              full_name: this.edit.full_name,
              email: this.edit.email,
              school: this.edit.school,
              status: this.edit.status,
              extend_months: this.edit.extend_months
            };
            if ((this.edit.password || '').trim() !== '') {
              payload.new_password = this.edit.password;
            }
            await this.apiPost('update_student', payload);
            this.edit.password = '';
            this.showToast('Saved!', 'Student account details were updated successfully.', 'ok');
            await this.loadStudent(this.selectedId);
          } catch (e) {
            this.showToast('Save failed', e.message, 'err');
          } finally {
            this.saveAction = null;
          }
        },
        async saveBulkPermissions() {
          if (this.selectedIds.length === 0) {
            this.showToast('No students selected', 'Check at least one student from the list.', 'err');
            return;
          }
          const hasAccess = this.hasFullLms || this.bulkPermissions.length > 0;
          if (!hasAccess) {
            this.showToast('Select content access', 'Enable Full LMS or choose at least one content item to assign.', 'err');
            return;
          }
          if (!confirm('Apply the selected access to ' + this.selectedIds.length + ' student(s)? This replaces their current content permissions.')) {
            return;
          }
          this.saveAction = 'bulk';
          try {
            const data = await this.apiPost('save_bulk_permissions', {
              user_ids: JSON.stringify(this.selectedIds),
              permissions: JSON.stringify(this.bulkPermissions)
            });
            const failed = (data.failed || []).length;
            const msg = failed > 0
              ? data.updated + ' updated, ' + failed + ' failed.'
              : 'The same content access was applied to ' + data.updated + ' student(s).';
            this.showToast('Bulk assign complete', msg, failed > 0 ? 'err' : 'ok');
            this.selectedIds = [];
            this.selectedStudentsMeta = {};
            this.bulkPermissions = [];
            this.panelMode = 'idle';
          } catch (e) {
            this.showToast('Bulk assign failed', e.message, 'err');
          } finally {
            this.saveAction = null;
          }
        },
        async createStudent() {
          if (!this.newStudent.full_name.trim() || !this.newStudent.email.trim() || !this.newStudent.password) {
            this.showToast('Missing fields', 'Full name, email, and password are required.', 'err');
            return;
          }
          const hasAccess = this.hasFullLms || this.newPermissions.length > 0;
          if (!hasAccess) {
            this.showToast('Select content access', 'Enable Full LMS or choose at least one subject, preboard, pre-week, or test bank item.', 'err');
            return;
          }
          this.saveAction = 'create';
          try {
            const grantFull = this.hasFullLms ? '1' : '0';
            const data = await this.apiPost('create_student', {
              full_name: this.newStudent.full_name,
              email: this.newStudent.email,
              student_password: this.newStudent.password,
              school: this.newStudent.school,
              months: this.newStudent.months,
              grant_full_lms: grantFull,
              permissions: JSON.stringify(this.newPermissions)
            });
            this.newStudent.password = '';
            this.showToast('Student created!', this.newStudent.full_name + ' was added with the selected access.', 'ok');
            await this.searchStudents();
            await this.loadStudent(data.user_id);
          } catch (e) {
            this.showToast('Create failed', e.message, 'err');
          } finally {
            this.saveAction = null;
          }
        },
        ...(window.ereviewScaPickerMethods || {})
      };
    });
  });
  </script>
</head>
<body class="font-sans antialiased admin-app admin-student-access-page">
<?php include 'admin_sidebar.php'; ?>

<div x-data="studentAccessAdmin()" x-init="init()" @keydown.escape.window="closeWorkspace()">

<?php
  $adminHeroIcon = 'shield-lock';
  $adminHeroEyebrow = 'Student management';
  $adminHeroTitle = 'Manual / Administrative Access';
  $adminHeroSubtitle = 'Manage student accounts, access status, and LMS permissions.';
  $adminHeroActions = '<a href="admin_students" class="admin-btn admin-btn--secondary inline-flex h-10 items-center gap-2 rounded-xl px-4 text-sm font-semibold"><i class="bi bi-people"></i> Students List</a>'
    . '<button type="button" class="admin-btn admin-btn--primary admin-btn--sm inline-flex h-10 items-center gap-2 rounded-xl px-4 text-sm font-semibold" @click="startCreate($event)"><i class="bi bi-plus-lg"></i> Add New Student</button>';
  include __DIR__ . '/includes/components/admin_page_hero.php';
?>

  <!-- Toast notifications -->
  <div class="sca-toast-stack" aria-live="polite" aria-atomic="true">
    <template x-for="t in toasts" :key="t.id">
      <div class="sca-toast" :class="t.type === 'ok' ? 'sca-toast--ok' : 'sca-toast--err'" role="alert">
        <span class="sca-toast__icon">
          <i class="bi" :class="t.type === 'ok' ? 'bi-check-lg' : 'bi-exclamation-lg'"></i>
        </span>
        <div class="min-w-0">
          <p class="sca-toast__title" x-text="t.title"></p>
          <p class="sca-toast__msg" x-text="t.message"></p>
        </div>
        <button type="button" class="sca-toast__close" @click="dismissToast(t.id)" aria-label="Dismiss">&times;</button>
      </div>
    </template>
  </div>

  <div class="sca-layout">
    <div class="sca-kpis">
      <article class="sca-kpi">
        <p class="sca-kpi__label">Total Students</p>
        <p class="sca-kpi__value" x-text="kpiTotal">0</p>
      </article>
      <article class="sca-kpi">
        <p class="sca-kpi__label">Active Access</p>
        <p class="sca-kpi__value" x-text="kpiActive">0</p>
      </article>
      <article class="sca-kpi">
        <p class="sca-kpi__label">Pending Review</p>
        <p class="sca-kpi__value" x-text="kpiPending">0</p>
      </article>
      <article class="sca-kpi">
        <p class="sca-kpi__label">Expiring Soon</p>
        <p class="sca-kpi__value" x-text="kpiExpiring">0</p>
      </article>
    </div>

    <section class="sca-directory">
      <div class="sca-directory__head">
        <div>
          <h2 class="sca-directory__title">Student Access Directory</h2>
          <p class="sca-directory__sub">Manage student accounts and assigned learning access.</p>
        </div>
        <div class="sca-directory__tools admin-data-toolbar">
          <div class="sca-directory__search">
            <i class="bi bi-search" aria-hidden="true"></i>
            <input type="search" class="input-custom" placeholder="Search name or email..." x-model="searchQ" @input.debounce.300ms="searchStudents()" aria-label="Search students">
          </div>
          <button type="button" class="admin-btn admin-btn--secondary admin-btn--sm" @click="searchStudents()" title="Apply filters"><i class="bi bi-funnel"></i> Filter</button>
        </div>
      </div>

      <div class="sca-bulk-bar" x-show="selectedIds.length > 0" x-cloak>
        <span class="sca-bulk-bar__count" x-text="selectedIds.length + ' selected'"></span>
        <button type="button" class="sca-btn sca-btn--outline sca-btn--sm" @click="selectAllMatching()" x-show="!allMatchingSelected && searchTotal > 0">Select all</button>
        <button type="button" class="sca-btn sca-btn--outline sca-btn--sm" @click="clearSelection()">Clear all</button>
        <button type="button" class="sca-btn sca-btn--primary sca-btn--sm ml-auto" @click="openBulkAssign($event)">
          <i class="bi bi-people-fill"></i> Assign access
        </button>
      </div>

      <div class="student-access-table-scroll admin-data-surface" x-show="!loadingSearch && searchResults.length > 0">
        <table class="sca-access-table">
          <thead>
            <tr>
              <th class="col-check" scope="col">
                <input type="checkbox" class="sca-student-check" x-ref="selectAllCheckbox"
                       :checked="allMatchingSelected"
                       @change="toggleSelectAllMatching($event.target.checked)"
                       aria-label="Select all students in this list">
              </th>
              <th scope="col">Student</th>
              <th scope="col">Account</th>
              <th scope="col">Access until</th>
              <th scope="col">Status</th>
              <th class="col-actions" scope="col">Actions</th>
            </tr>
          </thead>
          <tbody>
            <template x-for="s in searchResults" :key="s.user_id">
              <tr :class="{ 'is-selected': isSelected(s.user_id) }">
                <td class="col-check">
                  <input type="checkbox" class="sca-student-check" :checked="isSelected(s.user_id)" @click.stop="toggleSelected(s.user_id)" :aria-label="'Select ' + s.full_name">
                </td>
                <td>
                  <div class="sca-student-cell">
                    <span class="sca-student-item__avatar" x-text="initials(s.full_name)"></span>
                    <span class="sca-student-cell__text">
                      <span class="sca-student-cell__name" x-text="s.full_name"></span>
                      <span class="sca-student-cell__email" :title="s.email" x-text="s.email"></span>
                    </span>
                  </div>
                </td>
                <td x-text="accountLabel(s.status)"></td>
                <td x-text="formatAccessEnd(s.access_end)"></td>
                <td>
                  <span class="sca-badge" :class="'sca-badge--' + (s.status || 'pending')" x-text="accountLabel(s.status)"></span>
                </td>
                <td class="col-actions">
                  <button type="button" class="admin-btn admin-btn--secondary admin-btn--sm" @click="loadStudent(s.user_id, $event)">Manage Access</button>
                  <a class="admin-btn admin-btn--ghost admin-btn--sm no-underline" :href="'admin_student_view?id=' + s.user_id" title="View profile"><i class="bi bi-three-dots"></i></a>
                </td>
              </tr>
            </template>
          </tbody>
        </table>
      </div>

      <div class="flex items-center justify-center gap-2 text-sm py-8" x-show="loadingSearch" x-cloak>
        <span class="sca-spinner" style="width:1.25rem;height:1.25rem;border-width:2px"></span>
        Searching...
      </div>

      <div class="sca-empty" x-show="!loadingSearch && searchResults.length === 0" x-cloak>
        <h3 class="sca-directory__title">No students found.</h3>
        <p class="sca-directory__sub">Add a student to begin assigning LMS access.</p>
        <button type="button" class="sca-btn sca-btn--primary mt-3" @click="startCreate($event)">
          <i class="bi bi-person-plus"></i> Add New Student
        </button>
      </div>
    </section>

    <!-- Create panel -->
    <div class="sca-workspace-overlay" :class="{ 'is-open': panelMode === 'create' }" x-show="panelMode === 'create'" x-cloak x-teleport="body" role="dialog" aria-modal="true" aria-labelledby="scaCreateTitle" @click.self="closeWorkspace()">
    <section class="sca-workspace-dialog sca-workspace-dialog--create sca-panel">
      <div x-show="panelLoading" class="sca-panel-loading" x-cloak>
        <span class="sca-spinner"></span>
        <span class="text-sm font-semibold text-gray-600" x-text="panelLoadingLabel"></span>
      </div>

      <header class="sca-workspace-head">
        <div>
          <h2 id="scaCreateTitle">New Student</h2>
          <p>Create a student account and assign access.</p>
        </div>
        <button type="button" class="sca-btn sca-btn--ghost" @click="cancelCreate()" aria-label="Close"><i class="bi bi-x-lg"></i></button>
      </header>
      <div class="sca-workspace-body">

      <div class="sca-section rounded-2xl border border-slate-200/70 bg-white/80 p-4 shadow-sm">
        <div class="sca-section__head"><span class="lms-icon-tile bg-emerald-50 text-emerald-600"><i class="bi bi-person-vcard"></i></span> Account details</div>
        <div class="sca-form-grid" autocomplete="off">
          <div class="col-span-full">
            <label class="field-label">Full name</label>
            <input type="text" class="input-custom w-full" x-model="newStudent.full_name" placeholder="Juan Dela Cruz" autocomplete="off">
          </div>
          <div class="col-span-full">
            <label class="field-label">Email</label>
            <input type="email" class="input-custom w-full" x-model="newStudent.email" placeholder="student@email.com" autocomplete="off" name="student_email">
          </div>
          <div class="col-span-full">
            <label class="field-label">Password <span class="normal-case font-normal text-gray-400">(for student login only)</span></label>
            <input type="password" class="input-custom w-full" x-model="newStudent.password" placeholder="Set student password" autocomplete="new-password" name="student_password" id="sca-new-student-password">
          </div>
          <div>
            <label class="field-label">School</label>
            <input type="text" class="input-custom w-full" x-model="newStudent.school">
          </div>
          <div>
            <label class="field-label">Access months</label>
            <input type="number" min="0" class="input-custom w-full" x-model.number="newStudent.months">
          </div>
        </div>
      </div>

      <div class="sca-section mb-0 rounded-2xl border border-slate-200/70 bg-white/80 p-4 shadow-sm">
        <div class="sca-section__head flex-wrap">
          <span class="flex items-center gap-2"><span class="lms-icon-tile bg-blue-50 text-blue-600"><i class="bi bi-diagram-3"></i></span> LMS content access</span>
          <span class="sca-perm-count ml-auto" x-text="activePermCount"></span>
        </div>
        <div class="sca-access-card-grid grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <div class="sca-access-card rounded-xl border border-slate-200/70 bg-white/90 p-4 shadow-sm">
            <div class="sca-access-card__top">
              <span class="lms-icon-tile bg-blue-50 text-blue-600" style="width:1.75rem;height:1.75rem;border-radius:0.5rem;font-size:0.8rem"><i class="bi bi-collection-play"></i></span>
              <span class="sca-access-card__title">LMS Content</span>
            </div>
            <span class="sca-access-card__status" :class="(hasFullLms || activePermissionList.some(p => ['subject','lesson','quiz','video','handout'].includes(p.content_type))) ? 'is-on' : ''"
                  x-text="hasFullLms ? 'Full LMS' : (activePermissionList.some(p => ['subject','lesson','quiz','video','handout'].includes(p.content_type)) ? 'Scoped' : 'None')"></span>
            <span class="sca-access-card__meta" x-text="hasFullLms ? 'All subjects unlocked' : 'Subjects & topics below'"></span>
            <span class="sca-access-card__action">Configure in tree</span>
          </div>
          <div class="sca-access-card rounded-xl border border-slate-200/70 bg-white/90 p-4 shadow-sm">
            <div class="sca-access-card__top">
              <span class="lms-icon-tile bg-violet-50 text-violet-600" style="width:1.75rem;height:1.75rem;border-radius:0.5rem;font-size:0.8rem"><i class="bi bi-clipboard2-check"></i></span>
              <span class="sca-access-card__title">Preboards</span>
            </div>
            <span class="sca-access-card__status" :class="(hasFullLms || activePermissionList.some(p => String(p.content_type || '').indexOf('preboard') === 0)) ? 'is-on' : ''"
                  x-text="hasFullLms ? 'Included' : (activePermissionList.some(p => String(p.content_type || '').indexOf('preboard') === 0) ? 'Granted' : 'None')"></span>
            <span class="sca-access-card__meta">Preboard subjects & sets</span>
            <span class="sca-access-card__action">Configure in tree</span>
          </div>
          <div class="sca-access-card rounded-xl border border-slate-200/70 bg-white/90 p-4 shadow-sm">
            <div class="sca-access-card__top">
              <span class="lms-icon-tile bg-amber-50 text-amber-600" style="width:1.75rem;height:1.75rem;border-radius:0.5rem;font-size:0.8rem"><i class="bi bi-calendar-week"></i></span>
              <span class="sca-access-card__title">Pre-week</span>
            </div>
            <span class="sca-access-card__status" :class="(hasFullLms || activePermissionList.some(p => String(p.content_type || '').indexOf('preweek') === 0)) ? 'is-on' : ''"
                  x-text="hasFullLms ? 'Included' : (activePermissionList.some(p => String(p.content_type || '').indexOf('preweek') === 0) ? 'Granted' : 'None')"></span>
            <span class="sca-access-card__meta">Units & topics</span>
            <span class="sca-access-card__action">Configure in tree</span>
          </div>
          <div class="sca-access-card rounded-xl border border-slate-200/70 bg-white/90 p-4 shadow-sm">
            <div class="sca-access-card__top">
              <span class="lms-icon-tile bg-emerald-50 text-emerald-600" style="width:1.75rem;height:1.75rem;border-radius:0.5rem;font-size:0.8rem"><i class="bi bi-bank"></i></span>
              <span class="sca-access-card__title">Test Bank</span>
            </div>
            <span class="sca-access-card__status" :class="(hasFullLms || activePermissionList.some(p => p.content_type === 'test_bank')) ? 'is-on' : ''"
                  x-text="hasFullLms ? 'Included' : (activePermissionList.some(p => p.content_type === 'test_bank') ? 'Granted' : 'None')"></span>
            <span class="sca-access-card__meta">Selected bank items</span>
            <span class="sca-access-card__action">Configure in tree</span>
          </div>
        </div>
        <p class="text-xs text-gray-500 mb-3">Choose specific subjects, lessons, preboards, pre-week, or test bank - or enable Full LMS for everything.</p>
        <?php $scaTreeScope = 'create'; require __DIR__ . '/includes/admin_sca_permission_tree.php'; ?>
      </div>
      </div>

      <div class="sca-sticky-actions">
        <button type="button" class="sca-btn sca-btn--outline" @click="cancelCreate()" :disabled="saveAction !== null">Cancel</button>
        <button type="button" class="sca-btn sca-btn--primary" @click="createStudent()" :disabled="saveAction !== null">
          <i class="bi" :class="saveAction === 'create' ? 'bi-arrow-repeat sca-btn__spin' : 'bi-plus-circle'"></i>
          <span x-text="saveAction === 'create' ? 'Creating...' : 'Create Student'"></span>
        </button>
        <span class="sca-sticky-flash" x-show="stickyFlash" x-cloak
              :class="stickyFlashType === 'ok' ? 'sca-sticky-flash--ok' : 'sca-sticky-flash--err'"
              x-text="stickyFlash" role="status"></span>
      </div>
    </section>
    </div>

    <!-- Bulk assign panel -->
    <div class="sca-workspace-overlay" :class="{ 'is-open': panelMode === 'bulk' }" x-show="panelMode === 'bulk'" x-cloak x-teleport="body" role="dialog" aria-modal="true" aria-labelledby="scaBulkTitle" @click.self="closeWorkspace()">
    <section class="sca-workspace-dialog sca-panel">
      <div x-show="panelLoading" class="sca-panel-loading" x-cloak>
        <span class="sca-spinner"></span>
        <span class="text-sm font-semibold text-gray-600" x-text="panelLoadingLabel"></span>
      </div>

      <header class="sca-workspace-head">
        <div>
          <h2 id="scaBulkTitle">Bulk assign access</h2>
          <p>Give the same LMS content access to multiple students in one save.</p>
        </div>
        <button type="button" class="sca-btn sca-btn--ghost" @click="cancelBulk()" aria-label="Close"><i class="bi bi-x-lg"></i></button>
      </header>
      <div class="sca-workspace-body">

      <div class="sca-section rounded-2xl border border-slate-200/70 bg-white/80 p-4 shadow-sm">
        <div class="sca-section__head"><span class="lms-icon-tile bg-emerald-50 text-emerald-600"><i class="bi bi-people"></i></span> Selected students (<span x-text="selectedIds.length"></span>)</div>
        <div class="sca-bulk-chips">
          <template x-for="s in selectedStudents" :key="'bulk-chip-' + s.user_id">
            <span class="sca-bulk-chip">
              <span x-text="s.full_name"></span>
              <button type="button" @click="removeFromBulk(s.user_id)" title="Remove">&times;</button>
            </span>
          </template>
        </div>
        <p class="text-xs text-gray-500 m-0 mt-2">Tip: use the checkboxes in the student list to add or remove students.</p>
      </div>

      <div class="sca-section mb-0 rounded-2xl border border-slate-200/70 bg-white/80 p-4 shadow-sm">
        <div class="sca-section__head flex-wrap">
          <span class="flex items-center gap-2"><span class="lms-icon-tile bg-blue-50 text-blue-600"><i class="bi bi-diagram-3"></i></span> LMS content access to apply</span>
          <span class="sca-perm-count ml-auto" x-text="activePermCount"></span>
        </div>
        <div class="sca-access-card-grid grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <div class="sca-access-card rounded-xl border border-slate-200/70 bg-white/90 p-4 shadow-sm">
            <div class="sca-access-card__top">
              <span class="lms-icon-tile bg-blue-50 text-blue-600" style="width:1.75rem;height:1.75rem;border-radius:0.5rem;font-size:0.8rem"><i class="bi bi-collection-play"></i></span>
              <span class="sca-access-card__title">LMS Content</span>
            </div>
            <span class="sca-access-card__status" :class="(hasFullLms || activePermissionList.some(p => ['subject','lesson','quiz','video','handout'].includes(p.content_type))) ? 'is-on' : ''"
                  x-text="hasFullLms ? 'Full LMS' : (activePermissionList.some(p => ['subject','lesson','quiz','video','handout'].includes(p.content_type)) ? 'Scoped' : 'None')"></span>
            <span class="sca-access-card__meta">Subjects & topics</span>
            <span class="sca-access-card__action">Configure in tree</span>
          </div>
          <div class="sca-access-card rounded-xl border border-slate-200/70 bg-white/90 p-4 shadow-sm">
            <div class="sca-access-card__top">
              <span class="lms-icon-tile bg-violet-50 text-violet-600" style="width:1.75rem;height:1.75rem;border-radius:0.5rem;font-size:0.8rem"><i class="bi bi-clipboard2-check"></i></span>
              <span class="sca-access-card__title">Preboards</span>
            </div>
            <span class="sca-access-card__status" :class="(hasFullLms || activePermissionList.some(p => String(p.content_type || '').indexOf('preboard') === 0)) ? 'is-on' : ''"
                  x-text="hasFullLms ? 'Included' : (activePermissionList.some(p => String(p.content_type || '').indexOf('preboard') === 0) ? 'Granted' : 'None')"></span>
            <span class="sca-access-card__meta">Preboard subjects & sets</span>
            <span class="sca-access-card__action">Configure in tree</span>
          </div>
          <div class="sca-access-card rounded-xl border border-slate-200/70 bg-white/90 p-4 shadow-sm">
            <div class="sca-access-card__top">
              <span class="lms-icon-tile bg-amber-50 text-amber-600" style="width:1.75rem;height:1.75rem;border-radius:0.5rem;font-size:0.8rem"><i class="bi bi-calendar-week"></i></span>
              <span class="sca-access-card__title">Pre-week</span>
            </div>
            <span class="sca-access-card__status" :class="(hasFullLms || activePermissionList.some(p => String(p.content_type || '').indexOf('preweek') === 0)) ? 'is-on' : ''"
                  x-text="hasFullLms ? 'Included' : (activePermissionList.some(p => String(p.content_type || '').indexOf('preweek') === 0) ? 'Granted' : 'None')"></span>
            <span class="sca-access-card__meta">Units & topics</span>
            <span class="sca-access-card__action">Configure in tree</span>
          </div>
          <div class="sca-access-card rounded-xl border border-slate-200/70 bg-white/90 p-4 shadow-sm">
            <div class="sca-access-card__top">
              <span class="lms-icon-tile bg-emerald-50 text-emerald-600" style="width:1.75rem;height:1.75rem;border-radius:0.5rem;font-size:0.8rem"><i class="bi bi-bank"></i></span>
              <span class="sca-access-card__title">Test Bank</span>
            </div>
            <span class="sca-access-card__status" :class="(hasFullLms || activePermissionList.some(p => p.content_type === 'test_bank')) ? 'is-on' : ''"
                  x-text="hasFullLms ? 'Included' : (activePermissionList.some(p => p.content_type === 'test_bank') ? 'Granted' : 'None')"></span>
            <span class="sca-access-card__meta">Selected bank items</span>
            <span class="sca-access-card__action">Configure in tree</span>
          </div>
        </div>
        <p class="text-xs text-gray-500 mb-3">This replaces the current content permissions for every selected student.</p>
        <?php $scaTreeScope = 'bulk'; require __DIR__ . '/includes/admin_sca_permission_tree.php'; ?>
      </div>
      </div>

      <div class="sca-sticky-actions">
        <button type="button" class="sca-btn sca-btn--outline" @click="cancelBulk()" :disabled="saveAction !== null">Cancel</button>
        <button type="button" class="sca-btn sca-btn--primary" @click="saveBulkPermissions()" :disabled="saveAction !== null || selectedIds.length === 0">
          <i class="bi" :class="saveAction === 'bulk' ? 'bi-arrow-repeat sca-btn__spin' : 'bi-shield-check'"></i>
          <span x-text="saveAction === 'bulk' ? 'Applying...' : ('Apply to ' + selectedIds.length + ' student' + (selectedIds.length === 1 ? '' : 's'))"></span>
        </button>
        <span class="sca-sticky-flash" x-show="stickyFlash" x-cloak
              :class="stickyFlashType === 'ok' ? 'sca-sticky-flash--ok' : 'sca-sticky-flash--err'"
              x-text="stickyFlash" role="status"></span>
      </div>
    </section>
    </div>

    <!-- Edit panel -->
    <div class="sca-workspace-overlay" :class="{ 'is-open': panelMode === 'edit' && selectedId }" x-show="panelMode === 'edit' && selectedId" x-cloak x-teleport="body" role="dialog" aria-modal="true" aria-labelledby="scaEditTitle" @click.self="closeWorkspace()">
    <section class="sca-workspace-dialog sca-panel">
      <div x-show="panelLoading" class="sca-panel-loading" x-cloak>
        <span class="sca-spinner"></span>
        <span class="text-sm font-semibold text-gray-600" x-text="panelLoadingLabel"></span>
      </div>

      <header class="sca-workspace-head">
        <div>
          <h2 id="scaEditTitle">Manage Student Access</h2>
          <p x-text="student ? (student.full_name + ' • ' + student.email) : 'Loading…'"></p>
        </div>
        <button type="button" class="sca-btn sca-btn--ghost" @click="closeWorkspace()" aria-label="Close"><i class="bi bi-x-lg"></i></button>
      </header>
      <div class="sca-workspace-body">

      <template x-if="student">
        <div>
          <div class="mb-4 flex flex-wrap items-start justify-between gap-3 relative overflow-hidden rounded-2xl border border-blue-100/80 bg-gradient-to-br from-white via-blue-50/70 to-indigo-50/80 p-4 shadow-[0_10px_30px_rgba(15,23,42,0.07),0_2px_10px_rgba(37,99,235,0.08)] ring-1 ring-white/70">
            <div class="pointer-events-none absolute -right-10 -top-10 h-32 w-32 rounded-full bg-blue-400/15 blur-2xl" aria-hidden="true"></div>
            <div class="relative z-[1] flex items-start gap-3">
              <span class="sca-student-item__avatar sca-student-item__avatar--lg shadow-[0_8px_20px_rgba(37,99,235,0.25)] ring-2 ring-white" x-text="initials(student.full_name)"></span>
              <div>
                <p class="mb-0.5 text-[10px] font-bold uppercase tracking-[0.14em] text-blue-600/80">Selected student</p>
                <h2 class="text-xl font-bold text-slate-900 m-0" x-text="student.full_name"></h2>
                <p class="text-sm text-slate-600 m-0 mt-0.5" x-text="student.email"></p>
                <div class="mt-2 flex flex-wrap items-center gap-1.5">
                  <span class="sca-access-pill">
                    <i class="bi bi-calendar3"></i>
                    <span x-text="(student.access_start || '-') + ' → ' + (student.access_end || '-')"></span>
                  </span>
                  <span class="inline-flex items-center rounded-full px-2.5 py-1 text-[11px] font-semibold ring-1 ring-white/70"
                        :class="hasFullLms ? 'bg-emerald-50 text-emerald-700' : (activePermCount > 0 ? 'bg-blue-50 text-blue-700' : 'bg-slate-100 text-slate-600')"
                        x-text="hasFullLms ? 'Full LMS access' : (activePermCount > 0 ? (activePermCount + ' entitlement' + (activePermCount === 1 ? '' : 's')) : 'No content access')"></span>
                </div>
              </div>
            </div>
            <span class="relative z-[1] sca-badge" :class="'sca-badge--' + (student.status || 'pending')" x-text="(student.status || '').toUpperCase()"></span>
          </div>

          <div class="sca-section rounded-2xl border border-white/80 bg-white/90 p-3.5 shadow-[0_8px_24px_rgba(15,23,42,0.05)] backdrop-blur-lg">
            <div class="sca-section__head mb-2"><span class="lms-icon-tile bg-emerald-50 text-emerald-600"><i class="bi bi-person-vcard"></i></span> Account details</div>
            <div class="sca-form-grid mb-2.5">
              <div>
                <label class="field-label">Full name</label>
                <input type="text" class="input-custom w-full" x-model="edit.full_name" :disabled="saveAction !== null">
              </div>
              <div>
                <label class="field-label">Email</label>
                <input type="text" class="input-custom w-full" x-model="edit.email" :disabled="saveAction !== null">
              </div>
              <div>
                <label class="field-label">School</label>
                <input type="text" class="input-custom w-full" x-model="edit.school" :disabled="saveAction !== null">
              </div>
              <div>
                <label class="field-label">Status</label>
                <select class="input-custom w-full" x-model="edit.status" :disabled="saveAction !== null">
                  <option value="approved">Approved (active)</option>
                  <option value="pending">Pending</option>
                  <option value="rejected">Rejected (deactivated)</option>
                </select>
              </div>
              <div>
                <label class="field-label">New password <span class="normal-case font-normal text-gray-400">(optional)</span></label>
                <input type="password" class="input-custom w-full" x-model="edit.password" placeholder="Leave blank to keep current" autocomplete="new-password" name="student_new_password" id="sca-edit-student-password" :disabled="saveAction !== null">
              </div>
              <div>
                <label class="field-label">Extend access (+ months)</label>
                <input type="number" min="0" class="input-custom w-full" x-model.number="edit.extend_months" :disabled="saveAction !== null">
              </div>
            </div>
            <button type="button" class="sca-btn sca-btn--outline" @click="saveStudent()" :disabled="saveAction !== null">
              <i class="bi" :class="saveAction === 'account' ? 'bi-arrow-repeat sca-btn__spin' : 'bi-save'"></i>
              <span x-text="saveAction === 'account' ? 'Saving...' : 'Save account details'"></span>
            </button>
          </div>

          <div class="sca-section mb-0 rounded-2xl border border-white/80 bg-white/90 p-4 shadow-[0_8px_24px_rgba(15,23,42,0.05)] backdrop-blur-lg">
            <div class="sca-section__head flex-wrap">
              <span class="flex items-center gap-2"><span class="lms-icon-tile bg-blue-50 text-blue-600"><i class="bi bi-diagram-3"></i></span> Access overview</span>
              <span class="sca-perm-count ml-auto" x-text="activePermCount"></span>
            </div>
            <div class="sca-access-card-grid grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
              <div class="sca-access-card relative overflow-hidden rounded-2xl border border-blue-100/80 bg-gradient-to-br from-white to-blue-50/50 p-4 shadow-[0_10px_30px_rgba(15,23,42,0.07),0_2px_10px_rgba(37,99,235,0.08)]">
                <div class="pointer-events-none absolute -right-6 -top-6 h-16 w-16 rounded-full bg-blue-400/20 blur-xl" aria-hidden="true"></div>
                <div class="sca-access-card__top relative z-[1]">
                  <span class="lms-icon-tile bg-gradient-to-br from-blue-100 to-blue-50 text-blue-600 shadow-sm ring-1 ring-white" style="width:1.75rem;height:1.75rem;border-radius:0.5rem;font-size:0.8rem"><i class="bi bi-collection-play"></i></span>
                  <span class="sca-access-card__title">LMS Content</span>
                </div>
                <span class="sca-access-card__status relative z-[1]" :class="(hasFullLms || activePermissionList.some(p => ['subject','lesson','quiz','video','handout'].includes(p.content_type))) ? 'is-on' : ''"
                      x-text="hasFullLms ? 'Full LMS' : (activePermissionList.some(p => ['subject','lesson','quiz','video','handout'].includes(p.content_type)) ? 'Scoped' : 'None')"></span>
                <span class="sca-access-card__meta relative z-[1]">
                  <span x-text="(student.access_start || '-') + ' → ' + (student.access_end || '-')"></span>
                </span>
                <span class="sca-access-card__action relative z-[1]">Edit below · extend via account</span>
              </div>
              <div class="sca-access-card relative overflow-hidden rounded-2xl border border-violet-100/80 bg-gradient-to-br from-white to-violet-50/50 p-4 shadow-[0_10px_30px_rgba(15,23,42,0.07),0_2px_10px_rgba(139,92,246,0.08)]">
                <div class="pointer-events-none absolute -right-6 -top-6 h-16 w-16 rounded-full bg-violet-400/20 blur-xl" aria-hidden="true"></div>
                <div class="sca-access-card__top relative z-[1]">
                  <span class="lms-icon-tile bg-gradient-to-br from-violet-100 to-violet-50 text-violet-600 shadow-sm ring-1 ring-white" style="width:1.75rem;height:1.75rem;border-radius:0.5rem;font-size:0.8rem"><i class="bi bi-clipboard2-check"></i></span>
                  <span class="sca-access-card__title">Preboards</span>
                </div>
                <span class="sca-access-card__status relative z-[1]" :class="(hasFullLms || activePermissionList.some(p => String(p.content_type || '').indexOf('preboard') === 0)) ? 'is-on' : ''"
                      x-text="hasFullLms ? 'Included' : (activePermissionList.some(p => String(p.content_type || '').indexOf('preboard') === 0) ? 'Granted' : 'None')"></span>
                <span class="sca-access-card__meta relative z-[1]">Preboard subjects & sets</span>
                <span class="sca-access-card__action relative z-[1]">Configure in tree</span>
              </div>
              <div class="sca-access-card relative overflow-hidden rounded-2xl border border-amber-100/80 bg-gradient-to-br from-white to-amber-50/50 p-4 shadow-[0_10px_30px_rgba(15,23,42,0.07),0_2px_10px_rgba(245,158,11,0.08)]">
                <div class="pointer-events-none absolute -right-6 -top-6 h-16 w-16 rounded-full bg-amber-400/20 blur-xl" aria-hidden="true"></div>
                <div class="sca-access-card__top relative z-[1]">
                  <span class="lms-icon-tile bg-gradient-to-br from-amber-100 to-amber-50 text-amber-600 shadow-sm ring-1 ring-white" style="width:1.75rem;height:1.75rem;border-radius:0.5rem;font-size:0.8rem"><i class="bi bi-calendar-week"></i></span>
                  <span class="sca-access-card__title">Pre-week</span>
                </div>
                <span class="sca-access-card__status relative z-[1]" :class="(hasFullLms || activePermissionList.some(p => String(p.content_type || '').indexOf('preweek') === 0)) ? 'is-on' : ''"
                      x-text="hasFullLms ? 'Included' : (activePermissionList.some(p => String(p.content_type || '').indexOf('preweek') === 0) ? 'Granted' : 'None')"></span>
                <span class="sca-access-card__meta relative z-[1]">Units & topics</span>
                <span class="sca-access-card__action relative z-[1]">Configure in tree</span>
              </div>
              <div class="sca-access-card relative overflow-hidden rounded-2xl border border-emerald-100/80 bg-gradient-to-br from-white to-emerald-50/50 p-4 shadow-[0_10px_30px_rgba(15,23,42,0.07),0_2px_10px_rgba(16,185,129,0.08)]">
                <div class="pointer-events-none absolute -right-6 -top-6 h-16 w-16 rounded-full bg-emerald-400/20 blur-xl" aria-hidden="true"></div>
                <div class="sca-access-card__top relative z-[1]">
                  <span class="lms-icon-tile bg-gradient-to-br from-emerald-100 to-emerald-50 text-emerald-600 shadow-sm ring-1 ring-white" style="width:1.75rem;height:1.75rem;border-radius:0.5rem;font-size:0.8rem"><i class="bi bi-bank"></i></span>
                  <span class="sca-access-card__title">Test Bank</span>
                </div>
                <span class="sca-access-card__status relative z-[1]" :class="(hasFullLms || activePermissionList.some(p => p.content_type === 'test_bank')) ? 'is-on' : ''"
                      x-text="hasFullLms ? 'Included' : (activePermissionList.some(p => p.content_type === 'test_bank') ? 'Granted' : 'None')"></span>
                <span class="sca-access-card__meta relative z-[1]">Selected bank items</span>
                <span class="sca-access-card__action relative z-[1]">Configure in tree</span>
              </div>
            </div>
            <?php $scaTreeScope = 'edit'; require __DIR__ . '/includes/admin_sca_permission_tree.php'; ?>
          </div>
        </div>
      </template>

      <div class="flex flex-col items-center justify-center py-8 text-gray-400" x-show="loadingStudent && !student">
        <span class="sca-spinner mb-3"></span>
        <span class="text-sm font-medium">Loading student...</span>
      </div>
      </div>

      <div class="sca-sticky-actions" x-show="student" x-cloak>
        <button type="button" class="sca-btn sca-btn--outline" @click="closeWorkspace()" :disabled="saveAction !== null">Cancel</button>
        <button type="button" class="sca-btn sca-btn--primary" @click="savePermissions()" :disabled="saveAction !== null">
          <i class="bi" :class="saveAction === 'permissions' ? 'bi-arrow-repeat sca-btn__spin' : 'bi-shield-check'"></i>
          <span x-text="saveAction === 'permissions' ? 'Saving...' : 'Save Access'"></span>
        </button>
        <a class="sca-btn sca-btn--ghost no-underline" :href="'admin_student_view?id=' + selectedId">
          <i class="bi bi-person-lines-fill"></i> View profile
        </a>
        <span class="sca-sticky-flash" x-show="stickyFlash" x-cloak
              :class="stickyFlashType === 'ok' ? 'sca-sticky-flash--ok' : 'sca-sticky-flash--err'"
              x-text="stickyFlash" role="status"></span>
      </div>
    </section>
    </div>

  </div>
</div>
</main>
</body>
</html>

