<?php

/**
 * Sitemap Admin Process Module
 */

class ProcessSitemap extends Process implements Module {

    public static function getModuleInfo(): array {
        return [
            'title'    => 'Sitemap Admin',
            'summary'  => 'Admin UI for Sitemap — settings, status dashboard, and manual generation.',
            'author'   => 'Maxim Semenov',
            'href'     => 'https://smnv.org',
            'version'  => '1.0.1',
            'autoload' => false,
            'singular' => true,
            'icon'     => 'sitemap',
            'requires' => ['Sitemap'],
            'page'     => [
                'name'   => 'sitemap',
                'title'  => 'Sitemap',
                'parent' => 'setup',
            ],
            'permission'  => 'sitemap-edit',
            'permissions' => [
                'sitemap-edit' => 'Manage Sitemap settings and generation',
            ],
        ];
    }

    public function execute(): string {
        $this->wire('breadcrumbs')->add(new Breadcrumb('../../', 'Setup'));
        $this->headline('Sitemap');
        $sitemap = $this->modules->get('Sitemap');

        $post = $this->input->post;
        if ($post->text('action') === 'generate') {
            $this->session->CSRF->validate();
            try {
                $result = $sitemap->generate(true);
                if (isset($result['error'])) $this->error($result['error']);
                else $this->message(sprintf('Generated: %d file(s), %s URLs in %.2fs.',
                    $result['files'], number_format($result['urls']), $result['time']));
            } catch (\Exception $e) { $this->error('Generation failed: ' . $e->getMessage()); }
            $this->session->redirect('./');
        }
        if ($post->text('action') === 'delete_files') {
            $this->session->CSRF->validate();
            $status = $sitemap->getStatus();
            foreach ($status['files'] as $f) @unlink($status['dir'] . '/' . $f['name']);
            $this->message('All sitemap files deleted.');
            $this->session->redirect('./');
        }
        if ($post->text('action') === 'create_dir') {
            $this->session->CSRF->validate();
            try { $sitemap->ensureSitemapDir(); $this->message('Directory created.'); }
            catch (\Exception $e) { $this->error($e->getMessage()); }
            $this->session->redirect('./');
        }

        return $this->renderDashboard($sitemap);
    }

    public function executeSettings(): string {
        $this->wire('breadcrumbs')->add(new Breadcrumb('../../', 'Setup'));
        $this->wire('breadcrumbs')->add(new Breadcrumb('../', 'Sitemap'));
        $this->headline('Sitemap Settings');
        $sitemap = $this->modules->get('Sitemap');

        if ($this->input->post->text('action') === 'save_settings') {
            $this->session->CSRF->validate();
            $this->savePostedSettings($sitemap);
            $this->message('Settings saved.');
            $this->session->redirect('./');
        }

        return $this->renderSettingsForm($sitemap);
    }

