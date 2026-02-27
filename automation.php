<?php

$ROOT_DIR = __DIR__;
$RUNTIME_DIR = $ROOT_DIR . DIRECTORY_SEPARATOR . 'runtime';
$AUTOMATION_DIR = $ROOT_DIR . DIRECTORY_SEPARATOR . 'automation';
$STATE_FILE = $RUNTIME_DIR . DIRECTORY_SEPARATOR . 'automation_state.json';
$JOB_FILE = $RUNTIME_DIR . DIRECTORY_SEPARATOR . 'automation_job.json';
$QUEUE_FILE = $RUNTIME_DIR . DIRECTORY_SEPARATOR . 'automation_queue.json';
$STOP_FILE = $RUNTIME_DIR . DIRECTORY_SEPARATOR . 'automation_stop.flag';
$LOG_FILE = $RUNTIME_DIR . DIRECTORY_SEPARATOR . 'automation_worker.log';
$PROFILE_DIR = $RUNTIME_DIR . DIRECTORY_SEPARATOR . 'browser_profile';
$PLAYWRIGHT_BROWSERS_DIR = $RUNTIME_DIR . DIRECTORY_SEPARATOR . 'ms-playwright';
$WORKER_FILE = $AUTOMATION_DIR . DIRECTORY_SEPARATOR . 'earnapp_worker.js';

ensureDir($RUNTIME_DIR);
ensureDir($AUTOMATION_DIR);
ensureDir($PROFILE_DIR);
ensureDir($PLAYWRIGHT_BROWSERS_DIR);

$method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';
if ($method === 'GET') {
    $action = isset($_GET['action']) ? trim((string) $_GET['action']) : 'status';
    if ($action !== 'status') {
        sendJson(array('ok' => false, 'error' => 'Accion GET no permitida'), 400);
    }
    $state = readState($STATE_FILE);
    $state = refreshRunningStatus($state, $STATE_FILE);
    sendJson(array('ok' => true, 'state' => $state), 200);
}

if ($method !== 'POST') {
    sendJson(array('ok' => false, 'error' => 'Metodo no permitido'), 405);
}

$rawInput = file_get_contents('php://input');
$payload = json_decode($rawInput, true);
if (!is_array($payload)) {
    $payload = array();
}

$action = getInput($payload, 'action', 'status');
$action = strtolower(trim((string) $action));

if ($action === 'status') {
    $state = readState($STATE_FILE);
    $state = refreshRunningStatus($state, $STATE_FILE);
    sendJson(array('ok' => true, 'state' => $state), 200);
}

if ($action === 'stop') {
    @file_put_contents($STOP_FILE, (string) time());
    $state = readState($STATE_FILE);
    if (isset($state['pid']) && (int) $state['pid'] > 0) {
        $state['stop_requested'] = true;
        $state['updated_at'] = date(DATE_ATOM);
        $state['message'] = 'Detencion solicitada';
        writeJsonFile($STATE_FILE, $state);
    }
    sendJson(array('ok' => true, 'state' => $state), 200);
}

if ($action !== 'start') {
    sendJson(array('ok' => false, 'error' => 'Accion no soportada'), 400);
}

if (!is_file($WORKER_FILE)) {
    sendJson(array('ok' => false, 'error' => 'Worker no encontrado: ' . $WORKER_FILE), 500);
}

$current = refreshRunningStatus(readState($STATE_FILE), $STATE_FILE);
if (!empty($current['running'])) {
    sendJson(array('ok' => false, 'error' => 'Ya hay una automatizacion en ejecucion', 'state' => $current), 409);
}

$urls = normalizeUrls(getInput($payload, 'urls', array()));
$keywords = normalizeKeywords(getInput($payload, 'keywords', array()));
$delayMs = sanitizeInt(getInput($payload, 'delay_ms', 12000), 12000, 1000, 300000);
$pageWaitMs = sanitizeInt(getInput($payload, 'page_wait_ms', 8000), 8000, 1000, 120000);
$headless = toBoolean(getInput($payload, 'headless', false));

if (count($urls) === 0) {
    sendJson(array('ok' => false, 'error' => 'No hay URLs validas para procesar'), 400);
}
if (count($keywords) === 0) {
    sendJson(array('ok' => false, 'error' => 'No hay palabras clave validas'), 400);
}

if (is_file($STOP_FILE)) {
    @unlink($STOP_FILE);
}

$job = array(
    'job_id' => uniqid('job_', true),
    'created_at' => date(DATE_ATOM),
    'urls' => $urls,
    'keywords' => $keywords,
    'delay_ms' => $delayMs,
    'page_wait_ms' => $pageWaitMs,
    'headless' => $headless,
    'profile_dir' => $PROFILE_DIR
);

