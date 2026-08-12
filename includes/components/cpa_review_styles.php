<?php
/** Shared styles for My CPA Review pages + modal. */
?>
<style>
  .cpa-page .rounded-2xl { border-radius: 0.75rem !important; }
  .cpa-page .rounded-xl { border-radius: 0.625rem !important; }
  .cpa-hero {
    background: linear-gradient(90deg, #1665A0 0%, #143D59 100%);
    color: #fff;
  }
  .cpa-last-minute-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.7rem 1.1rem;
    border-radius: 0.75rem;
    font-weight: 700;
    font-size: 0.875rem;
    text-decoration: none;
    color: #143D59 !important;
    background: #fff !important;
    border: 2px solid #F2B01E;
    box-shadow: 0 4px 14px rgba(0, 0, 0, 0.18);
    transition: transform 0.15s ease, background 0.15s ease, box-shadow 0.15s ease;
  }
  .cpa-last-minute-btn:hover {
    background: #FFF8E7 !important;
    color: #143D59 !important;
    transform: translateY(-1px);
    box-shadow: 0 6px 18px rgba(0, 0, 0, 0.22);
  }
  .cpa-last-minute-btn i { color: #C98900; }
  .cpa-count-card {
    display: block;
    border-radius: 0.75rem;
    border: 1px solid rgba(22, 101, 160, 0.12);
    background: linear-gradient(180deg, #f4f8fe 0%, #fff 100%);
    box-shadow: 0 1px 4px rgba(15, 23, 42, 0.08);
    padding: 1rem 1.1rem;
    text-decoration: none;
    color: inherit;
    transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease;
  }
  .cpa-count-card:hover { transform: translateY(-2px); box-shadow: 0 6px 18px rgba(15, 23, 42, 0.1); border-color: rgba(22, 101, 160, 0.28); }
  .cpa-workspace-card { min-height: 8.5rem; }
  .cpa-card-icon {
    display: inline-flex; align-items: center; justify-content: center;
    width: 2.25rem; height: 2.25rem; border-radius: 0.625rem;
    background: rgba(22, 101, 160, 0.1); color: #1665A0; font-size: 1.1rem;
  }
  .cpa-count-card .cpa-count { font-size: 1.75rem; font-weight: 800; color: #143D59; line-height: 1.1; }
  .cpa-count-card .cpa-count-label { font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: rgba(20, 61, 89, 0.65); }
  .cpa-tag {
    display: inline-block; font-size: 0.7rem; font-weight: 700; color: #1665A0;
    background: rgba(22, 101, 160, 0.1); border-radius: 999px; padding: 0.15rem 0.55rem;
  }
  .cpa-note-body p { margin: 0 0 0.5rem; }
  .cpa-note-body p:last-child { margin-bottom: 0; }
  .cpa-toolbar-btn {
    display: inline-flex; align-items: center; gap: 0.4rem;
    padding: 0.5rem 0.85rem; border-radius: 0.5rem; font-size: 0.8125rem; font-weight: 600;
    border: 1px solid rgba(22, 101, 160, 0.2); background: #e8f2fa; color: #143D59; cursor: pointer;
    transition: background 0.15s ease, color 0.15s ease;
  }
  .cpa-toolbar-btn:hover { background: #d4e8f7; }
  .cpa-toolbar-btn.is-active { background: #1665A0; color: #fff; border-color: #1665A0; }
  .cpa-modal[hidden] { display: none !important; }
  .cpa-modal { position: fixed; inset: 0; z-index: 80; display: flex; align-items: center; justify-content: center; padding: 1rem; }
  .cpa-modal__backdrop { position: absolute; inset: 0; background: rgba(15, 23, 42, 0.45); }
  .cpa-modal__panel {
    position: relative; z-index: 1; width: min(560px, 100%); max-height: min(90vh, 720px);
    overflow: auto; background: #fff; border-radius: 0.875rem;
    box-shadow: 0 20px 50px rgba(15, 23, 42, 0.25); border: 1px solid rgba(22, 101, 160, 0.15);
  }
  .cpa-modal__head { display: flex; align-items: center; justify-content: space-between; padding: 1rem 1.15rem; border-bottom: 1px solid rgba(22, 101, 160, 0.1); }
  .cpa-modal__close { border: 0; background: transparent; font-size: 1.5rem; line-height: 1; cursor: pointer; color: #64748b; }
  .cpa-modal__body { padding: 1rem 1.15rem 1.25rem; }
  .cpa-editor {
    min-height: 140px; border: 1px solid rgba(22, 101, 160, 0.25); border-radius: 0.5rem;
    padding: 0.65rem 0.75rem; font-size: 0.875rem; outline: none; background: #fff;
  }
  .cpa-editor:focus { box-shadow: 0 0 0 3px rgba(22, 101, 160, 0.15); }
  .cpa-ed-btn {
    border: 1px solid rgba(22, 101, 160, 0.2); background: #f8fafc; border-radius: 0.375rem;
    padding: 0.2rem 0.5rem; font-size: 0.75rem; cursor: pointer;
  }
  .cpa-ed-btn:hover { background: #e8f2fa; }
  .cpa-pager { display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center; margin-top: 1rem; }
  .cpa-pager a, .cpa-pager span {
    display: inline-flex; padding: 0.35rem 0.7rem; border-radius: 0.5rem; font-size: 0.8125rem; font-weight: 600;
    border: 1px solid rgba(22, 101, 160, 0.2); text-decoration: none; color: #143D59; background: #fff;
  }
  .cpa-pager .is-current { background: #1665A0; color: #fff; border-color: #1665A0; }
  @media (max-width: 640px) {
    .cpa-filters { flex-direction: column; align-items: stretch; }
    .cpa-filters .min-w-\[140px\], .cpa-filters .min-w-\[120px\], .cpa-filters .min-w-\[160px\] { min-width: 0 !important; width: 100%; }
  }
</style>
