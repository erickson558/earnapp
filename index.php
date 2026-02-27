<?php

$urlsFile = __DIR__ . DIRECTORY_SEPARATOR . 'urls.txt';
$keywordsFile = __DIR__ . DIRECTORY_SEPARATOR . 'keywords.txt';

$defaultUrls = '';
$defaultKeywords = "Sucessfull\nSuccessful\nAlready";
$defaultDelay = 3500;

if (is_file($urlsFile) && is_readable($urlsFile)) {
    $defaultUrls = trim((string) file_get_contents($urlsFile));
}

if (is_file($keywordsFile) && is_readable($keywordsFile)) {
    $rawKeywords = trim((string) file_get_contents($keywordsFile));
    if ($rawKeywords !== '') {
        $defaultKeywords = $rawKeywords;
    }
}

$bootstrap = array(
    'urls' => $defaultUrls,
    'keywords' => $defaultKeywords,
    'delay_ms' => $defaultDelay
);

$jsonOptions = 0;
if (defined('JSON_UNESCAPED_SLASHES')) {
    $jsonOptions |= JSON_UNESCAPED_SLASHES;
}
if (defined('JSON_UNESCAPED_UNICODE')) {
    $jsonOptions |= JSON_UNESCAPED_UNICODE;
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>EarnApp Scanner XP Dark</title>
  <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
  <main class="xp-shell">
    <section class="xp-window">
      <div class="xp-titlebar">
        <span>EarnApp Scanner Control Center</span>
        <span id="statusText" class="status-idle">Detenido</span>
      </div>
      <div class="xp-body">
        <div class="xp-section xp-grid-2">
          <div>
            <label for="delayInput">Espera entre URLs (ms)</label>
            <input id="delayInput" type="number" min="1000" step="500" value="<?= (int) $defaultDelay ?>">
          </div>
          <div class="xp-buttons">
            <button id="startBtn" type="button" class="xp-button">Iniciar</button>
            <button id="stopBtn" type="button" class="xp-button stop" disabled>Finalizar</button>
          </div>
        </div>

        <div class="xp-section">
          <div class="meta-row">
            <div class="xp-pill">Pendientes: <strong id="pendingCount">0</strong></div>
            <div class="xp-pill">Eliminadas: <strong id="removedCount">0</strong></div>
            <div class="xp-pill">Actual: <strong id="currentUrlText">-</strong></div>
          </div>
          <div class="xp-submeta">
            <span id="persistText" class="persist-warn">Persistencia: cargando...</span>
            <span>Cierre seguro: guarda estado pendiente en servidor</span>
          </div>
          <div class="xp-toolbar">
            <button id="saveBtn" type="button" class="xp-button soft">Guardar estado</button>
            <button id="loadStateBtn" type="button" class="xp-button soft">Cargar estado</button>
            <button id="clearStateBtn" type="button" class="xp-button soft">Limpiar guardado</button>
          </div>
        </div>

        <div class="xp-section">
          <label for="urlsInput">URLs (una por línea). Se eliminan automáticamente cuando coinciden.</label>
          <textarea id="urlsInput" spellcheck="false"><?= htmlspecialchars($defaultUrls, ENT_QUOTES, 'UTF-8') ?></textarea>
        </div>

        <div class="xp-section">
          <label for="keywordsInput">Palabras buscadas (una por línea o separadas por coma).</label>
          <textarea id="keywordsInput" spellcheck="false"><?= htmlspecialchars($defaultKeywords, ENT_QUOTES, 'UTF-8') ?></textarea>
        </div>
      </div>
    </section>

    <section class="content-stack">
      <section class="xp-window">
        <div class="xp-titlebar">
          <span>Vista de URL actual</span>
          <span>Frame proxy local</span>
        </div>
        <div class="xp-body" style="height: 100%;">
          <iframe id="previewFrame" class="preview-frame" title="Vista previa"></iframe>
        </div>
      </section>

      <section class="xp-window">
        <div class="xp-titlebar">
          <span>Cola y bitácora</span>
          <span>Seguimiento en tiempo real</span>
        </div>
        <div class="xp-body lists-grid">
          <div class="list-box">
            <h3 class="list-title">Cola pendiente</h3>
            <ol id="pendingList" class="list-scroll"></ol>
          </div>
          <div class="list-box">
            <h3 class="list-title">Bitácora de escaneo</h3>
            <ul id="logList" class="list-scroll"></ul>
          </div>
        </div>
      </section>
    </section>
  </main>

  <script>
    window.APP_DEFAULTS = <?= json_encode($bootstrap, $jsonOptions) ?>;
  </script>
  <script src="assets/js/app.js"></script>
</body>
</html>
