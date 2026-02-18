<?php

if (!function_exists('dump')) {
    function dump(mixed ...$vars): void {
        \Lovante\Debug\Dumper::dump(...$vars);
    }
}

if (!function_exists('dd')) {
    function dd(mixed ...$vars): never {
        \Lovante\Debug\Dumper::dd(...$vars);
    }
}