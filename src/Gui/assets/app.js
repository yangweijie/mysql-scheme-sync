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
  };

  // ════════════════════════════════════════════════════
  //  Bridge wrapper with built-in error handling
  // ════════════════════════════════════════════════════

  function bridgeCall(method, data) {
    return window.__webview__.call(method, data);
  }

  function showLoading(text) {
    state.isLoading = true;
    document.getElementById('loadingText').textContent = text || '处理中...';
    document.getElementById('loadingOverlay').style.display = 'flex';
  }

  function hideLoading() {
    state.isLoading = false;
    document.getElementById('loadingOverlay').style.display = 'none';
  }

  function showError(msg) {
    hideLoading();
    alert('错误: ' + msg);
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

  function loadConnections() {
    showLoading('加载连接列表...');
    bridgeCall('getConnections').then(function(conns) {
      state.connections = conns || [];
      renderConnList();
      populateCompareSelects();
      hideLoading();
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
      return '<div class="conn-item" onclick="editConnection(\'' + c.id + '\')" data-id="' + c.id + '">' +
        '<div class="conn-item-name">' + escHtml(c.name) + '</div>' +
        '<div class="conn-item-detail">' + escHtml(c.host) + ':' + c.port + '/' + escHtml(c.database) + '</div>' +
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

    // Restore selection
    if (state.srcId) src.value = state.srcId;
    if (state.tgtId) tgt.value = state.tgtId;
  }

  function loadComparePage() {
    // Load settings
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

    showLoading('正在比对...\n连接数据库并获取表结构中，请稍候');
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
  }

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
        alert('✅ SQL 已复制到剪贴板');
      } else {
        alert('⚠ 复制失败，请手动复制');
      }
    }).catch(function(err) {
      hideLoading();
      alert('复制失败: ' + err);
    });
  };

  window.saveSql = function() {
    var text = document.getElementById('sqlOutput').value;
    if (!text) { alert('没有 SQL 内容可保存'); return; }

    // Use JS Blob + download link (works in WebView2)
    try {
      var blob = new Blob([text], {type: 'text/plain;charset=utf-8'});
      var url = URL.createObjectURL(blob);
      var a = document.createElement('a');
      a.href = url;
      a.download = 'migration_' + new Date().toISOString().slice(0,10) + '.sql';
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      URL.revokeObjectURL(url);
    } catch(e) {
      alert('保存失败: ' + e.message);
    }
  };

  window.clearSql = function() {
    if (document.getElementById('sqlOutput').value && !confirm('确定清空 SQL 内容？')) return;
    document.getElementById('sqlOutput').value = '';
    state.generatedSql = '';
  };

  // ════════════════════════════════════════════════════
  //  Utilities
  // ════════════════════════════════════════════════════

  function escHtml(s) {
    if (typeof s !== 'string') return String(s || '');
    return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  // ════════════════════════════════════════════════════
  //  Initialization
  // ════════════════════════════════════════════════════

  // Show app after init script loads
  document.getElementById('app').style.display = 'flex';
  document.getElementById('app-loader').style.display = 'none';

  // Load connections on startup
  setTimeout(function() {
    loadConnections();
    loadComparePage();
  }, 100);

})();
