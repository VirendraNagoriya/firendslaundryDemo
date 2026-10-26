<?php
declare(strict_types=1);

/**
 * Central error handling: log the details, never show a raw stack trace to a
 * customer. Set APP_DEBUG=1 (environment) or define APP_DEBUG in config/local.php
 * to see the technical details while developing.
 */

function app_error_id(): string
{
    static $id = null;
    if ($id === null) {
        try {
            $id = strtoupper(bin2hex(random_bytes(4)));
        } catch (Throwable $e) {
            $id = (string) mt_rand(10000000, 99999999);
        }
    }
    return $id;
}

function app_log_file(): string
{
    if (defined('APP_LOG_FILE') && APP_LOG_FILE !== '') {
        return APP_LOG_FILE;
    }
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'app.log';
}

function app_write_log(string $text): void
{
    $file = app_log_file();
    $dir = dirname($file);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    if (@file_put_contents($file, $text, FILE_APPEND | LOCK_EX) === false) {
        error_log($text);
    }
}

function app_log_throwable(Throwable $e): void
{
    $request = ($_SERVER['REQUEST_METHOD'] ?? 'CLI') . ' ' . ($_SERVER['REQUEST_URI'] ?? ($_SERVER['argv'][0] ?? ''));
    $userId = $_SESSION['user_id'] ?? 'guest';
    $lines = [
        '[' . date('Y-m-d H:i:s') . '] [' . app_error_id() . '] ' . get_class($e) . ': ' . $e->getMessage(),
        'Request: ' . $request,
        'User: ' . (is_scalar($userId) ? (string) $userId : 'unknown') . ' | IP: ' . ($_SERVER['REMOTE_ADDR'] ?? '-'),
        'File: ' . $e->getFile() . ':' . $e->getLine(),
        $e->getTraceAsString(),
        '',
    ];
    app_write_log(implode(PHP_EOL, $lines) . PHP_EOL);
}

function app_wants_json(): bool
{
    $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
    $requestedWith = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
    return stripos($accept, 'application/json') !== false || $requestedWith === 'xmlhttprequest';
}

function app_render_error(Throwable $e, int $status = 500): void
{
    $debug = defined('APP_DEBUG') && APP_DEBUG;
    $reference = app_error_id();

    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, 'ERROR [' . $reference . ']: ' . $e->getMessage() . PHP_EOL);
        if ($debug) {
            fwrite(STDERR, $e->getFile() . ':' . $e->getLine() . PHP_EOL . $e->getTraceAsString() . PHP_EOL);
        }
        exit(1);
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: text/html; charset=UTF-8');
    }

    if (app_wants_json()) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'ok' => false,
            'error' => 'Something went wrong. Please try again.',
            'reference' => $reference,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $appName = defined('APP_NAME') ? APP_NAME : 'Laundry Friends';
    $phone = defined('APP_CONTACT_PHONE') ? APP_CONTACT_PHONE : '';
    $email = defined('APP_CONTACT_EMAIL') ? APP_CONTACT_EMAIL : '';
    $details = '';
    if ($debug) {
        $details = '<details open style="margin-top:18px;text-align:left">'
            . '<summary style="cursor:pointer;font-weight:600">Technical details (debug mode)</summary>'
            . '<pre style="white-space:pre-wrap;word-break:break-word;background:#f6f7f9;padding:14px;border-radius:8px;font-size:12px">'
            . htmlspecialchars(get_class($e) . ': ' . $e->getMessage() . PHP_EOL . $e->getFile() . ':' . $e->getLine() . PHP_EOL . $e->getTraceAsString(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '</pre></details>';
    }

    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>Something went wrong - ' . htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') . '</title>'
        . '<style>'
        . 'body{margin:0;min-height:100vh;display:grid;place-items:center;background:#eef2f7;font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;color:#1f2937;padding:20px}'
        . '.card{max-width:620px;width:100%;background:#fff;border-radius:16px;padding:32px;box-shadow:0 12px 40px rgba(15,23,42,.12);text-align:center}'
        . '.mark{width:54px;height:54px;border-radius:14px;background:#2563eb;color:#fff;display:grid;place-items:center;font-weight:700;margin:0 auto 18px;font-size:20px}'
        . 'h1{font-size:24px;margin:0 0 10px}p{line-height:1.55;color:#4b5563;margin:0 0 12px}'
        . '.ref{display:inline-block;background:#f3f4f6;border-radius:8px;padding:6px 10px;font-family:ui-monospace,monospace;font-size:13px;margin:8px 0 18px}'
        . '.actions{display:flex;gap:10px;justify-content:center;flex-wrap:wrap;margin-top:18px}'
        . 'a.btn{display:inline-block;text-decoration:none;background:#2563eb;color:#fff;padding:10px 18px;border-radius:10px;font-weight:600}'
        . 'a.ghost{background:#fff;color:#2563eb;border:1px solid #c7d2fe}'
        . '</style></head><body><div class="card">'
        . '<div class="mark">LF</div>'
        . '<h1>Something went wrong</h1>'
        . '<p>We could not complete that request. Nothing was lost - please try again in a moment.</p>'
        . '<div class="ref">Reference: ' . htmlspecialchars($reference, ENT_QUOTES, 'UTF-8') . '</div>'
        . '<p>If it keeps happening, contact us'
        . ($phone !== '' ? ' on <strong>' . htmlspecialchars($phone, ENT_QUOTES, 'UTF-8') . '</strong>' : '')
        . ($email !== '' ? ' or <strong>' . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . '</strong>' : '')
        . ' and quote the reference above.</p>'
        . $details
        . '<div class="actions"><a class="btn" href="' . htmlspecialchars(defined('BASE_URL') && BASE_URL !== '' ? BASE_URL . '/' : '/', ENT_QUOTES, 'UTF-8') . '">Back to home</a>'
        . '<a class="btn ghost" href="javascript:history.back()">Go back</a></div>'
        . '</div></body></html>';
    exit;
}

