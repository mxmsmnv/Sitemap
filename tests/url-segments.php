<?php

class WireData {
    public object $sanitizer;

    public function __construct() {
        $this->sanitizer = new class {
            public function name(string $value): string {
                return preg_replace('/[^a-zA-Z0-9_-]/', '', $value);
            }
        };
    }
}

interface Module {}
interface ConfigurableModule {}
class Page {}

require dirname(__DIR__) . '/Sitemap.module.php';

class TestSitemap extends Sitemap {
    public function segment(string $base, array $entry, mixed $segment): ?array {
        return $this->buildUrlSegmentEntry($base, $entry, $segment);
    }
}

$source = (string)file_get_contents(dirname(__DIR__) . '/Sitemap.module.php');
$entry = [
    'lastmod' => '2026-08-25',
    'changefreq' => 'weekly',
    'priority' => '0.5',
    'template' => 'blog-authors',
];
$sitemap = new TestSitemap();
$author = $sitemap->segment('https://example.com/blog/authors/', $entry, [
    'segment' => 'joe-bloggs/',
    'lastmod' => '2026-08-24T12:00:00-04:00',
]);
$pageTwo = $sitemap->segment('https://example.com/blog/', $entry, 'page2/');

$checks = [
    str_contains($source, '$page->template->urlSegments || $page->template->allowPageNum'),
    str_contains($source, '___collectUrlSegments(Page $page)'),
    ($author['loc'] ?? null) === 'https://example.com/blog/authors/joe-bloggs/',
    ($author['lastmod'] ?? null) === '2026-08-24T12:00:00-04:00',
    ($pageTwo['loc'] ?? null) === 'https://example.com/blog/page2/',
];

if (in_array(false, $checks, true)) {
    fwrite(STDERR, "Sitemap dynamic route contract failed.\n");
    exit(1);
}

echo "Sitemap dynamic route contract passed.\n";
