<?php

$ALLOWED_HOSTS = array('earnapp.com', 'www.earnapp.com');
$DEFAULT_MATCH_WORDS = array('Sucessfull', 'Successful', 'Already');

$action = isset($_GET['action']) ? $_GET['action'] : '';
if ($action === 'frame') {
    renderFrame($ALLOWED_HOSTS);
    exit;
}
if ($action === 'proxy') {
    proxyRequest($ALLOWED_HOSTS);
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
    $proxyEndpointUrl = getProxyEndpointUrl();
    $frameEndpointUrl = getFrameEndpointUrl();
    $html = injectBaseTag($body, $effectiveUrl);
    $html = rewriteHtmlAssetUrlsForProxy($html, $effectiveUrl, $proxyEndpointUrl, $allowedHosts);
    $html = injectFrameBrowserBridge($html, $effectiveUrl, $proxyEndpointUrl, $frameEndpointUrl);
    echo $html;
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

    $result = executeCurlRequest($url, true);

    // Entornos viejos (PHP/OpenSSL antiguos) pueden fallar con error 60 por CA local.
    // Si pasa eso, se hace un reintento sin verificacion SSL para mantener operativo el escaner.
    if ((int) $result['error_no'] === 60 || isSslIssuerError($result['error'])) {
        $fallback = executeCurlRequest($url, false);
        if (empty($fallback['error'])) {
            $fallback['warning'] = 'SSL verification disabled fallback';
            return $fallback;
        }
        return $result;
    }

    return $result;
}

function executeCurlRequest($url, $verifySsl)
{
    $ch = curl_init($url);
    if ($ch === false) {
        return array(
            'error' => 'No se pudo inicializar cURL.',
            'error_no' => 0,
            'body' => '',
            'http_code' => 0,
            'effective_url' => '',
            'content_type' => ''
        );
    }

    $verifyPeer = $verifySsl ? true : false;
    $verifyHost = $verifySsl ? 2 : 0;

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
            CURLOPT_SSL_VERIFYPEER => $verifyPeer,
            CURLOPT_SSL_VERIFYHOST => $verifyHost
        )
    );

    $body = curl_exec($ch);
    $errorText = null;
    $errorNo = 0;
    if ($body === false) {
        $errorText = curl_error($ch);
        $errorNo = (int) curl_errno($ch);
        $body = '';
    }

    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $effectiveUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    return array(
        'error' => $errorText,
        'error_no' => $errorNo,
        'body' => (string) $body,
        'http_code' => $httpCode,
        'effective_url' => $effectiveUrl,
        'content_type' => $contentType
    );
}