if (!writeJsonFile($JOB_FILE, $job)) {
    sendJson(array('ok' => false, 'error' => 'No se pudo guardar el job de automatizacion'), 500);
}

$bootstrapState = defaultState();
$bootstrapState['running'] = true;
$bootstrapState['job_id'] = $job['job_id'];
$bootstrapState['started_at'] = date(DATE_ATOM);
$bootstrapState['updated_at'] = date(DATE_ATOM);
$bootstrapState['keywords'] = $keywords;
$bootstrapState['pending_urls'] = $urls;
$bootstrapState['pending_count'] = count($urls);
$bootstrapState['total_count'] = count($urls);
$bootstrapState['message'] = 'Iniciando worker...';
$bootstrapState['scan_count'] = 0;
$bootstrapState['lap_count'] = 1;
$bootstrapState['logs'] = array(
    array(
        'time' => date(DATE_ATOM),
        'level' => 'note',
        'message' => 'Iniciando worker de automatizacion'
    )
);
writeJsonFile($STATE_FILE, $bootstrapState);
writeJsonFile($QUEUE_FILE, array('pending_urls' => $urls, 'updated_at' => date(DATE_ATOM)));

$nodeBin = detectNodeBinary();
$nodeOk = verifyNodeBinary($nodeBin);
if (!$nodeOk) {
    $failedState = readState($STATE_FILE);
    $failedState['running'] = false;
    $failedState['message'] = 'Node.js no disponible';
    $failedState['updated_at'] = date(DATE_ATOM);
    $failedState['logs'] = array(
        array(
            'time' => date(DATE_ATOM),
            'level' => 'err',
            'message' => 'No se encontro Node.js. Define la ruta o instala Node.'
        )
    );
    writeJsonFile($STATE_FILE, $failedState);
    sendJson(array('ok' => false, 'error' => 'Node.js no encontrado para ejecutar el worker'), 500);
}
$launch = launchWorker(
    $nodeBin,
    $WORKER_FILE,
    $JOB_FILE,
    $STATE_FILE,
    $QUEUE_FILE,
    $STOP_FILE,
    $LOG_FILE,
    $PLAYWRIGHT_BROWSERS_DIR
);

if (empty($launch['ok'])) {
    $failedState = readState($STATE_FILE);
    $failedState['running'] = false;
    $failedState['message'] = 'No se pudo iniciar el worker';
    $failedState['updated_at'] = date(DATE_ATOM);
    writeJsonFile($STATE_FILE, $failedState);
    sendJson(array('ok' => false, 'error' => 'No se pudo iniciar el worker'), 500);
}

if (!empty($launch['pid'])) {
    $runningState = readState($STATE_FILE);
    $runningState['pid'] = (int) $launch['pid'];
    $runningState['updated_at'] = date(DATE_ATOM);
    writeJsonFile($STATE_FILE, $runningState);
}

sleep(1);
$state = refreshRunningStatus(readState($STATE_FILE), $STATE_FILE);
sendJson(array('ok' => true, 'state' => $state), 200);

function launchWorker($nodeBin, $workerFile, $jobFile, $stateFile, $queueFile, $stopFile, $logFile, $playwrightBrowsersDir)
{
    $launcherLog = dirname($logFile) . DIRECTORY_SEPARATOR . 'automation_launcher.log';
    @putenv('PLAYWRIGHT_BROWSERS_PATH=' . $playwrightBrowsersDir);
    $command = toCmdQuoted($nodeBin)
        . ' ' . toCmdQuoted($workerFile)
        . ' --job ' . toCmdQuoted($jobFile)
        . ' --state ' . toCmdQuoted($stateFile)
        . ' --queue ' . toCmdQuoted($queueFile)
        . ' --stop ' . toCmdQuoted($stopFile);
    writeLauncherLog($launcherLog, 'proc_open command: ' . $command);

    $descriptors = array(
        0 => array('file', 'NUL', 'r'),
        1 => array('file', 'NUL', 'a'),
        2 => array('file', 'NUL', 'a')
    );
    $pipes = array();
    $process = @proc_open(
        $command,
        $descriptors,
        $pipes,
        dirname($workerFile),
        null,
        array('bypass_shell' => true)
    );

    if (is_resource($process)) {
        $status = @proc_get_status($process);
        $pid = 0;
        if (is_array($status) && isset($status['pid'])) {
            $pid = (int) $status['pid'];
        }
        writeLauncherLog($launcherLog, 'proc_open ok, pid=' . $pid);
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                @fclose($pipe);
            }
        }
        return array('ok' => true, 'pid' => $pid);
    }

    $error = error_get_last();
    if (is_array($error) && isset($error['message'])) {
        writeLauncherLog($launcherLog, 'proc_open failed: ' . (string) $error['message']);
    } else {
        writeLauncherLog($launcherLog, 'proc_open failed without PHP error');
    }

    $cmd = 'cmd /c start "" /B '
        . toCmdQuoted($nodeBin)
        . ' ' . toCmdQuoted($workerFile)
        . ' --job ' . toCmdQuoted($jobFile)
        . ' --state ' . toCmdQuoted($stateFile)
        . ' --queue ' . toCmdQuoted($queueFile)
        . ' --stop ' . toCmdQuoted($stopFile)
        . ' > ' . toCmdQuoted($logFile) . ' 2>&1';
    writeLauncherLog($launcherLog, 'fallback command: ' . $cmd);
    $handle = @popen($cmd, 'r');
    if (is_resource($handle)) {
        @pclose($handle);
        writeLauncherLog($launcherLog, 'fallback popen accepted');
        return array('ok' => true, 'pid' => 0);
    }
    writeLauncherLog($launcherLog, 'fallback popen failed');

    return array('ok' => false, 'pid' => 0);
}

