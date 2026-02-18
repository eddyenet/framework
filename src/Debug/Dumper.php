<?php

declare(strict_types=1);

namespace Lovante\Debug;

/**
 * Lovante Dumper
 *
 * Beautiful, interactive variable inspector.
 * Drop-in replacement for var_dump() with HTML output.
 *
 * Usage:
 *   Dumper::dump($variable);           // dump and continue
 *   Dumper::dd($var1, $var2);          // dump and die
 *   dump($var);                        // global helper
 *   dd($var);                          // global helper
 */
class Dumper
{
    protected static int $depth       = 0;
    protected static int $maxDepth    = 6;
    protected static int $dumpCount   = 0;

    // =========================================================================
    // Public API
    // =========================================================================

    /**
     * Dump one or more variables and continue execution.
     */
    public static function dump(mixed ...$vars): void
    {
        foreach ($vars as $var) {
            static::$dumpCount++;
            $id      = 'zd-' . static::$dumpCount;
            $origin  = static::getCallOrigin();
            $html    = static::renderContainer($var, $id, $origin);
            echo $html;
        }
    }

    /**
     * Dump and Die — dump variables then halt execution.
     */
    public static function dd(mixed ...$vars): never
    {
        static::dump(...$vars);
        exit(1);
    }

    /**
     * Return the dump HTML instead of echoing it.
     */
    public static function capture(mixed $var): string
    {
        static::$dumpCount++;
        $id     = 'zd-' . static::$dumpCount;
        $origin = static::getCallOrigin();
        return static::renderContainer($var, $id, $origin);
    }

    // =========================================================================
    // Container
    // =========================================================================

    protected static function renderContainer(mixed $var, string $id, array $origin): string
    {
        static::$depth = 0;
        $type    = static::getType($var);
        $inner   = static::renderValue($var);
        $file    = htmlspecialchars(ExceptionHandler::shortPath($origin['file'] ?? ''));
        $line    = $origin['line'] ?? '?';
        $css     = static::css();
        $js      = static::injectJs();

        return <<<HTML
        <div class="zd-wrap" id="{$id}">
            <style>{$css}</style>
            {$js}
            <div class="zd-header">
                <span class="zd-brand">⚡ dump</span>
                <span class="zd-type">{$type}</span>
                <span class="zd-origin">{$file}:{$line}</span>
            </div>
            <div class="zd-body">{$inner}</div>
        </div>
        HTML;
    }

    // =========================================================================
    // Value Rendering (recursive)
    // =========================================================================

    protected static function renderValue(mixed $var): string
    {
        return match(true) {
            is_null($var)    => '<span class="zd-null">null</span>',
            is_bool($var)    => '<span class="zd-bool">' . ($var ? 'true' : 'false') . '</span>',
            is_int($var)     => '<span class="zd-int">' . $var . '</span><span class="zd-dim"> int</span>',
            is_float($var)   => '<span class="zd-float">' . $var . '</span><span class="zd-dim"> float</span>',
            is_string($var)  => static::renderString($var),
            is_array($var)   => static::renderArray($var),
            is_object($var)  => static::renderObject($var),
            is_resource($var)=> '<span class="zd-resource">resource(' . get_resource_type($var) . ')</span>',
            default          => '<span class="zd-unknown">' . gettype($var) . '</span>',
        };
    }

    protected static function renderString(string $val): string
    {
        $len     = strlen($val);
        $escaped = htmlspecialchars($val, ENT_QUOTES);

        // Truncate very long strings
        if ($len > 500) {
            $escaped  = htmlspecialchars(substr($val, 0, 500), ENT_QUOTES);
            $truncated = '<span class="zd-dim"> …(' . ($len - 500) . ' more)</span>';
        } else {
            $truncated = '';
        }

        return '<span class="zd-string">"' . $escaped . '"</span>'
             . '<span class="zd-dim"> (' . $len . ')</span>'
             . $truncated;
    }

    protected static function renderArray(array $arr): string
    {
        $count = count($arr);

        if ($count === 0) {
            return '<span class="zd-keyword">array</span>'
                 . '<span class="zd-dim">(0) []</span>';
        }

        if (static::$depth >= static::$maxDepth) {
            return '<span class="zd-keyword">array</span>'
                 . '<span class="zd-dim">(' . $count . ') […]</span>';
        }

        static::$depth++;
        $id   = 'za-' . uniqid();
        $rows = '';

        foreach ($arr as $key => $value) {
            $k = is_string($key)
                ? '<span class="zd-key-str">"' . htmlspecialchars($key) . '"</span>'
                : '<span class="zd-key-int">' . $key . '</span>';

            $rows .= '<div class="zd-row">'
                   . '<span class="zd-arrow">▶</span>'
                   . $k
                   . '<span class="zd-sep"> => </span>'
                   . static::renderValue($value)
                   . '</div>';
        }

        static::$depth--;

        return <<<HTML
        <span class="zd-keyword zd-toggle" onclick="zdToggle('{$id}')">array</span>
        <span class="zd-dim">({$count})</span>
        <span class="zd-brace">[</span>
        <div class="zd-block" id="{$id}">{$rows}</div>
        <span class="zd-brace">]</span>
        HTML;
    }

