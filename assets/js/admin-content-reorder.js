/**
 * Content Hub drag-and-drop reorder (lessons / quizzes).
 * Expects a form#content-reorder-form with tbody#content-reorder-list and hidden inputs name="ordered_ids[]".
 */
(function () {
  'use strict';

  function refreshOrderNumbers(tbody) {
    var rows = tbody.querySelectorAll('tr[data-id]');
    rows.forEach(function (row, idx) {
      var num = row.querySelector('[data-order-num]');
      if (num) num.textContent = String(idx + 1);
      var input = row.querySelector('input[name="ordered_ids[]"]');
      if (input) input.value = row.getAttribute('data-id') || '';
    });
  }

  function bindList(tbody) {
    if (!tbody || tbody._contentReorderBound) return;
    tbody._contentReorderBound = true;
    var dragRow = null;

    tbody.addEventListener('dragstart', function (e) {
      var row = e.target.closest('tr[data-id]');
      if (!row || !tbody.contains(row)) return;
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

  document.addEventListener('DOMContentLoaded', function () {
    var tbody = document.getElementById('content-reorder-list');
    if (tbody) {
      bindList(tbody);
      refreshOrderNumbers(tbody);
    }
  });
})();