function isSslIssuerError($errorText)
{
    if (!is_string($errorText) || trim($errorText) === '') {
        return false;
    }

    $msg = strtolower($errorText);
    if (strpos($msg, 'unable to get local issuer certificate') !== false) {
        return true;
    }
    if (strpos($msg, 'ssl certificate problem') !== false) {
        return true;
    }
    if (strpos($msg, 'certificate verify failed') !== false) {
        return true;
    }

    return false;
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

function rewriteHtmlAssetUrlsForProxy($html, $baseUrl, $proxyEndpointUrl, $allowedHosts)
{
    $pattern = '/(<(?:script|link|img|iframe)\b[^>]*?\b(?:src|href)=["\'])([^"\']+)(["\'])/i';
    $rewritten = preg_replace_callback(
        $pattern,
        function ($m) use ($baseUrl, $proxyEndpointUrl, $allowedHosts) {
            $prefix = $m[1];
            $url = $m[2];
            $suffix = $m[3];

            $trimmed = trim((string) $url);
            if ($trimmed === '') {
                return $m[0];
            }
            if (strpos($trimmed, 'data:') === 0 || strpos($trimmed, 'javascript:') === 0 || strpos($trimmed, '#') === 0) {
                return $m[0];
            }
            if (stripos($trimmed, 'scan.php?action=proxy') !== false || stripos($trimmed, 'scan.php?action=frame') !== false) {
                return $m[0];
            }

            $absolute = resolveUrl($trimmed, $baseUrl);
            if ($absolute === null) {
                return $m[0];
            }

            $host = strtolower((string) parse_url($absolute, PHP_URL_HOST));
            if (!in_array($host, $allowedHosts, true)) {
                return $m[0];
            }

            $proxied = $proxyEndpointUrl . urlencode($absolute);
            return $prefix . $proxied . $suffix;
        },
        $html
    );

    if (!is_string($rewritten)) {
        return $html;
    }
    return $rewritten;
}

function resolveUrl($url, $baseUrl)
{
    $url = trim((string) $url);
    if ($url === '') {
        return null;
    }

    if (preg_match('/^https?:\/\//i', $url)) {
        return $url;
    }

    $base = parse_url($baseUrl);
    if (!is_array($base)) {
        return null;
    }
    $scheme = isset($base['scheme']) ? $base['scheme'] : 'https';
    $host = isset($base['host']) ? $base['host'] : '';
    if ($host === '') {
        return null;
    }
    $port = isset($base['port']) ? ':' . $base['port'] : '';

    if (strpos($url, '//') === 0) {
        return $scheme . ':' . $url;
    }

    if (strpos($url, '/') === 0) {
        return $scheme . '://' . $host . $port . $url;
    }

    $basePath = isset($base['path']) ? (string) $base['path'] : '/';
    $dir = preg_replace('/[^\/]+$/', '', $basePath);
    if (!is_string($dir) || $dir === '') {
        $dir = '/';
    }
    return $scheme . '://' . $host . $port . $dir . $url;
}

function injectFrameBrowserBridge($html, $targetUrl, $proxyEndpointUrl, $frameEndpointUrl)
{
    $escapedTargetUrl = escapeJsString((string) $targetUrl);
    $escapedProxyEndpointUrl = escapeJsString((string) $proxyEndpointUrl);
    $escapedFrameEndpointUrl = escapeJsString((string) $frameEndpointUrl);
    $bridgeScript = '<script>(function(){'
        . 'var proxyPrefix="' . $escapedProxyEndpointUrl . '";'
        . 'var framePrefix="' . $escapedFrameEndpointUrl . '";'
        . 'var targetUrl="' . $escapedTargetUrl . '";'
        . 'var targetObj=null;'
        . 'try{targetObj=new URL(targetUrl);}catch(e){}'
        . 'try{'
        . 'if(targetObj){'
        . 'var virtualPath=(targetObj.pathname||"/")+(targetObj.search||"")+(targetObj.hash||"");'
        . 'if(virtualPath){history.replaceState({embeddedProxy:true},"",virtualPath);}'
        . '}'
        . '}catch(e){}'
        . 'function toAbs(url){'
        . 'try{return new URL(url, document.baseURI).toString();}catch(e){return null;}'
        . '}'
        . 'function normalizeProxyAbs(abs){'
        . 'if(!abs){return false;}'
        . 'try{'
        . 'var u=new URL(abs);'
        . 'var protocol=(u.protocol||"").toLowerCase();'
        . 'if(protocol!=="http:" && protocol!=="https:"){return null;}'
        . 'var host=(u.host||"").toLowerCase();'
        . 'if(host==="earnapp.com" || host==="www.earnapp.com"){return u.toString();}'
        . 'if(targetObj && host===String(window.location.host||"").toLowerCase() && /^\\/dashboard\\//i.test(u.pathname||"")){'
        . 'return targetObj.protocol+"//"+targetObj.host+u.pathname+(u.search||"")+(u.hash||"");'
        . '}'
        . '}catch(e){}'
        . 'return null;'
        . '}'
        . 'function toProxy(url){'
        . 'if(typeof url!=="string"){return url;}'
        . 'var abs=toAbs(url);'
        . 'var normalized=normalizeProxyAbs(abs);'
        . 'if(!normalized){return url;}'
        . 'return proxyPrefix + encodeURIComponent(normalized);'
        . '}'
        . 'function toFrame(url){'
        . 'if(typeof url!=="string"){return url;}'
        . 'var abs=toAbs(url);'
        . 'var normalized=normalizeProxyAbs(abs);'
        . 'if(!normalized){return url;}'
        . 'return framePrefix + encodeURIComponent(normalized);'
        . '}'
        . 'if(window.fetch){'
        . 'var nativeFetch=window.fetch;'
        . 'window.fetch=function(resource,init){'
        . 'try{'
        . 'if(typeof Request!=="undefined" && resource instanceof Request){'
        . 'resource=new Request(toProxy(resource.url),resource);'
        . '}else if(typeof resource==="string"){'
        . 'resource=toProxy(resource);'
        . '}'
        . '}catch(e){}'
        . 'return nativeFetch.call(this,resource,init);'
        . '};'
        . '}'
        . 'if(window.XMLHttpRequest && window.XMLHttpRequest.prototype){'
        . 'var nativeOpen=window.XMLHttpRequest.prototype.open;'
        . 'window.XMLHttpRequest.prototype.open=function(method,url){'
        . 'try{arguments[1]=toProxy(url);}catch(e){}'
        . 'return nativeOpen.apply(this,arguments);'
        . '};'
        . '}'
        . 'if(window.EventSource){'
        . 'var NativeEventSource=window.EventSource;'
        . 'window.EventSource=function(url,config){'
        . 'return new NativeEventSource(toProxy(url),config);'
        . '};'
        . '}'
        . 'window.__earnappProxyToProxy=toProxy;'
        . 'window.__earnappProxyToFrame=toFrame;'
        . '})();</script>';

    $updated = preg_replace('/<head\b[^>]*>/i', '$0' . $bridgeScript, $html, 1);
    if (is_string($updated) && $updated !== $html) {
        return $updated;
    }
    return $bridgeScript . $html;
}

function getProxyEndpointUrl()
{
    $scheme = 'http';
    if (isset($_SERVER['REQUEST_SCHEME']) && $_SERVER['REQUEST_SCHEME'] !== '') {
        $scheme = strtolower((string) $_SERVER['REQUEST_SCHEME']);
    } elseif (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        $scheme = 'https';
    }

    $host = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : 'localhost';
    $scriptName = isset($_SERVER['SCRIPT_NAME']) ? (string) $_SERVER['SCRIPT_NAME'] : '/scan.php';

    return $scheme . '://' . $host . $scriptName . '?action=proxy&u=';
}

function getFrameEndpointUrl()
{
    $scheme = 'http';
    if (isset($_SERVER['REQUEST_SCHEME']) && $_SERVER['REQUEST_SCHEME'] !== '') {
        $scheme = strtolower((string) $_SERVER['REQUEST_SCHEME']);
    } elseif (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        $scheme = 'https';
    }

    $host = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : 'localhost';
    $scriptName = isset($_SERVER['SCRIPT_NAME']) ? (string) $_SERVER['SCRIPT_NAME'] : '/scan.php';

    return $scheme . '://' . $host . $scriptName . '?action=frame&url=';
}

function escapeJsString($value)
{
    $value = (string) $value;
    $value = str_replace('\\', '\\\\', $value);
    $value = str_replace('"', '\\"', $value);
    $value = str_replace("\r", '\\r', $value);
    $value = str_replace("\n", '\\n', $value);
    return $value;
}

function proxyRequest($allowedHosts)
{
    $target = isset($_GET['u']) ? trim((string) $_GET['u']) : '';
    $safeTarget = validateUrl($target, $allowedHosts);
    if ($safeTarget === null) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'URL de proxy invalida o no permitida.';
        return;
    }

    $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';
    $inputBody = (string) file_get_contents('php://input');
    $incomingHeaders = getRequestHeadersSafe();
    $cookieHeader = getProxyCookieHeader($safeTarget);

    $forwardHeaders = array();
    foreach ($incomingHeaders as $headerName => $headerValue) {
        $nameLower = strtolower($headerName);
        if ($nameLower === 'host') {
            continue;
        }
        if ($nameLower === 'content-length') {
            continue;
        }
        if ($nameLower === 'cookie') {
            continue;
        }
        if ($nameLower === 'referer' || $nameLower === 'origin') {
            continue;
        }
        if ($nameLower === 'accept-encoding') {
            continue;
        }
        $forwardHeaders[] = $headerName . ': ' . $headerValue;
    }
    if ($cookieHeader !== '') {
        $forwardHeaders[] = 'Cookie: ' . $cookieHeader;
    }
    if (!hasHeader($forwardHeaders, 'User-Agent')) {
        $forwardHeaders[] = 'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) Embedded Proxy Scanner';
    }
    if (!hasHeader($forwardHeaders, 'Accept')) {
        $forwardHeaders[] = 'Accept: */*';
    }
    $forwardHeaders[] = 'Accept-Encoding: gzip, deflate';

    $responseHeaders = array();
    $ch = curl_init($safeTarget);
    if ($ch === false) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'No se pudo inicializar proxy.';
        return;
    }

    curl_setopt_array(
        $ch,
        array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 8,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_ENCODING => 'gzip,deflate',
            CURLOPT_HTTPHEADER => $forwardHeaders,
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$responseHeaders) {
                $len = strlen($line);
                $trimmed = trim($line);
                if ($trimmed !== '') {
                    $responseHeaders[] = $trimmed;
                }
                return $len;
            }
        )
    );

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $inputBody);
    } elseif ($method !== 'GET' && $method !== 'HEAD') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        if ($inputBody !== '') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $inputBody);
        }
    } elseif ($method === 'HEAD') {
        curl_setopt($ch, CURLOPT_NOBODY, true);
    }

    $body = curl_exec($ch);
    $errorText = null;
    $errorNo = 0;
    if ($body === false) {
        $errorText = curl_error($ch);
        $errorNo = (int) curl_errno($ch);
        $body = '';
    }

    if ($errorNo === 60 || isSslIssuerError($errorText)) {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        $responseHeaders = array();
        $body = curl_exec($ch);
        if ($body === false) {
            $errorText = curl_error($ch);
            $errorNo = (int) curl_errno($ch);
            $body = '';
        } else {
            $errorText = null;
            $errorNo = 0;
        }
    }

    $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $effectiveUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);

    if ($errorText !== null) {
        http_response_code(502);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Proxy error: ' . $errorText;
        return;
    }

    storeProxyCookiesFromHeaders($responseHeaders, $effectiveUrl !== '' ? $effectiveUrl : $safeTarget);
    emitProxyCookiesToClient($responseHeaders);

    $requestedPath = (string) parse_url($safeTarget, PHP_URL_PATH);
    if ($statusCode === 403 && stripos($requestedPath, '/dashboard/api/user_data') === 0) {
        $statusCode = 200;
        $contentType = 'application/json; charset=utf-8';
        $body = '{"ok":true,"guest":true,"id":0,"email":"embedded@local.invalid","name":"Embedded User","country":"","features":{},"proxy_embedded":true}';
    }

    if ($statusCode > 0) {
        http_response_code($statusCode);
    }
    if ($contentType !== '') {
        header('Content-Type: ' . $contentType);
    }

    emitSafeProxyHeaders($responseHeaders);
    echo (string) $body;
}

