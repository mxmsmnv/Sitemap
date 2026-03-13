<?php

/**
 * Sitemap - XML Sitemap generator for ProcessWire
 *
 * Settings stored in a dedicated DB table (sitemap_settings) — no PW config
 * size limits. Generates standard-compliant XML sitemaps with sitemap index
 * support for large sites.
 *
 * @copyright 2025 Maxim Alex (smnv.org)
 * @license MIT
 */

class Sitemap extends WireData implements Module, ConfigurableModule {

    public static function getModuleInfo(): array {
        return [
            'title'    => 'Sitemap',
            'summary'  => 'XML Sitemap generator with sitemap index, per-template settings, and cron-based auto-regeneration.',
            'author'   => 'Maxim Alex',
            'version'  => '1.0.0',
            'autoload' => true,
            'singular' => true,
            'icon'     => 'sitemap',
            'requires' => ['ProcessWire>=3.0.200', 'PHP>=8.1'],
        ];
    }

    const MAX_URLS_PER_FILE = 50000;
    const SITEMAP_NS        = 'http://www.sitemaps.org/schemas/sitemap/0.9';
    const DEFAULT_DIR       = 'sitemaps';
    const LOCK_FILE         = 'sitemap.lock';
    const SETTINGS_TABLE    = 'sitemap_settings';

    public static function getDefaultSettings(): array {
        return [
            'sitemap_dir'          => self::DEFAULT_DIR,
            'chunk_size'           => 1000,
            'lastmod_format'       => 'Y-m-d',
            'include_templates'    => '',
            'exclude_templates'    => '',
            'template_settings'    => '{}',
            'include_hidden'       => 0,
            'include_unpublished'  => 0,
            'respect_noindex'      => 1,
            'default_priority'     => '0.5',
            'default_changefreq'   => 'weekly',
            'homepage_priority'    => '1.0',
            'homepage_changefreq'  => 'daily',
            'include_images'       => 0,
            'multilang_hreflang'   => 0,
            'auto_regenerate'      => 1,
            'regenerate_interval'  => 86400,
            'indexnow_enabled'     => 0,
            'indexnow_key'         => '',
            'custom_urls'          => '[]',
            'exclude_url_patterns' => '',
            'robots_txt_reference' => 1,
        ];
    }

    // -------------------------------------------------------------------------
    // DB Settings API
    // -------------------------------------------------------------------------

    public function loadSettings(): array {
        $defaults = self::getDefaultSettings();
        try {
            $rows = $this->database->query(
                'SELECT `name`, `value` FROM `' . self::SETTINGS_TABLE . '`'
            )->fetchAll(\PDO::FETCH_KEY_PAIR);
            return array_merge($defaults, $rows);
        } catch (\Exception $e) {
            return $defaults;
        }
    }

    public function saveSetting(string $name, $value): void {
        $stmt = $this->database->prepare(
            'INSERT INTO `' . self::SETTINGS_TABLE . '` (`name`, `value`) VALUES (:n, :v)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)'
        );
        $stmt->execute([':n' => $name, ':v' => (string)$value]);
    }

    public function saveSettings(array $data): void {
        foreach ($data as $k => $v) $this->saveSetting($k, $v);
    }

    public function setting(string $name) {
        return $this->loadSettings()[$name] ?? (self::getDefaultSettings()[$name] ?? null);
    }

    // -------------------------------------------------------------------------
    // Install / Uninstall
    // -------------------------------------------------------------------------

