<?php

declare(strict_types=1);

namespace Lovante\Debug;

use Throwable;

/**
 * Lovante ExceptionHandler
 *
 * Renders two kinds of error pages:
 *  - renderDebugPage()      → rich HTML with stack trace, code preview, request info
 *  - renderProductionPage() → clean minimal error page
 */
class ExceptionHandler
{
    /**
     * Number of lines of source code to show around the error line
     */
    protected const CODE_CONTEXT = 10;

    // =========================================================================
    // Public API
    // =========================================================================

    public static function renderDebugPage(Throwable $e): string
    {
        $frames   = static::buildFrames($e);
        $request  = static::captureRequest();
        $timeline = static::captureTimeline();

        return static::renderHtml($e, $frames, $request, $timeline);
    }

    public static function renderProductionPage(int $status = 500): string
    {
        $messages = [
            400 => ['Bad Request',           'The server could not understand the request.'],
            401 => ['Unauthorized',          'Authentication is required to access this resource.'],
            403 => ['Forbidden',             'You do not have permission to access this resource.'],
            404 => ['Not Found',             'The page you are looking for could not be found.'],
            405 => ['Method Not Allowed',    'The HTTP method is not allowed for this endpoint.'],
            419 => ['CSRF Token Mismatch',   'Your session has expired. Please refresh and try again.'],
            422 => ['Unprocessable Entity',  'The submitted data could not be processed.'],
            429 => ['Too Many Requests',     'You have made too many requests. Please slow down.'],
            500 => ['Server Error',          'Something went wrong on our end. We\'re working on it.'],
            503 => ['Service Unavailable',   'The service is temporarily unavailable. Please try again later.'],
        ];

        [$title, $message] = $messages[$status] ?? ['Error', 'An unexpected error occurred.'];

        return <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>{$status} {$title}</title>
            <style>
                *{margin:0;padding:0;box-sizing:border-box}
                body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;
                     background:#0f172a;color:#e2e8f0;display:flex;align-items:center;
                     justify-content:center;min-height:100vh;}
                .box{text-align:center;padding:3rem}
                .code{font-size:7rem;font-weight:800;color:#3b82f6;line-height:1;
                      letter-spacing:-4px;margin-bottom:1rem}
                h1{font-size:1.5rem;font-weight:600;margin-bottom:.75rem;color:#f1f5f9}
                p{color:#94a3b8;font-size:1rem;max-width:400px;line-height:1.6}
            </style>
        </head>
        <body>
            <div class="box">
                <div class="code">{$status}</div>
                <h1>{$title}</h1>
                <p>{$message}</p>
            </div>
        </body>
        </html>
        HTML;
    }

    // =========================================================================
    // Frame Building
    // =========================================================================

    protected static function buildFrames(Throwable $e): array
    {
        $frames = [];

        // The error origin itself (first frame)
        $frames[] = [
            'file'     => $e->getFile(),
            'line'     => $e->getLine(),
            'function' => '{main}',
            'class'    => null,
            'snippet'  => static::extractSnippet($e->getFile(), $e->getLine()),
        ];

        // Stack trace frames
        foreach ($e->getTrace() as $trace) {
            $file = $trace['file'] ?? null;
            $line = $trace['line'] ?? null;

            $frames[] = [
                'file'     => $file,
                'line'     => $line,
                'function' => $trace['function'] ?? null,
                'class'    => $trace['class'] ?? null,
                'type'     => $trace['type'] ?? null,
                'args'     => static::formatArgs($trace['args'] ?? []),
                'snippet'  => $file ? static::extractSnippet($file, (int) $line) : [],
            ];
        }

        return $frames;
    }

    /**
     * Extract lines of source code around the error line.
     * Returns array of ['line' => int, 'code' => string, 'highlight' => bool]
     */
    protected static function extractSnippet(string $file, int $errorLine): array
    {
        if (!file_exists($file) || !is_readable($file)) {
            return [];
        }

        $lines  = file($file);
        $start  = max(0, $errorLine - static::CODE_CONTEXT - 1);
        $end    = min(count($lines) - 1, $errorLine + static::CODE_CONTEXT - 1);
        $result = [];

        for ($i = $start; $i <= $end; $i++) {
            $result[] = [
                'line'      => $i + 1,
                'code'      => rtrim($lines[$i]),
                'highlight' => ($i + 1) === $errorLine,
            ];
        }

        return $result;
    }

    protected static function formatArgs(array $args): array
    {
        return array_map(static function ($arg): string {
            if (is_string($arg))  return '"' . (strlen($arg) > 40 ? substr($arg, 0, 40) . '…' : $arg) . '"';
            if (is_int($arg) || is_float($arg)) return (string) $arg;
            if (is_bool($arg))    return $arg ? 'true' : 'false';
            if (is_null($arg))    return 'null';
            if (is_array($arg))   return 'Array(' . count($arg) . ')';
            if (is_object($arg))  return get_class($arg);
            return gettype($arg);
        }, $args);
    }

    // =========================================================================
    // Context Capture
    // =========================================================================

    protected static function captureRequest(): array
    {
        return [
            'method'  => $_SERVER['REQUEST_METHOD'] ?? 'CLI',
            'uri'     => $_SERVER['REQUEST_URI'] ?? 'N/A',
            'ip'      => $_SERVER['REMOTE_ADDR'] ?? 'N/A',
            'get'     => $_GET,
            'post'    => $_POST,
            'headers' => static::getRequestHeaders(),
        ];
    }

    protected static function captureTimeline(): array
    {
        $start = defined('LOVANTE_START') ? LOVANTE_START : ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));

