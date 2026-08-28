/**
 * Taakl read-only share viewer.
 *
 * Standalone: no dependencies, no localStorage, everything rendered via
 * textContent (payload data is untrusted in this context).
 *
 * DUPLICATION CONTRACT — these functions deliberately mirror semantics in the
 * client repo (taakl-client/js/timetracker.js). A behavior change there must be
 * ported here by hand:
 *   computeSearchMatches()  -> treeView._doUpdate search block
 *   computeRecentMatches()  -> treeView._doUpdate recent block
 *   getDateRange()          -> analyze.getDateRange (isoWeek = Monday start;
 *                              'week'/'month' end at today, not period end)
 *   rollupTime()            -> calculateNodeTime
 *   prettyTime()            -> prettyTime
 *   renderNode() filters    -> treeView.renderNode (hide-done -> search -> recent;
 *                              leaf completed tasks only; force-expand while filtering)
 */
(function () {
  'use strict';

  var data = null; // { share, rootUuid, nodes }
  var state = {
    search: '',
    hideCompleted: true,
    recentPreset: '',   // '' = All (no recency filter)
    collapsed: {},      // explicit user toggles, by node id
    searchMatchIds: null,
    recentMatchIds: null
  };
  var timeCache = {};

  function gebi(id) { return document.getElementById(id); }

  function getNode(id) { return data.nodes[id] || null; }

  function nodeIsTask(id) {
    var n = getNode(id);
    return !!n && (!n.childOrder || n.childOrder.length === 0);
  }

  /* ---------- date helpers (mirrors analyze.getDateRange, no moment.js) ---------- */

  function pad2(n) { return n < 10 ? '0' + n : '' + n; }

  function fmtDate(d) {
    return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate());
  }

  function addDays(d, days) {
    var r = new Date(d.getTime());
    r.setDate(r.getDate() + days);
    return r;
  }

  function startOfIsoWeek(d) { // Monday
    var r = new Date(d.getTime());
    var back = (r.getDay() + 6) % 7;
    r.setDate(r.getDate() - back);
    return r;
  }

  function getDateRange(preset) {
    var now = new Date();
    switch (preset) {
      case 'today':
        return { start: fmtDate(now), end: fmtDate(now) };
      case 'yesterday':
        var y = addDays(now, -1);
        return { start: fmtDate(y), end: fmtDate(y) };
      case 'week':
        return { start: fmtDate(startOfIsoWeek(now)), end: fmtDate(now) };
      case 'lastweek':
        var lw = startOfIsoWeek(addDays(now, -7));
        return { start: fmtDate(lw), end: fmtDate(addDays(lw, 6)) };
      case 'month':
        return { start: fmtDate(new Date(now.getFullYear(), now.getMonth(), 1)), end: fmtDate(now) };
      default:
        return { start: fmtDate(startOfIsoWeek(now)), end: fmtDate(now) };
    }
  }

  /* ---------- time helpers ---------- */

  // Mirrors client prettyTime: Xh Ym (or Ym / Xs for small values)
  function prettyTime(secs) {
    secs = Math.max(0, Math.floor(secs));
    var h = Math.floor(secs / 3600);
    var m = Math.floor((secs % 3600) / 60);
    if (h > 0) return h + 'h ' + m + 'm';
    if (m > 0) return m + 'm';
    return secs + 's';
  }

  // Mirrors calculateNodeTime: own logged time + all descendants
  function rollupTime(id) {
    if (timeCache.hasOwnProperty(id)) return timeCache[id];
    var n = getNode(id);
    if (!n) return 0;
    var total = n.time_logged || 0;
    var kids = n.childOrder || [];
    for (var i = 0; i < kids.length; i++) total += rollupTime(kids[i]);
    timeCache[id] = total;
    return total;
  }

  /* ---------- filters ---------- */

  // A name match marks the node and all its ancestors (up to the share root)
  function computeSearchMatches() {
    if (!state.search) { state.searchMatchIds = null; return; }
    var q = state.search.toLowerCase();
    var matches = {};
    for (var id in data.nodes) {
      var n = data.nodes[id];
      if (n.name && n.name.toLowerCase().indexOf(q) !== -1) {
        matches[id] = true;
        var pid = n.parentId;
        while (pid) {
          matches[pid] = true;
          var pn = getNode(pid);
          pid = pn ? pn.parentId : null;
        }
      }
    }
    state.searchMatchIds = matches;
  }

  // Compares date parts only, so both "T" and space datetime separators work
  function computeRecentMatches() {
    if (!state.recentPreset) { state.recentMatchIds = null; return; }
    var range = getDateRange(state.recentPreset);
    var matches = {};
    for (var id in data.nodes) {
      var cd = data.nodes[id].creation_date;
      if (!cd) continue;
      var day = String(cd).slice(0, 10);
      if (day >= range.start && day <= range.end) {
        matches[id] = true;
        var pid = data.nodes[id].parentId;
        while (pid) {
          matches[pid] = true;
          var pn = getNode(pid);
          pid = pn ? pn.parentId : null;
        }
      }
    }
    state.recentMatchIds = matches;
  }

  /* ---------- rendering ---------- */

  function el(tag, className, text) {
    var e = document.createElement(tag);
    if (className) e.className = className;
    if (text !== undefined && text !== null) e.textContent = text;
    return e;
  }

  var STATUS_LABELS = { 'new': 'New', 'inProcess': 'In Process', 'onHold': 'On Hold', 'completed': 'Completed' };

  function isOverdue(due) {
    return due && String(due).slice(0, 10) < fmtDate(new Date());
  }

  function badge(row, cls, text, title) {
    var b = el('span', 'share-badge ' + cls, text);
    if (title) b.title = title;
    row.appendChild(b);
  }

  function appendMetaBadges(container, id, isTask) {
    var n = getNode(id);
    var time = rollupTime(id);
    if (n.starred) badge(container, 'badge-star', '★', 'Starred');
    if (isTask && n.status && n.status !== 'new') {
      badge(container, 'badge-status status-' + n.status, STATUS_LABELS[n.status] || n.status);
    }
    if (n.due) badge(container, isOverdue(n.due) ? 'badge-due overdue' : 'badge-due', '⏰ ' + String(n.due).slice(0, 10), 'Due date');
    if (n.estimate) badge(container, 'badge-estimate', '≈ ' + prettyTime(n.estimate), 'Estimate');
    if (time > 0) badge(container, 'badge-time', prettyTime(time), 'Time logged');
  }

  function isCollapsed(id, depth) {
    if (state.collapsed.hasOwnProperty(id)) return state.collapsed[id];
    return depth >= 2; // default: expand the first two levels
  }

  function renderNode(container, id, depth) {
    var n = getNode(id);
    if (!n) return;

    var isTask = nodeIsTask(id);
    var hasChildren = !isTask;
    var isCompleted = n.status === 'completed';

    // Same skip order as the client: hide-done, then search, then recent
    if (state.hideCompleted && isTask && isCompleted) return;
    if (state.searchMatchIds && !state.searchMatchIds[id]) return;
    if (state.recentMatchIds && !state.recentMatchIds[id]) return;

    var row = el('div', 'share-row' + (hasChildren ? ' share-h' + (Math.min(depth, 3) + 1) : '') + (isCompleted ? ' completed' : ''));

    var bullet = el('span', 'share-bullet' + (hasChildren ? ' has-children' : ''));
    var filtering = !!(state.searchMatchIds || state.recentMatchIds);
    var collapsed = hasChildren && !filtering && isCollapsed(id, depth);
    bullet.textContent = hasChildren ? (collapsed ? '▶' : '▼') : '•';
    if (hasChildren) {
      bullet.onclick = function () {
        state.collapsed[id] = !isCollapsed(id, depth);
        render();
      };
    }
    row.appendChild(bullet);

    var name = el('span', 'share-name', n.name || '(unnamed)');
    row.appendChild(name);

    appendMetaBadges(row, id, isTask);

    if (n.notes) {
      var notes = el('div', 'share-notes', n.notes);
      row.appendChild(notes);
    }

    row.style.paddingLeft = (depth * 22) + 'px';
    container.appendChild(row);

    // Force-expand while a filter is active (mirrors client behavior)
    if (hasChildren && !collapsed) {
      var kids = n.childOrder || [];
      for (var i = 0; i < kids.length; i++) {
        renderNode(container, kids[i], depth + 1);
      }
    }
  }

  function renderHeader() {
    var header = gebi('share-header');
    header.innerHTML = '';

    var root = getNode(data.rootUuid);
    if (!root) return;

    header.appendChild(el('h1', 'share-title', root.name || '(unnamed)'));

    var meta = el('div', 'share-header-meta');
    var taskCount = 0, doneCount = 0;
    for (var id in data.nodes) {
      if (nodeIsTask(id)) {
        taskCount++;
        if (data.nodes[id].status === 'completed') doneCount++;
      }
    }
    var total = rollupTime(data.rootUuid);
    if (total > 0) meta.appendChild(el('span', 'share-header-stat', prettyTime(total) + ' logged'));
    meta.appendChild(el('span', 'share-header-stat', taskCount + ' task' + (taskCount === 1 ? '' : 's') + (taskCount ? ', ' + doneCount + ' done' : '')));

    var rootIsTask = nodeIsTask(data.rootUuid);
    if (rootIsTask && root.status) meta.appendChild(el('span', 'share-header-stat', STATUS_LABELS[root.status] || root.status));
    if (root.due) meta.appendChild(el('span', 'share-header-stat' + (isOverdue(root.due) ? ' overdue' : ''), 'due ' + String(root.due).slice(0, 10)));
    if (root.estimate) meta.appendChild(el('span', 'share-header-stat', 'estimate ' + prettyTime(root.estimate)));
    header.appendChild(meta);

    if (root.notes) header.appendChild(el('div', 'share-header-notes', root.notes));
  }

  function render() {
    computeSearchMatches();
    computeRecentMatches();
    timeCache = {};

    var tree = gebi('share-tree');
    tree.innerHTML = '';

    var root = getNode(data.rootUuid);
    var kids = (root && root.childOrder) || [];

    if (kids.length === 0) {
      // Root is a leaf task: its own row carries the detail
      renderNode(tree, data.rootUuid, 0);
    } else {
      for (var i = 0; i < kids.length; i++) {
        renderNode(tree, kids[i], 0);
      }
    }

    gebi('share-tree-empty').style.display = tree.children.length === 0 ? '' : 'none';
  }

  /* ---------- bootstrap ---------- */

  function showError() {
    gebi('share-loading').style.display = 'none';
    gebi('share-content').style.display = 'none';
    gebi('share-error').style.display = '';
  }

  function bindControls() {
    gebi('share-search').oninput = function () {
      state.search = this.value;
      render();
    };
    gebi('share-hide-done').onchange = function () {
      state.hideCompleted = this.checked;
      render();
    };
    gebi('share-chips').onclick = function (e) {
      if (!e.target.classList.contains('share-chip')) return;
      state.recentPreset = e.target.getAttribute('data-preset') || '';
      var chips = this.querySelectorAll('.share-chip');
      for (var i = 0; i < chips.length; i++) chips[i].classList.remove('active');
      e.target.classList.add('active');
      render();
    };
  }

  function init() {
    var token = (location.hash || '').replace(/^#/, '');
    if (!/^[a-f0-9]{64}$/.test(token)) {
      showError();
      return;
    }

    var xhr = new XMLHttpRequest();
    xhr.open('GET', '/api/share/' + token, true);
    xhr.onreadystatechange = function () {
      if (xhr.readyState !== 4) return;
      var payload = null;
      try { payload = JSON.parse(xhr.responseText); } catch (e) { /* fall through */ }
      if (xhr.status !== 200 || !payload || !payload.success || !payload.nodes) {
        showError();
        return;
      }
      data = payload;
      gebi('share-loading').style.display = 'none';
      gebi('share-content').style.display = '';
      bindControls();
      renderHeader();
      render();
    };
    xhr.send();
  }

  init();
})();