    protected function renderDashboard(Sitemap $sitemap): string {
        $status        = $sitemap->getStatus();
        $cfg           = $this->config;
        $baseUrl       = $sitemap->getBaseUrl();
        $sitemapUrl    = $baseUrl . '/' . $status['sitemap_dir'] . '/sitemap.xml';
        $csrf          = [
            'name'  => $this->session->CSRF->getTokenName(),
            'value' => $this->session->CSRF->getTokenValue(),
        ];
        $lastGen       = $status['last_generated'];
        $needsRegen    = $status['needs_regen'];
        $isLocked      = $status['is_locked'];
        $dirOk         = $status['dir_exists'] && $status['dir_writable'];
        $lazyCronOk    = $this->modules->isInstalled('LazyCron');
        $autoRegen     = $sitemap->setting('auto_regenerate');

        ob_start(); ?>
<div uk-margin>

<?php if ($autoRegen && !$lazyCronOk): ?>
<div class="uk-alert uk-alert-danger">
    <p><span uk-icon="warning"></span> <strong>LazyCron is not installed.</strong>
    Auto-regenerate will not work. Install it: <a href="<?= $cfg->urls->admin ?>module/edit?name=LazyCron">Admin &rsaquo; Modules &rsaquo; Core &rsaquo; LazyCron</a>.</p>
</div>
<?php endif; ?>

<?php if (!$status['dir_exists']): ?>
<div class="uk-alert uk-alert-warning">
    <p><span uk-icon="warning"></span> Directory <code>/<?= htmlspecialchars($status['sitemap_dir']) ?>/</code> does not exist.</p>
    <form method="post" action="./">
        <input type="hidden" name="<?= $csrf['name'] ?>" value="<?= $csrf['value'] ?>">
        <input type="hidden" name="action" value="create_dir">
        <button type="submit" class="ui-button ui-state-default"><span class="ui-button-text">Create Directory</span></button>
    </form>
</div>
<?php elseif (!$status['dir_writable']): ?>
<div class="uk-alert uk-alert-danger">
    <p><span uk-icon="ban"></span> Directory <code>/<?= htmlspecialchars($status['sitemap_dir']) ?>/</code> is not writable. Fix permissions (755).</p>
</div>
<?php endif; ?>

<div class="uk-card uk-card-default uk-card-body uk-padding-small">
    <div class="uk-flex uk-flex-middle uk-flex-between">
        <div class="uk-flex uk-flex-middle">
            <?php if ($isLocked): ?>
                <span class="uk-label uk-label-warning">Generating…</span>
            <?php elseif (!$lastGen): ?>
                <span class="uk-label">Not generated yet</span>
            <?php elseif ($needsRegen): ?>
                <span class="uk-label uk-label-warning">Needs regeneration</span>
            <?php else: ?>
                <span class="uk-label uk-label-success">Up to date</span>
            <?php endif; ?>
            <?php if ($lastGen): ?>
                <span class="uk-text-muted uk-margin-small-left" style="font-size:13px">
                    Last generated: <?= date('Y-m-d H:i:s', $lastGen) ?>
                </span>
            <?php endif; ?>
        </div>
        <a href="<?= htmlspecialchars($sitemapUrl) ?>" target="_blank" class="uk-text-small">
            <?= htmlspecialchars($sitemapUrl) ?> <span uk-icon="icon:link;ratio:0.75"></span>
        </a>
    </div>
</div>

<div class="uk-grid-small uk-child-width-1-4@m" uk-grid>
    <div>
        <div class="uk-card uk-card-default uk-card-body uk-text-center uk-padding-small">
            <div class="uk-text-large uk-text-bold"><?= $status['file_count'] ?></div>
            <div class="uk-text-muted uk-text-small uk-text-uppercase">Sitemap files</div>
        </div>
    </div>
    <div>
        <div class="uk-card uk-card-default uk-card-body uk-text-center uk-padding-small">
            <div class="uk-text-large uk-text-bold"><?= number_format($status['total_urls']) ?></div>
            <div class="uk-text-muted uk-text-small uk-text-uppercase">Total URLs</div>
        </div>
    </div>
    <div>
        <div class="uk-card uk-card-default uk-card-body uk-text-center uk-padding-small">
            <div class="uk-text-large uk-text-bold"><?= $this->fmtBytes($status['total_size']) ?></div>
            <div class="uk-text-muted uk-text-small uk-text-uppercase">Total size</div>
        </div>
    </div>
    <div>
        <div class="uk-card uk-card-default uk-card-body uk-text-center uk-padding-small">
            <div class="uk-text-large uk-text-bold <?= $dirOk ? 'uk-text-success' : 'uk-text-danger' ?>">
                <span uk-icon="icon:<?= $dirOk ? 'check' : 'ban' ?>;ratio:1.4"></span>
            </div>
            <div class="uk-text-muted uk-text-small uk-text-uppercase">
                Directory <?= $dirOk ? 'writable' : ($status['dir_exists'] ? 'not writable' : 'missing') ?>
            </div>
        </div>
    </div>
</div>

<div uk-margin>
    <form method="post" action="./" style="display:inline-block;margin-right:6px">
        <input type="hidden" name="<?= $csrf['name'] ?>" value="<?= $csrf['value'] ?>">
        <input type="hidden" name="action" value="generate">
        <button type="submit" class="ui-button ui-state-default" <?= $isLocked ? 'disabled' : '' ?>>
            <span class="ui-button-text"><?= $isLocked ? 'Generating…' : 'Generate Now' ?></span>
        </button>
    </form>
    <a href="./settings/" class="ui-button ui-state-default" style="margin-right:6px">
        <span class="ui-button-text">Settings</span>
    </a>
    <?php if ($status['file_count'] > 0): ?>
    <form method="post" action="./" style="display:inline-block"
        onsubmit="return confirm('Delete all sitemap files?')">
        <input type="hidden" name="<?= $csrf['name'] ?>" value="<?= $csrf['value'] ?>">
        <input type="hidden" name="action" value="delete_files">
        <button type="submit" class="ui-button ui-state-default ui-priority-secondary">
            <span class="ui-button-text">Delete Files</span>
        </button>
    </form>
    <?php endif; ?>
</div>

<h3 class="uk-heading-divider uk-text-small uk-text-uppercase uk-margin-remove-bottom" style="padding-bottom:8px">
    Generated Files
</h3>

<?php if (empty($status['files'])): ?>
<div class="uk-placeholder uk-text-center uk-text-muted">
    No sitemap files yet. Click "Generate Now" to create them.
</div>
<?php else: ?>
<table class="uk-table uk-table-striped uk-table-small uk-table-hover">
    <thead>
        <tr>
            <th>File</th>
            <th class="uk-text-right">URLs</th>
            <th class="uk-text-right">Size</th>
            <th>Last modified</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($status['files'] as $f):
        $fileUrl = $baseUrl . '/' . $status['sitemap_dir'] . '/' . $f['name'];
        $isIndex = !empty($f['is_index']);
    ?>
    <tr>
        <td><?= htmlspecialchars($f['name']) ?><?= $isIndex ? ' <span class="uk-label uk-label-muted" style="font-size:10px">index</span>' : '' ?></td>
        <td class="uk-text-right <?= $f['urls'] > 0 ? '' : 'uk-text-muted' ?>">
            <?= $f['urls'] > 0 ? number_format($f['urls']) : ($isIndex ? '—' : '0') ?>
        </td>
        <td class="uk-text-right uk-text-muted"><?= $this->fmtBytes($f['size']) ?></td>
        <td class="uk-text-muted uk-text-small"><?= date('Y-m-d H:i:s', $f['modified']) ?></td>
        <td><a href="<?= htmlspecialchars($fileUrl) ?>" target="_blank" class="uk-text-small">Open ↗</a></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

</div>
<?php
        return ob_get_clean();
    }

