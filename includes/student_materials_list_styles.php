<?php
/**
 * Shared styles: Materials list UI (cards/list toggle, toolbar, lesson cards & list).
 * Included by student_subject.php (Materials tab) and student_preweek.php.
 */
if (!defined('EREVIEW_MATERIALS_LIST_STYLES')) {
define('EREVIEW_MATERIALS_LIST_STYLES', true);
?>
<style>
    /* Materials: view toggle (cards / list) */
    .materials-view-toggle {
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
      flex-shrink: 0;
    }
    .materials-view-toggle__label {
      font-size: 0.7rem;
      font-weight: 700;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      color: rgba(20, 61, 89, 0.45);
      display: none;
    }
    @media (min-width: 640px) {
      .materials-view-toggle__label {
        display: block;
      }
    }
    .materials-view-seg {
      display: inline-flex;
      padding: 0.2rem;
      border-radius: 0.875rem;
      background: rgba(255, 255, 255, 0.85);
      border: 1px solid rgba(22, 101, 160, 0.18);
      box-shadow: 0 1px 3px rgba(20, 61, 89, 0.06);
    }
    .materials-view-seg button {
      display: inline-flex;
      align-items: center;
      gap: 0.35rem;
      padding: 0.45rem 0.75rem;
      border: none;
      border-radius: 0.65rem;
      font-size: 0.8125rem;
      font-weight: 700;
      color: rgba(20, 61, 89, 0.55);
      background: transparent;
      cursor: pointer;
      transition: color 0.2s ease, background 0.2s ease, box-shadow 0.2s ease;
    }
    .materials-view-seg button:hover {
      color: #1665A0;
      background: rgba(232, 242, 250, 0.9);
    }
    .materials-view-seg button.is-active {
      color: #fff;
      background: linear-gradient(145deg, #1e7cc8 0%, #1665A0 55%, #124a73 100%);
      box-shadow: 0 2px 10px rgba(22, 101, 160, 0.35);
    }
    .materials-view-seg button i {
      font-size: 1rem;
      opacity: 0.95;
    }
    .materials-view-seg button:hover:not(.is-active) i {
      opacity: 1;
    }

    /* Materials: lesson cards */
    .lesson-cards-wrap {
      padding: 1rem 1rem 1.25rem;
    }
    @media (min-width: 640px) {
      .lesson-cards-wrap {
        padding: 1.25rem 1.5rem 1.5rem;
      }
    }
    .lesson-cards-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(min(100%, 280px), 1fr));
      gap: 1rem 1.125rem;
    }
    .lesson-card {
      position: relative;
      display: flex;
      flex-direction: column;
      min-height: 100%;
      padding: 1rem 1rem 1.125rem;
      border-radius: 1rem;
      text-decoration: none;
      color: inherit;
      background: linear-gradient(165deg, #ffffff 0%, #f5f9fd 55%, #eef5fb 100%);
      border: 1px solid rgba(22, 101, 160, 0.14);
      box-shadow:
        0 1px 0 rgba(255, 255, 255, 0.95) inset,
        0 4px 16px rgba(20, 61, 89, 0.07),
        0 1px 3px rgba(20, 61, 89, 0.05);
      transition: transform 0.22s ease, box-shadow 0.22s ease, border-color 0.22s ease;
      overflow: hidden;
    }
    .lesson-card::before {
      content: "";
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      height: 3px;
      background: linear-gradient(90deg, #1665A0, #3393ff, #1665A0);
      opacity: 0.85;
      transform: scaleX(0.35);
      transform-origin: left;
      transition: transform 0.28s ease;
    }
    .lesson-card:hover {
      transform: translateY(-4px);
      border-color: rgba(22, 101, 160, 0.32);
      box-shadow:
        0 1px 0 rgba(255, 255, 255, 1) inset,
        0 12px 32px rgba(22, 101, 160, 0.14),
        0 4px 12px rgba(20, 61, 89, 0.08);
    }
    .lesson-card:hover::before {
      transform: scaleX(1);
    }
    .lesson-card:focus-visible {
      outline: 2px solid #1665A0;
      outline-offset: 3px;
    }
    .lesson-card__top {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 0.75rem;
      margin-bottom: 0.625rem;
    }
    .lesson-card__badge {
      flex-shrink: 0;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-width: 2.25rem;
      height: 2.25rem;
      padding: 0 0.5rem;
      border-radius: 0.75rem;
      font-size: 0.8125rem;
      font-weight: 800;
      letter-spacing: 0.04em;
      color: #1665A0;
      background: linear-gradient(180deg, #e8f2fa 0%, #dceaf5 100%);
      border: 1px solid rgba(22, 101, 160, 0.2);
      box-shadow: 0 1px 2px rgba(20, 61, 89, 0.06);
    }
    .lesson-card__icon {
      flex-shrink: 0;
      display: flex;
      align-items: center;
      justify-content: center;
      width: 2.5rem;
      height: 2.5rem;
      border-radius: 0.875rem;
      color: #fff;
      background: linear-gradient(145deg, #1e7cc8 0%, #1665A0 50%, #124a73 100%);
      box-shadow: 0 4px 12px rgba(22, 101, 160, 0.35);
      font-size: 1.125rem;
    }
    .lesson-card__title {
      margin: 0;
      font-size: 0.98rem;
      font-weight: 700;
      line-height: 1.4;
      color: #143D59;
      display: -webkit-box;
      -webkit-line-clamp: 3;
      -webkit-box-orient: vertical;
      overflow: hidden;
    }
    .lesson-card__meta {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 0.5rem;
      margin-top: auto;
      padding-top: 0.875rem;
      border-top: 1px solid rgba(22, 101, 160, 0.1);
    }
    .lesson-card__hint {
      font-size: 0.75rem;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.06em;
      color: rgba(20, 61, 89, 0.45);
    }
    .lesson-card__cta {
      display: inline-flex;
      align-items: center;
      gap: 0.35rem;
      font-size: 0.8125rem;
      font-weight: 700;
      color: #1665A0;
    }
    .lesson-card:hover .lesson-card__cta {
      color: #0f4d7a;
    }
    .lesson-card:hover .lesson-card__cta i {
      transform: translateX(3px);
    }
    .lesson-card__cta i {
      font-size: 0.95rem;
      transition: transform 0.2s ease;
    }
    .lesson-empty-state {
      grid-column: 1 / -1;
      text-align: center;
      padding: 2.5rem 1.5rem;
      border-radius: 1rem;
      border: 1px dashed rgba(22, 101, 160, 0.25);
      background: rgba(232, 242, 250, 0.45);
      color: rgba(20, 61, 89, 0.65);
      font-size: 0.9375rem;
      font-weight: 500;
      line-height: 1.5;
    }
    .lesson-empty-state i {
      display: block;
      font-size: 2.25rem;
      color: rgba(22, 101, 160, 0.35);
      margin-bottom: 0.75rem;
    }

    /* Materials: modern list view */
    .lesson-list-wrap {
      padding: 0.75rem 1rem 1.25rem;
    }
    @media (min-width: 640px) {
      .lesson-list-wrap {
        padding: 1rem 1.5rem 1.5rem;
      }
    }
    .lesson-list {
      list-style: none;
      margin: 0;
      padding: 0;
      border-radius: 1rem;
      overflow: hidden;
      background: linear-gradient(180deg, rgba(255, 255, 255, 0.95) 0%, rgba(245, 249, 253, 0.98) 100%);
      border: 1px solid rgba(22, 101, 160, 0.12);
      box-shadow:
        0 1px 0 rgba(255, 255, 255, 1) inset,
        0 4px 20px rgba(20, 61, 89, 0.06);
    }
    .lesson-list__item {
      border-bottom: 1px solid rgba(22, 101, 160, 0.08);
    }
    .lesson-list__item:last-child {
      border-bottom: none;
    }
    .lesson-list__link {
      display: flex;
      align-items: stretch;
      gap: 0.75rem 1rem;
      padding: 0.875rem 1rem;
      text-decoration: none;
      color: inherit;
      transition: background 0.2s ease, box-shadow 0.2s ease;
    }
    @media (min-width: 640px) {
      .lesson-list__link {
        padding: 1rem 1.125rem;
        gap: 1rem 1.25rem;
      }
    }
    .lesson-list__link:hover {
      background: linear-gradient(90deg, rgba(232, 242, 250, 0.65) 0%, rgba(255, 255, 255, 0.4) 100%);
      box-shadow: inset 3px 0 0 #1665A0;
    }
    .lesson-list__link:focus-visible {
      outline: 2px solid #1665A0;
      outline-offset: -2px;
    }
    .lesson-list__idx {
      flex-shrink: 0;
      display: flex;
      align-items: center;
      justify-content: center;
      width: 2.5rem;
      height: 2.5rem;
      border-radius: 0.875rem;
      font-size: 0.8125rem;
      font-weight: 800;
      color: #1665A0;
      background: linear-gradient(180deg, #e8f2fa 0%, #dceaf5 100%);
      border: 1px solid rgba(22, 101, 160, 0.2);
      box-shadow: 0 1px 3px rgba(20, 61, 89, 0.06);
    }
    .lesson-list__body {
      flex: 1;
      min-width: 0;
      display: flex;
      flex-direction: column;
      justify-content: center;
      gap: 0.2rem;
    }
    .lesson-list__title {
      margin: 0;
      font-size: 0.9375rem;
      font-weight: 700;
      line-height: 1.45;
      color: #143D59;
    }
    @media (min-width: 640px) {
      .lesson-list__title {
        font-size: 1rem;
      }
    }
    .lesson-list__sub {
      font-size: 0.75rem;
      font-weight: 600;
      letter-spacing: 0.04em;
      text-transform: uppercase;
      color: rgba(20, 61, 89, 0.42);
    }
    .lesson-list__action {
      flex-shrink: 0;
      display: flex;
      align-items: center;
      align-self: center;
    }
    .lesson-list__pill {
      display: inline-flex;
      align-items: center;
      gap: 0.25rem;
      padding: 0.4rem 0.75rem;
      border-radius: 9999px;
      font-size: 0.75rem;
      font-weight: 700;
      color: #1665A0;
      background: rgba(232, 242, 250, 0.95);
      border: 1px solid rgba(22, 101, 160, 0.2);
      transition: transform 0.2s ease, background 0.2s ease, color 0.2s ease;
    }
    .lesson-list__link:hover .lesson-list__pill {
      background: #1665A0;
      color: #fff;
      border-color: transparent;
      transform: translateX(2px);
    }
    .lesson-list__pill i {
      font-size: 1.1rem;
      transition: transform 0.2s ease;
    }
    .lesson-list__link:hover .lesson-list__pill i {
      transform: translateX(3px);
    }

    .sr-only {
      position: absolute;
      width: 1px;
      height: 1px;
      padding: 0;
      margin: -1px;
      overflow: hidden;
      clip: rect(0, 0, 0, 0);
      white-space: nowrap;
      border: 0;
    }

    /* Materials: search + sort toolbar */
    .materials-toolbar {
      display: flex;
      flex-direction: column;
      gap: 0.75rem;
      padding: 0.75rem 1rem 1rem;
      border-top: 1px solid rgba(22, 101, 160, 0.08);
      background: linear-gradient(180deg, rgba(255, 255, 255, 0.65) 0%, rgba(240, 247, 252, 0.5) 100%);
    }
    @media (min-width: 640px) {
      .materials-toolbar {
        flex-direction: row;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        padding: 0.875rem 1.5rem 1.125rem;
      }
    }
    .materials-search-wrap {
      position: relative;
      flex: 1;
      min-width: 0;
      max-width: 100%;
    }
    @media (min-width: 640px) {
      .materials-search-wrap {
        max-width: 22rem;
      }
    }
    .materials-search-wrap .materials-search-icon {
      position: absolute;
      left: 0.85rem;
      top: 50%;
      transform: translateY(-50%);
      color: rgba(20, 61, 89, 0.38);
      font-size: 1rem;
      pointer-events: none;
    }
    .materials-search-input {
      width: 100%;
      padding: 0.55rem 2.5rem 0.55rem 2.45rem;
      border-radius: 0.75rem;
      border: 1px solid rgba(22, 101, 160, 0.2);
      background: #fff;
      font-size: 0.9375rem;
      color: #143D59;
      box-shadow: 0 1px 2px rgba(20, 61, 89, 0.04);
      transition: border-color 0.2s ease, box-shadow 0.2s ease, background-color 0.2s ease;
    }
    .materials-search-input::placeholder {
      color: rgba(20, 61, 89, 0.42);
    }
    .materials-search-input:hover {
      border-color: rgba(22, 101, 160, 0.32);
    }
    .materials-search-input:focus {
      outline: none;
      border-color: rgba(22, 101, 160, 0.55);
      box-shadow: 0 0 0 3px rgba(22, 101, 160, 0.14);
    }
    .materials-search-clear {
      position: absolute;
      right: 0.4rem;
      top: 50%;
      transform: translateY(-50%);
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 1.85rem;
      height: 1.85rem;
      border: none;
      border-radius: 0.5rem;
      background: rgba(20, 61, 89, 0.06);
      color: rgba(20, 61, 89, 0.55);
      cursor: pointer;
      transition: background 0.15s ease, color 0.15s ease;
    }
    .materials-search-clear:hover {
      background: rgba(22, 101, 160, 0.12);
      color: #1665A0;
    }
    .materials-sort-group {
      display: flex;
      align-items: center;
      gap: 0.5rem;
      flex-shrink: 0;
    }
    .materials-sort-group__label {
      font-size: 0.7rem;
      font-weight: 700;
      letter-spacing: 0.06em;
      text-transform: uppercase;
      color: rgba(20, 61, 89, 0.45);
      white-space: nowrap;
    }
    .materials-sort-select {
      min-width: 10.5rem;
      padding: 0.5rem 2rem 0.5rem 0.65rem;
      border-radius: 0.75rem;
      border: 1px solid rgba(22, 101, 160, 0.2);
      background-color: #fff;
      background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' fill='%23143D59' viewBox='0 0 16 16'%3E%3Cpath d='M8 11L3 6h10l-5 5z'/%3E%3C/svg%3E");
      background-repeat: no-repeat;
      background-position: right 0.6rem center;
      background-size: 0.7rem;
      font-size: 0.875rem;
      font-weight: 600;
      color: #143D59;
      cursor: pointer;
      appearance: none;
      -webkit-appearance: none;
      box-shadow: 0 1px 2px rgba(20, 61, 89, 0.05);
      transition: border-color 0.2s ease, box-shadow 0.2s ease;
    }
    .materials-sort-select:hover {
      border-color: rgba(22, 101, 160, 0.35);
    }
    .materials-sort-select:focus {
      outline: none;
      border-color: rgba(22, 101, 160, 0.55);
      box-shadow: 0 0 0 3px rgba(22, 101, 160, 0.14);
    }
    .materials-results-hint {
      font-size: 0.75rem;
      color: rgba(20, 61, 89, 0.5);
      padding: 0 1rem 0.5rem;
      margin: 0;
    }
    @media (min-width: 640px) {
      .materials-results-hint {
        padding-left: 1.5rem;
        padding-right: 1.5rem;
      }
    }
    .lesson-no-match {
      margin: 0 1rem 1.25rem;
      padding: 1.75rem 1.25rem;
      text-align: center;
      border-radius: 0.875rem;
      border: 1px dashed rgba(22, 101, 160, 0.25);
      background: rgba(232, 242, 250, 0.45);
      color: rgba(20, 61, 89, 0.75);
      font-size: 0.9375rem;
      font-weight: 600;
    }
    .lesson-no-match i {
      display: block;
      font-size: 1.75rem;
      margin-bottom: 0.5rem;
      opacity: 0.55;
      color: #1665A0;
    }
    @media (min-width: 640px) {
      .lesson-no-match {
        margin-left: 1.5rem;
        margin-right: 1.5rem;
      }
    }
</style>
<?php
}