function emitSafeProxyHeaders($responseHeaders)
{
    foreach ($responseHeaders as $headerLine) {
        $parts = explode(':', $headerLine, 2);
        if (count($parts) < 2) {
            continue;
        }
        $name = trim($parts[0]);
        $value = trim($parts[1]);
        if ($name === '') {
            continue;
        }

        $nameLower = strtolower($name);
        if ($nameLower === 'content-length') {
            continue;
        }
        if ($nameLower === 'transfer-encoding') {
            continue;
        }
        if ($nameLower === 'connection') {
            continue;
        }
        if ($nameLower === 'content-encoding') {
            continue;
        }
        if ($nameLower === 'set-cookie') {
            continue;
        }
        if ($nameLower === 'x-frame-options') {
            continue;
        }
        if ($nameLower === 'content-security-policy') {
            continue;
        }
        if ($nameLower === 'strict-transport-security') {
            continue;
        }
        if ($nameLower === 'cf-ray' || $nameLower === 'cf-cache-status' || $nameLower === 'server' || $nameLower === 'alt-svc') {
            continue;
        }

        header($name . ': ' . $value, false);
    }
}

function getRequestHeadersSafe()
{
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        if (is_array($headers)) {
            return $headers;
        }
    }

    $out = array();
    foreach ($_SERVER as $key => $value) {
        if (strpos($key, 'HTTP_') !== 0) {
            continue;
        }
        $name = str_replace('_', '-', substr($key, 5));
        $name = ucwords(strtolower($name), '-');
        $out[$name] = (string) $value;
    }
    return $out;
}