function writeLauncherLog($logFile, $message)
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . (string) $message . "\n";
    @file_put_contents($logFile, $line, FILE_APPEND);
}

function toCmdQuoted($value)
{
    $text = (string) $value;
    $text = str_replace('"', '""', $text);
    return '"' . $text . '"';
}

function detectNodeBinary()
{
    $node = '';
    $where = @shell_exec('where node');
    if (is_string($where) && trim($where) !== '') {
        $lines = preg_split('/\r\n|\r|\n/', trim($where));
        if (is_array($lines)) {
            foreach ($lines as $line) {
                $candidate = trim((string) $line);
                if ($candidate !== '' && @is_file($candidate)) {
                    $node = $candidate;
                    break;
                }
            }
        }
    }

    if ($node !== '') {
        return $node;
    }

    $candidates = array(
        'C:\\Program Files\\nodejs\\node.exe',
        'C:\\Program Files (x86)\\nodejs\\node.exe'
    );

    $programFiles = getenv('ProgramFiles');
    if (is_string($programFiles) && trim($programFiles) !== '') {
        $candidates[] = rtrim($programFiles, '\\/') . '\\nodejs\\node.exe';
    }
    $programFilesX86 = getenv('ProgramFiles(x86)');
    if (is_string($programFilesX86) && trim($programFilesX86) !== '') {
        $candidates[] = rtrim($programFilesX86, '\\/') . '\\nodejs\\node.exe';
    }

    foreach ($candidates as $candidate) {
        if (is_string($candidate) && $candidate !== '' && @is_file($candidate)) {
            return $candidate;
        }
    }

    return 'node';
}

function verifyNodeBinary($nodeBin)
{
    $bin = trim((string) $nodeBin);
    if ($bin === '') {
        return false;
    }

    if ((strpos($bin, '\\') !== false || strpos($bin, '/') !== false) && !@is_file($bin)) {
        return false;
    }

    $cmd = toCmdQuoted($bin) . ' -v 2>NUL';
    $output = @shell_exec($cmd);
    if (!is_string($output) || trim($output) === '') {
        return false;
    }

    return (strpos(trim($output), 'v') === 0);
}

function refreshRunningStatus($state, $stateFile)
{
    $running = !empty($state['running']);
    $pid = isset($state['pid']) ? (int) $state['pid'] : 0;
    if ($running && $pid > 0 && !isProcessRunning($pid)) {
        $state['running'] = false;
        $state['message'] = 'Worker finalizado';
        $state['updated_at'] = date(DATE_ATOM);
        writeJsonFile($stateFile, $state);
    } elseif ($running && $pid <= 0) {
        $startedAt = isset($state['started_at']) ? strtotime((string) $state['started_at']) : 0;
        if ($startedAt > 0 && (time() - $startedAt) > 20) {
            $state['running'] = false;
            $state['message'] = 'Worker no reporto PID';
            $state['updated_at'] = date(DATE_ATOM);
            writeJsonFile($stateFile, $state);
        }
    }
    return $state;
}

function isProcessRunning($pid)
{
    if ($pid <= 0) {
        return false;
    }
    $cmd = 'tasklist /FI "PID eq ' . (int) $pid . '" /NH';
    $output = @shell_exec($cmd);
    if (!is_string($output) || trim($output) === '') {
        return false;
    }
    if (stripos($output, 'No tasks are running') !== false) {
        return false;
    }
    return (stripos($output, (string) $pid) !== false);
}

