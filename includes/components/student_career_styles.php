<style>
  .career-page {
    padding-bottom: 2.5rem;
  }
  .career-hero-card {
    border-radius: 1.25rem;
    border: 1px solid rgba(22, 101, 160, 0.14);
    background: linear-gradient(135deg, #1665a0 0%, #143d59 100%);
    color: #fff;
    box-shadow: 0 12px 32px rgba(20, 61, 89, 0.28);
    padding: 1.5rem 1.25rem;
  }
  .career-hero-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.85rem 1rem;
    margin-top: 1rem;
  }
  @media (min-width: 640px) {
    .career-hero-grid {
      grid-template-columns: repeat(4, minmax(0, 1fr));
    }
  }
  .career-stat {
    background: rgba(255, 255, 255, 0.1);
    border: 1px solid rgba(255, 255, 255, 0.16);
    border-radius: 0.85rem;
    padding: 0.75rem 0.85rem;
  }
  .career-stat .k {
    display: block;
    font-size: 0.72rem;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    opacity: 0.85;
    margin-bottom: 0.2rem;
  }
  .career-stat .v {
    display: block;
    font-size: 1.15rem;
    font-weight: 800;
    line-height: 1.2;
  }
  .career-panel {
    border-radius: 1rem;
    border: 1px solid rgba(22, 101, 160, 0.12);
    background: #fff;
    padding: 1rem 1.1rem;
    box-shadow: 0 4px 18px rgba(15, 23, 42, 0.04);
  }
  html[data-student-theme="dark"] .career-panel {
    background: rgba(15, 23, 42, 0.72);
    border-color: rgba(148, 163, 184, 0.18);
  }
  .career-panel h2 {
    margin: 0 0 0.85rem;
    font-size: 1rem;
    font-weight: 800;
    color: #143d59;
  }
  html[data-student-theme="dark"] .career-panel h2 {
    color: #e2e8f0;
  }
  .career-progress-bar {
    height: 0.75rem;
    border-radius: 999px;
    background: rgba(22, 101, 160, 0.14);
    overflow: hidden;
    margin: 0.65rem 0 0.45rem;
  }
  .career-progress-bar > i {
    display: block;
    height: 100%;
    border-radius: inherit;
    background: linear-gradient(90deg, #1665a0, #3393ff);
    min-width: 0.35rem;
  }
  .career-progress-meta {
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    gap: 0.35rem 0.75rem;
    font-size: 0.82rem;
    color: #64748b;
  }
  html[data-student-theme="dark"] .career-progress-meta {
    color: #94a3b8;
  }
  .career-history-list,
  .career-ach-list {
    list-style: none;
    margin: 0;
    padding: 0;
    display: grid;
    gap: 0.55rem;
  }
  .career-history-item,
  .career-ach-item {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 0.75rem;
    padding: 0.75rem 0.85rem;
    border-radius: 0.75rem;
    border: 1px solid rgba(22, 101, 160, 0.1);
    background: #f8fbff;
  }
  html[data-student-theme="dark"] .career-history-item,
  html[data-student-theme="dark"] .career-ach-item {
    background: rgba(30, 41, 59, 0.55);
    border-color: rgba(148, 163, 184, 0.14);
  }
  .career-history-item .title,
  .career-ach-item .title {
    font-weight: 700;
    color: #143d59;
    font-size: 0.92rem;
  }
  html[data-student-theme="dark"] .career-history-item .title,
  html[data-student-theme="dark"] .career-ach-item .title {
    color: #e2e8f0;
  }
  .career-history-item .meta,
  .career-ach-item .meta {
    font-size: 0.78rem;
    color: #64748b;
    margin-top: 0.15rem;
  }
  html[data-student-theme="dark"] .career-history-item .meta,
  html[data-student-theme="dark"] .career-ach-item .meta {
    color: #94a3b8;
  }
  .career-xp-badge {
    flex-shrink: 0;
    font-weight: 800;
    font-size: 0.88rem;
    padding: 0.25rem 0.55rem;
    border-radius: 999px;
    background: #eef6ff;
    color: #1665a0;
    white-space: nowrap;
  }
  .career-xp-badge.is-zero {
    background: #f1f5f9;
    color: #64748b;
  }
  html[data-student-theme="dark"] .career-xp-badge {
    background: rgba(51, 147, 255, 0.16);
    color: #93c5fd;
  }
  .career-ach-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 0.65rem;
  }
  @media (min-width: 768px) {
    .career-ach-grid {
      grid-template-columns: repeat(2, minmax(0, 1fr));
    }
  }
  .career-ach-item.is-locked {
    opacity: 0.82;
  }
  .career-ach-item.is-unlocked {
    border-color: rgba(22, 163, 74, 0.25);
    background: linear-gradient(180deg, rgba(240, 253, 244, 0.95), #fff);
  }
  html[data-student-theme="dark"] .career-ach-item.is-unlocked {
    background: rgba(22, 101, 52, 0.18);
    border-color: rgba(74, 222, 128, 0.22);
  }
  .career-ach-icon {
    width: 2rem;
    height: 2rem;
    border-radius: 0.65rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: rgba(22, 101, 160, 0.12);
    color: #1665a0;
    font-size: 1rem;
    margin-right: 0.55rem;
    flex-shrink: 0;
  }
  .career-ach-item.is-unlocked .career-ach-icon {
    background: rgba(22, 163, 74, 0.15);
    color: #15803d;
  }
  .career-ach-body {
    display: flex;
    align-items: flex-start;
    min-width: 0;
    flex: 1;
  }
  .career-ach-progress {
    margin-top: 0.45rem;
    height: 0.45rem;
    border-radius: 999px;
    background: rgba(100, 116, 139, 0.18);
    overflow: hidden;
  }
  .career-ach-progress > i {
    display: block;
    height: 100%;
    background: #1665a0;
    border-radius: inherit;
  }
  .career-empty {
    text-align: center;
    padding: 2rem 1rem;
    color: #64748b;
  }
  .career-link-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    margin-top: 0.65rem;
    font-size: 0.85rem;
    font-weight: 700;
    color: #1665a0;
    text-decoration: none;
  }
  .career-link-btn:hover {
    text-decoration: underline;
  }

  .career-subnav {
    display: inline-flex;
    flex-wrap: wrap;
    gap: 0.35rem;
    padding: 0.25rem;
    border-radius: 999px;
    background: rgba(22, 101, 160, 0.08);
    border: 1px solid rgba(22, 101, 160, 0.12);
  }
  html[data-student-theme="dark"] .career-subnav {
    background: rgba(30, 41, 59, 0.55);
    border-color: rgba(148, 163, 184, 0.16);
  }
  .career-subnav__link {
    display: inline-flex;
    align-items: center;
    padding: 0.45rem 0.95rem;
    border-radius: 999px;
    font-size: 0.82rem;
    font-weight: 800;
    color: #64748b;
    text-decoration: none;
    transition: background 0.15s ease, color 0.15s ease;
  }
  .career-subnav__link:hover {
    color: #1665a0;
    background: rgba(255, 255, 255, 0.65);
    text-decoration: none;
  }
  .career-subnav__link.is-active {
    color: #fff;
    background: linear-gradient(135deg, #1665a0, #143d59);
    box-shadow: 0 4px 12px rgba(20, 61, 89, 0.22);
  }
  html[data-student-theme="dark"] .career-subnav__link.is-active {
    color: #fff;
  }

  .career-reward-banner {
    position: relative;
    margin: 0 0 1rem;
    padding: 0.9rem 2.2rem 0.9rem 1rem;
    border-radius: 0.9rem;
    border: 1px solid rgba(22, 101, 160, 0.22);
    background: linear-gradient(180deg, #f0f7ff, #fff);
    box-shadow: 0 8px 24px rgba(22, 101, 160, 0.12);
  }
  html[data-student-theme="dark"] .career-reward-banner {
    background: rgba(30, 58, 95, 0.55);
    border-color: rgba(96, 165, 250, 0.25);
  }
  .career-reward-banner.is-dismissed {
    opacity: 0;
    transform: translateY(-4px);
    transition: opacity 0.2s ease, transform 0.2s ease;
  }
  .career-reward-levelup {
    display: flex;
    flex-wrap: wrap;
    align-items: baseline;
    gap: 0.35rem 0.65rem;
    margin-bottom: 0.55rem;
    color: #1665a0;
  }
  .career-reward-levelup strong {
    font-size: 0.95rem;
    letter-spacing: 0.03em;
  }
  .career-reward-lines {
    display: grid;
    gap: 0.35rem;
  }
  .career-reward-line {
    display: flex;
    flex-wrap: wrap;
    align-items: baseline;
    gap: 0.35rem 0.55rem;
  }
  .career-reward-xp {
    font-weight: 800;
    color: #1665a0;
    font-size: 0.95rem;
  }
  .career-reward-xp.is-zero {
    color: #64748b;
  }
  .career-reward-label {
    font-weight: 600;
    color: #143d59;
    font-size: 0.88rem;
  }
  html[data-student-theme="dark"] .career-reward-label {
    color: #e2e8f0;
  }
  .career-reward-cap {
    font-size: 0.75rem;
    color: #64748b;
  }
  .career-reward-achievements {
    margin-top: 0.55rem;
    display: grid;
    gap: 0.35rem;
  }
  .career-reward-ach {
    font-size: 0.82rem;
    color: #15803d;
  }
  .career-reward-ach strong {
    margin-right: 0.35rem;
  }
  .career-reward-total {
    margin: 0.55rem 0 0;
    font-size: 0.78rem;
    color: #64748b;
  }
  .career-reward-dismiss {
    position: absolute;
    top: 0.45rem;
    right: 0.45rem;
    width: 1.75rem;
    height: 1.75rem;
    border: 0;
    border-radius: 999px;
    background: rgba(100, 116, 139, 0.12);
    color: #64748b;
    font-size: 1.1rem;
    line-height: 1;
    cursor: pointer;
  }
  .career-reward-dismiss:hover {
    background: rgba(100, 116, 139, 0.22);
  }
</style>