function hasHeader($headerLines, $headerName)
{
    $needle = strtolower($headerName . ':');
    foreach ($headerLines as $line) {
        if (strpos(strtolower($line), $needle) === 0) {
            return true;
        }
    }
    return false;
}

function getProxyCookieHeader($url)
{
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    if ($host === '') {
        return '';
    }

    if (session_id() === '') {
        @session_start();
    }

    if (!isset($_SESSION['proxy_cookie_jar']) || !is_array($_SESSION['proxy_cookie_jar'])) {
        return '';
    }

    if (!isset($_SESSION['proxy_cookie_jar'][$host]) || !is_array($_SESSION['proxy_cookie_jar'][$host])) {
        return '';
    }

    $pairs = array();
    foreach ($_SESSION['proxy_cookie_jar'][$host] as $name => $value) {
        $pairs[] = $name . '=' . $value;
    }
    return implode('; ', $pairs);
}

function storeProxyCookiesFromHeaders($responseHeaders, $url)
{
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    if ($host === '') {
        return;
    }

    if (session_id() === '') {
        @session_start();
    }

    if (!isset($_SESSION['proxy_cookie_jar']) || !is_array($_SESSION['proxy_cookie_jar'])) {
        $_SESSION['proxy_cookie_jar'] = array();
    }
    if (!isset($_SESSION['proxy_cookie_jar'][$host]) || !is_array($_SESSION['proxy_cookie_jar'][$host])) {
        $_SESSION['proxy_cookie_jar'][$host] = array();
    }

    foreach ($responseHeaders as $line) {
        if (stripos($line, 'Set-Cookie:') !== 0) {
            continue;
        }
        $cookiePart = trim(substr($line, strlen('Set-Cookie:')));
        if ($cookiePart === '') {
            continue;
        }
        $cookiePair = explode(';', $cookiePart, 2);
        $nameValue = trim($cookiePair[0]);
        if (strpos($nameValue, '=') === false) {
            continue;
        }
        $nameValueParts = explode('=', $nameValue, 2);
        $cookieName = trim($nameValueParts[0]);
        $cookieValue = trim($nameValueParts[1]);
        if ($cookieName === '') {
            continue;
        }
        if ($cookieValue === '') {
            unset($_SESSION['proxy_cookie_jar'][$host][$cookieName]);
            continue;
        }
        $_SESSION['proxy_cookie_jar'][$host][$cookieName] = $cookieValue;
    }
}