function app_handle_exception(Throwable $e): void
{
    static $handled = false;
    if ($handled) {
        return;
    }
    $handled = true;
    app_log_throwable($e);
    app_render_error($e, 500);
}

function app_handle_error(int $severity, string $message, string $file, int $line): bool
{
    if ((error_reporting() & $severity) === 0) {
        return false;
    }
    $levels = [
        E_ERROR => 'ERROR',
        E_WARNING => 'WARNING',
        E_PARSE => 'PARSE',
        E_NOTICE => 'NOTICE',
        E_CORE_ERROR => 'CORE ERROR',
        E_CORE_WARNING => 'CORE WARNING',
        E_COMPILE_ERROR => 'COMPILE ERROR',
        E_COMPILE_WARNING => 'COMPILE WARNING',
        E_USER_ERROR => 'USER ERROR',
        E_USER_WARNING => 'USER WARNING',
        E_USER_NOTICE => 'USER NOTICE',
        E_RECOVERABLE_ERROR => 'RECOVERABLE ERROR',
        E_DEPRECATED => 'DEPRECATED',
        E_USER_DEPRECATED => 'USER DEPRECATED',
    ];
    $level = $levels[$severity] ?? ('LEVEL ' . $severity);
    app_write_log('[' . date('Y-m-d H:i:s') . '] [' . app_error_id() . '] ' . $level . ': ' . $message
        . ' in ' . $file . ':' . $line . ' | ' . ($_SERVER['REQUEST_METHOD'] ?? 'CLI') . ' ' . ($_SERVER['REQUEST_URI'] ?? '') . PHP_EOL);
    return false;
}

function app_handle_shutdown(): void
{
    $error = error_get_last();
    if ($error === null) {
        return;
    }
    $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array((int) $error['type'], $fatal, true)) {
        return;
    }
    app_handle_exception(new ErrorException(
        (string) $error['message'],
        0,
        (int) $error['type'],
        (string) $error['file'],
        (int) $error['line']
    ));
}

function app_register_error_handlers(): void
{
    error_reporting(E_ALL);
    $debug = defined('APP_DEBUG') && APP_DEBUG;
    @ini_set('display_errors', $debug ? '1' : '0');
    @ini_set('display_startup_errors', $debug ? '1' : '0');
    @ini_set('log_errors', '1');
    set_error_handler('app_handle_error');
    set_exception_handler('app_handle_exception');
    register_shutdown_function('app_handle_shutdown');
}
