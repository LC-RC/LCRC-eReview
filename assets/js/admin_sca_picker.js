/**
 * Shared SCA admin picker helpers (Full Subject vs Selected Topics).
 * Used by admin_student_access, admin_students, admin_student_view.
 *
 * Critical: subjectModes must be explicit state. Inferring "selected" only from
 * checked lessons caused a chicken-and-egg bug — choosing Selected Topics cleared
 * lessons, mode became "none", and the topic list hid immediately.
 */
(function (global) {
  'use strict';

  function scaCollectSubjectChildIds(sub) {
    var lessonIds = {};
    var quizIds = {};
    var videoIds = {};
    var handoutIds = {};
    (sub.lessons || []).forEach(function (l) {
      lessonIds[Number(l.id)] = true;
      (l.videos || []).forEach(function (v) { videoIds[Number(v.id)] = true; });
      (l.handouts || []).forEach(function (h) { handoutIds[Number(h.id)] = true; });
    });
    (sub.quizzes || []).forEach(function (q) { quizIds[Number(q.id)] = true; });
    return { lessonIds: lessonIds, quizIds: quizIds, videoIds: videoIds, handoutIds: handoutIds };
  }

  function scaSubjectHasChildGrant(list, sub) {
    var ids = scaCollectSubjectChildIds(sub);
    return (list || []).some(function (p) {
      var id = Number(p.content_id);
      if (p.content_type === 'lesson' && ids.lessonIds[id]) return true;
      if (p.content_type === 'quiz' && ids.quizIds[id]) return true;
      if (p.content_type === 'video' && ids.videoIds[id]) return true;
      if (p.content_type === 'handout' && ids.handoutIds[id]) return true;
      return false;
    });
  }

  function scaFindSubjectIdForLesson(catalog, lessonId) {
    var lid = Number(lessonId);
    var subjects = (catalog && catalog.subjects) || [];
    for (var i = 0; i < subjects.length; i++) {
      var lessons = subjects[i].lessons || [];
      for (var j = 0; j < lessons.length; j++) {
        if (Number(lessons[j].id) === lid) return Number(subjects[i].id);
      }
    }
    return 0;
  }

  /**
   * Mix into an Alpine component that has:
   * - catalog
   * - permissionListKey / activePermissionList OR permissions array via getters
   * - subjectModes: {}
   */
  global.ereviewScaPickerMethods = {
    isChecked: function (type, id) {
      var list = this.activePermissionList || this.permissions || [];
      return list.some(function (p) {
        return p.content_type === type && Number(p.content_id) === Number(id);
      });
    },

    toggle: function (type, id, on) {
      var key = this.permissionListKey || 'permissions';
      var list = (this[key] || []).filter(function (p) {
        return !(p.content_type === type && Number(p.content_id) === Number(id));
      });
      if (on) {
        list = list.concat([{ content_type: type, content_id: Number(id) }]);
        if (type === 'lesson') {
          var sid = scaFindSubjectIdForLesson(this.catalog, id);
          if (sid > 0) {
            list = list.filter(function (p) {
              return !(p.content_type === 'subject' && Number(p.content_id) === sid);
            });
            var modes = Object.assign({}, this.subjectModes || {});
            modes[sid] = 'selected';
            this.subjectModes = modes;
          }
        }
      }
      this[key] = list;
    },

    toggleFullLms: function (on) {
      var key = this.permissionListKey || 'permissions';
      var list = (this[key] || []).filter(function (p) { return p.content_type !== 'full_lms'; });
      if (on) {
        list = list.concat([{ content_type: 'full_lms', content_id: 0 }]);
        this.subjectModes = {};
      }
      this[key] = list;
    },

    /** Prefer explicit subjectModes; fall back to permission inference (load). */
    subjectMode: function (subjectId) {
      var id = Number(subjectId);
      var modes = this.subjectModes || {};
      if (modes[id] === 'full' || modes[id] === 'selected' || modes[id] === 'none') {
        return modes[id];
      }
      if (this.isChecked('subject', id)) return 'full';
      var sub = ((this.catalog && this.catalog.subjects) || []).find(function (s) {
        return Number(s.id) === id;
      });
      if (!sub) return 'none';
      return scaSubjectHasChildGrant(this.activePermissionList || this.permissions || [], sub)
        ? 'selected'
        : 'none';
    },

    clearSubjectChildren: function (sub) {
      var key = this.permissionListKey || 'permissions';
      var ids = scaCollectSubjectChildIds(sub);
      var sid = Number(sub.id);
      this[key] = (this[key] || []).filter(function (p) {
        var id = Number(p.content_id);
        if (p.content_type === 'subject' && id === sid) return false;
        if (p.content_type === 'lesson' && ids.lessonIds[id]) return false;
        if (p.content_type === 'quiz' && ids.quizIds[id]) return false;
        if (p.content_type === 'video' && ids.videoIds[id]) return false;
        if (p.content_type === 'handout' && ids.handoutIds[id]) return false;
        return true;
      });
    },

    setSubjectMode: function (sub, mode) {
      var id = Number(sub.id);
      var modes = Object.assign({}, this.subjectModes || {});
      modes[id] = mode;
      this.subjectModes = modes;
      this.clearSubjectChildren(sub);
      if (mode === 'full') {
        var key = this.permissionListKey || 'permissions';
        this[key] = (this[key] || []).concat([{ content_type: 'subject', content_id: id }]);
      }
    },

    inferSubjectModesFromPermissions: function () {
      var modes = {};
      var list = this.activePermissionList || this.permissions || [];
      ((this.catalog && this.catalog.subjects) || []).forEach(function (sub) {
        var id = Number(sub.id);
        var hasFull = list.some(function (p) {
          return p.content_type === 'subject' && Number(p.content_id) === id;
        });
        if (hasFull) {
          modes[id] = 'full';
        } else if (scaSubjectHasChildGrant(list, sub)) {
          modes[id] = 'selected';
        } else {
          modes[id] = 'none';
        }
      });
      this.subjectModes = modes;
    },

    selectedTopicCount: function (sub) {
      var lessonIds = {};
      (sub.lessons || []).forEach(function (l) { lessonIds[Number(l.id)] = true; });
      var list = this.activePermissionList || this.permissions || [];
      var n = 0;
      list.forEach(function (p) {
        if (p.content_type === 'lesson' && lessonIds[Number(p.content_id)]) n += 1;
      });
      return n;
    },

    accessSummaryLines: function () {
      if (this.hasFullLms) {
        return ['Full LMS access — all subjects and topics unlocked'];
      }
      var list = this.activePermissionList || this.permissions || [];
      if (!list.length) {
        return ['No content selected yet'];
      }
      var lines = [];
      var other = 0;
      ((this.catalog && this.catalog.subjects) || []).forEach(function (sub) {
        var mode = typeof this.subjectMode === 'function' ? this.subjectMode(sub.id) : 'none';
        var label = sub.label || ('Subject #' + sub.id);
        if (mode === 'full') {
          lines.push(label + ' — full subject access');
        } else if (mode === 'selected') {
          var n = this.selectedTopicCount(sub);
          lines.push(label + ' — ' + n + ' topic' + (n === 1 ? '' : 's') + ' selected');
        }
      }.bind(this));
      list.forEach(function (p) {
        if (['subject', 'lesson', 'full_lms'].indexOf(p.content_type) === -1) other += 1;
      });
      if (other) lines.push(other + ' other item' + (other === 1 ? '' : 's'));
      if (!lines.length) lines.push(list.length + ' permission item' + (list.length === 1 ? '' : 's'));
      return lines;
    },

    accessSummaryIsEmpty: function () {
      if (this.hasFullLms) return false;
      var list = this.activePermissionList || this.permissions || [];
      return !list.length;
    },

    accessSummaryShort: function () {
      var lines = this.accessSummaryLines();
      return lines.join(' · ');
    }
  };
})(window);