    protected function renderSettingsForm(Sitemap $sitemap): string {
        $s    = $sitemap->loadSettings();
        $csrf = [
            'name'  => $this->session->CSRF->getTokenName(),
            'value' => $this->session->CSRF->getTokenValue(),
        ];

        $allTemplates = [];
        foreach ($this->templates as $t) {
            if ($t->flags & Template::flagSystem) continue;
            $allTemplates[$t->name] = $t->label ?: $t->name;
        }

        $includeTpls = array_filter(array_map('trim', explode(',', $s['include_templates'])));
        $excludeTpls = array_filter(array_map('trim', explode(',', $s['exclude_templates'])));
        $tplSettingsRaw = json_decode($s['template_settings'], true);
        $tplSettings = (is_array($tplSettingsRaw) && !array_is_list($tplSettingsRaw)) ? $tplSettingsRaw : [];

        $cfOptions = ['', 'always', 'hourly', 'daily', 'weekly', 'monthly', 'yearly', 'never'];
        $prOptions = ['', '0.0', '0.1', '0.2', '0.3', '0.4', '0.5', '0.6', '0.7', '0.8', '0.9', '1.0'];

        $tplRows = '';
        foreach ($allTemplates as $tname => $tlabel) {
            $tc = $tplSettings[$tname] ?? [];
            // Determine current include/exclude status
            if (in_array($tname, $includeTpls))      $tStatus = 'include';
            elseif (in_array($tname, $excludeTpls))  $tStatus = 'exclude';
            else                                      $tStatus = 'ignore';

            $ri = ($tStatus === 'include') ? ' checked' : '';
            $re = ($tStatus === 'exclude') ? ' checked' : '';
            $rn = ($tStatus === 'ignore')  ? ' checked' : '';

            $cfOpts = '';
            foreach ($cfOptions as $v) {
                $sel = ($tc['changefreq'] ?? '') === $v ? ' selected' : '';
                $cfOpts .= '<option value="' . $v . '"' . $sel . '>' . ($v ?: '— default —') . '</option>';
            }
            $prOpts = '';
            foreach ($prOptions as $v) {
                $sel = ($tc['priority'] ?? '') === $v ? ' selected' : '';
                $prOpts .= '<option value="' . $v . '"' . $sel . '>' . ($v ?: '— default —') . '</option>';
            }

            $tplRows .= '<tr>'
                . '<td>' . htmlspecialchars($tname) . ($tlabel !== $tname ? '<br><small class="uk-text-muted">' . htmlspecialchars($tlabel) . '</small>' : '') . '</td>'
                . '<td style="white-space:nowrap">'
                .   '<label style="margin-right:12px"><input class="uk-radio" type="radio" name="tpl_status[' . $tname . ']" value="include"' . $ri . '> Include</label>'
                .   '<label style="margin-right:12px"><input class="uk-radio" type="radio" name="tpl_status[' . $tname . ']" value="exclude"' . $re . '> Exclude</label>'
                .   '<label><input class="uk-radio" type="radio" name="tpl_status[' . $tname . ']" value="ignore"' . $rn . '> Ignore</label>'
                . '</td>'
                . '<td><select class="uk-select uk-form-small" data-tpl="' . $tname . '" data-key="changefreq" onchange="smTplChange(this)">' . $cfOpts . '</select></td>'
                . '<td><select class="uk-select uk-form-small" data-tpl="' . $tname . '" data-key="priority" onchange="smTplChange(this)">' . $prOpts . '</select></td>'
                . '</tr>';
        }

        $tplJsonVal = htmlspecialchars(json_encode($tplSettings ?: new stdClass(), JSON_UNESCAPED_UNICODE), ENT_QUOTES);
        $chkd       = fn($v) => $v ? ' checked' : '';

        $selOpt = function(array $opts, $cur) {
            $out = '';
            foreach ($opts as $v => $l) {
                $sel = (string)$cur === (string)$v ? ' selected' : '';
                $out .= '<option value="' . $v . '"' . $sel . '>' . htmlspecialchars($l) . '</option>';
            }
            return $out;
        };

        ob_start(); ?>
<form method="post" action="./" class="uk-form-stacked">
<input type="hidden" name="<?= $csrf['name'] ?>" value="<?= $csrf['value'] ?>">
<input type="hidden" name="action" value="save_settings">
<input type="hidden" name="template_settings" id="sm_tpl_json" value="<?= $tplJsonVal ?>">

<ul class="uk-tab" uk-tab="connect: #sm-tab-content">
    <li><a href="#">General</a></li>
    <li><a href="#">Page Visibility</a></li>
    <li><a href="#">Templates</a></li>
    <li><a href="#">Priority &amp; Freq</a></li>
    <li><a href="#">Extensions</a></li>
    <li><a href="#">Custom URLs</a></li>
    <li><a href="#">Automation</a></li>
    <li><a href="#">IndexNow</a></li>
</ul>

<ul id="sm-tab-content" class="uk-switcher uk-margin">

    <!-- General -->
    <li>
        <div class="uk-grid-small uk-child-width-1-2@m" uk-grid>
            <div>
                <label class="uk-form-label">Sitemap directory</label>
                <div class="uk-form-controls">
                    <input class="uk-input" type="text" name="sitemap_dir" value="<?= htmlspecialchars($s['sitemap_dir']) ?>">
                    <div class="uk-text-small uk-text-muted">Relative to site root. Sitemap served at <code>/[dir]/sitemap.xml</code></div>
                </div>
            </div>
            <div>
                <label class="uk-form-label">URLs per file</label>
                <div class="uk-form-controls">
                    <select class="uk-select" name="chunk_size">
                        <?= $selOpt([500=>'500',1000=>'1,000',5000=>'5,000',10000=>'10,000',25000=>'25,000',50000=>'50,000 (max)'], $s['chunk_size']) ?>
                    </select>
                    <div class="uk-text-small uk-text-muted">Creates a sitemap index when exceeded.</div>
                </div>
            </div>
        </div>
        <div class="uk-margin-small">
            <label class="uk-form-label">Last modified date format</label>
            <div class="uk-form-controls">
                <select class="uk-select uk-form-width-medium" name="lastmod_format">
                    <?= $selOpt(['Y-m-d'=>'YYYY-MM-DD (recommended)','Y-m-d\TH:i:sP'=>'YYYY-MM-DDThh:mm:ss+tz','Y-m-d\TH:i:s\Z'=>'YYYY-MM-DDThh:mm:ssZ (UTC)'], $s['lastmod_format']) ?>
                </select>
            </div>
        </div>
    </li>

    <!-- Page Visibility -->
    <li>
        <div uk-margin>
            <label><input class="uk-checkbox" type="checkbox" name="include_hidden" value="1"<?= $chkd($s['include_hidden']) ?>> &nbsp;Include hidden pages</label>
        </div>
        <div uk-margin>
            <label><input class="uk-checkbox" type="checkbox" name="include_unpublished" value="1"<?= $chkd($s['include_unpublished']) ?>> &nbsp;Include unpublished pages</label>
        </div>
        <div uk-margin>
            <label><input class="uk-checkbox" type="checkbox" name="respect_noindex" value="1"<?= $chkd($s['respect_noindex']) ?>> &nbsp;Exclude pages with <code>noindex</code> meta/header</label>
        </div>
    </li>

    <!-- Template Selection -->
    <li>
        <div class="uk-text-small uk-text-muted uk-margin-small-bottom">
            <b>Include</b> — only included templates appear in the sitemap &nbsp;
            <b>Exclude</b> — always skipped &nbsp;
            <b>Ignore</b> — default (included unless filtered elsewhere)
        </div>
        <?php if (!empty($allTemplates)): ?>
        <div class="uk-overflow-auto">
            <table class="uk-table uk-table-small uk-table-divider uk-table-striped">
                <thead><tr>
                    <th>Template</th>
                    <th>Status</th>
                    <th>Change frequency</th>
                    <th>Priority</th>
                </tr></thead>
                <tbody><?= $tplRows ?></tbody>
            </table>
        </div>
        <?php endif; ?>
    </li>

    <!-- Priority & Change Frequency -->
    <li>
        <div class="uk-grid-small uk-child-width-1-2@m" uk-grid>
            <div>
                <label class="uk-form-label">Homepage priority</label>
                <select class="uk-select" name="homepage_priority">
                    <?= $selOpt(array_combine($prOptions, $prOptions), $s['homepage_priority']) ?>
                </select>
            </div>
            <div>
                <label class="uk-form-label">Homepage change frequency</label>
                <select class="uk-select" name="homepage_changefreq">
                    <?= $selOpt(array_combine($cfOptions, $cfOptions), $s['homepage_changefreq']) ?>
                </select>
            </div>
            <div>
                <label class="uk-form-label">Default priority</label>
                <select class="uk-select" name="default_priority">
                    <?= $selOpt(array_combine($prOptions, $prOptions), $s['default_priority']) ?>
                </select>
            </div>
            <div>
                <label class="uk-form-label">Default change frequency</label>
                <select class="uk-select" name="default_changefreq">
                    <?= $selOpt(array_combine($cfOptions, $cfOptions), $s['default_changefreq']) ?>
                </select>
            </div>
        </div>
        <p class="uk-text-small uk-text-muted uk-margin-small-top">Per-template overrides are set in the Templates tab.</p>
    </li>

    <!-- Extensions -->
    <li>
        <div class="uk-grid-small uk-child-width-1-2@m" uk-grid>
            <div>
                <label><input class="uk-checkbox" type="checkbox" name="include_images" value="1"<?= $chkd($s['include_images']) ?>> &nbsp;Include image sitemap extension</label>
                <div class="uk-text-small uk-text-muted uk-margin-small-top">Adds <code>&lt;image:image&gt;</code> entries for pages with images.</div>
            </div>
            <div>
                <label><input class="uk-checkbox" type="checkbox" name="multilang_hreflang" value="1"<?= $chkd($s['multilang_hreflang']) ?>> &nbsp;Add hreflang alternate links</label>
                <div class="uk-text-small uk-text-muted uk-margin-small-top">Requires LanguageSupport. Adds <code>&lt;xhtml:link rel="alternate"&gt;</code>.</div>
            </div>
        </div>
    </li>

    <!-- Custom URLs -->
    <li>
        <label class="uk-form-label">Custom URLs (JSON array)</label>
        <textarea class="uk-textarea uk-form-small" name="custom_urls" rows="6" style="font-family:monospace"><?= htmlspecialchars($s['custom_urls']) ?></textarea>
        <div class="uk-text-small uk-text-muted uk-margin-small-bottom">
            Example: <code>[{"loc":"https://example.com/page","changefreq":"monthly","priority":"0.5"}]</code>
        </div>
        <label class="uk-form-label uk-margin-small-top">Exclude URL patterns</label>
        <textarea class="uk-textarea uk-form-small" name="exclude_url_patterns" rows="5" placeholder="/private/&#10;/admin/" style="font-family:monospace"><?= htmlspecialchars($s['exclude_url_patterns']) ?></textarea>
        <div class="uk-text-small uk-text-muted">One pattern per line. URLs containing the pattern are excluded.</div>
    </li>

    <!-- Automation -->
    <li>
        <div class="uk-grid-small uk-child-width-1-2@m" uk-grid>
            <div>
                <label><input class="uk-checkbox" type="checkbox" name="auto_regenerate" value="1"<?= $chkd($s['auto_regenerate']) ?>> &nbsp;Auto-regenerate via LazyCron</label>
                <div class="uk-text-small uk-text-muted uk-margin-small-top">Automatically regenerates the sitemap on a schedule.</div>
            </div>
            <div>
                <label class="uk-form-label">Regeneration interval</label>
                <select class="uk-select" name="regenerate_interval">
                    <?= $selOpt([60=>'Every minute',3600=>'Every hour',21600=>'Every 6 hours',43200=>'Every 12 hours',86400=>'Every 24 hours (recommended)',604800=>'Every week',2419200=>'Every 4 weeks'], $s['regenerate_interval']) ?>
                </select>
            </div>
        </div>
        <div class="uk-margin-small">
            <label><input class="uk-checkbox" type="checkbox" name="robots_txt_reference" value="1"<?= $chkd($s['robots_txt_reference']) ?>> &nbsp;Add sitemap reference to robots.txt</label>
            <div class="uk-text-small uk-text-muted">Injects a <code>Sitemap:</code> directive into robots.txt via hook.</div>
        </div>
    </li>

    <!-- IndexNow -->
    <li>
        <div class="uk-alert uk-alert-primary">
            <p class="uk-text-small">
                <a href="https://www.indexnow.org" target="_blank">IndexNow</a> is supported by Bing, Yandex, DuckDuckGo and others.
                Google and Bing deprecated anonymous sitemap ping endpoints in 2022&ndash;2023.
            </p>
        </div>
        <div uk-margin>
            <label><input class="uk-checkbox" type="checkbox" name="indexnow_enabled" value="1"<?= $chkd($s['indexnow_enabled']) ?>> &nbsp;Submit URLs to IndexNow after generation</label>
        </div>
        <div class="uk-margin-small">
            <label class="uk-form-label">API key</label>
            <div class="uk-flex uk-flex-middle" style="gap:8px">
                <input class="uk-input uk-form-width-medium" type="text" name="indexnow_key"
                    value="<?= htmlspecialchars($s['indexnow_key']) ?>"
                    placeholder="32-character hex key"
                    style="font-family:monospace">
                <button type="button" class="ui-button ui-state-default" onclick="smGenKey()">
                    <span class="ui-button-text">Generate</span>
                </button>
            </div>
            <div class="uk-text-small uk-text-muted uk-margin-small-top">
                After saving, <code>/[key].txt</code> is written to the site root for verification.
                <a href="https://www.indexnow.org/documentation" target="_blank">Documentation ↗</a>
            </div>
        </div>
    </li>

</ul>

<div class="uk-margin">
    <button type="submit" class="ui-button ui-state-default ui-priority-primary">
        <span class="ui-button-text">Save Settings</span>
    </button>
    &nbsp;
    <a href="../" class="ui-button ui-state-default ui-priority-secondary">
        <span class="ui-button-text">Cancel</span>
    </a>
</div>

</form>

<script>
function smTplChange(sel) {
    var hidden = document.getElementById('sm_tpl_json');
    var data = {};
    try { data = JSON.parse(hidden.value) || {}; } catch(e) {}
    var tpl = sel.getAttribute('data-tpl');
    var key = sel.getAttribute('data-key');
    if (!data[tpl]) data[tpl] = {};
    if (sel.value) data[tpl][key] = sel.value;
    else delete data[tpl][key];
    if (!data[tpl].changefreq && !data[tpl].priority) delete data[tpl];
    hidden.value = JSON.stringify(data);
}
function smGenKey() {
    var arr = new Uint8Array(16);
    crypto.getRandomValues(arr);
    document.querySelector('input[name="indexnow_key"]').value =
        Array.from(arr).map(b => b.toString(16).padStart(2,'0')).join('');
}
</script>
<?php
        return ob_get_clean();
    }

