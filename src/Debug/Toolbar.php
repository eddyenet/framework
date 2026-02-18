<?php

declare(strict_types=1);

namespace Lovante\Debug;

use Lovante\Http\Request;
use Lovante\Http\Response;

/**
 * Lovante Debug Toolbar
 *
 * Injects a Symfony-style debug toolbar into HTML responses.
 * Only active in debug mode — completely invisible in production.
 *
 * Panels:
 *  ⚡ Lovante      — framework brand + execution time
 *  🛣  Route       — matched method + URI
 *  📥 Request     — method, params count
 *  📤 Response    — status code
 *  💾 Memory      — peak memory usage
 *  🐘 PHP         — PHP version
 */
class Toolbar
{
    protected static float  $startTime;
    protected static int    $startMemory;
    protected static array  $collectors = [];

    /**
     * Initialize — call this at bootstrap (before any output)
     */
    public static function init(): void
    {
        static::$startTime   = defined('LOVANTE_START')
            ? LOVANTE_START
            : microtime(true);
        static::$startMemory = memory_get_usage(true);
    }

    /**
     * Add a custom data collector
     * $collector = ['label' => '🔥 Jobs', 'value' => '3', 'detail' => 'queued']
     */
    public static function collect(array $collector): void
    {
        static::$collectors[] = $collector;
    }

    /**
     * Inject the toolbar into an HTML Response.
     * Returns the Response unchanged if it's not HTML.
     */
    public static function inject(Response $response, ?Request $request = null): Response
    {
        // Only inject into successful HTML responses
        $contentType = $response->getHeader('Content-Type') ?? 'text/html';
        if (!str_contains($contentType, 'text/html')) {
            return $response;
        }

        $content = $response->getContent();

        // Only inject if there's a closing </body> tag
        if (!str_contains($content, '</body>')) {
            return $response;
        }

        $toolbar = static::render($response, $request);
        $content = str_replace('</body>', $toolbar . '</body>', $content);

        return $response->setContent($content);
    }

