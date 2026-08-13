/**
 * Content Hub drag-and-drop reorder (lessons / quizzes).
 * Expects a form#content-reorder-form with tbody#content-reorder-list
 * and hidden inputs name="ordered_ids[]".
 */
(function () {
  'use strict';

  function refreshOrderNumbers(tbody) {
    var rows = tbody.querySelectorAll('tr[data-id]');
    rows.forEach(function (row, idx) {
      var input = row.querySelector('input[name="ordered_ids[]"]');
      if (input) input.value = row.getAttribute('data-id') || '';
      // Absolute student order from full DOM order (filtered rows stay in place).
      var num = row.querySelector('[data-order-num]');
      if (num) num.textContent = String(idx + 1);
    });
  }

  function bindList(tbody) {
    if (!tbody) return;
    if (tbody._contentReorderBound) {
      refreshOrderNumbers(tbody);
      return;
    }
    tbody._contentReorderBound = true;
    var dragRow = null;

    tbody.addEventListener('dragstart', function (e) {
      var row = e.target.closest('tr[data-id]');
      if (!row || !tbody.contains(row)) return;
      if (row.hasAttribute('hidden') || row.classList.contains('is-filter-hidden')) {
        e.preventDefault();
        return;
      }
      dragRow = row;
      row.classList.add('is-dragging');
      try {
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', row.getAttribute('data-id') || '');
      } catch (err) {}
    });

    tbody.addEventListener('dragend', function () {
      if (dragRow) dragRow.classList.remove('is-dragging');
      tbody.querySelectorAll('.is-drag-over').forEach(function (el) {
        el.classList.remove('is-drag-over');
      });
      dragRow = null;
      refreshOrderNumbers(tbody);
    });

    tbody.addEventListener('dragover', function (e) {
      e.preventDefault();
      var row = e.target.closest('tr[data-id]');
      if (!row || !dragRow || row === dragRow) return;
      if (row.hasAttribute('hidden') || row.classList.contains('is-filter-hidden')) return;
      tbody.querySelectorAll('.is-drag-over').forEach(function (el) {
        if (el !== row) el.classList.remove('is-drag-over');
      });
      row.classList.add('is-drag-over');
      var rect = row.getBoundingClientRect();
      var before = (e.clientY - rect.top) < rect.height / 2;
      if (before) {
        tbody.insertBefore(dragRow, row);
      } else {
        tbody.insertBefore(dragRow, row.nextSibling);
      }
    });

    tbody.addEventListener('drop', function (e) {
      e.preventDefault();
      refreshOrderNumbers(tbody);
    });
  }

  function clearModalFilter(root) {
    if (!root) return;
    var input = root.querySelector('[data-reorder-filter]');
    if (input) input.value = '';
    var tbody = root.querySelector('#content-reorder-list');
    if (!tbody) return;
    tbody.querySelectorAll('tr[data-id]').forEach(function (row) {
      row.classList.remove('is-filter-hidden');
      row.hidden = false;
    });
    var empty = root.querySelector('[data-reorder-filter-empty]');
    if (empty) empty.hidden = true;
    refreshOrderNumbers(tbody);
  }

  function applyModalFilter(root) {
    if (!root) return;
    var input = root.querySelector('[data-reorder-filter]');
    var tbody = root.querySelector('#content-reorder-list');
    if (!input || !tbody) return;
    var q = (input.value || '').trim().toLowerCase();
    var visible = 0;
    tbody.querySelectorAll('tr[data-id]').forEach(function (row) {
      var hay = (row.getAttribute('data-search') || row.textContent || '').toLowerCase();
      var match = q === '' || hay.indexOf(q) !== -1;
      row.classList.toggle('is-filter-hidden', !match);
      row.hidden = !match;
      if (match) visible += 1;
    });
    var empty = root.querySelector('[data-reorder-filter-empty]');
    if (empty) empty.hidden = visible > 0 || q === '';
    refreshOrderNumbers(tbody);
  }

  function initReorderForm(form) {
    if (!form) return;
    var tbody = form.querySelector('#content-reorder-list') || document.getElementById('content-reorder-list');
    bindList(tbody);
    if (tbody) refreshOrderNumbers(tbody);

    var filter = form.querySelector('[data-reorder-filter]');
    if (filter && !filter._contentReorderFilterBound) {
      filter._contentReorderFilterBound = true;
      filter.addEventListener('input', function () {
        applyModalFilter(form);
      });
    }

    if (!form._contentReorderSubmitBound) {
      form._contentReorderSubmitBound = true;
      form.addEventListener('submit', function () {
        // Always persist full subject order (hidden filter rows must still submit).
        clearModalFilter(form);
      });
    }
  }

  window.AdminContentReorder = {
    init: initReorderForm,
    clearFilter: clearModalFilter,
    applyFilter: applyModalFilter
  };

  document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('content-reorder-form');
    if (form) initReorderForm(form);
  });
})();