        return [
            'start'    => $start,
            'elapsed'  => round((microtime(true) - $start) * 1000, 2),
            'memory'   => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
            'php'      => PHP_VERSION,
            'lovante'   => '1.0.0',
        ];
    }

    protected static function getRequestHeaders(): array
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = ucwords(strtolower(str_replace('_', '-', substr($key, 5))), '-');
                $headers[$name] = $value;
            }
        }
        return $headers;
    }

    // =========================================================================
    // HTML Rendering
    // =========================================================================

    protected static function renderHtml(Throwable $e, array $frames, array $request, array $timeline): string
    {
        $class       = get_class($e);
        $shortClass  = static::shortClass($class);
        $message     = htmlspecialchars($e->getMessage(), ENT_QUOTES);
        $file        = $e->getFile();
        $line        = $e->getLine();
        $shortFile   = static::shortPath($file);

        $framesHtml  = static::renderFrames($frames);
        $requestHtml = static::renderRequestPanel($request);
        $timeHtml    = static::renderTimelinePanel($timeline);
        $css         = static::css();
        $js          = static::js();

        return <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>⚡ {$shortClass} — Lovante</title>
            <style>
                {$css}
            </style>
        </head>
        <body>

        <!-- ── TOP HEADER ─────────────────────────────────── -->
        <header class="top-header">
            <div class="brand">⚡ Lovante <span>Debug</span></div>
            <div class="meta">
                <span class="badge badge-red">{$shortClass}</span>
                <span class="badge badge-gray">{$timeline['elapsed']} ms</span>
                <span class="badge badge-gray">{$timeline['memory']} MB</span>
                <span class="badge badge-gray">PHP {$timeline['php']}</span>
            </div>
        </header>

        <!-- ── EXCEPTION SUMMARY ──────────────────────────── -->
        <section class="exception-summary">
            <div class="exception-class">{$class}</div>
            <div class="exception-message">{$message}</div>
            <div class="exception-location">
                <span class="file">{$shortFile}</span>
                <span class="sep">:</span>
                <span class="linenum">line {$line}</span>
            </div>
        </section>

        <!-- ── MAIN LAYOUT ────────────────────────────────── -->
        <div class="main-layout">

            <!-- Frames sidebar -->
            <aside class="frames-sidebar">
                <div class="sidebar-title">Stack Trace</div>
                {$framesHtml['sidebar']}
            </aside>

            <!-- Code + details panel -->
            <main class="detail-panel">
                <div id="code-area">
                    {$framesHtml['first_code']}
                </div>

                <!-- Tabs -->
                <div class="tabs">
                    <button class="tab active" onclick="showTab('request')">📥 Request</button>
                    <button class="tab" onclick="showTab('timeline')">⏱ Timeline</button>
                </div>

                <div id="tab-request" class="tab-content active">
                    {$requestHtml}
                </div>

                <div id="tab-timeline" class="tab-content">
                    {$timeHtml}
                </div>
            </main>
        </div>

        <script>
            {$js}
        </script>

        </body>
        </html>
        HTML;
    }

    protected static function renderFrames(array $frames): array
    {
        $sidebarItems = '';
        $firstCode    = '';

        foreach ($frames as $index => $frame) {
            $file      = $frame['file'] ?? 'internal';
            $line      = $frame['line'] ?? '';
            $shortFile = static::shortPath((string) $file);
            $func      = $frame['class']
                ? ($frame['class'] . ($frame['type'] ?? '::') . $frame['function'])
                : ($frame['function'] ?? '');
            $isApp     = !str_contains((string)$file, 'vendor');
            $appClass  = $isApp ? 'frame-app' : 'frame-vendor';
            $activeClass = $index === 0 ? 'active' : '';

            $argsHtml = '';
            if (!empty($frame['args'])) {
                $argsHtml = '<span class="frame-args">(' . htmlspecialchars(implode(', ', $frame['args'])) . ')</span>';
            }

            $sidebarItems .= <<<HTML
            <div class="frame-item {$appClass} {$activeClass}" 
                 onclick="selectFrame(this, 'frame-{$index}')"
                 data-frame="{$index}">
                <div class="frame-func">{$func}{$argsHtml}</div>
                <div class="frame-file">{$shortFile}<span class="frame-line">:{$line}</span></div>
            </div>
            HTML;

            $codeHtml = static::renderCodeSnippet($frame['snippet'], $frame['line'] ?? null);
            $display  = $index === 0 ? '' : 'style="display:none"';

            $firstCode .= <<<HTML
            <div id="frame-{$index}" class="code-block" {$display}>
                <div class="code-header">
                    <span class="code-file">{$file}</span>
                    <span class="code-line-badge">line {$line}</span>
                </div>
                {$codeHtml}
            </div>
            HTML;
        }

        return [
            'sidebar'    => $sidebarItems,
            'first_code' => $firstCode,
        ];
    }

    protected static function renderCodeSnippet(array $lines, ?int $errorLine): string
    {
        if (empty($lines)) {
            return '<div class="no-source">Source not available</div>';
        }

        $html = '<div class="code-snippet"><table>';

        foreach ($lines as $row) {
            $isHighlight = $row['highlight'] ? 'highlight' : '';
            $lineNum     = htmlspecialchars((string)$row['line']);
            $code        = htmlspecialchars($row['code']);

            $html .= <<<HTML
            <tr class="code-row {$isHighlight}">
                <td class="line-num">{$lineNum}</td>
                <td class="line-code"><pre>{$code}</pre></td>
            </tr>
            HTML;
        }

        return $html . '</table></div>';
    }

    protected static function renderRequestPanel(array $request): string
    {
        $method = htmlspecialchars($request['method']);
        $uri    = htmlspecialchars($request['uri']);
        $ip     = htmlspecialchars($request['ip']);

        $getHtml     = static::renderKvTable($request['get'], 'GET Parameters');
        $postHtml    = static::renderKvTable($request['post'], 'POST Parameters');
        $headersHtml = static::renderKvTable($request['headers'], 'Request Headers');

        return <<<HTML
        <div class="info-panel">
            <div class="info-row">
                <span class="info-label">Method</span>
                <span class="info-value badge badge-blue">{$method}</span>
            </div>
            <div class="info-row">
                <span class="info-label">URI</span>
                <span class="info-value mono">{$uri}</span>
            </div>
            <div class="info-row">
                <span class="info-label">IP</span>
                <span class="info-value mono">{$ip}</span>
            </div>
        </div>
        {$getHtml}
        {$postHtml}
        {$headersHtml}
        HTML;
    }

    protected static function renderTimelinePanel(array $timeline): string
    {
        $elapsed = $timeline['elapsed'];
        $memory  = $timeline['memory'];
        $php     = htmlspecialchars($timeline['php']);
        $lovante  = htmlspecialchars($timeline['lovante']);

        // Bar width capped at 100%
        $barWidth = min(100, (int)($elapsed / 2));

        return <<<HTML
        <div class="info-panel">
            <div class="info-row">
                <span class="info-label">Execution Time</span>
                <span class="info-value">
                    <span class="badge badge-blue">{$elapsed} ms</span>
                    <div class="perf-bar"><div class="perf-fill" style="width:{$barWidth}%"></div></div>
                </span>
            </div>
            <div class="info-row">
                <span class="info-label">Peak Memory</span>
                <span class="info-value badge badge-gray">{$memory} MB</span>
            </div>
            <div class="info-row">
                <span class="info-label">PHP Version</span>
                <span class="info-value badge badge-green">{$php}</span>
            </div>
            <div class="info-row">
                <span class="info-label">Lovante Version</span>
                <span class="info-value badge badge-purple">v{$lovante}</span>
            </div>
        </div>
        HTML;
    }

    protected static function renderKvTable(array $data, string $title): string
    {
        if (empty($data)) {
            return "<div class='kv-empty'>{$title}: <em>empty</em></div>";
        }

        $rows = '';
        foreach ($data as $key => $value) {
            $k = htmlspecialchars((string) $key);
            $v = htmlspecialchars(is_array($value) ? json_encode($value) : (string) $value);
            $rows .= "<tr><td class='kv-key'>{$k}</td><td class='kv-val'>{$v}</td></tr>";
        }

        return <<<HTML
        <div class="kv-table-wrap">
            <div class="kv-title">{$title}</div>
            <table class="kv-table">{$rows}</table>
        </div>
        HTML;
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    protected static function shortClass(string $class): string
    {
        $parts = explode('\\', $class);
        return end($parts);
    }

    public static function shortPath(string $path): string
    {
        // Show path relative to project root if possible
        $cwd = getcwd();
        if ($cwd && str_starts_with($path, $cwd)) {
            return '.' . str_replace('\\', '/', substr($path, strlen($cwd)));
        }
        return str_replace('\\', '/', $path);
    }

    // =========================================================================
    // CSS
    // =========================================================================

    protected static function css(): string
    {
        return <<<CSS
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --bg:        #0f172a;
            --bg2:       #1e293b;
            --bg3:       #334155;
            --border:    #334155;
            --text:      #e2e8f0;
            --muted:     #94a3b8;
            --red:       #ef4444;
            --red-dim:   #450a0a;
            --blue:      #3b82f6;
            --blue-dim:  #172554;
            --green:     #22c55e;
            --purple:    #a855f7;
            --yellow:    #fbbf24;
            --hl-bg:     #422006;
            --hl-border: #f97316;
            --font-mono: 'Fira Code', 'Cascadia Code', Consolas, monospace;
        }

        html { font-size: 14px; }
        body { background: var(--bg); color: var(--text);
               font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
               line-height: 1.5; min-height: 100vh; }

        /* ── HEADER ── */
        .top-header {
            display: flex; align-items: center; justify-content: space-between;
            padding: .65rem 1.5rem; background: var(--bg2);
            border-bottom: 1px solid var(--border); position: sticky; top: 0; z-index: 100;
        }
        .brand { font-weight: 700; font-size: 1.05rem; letter-spacing: -.5px; }
        .brand span { color: var(--blue); }
        .meta { display: flex; gap: .4rem; flex-wrap: wrap; }

        /* ── BADGES ── */
        .badge {
            display: inline-block; padding: .2rem .55rem; border-radius: 999px;
            font-size: .72rem; font-weight: 600; letter-spacing: .3px;
        }
        .badge-red    { background: var(--red-dim);  color: var(--red); }
        .badge-blue   { background: var(--blue-dim); color: var(--blue); }
        .badge-gray   { background: var(--bg3);      color: var(--muted); }
        .badge-green  { background: #052e16;         color: var(--green); }
        .badge-purple { background: #2e1065;         color: var(--purple); }

        /* ── EXCEPTION SUMMARY ── */
        .exception-summary {
            padding: 1.5rem 1.5rem 1.25rem;
            border-bottom: 1px solid var(--border);
            background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
        }
        .exception-class {
            font-size: .8rem; color: var(--red); font-weight: 600;
            font-family: var(--font-mono); margin-bottom: .4rem;
        }
        .exception-message {
            font-size: 1.4rem; font-weight: 700; color: #f8fafc;
            line-height: 1.3; margin-bottom: .65rem;
        }
        .exception-location { font-family: var(--font-mono); font-size: .8rem; color: var(--muted); }
        .exception-location .sep { margin: 0 .15rem; }
        .exception-location .linenum { color: var(--yellow); }

        /* ── MAIN LAYOUT ── */
        .main-layout {
            display: grid; grid-template-columns: 300px 1fr;
            height: calc(100vh - 120px);
        }

        /* ── FRAMES SIDEBAR ── */
        .frames-sidebar {
            background: var(--bg2); border-right: 1px solid var(--border);
            overflow-y: auto; display: flex; flex-direction: column;
        }
        .sidebar-title {
            padding: .65rem 1rem; font-size: .7rem; font-weight: 700;
            text-transform: uppercase; letter-spacing: 1px; color: var(--muted);
            border-bottom: 1px solid var(--border); position: sticky; top: 0;
            background: var(--bg2);
        }
        .frame-item {
            padding: .65rem 1rem; cursor: pointer; border-bottom: 1px solid var(--border);
            transition: background .12s;
        }
        .frame-item:hover   { background: var(--bg3); }
        .frame-item.active  { background: var(--blue-dim); border-left: 3px solid var(--blue); }
        .frame-app          { }
        .frame-vendor       { opacity: .55; }
        .frame-func { font-size: .78rem; font-family: var(--font-mono); color: var(--text);
                      white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .frame-file { font-size: .7rem; color: var(--muted); margin-top: .15rem;
                      white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .frame-line { color: var(--yellow); }
        .frame-args { color: var(--muted); font-size: .72rem; }

        /* ── DETAIL PANEL ── */
        .detail-panel { overflow-y: auto; display: flex; flex-direction: column; }

        /* ── CODE BLOCK ── */
        .code-block { flex-shrink: 0; }
        .code-header {
            display: flex; align-items: center; justify-content: space-between;
            padding: .5rem 1rem; background: var(--bg3);
            border-bottom: 1px solid var(--border);
        }
        .code-file { font-family: var(--font-mono); font-size: .75rem; color: var(--muted); }
        .code-line-badge { font-size: .72rem; color: var(--yellow); font-weight: 600; }

        .code-snippet { overflow-x: auto; }
        .code-snippet table { width: 100%; border-collapse: collapse; }
        .code-row td { padding: .12rem 0; }
        .code-row.highlight { background: var(--hl-bg); border-left: 3px solid var(--hl-border); }
        .code-row.highlight .line-num { color: var(--hl-border); }
        .line-num  { width: 3.5rem; text-align: right; padding-right: 1.2rem;
                     font-family: var(--font-mono); font-size: .78rem;
                     color: var(--bg3); user-select: none; vertical-align: top; }
        .code-row.highlight .line-num { color: var(--hl-border); }
        .line-code pre { font-family: var(--font-mono); font-size: .8rem;
                         color: var(--text); white-space: pre; }
        .no-source { padding: 1.5rem; color: var(--muted); font-style: italic; }

        /* ── TABS ── */
        .tabs {
            display: flex; border-bottom: 1px solid var(--border);
            background: var(--bg2); padding: 0 1rem; gap: .25rem;
            position: sticky; top: 0; z-index: 10;
        }
        .tab {
            padding: .55rem .9rem; background: none; border: none; cursor: pointer;
            font-size: .8rem; color: var(--muted); border-bottom: 2px solid transparent;
            margin-bottom: -1px; transition: color .15s, border-color .15s;
        }
        .tab:hover  { color: var(--text); }
        .tab.active { color: var(--blue); border-bottom-color: var(--blue); }

        .tab-content { display: none; padding: 1.25rem 1.5rem; }
        .tab-content.active { display: block; }

        /* ── INFO PANELS ── */
        .info-panel { margin-bottom: 1.25rem; }
        .info-row {
            display: flex; align-items: center; gap: 1rem; padding: .5rem 0;
            border-bottom: 1px solid var(--border);
        }
        .info-label { color: var(--muted); font-size: .78rem; min-width: 130px; }
        .info-value { font-size: .82rem; font-family: var(--font-mono); }
        .info-value.mono { color: var(--text); }

        /* ── PERF BAR ── */
        .perf-bar { width: 120px; height: 4px; background: var(--bg3);
                    border-radius: 2px; margin-top: .3rem; }
        .perf-fill { height: 100%; background: var(--blue); border-radius: 2px; }

        /* ── KV TABLE ── */
        .kv-empty { color: var(--muted); font-size: .78rem; padding: .4rem 0; margin-bottom: .75rem; }
        .kv-table-wrap { margin-bottom: 1.25rem; }
        .kv-title { font-size: .7rem; font-weight: 700; text-transform: uppercase;
                    letter-spacing: 1px; color: var(--muted); margin-bottom: .4rem; }
        .kv-table { width: 100%; border-collapse: collapse; font-size: .78rem; }
        .kv-table tr { border-bottom: 1px solid var(--border); }
        .kv-key { padding: .35rem .5rem; color: var(--blue); font-family: var(--font-mono);
                  width: 35%; vertical-align: top; }
        .kv-val { padding: .35rem .5rem; color: var(--text); font-family: var(--font-mono);
                  word-break: break-all; }

        /* ── SCROLLBAR ── */
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: var(--bg); }
        ::-webkit-scrollbar-thumb { background: var(--bg3); border-radius: 3px; }

        @media (max-width: 768px) {
            .main-layout { grid-template-columns: 1fr; height: auto; }
            .frames-sidebar { max-height: 220px; }
        }
        CSS;
    }

    // =========================================================================
    // JS
    // =========================================================================

    protected static function js(): string
    {
        return <<<JS
        function selectFrame(el, frameId) {
            document.querySelectorAll('.frame-item').forEach(f => f.classList.remove('active'));
            el.classList.add('active');
            document.querySelectorAll('.code-block').forEach(b => b.style.display = 'none');
            const block = document.getElementById(frameId);
            if (block) block.style.display = 'block';
        }

        function showTab(name) {
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
            document.querySelector('[onclick="showTab(\'' + name + '\')"]').classList.add('active');
            document.getElementById('tab-' + name).classList.add('active');
        }
        JS;
    }
}