#!/usr/bin/env php
<?php

$root = dirname(__DIR__);
$process = (string)file_get_contents($root . '/ProcessSitemap.module.php');
$styles = (string)file_get_contents($root . '/assets/process-sitemap.css');

$checks = [
    'admin stylesheet is cache-busted' => str_contains($process, 'process-sitemap.css?v=') && str_contains($process, 'filemtime($path)'),
    'dashboard and settings share a responsive scope' => substr_count($process, 'sitemap-admin') >= 2,
    'generated-files table has a local scroller' => str_contains($process, 'sitemap-table-wrap') && str_contains($styles, '.sitemap-admin .sitemap-table-wrap'),
    'status URL wraps on phones' => str_contains($styles, '.sitemap-admin .sitemap-status-row a') && str_contains($styles, 'overflow-wrap: anywhere;'),
    'settings tabs scroll locally' => str_contains($styles, '.sitemap-admin .uk-tab') && str_contains($styles, 'overflow-x: auto;'),
];

foreach ($checks as $label => $passed) {
    if (!$passed) throw new RuntimeException('Sitemap admin responsive check failed: ' . $label);
}

echo 'Sitemap admin responsive contract: ' . count($checks) . " checks passed.\n";
