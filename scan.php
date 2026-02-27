<?php

$ALLOWED_HOSTS = array('earnapp.com', 'www.earnapp.com');
$DEFAULT_MATCH_WORDS = array('Sucessfull', 'Successful', 'Already');

$action = isset($_GET['action']) ? $_GET['action'] : '';
if ($action === 'frame') {
    renderFrame($ALLOWED_HOSTS);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJson(array('ok' => false, 'error' => 'Metodo no permitido'), 405);
}

$rawInput = file_get_contents('php://input');
$payload = json_decode($rawInput, true);
$url = '';

if (is_array($payload) && isset($payload['url'])) {
    $url = (string) $payload['url'];
} elseif (isset($_POST['url'])) {
    $url = (string) $_POST['url'];
}

$url = trim($url);
$safeUrl = validateUrl($url, $ALLOWED_HOSTS);
$keywords = resolveKeywords($payload, $DEFAULT_MATCH_WORDS);

if ($safeUrl === null) {
    sendJson(array('ok' => false, 'error' => 'URL invalida o no permitida'), 400);
}

$result = fetchUrl($safeUrl);

if (!empty($result['error'])) {
    sendJson(
        array(
            'ok' => false,
            'error' => $result['error'],
            'http_code' => $result['http_code'],
            'effective_url' => $result['effective_url']
        ),
        500
    );
}

$keyword = findKeyword($result['body'], $keywords);

sendJson(
    array(
        'ok' => true,
        'matched' => ($keyword !== null),
        'keyword' => $keyword,
        'keywords_count' => count($keywords),
        'http_code' => $result['http_code'],
        'effective_url' => $result['effective_url'],
        'checked_at' => date(DATE_ATOM)
    ),
    200
);

function renderFrame($allowedHosts)
{
    $url = isset($_GET['url']) ? trim((string) $_GET['url']) : '';
    $safeUrl = validateUrl($url, $allowedHosts);

    if ($safeUrl === null) {
        outputErrorHtml('URL invalida o no permitida.');
        return;
    }

    $result = fetchUrl($safeUrl);

    if (!empty($result['error'])) {
        outputErrorHtml('No se pudo cargar la URL: ' . escapeHtml($result['error']));
        return;
    }

    $contentType = strtolower(isset($result['content_type']) ? (string) $result['content_type'] : '');
    $body = isset($result['body']) ? (string) $result['body'] : '';

    if (strpos($contentType, 'text/html') === false) {
        header('Content-Type: text/plain; charset=utf-8');
        echo $body;
        return;
    }

    header('Content-Type: text/html; charset=utf-8');
    $effectiveUrl = isset($result['effective_url']) ? (string) $result['effective_url'] : '';
    if ($effectiveUrl === '') {
        $effectiveUrl = $safeUrl;
    }
    echo injectBaseTag($body, $effectiveUrl);
}

function validateUrl($url, $allowedHosts)
{
    if ($url === '') {
        return null;
    }

    $validated = filter_var($url, FILTER_VALIDATE_URL);
    if (!is_string($validated)) {
        return null;
    }

    $parts = parse_url($validated);
    if (!is_array($parts)) {
        return null;
    }

    $scheme = isset($parts['scheme']) ? strtolower((string) $parts['scheme']) : '';
    if ($scheme !== 'https' && $scheme !== 'http') {
        return null;
    }

    $host = isset($parts['host']) ? strtolower((string) $parts['host']) : '';
    if (!in_array($host, $allowedHosts, true)) {
        return null;
    }

    return $validated;
}

function fetchUrl($url)
{
    if (!function_exists('curl_init')) {
        return array(
            'error' => 'La extension cURL no esta habilitada en PHP.',
            'body' => '',
            'http_code' => 0,
            'effective_url' => '',
            'content_type' => ''
        );
    }

    $ch = curl_init($url);
    if ($ch === false) {
        return array(
            'error' => 'No se pudo inicializar cURL.',
            'body' => '',
            'http_code' => 0,
            'effective_url' => '',
            'content_type' => ''
        );
    }

    curl_setopt_array(
        $ch,
        array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 8,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) PHP URL Scanner',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2
        )
    );

    $body = curl_exec($ch);
    $errorText = null;
    if ($body === false) {
        $errorText = curl_error($ch);
        $body = '';
    }

    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $effectiveUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    return array(
        'error' => $errorText,
        'body' => (string) $body,
        'http_code' => $httpCode,
        'effective_url' => $effectiveUrl,
        'content_type' => $contentType
    );
}

function findKeyword($content, $matchWords)
{
    foreach ($matchWords as $word) {
        if (stripos($content, $word) !== false) {
            return $word;
        }
    }
    return null;
}

function resolveKeywords($payload, $defaultWords)
{
    $raw = null;
    if (is_array($payload) && isset($payload['keywords'])) {
        $raw = $payload['keywords'];
    } elseif (isset($_POST['keywords'])) {
        $raw = $_POST['keywords'];
    }

    $clean = normalizeKeywords($raw);
    if (count($clean) === 0) {
        return $defaultWords;
    }
    return $clean;
}

function normalizeKeywords($raw)
{
    $items = array();

    if (is_array($raw)) {
        foreach ($raw as $piece) {
            $items[] = (string) $piece;
        }
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
        if (count($out) >= 50) {
            break;
        }
    }

    return $out;
}

function injectBaseTag($html, $baseUrl)
{
    $baseTag = '<base href="' . escapeHtml($baseUrl) . '">';
    $updated = preg_replace('/<head\b[^>]*>/i', '$0' . $baseTag, $html, 1);
    if (is_string($updated) && $updated !== $html) {
        return $updated;
    }
    return $baseTag . $html;
}

function outputErrorHtml($message)
{
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html><head><meta charset="utf-8"><title>Error</title></head><body style="font-family:Segoe UI,Tahoma,sans-serif;background:#f8fafc;padding:16px;"><h3 style="margin-top:0;color:#9f2424;">No se pudo cargar el frame</h3><p>' . $message . '</p></body></html>';
}

function escapeHtml($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
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
