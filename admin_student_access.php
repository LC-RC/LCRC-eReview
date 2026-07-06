<?php
require_once 'auth.php';
requireRole('admin');
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
    .sca-layout { display: grid; grid-template-columns: minmax(300px, 340px) 1fr; gap: 1.25rem; align-items: start; }
    @media (max-width: 1024px) { .sca-layout { grid-template-columns: 1fr; } }

    .sca-sidebar { position: sticky; top: 1rem; }
    .sca-student-item {
      display: flex; align-items: flex-start; gap: 0.65rem; width: 100%; text-align: left;
      border: 1px solid #e5e7eb; border-radius: 0.75rem;
      padding: 0.75rem 0.85rem; margin-bottom: 0.5rem;
      background: #fff; transition: all .18s ease;
    }
    .sca-student-item:hover { border-color: #c7d2fe; background: #f8faff; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(65,84,241,.08); }
    .sca-student-item.active { border-color: #4154f1; background: linear-gradient(135deg, #eef2ff 0%, #f5f7ff 100%); box-shadow: 0 4px 14px rgba(65,84,241,.12); }
    .sca-student-item--picked { border-color: #34d399; background: linear-gradient(135deg, #ecfdf5 0%, #f0fdf4 100%); }
    .sca-student-item--picked.active { border-color: #4154f1; }
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
      border: 1px solid #a7f3d0; border-radius: 0.75rem;
      background: linear-gradient(135deg, #ecfdf5 0%, #f0fdf4 100%);
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
      border: 1px solid #e5e7eb; border-radius: 0.625rem; background: #f8fafc;
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

    .sca-panel { position: relative; min-height: 420px; }
    .sca-panel-loading {
      position: absolute; inset: 0; z-index: 20; border-radius: 0.75rem;
      background: rgba(255,255,255,.82); backdrop-filter: blur(2px);
      display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 0.75rem;
    }
    .sca-spinner {
      width: 2.5rem; height: 2.5rem; border-radius: 999px;
      border: 3px solid rgba(65,84,241,.15); border-top-color: #4154f1;
      animation: scaSpin .7s linear infinite;
    }
    @keyframes scaSpin { to { transform: rotate(360deg); } }

    .sca-section {
      border: 1px solid #eef2ff; border-radius: 0.875rem;
      background: #fafbff; padding: 1.1rem 1.15rem; margin-bottom: 1rem;
    }
    .sca-section__head {
      display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.85rem;
      font-size: 0.95rem; font-weight: 800; color: #012970;
    }
    .sca-section__head i { color: #4154f1; }

    .sca-tree { max-height: 22rem; overflow-y: auto; padding-right: 0.25rem; }
    .sca-tree details {
      border: 1px solid #e5e7eb; border-radius: 0.625rem;
      margin-bottom: 0.45rem; padding: 0.35rem 0.65rem; background: #fff;
    }
    .sca-tree summary { cursor: pointer; font-weight: 700; color: #012970; list-style: none; font-size: 0.875rem; }
    .sca-tree summary::-webkit-details-marker { display: none; }
    .sca-tree label {
      display: flex; align-items: center; gap: 0.45rem; padding: 0.25rem 0 0.25rem 1rem;
      font-size: 0.84rem; color: #4b5563; cursor: pointer; border-radius: 0.375rem;
    }
    .sca-tree label:hover { background: #f3f4f6; }
    .sca-tree input[type=checkbox] { accent-color: #4154f1; width: 1rem; height: 1rem; }
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
      position: sticky; bottom: 0; z-index: 10; margin: 1.25rem -1.25rem -1.25rem;
      padding: 1rem 1.25rem; border-top: 1px solid #e5e7eb;
      background: linear-gradient(to top, #fff 85%, rgba(255,255,255,.95));
      border-radius: 0 0 0.75rem 0.75rem;
      display: flex; flex-wrap: wrap; gap: 0.65rem; align-items: center;
    }

    .sca-btn {
      display: inline-flex; align-items: center; justify-content: center; gap: 0.45rem;
      padding: 0.62rem 1.1rem; border-radius: 0.625rem; font-weight: 700; font-size: 0.875rem;
      border: 2px solid transparent; transition: all .15s ease; cursor: pointer;
    }
    .sca-btn:disabled { opacity: 0.65; cursor: not-allowed; transform: none !important; }
    .sca-btn--primary { background: #4154f1; color: #fff; box-shadow: 0 4px 14px rgba(65,84,241,.25); }
    .sca-btn--primary:hover:not(:disabled) { background: #3448d4; transform: translateY(-1px); }
    .sca-btn--outline { background: #fff; border-color: #e5e7eb; color: #475569; }
    .sca-btn--outline:hover:not(:disabled) { border-color: #4154f1; color: #4154f1; background: #f8faff; }
    .sca-btn--ghost { background: transparent; color: #64748b; border-color: transparent; }
    .sca-btn--ghost:hover:not(:disabled) { color: #334155; background: #f1f5f9; }
    .sca-btn__spin { animation: scaSpin .7s linear infinite; }

    .sca-idle { text-align: center; padding: 3rem 1.5rem; }
    .sca-idle__icon {
      width: 4.5rem; height: 4.5rem; margin: 0 auto 1rem; border-radius: 1.25rem;
      background: linear-gradient(135deg, #eef2ff, #f5f7ff); color: #4154f1;
      display: flex; align-items: center; justify-content: center; font-size: 2rem;
    }

    /* Toast stack */
    .sca-toast-stack {
      position: fixed; top: 5.5rem; right: 1.25rem; z-index: 9999;
      display: flex; flex-direction: column; gap: 0.65rem; width: min(100vw - 2rem, 22rem);
      pointer-events: none;
    }
    .sca-toast {
      pointer-events: auto; display: flex; gap: 0.75rem; align-items: flex-start;
      padding: 0.9rem 1rem; border-radius: 0.875rem;
      box-shadow: 0 12px 40px rgba(15,23,42,.15); border: 1px solid transparent;
      animation: scaToastIn .35s ease;
    }
    @keyframes scaToastIn {
      from { opacity: 0; transform: translateX(1.25rem); }
      to { opacity: 1; transform: translateX(0); }
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
  </style>
  <script>
  document.addEventListener('alpine:init', function () {
    Alpine.data('studentAccessAdmin', function () {
      return {
        csrf: <?php echo json_encode($csrf); ?>,
        preselectId: <?php echo (int) $preselectId; ?>,
        panelMode: <?php echo $preselectId > 0 ? "'edit'" : "'idle'"; ?>,
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
        toasts: [],
        toastSeq: 0,
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
          if (this.saveAction === 'create') return 'Creating student account…';
          if (this.saveAction === 'account') return 'Saving account details…';
          if (this.saveAction === 'permissions') return 'Saving content access…';
          if (this.saveAction === 'bulk') return 'Applying access to selected students…';
          if (this.loadingStudent) return 'Loading student data…';
          return 'Please wait…';
        },

        async init() {
          this.loadingCatalog = true;
          await this.loadCatalog();
          this.loadingCatalog = false;
          await this.searchStudents();
          if (this.preselectId > 0) await this.loadStudent(this.preselectId);
        },

        showToast(title, message, type) {
          const id = ++this.toastSeq;
          this.toasts.push({ id, title, message, type: type || 'ok' });
          setTimeout(() => this.dismissToast(id), type === 'err' ? 7000 : 4500);
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

        startCreate() {
          this.panelMode = 'create';
          this.selectedId = 0;
          this.selectedIds = [];
          this.selectedStudentsMeta = {};
          this.student = null;
          this.newPermissions = [];
          this.newStudent = { full_name: '', email: '', password: '', school: 'Manual enrollment', months: 6 };
        },
        cancelCreate() {
          this.panelMode = 'idle';
          this.newPermissions = [];
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
        openBulkAssign() {
          if (this.selectedIds.length === 0) {
            this.showToast('No students selected', 'Check at least one student from the list.', 'err');
            return;
          }
          this.panelMode = 'bulk';
          this.selectedId = 0;
          this.student = null;
          this.bulkPermissions = [];
        },
        cancelBulk() {
          this.panelMode = 'idle';
          this.bulkPermissions = [];
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
        async loadStudent(id) {
          this.panelMode = 'edit';
          this.selectedId = id;
          this.selectedIds = [];
          this.selectedStudentsMeta = {};
          this.bulkPermissions = [];
          this.loadingStudent = true;
          this.student = null;
          try {
            const data = await this.apiGet('student', { user_id: id });
            this.student = data.user;
            this.permissions = data.permissions || [];
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

        isChecked(type, id) {
          return this.activePermissionList.some(p => p.content_type === type && Number(p.content_id) === Number(id));
        },
        toggle(type, id, on) {
          const key = this.permissionListKey;
          this[key] = this[key].filter(p => !(p.content_type === type && Number(p.content_id) === Number(id)));
          if (on) this[key].push({ content_type: type, content_id: Number(id) });
        },
        toggleFullLms(on) {
          const key = this.permissionListKey;
          this[key] = this[key].filter(p => p.content_type !== 'full_lms');
          if (on) this[key].push({ content_type: 'full_lms', content_id: 0 });
        },

        async savePermissions() {
          this.saveAction = 'permissions';
          try {
            const data = await this.apiPost('save_permissions', {
              user_id: this.selectedId,
              permissions: JSON.stringify(this.permissions)
            });
            this.permissions = data.permissions || [];
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
        }
      };
    });
  });
  </script>
</head>
<body class="font-sans antialiased admin-app admin-student-access-page">
<?php include 'admin_sidebar.php'; ?>

<div class="quiz-admin-hero rounded-xl px-5 py-5 mb-5 page-hero">
  <?php include __DIR__ . '/includes/admin_breadcrumb.php'; ?>
  <h1 class="text-2xl font-bold text-gray-100 m-0 flex flex-wrap items-center gap-2">
    <span class="quiz-admin-hero-icon" aria-hidden="true"><i class="bi bi-shield-lock"></i></span>
    Student Access Management
  </h1>
  <p class="text-gray-400 mt-2 mb-0">Add students, manage account status, and assign LMS content access to one or many students at once.</p>
</div>

<div x-data="studentAccessAdmin()" x-init="init()">

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
    <!-- Sidebar -->
    <aside class="sca-sidebar rounded-xl shadow-card border p-5">
      <button type="button" class="sca-btn sca-btn--primary w-full mb-4" @click="startCreate()">
        <i class="bi bi-person-plus"></i> Add new student
      </button>

      <h2 class="text-sm font-bold text-gray-100 m-0 mb-3 flex items-center gap-2">
        <i class="bi bi-search text-emerald-400"></i> Find student
      </h2>
      <div class="relative mb-3">
        <i class="bi bi-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
        <input type="search" class="input-custom w-full pl-9" placeholder="Name or email…" x-model="searchQ" @input.debounce.300ms="searchStudents()">
      </div>

      <div class="sca-bulk-bar" x-show="selectedIds.length > 0" x-cloak>
        <span class="sca-bulk-bar__count" x-text="selectedIds.length + ' selected'"></span>
        <button type="button" class="sca-btn sca-btn--outline sca-btn--sm" @click="selectAllMatching()" x-show="!allMatchingSelected && searchTotal > 0">Select all</button>
        <button type="button" class="sca-btn sca-btn--outline sca-btn--sm" @click="clearSelection()">Clear all</button>
        <button type="button" class="sca-btn sca-btn--primary sca-btn--sm ml-auto" @click="openBulkAssign()">
          <i class="bi bi-people-fill"></i> Assign access
        </button>
      </div>

      <div class="sca-select-all" x-show="!loadingSearch && searchResults.length > 0" x-cloak>
        <label class="sca-select-all__label">
          <input type="checkbox" class="sca-student-check" x-ref="selectAllCheckbox"
                 :checked="allMatchingSelected"
                 @change="toggleSelectAllMatching($event.target.checked)">
          <span>Select all students (<span x-text="searchTotal"></span>)</span>
        </label>
        <span class="sca-select-all__hint" x-show="selectedInFilterCount > 0 && !allMatchingSelected"
              x-text="selectedInFilterCount + ' of ' + searchTotal + ' selected'"></span>
      </div>

      <div class="max-h-[32rem] overflow-y-auto sca-tree" x-show="!loadingSearch">
        <template x-for="s in searchResults" :key="s.user_id">
          <div class="sca-student-item"
               :class="{
                 active: selectedId == s.user_id && panelMode === 'edit',
                 'sca-student-item--picked': isSelected(s.user_id)
               }">
            <input type="checkbox" class="sca-student-check" :checked="isSelected(s.user_id)" @click.stop="toggleSelected(s.user_id)" :aria-label="'Select ' + s.full_name">
            <button type="button" class="sca-student-item__main" @click="loadStudent(s.user_id)">
              <span class="sca-student-item__avatar" x-text="initials(s.full_name)"></span>
              <span class="min-w-0 flex-1">
                <span class="font-semibold text-gray-100 text-sm block truncate" x-text="s.full_name"></span>
                <span class="text-xs text-gray-500 block truncate" x-text="s.email"></span>
                <span class="sca-badge mt-1" :class="'sca-badge--' + (s.status || 'pending')" x-text="(s.status || 'pending').toUpperCase()"></span>
              </span>
            </button>
          </div>
        </template>
        <p class="text-sm text-gray-400 text-center py-6 m-0" x-show="searchResults.length === 0">
          <i class="bi bi-inbox text-2xl block mb-2"></i>
          No students found
        </p>
      </div>
      <div class="flex items-center justify-center gap-2 text-sm text-gray-400 py-6" x-show="loadingSearch">
        <span class="sca-spinner" style="width:1.25rem;height:1.25rem;border-width:2px"></span>
        Searching…
      </div>
    </aside>

    <!-- Create panel -->
    <section class="sca-panel rounded-xl shadow-card border p-5" x-show="panelMode === 'create'" x-cloak>
      <div x-show="panelLoading" class="sca-panel-loading" x-cloak>
        <span class="sca-spinner"></span>
        <span class="text-sm font-semibold text-gray-600" x-text="panelLoadingLabel"></span>
      </div>

      <div class="flex flex-wrap items-center justify-between gap-3 mb-4 pb-4 border-b border-gray-100">
        <div>
          <h2 class="text-lg font-bold text-gray-100 m-0 flex items-center gap-2">
            <i class="bi bi-person-plus text-emerald-400"></i> New student
          </h2>
          <p class="text-xs text-gray-500 m-0 mt-1">Fill in account details, then choose content access below.</p>
        </div>
        <button type="button" class="sca-btn sca-btn--ghost" @click="cancelCreate()"><i class="bi bi-x-lg"></i> Cancel</button>
      </div>

      <div class="sca-section">
        <div class="sca-section__head"><i class="bi bi-person-vcard"></i> Account details</div>
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

      <div class="sca-section mb-0">
        <div class="sca-section__head flex-wrap">
          <span class="flex items-center gap-2"><i class="bi bi-diagram-3"></i> LMS content access</span>
          <span class="sca-perm-count ml-auto" x-text="activePermCount"></span>
        </div>
        <p class="text-xs text-gray-500 mb-3">Choose specific subjects, lessons, preboards, pre-week, or test bank — or enable Full LMS for everything.</p>
        <?php $scaTreeScope = 'create'; require __DIR__ . '/includes/admin_sca_permission_tree.php'; ?>
      </div>

      <div class="sca-sticky-actions">
        <button type="button" class="sca-btn sca-btn--primary" @click="createStudent()" :disabled="saveAction !== null">
          <i class="bi" :class="saveAction === 'create' ? 'bi-arrow-repeat sca-btn__spin' : 'bi-plus-circle'"></i>
          <span x-text="saveAction === 'create' ? 'Creating…' : 'Create student'"></span>
        </button>
        <button type="button" class="sca-btn sca-btn--outline" @click="cancelCreate()" :disabled="saveAction !== null">Cancel</button>
      </div>
    </section>

    <!-- Bulk assign panel -->
    <section class="sca-panel rounded-xl shadow-card border p-5" x-show="panelMode === 'bulk'" x-cloak>
      <div x-show="panelLoading" class="sca-panel-loading" x-cloak>
        <span class="sca-spinner"></span>
        <span class="text-sm font-semibold text-gray-600" x-text="panelLoadingLabel"></span>
      </div>

      <div class="flex flex-wrap items-center justify-between gap-3 mb-4 pb-4 border-b border-gray-100">
        <div>
          <h2 class="text-lg font-bold text-gray-100 m-0 flex items-center gap-2">
            <i class="bi bi-people-fill text-emerald-400"></i> Bulk assign access
          </h2>
          <p class="text-xs text-gray-500 m-0 mt-1">Give the same LMS content access to multiple students in one save.</p>
        </div>
        <button type="button" class="sca-btn sca-btn--ghost" @click="cancelBulk()"><i class="bi bi-x-lg"></i> Cancel</button>
      </div>

      <div class="sca-section">
        <div class="sca-section__head"><i class="bi bi-people"></i> Selected students (<span x-text="selectedIds.length"></span>)</div>
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

      <div class="sca-section mb-0">
        <div class="sca-section__head flex-wrap">
          <span class="flex items-center gap-2"><i class="bi bi-diagram-3"></i> LMS content access to apply</span>
          <span class="sca-perm-count ml-auto" x-text="activePermCount"></span>
        </div>
        <p class="text-xs text-gray-500 mb-3">This replaces the current content permissions for every selected student.</p>
        <?php $scaTreeScope = 'bulk'; require __DIR__ . '/includes/admin_sca_permission_tree.php'; ?>
      </div>

      <div class="sca-sticky-actions">
        <button type="button" class="sca-btn sca-btn--primary" @click="saveBulkPermissions()" :disabled="saveAction !== null || selectedIds.length === 0">
          <i class="bi" :class="saveAction === 'bulk' ? 'bi-arrow-repeat sca-btn__spin' : 'bi-shield-check'"></i>
          <span x-text="saveAction === 'bulk' ? 'Applying…' : ('Apply to ' + selectedIds.length + ' student' + (selectedIds.length === 1 ? '' : 's'))"></span>
        </button>
        <button type="button" class="sca-btn sca-btn--outline" @click="cancelBulk()" :disabled="saveAction !== null">Cancel</button>
      </div>
    </section>

    <!-- Edit panel -->
    <section class="sca-panel rounded-xl shadow-card border p-5" x-show="panelMode === 'edit' && selectedId" x-cloak>
      <div x-show="panelLoading" class="sca-panel-loading" x-cloak>
        <span class="sca-spinner"></span>
        <span class="text-sm font-semibold text-gray-600" x-text="panelLoadingLabel"></span>
      </div>

      <template x-if="student">
        <div>
          <div class="flex flex-wrap items-start justify-between gap-3 mb-4 pb-4 border-b border-gray-100">
            <div class="flex items-start gap-3">
              <span class="sca-student-item__avatar" style="width:2.75rem;height:2.75rem;font-size:1rem" x-text="initials(student.full_name)"></span>
              <div>
                <h2 class="text-lg font-bold text-gray-100 m-0" x-text="student.full_name"></h2>
                <p class="text-sm text-gray-500 m-0" x-text="student.email"></p>
                <span class="sca-access-pill mt-2">
                  <i class="bi bi-calendar3"></i>
                  <span x-text="(student.access_start || '—') + ' → ' + (student.access_end || '—')"></span>
                </span>
              </div>
            </div>
            <span class="sca-badge" :class="'sca-badge--' + (student.status || 'pending')" x-text="(student.status || '').toUpperCase()"></span>
          </div>

          <div class="sca-section">
            <div class="sca-section__head"><i class="bi bi-person-vcard"></i> Account details</div>
            <div class="sca-form-grid mb-3">
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
              <span x-text="saveAction === 'account' ? 'Saving…' : 'Save account details'"></span>
            </button>
          </div>

          <div class="sca-section mb-0">
            <div class="sca-section__head flex-wrap">
              <span class="flex items-center gap-2"><i class="bi bi-diagram-3"></i> LMS content access</span>
              <span class="sca-perm-count ml-auto" x-text="activePermCount"></span>
            </div>
            <?php $scaTreeScope = 'edit'; require __DIR__ . '/includes/admin_sca_permission_tree.php'; ?>
          </div>

          <div class="sca-sticky-actions">
            <button type="button" class="sca-btn sca-btn--primary" @click="savePermissions()" :disabled="saveAction !== null">
              <i class="bi" :class="saveAction === 'permissions' ? 'bi-arrow-repeat sca-btn__spin' : 'bi-shield-check'"></i>
              <span x-text="saveAction === 'permissions' ? 'Saving…' : 'Save content access'"></span>
            </button>
            <a class="sca-btn sca-btn--outline no-underline" :href="'admin_student_view?id=' + selectedId">
              <i class="bi bi-person-lines-fill"></i> View profile
            </a>
          </div>
        </div>
      </template>

      <div class="flex flex-col items-center justify-center py-16 text-gray-400" x-show="loadingStudent && !student">
        <span class="sca-spinner mb-3"></span>
        <span class="text-sm font-medium">Loading student…</span>
      </div>
    </section>

    <!-- Idle -->
    <section class="sca-panel rounded-xl shadow-card border sca-idle" x-show="panelMode === 'idle'" x-cloak>
      <div class="sca-idle__icon"><i class="bi bi-shield-lock"></i></div>
      <h2 class="text-lg font-bold text-gray-100 m-0 mb-2">Manage student access</h2>
      <p class="text-sm text-gray-500 m-0 mb-5 max-w-sm mx-auto">Select a student to edit one account, check multiple students for bulk assign, or create a new student.</p>
      <button type="button" class="sca-btn sca-btn--primary" @click="startCreate()">
        <i class="bi bi-person-plus"></i> Add new student
      </button>
    </section>
  </div>
</div>
</main>
</body>
</html>