    /**
     * Render the toolbar HTML
     */
    protected static function render(Response $response, ?Request $request): string
    {
        $elapsed = round((microtime(true) - static::$startTime) * 1000, 2);
        
        // Memory dalam bytes
        $memoryPeakBytes = memory_get_peak_usage(true);
        $memoryStartBytes = static::$startMemory ?? 0;
        $memoryUsedBytes = $memoryPeakBytes - $memoryStartBytes;
        
        // Format memory - gunakan KB jika < 1MB, MB jika >= 1MB
        $memory = static::formatMemory($memoryPeakBytes);
        $memoryUsed = static::formatMemory($memoryUsedBytes);
        
        // Color based on peak memory
        $memoryPeakMB = $memoryPeakBytes / 1024 / 1024;
        $memClass = match(true) {
            $memoryPeakMB > 64 => 'tb-red',
            $memoryPeakMB > 32 => 'tb-orange',
            default            => 'tb-green',
        };
        
        $status  = $response->getStatus();
        $php     = PHP_VERSION;

        $statusClass = match(true) {
            $status >= 500 => 'tb-red',
            $status >= 400 => 'tb-orange',
            $status >= 300 => 'tb-blue',
            default        => 'tb-green',
        };

        $timeClass = match(true) {
            $elapsed > 500  => 'tb-red',
            $elapsed > 200  => 'tb-orange',
            default         => 'tb-green',
        };

        $memClass = match(true) {
            $memory > 64 => 'tb-red',
            $memory > 32 => 'tb-orange',
            default      => 'tb-green',
        };

        // Route info
        $method = $request?->method() ?? '—';
        $uri    = htmlspecialchars($request?->path() ?? '—');

        // Database queries
        $queries = class_exists('Lovante\Database\Connection')
            ? \Lovante\Database\Connection::getQueryLog()
            : [];
        $queryCount = count($queries);
        $queryTime  = array_sum(array_column($queries, 'time'));
        $dbClass    = $queryTime > 100 ? 'tb-orange' : ($queryTime > 50 ? 'tb-yellow' : 'tb-green');

        // Custom collectors
        $extra = '';
        foreach (static::$collectors as $c) {
            $label  = htmlspecialchars($c['label'] ?? '');
            $value  = htmlspecialchars($c['value'] ?? '');
            $detail = htmlspecialchars($c['detail'] ?? '');
            $extra .= <<<HTML
            <div class="tb-panel" title="{$detail}">
                <span class="tb-label">{$label}</span>
                <span class="tb-value">{$value}</span>
            </div>
            HTML;
        }

        // Query panel HTML
        $queryPanelHtml = static::renderQueryPanel($queries, $queryTime);

        $css = static::css();
        $js  = static::js();

        return <<<HTML
        <div id="lovante-toolbar">
            <style>{$css}</style>

            <!-- ── COLLAPSED BAR ─────────────────────────── -->
            <div id="tb-bar" onclick="tbToggle()">

                <div class="tb-panel tb-brand">
                    ⚡ <strong>Lovante</strong>
                </div>

                <div class="tb-sep"></div>

                <div class="tb-panel {$timeClass}" title="Execution time">
                    ⏱ <span class="tb-value">{$elapsed} ms</span>
                </div>

                <div class="tb-panel {$memClass}" title="Peak memory">
                    💾 <span class="tb-value">{$memory}</span>
                </div>

                <div class="tb-panel" title="Route">
                    🛣 <span class="tb-badge tb-blue-bg">{$method}</span>
                    <span class="tb-value tb-uri">{$uri}</span>
                </div>

                <div class="tb-panel {$statusClass}" title="HTTP Status">
                    📤 <span class="tb-value">{$status}</span>
                </div>

                <div class="tb-panel {$dbClass}" title="Database Queries">
                    🗄 <span class="tb-value">{$queryCount} queries</span>
                    <span class="tb-dim"> ({$queryTime} ms)</span>
                </div>

                <div class="tb-panel" title="PHP version">
                    🐘 <span class="tb-value">{$php}</span>
                </div>

                {$extra}

                <div class="tb-toggle-btn" title="Toggle profiler panel">▲</div>
            </div>

            <!-- ── EXPANDED PROFILER PANEL ───────────────── -->
            <div id="tb-profiler" style="display:none">

                <div class="tbp-grid">

                    <div class="tbp-card">
                        <div class="tbp-card-title">⏱ Performance</div>
                        <div class="tbp-row">
                            <span class="tbp-label">Execution Time</span>
                            <span class="tbp-val {$timeClass}">{$elapsed} ms</span>
                        </div>
                        <div class="tbp-row">
                            <span class="tbp-label">Peak Memory</span>
                            <span class="tbp-val {$memClass}">{$memory}</span>
                        </div>
                        <div class="tbp-row">
                            <span class="tbp-label">Memory Used (Δ)</span>
                            <span class="tbp-val">{$memoryUsed}</span>
                        </div>
                        <div class="tbp-perf-bar">
                            <div class="tbp-perf-fill" style="width:<?= min(100, $elapsed / 10) ?>%"></div>
                        </div>
                    </div>

                    <div class="tbp-card">
                        <div class="tbp-card-title">🛣 Request</div>
                        <div class="tbp-row">
                            <span class="tbp-label">Method</span>
                            <span class="tbp-val">
                                <span class="tb-badge tb-blue-bg">{$method}</span>
                            </span>
                        </div>
                        <div class="tbp-row">
                            <span class="tbp-label">URI</span>
                            <span class="tbp-val tb-uri">{$uri}</span>
                        </div>
                        <div class="tbp-row">
                            <span class="tbp-label">Status</span>
                            <span class="tbp-val {$statusClass}">{$status}</span>
                        </div>
                    </div>

                    <div class="tbp-card">
                        <div class="tbp-card-title">🐘 Environment</div>
                        <div class="tbp-row">
                            <span class="tbp-label">PHP</span>
                            <span class="tbp-val">{$php}</span>
                        </div>
                        <div class="tbp-row">
                            <span class="tbp-label">Lovante</span>
                            <span class="tbp-val">v1.0.0</span>
                        </div>
                        <div class="tbp-row">
                            <span class="tbp-label">OS</span>
                            <span class="tbp-val"><?= PHP_OS ?></span>
                        </div>
                    </div>

                    {$queryPanelHtml}

                </div>
            </div>

            <script>{$js}</script>
        </div>
        HTML;
    }

    /**
     * Format bytes into human-readable KB or MB
     */
    protected static function formatMemory(int|float $bytes): string
    {
        if ($bytes < 0) return '0 KB';
        
        $kb = $bytes / 1024;
        $mb = $kb / 1024;
        
        // Jika >= 1MB, tampilkan dalam MB
        if ($mb >= 1) {
            return round($mb, 2) . ' MB';
        }
        
        // Jika < 1MB, tampilkan dalam KB
        return round($kb, 2) . ' KB';
    }

    /**
     * Render database queries panel
     */
    protected static function renderQueryPanel(array $queries, float $totalTime): string
    {
        $count = count($queries);
        
        if (empty($queries)) {
            return <<<HTML
            <div class="tbp-card tbp-wide">
                <div class="tbp-card-title">🗄 Database ({$count})</div>
                <div class="tbp-empty">No queries executed</div>
            </div>
            HTML;
        }

        $rows = '';
        foreach ($queries as $i => $q) {
            $num      = $i + 1;
            $query    = htmlspecialchars($q['query']);
            $bindings = htmlspecialchars(json_encode($q['bindings']));
            $time     = $q['time'];
            $timeClass = $time > 50 ? 'tb-orange' : ($time > 20 ? 'tb-yellow' : 'tb-green');

            $rows .= <<<HTML
            <div class="tbp-query">
                <div class="tbp-query-header">
                    <span class="tbp-query-num">#{$num}</span>
                    <span class="tbp-query-time {$timeClass}">{$time} ms</span>
                </div>
                <pre class="tbp-query-sql">{$query}</pre>
                <div class="tbp-query-bindings">{$bindings}</div>
            </div>
            HTML;
        }

        return <<<HTML
        <div class="tbp-card tbp-wide">
            <div class="tbp-card-title">
                🗄 Database ({$count} queries, {$totalTime} ms)
            </div>
            <div class="tbp-queries">{$rows}</div>
        </div>
        HTML;
    }

