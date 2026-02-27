<?php

$urlsFile = __DIR__ . DIRECTORY_SEPARATOR . 'urls.txt';
$defaultUrls = '';
$defaultKeywords = "Sucessfull\nSuccessful\nAlready";

if (is_file($urlsFile) && is_readable($urlsFile)) {
    $defaultUrls = trim((string) file_get_contents($urlsFile));
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Escaner EarnApp</title>
  <style>
    :root {
      --bg: #f4f6fb;
      --panel: #ffffff;
      --line: #d8deeb;
      --ink: #1f2430;
      --muted: #5a6578;
      --ok: #0f7a4a;
      --warn: #8c5a12;
      --danger: #a12c2c;
      --accent: #1055cc;
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      font-family: Segoe UI, Tahoma, sans-serif;
      color: var(--ink);
      background: linear-gradient(160deg, #e9eefc 0%, #f8fafc 45%, #eef6f3 100%);
      min-height: 100vh;
    }
    .layout {
      width: min(1400px, 96vw);
      margin: 18px auto;
      display: grid;
      grid-template-columns: 420px 1fr;
      gap: 16px;
    }
    .card {
      background: var(--panel);
      border: 1px solid var(--line);
      border-radius: 10px;
      box-shadow: 0 8px 24px rgba(0, 0, 0, 0.05);
      overflow: hidden;
    }
    .card-head {
      padding: 12px 14px;
      border-bottom: 1px solid var(--line);
      background: #f9fbff;
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 10px;
    }
    .card-head h1,
    .card-head h2 {
      margin: 0;
      font-size: 16px;
    }
    .controls {
      padding: 12px 14px;
      border-bottom: 1px solid var(--line);
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 8px;
      align-items: center;
    }
    label {
      font-size: 12px;
      color: var(--muted);
      display: block;
      margin-bottom: 4px;
    }
    textarea {
      width: 100%;
      min-height: 250px;
      resize: vertical;
      border: 1px solid var(--line);
      border-radius: 8px;
      padding: 10px;
      font: 13px/1.4 Consolas, monospace;
      color: #111826;
      background: #fcfdff;
    }
    .urls-wrap { padding: 12px 14px; }
    .keywords-wrap {
      padding: 0 14px 14px;
    }
    textarea.keyword-field {
      min-height: 90px;
    }
    input[type="number"] {
      width: 100%;
      border: 1px solid var(--line);
      border-radius: 8px;
      padding: 9px 10px;
      font-size: 14px;
      background: #fff;
    }
    button {
      border: 0;
      border-radius: 8px;
      padding: 10px 12px;
      font-weight: 600;
      cursor: pointer;
    }
    #startBtn {
      background: var(--accent);
      color: #fff;
    }
    #stopBtn {
      background: #eef1f8;
      color: #24354f;
    }
    button:disabled {
      cursor: not-allowed;
      opacity: 0.55;
    }
    .meta {
      padding: 8px 14px 12px;
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 8px;
      font-size: 12px;
    }
    .pill {
      border: 1px solid var(--line);
      border-radius: 999px;
      padding: 6px 10px;
      text-align: center;
      background: #fafcff;
      color: #22334f;
    }
    .status-idle { color: #3f4a5d; }
    .status-run { color: var(--ok); }
    .status-stop { color: var(--danger); }
    .content-grid {
      display: grid;
      grid-template-rows: 1fr 230px;
      gap: 16px;
      height: calc(100vh - 36px);
      min-height: 680px;
    }
    iframe {
      width: 100%;
      height: 100%;
      border: 0;
      background: #fff;
    }
    .list-wrap {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 12px;
      padding: 12px;
      height: 100%;
    }
    .list-box {
      border: 1px solid var(--line);
      border-radius: 8px;
      background: #fff;
      overflow: hidden;
      display: flex;
      flex-direction: column;
      min-height: 0;
    }
    .list-title {
      margin: 0;
      padding: 8px 10px;
      border-bottom: 1px solid var(--line);
      font-size: 13px;
      background: #f7faff;
    }
    ol, ul {
      margin: 0;
      padding: 8px 10px 10px 28px;
      list-style-position: outside;
      overflow: auto;
      font-size: 12px;
      line-height: 1.4;
      flex: 1;
    }
    #pendingList li.active {
      color: var(--accent);
      font-weight: 700;
    }
    #logList li.ok { color: var(--ok); }
    #logList li.warn { color: var(--warn); }
    #logList li.err { color: var(--danger); }
    #logList li.note { color: #29415f; }
    @media (max-width: 1100px) {
      .layout { grid-template-columns: 1fr; }
      .content-grid { min-height: 620px; height: auto; }
      .list-wrap { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>
  <main class="layout">
    <section class="card">
      <div class="card-head">
        <h1>Escaner de URLs</h1>
        <span id="statusText" class="status-idle">Detenido</span>
      </div>
      <div class="controls">
        <div>
          <label for="delayInput">Espera entre URLs (ms)</label>
          <input id="delayInput" type="number" min="1000" step="500" value="3500">
        </div>
        <div>
          <button id="startBtn" type="button">Iniciar escaneo</button>
          <button id="stopBtn" type="button" disabled>Finalizar escaneo</button>
        </div>
      </div>
      <div class="meta">
        <div class="pill">Pendientes: <strong id="pendingCount">0</strong></div>
        <div class="pill">Eliminadas: <strong id="removedCount">0</strong></div>
        <div class="pill">Actual: <strong id="currentUrlText">-</strong></div>
      </div>
      <div class="urls-wrap">
        <label for="urlsInput">URLs (una por linea). Puedes agregar o editar en cualquier momento.</label>
        <textarea id="urlsInput" spellcheck="false"><?= htmlspecialchars($defaultUrls, ENT_QUOTES, 'UTF-8') ?></textarea>
      </div>
      <div class="keywords-wrap">
        <label for="keywordsInput">Palabras a buscar (una por linea o separadas por coma). Puedes agregar palabras adicionales.</label>
        <textarea id="keywordsInput" class="keyword-field" spellcheck="false"><?= htmlspecialchars($defaultKeywords, ENT_QUOTES, 'UTF-8') ?></textarea>
      </div>
    </section>
    <section class="content-grid">
      <section class="card">
        <div class="card-head">
          <h2>Vista de URL actual (frame)</h2>
        </div>
        <iframe id="previewFrame" title="Vista previa"></iframe>
      </section>
      <section class="card list-wrap">
        <div class="list-box">
          <h3 class="list-title">Cola pendiente</h3>
          <ol id="pendingList"></ol>
        </div>
        <div class="list-box">
          <h3 class="list-title">Bitacora</h3>
          <ul id="logList"></ul>
        </div>
      </section>
    </section>
  </main>

  <script>
    const urlsInput = document.getElementById('urlsInput');
    const keywordsInput = document.getElementById('keywordsInput');
    const delayInput = document.getElementById('delayInput');
    const startBtn = document.getElementById('startBtn');
    const stopBtn = document.getElementById('stopBtn');
    const statusText = document.getElementById('statusText');
    const pendingCount = document.getElementById('pendingCount');
    const removedCount = document.getElementById('removedCount');
    const currentUrlText = document.getElementById('currentUrlText');
    const pendingList = document.getElementById('pendingList');
    const logList = document.getElementById('logList');
    const previewFrame = document.getElementById('previewFrame');

    const state = {
      running: false,
      pending: [],
      keywords: [],
      currentIndex: 0,
      removed: 0,
      timer: null,
      urlsEdited: false,
      keywordsEdited: false,
      controller: null
    };

    urlsInput.addEventListener('input', () => {
      state.urlsEdited = true;
    });

    keywordsInput.addEventListener('input', () => {
      state.keywordsEdited = true;
    });

    startBtn.addEventListener('click', () => {
      startScan();
    });

    stopBtn.addEventListener('click', () => {
      stopScan(true);
    });

    function normalizeUrls(rawText) {
      const lines = rawText.split(/\r?\n/);
      const out = [];
      const seen = new Set();

      for (const line of lines) {
        const candidate = line.trim();
        if (!candidate) {
          continue;
        }
        try {
          const parsed = new URL(candidate);
          if (!/^https?:$/.test(parsed.protocol)) {
            continue;
          }
          const url = parsed.toString();
          if (!seen.has(url)) {
            seen.add(url);
            out.push(url);
          }
        } catch (error) {
          // Ignore invalid line.
        }
      }
      return out;
    }

    function normalizeKeywords(rawText) {
      const parts = rawText.split(/[\r\n,;]+/);
      const out = [];
      const seen = new Set();

      for (const part of parts) {
        const keyword = part.trim();
        if (!keyword) {
          continue;
        }
        const key = keyword.toLowerCase();
        if (!seen.has(key)) {
          seen.add(key);
          out.push(keyword);
        }
      }
      return out;
    }

    function applyManualChanges() {
      let changed = false;

      if (state.urlsEdited) {
        state.pending = normalizeUrls(urlsInput.value);
        if (state.currentIndex >= state.pending.length) {
          state.currentIndex = 0;
        }
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
      log('Cola o palabras actualizadas manualmente.', 'note');
    }

    function updateCounters() {
      pendingCount.textContent = String(state.pending.length);
      removedCount.textContent = String(state.removed);
      const current = state.pending[state.currentIndex] || '-';
      currentUrlText.textContent = current === '-' ? '-' : shortUrl(current);
    }

    function shortUrl(url) {
      return url.length > 45 ? url.slice(0, 42) + '...' : url;
    }

    function renderPending() {
      pendingList.innerHTML = '';
      for (let i = 0; i < state.pending.length; i += 1) {
        const li = document.createElement('li');
        li.textContent = state.pending[i];
        if (state.running && i === state.currentIndex) {
          li.classList.add('active');
        }
        pendingList.appendChild(li);
      }
    }

    function log(message, level = 'note') {
      const li = document.createElement('li');
      li.className = level;
      const stamp = new Date().toLocaleTimeString();
      li.textContent = '[' + stamp + '] ' + message;
      logList.prepend(li);
    }

    function setStatus(text, mode) {
      statusText.textContent = text;
      statusText.className = mode;
    }

    function setControls(running) {
      startBtn.disabled = running;
      stopBtn.disabled = !running;
    }

    function getDelay() {
      const value = Number.parseInt(delayInput.value, 10);
      if (!Number.isFinite(value) || value < 1000) {
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
      const frameUrl = 'scan.php?action=frame&url=' + encodeURIComponent(url);
      previewFrame.src = frameUrl;
    }

    async function scanCycle() {
      if (!state.running) {
        return;
      }

      applyManualChanges();

      if (!state.pending.length) {
        log('No quedan URLs pendientes.', 'note');
        stopScan(false);
        return;
      }

      if (state.currentIndex >= state.pending.length) {
        state.currentIndex = 0;
      }

      const url = state.pending[state.currentIndex];
      loadFrame(url);
      updateCounters();
      renderPending();

      let advance = true;

      try {
        state.controller = new AbortController();
        const response = await fetch('scan.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json'
          },
          body: JSON.stringify({
            url,
            keywords: state.keywords
          }),
          signal: state.controller.signal
        });

        const data = await response.json();

        if (!response.ok || !data.ok) {
          const detail = data && data.error ? data.error : 'Error al escanear';
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
        if (state.currentIndex >= state.pending.length) {
          state.currentIndex = 0;
        }
        updateCounters();
        renderPending();
      }

      if (!state.running) {
        return;
      }

      if (!state.pending.length) {
        log('Escaneo completo. Todas las URLs se eliminaron.', 'ok');
        stopScan(false);
        return;
      }

      if (advance) {
        state.currentIndex = (state.currentIndex + 1) % state.pending.length;
      }

      state.timer = window.setTimeout(scanCycle, getDelay());
    }

    function startScan() {
      state.pending = normalizeUrls(urlsInput.value);
      state.keywords = normalizeKeywords(keywordsInput.value);
      state.urlsEdited = false;
      state.keywordsEdited = false;
      state.removed = 0;
      state.currentIndex = 0;

      if (!state.pending.length) {
        alert('Agrega al menos una URL valida.');
        return;
      }

      if (!state.keywords.length) {
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
      scanCycle();
    }

    function stopScan(manualStop) {
      const wasRunning = state.running;
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
      updateCounters();
      renderPending();

      if (manualStop && wasRunning) {
        log('Escaneo finalizado por usuario.', 'note');
      }
    }

    (function init() {
      const clean = normalizeUrls(urlsInput.value);
      const cleanKeywords = normalizeKeywords(keywordsInput.value);
      urlsInput.value = clean.join('\n');
      keywordsInput.value = cleanKeywords.join('\n');
      state.pending = clean;
      state.keywords = cleanKeywords;
      renderPending();
      updateCounters();
      log('Listo para iniciar.', 'note');
    })();
  </script>
</body>
</html>
