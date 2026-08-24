# Sitemap public API

This file documents the public calls and hooks intended for site code. Methods
not listed here are implementation details.

## Module access

```php
if ($modules->isInstalled('Sitemap')) {
    /** @var Sitemap $sitemap */
    $sitemap = $modules->get('Sitemap');
}
```

## Methods

### `generate(bool $force = false): array`

Generate the sitemap files. Returns `files`, `urls`, and `time`. When generation
is already locked and `$force` is false, returns an `error` entry instead.
Filesystem and XML generation failures may throw an exception.

### `loadSettings(): array`

Return all stored settings merged over module defaults.

### `setting(string $name): mixed`

Return one current setting or `null` for an unknown setting.

### `saveSetting(string $name, mixed $value): void`

Persist one setting. Treat this as a configuration mutation and validate values
at the site boundary.

### `saveSettings(array $data): void`

Persist multiple settings. Keys and values are not validated by this low-level
method; prefer the Sitemap admin UI for ordinary configuration changes.

### `getBaseUrl(): string`

Return the current ProcessWire site root URL, including its scheme and any
subdirectory.

### `getStatus(): array`

Return generation time, regeneration and lock flags, generated-file metadata,
URL and size totals, and sitemap directory status.

### `ensureSitemapDir(): string`

Create the configured sitemap directory if needed, verify writability, and
return its filesystem path. Throws `WireException` on failure.

### `getSitemapFilePath(string $filename): string`

Return the configured filesystem path for a sitemap filename.

### `updateRobotsTxt(): bool`

Write or remove the physical `robots.txt` `Sitemap:` directive according to the
current setting. This mutates a public site file.

### `writeIndexNowKeyFile(): bool`

Write the configured IndexNow verification file to the site root. This mutates
a public site file.

## Hooks

### `Sitemap::collectExtraUrls`

Called during generation with no arguments. Append URLs that are not represented
by normal ProcessWire Pages. The return value is an array of entries supporting:

- `loc` (required, absolute HTTP or HTTPS URL)
- `lastmod`
- `changefreq`
- `priority` (clamped to 0.0 through 1.0)
- `template` (sitemap file grouping name)

```php
$wire->addHookAfter('Sitemap::collectExtraUrls', function(HookEvent $event) {
    $event->return = array_merge((array)$event->return, [
        ['loc' => 'https://example.com/virtual/', 'template' => 'virtual'],
    ]);
});
```

### `Sitemap::collectUrlSegments`

Called once for each included Page whose template has URL segments enabled.
Argument 0 is that `Page`. Return an array whose items are either:

- a relative segment string such as `print/`; or
- an array with `segment`, or with an absolute `loc`, plus optional `lastmod`,
  `changefreq`, `priority`, and `template` overrides.

Relative entries inherit the Page entry's metadata. Sitemap URL exclusion rules,
validation, and deduplication are applied after the hook result is collected.

```php
$wire->addHookAfter('Sitemap::collectUrlSegments', function(HookEvent $event) {
    $page = $event->arguments(0);
    if ($page->template->name !== 'article') return;
    $event->return = array_merge((array)$event->return, ['print/', 'comments/']);
});
```
