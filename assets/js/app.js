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
  var statusText = document.getElementById('statusText');
  var persistText = document.getElementById('persistText');
  var pendingCount = document.getElementById('pendingCount');
  var removedCount = document.getElementById('removedCount');
  var currentUrlText = document.getElementById('currentUrlText');
  var pendingList = document.getElementById('pendingList');
  var logList = document.getElementById('logList');
  var previewFrame = document.getElementById('previewFrame');

  if (!urlsInput || !keywordsInput) {
    return;
  }

  var state = {
    running: false,
    pending: [],
    keywords: [],
    currentIndex: 0,
    removed: 0,
    timer: null,
    controller: null,
    urlsEdited: false,
    keywordsEdited: false,
    saveTimer: null,
    lastSavedAt: ''
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
        // Ignora lineas invalidas.
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

  function shortUrl(url) {
    if (!url) {
      return '-';
    }
    return url.length > 45 ? url.slice(0, 42) + '...' : url;
  }

  function renderPending() {
    pendingList.innerHTML = '';

    var i;
    for (i = 0; i < state.pending.length; i += 1) {
      var li = document.createElement('li');
      li.textContent = state.pending[i];
      if (state.running && i === state.currentIndex) {
        li.className = 'active';
      }
      pendingList.appendChild(li);
    }
  }

  function updateCounters() {
    pendingCount.textContent = String(state.pending.length);
    removedCount.textContent = String(state.removed);

    var current = state.pending[state.currentIndex] || '-';
    currentUrlText.textContent = shortUrl(current);
  }

  function log(message, level) {
    var li = document.createElement('li');
    li.className = level || 'note';
    var stamp = new Date().toLocaleTimeString();
    li.textContent = '[' + stamp + '] ' + message;
    logList.insertBefore(li, logList.firstChild);
  }

  function setStatus(text, modeClass) {
    statusText.textContent = text;
    statusText.className = modeClass;
  }

  function setPersistText(text, modeClass) {
    persistText.textContent = text;
    persistText.className = modeClass;
  }

  function setControls(running) {
    startBtn.disabled = running;
    stopBtn.disabled = !running;
  }

  function getDelay() {
    var value = parseInt(delayInput.value, 10);
    if (!isFinite(value) || value < 1000) {
      return 3500;
    }
    return value;
  }

  function syncTextarea() {
    urlsInput.value = state.pending.join('\n');
  }

  function syncKeywordsTextarea() {
    keywordsInput.value = state.keywords.join('\n');
  }

  function loadFrame(url) {
    var frameUrl = 'scan.php?action=frame&url=' + encodeURIComponent(url);
    previewFrame.src = frameUrl;
  }

  function clampCurrentIndex() {
    if (state.pending.length === 0) {
      state.currentIndex = 0;
      return;
    }
    if (state.currentIndex >= state.pending.length || state.currentIndex < 0) {
      state.currentIndex = 0;
    }
  }

  function applyManualChanges() {
    var changed = false;

    if (state.urlsEdited) {
      state.pending = normalizeUrls(urlsInput.value);
      clampCurrentIndex();
      state.urlsEdited = false;
      changed = true;
    }

    if (state.keywordsEdited) {
      state.keywords = normalizeKeywords(keywordsInput.value);
      state.keywordsEdited = false;
      changed = true;
    }

    if (!changed) {
      return;
    }

    syncTextarea();
    syncKeywordsTextarea();
    renderPending();
    updateCounters();
    queueSave('manual-change');
    log('Cambios manuales aplicados.', 'note');
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
        })
      });
      var data = await response.json();
      if (!response.ok || !data.ok) {
        markPersistErr('Persistencia: no se pudo limpiar');
        return;
      }
      markPersistWarn('Persistencia: limpia');
      log('Estado persistente del servidor limpio.', 'note');
    } catch (error) {
      markPersistErr('Persistencia: error al limpiar');
    }
  }

  function applyLoadedState(remoteState) {
    var pending = parseAnyUrls(remoteState.pending_urls);
    var keywords = parseAnyKeywords(remoteState.keywords);

    if (pending.length > 0) {
      state.pending = pending;
      urlsInput.value = pending.join('\n');
    } else {
      state.pending = normalizeUrls(urlsInput.value);
      syncTextarea();
    }

    if (keywords.length > 0) {
      state.keywords = keywords;
      keywordsInput.value = keywords.join('\n');
    } else {
      state.keywords = normalizeKeywords(keywordsInput.value);
      syncKeywordsTextarea();
    }

    var savedDelay = parseInt(remoteState.delay_ms, 10);
    if (isFinite(savedDelay) && savedDelay >= 1000) {
      delayInput.value = String(savedDelay);
    }

    var savedRemoved = parseInt(remoteState.removed_count, 10);
    if (isFinite(savedRemoved) && savedRemoved >= 0) {
      state.removed = savedRemoved;
    }

    var savedIndex = parseInt(remoteState.current_index, 10);
    if (isFinite(savedIndex) && savedIndex >= 0) {
      state.currentIndex = savedIndex;
    }
    clampCurrentIndex();

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
        log('Estado cargado desde servidor.', 'note');
      }

      return true;
    } catch (error) {
      markPersistErr('Persistencia: no disponible');
      return false;
    }
  }

  async function scanCycle() {
    if (!state.running) {
      return;
    }

    applyManualChanges();

    if (state.pending.length === 0) {
      log('No quedan URLs pendientes.', 'note');
      stopScan(false);
      return;
    }

    clampCurrentIndex();
    var url = state.pending[state.currentIndex];

    loadFrame(url);
    updateCounters();
    renderPending();

    var advance = true;

    try {
      state.controller = new AbortController();
      var response = await fetch('scan.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({
          url: url,
          keywords: state.keywords
        }),
        signal: state.controller.signal
      });

      var data = await response.json();

      if (!response.ok || !data.ok) {
        var detail = data && data.error ? data.error : 'Error al escanear';
        log('Error en ' + shortUrl(url) + ': ' + detail, 'err');
      } else if (data.matched) {
        state.pending.splice(state.currentIndex, 1);
        state.removed += 1;
        syncTextarea();
        log('Eliminada ' + shortUrl(url) + ' por "' + data.keyword + '".', 'ok');
        advance = false;
      } else {
        log('Sin coincidencia en ' + shortUrl(url) + ' (HTTP ' + data.http_code + ').', 'warn');
      }
    } catch (error) {
      if (error && error.name === 'AbortError') {
        return;
      }
      log('Fallo de red en ' + shortUrl(url) + '.', 'err');
    } finally {
      state.controller = null;
      clampCurrentIndex();
      updateCounters();
      renderPending();
    }

    if (!state.running) {
      return;
    }

    if (state.pending.length === 0) {
      queueSave('completed');
      log('Escaneo completo. Todas las URLs se eliminaron.', 'ok');
      stopScan(false);
      return;
    }

    if (advance) {
      state.currentIndex = (state.currentIndex + 1) % state.pending.length;
    }

    queueSave('scan-step');
    state.timer = setTimeout(scanCycle, getDelay());
  }

  function startScan() {
    if (state.running) {
      return;
    }

    state.pending = normalizeUrls(urlsInput.value);
    state.keywords = normalizeKeywords(keywordsInput.value);
    state.urlsEdited = false;
    state.keywordsEdited = false;
    clampCurrentIndex();

    if (state.pending.length === 0) {
      alert('Agrega al menos una URL valida.');
      return;
    }

    if (state.keywords.length === 0) {
      alert('Agrega al menos una palabra a buscar.');
      return;
    }

    syncTextarea();
    syncKeywordsTextarea();
    renderPending();
    updateCounters();

    state.running = true;
    setControls(true);
    setStatus('Escaneando', 'status-run');
    log('Escaneo iniciado. URLs: ' + state.pending.length + '. Palabras: ' + state.keywords.length + '.', 'note');
    queueSave('scan-start');
    scanCycle();
  }

  function stopScan(manualStop) {
    var wasRunning = state.running;
    state.running = false;

    if (state.timer !== null) {
      clearTimeout(state.timer);
      state.timer = null;
    }

    if (state.controller) {
      state.controller.abort();
      state.controller = null;
    }

    setControls(false);
    setStatus('Detenido', wasRunning ? 'status-stop' : 'status-idle');
    renderPending();
    updateCounters();
    queueSave(manualStop ? 'scan-stop-manual' : 'scan-stop-auto');

    if (manualStop && wasRunning) {
      log('Escaneo finalizado por usuario.', 'note');
    }
  }

  function bindEvents() {
    urlsInput.addEventListener('input', function () {
      if (state.running) {
        state.urlsEdited = true;
        markPersistWarn('Persistencia: cambios en cola pendientes');
        return;
      }
      state.pending = normalizeUrls(urlsInput.value);
      clampCurrentIndex();
      syncTextarea();
      renderPending();
      updateCounters();
      queueSave('edit-urls');
    });

    keywordsInput.addEventListener('input', function () {
      if (state.running) {
        state.keywordsEdited = true;
        markPersistWarn('Persistencia: cambios en palabras pendientes');
        return;
      }
      state.keywords = normalizeKeywords(keywordsInput.value);
      syncKeywordsTextarea();
      queueSave('edit-keywords');
    });

    delayInput.addEventListener('input', function () {
      markPersistWarn('Persistencia: ajustes pendientes');
      queueSave('edit-delay');
    });

    startBtn.addEventListener('click', function () {
      startScan();
    });

    stopBtn.addEventListener('click', function () {
      stopScan(true);
    });

    saveBtn.addEventListener('click', function () {
      persistState('manual-save').then(function (ok) {
        if (ok) {
          log('Estado guardado en servidor.', 'note');
        }
      });
    });

    clearStateBtn.addEventListener('click', function () {
      var confirmed = window.confirm('Esto limpia el estado guardado del servidor. Continuar?');
      if (!confirmed) {
        return;
      }
      clearPersistedState();
    });

    loadStateBtn.addEventListener('click', function () {
      loadPersistedState(true);
    });

    window.addEventListener('beforeunload', function () {
      persistStateBeacon('beforeunload');
    });
  }

  async function init() {
    state.pending = parseAnyUrls(defaults.urls || urlsInput.value);
    state.keywords = parseAnyKeywords(defaults.keywords || keywordsInput.value);
    state.currentIndex = 0;
    state.removed = 0;

    urlsInput.value = state.pending.join('\n');
    keywordsInput.value = state.keywords.join('\n');
    if (defaults.delay_ms) {
      delayInput.value = String(defaults.delay_ms);
    }

    setControls(false);
    setStatus('Detenido', 'status-idle');
    setPersistText('Persistencia: cargando...', 'persist-warn');
    renderPending();
    updateCounters();
    bindEvents();

    var loaded = await loadPersistedState(false);
    if (loaded && state.pending.length > 0) {
      log('Estado pendiente restaurado. Puedes continuar el escaneo.', 'note');
    } else {
      log('Listo para iniciar.', 'note');
      queueSave('init-default');
    }
  }

  init();
})();