function normalizeUrls($raw)
{
    $items = array();
    if (is_array($raw)) {
        $items = $raw;
    } elseif (is_string($raw)) {
        $split = preg_split('/[\r\n]+/', $raw);
        if (is_array($split)) {
            $items = $split;
        }
    }

    $out = array();
    $seen = array();
    foreach ($items as $item) {
        $url = trim((string) $item);
        if ($url === '') {
            continue;
        }
        $valid = filter_var($url, FILTER_VALIDATE_URL);
        if (!is_string($valid)) {
            continue;
        }
        $parts = parse_url($valid);
        if (!is_array($parts)) {
            continue;
        }
        $scheme = isset($parts['scheme']) ? strtolower((string) $parts['scheme']) : '';
        if ($scheme !== 'http' && $scheme !== 'https') {
            continue;
        }
        if (!isset($seen[$valid])) {
            $seen[$valid] = true;
            $out[] = $valid;
        }
        if (count($out) >= 5000) {
            break;
        }
    }

    return $out;
}

function normalizeKeywords($raw)
{
    $items = array();
    if (is_array($raw)) {
        $items = $raw;
    } elseif (is_string($raw)) {
        $split = preg_split('/[\r\n,;]+/', $raw);
        if (is_array($split)) {
            $items = $split;
        }
    }

    $out = array();
    $seen = array();
    foreach ($items as $item) {
        $keyword = trim((string) $item);
        if ($keyword === '') {
            continue;
        }
        $keyword = substr($keyword, 0, 120);
        $key = strtolower($keyword);
        if (!isset($seen[$key])) {
            $seen[$key] = true;
            $out[] = $keyword;
        }
        if (count($out) >= 100) {
            break;
        }
    }

    return $out;
}

function sanitizeInt($value, $default, $min, $max)
{
    if (is_array($value) || is_object($value)) {
        return $default;
    }
    if ($value === null || $value === '') {
        return $default;
    }
    $number = (int) $value;
    if ($number < $min) {
        return $min;
    }
    if ($number > $max) {
        return $max;
    }
    return $number;
}

function toBoolean($value)
{
    if (is_bool($value)) {
        return $value;
    }
    $str = strtolower(trim((string) $value));
    return ($str === '1' || $str === 'true' || $str === 'yes' || $str === 'on');
}

function defaultState()
{
    return array(
        'running' => false,
        'pid' => 0,
        'job_id' => '',
        'message' => 'idle',
        'started_at' => '',
        'finished_at' => '',
        'updated_at' => date(DATE_ATOM),
        'current_url' => '',
        'current_index' => 0,
        'pending_urls' => array(),
        'pending_count' => 0,
        'total_count' => 0,
        'removed_count' => 0,
        'scan_count' => 0,
        'lap_count' => 0,
        'keywords' => array(),
        'logs' => array(),
        'stop_requested' => false
    );
}

function readState($stateFile)
{
    if (!is_file($stateFile) || !is_readable($stateFile)) {
        return defaultState();
    }

    $raw = @file_get_contents($stateFile);
    if (!is_string($raw) || trim($raw) === '') {
        return defaultState();
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return defaultState();
    }

    $state = defaultState();
    foreach ($decoded as $key => $value) {
        $state[$key] = $value;
    }
    return $state;
}

function writeJsonFile($file, $data)
{
    $options = 0;
    if (defined('JSON_UNESCAPED_SLASHES')) {
        $options |= JSON_UNESCAPED_SLASHES;
    }
    if (defined('JSON_UNESCAPED_UNICODE')) {
        $options |= JSON_UNESCAPED_UNICODE;
    }
    if (defined('JSON_PRETTY_PRINT')) {
        $options |= JSON_PRETTY_PRINT;
    }
    $json = json_encode($data, $options);
    if (!is_string($json)) {
        return false;
    }
    $bytes = @file_put_contents($file, $json, LOCK_EX);
    return ($bytes !== false);
}

function ensureDir($dir)
{
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
}

function getInput($payload, $key, $default)
{
    if (is_array($payload) && array_key_exists($key, $payload)) {
        return $payload[$key];
    }
    if (isset($_POST[$key])) {
        return $_POST[$key];
    }
    return $default;
}

function sendJson($payload, $statusCode)
{
    http_response_code((int) $statusCode);
    header('Content-Type: application/json; charset=utf-8');
    $options = 0;
    if (defined('JSON_UNESCAPED_SLASHES')) {
        $options |= JSON_UNESCAPED_SLASHES;
    }
    if (defined('JSON_UNESCAPED_UNICODE')) {
        $options |= JSON_UNESCAPED_UNICODE;
    }
    echo json_encode($payload, $options);
    exit;
}