    // =========================================================================
    // CSS
    // =========================================================================
    protected static function css(): string
    {
        return <<<CSS
        #lovante-toolbar *{box-sizing:border-box;margin:0;padding:10}
        #lovante-toolbar{
            position:fixed;bottom:0;left:0;right:0;z-index:99999;
            font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;
            font-size:12px;
        }
        #tb-bar{
            display:flex;align-items:stretch;background:#0f172a;
            border-top:2px solid #3b82f6;cursor:pointer;
            box-shadow:0 -4px 20px rgba(0,0,0,.5);
            user-select:none;
        }
        .tb-panel{
            display:flex;align-items:center;gap:5px;
            padding:6px 12px;color:#94a3b8;white-space:nowrap;
            border-right:1px solid #1e293b;transition:background .12s;
        }
        .tb-panel:hover{background:#1e293b}
        .tb-brand{color:#3b82f6;font-size:13px}
        .tb-value{color:#e2e8f0;font-weight:600}
        .tb-uri{max-width:200px;overflow:hidden;text-overflow:ellipsis;color:#94a3b8}
        .tb-sep{flex:1}
        .tb-badge{padding:1px 6px;border-radius:3px;font-size:11px;font-weight:700}
        .tb-blue-bg{background:#172554;color:#60a5fa}
        .tb-toggle-btn{
            margin-left:auto;padding:6px 14px;color:#64748b;
            border-left:1px solid #1e293b;
        }
        /* Status colors */
        .tb-green .tb-value{color:#22c55e}
        .tb-orange .tb-value{color:#f97316}
        .tb-red .tb-value{color:#ef4444}
        .tb-blue .tb-value{color:#3b82f6}
        /* Profiler panel */
        #tb-profiler{
            background:#0f172a;border-top:1px solid #334155;
            max-height:320px;overflow-y:auto;
        }
        .tbp-header{
            display:flex;align-items:center;justify-content:space-between;
            padding:10px 16px;border-bottom:1px solid #334155;
            color:#e2e8f0;font-size:13px;
        }
        .tbp-close{
            background:#1e293b;border:1px solid #334155;color:#94a3b8;
            padding:3px 10px;border-radius:4px;cursor:pointer;font-size:11px;
        }
        .tbp-close:hover{background:#334155;color:#e2e8f0}
        .tbp-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));
                  gap:1px;background:#1e293b}
        .tbp-card{background:#0f172a;padding:14px 16px}
        .tbp-card-title{font-size:11px;font-weight:700;text-transform:uppercase;
                        letter-spacing:1px;color:#64748b;margin-bottom:10px}
        .tbp-row{display:flex;align-items:center;justify-content:space-between;
                 padding:4px 0;border-bottom:1px solid #1e293b}
        .tbp-label{color:#64748b;font-size:11px}
        .tbp-val{color:#e2e8f0;font-size:11px;font-weight:600}
        .tbp-val.tb-green{color:#22c55e}
        .tbp-val.tb-orange{color:#f97316}
        .tbp-val.tb-red{color:#ef4444}
        .tbp-val.tb-yellow{color:#fbbf24}
        .tbp-perf-bar{height:3px;background:#1e293b;border-radius:2px;margin-top:10px}
        .tbp-perf-fill{height:100%;background:#3b82f6;border-radius:2px;transition:width .3s}
        .tb-dim{color:#64748b;font-size:10px}
        /* Wide card (full width) */
        .tbp-wide{grid-column:1/-1}
        .tbp-empty{color:#64748b;font-size:11px;padding:8px 0}
        /* Query panel */
        .tbp-queries{max-height:240px;overflow-y:auto;margin-top:8px}
        .tbp-query{background:#1e293b;border:1px solid #334155;border-radius:4px;
                   padding:8px 10px;margin-bottom:6px;font-size:11px}
        .tbp-query-header{display:flex;justify-content:space-between;margin-bottom:6px}
        .tbp-query-num{color:#64748b;font-weight:600}
        .tbp-query-time{font-weight:700;font-size:10px}
        .tbp-query-time.tb-green{color:#22c55e}
        .tbp-query-time.tb-yellow{color:#fbbf24}
        .tbp-query-time.tb-orange{color:#f97316}
        .tbp-query-sql{color:#e2e8f0;font-family:'Fira Code',Consolas,monospace;
                       font-size:11px;white-space:pre-wrap;word-break:break-all;
                       line-height:1.5;margin-bottom:6px}
        .tbp-query-bindings{color:#64748b;font-size:10px}
        CSS;
    }

    // =========================================================================
    // JS
    // =========================================================================
    protected static function js(): string
    {
        return <<<JS
        function tbToggle() {
            const p  = document.getElementById('tb-profiler');
            const btn = document.querySelector('.tb-toggle-btn');
            if (p.style.display === 'none') {
                p.style.display = 'block';
                btn.textContent = '▼';
            } else {
                p.style.display = 'none';
                btn.textContent = '▲';
            }
        }
        JS;
    }
}