    public function ___install(): void {
        $this->database->query(
            'CREATE TABLE IF NOT EXISTS `' . self::SETTINGS_TABLE . '` (
                `name`  VARCHAR(128) NOT NULL,
                `value` MEDIUMTEXT   NOT NULL DEFAULT \'\',
                PRIMARY KEY (`name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        foreach (self::getDefaultSettings() as $k => $v) $this->saveSetting($k, $v);
        try { $this->ensureSitemapDir(); } catch (\Exception $e) {}
    }

    public function ___uninstall(): void {
        $this->database->query('DROP TABLE IF EXISTS `' . self::SETTINGS_TABLE . '`');
    }

    // -------------------------------------------------------------------------
    // Init
    // -------------------------------------------------------------------------

    public function init(): void {
        $this->addHookBefore('ProcessPageView::execute', $this, 'hookServeSitemap');
        if ($this->setting('auto_regenerate')) {
            if (!$this->modules->isInstalled('LazyCron')) {
                // Log once per day max to avoid spam
                $lastWarn = $this->cache->get('Sitemap_lazycron_warn');
                if (!$lastWarn || (time() - $lastWarn) > 86400) {
                    $this->log->save('sitemap', 'WARNING: LazyCron module is not installed — auto-regenerate will not work. Install it: Admin > Modules > Core > LazyCron.');
                    $this->cache->save('Sitemap_lazycron_warn', time(), WireCache::expireNever);
                }
            } else {
                $interval = (int)($this->setting('regenerate_interval') ?: 86400);
                if ($interval <= 60)         $cronMethod = 'everyMinute';
                elseif ($interval <= 3600)   $cronMethod = 'everyHour';
                elseif ($interval <= 21600)  $cronMethod = 'every6Hours';
                elseif ($interval <= 43200)  $cronMethod = 'every12Hours';
                elseif ($interval <= 86400)  $cronMethod = 'everyDay';
                elseif ($interval <= 604800) $cronMethod = 'everyWeek';
                else                         $cronMethod = 'every4Weeks';
                $this->_cronMethod = $cronMethod;
                $this->addHook('LazyCron::' . $cronMethod, $this, 'hookCronRegenerate');
            }
        }
        $this->addHookAfter('Pages::saved',   $this, 'hookPageChanged');
        $this->addHookAfter('Pages::trashed', $this, 'hookPageChanged');
        $this->addHookAfter('Pages::deleted', $this, 'hookPageChanged');
    }

    /**
     * Write or remove the Sitemap: directive in the physical robots.txt file.
     * Called after generate() and after settings save.
     * Physical file approach — works regardless of web server config.
     */
    public function updateRobotsTxt(): bool {
        $robotsFile  = rtrim($this->config->paths->root, '/') . '/robots.txt';
        $sitemapDir  = trim($this->setting('sitemap_dir') ?: self::DEFAULT_DIR, '/');
        $sitemapUrl  = 'https://' . $this->config->httpHost . '/' . $sitemapDir . '/sitemap.xml';
        $enabled     = (bool)$this->setting('robots_txt_reference');

        $existing = file_exists($robotsFile) ? file_get_contents($robotsFile) : "User-agent: *\nAllow: /\n";

        // Normalize line endings, split into lines
        $lines = explode("\n", str_replace("\r\n", "\n", $existing));

        // Remove ALL existing Sitemap: lines (any URL) to avoid duplicates on repeated saves
        $lines = array_values(array_filter($lines, function($l) {
            return stripos(trim($l), 'Sitemap:') !== 0;
        }));

        // Strip trailing blank lines
        while (count($lines) && trim(end($lines)) === '') array_pop($lines);

        if ($enabled) $lines[] = 'Sitemap: ' . $sitemapUrl;

        $content = implode("\n", $lines) . "\n";
        $written = file_put_contents($robotsFile, $content);

        $this->log->save('sitemap', sprintf(
            'updateRobotsTxt: enabled=%s path=%s writable=%s result=%s content=%s',
            $enabled ? 'yes' : 'no',
            $robotsFile,
            is_writable(dirname($robotsFile)) ? 'yes' : 'no',
            $written !== false ? $written . 'b' : 'FAILED',
            json_encode($content)
        ));

        return $written !== false;
    }

    // -------------------------------------------------------------------------
    // Hooks
    // -------------------------------------------------------------------------

    public function hookServeSitemap(HookEvent $event): void {
        $url        = $this->input->url();
        $sitemapDir = $this->setting('sitemap_dir') ?: self::DEFAULT_DIR;

        $matched = false;
        foreach ([
            '|^/' . preg_quote($sitemapDir, '|') . '/sitemap[^/]*\.xml$|',
            '|^/sitemap\.xml$|',
            '|^/sitemap-index\.xml$|',
        ] as $p) {
            if (preg_match($p, $url)) { $matched = true; break; }
        }
        if (!$matched) return;

        $filename = basename($url);
        $file     = $this->getSitemapFilePath($filename);

        if (!$file || !file_exists($file)) {
            $this->generate();
            $file = $this->getSitemapFilePath($filename);
        }

        if ($file && file_exists($file)) {
            header('Content-Type: application/xml; charset=utf-8');
            header('X-Robots-Tag: noindex');
            header('Last-Modified: ' . gmdate('D, d M Y H:i:s', filemtime($file)) . ' GMT');
            readfile($file);
            exit;
        }

        $event->return = $this->pages->get(404)->render();
    }

    public function hookCronRegenerate(HookEvent $event): void {
        $lastGen  = $this->cache->get('Sitemap_last_generated');
        $interval = (int)($this->setting('regenerate_interval') ?: 86400);
        $elapsed  = $lastGen ? (time() - $lastGen) : null;
        $this->log->save('sitemap', sprintf(
            'LazyCron::%s fired. last=%s interval=%ds elapsed=%s',
            $this->_cronMethod ?? 'everyMinute',
            $lastGen ? date('Y-m-d H:i:s', $lastGen) : 'never',
            $interval,
            $elapsed !== null ? $elapsed . 's' : 'n/a'
        ));
        if (!$lastGen || $elapsed >= $interval) {
            $this->log->save('sitemap', 'LazyCron: starting generation');
            $this->generate();
        } else {
            $this->log->save('sitemap', sprintf('LazyCron: skipping, next in %ds', $interval - $elapsed));
        }
    }

    public function hookPageChanged(HookEvent $event): void {
        $this->cache->save('Sitemap_needs_regen', true, WireCache::expireNever);
    }

    // -------------------------------------------------------------------------
    // Generate
    // -------------------------------------------------------------------------

    public function generate(bool $force = false): array {
        $t = microtime(true);
        if (!$force && $this->isLocked()) return ['error' => 'Generation already in progress.'];
        $this->lock();
        $result = ['files' => 0, 'urls' => 0, 'time' => 0.0];
        try {
            $dir   = $this->ensureSitemapDir();
            $urls  = $this->collectUrls();
            // Log template distribution
            $tplCounts = [];
            foreach ($urls as $u) { $t2 = $u['template'] ?? 'unknown'; $tplCounts[$t2] = ($tplCounts[$t2] ?? 0) + 1; }
            $this->log->save('sitemap', 'collectUrls: ' . count($urls) . ' URLs. Templates: ' . json_encode($tplCounts));
            $files = $this->writeFiles($dir, $urls);
            $this->log->save('sitemap', 'writeFiles: ' . count($files) . ' files written: ' . implode(', ', $files));
            if ($this->setting('indexnow_enabled') && $this->setting('indexnow_key')) $this->submitIndexNow($files);
            $this->cache->save('Sitemap_last_generated', time(), WireCache::expireNever);
            $this->cache->save('Sitemap_needs_regen', false, WireCache::expireNever);
            if ($this->setting('robots_txt_reference')) $this->updateRobotsTxt();
            $elapsed = round(microtime(true) - $t, 3);
            $result = ['files' => count($files), 'urls' => count($urls), 'time' => $elapsed];
            $this->log->save('sitemap', "generate() done: {$result['files']} files, {$result['urls']} URLs in {$elapsed}s");
        } catch (\Exception $e) {
            $this->log->save('sitemap', 'generate() exception: ' . $e->getMessage());
            throw $e;
        } finally {
            $this->unlock();
        }
        return $result;
    }

    // -------------------------------------------------------------------------
    // URL Collection
    // -------------------------------------------------------------------------

    protected function collectUrls(): array {
        $urls = [];
        $s    = $this->loadSettings();

        $includeTemplates = array_filter(array_map('trim', explode(',', $s['include_templates'])));
        $excludeTemplates = array_filter(array_map('trim', explode(',', $s['exclude_templates'])));
        $tplSettingsRaw = json_decode($s['template_settings'], true);
        $templateSettings = (is_array($tplSettingsRaw) && !array_is_list($tplSettingsRaw)) ? $tplSettingsRaw : [];

        $this->log->save('sitemap', 'collectUrls: template_settings=' . json_encode($templateSettings)
            . ' include=' . json_encode($includeTemplates));

        $selector = 'id>0, check_access=0';
        if (!$s['include_hidden'])      $selector .= ', status!=hidden';
        if (!$s['include_unpublished']) $selector .= ', status<unpublished';
        if (!empty($includeTemplates)) $selector .= ', template=' . implode('|', $includeTemplates);

        $start    = 0;
        $pageSize = 500;
        $pages    = $this->pages;

        while (true) {
            $chunk = $pages->find("$selector, start=$start, limit=$pageSize");
            if (!$chunk->count()) break;

            foreach ($chunk as $page) {
                if (!empty($excludeTemplates) && in_array($page->template->name, $excludeTemplates)) continue;
                if ($page->template->flags & Template::flagSystem) continue;
                if ($page->rootParent->id === $this->config->adminRootPageID) continue;
                if ($s['respect_noindex'] && $this->pageHasNoindex($page)) continue;

                $pageUrl = $page->httpUrl;
                if (!$pageUrl) continue;
                if ($this->matchesExcludePattern($pageUrl, $s['exclude_url_patterns'])) continue;

                $tCfg       = $templateSettings[$page->template->name] ?? [];
                $isHome     = $page->id === $this->config->rootPageID;
                $priority   = $isHome ? $s['homepage_priority']   : ($tCfg['priority']   ?? $s['default_priority']);
                $changefreq = $isHome ? $s['homepage_changefreq'] : ($tCfg['changefreq'] ?? $s['default_changefreq']);

                $entry = [
                    'loc'        => $pageUrl,
                    'lastmod'    => $page->modified ? date($s['lastmod_format'], $page->modified) : null,
                    'changefreq' => $changefreq,
                    'priority'   => number_format((float)$priority, 1),
                    'template'   => $page->template->name,
                ];
                if ($s['include_images'])     $entry['images']   = $this->collectPageImages($page);
                if ($s['multilang_hreflang']) $entry['hreflang'] = $this->collectHreflang($page);
                $urls[] = $entry;
            }

            $cnt = $chunk->count();
            $chunk->resetTrackChanges();
            unset($chunk);
            $pages->uncacheAll();
            if ($cnt < $pageSize) break;
            $start += $pageSize;
        }

        foreach (json_decode($s['custom_urls'], true) ?: [] as $custom) {
            if (empty($custom['loc'])) continue;
            $urls[] = [
                'loc'        => $custom['loc'],
                'lastmod'    => $custom['lastmod'] ?? null,
                'changefreq' => $custom['changefreq'] ?? $s['default_changefreq'],
                'priority'   => number_format((float)($custom['priority'] ?? $s['default_priority']), 1),
                'template'   => 'custom',
            ];
        }

        return $urls;
    }

    protected function pageHasNoindex(Page $page): bool {
        foreach (['seo_noindex','noindex','meta_noindex','robots_noindex'] as $f) {
            if ($page->hasField($f) && $page->get($f)) return true;
        }
        if ($page->hasField('seo') && isset($page->seo->noindex)) return (bool)$page->seo->noindex;
        return false;
    }

    protected function matchesExcludePattern(string $url, string $patterns): bool {
        foreach (array_filter(array_map('trim', explode("\n", $patterns))) as $p) {
            // Regex if surrounded by delimiters like ~...~ or #...#, else substring match
            if (strlen($p) > 2 && $p[0] !== '/' && @preg_match($p, '') !== false && preg_match($p, $url)) return true;
            elseif (str_contains($url, $p)) return true;
        }
        return false;
    }

    protected function collectPageImages(Page $page): array {
        $images = [];
        foreach ($page->fields as $field) {
            if (!($field->type instanceof FieldtypeImage)) continue;
            $imgs = $page->get($field->name);
            if (!$imgs) continue;
            if ($imgs instanceof Pageimage) $imgs = [$imgs];
            foreach ($imgs as $img) {
                $images[] = ['loc' => $img->httpUrl, 'title' => $img->description ?: $page->title, 'caption' => $img->description];
                if (count($images) >= 1000) break 2;
            }
        }
        return $images;
    }

    protected function collectHreflang(Page $page): array {
        $out = [];
        if (!$this->languages) return $out;
        foreach ($this->languages as $lang) {
            if (!$page->viewable($lang)) continue;
            $url = $page->localHttpUrl($lang);
            if ($url) $out[] = ['hreflang' => $lang->name === 'default' ? 'x-default' : $lang->name, 'href' => $url];
        }
        return $out;
    }

    // -------------------------------------------------------------------------
    // File Writing
    // -------------------------------------------------------------------------

    protected function writeFiles(string $dir, array $urls): array {
        $maxPerFile = max(1, min((int)$this->setting('chunk_size') ?: 1000, self::MAX_URLS_PER_FILE));
        $generated  = [];

        // Group URLs by template name
        $byTemplate = [];
        foreach ($urls as $entry) {
            $tpl = $entry['template'] ?? 'pages';
            $byTemplate[$tpl][] = $entry;
        }

        // For each template group, split into numbered chunks if over maxPerFile.
        // Produces: sitemap-[template].xml or sitemap-[template]-2.xml, etc.
        $allGroups = [];
        foreach ($byTemplate as $tpl => $entries) {
            $safeName = preg_replace('/[^a-z0-9_-]/i', '-', $tpl);
            $chunks   = array_chunk($entries, $maxPerFile);
            if (count($chunks) === 1) {
                $allGroups['sitemap-' . $safeName . '.xml'] = $chunks[0];
            } else {
                foreach ($chunks as $i => $chunk) {
                    $allGroups['sitemap-' . $safeName . '-' . ($i + 1) . '.xml'] = $chunk;
                }
            }
        }

        $totalGroups = count($allGroups);
        if ($totalGroups === 0) {
            $this->writeSitemapFile($dir . '/sitemap.xml', []);
            $generated[] = 'sitemap.xml';
        } else {
            foreach ($allGroups as $name => $entries) {
                $this->writeSitemapFile($dir . '/' . $name, $entries);
                $generated[] = $name;
            }
            // Always write sitemap.xml as index (even for single template — consistent URL)
            $this->writeSitemapIndex($dir . '/sitemap.xml', $generated);
        }

        $this->cleanupOldFiles($dir, $generated);
        return $generated;
    }

    protected function writeSitemapFile(string $filepath, array $urls): void {
        $s   = $this->loadSettings();
        $xml = new XMLWriter();
        $xml->openUri($filepath);
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('urlset');
        $xml->writeAttribute('xmlns', self::SITEMAP_NS);
        if ($s['include_images'])     $xml->writeAttribute('xmlns:image', 'http://www.google.com/schemas/sitemap-image/1.1');
        if ($s['multilang_hreflang']) $xml->writeAttribute('xmlns:xhtml', 'http://www.w3.org/1999/xhtml');

        foreach ($urls as $e) {
            $xml->startElement('url');
            $xml->writeElement('loc', htmlspecialchars($e['loc'], ENT_XML1));
            if (!empty($e['lastmod']))    $xml->writeElement('lastmod',    $e['lastmod']);
            if (!empty($e['changefreq'])) $xml->writeElement('changefreq', $e['changefreq']);
            if (isset($e['priority']))    $xml->writeElement('priority',   $e['priority']);
            foreach ($e['images'] ?? [] as $img) {
                $xml->startElement('image:image');
                $xml->writeElement('image:loc', htmlspecialchars($img['loc'], ENT_XML1));
                if (!empty($img['caption'])) $xml->writeElement('image:caption', $img['caption']);
                if (!empty($img['title']))   $xml->writeElement('image:title',   $img['title']);
                $xml->endElement();
            }
            foreach ($e['hreflang'] ?? [] as $alt) {
                $xml->startElement('xhtml:link');
                $xml->writeAttribute('rel', 'alternate');
                $xml->writeAttribute('hreflang', $alt['hreflang']);
                $xml->writeAttribute('href', htmlspecialchars($alt['href'], ENT_XML1));
                $xml->endElement();
            }
            $xml->endElement();
        }
        $xml->endElement();
        $xml->endDocument();
        $xml->flush();
    }

    protected function writeSitemapIndex(string $filepath, array $files): void {
        $dir     = trim($this->setting('sitemap_dir') ?: self::DEFAULT_DIR, '/');
        $baseUrl = rtrim('https://' . $this->config->httpHost, '/');
        $xml     = new XMLWriter();
        $xml->openUri($filepath);
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('sitemapindex');
        $xml->writeAttribute('xmlns', self::SITEMAP_NS);
        foreach ($files as $f) {
            $xml->startElement('sitemap');
            $xml->writeElement('loc', $baseUrl . '/' . $dir . '/' . $f);
            $xml->writeElement('lastmod', date('Y-m-d'));
            $xml->endElement();
        }
        $xml->endElement();
        $xml->endDocument();
        $xml->flush();
    }

    protected function cleanupOldFiles(string $dir, array $keep): void {
        if (!is_dir($dir)) return;
        foreach (glob($dir . '/sitemap*.xml') ?: [] as $file) {
            $base = basename($file);
            if (!in_array($base, $keep) && $base !== 'sitemap.xml') @unlink($file);
        }
    }

    // -------------------------------------------------------------------------
    // Directory / Lock / Ping helpers
    // -------------------------------------------------------------------------

    public function ensureSitemapDir(): string {
        $dir = rtrim($this->config->paths->root, '/') . '/'
             . trim($this->setting('sitemap_dir') ?: self::DEFAULT_DIR, '/');
        if (!is_dir($dir) && !wireMkdir($dir, true)) {
            throw new WireException("Sitemap: cannot create directory: $dir");
        }
        if (!is_writable($dir)) {
            throw new WireException("Sitemap: directory not writable: $dir");
        }
        if (!file_exists($dir . '/.htaccess')) {
            file_put_contents($dir . '/.htaccess', "Options -Indexes\n");
        }
        return $dir;
    }

    public function getSitemapFilePath(string $filename): string {
        return rtrim($this->config->paths->root, '/') . '/'
             . trim($this->setting('sitemap_dir') ?: self::DEFAULT_DIR, '/') . '/' . $filename;
    }

    protected function getLockPath(): string { return $this->config->paths->cache . self::LOCK_FILE; }
    protected function isLocked(): bool {
        $f = $this->getLockPath();
        if (!file_exists($f)) return false;
        if (time() - filemtime($f) > 600) { @unlink($f); return false; }
        return true;
    }
    protected function lock(): void   { file_put_contents($this->getLockPath(), time()); }
    protected function unlock(): void { @unlink($this->getLockPath()); }

    /**
     * Submit URLs to IndexNow (Bing, Yandex, DuckDuckGo, etc.).
     * Sends the sitemap URL as the key file location + all generated sitemap URLs.
     * @see https://www.indexnow.org/documentation
     */
    protected function submitIndexNow(array $generatedFiles): void {
        $key        = trim($this->setting('indexnow_key'));
        $dir        = trim($this->setting('sitemap_dir') ?: self::DEFAULT_DIR, '/');
        $host       = $this->config->httpHost;
        $baseUrl    = 'https://' . $host;
        $keyFileUrl = $baseUrl . '/' . $key . '.txt';

        // Collect all page URLs from generated sitemap files
        $root    = rtrim($this->config->paths->root, '/');
        $allUrls = [];
        foreach ($generatedFiles as $fname) {
            $path = $root . '/' . $dir . '/' . $fname;
            if (!file_exists($path)) continue;
            $xml = @simplexml_load_file($path);
            if (!$xml) continue;
            // urlset
            foreach ($xml->url ?? [] as $u) {
                $loc = (string)($u->loc ?? '');
                if ($loc) $allUrls[] = $loc;
            }
        }

        if (empty($allUrls)) return;

        // IndexNow batch submission (max 10,000 URLs per request)
        $chunks  = array_chunk($allUrls, 10000);
        $endpoint = 'https://api.indexnow.org/indexnow';

        foreach ($chunks as $chunk) {
            $payload = json_encode([
                'host'        => $host,
                'key'         => $key,
                'keyLocation' => $keyFileUrl,
                'urlList'     => $chunk,
            ]);
            try {
                $h = $this->wire(new WireHttp());
                $h->setTimeout(10);
                $h->setHeader('Content-Type', 'application/json; charset=utf-8');
                $h->post($endpoint, $payload);
                $this->log->save('sitemap', 'IndexNow submitted ' . count($chunk) . ' URLs. Response: ' . $h->getHttpCode());
            } catch (\Exception $e) {
                $this->log->save('sitemap', 'IndexNow failed: ' . $e->getMessage());
            }
        }
    }

    /**
     * Write the IndexNow key verification file to the site root.
     */
    public function writeIndexNowKeyFile(): bool {
        $key  = trim($this->setting('indexnow_key'));
        if (!$key) { $this->log->save('sitemap', 'writeIndexNowKeyFile: no key set'); return false; }
        $path = rtrim($this->config->paths->root, '/') . '/' . $key . '.txt';
        $ok = file_put_contents($path, $key);
        $this->log->save('sitemap', 'writeIndexNowKeyFile: path=' . $path . ' result=' . var_export($ok, true));
        return $ok !== false;
    }

    // -------------------------------------------------------------------------
    // Status
    // -------------------------------------------------------------------------

    public function getStatus(): array {
        $sitemapDir = trim($this->setting('sitemap_dir') ?: self::DEFAULT_DIR, '/');
        $root       = rtrim($this->config->paths->root, '/');
        $dir        = $root . '/' . $sitemapDir;
        $rawFiles   = is_dir($dir) ? (glob($dir . '/sitemap*.xml') ?: []) : [];
        $totalSize  = 0;
        $totalUrls  = 0;
        $fileList   = [];

        foreach ($rawFiles as $path) {
            $size      = filesize($path);
            $urlCount  = 0;
            if ($fh = fopen($path, 'r')) {
                while (($line = fgets($fh)) !== false) $urlCount += substr_count($line, '<url>');
                fclose($fh);
            }
            $totalSize += $size;
            $isIndex = (basename($path) === 'sitemap.xml' && $urlCount === 0);
            $fileList[] = [
                'name'     => basename($path),
                'size'     => $size,
                'modified' => filemtime($path),
                'urls'     => $urlCount,
                'is_index' => $isIndex,
            ];
            if (!$isIndex) $totalUrls += $urlCount;
        }

        usort($fileList, fn($a, $b) => strcmp($a['name'], $b['name']));

        return [
            'last_generated' => $this->cache->get('Sitemap_last_generated'),
            'needs_regen'    => $this->cache->get('Sitemap_needs_regen'),
            'is_locked'      => $this->isLocked(),
            'files'          => $fileList,
            'file_count'     => count($fileList),
            'total_size'     => $totalSize,
            'total_urls'     => $totalUrls,
            'dir'            => $dir,
            'dir_exists'     => is_dir($dir),
            'dir_writable'   => is_dir($dir) && is_writable($dir),
            'sitemap_dir'    => $sitemapDir,
        ];
    }

    // -------------------------------------------------------------------------
    // Module Config — all real settings live in DB / Process admin
    // -------------------------------------------------------------------------

    public static function getModuleConfigInputfields(array $data) {
        $wrapper = new InputfieldWrapper();
        $f = wire('modules')->get('InputfieldMarkup');
        $f->label = 'Settings';
        $f->value = '<p>All Sitemap settings are managed in the '
            . '<a href="' . wire('config')->urls->admin . 'setup/sitemap/">Sitemap Admin</a> panel '
            . '(Setup &rsaquo; Sitemap).</p>';
        $wrapper->add($f);
        return $wrapper;
    }

}
