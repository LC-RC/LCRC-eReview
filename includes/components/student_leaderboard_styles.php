<style>
  .leaderboard-preview-panel { margin-top: 0; }
  .leaderboard-preview-list {
    list-style: none;
    margin: 0;
    padding: 0;
    display: grid;
    gap: 0.45rem;
  }
  .leaderboard-preview-item {
    display: flex;
    flex-wrap: wrap;
    align-items: baseline;
    justify-content: space-between;
    gap: 0.35rem 0.75rem;
    padding: 0.65rem 0.85rem;
    border-radius: 0.75rem;
    border: 1px solid rgba(22, 101, 160, 0.1);
    background: #f8fbff;
  }
  html[data-student-theme="dark"] .leaderboard-preview-item {
    background: rgba(30, 41, 59, 0.55);
    border-color: rgba(148, 163, 184, 0.14);
  }
  .leaderboard-preview-item.is-you {
    border-color: rgba(22, 101, 160, 0.35);
    background: linear-gradient(180deg, rgba(238, 246, 255, 0.95), #fff);
  }
  html[data-student-theme="dark"] .leaderboard-preview-item.is-you {
    background: rgba(30, 58, 95, 0.45);
  }
  .leaderboard-preview-item .name {
    font-weight: 800;
    color: #143d59;
    font-size: 0.9rem;
  }
  html[data-student-theme="dark"] .leaderboard-preview-item .name {
    color: #e2e8f0;
  }
  .leaderboard-preview-item .score {
    font-weight: 800;
    color: #1665a0;
    font-variant-numeric: tabular-nums;
  }
  .leaderboard-standing-card {
    border-radius: 1rem;
    border: 1px solid rgba(22, 101, 160, 0.14);
    background: linear-gradient(180deg, #f4f8fe, #fff);
    padding: 1rem 1.1rem;
    margin-bottom: 1rem;
  }
  html[data-student-theme="dark"] .leaderboard-standing-card {
    background: rgba(15, 23, 42, 0.72);
    border-color: rgba(148, 163, 184, 0.18);
  }
  .leaderboard-standing-card h2 {
    margin: 0 0 0.35rem;
    font-size: 1rem;
    font-weight: 800;
    color: #143d59;
  }
  html[data-student-theme="dark"] .leaderboard-standing-card h2 {
    color: #e2e8f0;
  }
  .leaderboard-standing-rank {
    font-size: 1.35rem;
    font-weight: 900;
    color: #1665a0;
    margin: 0 0 0.25rem;
  }
  .leaderboard-standing-meta {
    font-size: 0.88rem;
    color: #64748b;
    margin: 0;
  }
  .leaderboard-standing-gap {
    margin: 0.55rem 0 0;
    font-size: 0.82rem;
    font-weight: 700;
    color: #143d59;
  }
  html[data-student-theme="dark"] .leaderboard-standing-gap {
    color: #cbd5e1;
  }
  .leaderboard-tabs {
    display: inline-flex;
    flex-wrap: wrap;
    gap: 0.35rem;
    margin-bottom: 1rem;
  }
  .leaderboard-tab {
    display: inline-flex;
    align-items: center;
    padding: 0.45rem 0.95rem;
    border-radius: 999px;
    font-size: 0.82rem;
    font-weight: 800;
    color: #64748b;
    text-decoration: none;
    border: 1px solid rgba(22, 101, 160, 0.12);
    background: #fff;
  }
  .leaderboard-tab.is-active {
    color: #fff;
    background: linear-gradient(135deg, #1665a0, #143d59);
    border-color: transparent;
  }
  .leaderboard-table-wrap {
    overflow-x: auto;
  }
  .leaderboard-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 520px;
  }
  .leaderboard-table th,
  .leaderboard-table td {
    padding: 0.65rem 0.75rem;
    text-align: left;
    border-bottom: 1px solid rgba(22, 101, 160, 0.1);
    font-size: 0.88rem;
  }
  .leaderboard-table th {
    font-size: 0.72rem;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: #64748b;
    font-weight: 800;
  }
  .leaderboard-table tr.is-viewer {
    background: rgba(22, 101, 160, 0.08);
  }
  html[data-student-theme="dark"] .leaderboard-table tr.is-viewer {
    background: rgba(51, 147, 255, 0.12);
  }
  .leaderboard-table .rank-col {
    width: 4rem;
    font-weight: 900;
    color: #1665a0;
    font-variant-numeric: tabular-nums;
  }
  .leaderboard-table .xp-col {
    font-weight: 800;
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
  }
  .leaderboard-pagination {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: center;
    gap: 0.35rem;
    margin-top: 1rem;
  }
  .leaderboard-pagination a,
  .leaderboard-pagination span {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 2rem;
    height: 2rem;
    padding: 0 0.55rem;
    border-radius: 0.55rem;
    font-size: 0.82rem;
    font-weight: 700;
    text-decoration: none;
    color: #1665a0;
    border: 1px solid rgba(22, 101, 160, 0.14);
    background: #fff;
  }
  .leaderboard-pagination span.is-current {
    color: #fff;
    background: #1665a0;
    border-color: #1665a0;
  }
  .leaderboard-pagination span.is-disabled {
    opacity: 0.45;
    pointer-events: none;
  }
</style>
