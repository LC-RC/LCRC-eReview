<?php
/**
 * Shared styles for locked LMS content on student pages.
 */
?>
<style>
  .lms-locked-card {
    position: relative;
    opacity: 0.82;
    pointer-events: none;
    filter: grayscale(0.08) saturate(0.85);
  }
  .lms-locked-card::after {
    content: '';
    position: absolute;
    inset: 0;
    border-radius: inherit;
    background: rgba(248, 250, 252, 0.2);
    pointer-events: none;
  }
  html[data-student-theme="dark"] .lms-locked-card::after {
    background: rgba(7, 11, 20, 0.35);
  }
  .lms-lock-badge {
    display: inline-flex;
    align-items: center;
    gap: .35rem;
    padding: .2rem .55rem;
    border-radius: 999px;
    background: #f1f5f9;
    border: 1px solid #cbd5e1;
    color: #475569;
    font-size: .72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .03em;
  }
  html[data-student-theme="dark"] .lms-lock-badge {
    background: var(--student-surface-2, rgba(24, 34, 54, 0.78));
    border-color: var(--student-border, rgba(148, 183, 255, 0.14));
    color: var(--student-text-secondary, #9aa8c0);
  }
  .lms-lock-overlay {
    position: absolute;
    top: .65rem;
    right: .65rem;
    z-index: 2;
  }
</style>