function emitProxyCookiesToClient($responseHeaders)
{
    foreach ($responseHeaders as $line) {
        if (stripos($line, 'Set-Cookie:') !== 0) {
            continue;
        }

        $cookiePart = trim(substr($line, strlen('Set-Cookie:')));
        if ($cookiePart === '') {
            continue;
        }

        $parts = explode(';', $cookiePart);
        if (!is_array($parts) || count($parts) === 0) {
            continue;
        }

        $nameValue = trim(array_shift($parts));
        if ($nameValue === '' || strpos($nameValue, '=') === false) {
            continue;
        }

        $newAttrs = array();
        $hasPath = false;
        $hasSameSite = false;
        foreach ($parts as $attr) {
            $attr = trim($attr);
            if ($attr === '') {
                continue;
            }
            $lower = strtolower($attr);
            if ($lower === 'secure') {
                continue;
            }
            if (strpos($lower, 'domain=') === 0) {
                continue;
            }
            if (strpos($lower, 'path=') === 0) {
                $hasPath = true;
            }
            if (strpos($lower, 'samesite=') === 0) {
                $hasSameSite = true;
            }
            $newAttrs[] = $attr;
        }

        if (!$hasPath) {
            $newAttrs[] = 'Path=/';
        }
        if (!$hasSameSite) {
            $newAttrs[] = 'SameSite=Lax';
        }

        $headerLine = 'Set-Cookie: ' . $nameValue;
        if (count($newAttrs) > 0) {
            $headerLine .= '; ' . implode('; ', $newAttrs);
        }
        header($headerLine, false);
    }
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
