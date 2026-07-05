(function() {
  'use strict';

  // ════════════════════════════════════════════════════
  //  State
  // ════════════════════════════════════════════════════

  var state = {
    connections: [],
    diffItems: [],
    generatedSql: '',
    currentPage: 'connections',
    srcId: '',
    tgtId: '',
    isLoading: false,
    terminalLines: [],
    terminalAutoScroll: true,
    terminalPollTimer: null,
    terminalLastOffset: 0,
    quickCompares: [],
  };

  // ════════════════════════════════════════════════════
  //  Bridge wrapper with built-in error handling
  // ════════════════════════════════════════════════════

  function bridgeCall(method, data) {
    return window.__webview__.call(method, data);
  }

  function showLoading(text, showCancel) {
    state.isLoading = true;
    document.getElementById('loadingText').textContent = text || '处理中...';
    document.getElementById('loadingCancelBtn').style.display = showCancel ? 'inline-block' : 'none';
    document.getElementById('loadingOverlay').style.display = 'flex';
  }

  function hideLoading() {
    state.isLoading = false;
    document.getElementById('loadingOverlay').style.display = 'none';
    document.getElementById('loadingCancelBtn').style.display = 'none';
  }

  function showError(msg) {
    hideLoading();
    alert('错误: ' + msg);
  }

  function showToast(msg, duration) {
    duration = duration || 1000;
    var el = document.getElementById('toast');
    if (!el) {
      el = document.createElement('div');
      el.id = 'toast';
      document.body.appendChild(el);
    }
    el.textContent = msg;
    el.className = 'toast visible';
    clearTimeout(el._timer);
    el._timer = setTimeout(function() { el.className = 'toast'; }, duration);
  }

  function getVal(id) { return document.getElementById(id).value; }
  function setVal(id, v) { document.getElementById(id).value = v; }

  // ════════════════════════════════════════════════════
  //  Page Navigation
  // ════════════════════════════════════════════════════

  window.switchPage = function(page) {
    state.currentPage = page;
    document.querySelectorAll('.page').forEach(function(p) { p.classList.remove('active'); });
    document.getElementById('page-' + page).classList.add('active');
    document.querySelectorAll('.nav-item').forEach(function(n) { n.classList.remove('active'); });
    document.querySelector('.nav-item[data-page="' + page + '"]').classList.add('active');

    // Refresh data when entering pages
    if (page === 'connections') loadConnections();
    else if (page === 'compare') loadComparePage();
  };

  // ════════════════════════════════════════════════════
  //  Connections
  // ════════════════════════════════════════════════════

  function loadConnections(isInitialLoad) {
    showLoading('加载连接列表...');
    bridgeCall('getConnections').then(function(conns) {
      state.connections = conns || [];
      renderConnList();
      populateCompareSelects();
      hideLoading();
      // Default page: if < 2 connections → stay on connections; if >= 2 → compare page
      if (isInitialLoad && state.connections.length >= 2) {
        switchPage('compare');
      }
    }).catch(function(err) {
      showError('加载连接失败: ' + err);
    });
  }

  function renderConnList() {
    var el = document.getElementById('connList');
    if (state.connections.length === 0) {
      el.innerHTML = '<div style="padding:20px;text-align:center;color:#999;">暂无连接，请新建</div>';
      return;
    }
    el.innerHTML = state.connections.map(function(c) {
      return '<div class="conn-item" data-id="' + c.id + '">' +
        '<div class="conn-item-info" onclick="editConnection(\'' + c.id + '\')">' +
          '<div class="conn-item-row"><span class="conn-item-name">' + escHtml(c.name) + '</span><span class="conn-item-meta">' + c.port + ' · ' + escHtml(c.database) + '</span></div>' +
          '<div class="conn-item-host">' + escHtml(c.host) + '</div>' +
        '</div>' +
        '<button class="conn-item-delete" onclick="event.stopPropagation();confirmDeleteConnection(\'' + c.id + '\',\'' + escHtml(c.name) + '\')" title="删除连接">✕</button>' +
        '</div>';
    }).join('');
  }

  window.showNewConnectionForm = function() {
    document.getElementById('connFormTitle').textContent = '新建连接';
    setVal('connEditId', '');
    setVal('connName', '');
    setVal('connHost', '127.0.0.1');
    setVal('connPort', '3306');
    setVal('connDatabase', '');
    setVal('connUser', '');
    setVal('connPassword', '');
    document.getElementById('connStatus').textContent = '';
    // Remove active class from list
    document.querySelectorAll('.conn-item').forEach(function(i) { i.classList.remove('active'); });
  };

  window.editConnection = function(id) {
    var c = state.connections.find(function(x) { return x.id === id; });
    if (!c) return;
    document.getElementById('connFormTitle').textContent = '编辑连接';
    setVal('connEditId', c.id);
    setVal('connName', c.name);
    setVal('connHost', c.host);
    setVal('connPort', String(c.port));
    setVal('connDatabase', c.database);
    setVal('connUser', c.user);
    setVal('connPassword', c.password || '');
    document.getElementById('connStatus').textContent = '';
    document.querySelectorAll('.conn-item').forEach(function(i) {
      i.classList.toggle('active', i.getAttribute('data-id') === id);
    });
  };

  window.saveConnection = function() {
    var data = {
      id: getVal('connEditId') || undefined,
      name: getVal('connName'),
      host: getVal('connHost'),
      port: parseInt(getVal('connPort')) || 3306,
      user: getVal('connUser'),
      password: getVal('connPassword'),
      database: getVal('connDatabase'),
    };
    if (!data.name || !data.host || !data.database) {
      document.getElementById('connStatus').textContent = '⚠ 名称、主机、数据库不能为空';
      document.getElementById('connStatus').style.color = '#ff9800';
      return;
    }
    showLoading('保存连接...');
    bridgeCall('saveConnection', data).then(function(result) {
      hideLoading();
      document.getElementById('connStatus').textContent = '✅ 已保存';
      document.getElementById('connStatus').style.color = '#4caf50';
      setVal('connEditId', result.id);
      return bridgeCall('getConnections');
    }).then(function(conns) {
      state.connections = conns || [];
      renderConnList();
      populateCompareSelects();
    }).catch(function(err) {
      showError('保存失败: ' + err);
    });
  };

  window.testConnection = function() {
    var data = {
      host: getVal('connHost'),
      port: parseInt(getVal('connPort')) || 3306,
      user: getVal('connUser'),
      password: getVal('connPassword'),
      database: getVal('connDatabase'),
    };
    showLoading('测试连接中...');
    document.getElementById('connStatus').textContent = '⏳ 测试中...';
    document.getElementById('connStatus').style.color = '#666';
    bridgeCall('testConnection', data).then(function(result) {
      hideLoading();
      var el = document.getElementById('connStatus');
      if (result.ok) {
        el.textContent = '✅ 连接成功 | MySQL ' + (result.version || '');
        el.style.color = '#4caf50';
      } else {
        el.textContent = '❌ 失败: ' + (result.error || '未知错误');
        el.style.color = '#f44336';
      }
    }).catch(function(err) {
      hideLoading();
      document.getElementById('connStatus').textContent = '❌ 错误: ' + err;
      document.getElementById('connStatus').style.color = '#f44336';
    });
  };

  window.confirmDeleteConnection = function(id, name) {
    var overlay = document.createElement('div');
    overlay.className = 'confirm-overlay';
    overlay.innerHTML = '<div class="confirm-box">' +
      '<h3>确认删除</h3>' +
      '<p>确定要删除连接 <strong>' + escHtml(name) + '</strong> 吗？<br>此操作不可撤销。</p>' +
      '<div class="confirm-actions">' +
        '<button class="btn" onclick="this.closest(\'.confirm-overlay\').remove()">取消</button>' +
        '<button class="btn btn-danger" id="confirmDeleteBtn">删除</button>' +
      '</div></div>';
    document.body.appendChild(overlay);
    overlay.addEventListener('click', function(e) {
      if (e.target === overlay) overlay.remove();
    });
    document.getElementById('confirmDeleteBtn').addEventListener('click', function() {
      overlay.remove();
      deleteConnection(id);
    });
  };

  function deleteConnection(id) {
    showLoading('删除连接...');
    bridgeCall('deleteConnection', id).then(function() {
      return bridgeCall('getConnections');
    }).then(function(conns) {
      state.connections = conns || [];
      renderConnList();
      populateCompareSelects();
      hideLoading();
      document.getElementById('connFormTitle').textContent = '新建连接';
      setVal('connEditId', '');
      setVal('connName', '');
      setVal('connHost', '127.0.0.1');
      setVal('connPort', '3306');
      setVal('connDatabase', '');
      setVal('connUser', '');
      setVal('connPassword', '');
      document.getElementById('connStatus').textContent = '';
    }).catch(function(err) {
      hideLoading();
      showError('删除失败: ' + err);
    });
  }

  // ════════════════════════════════════════════════════
  //  Compare
  // ════════════════════════════════════════════════════

  function populateCompareSelects() {
    var src = document.getElementById('srcSelect');
    var tgt = document.getElementById('tgtSelect');
    src.innerHTML = '<option value="">— 选择源库 —</option>';
    tgt.innerHTML = '<option value="">— 选择目标库 —</option>';

    state.connections.forEach(function(c) {
      var label = escHtml(c.name) + ' (' + escHtml(c.host) + ':' + c.port + '/' + escHtml(c.database) + ')';
      src.innerHTML += '<option value="' + c.id + '">' + label + '</option>';
      tgt.innerHTML += '<option value="' + c.id + '">' + label + '</option>';
    });

    if (state.srcId) src.value = state.srcId;
    if (state.tgtId) tgt.value = state.tgtId;

    populateQuickCompareSelect();
  }

  function loadComparePage() {
    bridgeCall('getSettings').then(function(s) {
      if (!s) return;
      document.getElementById('excludePatterns').value = s.excludePatterns || '';
      var scope = s.compareScope || ['tables'];
      document.querySelectorAll('#scopeGroup input[type="checkbox"]').forEach(function(cb) {
        cb.checked = scope.indexOf(cb.value) >= 0;
      });
    }).catch(function(err) {
      console.error('Failed to load settings:', err);
    });
    loadQuickCompares();
  }

  window.swapConnections = function() {
    var src = document.getElementById('srcSelect');
    var tgt = document.getElementById('tgtSelect');
    var tmp = src.value;
    src.value = tgt.value;
    tgt.value = tmp;
    state.srcId = src.value;
    state.tgtId = tgt.value;
  };

  function loadQuickCompares() {
    return bridgeCall('getQuickCompares').then(function(list) {
      state.quickCompares = list || [];
      populateQuickCompareSelect();
    });
  }

  function populateQuickCompareSelect() {
    var sel = document.getElementById('quickCompareSelect');
    sel.innerHTML = '<option value="">— 选择快速对比配置 —</option>';
    state.quickCompares.forEach(function(qc) {
      var srcConn = state.connections.find(function(c) { return c.id === qc.srcId; });
      var tgtConn = state.connections.find(function(c) { return c.id === qc.tgtId; });
      var label = escHtml(qc.name);
      if (srcConn && tgtConn) {
        label += ' (' + escHtml(srcConn.name) + ' → ' + escHtml(tgtConn.name) + ')';
      }
      sel.innerHTML += '<option value="' + qc.id + '">' + label + '</option>';
    });
  }

  window.loadQuickCompare = function() {
    var sel = document.getElementById('quickCompareSelect');
    var qcId = sel.value;
    var delBtn = document.getElementById('quickCompareDelBtn');
    if (!qcId) {
      delBtn.style.display = 'none';
      return;
    }
    delBtn.style.display = 'inline-block';
    var qc = state.quickCompares.find(function(q) { return q.id === qcId; });
    if (!qc) return;
    var src = document.getElementById('srcSelect');
    var tgt = document.getElementById('tgtSelect');
    if (src.querySelector('option[value="' + qc.srcId + '"]')) {
      src.value = qc.srcId;
      state.srcId = qc.srcId;
    }
    if (tgt.querySelector('option[value="' + qc.tgtId + '"]')) {
      tgt.value = qc.tgtId;
      state.tgtId = qc.tgtId;
    }
  };

  window.deleteQuickCompare = function() {
    var sel = document.getElementById('quickCompareSelect');
    var qcId = sel.value;
    if (!qcId) return;
    var qc = state.quickCompares.find(function(q) { return q.id === qcId; });
    var name = qc ? qc.name : qcId;
    if (!confirm('确认删除快速对比配置「' + name + '」？')) return;
    bridgeCall('deleteQuickCompare', qcId).then(function() {
      loadQuickCompares();
    });
  };

  window.showSaveQuickCompareDialog = function() {
    var srcId = document.getElementById('srcSelect').value;
    var tgtId = document.getElementById('tgtSelect').value;
    if (!srcId || !tgtId) {
      alert('请先选择源库和目标库');
      return;
    }
    var srcConn = state.connections.find(function(c) { return c.id === srcId; });
    var tgtConn = state.connections.find(function(c) { return c.id === tgtId; });
    var defaultName = (srcConn ? srcConn.name : '') + ' → ' + (tgtConn ? tgtConn.name : '');

    var overlay = document.createElement('div');
    overlay.className = 'confirm-overlay';
    overlay.innerHTML = '<div class="confirm-box">'
      + '<h3 style="margin:0 0 12px;">保存快速对比</h3>'
      + '<label style="display:block;font-size:12px;color:var(--text-secondary);margin-bottom:4px;">快速对比名称</label>'
      + '<input type="text" class="input" id="qcSaveName" value="' + escHtml(defaultName) + '" style="width:100%;margin-bottom:12px;">'
      + '<div style="display:flex;gap:8px;justify-content:flex-end;">'
      + '<button class="btn btn-secondary" id="qcSaveCancel">取消</button>'
      + '<button class="btn btn-primary" id="qcSaveConfirm">保存</button>'
      + '</div></div>';
    document.body.appendChild(overlay);

    var nameInput = overlay.querySelector('#qcSaveName');
    nameInput.focus();
    nameInput.select();

    overlay.querySelector('#qcSaveCancel').onclick = function() { overlay.remove(); };
    overlay.onclick = function(e) { if (e.target === overlay) overlay.remove(); };

    overlay.querySelector('#qcSaveConfirm').onclick = function() {
      var name = nameInput.value.trim();
      if (!name) { alert('请输入名称'); return; }
      bridgeCall('saveQuickCompare', {name: name, srcId: srcId, tgtId: tgtId}).then(function(result) {
        if (result && result.error) { alert('保存失败: ' + result.error); return; }
        overlay.remove();
        loadQuickCompares().then(function() {
          var sel = document.getElementById('quickCompareSelect');
          if (result && result.id) sel.value = result.id;
          loadQuickCompare();
        });
      });
    };
  };

  window.startCompare = function() {
    var srcId = document.getElementById('srcSelect').value;
    var tgtId = document.getElementById('tgtSelect').value;
    var patterns = document.getElementById('excludePatterns').value;

    state.srcId = srcId;
    state.tgtId = tgtId;

    if (!srcId || !tgtId) {
      alert('请选择源库和目标库');
      return;
    }
    if (srcId === tgtId) {
      alert('源库和目标库不能相同');
      return;
    }

    // Collect scope
    var scope = [];
    document.querySelectorAll('#scopeGroup input[type="checkbox"]:checked').forEach(function(cb) {
      scope.push(cb.value);
    });

    // Save settings
    bridgeCall('saveSettings', {excludePatterns: patterns, compareScope: scope}).catch(function() {});

    var params = {srcId: srcId, tgtId: tgtId, excludePatterns: patterns, compareScope: scope};

    showLoading('正在比对...\n连接数据库并获取表结构中，请稍候', true);
    bridgeCall('compare', params).then(function(result) {
      if (!result || result.error) {
        hideLoading();
        alert('启动比对失败: ' + (result ? result.error : '未知错误'));
        return;
      }
      // Compare started asynchronously — poll for result
      if (result.status === 'started') {
        pollCompareResult();
      } else {
        hideLoading();
        alert('意外的响应: ' + JSON.stringify(result));
      }
    }).catch(function(err) {
      hideLoading();
      showError('启动比对失败: ' + (typeof err === 'string' ? err : JSON.stringify(err)));
    });
  };

  function pollCompareResult() {
    var timer = setInterval(function() {
      bridgeCall('getCompareResult').then(function(result) {
        if (result.phase === 'done') {
          clearInterval(timer);
          hideLoading();
          state.diffItems = result.items || [];
          renderDiffTable(result);
          // Show badge
          var badge = document.getElementById('resultBadge');
          badge.textContent = result.total || 0;
          badge.style.display = (result.total > 0) ? 'inline' : 'none';
          // Update summary
          var summary = '';
          if (result.srcName) summary += result.srcName + ' → ';
          if (result.tgtName) summary += result.tgtName + ' | ';
          summary += '共 ' + (result.total || 0) + ' 处差异';
          document.getElementById('resultSummary').textContent = summary;
          // Switch to results page
          switchPage('results');
        } else if (result.phase === 'error') {
          clearInterval(timer);
          hideLoading();
          showError('比对失败: ' + (result.error || '未知错误'));
        }
        // else: still running, keep polling
      }).catch(function(err) {
        clearInterval(timer);
        hideLoading();
        showError('查询比对状态失败: ' + err);
      });
    }, 300);
    state.comparePollTimer = timer;
  }

  window.cancelCompare = function() {
    if (state.comparePollTimer) {
      clearInterval(state.comparePollTimer);
      state.comparePollTimer = null;
    }
    bridgeCall('cancelCompare').then(function() {
      hideLoading();
    }).catch(function() {
      hideLoading();
    });
  };

  // ════════════════════════════════════════════════════
  //  Diff Results
  // ════════════════════════════════════════════════════

  function renderDiffTable(result) {
    var total = result.total || 0;
    var items = result.items || [];

    if (total === 0) {
      document.getElementById('diffBody').innerHTML = '';
      document.getElementById('emptyResult').style.display = 'flex';
      document.getElementById('resultsToolbar').style.display = 'none';
      document.getElementById('resultsFooter').style.display = 'none';
      return;
    }

    document.getElementById('emptyResult').style.display = 'none';
    document.getElementById('resultsToolbar').style.display = 'flex';
    document.getElementById('resultsFooter').style.display = 'flex';

    var html = items.map(function(item, idx) {
      var riskClass = item.risk === 'SAFE' ? 'risk-safe' : (item.risk === 'WARN' ? 'risk-warn' : 'risk-high');
      var checked = item.checked !== false ? 'checked' : '';
      var detail = escHtml(item.detail || '').substring(0, 200);
      return '<tr>' +
        '<td class="col-check"><input type="checkbox" ' + checked + ' onchange="toggleRow(' + idx + ', this.checked)"></td>' +
        '<td>' + escHtml(item.type) + '</td>' +
        '<td>' + escHtml(item.name) + '</td>' +
        '<td><span class="risk-badge ' + riskClass + '">' + escHtml(item.risk) + '</span></td>' +
        '<td class="detail-cell" title="' + detail.replace(/"/g, '&quot;') + '">' + detail + '</td>' +
        '</tr>';
    }).join('');

    document.getElementById('diffBody').innerHTML = html;

    // Store checked state
    items.forEach(function(item, idx) {
      state.diffItems[idx] = item;
    });

    updateSelectCount();
  }

  window.toggleRow = function(idx, checked) {
    if (state.diffItems[idx]) {
      state.diffItems[idx].checked = checked;
    }
    updateSelectCount();
  };

  window.selectAll = function(checked) {
    document.querySelectorAll('#diffBody input[type="checkbox"]').forEach(function(cb, idx) {
      cb.checked = checked;
      if (state.diffItems[idx]) state.diffItems[idx].checked = checked;
    });
    updateSelectCount();
  };

  window.selectByRisk = function(risk) {
    document.querySelectorAll('#diffBody tr').forEach(function(tr, idx) {
      var item = state.diffItems[idx];
      if (!item) return;
      var cb = tr.querySelector('input[type="checkbox"]');
      if (!cb) return;
      var isMatch = item.risk === risk;
      cb.checked = isMatch;
      if (state.diffItems[idx]) state.diffItems[idx].checked = isMatch;
    });
    updateSelectCount();
  };

  function updateSelectCount() {
    var total = state.diffItems.length;
    var selected = state.diffItems.filter(function(i) { return i.checked; }).length;
    document.getElementById('selectCount').textContent = '已选 ' + selected + '/' + total;
  }

  // ════════════════════════════════════════════════════
  //  SQL Generation
  // ════════════════════════════════════════════════════

  window.generateSql = function() {
    var selected = state.diffItems.filter(function(i) { return i.checked !== false && i.checked; });
    if (selected.length === 0) {
      alert('请先勾选需要迁移的差异项');
      return;
    }

    showLoading('正在生成 SQL...');
    bridgeCall('generateSql', selected).then(function(result) {
      hideLoading();
      if (!result || result.error) {
        showError(result ? result.error : '生成失败');
        return;
      }
      state.generatedSql = result.sql || '';
      document.getElementById('sqlOutput').value = state.generatedSql;
      switchPage('sql');
    }).catch(function(err) {
      hideLoading();
      showError('生成 SQL 失败: ' + (typeof err === 'string' ? err : JSON.stringify(err)));
    });
  };

  // ════════════════════════════════════════════════════
  //  SQL Preview
  // ════════════════════════════════════════════════════

  window.copySql = function() {
    var text = document.getElementById('sqlOutput').value;
    if (!text) { alert('没有 SQL 内容可复制'); return; }

    showLoading('复制到剪贴板...');
    bridgeCall('copyToClipboard', text).then(function(result) {
      hideLoading();
      if (result && result.ok) {
        showToast('✅ SQL 已复制到剪贴板');
      } else {
        alert('⚠ 复制失败，请手动复制');
      }
    }).catch(function(err) {
      hideLoading();
      alert('复制失败: ' + err);
    });
  };

  window.showSaveNotification = function(path, filename) {
    var el = document.getElementById('saveNotification');
    var textEl = document.getElementById('saveNotifText');
    if (!el || !textEl) return;
    textEl.innerHTML = '✅ 已保存到 <a href="#" id="saveNotifLink">' + filename + '</a>';
    document.getElementById('saveNotifLink').onclick = function(e) {
      e.preventDefault();
      bridgeCall('openDirectory', path);
    };
    el.style.display = '';
  };

  window.closeSaveNotification = function() {
    var el = document.getElementById('saveNotification');
    if (el) el.style.display = 'none';
  };

  window.saveSql = function() {
    var text = document.getElementById('sqlOutput').value;
    if (!text) { alert('没有 SQL 内容可保存'); return; }

    showLoading('保存中...');
    bridgeCall('saveSqlFile', text).then(function(result) {
      hideLoading();
      closeSaveNotification(); // dismiss any previous
      if (result && result.ok) {
        showSaveNotification(result.path, result.filename);
      } else {
        alert('保存失败: ' + (result && result.error ? result.error : '未知错误'));
      }
    }).catch(function(err) {
      hideLoading();
      alert('保存失败: ' + err);
    });
  };

  window.clearSql = function() {
    var ta = document.getElementById('sqlOutput');
    if (!ta.value) return;
    var overlay = document.createElement('div');
    overlay.className = 'confirm-overlay';
    overlay.innerHTML = '<div class="confirm-box">' +
      '<h3>确认清空</h3>' +
      '<p>确定清空 SQL 内容？<br>此操作不可撤销。</p>' +
      '<div class="confirm-actions">' +
        '<button class="btn" onclick="this.closest(\'.confirm-overlay\').remove()">取消</button>' +
        '<button class="btn btn-danger" id="confirmClearSqlBtn">清空</button>' +
      '</div></div>';
    document.body.appendChild(overlay);
    overlay.addEventListener('click', function(e) {
      if (e.target === overlay) overlay.remove();
    });
    document.getElementById('confirmClearSqlBtn').addEventListener('click', function() {
      overlay.remove();
      ta.value = '';
      state.generatedSql = '';
    });
  };

  // ════════════════════════════════════════════════════
  //  Terminal / Stdout
  // ════════════════════════════════════════════════════

  window.clearTerminal = function() {
    state.terminalLines = [];
    state.terminalLastOffset = 0;
    document.getElementById('terminalOutput').innerHTML = '';
    document.getElementById('terminalLineCount').textContent = '0 行';
  };

  window.toggleAutoScroll = function() {
    state.terminalAutoScroll = !state.terminalAutoScroll;
    var btn = document.getElementById('terminalAutoScrollBtn');
    btn.textContent = '📌 自动滚动: ' + (state.terminalAutoScroll ? '开' : '关');
  };

  function pollTerminal() {
    bridgeCall('getStdout').then(function(result) {
      if (result && result.lines && result.lines.length > 0) {
        var output = document.getElementById('terminalOutput');
        var wasAtBottom = output.scrollHeight - output.scrollTop - output.clientHeight < 30;

        result.lines.forEach(function(line) {
          var div = document.createElement('div');
          div.className = 'log-line';
          var tsMatch = line.match(/^\[(\d{2}:\d{2}:\d{2}\.\d{3})\]\s*\[(\w+)\]\s*(.*)$/);
          if (tsMatch) {
            div.innerHTML = '<span class="log-ts">[' + escHtml(tsMatch[1]) + ']</span> ' +
              '<span class="log-level log-level-' + tsMatch[2].toLowerCase() + '">[' + escHtml(tsMatch[2]) + ']</span> ' +
              escHtml(tsMatch[3]);
          } else {
            div.textContent = line;
          }
          output.appendChild(div);
          state.terminalLines.push(line);
        });

        document.getElementById('terminalLineCount').textContent = state.terminalLines.length + ' 行';

        if (state.terminalAutoScroll && wasAtBottom) {
          output.scrollTop = output.scrollHeight;
        }

        var dot = document.getElementById('terminalDot');
        if (dot) dot.style.display = 'inline';
      }
    }).catch(function() {});
  }

  function startTerminalPoll() {
    if (state.terminalPollTimer) return;
    state.terminalPollTimer = setInterval(pollTerminal, 500);
  }

  function stopTerminalPoll() {
    if (state.terminalPollTimer) {
      clearInterval(state.terminalPollTimer);
      state.terminalPollTimer = null;
    }
  }

  // ════════════════════════════════════════════════════
  //  Utilities
  // ════════════════════════════════════════════════════

  function escHtml(s) {
    if (typeof s !== 'string') return String(s || '');
    return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  // ════════════════════════════════════════════════════
  //  About Dialog
  // ════════════════════════════════════════════════════

  window.showAboutDialog = function() {
    var overlay = document.createElement('div');
    overlay.className = 'confirm-overlay';
    overlay.innerHTML =
      '<div class="confirm-box" style="max-width:380px;text-align:center;">' +
        '<div style="font-size:2.5rem;margin-bottom:0.25rem;">⚡</div>' +
        '<h3 style="font-size:1rem;margin:0 0 0.25rem;">MySQL SchemaSync</h3>' +
        '<p style="font-size:0.8125rem;color:var(--pico-muted-color);margin:0 0 1rem;line-height:1.5;">' +
          'MySQL 数据库结构对比与迁移 SQL 生成工具' +
        '</p>' +
        '<div style="font-size:0.8125rem;margin-bottom:1rem;line-height:2;">' +
          '<div>版本：<strong>v1.0</strong></div>' +
          '<div>' +
            '<a href="https://github.com/yangweijie/mysql-scheme-sync" ' +
               'class="about-link" target="_blank">' +
              'github.com/yangweijie/mysql-scheme-sync' +
            '</a>' +
          '</div>' +
          '<div>作者：yangweijie</div>' +
        '</div>' +
        '<button class="btn" onclick="this.closest(\'.confirm-overlay\').remove()">关闭</button>' +
      '</div>';
    document.body.appendChild(overlay);
    overlay.addEventListener('click', function(e) {
      if (e.target === overlay) overlay.remove();
    });
  };

  // ════════════════════════════════════════════════════
  //  Initialization
  // ════════════════════════════════════════════════════

  // Show app after init script loads
  document.getElementById('app').style.display = 'flex';
  document.getElementById('app-loader').style.display = 'none';

  setTimeout(function() {
    loadConnections(true);
    loadComparePage();
    startTerminalPoll();
  }, 100);

})();
