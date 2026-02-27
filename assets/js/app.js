(function () {
  'use strict';

  var defaults = window.APP_DEFAULTS || {};

  var urlsInput = document.getElementById('urlsInput');
  var keywordsInput = document.getElementById('keywordsInput');
  var delayInput = document.getElementById('delayInput');
  var startBtn = document.getElementById('startBtn');
  var stopBtn = document.getElementById('stopBtn');
  var saveBtn = document.getElementById('saveBtn');
  var clearStateBtn = document.getElementById('clearStateBtn');
  var loadStateBtn = document.getElementById('loadStateBtn');
  var openCurrentBtn = document.getElementById('openCurrentBtn');
  var openSigninBtn = document.getElementById('openSigninBtn');
  var statusText = document.getElementById('statusText');
  var persistText = document.getElementById('persistText');
  var pendingCount = document.getElementById('pendingCount');
  var removedCount = document.getElementById('removedCount');
  var currentUrlText = document.getElementById('currentUrlText');
  var currentUrlFull = document.getElementById('currentUrlFull');
  var pendingList = document.getElementById('pendingList');
  var logList = document.getElementById('logList');

  if (
    !urlsInput ||
    !keywordsInput ||
    !delayInput ||
    !startBtn ||
    !stopBtn ||
    !statusText ||
    !pendingList ||
    !logList
  ) {
    return;
  }

  var state = {
    running: false,
    busyStart: false,
    busyStop: false,
    pending: [],
    keywords: [],
    currentIndex: 0,
    currentUrl: '',
    removed: 0,
    message: 'idle',
    logs: [],
    pollTimer: null,
    saveTimer: null,
    lastSavedAt: '',
    hadStatusError: false
  };

  function normalizeUrls(rawText) {
    var lines = String(rawText || '').split(/\r?\n/);
    var out = [];
    var seen = {};
    var i;

    for (i = 0; i < lines.length; i += 1) {
      var candidate = lines[i].trim();
      if (!candidate) {
        continue;
      }

      try {
        var parsed = new URL(candidate);
        if (!/^https?:$/.test(parsed.protocol)) {
          continue;
        }
        var url = parsed.toString();
        if (!seen[url]) {
          seen[url] = true;
          out.push(url);
        }
      } catch (error) {
        // ignore invalid URL
      }
    }

    return out;
  }

  function normalizeKeywords(rawText) {
    var parts = String(rawText || '').split(/[\r\n,;]+/);
    var out = [];
    var seen = {};
    var i;

    for (i = 0; i < parts.length; i += 1) {
      var keyword = parts[i].trim();
      if (!keyword) {
        continue;
      }
      keyword = keyword.slice(0, 120);
      var key = keyword.toLowerCase();
      if (!seen[key]) {
        seen[key] = true;
        out.push(keyword);
      }
      if (out.length >= 100) {
        break;
      }
    }

    return out;
  }

  function parseAnyUrls(raw) {
    if (Array.isArray(raw)) {
      return normalizeUrls(raw.join('\n'));
    }
    return normalizeUrls(raw);
  }

  function parseAnyKeywords(raw) {
    if (Array.isArray(raw)) {
      return normalizeKeywords(raw.join('\n'));
    }
    return normalizeKeywords(raw);
  }

  function parseIntSafe(value, fallback) {
    var parsed = parseInt(value, 10);
    if (!isFinite(parsed)) {
      return fallback;
    }
    return parsed;
  }

  function shortUrl(url) {
    if (!url) {
      return '-';
    }
    return url.length > 45 ? url.slice(0, 42) + '...' : url;
  }

  function clampCurrentIndex() {
    if (!state.pending.length) {
      state.currentIndex = 0;
      return;
    }
    if (state.currentIndex < 0 || state.currentIndex >= state.pending.length) {
      state.currentIndex = 0;
    }
  }

  function syncUrlTextarea() {
    urlsInput.value = state.pending.join('\n');
  }

  function syncKeywordsTextarea() {
    keywordsInput.value = state.keywords.join('\n');
  }

  function renderPending() {
    pendingList.innerHTML = '';
    var activeUrl = getCurrentUrl();

    var i;
    for (i = 0; i < state.pending.length; i += 1) {
      var li = document.createElement('li');
      li.textContent = state.pending[i];
      if (activeUrl && state.pending[i] === activeUrl) {
        li.className = 'active';
      }
      pendingList.appendChild(li);
    }
  }

  function renderLogs() {
    logList.innerHTML = '';
    var logs = Array.isArray(state.logs) ? state.logs : [];
    var i;
    for (i = 0; i < logs.length; i += 1) {
      var item = logs[i] || {};
      var li = document.createElement('li');
      li.className = item.level || 'note';

      var stamp = '';
      if (item.time) {
        var date = new Date(item.time);
        if (!isNaN(date.getTime())) {
          stamp = '[' + date.toLocaleTimeString() + '] ';
        }
      }

      li.textContent = stamp + String(item.message || '');
      logList.appendChild(li);
    }
  }

  function pushClientLog(message, level) {
    state.logs.unshift({
      time: new Date().toISOString(),
      level: level || 'note',
      message: String(message || '')
    });
    if (state.logs.length > 300) {
      state.logs = state.logs.slice(0, 300);
    }
    renderLogs();
  }

  function getCurrentUrl() {
    if (state.currentUrl) {
      return state.currentUrl;
    }
    if (!state.pending.length) {
      return '';
    }
    clampCurrentIndex();
    return state.pending[state.currentIndex] || '';
  }

  function updateCounters() {
    pendingCount.textContent = String(state.pending.length);
    removedCount.textContent = String(state.removed);

    var current = getCurrentUrl();
    currentUrlText.textContent = shortUrl(current || '-');
    if (currentUrlFull) {
      currentUrlFull.value = current || '-';
    }
  }

  function setStatus(label, modeClass) {
    statusText.textContent = label;
    statusText.className = modeClass;
  }

  function setPersistText(label, modeClass) {
    if (!persistText) {
      return;
    }
    persistText.textContent = label;
    persistText.className = modeClass;
  }

  function setStatusFromState() {
    if (state.running) {
      setStatus('Escaneando', 'status-run');
      return;
    }

    var msg = String(state.message || '').toLowerCase();
    if (msg.indexOf('fallo') !== -1 || msg.indexOf('error') !== -1) {
      setStatus('Error', 'status-stop');
      return;
    }
    if (msg.indexOf('completado') !== -1) {
      setStatus('Completado', 'status-idle');
      return;
    }
    setStatus('Detenido', 'status-idle');
  }

  function setControls() {
    var running = !!state.running;
    var startBusy = !!state.busyStart;
    var stopBusy = !!state.busyStop;

    startBtn.disabled = running || startBusy;
    stopBtn.disabled = !running || stopBusy;

    urlsInput.disabled = running;
    keywordsInput.disabled = running;
    delayInput.disabled = running;
  }

  function getDelay() {
    var value = parseIntSafe(delayInput.value, 12000);
    if (value < 1000) {
      return 1000;
    }
    if (value > 300000) {
      return 300000;
    }
    return value;
  }

  function buildPersistPayload(reason) {
    return {
      pending_urls: state.pending.slice(),
      keywords: state.keywords.slice(),
      delay_ms: getDelay(),
      removed_count: state.removed,
      current_index: state.currentIndex,
      reason: reason || 'auto'
    };
  }

  function markPersistOk(isoDate) {
    state.lastSavedAt = isoDate || new Date().toISOString();
    var label = 'Persistencia: guardado';
    if (state.lastSavedAt) {
      var date = new Date(state.lastSavedAt);
      if (!isNaN(date.getTime())) {
        label = 'Persistencia: guardado ' + date.toLocaleTimeString();
      }
    }
    setPersistText(label, 'persist-ok');
  }

  function markPersistWarn(text) {
    setPersistText(text || 'Persistencia: pendiente', 'persist-warn');
  }

  function markPersistErr(text) {
    setPersistText(text || 'Persistencia: error', 'persist-err');
  }

  async function persistState(reason) {
    var payload = buildPersistPayload(reason);

    try {
      var response = await fetch('state.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json'
        },
        body: JSON.stringify(payload),
        cache: 'no-store'
      });

      var data = await response.json();
      if (!response.ok || !data.ok) {
        markPersistErr('Persistencia: error al guardar');
        return false;
      }

      if (data.state && data.state.updated_at) {
        markPersistOk(data.state.updated_at);
      } else {
        markPersistOk(new Date().toISOString());
      }
      return true;
    } catch (error) {
      markPersistErr('Persistencia: sin conexion');
      return false;
    }
  }

  function persistStateBeacon(reason) {
    if (!navigator.sendBeacon) {
      return;
    }

    var payload = buildPersistPayload(reason || 'beforeunload');
    var params = new URLSearchParams();
    params.append('pending_urls', payload.pending_urls.join('\n'));
    params.append('keywords', payload.keywords.join('\n'));
    params.append('delay_ms', String(payload.delay_ms));
    params.append('removed_count', String(payload.removed_count));
    params.append('current_index', String(payload.current_index));
    params.append('reason', payload.reason);
    navigator.sendBeacon('state.php', params);
  }

  function queueSave(reason) {
    if (state.running) {
      return;
    }
    if (state.saveTimer !== null) {
      clearTimeout(state.saveTimer);
    }
    state.saveTimer = setTimeout(function () {
      state.saveTimer = null;
      persistState(reason || 'autosave');
    }, 450);
  }

  async function clearPersistedState() {
    try {
      var response = await fetch('state.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({
          action: 'clear'
        }),
        cache: 'no-store'
      });
      var data = await response.json();
      if (!response.ok || !data.ok) {
        markPersistErr('Persistencia: no se pudo limpiar');
        return false;
      }
      markPersistWarn('Persistencia: limpia');
      return true;
    } catch (error) {
      markPersistErr('Persistencia: error al limpiar');
      return false;
    }
  }

  function applyLoadedState(remoteState) {
    var pending = parseAnyUrls(remoteState.pending_urls);
    var keywords = parseAnyKeywords(remoteState.keywords);

    if (pending.length > 0) {
      state.pending = pending;
    }

    if (keywords.length > 0) {
      state.keywords = keywords;
    }

    var savedDelay = parseIntSafe(remoteState.delay_ms, getDelay());
    if (savedDelay >= 1000) {
      delayInput.value = String(savedDelay);
    }

    var savedRemoved = parseIntSafe(remoteState.removed_count, 0);
    if (savedRemoved >= 0) {
      state.removed = savedRemoved;
    }

    state.currentIndex = parseIntSafe(remoteState.current_index, 0);
    clampCurrentIndex();

    syncUrlTextarea();
    syncKeywordsTextarea();
    renderPending();
    updateCounters();
  }

  async function loadPersistedState(showLog) {
    try {
      var response = await fetch('state.php?ts=' + encodeURIComponent(String(Date.now())), {
        cache: 'no-store'
      });
      var data = await response.json();

      if (!response.ok || !data.ok || !data.state) {
        markPersistWarn('Persistencia: sin estado');
        return false;
      }

      applyLoadedState(data.state);

      if (data.state.updated_at) {
        markPersistOk(data.state.updated_at);
      } else {
        markPersistOk(new Date().toISOString());
      }

      if (showLog) {
        pushClientLog('Estado cargado desde servidor.', 'note');
      }

      return true;
    } catch (error) {
      markPersistErr('Persistencia: no disponible');
      return false;
    }
  }

  function normalizeRemoteLogs(logs) {
    if (!Array.isArray(logs)) {
      return [];
    }
    var out = [];
    var i;
    for (i = 0; i < logs.length; i += 1) {
      var item = logs[i] || {};
      out.push({
        time: String(item.time || ''),
        level: String(item.level || 'note'),
        message: String(item.message || '')
      });
      if (out.length >= 300) {
        break;
      }
    }
    return out;
  }

  function applyAutomationState(remoteState, options) {
    var opts = options || {};
    if (!remoteState || typeof remoteState !== 'object') {
      return;
    }

    if (Array.isArray(remoteState.pending_urls)) {
      state.pending = parseAnyUrls(remoteState.pending_urls);
    }

    if (Array.isArray(remoteState.keywords) && remoteState.keywords.length > 0) {
      state.keywords = parseAnyKeywords(remoteState.keywords);
    }

    state.running = !!remoteState.running;
    state.currentIndex = parseIntSafe(remoteState.current_index, 0);
    state.currentUrl = String(remoteState.current_url || '');
    state.removed = Math.max(parseIntSafe(remoteState.removed_count, state.removed), 0);
    state.message = String(remoteState.message || state.message || '');

    if (Array.isArray(remoteState.logs)) {
      state.logs = normalizeRemoteLogs(remoteState.logs);
    }

    clampCurrentIndex();

    if (!opts.skipSyncInputs) {
      syncUrlTextarea();
      syncKeywordsTextarea();
    }

    renderPending();
    renderLogs();
    updateCounters();
    setStatusFromState();
    setControls();

    if (remoteState.updated_at) {
      markPersistOk(remoteState.updated_at);
    }
  }

  async function automationApi(action, payload) {
    var body = payload || {};
    body.action = action;

    var response = await fetch('automation.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json'
      },
      body: JSON.stringify(body),
      cache: 'no-store'
    });

    var data = null;
    try {
      data = await response.json();
    } catch (error) {
      data = {};
    }

    return {
      ok: response.ok && data && data.ok,
      status: response.status,
      data: data || {}
    };
  }

  async function fetchAutomationStatus() {
    var response = await fetch('automation.php?action=status&ts=' + encodeURIComponent(String(Date.now())), {
      cache: 'no-store'
    });
    var data = null;
    try {
      data = await response.json();
    } catch (error) {
      data = {};
    }
    return {
      ok: response.ok && data && data.ok,
      status: response.status,
      data: data || {}
    };
  }

  function scheduleStatusPoll(ms) {
    if (state.pollTimer !== null) {
      clearTimeout(state.pollTimer);
    }
    state.pollTimer = setTimeout(function () {
      state.pollTimer = null;
      pollAutomationStatus();
    }, ms);
  }

  async function pollAutomationStatus() {
    try {
      var result = await fetchAutomationStatus();
      if (!result.ok || !result.data || !result.data.state) {
        if (!state.hadStatusError) {
          pushClientLog('No se pudo consultar estado de automatizacion.', 'err');
          state.hadStatusError = true;
        }
        scheduleStatusPoll(state.running ? 2500 : 5000);
        return;
      }

      state.hadStatusError = false;
      applyAutomationState(result.data.state);
      scheduleStatusPoll(state.running ? 2000 : 5000);
    } catch (error) {
      if (!state.hadStatusError) {
        pushClientLog('Fallo de red al consultar estado.', 'err');
        state.hadStatusError = true;
      }
      scheduleStatusPoll(state.running ? 2500 : 5000);
    }
  }

  function openUrlTopLevel(url) {
    if (!url) {
      return;
    }
    var popup = window.open(url, '_blank', 'noopener,noreferrer');
    if (!popup) {
      alert('El navegador bloqueo la ventana. Permite popups para este sitio.');
    }
  }

  async function startAutomation() {
    if (state.running || state.busyStart) {
      return;
    }

    state.pending = normalizeUrls(urlsInput.value);
    state.keywords = normalizeKeywords(keywordsInput.value);
    state.currentIndex = 0;
    clampCurrentIndex();

    if (state.pending.length === 0) {
      alert('Agrega al menos una URL valida.');
      return;
    }

    if (state.keywords.length === 0) {
      alert('Agrega al menos una palabra a buscar.');
      return;
    }

    state.busyStart = true;
    setStatus('Iniciando', 'status-run');
    setControls();

    try {
      var result = await automationApi('start', {
        urls: state.pending,
        keywords: state.keywords,
        delay_ms: getDelay(),
        page_wait_ms: 8000,
        headless: true
      });

      if (!result.ok || !result.data || !result.data.state) {
        var errorText = result.data && result.data.error ? result.data.error : 'No se pudo iniciar';
        pushClientLog('Inicio fallido: ' + errorText, 'err');
        alert('No se pudo iniciar: ' + errorText);
        setStatus('Detenido', 'status-stop');
        return;
      }

      applyAutomationState(result.data.state);
      pushClientLog('Automatizacion iniciada.', 'note');
      scheduleStatusPoll(900);
    } catch (error) {
      pushClientLog('Fallo de red al iniciar.', 'err');
      alert('Fallo de red al iniciar automatizacion.');
      setStatus('Detenido', 'status-stop');
    } finally {
      state.busyStart = false;
      setControls();
    }
  }

  async function stopAutomation() {
    if (!state.running || state.busyStop) {
      return;
    }

    state.busyStop = true;
    setStatus('Deteniendo', 'status-stop');
    setControls();

    try {
      var result = await automationApi('stop', {});
      if (!result.ok) {
        var detail = result.data && result.data.error ? result.data.error : 'No se pudo detener';
        pushClientLog('Detencion fallida: ' + detail, 'err');
        alert('No se pudo detener: ' + detail);
      } else if (result.data && result.data.state) {
        applyAutomationState(result.data.state);
        pushClientLog('Detencion solicitada.', 'warn');
      }
      scheduleStatusPoll(800);
    } catch (error) {
      pushClientLog('Fallo de red al detener.', 'err');
      scheduleStatusPoll(1200);
    } finally {
      state.busyStop = false;
      setControls();
    }
  }

  function bindEvents() {
    urlsInput.addEventListener('input', function () {
      if (state.running) {
        return;
      }
      state.pending = normalizeUrls(urlsInput.value);
      clampCurrentIndex();
      syncUrlTextarea();
      renderPending();
      updateCounters();
      markPersistWarn('Persistencia: cambios pendientes');
      queueSave('edit-urls');
    });

    keywordsInput.addEventListener('input', function () {
      if (state.running) {
        return;
      }
      state.keywords = normalizeKeywords(keywordsInput.value);
      syncKeywordsTextarea();
      markPersistWarn('Persistencia: cambios pendientes');
      queueSave('edit-keywords');
    });

    delayInput.addEventListener('input', function () {
      if (state.running) {
        return;
      }
      markPersistWarn('Persistencia: ajustes pendientes');
      queueSave('edit-delay');
    });

    startBtn.addEventListener('click', function () {
      startAutomation();
    });

    stopBtn.addEventListener('click', function () {
      stopAutomation();
    });

    saveBtn.addEventListener('click', function () {
      persistState('manual-save').then(function (ok) {
        if (ok) {
          pushClientLog('Estado guardado en servidor.', 'note');
        }
      });
    });

    clearStateBtn.addEventListener('click', function () {
      var confirmed = window.confirm('Esto limpia el estado guardado. Continuar?');
      if (!confirmed) {
        return;
      }
      clearPersistedState().then(function (ok) {
        if (ok) {
          pushClientLog('Estado persistente limpiado.', 'note');
        }
      });
    });

    loadStateBtn.addEventListener('click', function () {
      if (state.running) {
        alert('Deten el worker antes de cargar otro estado.');
        return;
      }
      loadPersistedState(true);
    });

    if (openCurrentBtn) {
      openCurrentBtn.addEventListener('click', function () {
        var url = getCurrentUrl();
        if (!url) {
          alert('No hay URL actual para abrir.');
          return;
        }
        openUrlTopLevel(url);
      });
    }

    if (openSigninBtn) {
      openSigninBtn.addEventListener('click', function () {
        openUrlTopLevel('https://earnapp.com/dashboard/signin');
      });
    }

    window.addEventListener('beforeunload', function () {
      if (!state.running) {
        persistStateBeacon('beforeunload');
      }
    });
  }

  async function init() {
    state.pending = parseAnyUrls(defaults.urls || urlsInput.value);
    state.keywords = parseAnyKeywords(defaults.keywords || keywordsInput.value);
    state.currentIndex = 0;
    state.currentUrl = '';
    state.removed = 0;

    if (defaults.delay_ms) {
      delayInput.value = String(defaults.delay_ms);
    }

    syncUrlTextarea();
    syncKeywordsTextarea();
    renderPending();
    renderLogs();
    updateCounters();
    setStatus('Conectando', 'status-idle');
    setPersistText('Persistencia: cargando...', 'persist-warn');
    setControls();
    bindEvents();

    try {
      var remote = await fetchAutomationStatus();
      if (remote.ok && remote.data && remote.data.state) {
        applyAutomationState(remote.data.state);
        if (state.running) {
          pushClientLog('Worker activo detectado. Estado sincronizado.', 'note');
        } else if (state.pending.length > 0) {
          pushClientLog('Cola pendiente cargada desde runtime del servidor.', 'note');
        }
      } else {
        var loaded = await loadPersistedState(false);
        if (loaded) {
          pushClientLog('Estado persistente cargado.', 'note');
        } else {
          pushClientLog('Listo para iniciar.', 'note');
          queueSave('init-default');
        }
      }
    } catch (error) {
      var loadedState = await loadPersistedState(false);
      if (loadedState) {
        pushClientLog('Estado persistente cargado.', 'note');
      } else {
        pushClientLog('Listo para iniciar.', 'note');
      }
    }

    setStatusFromState();
    setControls();
    scheduleStatusPoll(state.running ? 1200 : 5000);
  }

  init();
})();
