<?php

$root = dirname(__DIR__);
$module = (string)file_get_contents($root . '/Sitemap.module.php');
$process = (string)file_get_contents($root . '/ProcessSitemap.module.php');
$runner = (string)file_get_contents($root . '/bin/sitemap');
$hookStart = strpos($module, 'public function hookCronRegenerate');
$hookEnd = strpos($module, 'public function hookPageChanged', $hookStart ?: 0);
$hook = ($hookStart !== false && $hookEnd !== false)
    ? substr($module, $hookStart, $hookEnd - $hookStart)
    : '';

$checks = [
    'release version' => str_contains($module, "'version'  => '1.2.2'")
        && str_contains($process, "'version'  => '1.2.2'"),
    'web hook queues CLI' => str_contains($hook, 'queueCliGeneration()'),
    'web hook never generates inline' => !str_contains($hook, '$this->generate('),
    'queue is bounded' => str_contains($module, "Sitemap_cli_queued")
        && str_contains($module, '< 900'),
    'runner is CLI-only' => str_contains($runner, "PHP_SAPI !== 'cli'"),
    'runner requires an explicit valid root' => str_contains($runner, "--root=")
        && str_contains($runner, "'index.php'"),
    'runner respects generation lock' => str_contains($runner, 'generate(false)'),
];

foreach ($checks as $label => $passed) {
    if (!$passed) throw new RuntimeException('Sitemap CLI regeneration check failed: ' . $label);
}

echo "Sitemap CLI regeneration contract passed.\n";
