/**
 * College regular exam client heartbeat (Phase 1).
 * Not wired into college_take_exam.php until that include is approved.
 * Uses sessionStorage instance id + localStorage browser-scope id (untrusted labels).
 */
(function (global) {
  function randomId() {
    var buf = new Uint8Array(16);
    if (global.crypto && global.crypto.getRandomValues) {
      global.crypto.getRandomValues(buf);
    } else {
      for (var i = 0; i < 16; i++) buf[i] = Math.floor(Math.random() * 256);
    }
    var hex = '';
    for (var j = 0; j < buf.length; j++) hex += ('0' + buf[j].toString(16)).slice(-2);
    return hex + hex.slice(0, 8);
  }
  function readOrSet(storage, key) {
    try {
      var existing = storage.getItem(key);
      if (existing && /^[A-Za-z0-9_-]{16,128}$/.test(existing)) return existing;
      var created = randomId();
      storage.setItem(key, created);
      return created;
    } catch (e) {
      return randomId();
    }
  }
  function startCollegeExamClientHeartbeat(opts) {
    opts = opts || {};
    var attemptId = parseInt(opts.attemptId, 10) || 0;
    var csrf = String(opts.csrfToken || '');
    var url = String(opts.url || 'college_exam_client_heartbeat.php');
    var intervalMs = parseInt(opts.intervalMs, 10) || 15000;
    if (attemptId <= 0 || !csrf) return;
    var instanceId = readOrSet(global.sessionStorage, 'ereview_exam_instance_' + attemptId);
    var scopeId = readOrSet(global.localStorage, 'ereview_exam_browser_scope');
    function beat() {
      var body = new URLSearchParams();
      body.set('csrf_token', csrf);
      body.set('attempt_id', String(attemptId));
      body.set('instance_id', instanceId);
      body.set('browser_scope_id', scopeId);
      body.set('client_label', /Mobi|Android/i.test(navigator.userAgent || '') ? 'mobile' : 'desktop');
      fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: body.toString()
      }).catch(function () {});
    }
    beat();
    return global.setInterval(beat, intervalMs);
  }
  global.startCollegeExamClientHeartbeat = startCollegeExamClientHeartbeat;
})(window);