    protected static function renderObject(object $obj): string
    {
        $class = get_class($obj);
        $short = static::shortClass($class);

        if (static::$depth >= static::$maxDepth) {
            return '<span class="zd-class">' . $short . '</span>'
                 . '<span class="zd-dim"> {…}</span>';
        }

        static::$depth++;
        $id   = 'zo-' . uniqid();
        $rows = '';

        // Public properties
        $ref   = new \ReflectionClass($obj);
        $props = $ref->getProperties();

        foreach ($props as $prop) {
            $prop->setAccessible(true);
            $visibility = match(true) {
                $prop->isPublic()    => '<span class="zd-pub">+</span>',
                $prop->isProtected() => '<span class="zd-pro">#</span>',
                $prop->isPrivate()   => '<span class="zd-priv">-</span>',
                default              => '',
            };

            try {
                $value = $prop->isInitialized($obj) ? $prop->getValue($obj) : '(uninitialized)';
                $rendered = is_string($value) && $value === '(uninitialized)'
                    ? '<span class="zd-dim">(uninitialized)</span>'
                    : static::renderValue($value);
            } catch (\Throwable) {
                $rendered = '<span class="zd-dim">(inaccessible)</span>';
            }

            $rows .= '<div class="zd-row">'
                   . $visibility
                   . '<span class="zd-prop">' . htmlspecialchars($prop->getName()) . '</span>'
                   . '<span class="zd-sep">: </span>'
                   . $rendered
                   . '</div>';
        }

        static::$depth--;

        if (!$rows) {
            $rows = '<span class="zd-dim">  (no properties)</span>';
        }

        return <<<HTML
        <span class="zd-class zd-toggle" onclick="zdToggle('{$id}')">{$short}</span>
        <span class="zd-dim">#{$class}</span>
        <span class="zd-brace">{</span>
        <div class="zd-block" id="{$id}">{$rows}</div>
        <span class="zd-brace">}</span>
        HTML;
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    protected static function getType(mixed $var): string
    {
        if (is_object($var)) return get_class($var);
        if (is_array($var))  return 'array(' . count($var) . ')';
        return gettype($var);
    }

    protected static function shortClass(string $class): string
    {
        $parts = explode('\\', $class);
        return end($parts);
    }

    protected static function getCallOrigin(): array
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 6);

        foreach ($trace as $frame) {
            $file = $frame['file'] ?? '';
            // Skip Dumper internals
            if (!str_contains($file, 'Dumper.php')) {
                return $frame;
            }
        }

        return $trace[0] ?? [];
    }

    // =========================================================================
    // CSS
    // =========================================================================

    protected static function css(): string
    {
        return <<<CSS
        .zd-wrap{font-family:'Fira Code',Consolas,monospace;font-size:13px;
                 background:#1e293b;border:1px solid #334155;border-radius:8px;
                 margin:12px 0;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,.4)}
        .zd-header{display:flex;align-items:center;gap:10px;padding:7px 14px;
                   background:#0f172a;border-bottom:1px solid #334155}
        .zd-brand{color:#3b82f6;font-weight:700;font-size:12px}
        .zd-type{color:#94a3b8;font-size:11px;background:#334155;
                 padding:2px 7px;border-radius:4px}
        .zd-origin{color:#64748b;font-size:11px;margin-left:auto}
        .zd-body{padding:12px 16px;color:#e2e8f0;line-height:1.8}
        .zd-null{color:#94a3b8;font-style:italic}
        .zd-bool{color:#f97316;font-weight:600}
        .zd-int{color:#60a5fa}
        .zd-float{color:#34d399}
        .zd-string{color:#a3e635}
        .zd-resource{color:#c084fc}
        .zd-unknown{color:#f87171}
        .zd-dim{color:#64748b;font-size:11px}
        .zd-keyword{color:#f97316;font-weight:600;cursor:pointer}
        .zd-keyword:hover{text-decoration:underline}
        .zd-class{color:#c084fc;font-weight:600;cursor:pointer}
        .zd-class:hover{text-decoration:underline}
        .zd-key-str{color:#a3e635}
        .zd-key-int{color:#60a5fa}
        .zd-sep{color:#64748b}
        .zd-arrow{color:#64748b;font-size:10px;margin-right:4px}
        .zd-prop{color:#93c5fd}
        .zd-brace{color:#94a3b8}
        .zd-pub{color:#22c55e;margin-right:4px;font-weight:700}
        .zd-pro{color:#f59e0b;margin-right:4px;font-weight:700}
        .zd-priv{color:#ef4444;margin-right:4px;font-weight:700}
        .zd-block{margin-left:20px;border-left:2px solid #334155;padding-left:10px}
        .zd-row{margin:2px 0}
        .zd-toggle{user-select:none}
        CSS;
    }

    // =========================================================================
    // JS (injected once)
    // =========================================================================

    protected static bool $jsInjected = false;

    protected static function injectJs(): string
    {
        if (static::$jsInjected) return '';
        static::$jsInjected = true;

        return <<<JS
        <script>
        function zdToggle(id) {
            const el = document.getElementById(id);
            if (el) el.style.display = el.style.display === 'none' ? '' : 'none';
        }
        </script>
        JS;
    }
}

// =========================================================================
// Global helper functions
// =========================================================================

