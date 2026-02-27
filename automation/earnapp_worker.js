#!/usr/bin/env node
'use strict';

const fs = require('fs');
const path = require('path');

const MAX_LOGS = 300;

function nowIso() {
  return new Date().toISOString();
}

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

function ensureDir(dirPath) {
  if (!dirPath) {
    return;
  }
  try {
    fs.mkdirSync(dirPath, { recursive: true });
  } catch (error) {
    // noop
  }
}

function fileExists(filePath) {
  try {
    fs.accessSync(filePath, fs.constants.F_OK);
    return true;
  } catch (error) {
    return false;
  }
}

function readJson(filePath, fallbackValue) {
  try {
    if (!fileExists(filePath)) {
      return fallbackValue;
    }
    const raw = fs.readFileSync(filePath, 'utf8');
    if (!raw || !raw.trim()) {
      return fallbackValue;
    }
    const parsed = JSON.parse(raw);
    if (parsed && typeof parsed === 'object') {
      return parsed;
    }
  } catch (error) {
    // noop
  }
  return fallbackValue;
}

function writeJson(filePath, data) {
  try {
    fs.writeFileSync(filePath, JSON.stringify(data, null, 2), 'utf8');
    return true;
  } catch (error) {
    return false;
  }
}

function normalizeUrls(rawUrls) {
  const items = Array.isArray(rawUrls) ? rawUrls : [];
  const out = [];
  const seen = Object.create(null);

  for (let i = 0; i < items.length; i += 1) {
    const candidate = String(items[i] || '').trim();
    if (!candidate) {
      continue;
    }
    let parsed;
    try {
      parsed = new URL(candidate);
    } catch (error) {
      continue;
    }
    const protocol = String(parsed.protocol || '').toLowerCase();
    if (protocol !== 'http:' && protocol !== 'https:') {
      continue;
    }
    const url = parsed.toString();
    if (!seen[url]) {
      seen[url] = true;
      out.push(url);
    }
    if (out.length >= 5000) {
      break;
    }
  }
  return out;
}