    protected function savePostedSettings(Sitemap $sitemap): void {
        $post = $this->input->post;
        $data = [];

        $data['sitemap_dir']          = $post->text('sitemap_dir')         ?: Sitemap::DEFAULT_DIR;
        $data['chunk_size']           = (int)$post->int('chunk_size')      ?: 1000;
        $data['lastmod_format']       = $post->text('lastmod_format')      ?: 'Y-m-d';
        // tpl_status[name] = include|exclude|ignore
        // PW WireInput cannot read nested arrays — use $_POST directly and sanitize
        $tplStatusRaw = isset($_POST['tpl_status']) && is_array($_POST['tpl_status'])
            ? $_POST['tpl_status'] : [];
        $inclTpls = [];
        $exclTpls = [];
        $san = $this->sanitizer;
        foreach ($tplStatusRaw as $tname => $status) {
            $tname  = $san->name((string)$tname);
            $status = $san->text((string)$status);
            if (!$tname) continue;
            if ($status === 'include') $inclTpls[] = $tname;
            elseif ($status === 'exclude') $exclTpls[] = $tname;
        }
        $data['include_templates'] = implode(',', $inclTpls);
        $data['exclude_templates'] = implode(',', $exclTpls);
        $data['include_hidden']       = $post->get('include_hidden')       ? 1 : 0;
        $data['include_unpublished']  = $post->get('include_unpublished')  ? 1 : 0;
        $data['respect_noindex']      = $post->get('respect_noindex')      ? 1 : 0;
        $data['default_priority']     = $post->text('default_priority')    ?: '0.5';
        $data['default_changefreq']   = $post->text('default_changefreq')  ?: 'weekly';
        $data['homepage_priority']    = $post->text('homepage_priority')   ?: '1.0';
        $data['homepage_changefreq']  = $post->text('homepage_changefreq') ?: 'daily';
        $data['include_images']       = $post->get('include_images')       ? 1 : 0;
        $data['multilang_hreflang']   = $post->get('multilang_hreflang')   ? 1 : 0;
        $data['auto_regenerate']      = $post->get('auto_regenerate')      ? 1 : 0;
        $data['robots_txt_reference'] = $post->get('robots_txt_reference') ? 1 : 0;
        $data['regenerate_interval']  = (int)$post->int('regenerate_interval') ?: 86400;
        $data['indexnow_enabled']     = $post->get('indexnow_enabled')     ? 1 : 0;
        $data['indexnow_key']         = preg_replace('/[^a-zA-Z0-9\-]/', '', $post->text('indexnow_key') ?: '');

        $ts = isset($_POST['template_settings']) ? trim((string)$_POST['template_settings']) : '';
        $tsDecoded = ($ts !== '') ? json_decode($ts, true) : null;
        // Must be a JSON object (associative array), not a plain array
        if ($tsDecoded !== null && (count($tsDecoded) === 0 || array_keys($tsDecoded) !== range(0, count($tsDecoded) - 1))) {
            $data['template_settings'] = $ts;
        } else {
            $data['template_settings'] = '{}';
        }

        $cu = $post->text('custom_urls');
        $data['custom_urls'] = ($cu && json_decode($cu) !== null) ? $cu : '[]';

        $data['exclude_url_patterns'] = $post->textarea('exclude_url_patterns') ?: '';

        // Debug log — what we're about to save
        $sitemap->saveSettings($data);

        try { $sitemap->ensureSitemapDir(); }
        catch (\Exception $e) { $this->error($e->getMessage()); }

        // Update robots.txt immediately on save (enable or disable Sitemap: directive)
        if (!$sitemap->updateRobotsTxt()) {
            $this->error('Could not write robots.txt. Check file permissions.');
        }

        if (!empty($data['indexnow_key'])) {
            if (!$sitemap->writeIndexNowKeyFile()) {
                $this->error('Could not write IndexNow key file to site root. Check permissions.');
            }
        }
    }

    protected function fmtBytes(int $bytes): string {
        if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
        if ($bytes >= 1024)    return round($bytes / 1024, 1) . ' KB';
        return $bytes . ' B';
    }

}
