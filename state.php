<?php

$STATE_FILE = __DIR__ . DIRECTORY_SEPARATOR . 'scanner_state.json';
$DEFAULT_KEYWORDS = array('Sucessfull', 'Successful', 'Already');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $state = loadState($STATE_FILE, $DEFAULT_KEYWORDS);
    sendJson(
        array(
            'ok' => true,
            'state' => $state
        ),
        200
    );
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJson(array('ok' => false, 'error' => 'Metodo no permitido'), 405);
}

$rawInput = file_get_contents('php://input');
$payload = json_decode($rawInput, true);
if (!is_array($payload)) {
    $payload = array();
}

$action = getInputValue('action', $payload);
if (!is_string($action)) {
    $action = '';
}
$action = strtolower(trim($action));

if ($action === 'clear') {
    $state = defaultState($DEFAULT_KEYWORDS);
    $state['updated_at'] = date(DATE_ATOM);
    $state['reason'] = 'clear';
    $saved = saveState($STATE_FILE, $state);
    if (!$saved) {
        sendJson(array('ok' => false, 'error' => 'No se pudo limpiar el estado'), 500);
    }

    sendJson(
        array(
            'ok' => true,
            'state' => $state
        ),
        200
    );
}

$pendingRaw = getInputValue('pending_urls', $payload);
$keywordsRaw = getInputValue('keywords', $payload);
$delayRaw = getInputValue('delay_ms', $payload);
$removedRaw = getInputValue('removed_count', $payload);
$currentIndexRaw = getInputValue('current_index', $payload);
$reasonRaw = getInputValue('reason', $payload);

$state = array(
    'pending_urls' => normalizeUrls($pendingRaw),
    'keywords' => normalizeKeywords($keywordsRaw, $DEFAULT_KEYWORDS),
    'delay_ms' => sanitizeInt($delayRaw, 3500, 1000, 120000),
    'removed_count' => sanitizeInt($removedRaw, 0, 0, 100000000),
    'current_index' => sanitizeInt($currentIndexRaw, 0, 0, 100000000),
    'updated_at' => date(DATE_ATOM),
    'reason' => substr(trim((string) $reasonRaw), 0, 60)
);

if (count($state['pending_urls']) === 0) {
    $state['current_index'] = 0;
} elseif ($state['current_index'] >= count($state['pending_urls'])) {
    $state['current_index'] = 0;
}

$saved = saveState($STATE_FILE, $state);
if (!$saved) {
    sendJson(array('ok' => false, 'error' => 'No se pudo guardar el estado'), 500);
}

sendJson(
    array(
        'ok' => true,
        'state' => $state
    ),
    200
);

function loadState($stateFile, $defaultKeywords)
{
    if (!is_file($stateFile) || !is_readable($stateFile)) {
        return defaultState($defaultKeywords);
    }

    $raw = @file_get_contents($stateFile);
    if (!is_string($raw) || trim($raw) === '') {
        return defaultState($defaultKeywords);
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return defaultState($defaultKeywords);
    }

    $state = array(
        'pending_urls' => normalizeUrls(isset($decoded['pending_urls']) ? $decoded['pending_urls'] : array()),
        'keywords' => normalizeKeywords(isset($decoded['keywords']) ? $decoded['keywords'] : array(), $defaultKeywords),
        'delay_ms' => sanitizeInt(isset($decoded['delay_ms']) ? $decoded['delay_ms'] : null, 3500, 1000, 120000),
        'removed_count' => sanitizeInt(isset($decoded['removed_count']) ? $decoded['removed_count'] : null, 0, 0, 100000000),
        'current_index' => sanitizeInt(isset($decoded['current_index']) ? $decoded['current_index'] : null, 0, 0, 100000000),
        'updated_at' => isset($decoded['updated_at']) ? (string) $decoded['updated_at'] : '',
        'reason' => isset($decoded['reason']) ? substr((string) $decoded['reason'], 0, 60) : ''
    );

    if ($state['updated_at'] === '') {
        $state['updated_at'] = date(DATE_ATOM);
    }

    if (count($state['pending_urls']) === 0) {
        $state['current_index'] = 0;
    } elseif ($state['current_index'] >= count($state['pending_urls'])) {
        $state['current_index'] = 0;
    }

    return $state;
}

function defaultState($defaultKeywords)
{
    return array(
        'pending_urls' => array(),
        'keywords' => $defaultKeywords,
        'delay_ms' => 3500,
        'removed_count' => 0,
        'current_index' => 0,
        'updated_at' => date(DATE_ATOM),
        'reason' => 'default'
    );
}

function getInputValue($key, $payload)
{
    if (is_array($payload) && array_key_exists($key, $payload)) {
        return $payload[$key];
    }
    if (isset($_POST[$key])) {
        return $_POST[$key];
    }
    return null;
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

function normalizeKeywords($raw, $defaultKeywords)
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

    if (count($out) === 0) {
        return $defaultKeywords;
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

function saveState($stateFile, $state)
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

    $json = json_encode($state, $options);
    if (!is_string($json)) {
        return false;
    }

    $bytes = @file_put_contents($stateFile, $json, LOCK_EX);
    return ($bytes !== false);
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