function normalizeKeywords(rawKeywords) {
  const items = Array.isArray(rawKeywords) ? rawKeywords : [];
  const out = [];
  const seen = Object.create(null);

  for (let i = 0; i < items.length; i += 1) {
    const keyword = String(items[i] || '').trim().slice(0, 120);
    if (!keyword) {
      continue;
    }
    const key = keyword.toLowerCase();
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

function parseIntSafe(value, defaultValue, minValue, maxValue) {
  const number = Number.parseInt(String(value), 10);
  if (!Number.isFinite(number)) {
    return defaultValue;
  }
  if (number < minValue) {
    return minValue;
  }
  if (number > maxValue) {
    return maxValue;
  }
  return number;
}

function parseArgs(argv) {
  const args = Object.create(null);
  for (let i = 2; i < argv.length; i += 1) {
    const token = String(argv[i] || '');
    if (token.indexOf('--') !== 0) {
      continue;
    }
    const key = token.slice(2);
    const next = i + 1 < argv.length ? String(argv[i + 1] || '') : '';
    if (next && next.indexOf('--') !== 0) {
      args[key] = next;
      i += 1;
      continue;
    }
    args[key] = '1';
  }
  return args;
}

function buildDefaultState() {
  return {
    running: false,
    pid: 0,
    job_id: '',
    message: 'idle',
    started_at: '',
    finished_at: '',
    updated_at: nowIso(),
    current_url: '',
    current_index: 0,
    pending_urls: [],
    pending_count: 0,
    total_count: 0,
    removed_count: 0,
    keywords: [],
    logs: [],
    stop_requested: false
  };
}

function withDefaultState(input) {
  const state = buildDefaultState();
  if (input && typeof input === 'object') {
    Object.keys(input).forEach((key) => {
      state[key] = input[key];
    });
  }
  if (!Array.isArray(state.logs)) {
    state.logs = [];
  }
  return state;
}

function createStateStore(stateFile, queueFile) {
  let state = withDefaultState(readJson(stateFile, buildDefaultState()));

  function appendLog(message, level) {
    const item = {
      time: nowIso(),
      level: level || 'note',
      message: String(message || '')
    };
    state.logs.unshift(item);
    if (state.logs.length > MAX_LOGS) {
      state.logs = state.logs.slice(0, MAX_LOGS);
    }
  }

  function writePendingQueue(pendingUrls) {
    const payload = {
      pending_urls: pendingUrls.slice(),
      updated_at: nowIso()
    };
    writeJson(queueFile, payload);
  }

  function save() {
    state.updated_at = nowIso();
    writeJson(stateFile, state);
  }

  function setPending(pendingUrls) {
    state.pending_urls = pendingUrls.slice();
    state.pending_count = pendingUrls.length;
    writePendingQueue(state.pending_urls);
  }

  function setMessage(message) {
    state.message = String(message || '');
  }

  function getState() {
    return state;
  }

  return {
    appendLog,
    save,
    setPending,
    setMessage,
    getState
  };
}

function shortUrl(url) {
  const value = String(url || '');
  return value.length > 90 ? value.slice(0, 87) + '...' : value;
}

function stopRequested(stopFile) {
  return fileExists(stopFile);
}

function loadPlaywright() {
  const candidates = ['playwright', 'playwright-core'];
  for (let i = 0; i < candidates.length; i += 1) {
    const name = candidates[i];
    try {
      return require(name);
    } catch (error) {
      // try next module
    }
  }
  return null;
}

function detectChromiumExecutable() {
  const candidates = [
    'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
    'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
    'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
    'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
    'C:\\Program Files\\BraveSoftware\\Brave-Browser\\Application\\brave.exe',
    'C:\\Program Files (x86)\\BraveSoftware\\Brave-Browser\\Application\\brave.exe'
  ];

  for (let i = 0; i < candidates.length; i += 1) {
    if (fileExists(candidates[i])) {
      return candidates[i];
    }
  }
  return '';
}

async function extractPageText(page) {
  return page.evaluate(() => {
    const title = document && typeof document.title === 'string' ? document.title : '';
    const bodyText = document && document.body && typeof document.body.innerText === 'string'
      ? document.body.innerText
      : '';
    const html = document && document.documentElement && typeof document.documentElement.outerHTML === 'string'
      ? document.documentElement.outerHTML
      : '';
    return (title + '\n' + bodyText + '\n' + html).slice(0, 2500000);
  });
}

function findKeyword(haystack, keywords) {
  if (!haystack) {
    return null;
  }
  const lowered = String(haystack).toLowerCase();
  for (let i = 0; i < keywords.length; i += 1) {
    const keyword = keywords[i];
    if (lowered.indexOf(String(keyword).toLowerCase()) !== -1) {
      return keyword;
    }
  }
  return null;
}

async function run() {
  const args = parseArgs(process.argv);
  const jobFile = String(args.job || '');
  const stateFile = String(args.state || '');
  const queueFile = String(args.queue || '');
  const stopFile = String(args.stop || '');

  if (!jobFile || !stateFile || !queueFile || !stopFile) {
    process.exitCode = 2;
    return;
  }

  ensureDir(path.dirname(stateFile));
  ensureDir(path.dirname(queueFile));

  const rawJob = readJson(jobFile, null);
  if (!rawJob || typeof rawJob !== 'object') {
    const stateStore = createStateStore(stateFile, queueFile);
    const state = stateStore.getState();
    state.running = false;
    state.pid = process.pid;
    state.finished_at = nowIso();
    stateStore.setMessage('Job invalido');
    stateStore.appendLog('No se pudo cargar automation_job.json', 'err');
    stateStore.save();
    return;
  }

  const urls = normalizeUrls(rawJob.urls);
  const keywords = normalizeKeywords(rawJob.keywords);
  const delayMs = parseIntSafe(rawJob.delay_ms, 12000, 1000, 300000);
  const pageWaitMs = parseIntSafe(rawJob.page_wait_ms, 8000, 500, 180000);
  const headless = !!rawJob.headless;
  const profileDir = String(rawJob.profile_dir || '').trim();
  const storageStateFile = profileDir ? path.join(profileDir, 'storage_state.json') : '';
  const jobId = String(rawJob.job_id || '');

  const stateStore = createStateStore(stateFile, queueFile);
  const state = stateStore.getState();

  state.running = true;
  state.pid = process.pid;
  state.job_id = jobId;
  state.started_at = state.started_at || nowIso();
  state.finished_at = '';
  state.stop_requested = false;
  state.removed_count = Number.isFinite(Number(state.removed_count)) ? Number(state.removed_count) : 0;
  state.total_count = urls.length;
  state.keywords = keywords.slice();
  state.current_index = 0;
  state.current_url = urls.length > 0 ? urls[0] : '';
  stateStore.setPending(urls);
  stateStore.setMessage('Worker iniciado');
  stateStore.appendLog('Worker PID ' + process.pid + ' iniciado', 'note');
  stateStore.save();

  if (urls.length === 0) {
    state.running = false;
    state.finished_at = nowIso();
    stateStore.setMessage('No hay URLs para procesar');
    stateStore.appendLog('No hay URLs validas en el job', 'warn');
    stateStore.save();
    return;
  }

  if (keywords.length === 0) {
    state.running = false;
    state.finished_at = nowIso();
    stateStore.setMessage('No hay palabras clave');
    stateStore.appendLog('No hay palabras clave validas', 'err');
    stateStore.save();
    return;
  }

  const playwright = loadPlaywright();
  if (!playwright || !playwright.chromium) {
    state.running = false;
    state.finished_at = nowIso();
    stateStore.setMessage('Playwright no esta instalado');
    stateStore.appendLog('Instala Playwright con: npm install playwright', 'err');
    stateStore.save();
    return;
  }

  let browser = null;
  let context = null;
  let page = null;
  let pending = urls.slice();
  let index = 0;

  try {
    const launchOptions = {
      headless: headless,
      ignoreHTTPSErrors: true,
      viewport: { width: 1366, height: 768 },
      args: ['--disable-dev-shm-usage']
    };
    const chromiumExecutable = detectChromiumExecutable();
    if (profileDir) {
      ensureDir(profileDir);
    }
    const launchAttempts = [];
    launchAttempts.push(launchOptions);
    if (chromiumExecutable) {
      const systemLaunch = {
        headless: launchOptions.headless,
        ignoreHTTPSErrors: launchOptions.ignoreHTTPSErrors,
        viewport: launchOptions.viewport,
        args: launchOptions.args.slice(),
        executablePath: chromiumExecutable
      };
      launchAttempts.push(systemLaunch);
    }

    const launchErrors = [];
    for (let i = 0; i < launchAttempts.length; i += 1) {
      const attempt = launchAttempts[i];
      try {
        browser = await playwright.chromium.launch(attempt);
        if (attempt.executablePath) {
          stateStore.appendLog('Usando navegador del sistema: ' + attempt.executablePath, 'note');
        } else {
          stateStore.appendLog('Usando Chromium de Playwright', 'note');
        }
        break;
      } catch (error) {
        const detail = error && error.message ? String(error.message) : 'Error de lanzamiento';
        launchErrors.push(detail);
      }
    }

    if (!browser) {
      throw new Error(launchErrors.join('\n---\n').slice(0, 4000));
    }

    const contextOptions = {
      ignoreHTTPSErrors: true,
      viewport: { width: 1366, height: 768 }
    };
    if (storageStateFile && fileExists(storageStateFile)) {
      contextOptions.storageState = storageStateFile;
      stateStore.appendLog('Sesion restaurada desde storage_state.json', 'note');
    }
    context = await browser.newContext(contextOptions);

    const pages = context.pages();
    page = pages && pages.length ? pages[0] : await context.newPage();
    page.setDefaultNavigationTimeout(Math.max(pageWaitMs + 10000, 20000));
    page.setDefaultTimeout(Math.max(pageWaitMs + 10000, 20000));

    stateStore.appendLog(
      'Navegador iniciado en modo ' + (headless ? 'headless' : 'visible'),
      'note'
    );
    stateStore.setMessage('Escaneando URLs');
    stateStore.save();

    while (pending.length > 0) {
      if (stopRequested(stopFile)) {
        state.stop_requested = true;
        stateStore.setMessage('Detenido por usuario');
        stateStore.appendLog('Stop solicitado por usuario', 'warn');
        break;
      }

      if (index < 0 || index >= pending.length) {
        index = 0;
      }

      const targetUrl = pending[index];
      state.current_index = index;
      state.current_url = targetUrl;
      stateStore.setPending(pending);
      stateStore.save();

      let matchedKeyword = null;
      let statusCode = 0;
      let note = '';

      try {
        const response = await page.goto(targetUrl, {
          waitUntil: 'domcontentloaded'
        });
        statusCode = response ? Number(response.status()) || 0 : 0;
        await page.waitForTimeout(pageWaitMs);
        const pageText = await extractPageText(page);
        matchedKeyword = findKeyword(pageText, keywords);
      } catch (error) {
        const msg = error && error.message ? String(error.message) : 'Error desconocido';
        note = msg.replace(/\s+/g, ' ').slice(0, 220);
      }

      if (matchedKeyword) {
        pending.splice(index, 1);
        state.removed_count += 1;
        if (index >= pending.length) {
          index = 0;
        }
        stateStore.appendLog(
          'Coincidencia "' + matchedKeyword + '" en ' + shortUrl(targetUrl) + '. URL eliminada.',
          'ok'
        );
        stateStore.setMessage('Coincidencia detectada y URL eliminada');
      } else {
        const suffix = note ? ' Error: ' + note : '';
        stateStore.appendLog(
          'Sin coincidencia en ' + shortUrl(targetUrl) + ' (HTTP ' + statusCode + ').' + suffix,
          note ? 'err' : 'warn'
        );
        if (pending.length > 0) {
          index = (index + 1) % pending.length;
        }
        stateStore.setMessage('Escaneando sin coincidencia');
      }

      if (pending.length === 0) {
        state.current_index = 0;
        state.current_url = '';
        stateStore.setPending(pending);
        stateStore.appendLog('Cola completada. No quedan URLs pendientes.', 'ok');
        stateStore.setMessage('Completado');
        break;
      }

      stateStore.setPending(pending);
      state.current_index = index;
      state.current_url = pending[index] || '';
      stateStore.save();

      if (stopRequested(stopFile)) {
        state.stop_requested = true;
        stateStore.setMessage('Detenido por usuario');
        stateStore.appendLog('Stop solicitado por usuario', 'warn');
        break;
      }

      await sleep(delayMs);
    }
  } catch (error) {
    const detail = error && error.message ? String(error.message) : 'Error no controlado';
    stateStore.setMessage('Fallo del worker');
    stateStore.appendLog('Worker fallo: ' + detail.slice(0, 2500), 'err');
  } finally {
    try {
      if (context && storageStateFile) {
        await context.storageState({ path: storageStateFile });
      }
    } catch (error) {
      // noop
    }
    try {
      if (context) {
        await context.close();
      }
    } catch (error) {
      // noop
    }
    try {
      if (browser) {
        await browser.close();
      }
    } catch (error) {
      // noop
    }

    state.running = false;
    state.finished_at = nowIso();
    state.current_url = '';
    if (!state.message || state.message === 'Escaneando URLs') {
      stateStore.setMessage('Worker finalizado');
    }
    stateStore.save();
  }
}

run().catch((error) => {
  const detail = error && error.stack ? String(error.stack) : String(error || 'fatal');
  try {
    process.stderr.write(detail + '\n');
  } catch (e) {
    // noop
  }
  process.exitCode = 1;
});
