# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

---

## [1.0.1] - 2026-06-07

### Fixed

- Use the current ProcessWire site scheme/root URL for generated sitemap file links instead of forcing `https://`.
- `robots.txt`, sitemap index entries, dashboard links, and IndexNow key file URLs now respect HTTP sites and installations in a subdirectory.

---

## [1.0.0] - 2026-03-13

### Added

- Per-template sitemap files — each template produces its own named file (`sitemap-product.xml`, `sitemap-brand.xml`, etc.) instead of generic numbered files
- Sitemap index (`sitemap.xml`) always written as the entry point; child files linked from it
- Overflow numbering when a single template exceeds the URLs-per-file limit (`sitemap-product-1.xml`, `sitemap-product-2.xml`, etc.)
- Per-template `changefreq` and `priority` overrides in the Templates settings tab, stored as a JSON object in the DB
- Templates tab with per-row Include / Exclude / Ignore radio buttons and per-template changefreq/priority selects
- IndexNow support — batch URL submission to `api.indexnow.org` after generation; key file written to site root for verification
- `robots.txt` integration — writes `Sitemap:` directive directly to the physical file on Save Settings and Generate Now; removes duplicates on repeated writes
- LazyCron auto-regeneration with dynamic slot selection — the LazyCron method (`everyMinute`, `everyHour`, `every6Hours`, `everyDay`, `everyWeek`, `every4Weeks`) is chosen to match the configured interval rather than always using `everyHour`
- Regeneration interval options: every minute, every hour, every 6 hours, every 12 hours, every 24 hours, every week, every 4 weeks
- LazyCron installation check — red alert on dashboard if LazyCron is not installed when auto-regenerate is enabled
- `is_index` flag on sitemap files in `getStatus()` so the dashboard correctly identifies the index file and excludes it from the total URL count
- `generate()` returns a result array with file count, URL count, and elapsed time; return value is now safe when an exception is thrown (uses `$result` initialized before the try block)
- Generation log entries: LazyCron method name, last generation time, elapsed seconds, skip reason, URL count per template, list of written files, total time
- `updateRobotsTxt()` log entry with enabled state, path, writability, byte count, and final file content
- All settings stored in a dedicated `sitemap_settings` DB table (name/value, MEDIUMTEXT) — no ProcessWire config size limits
- Settings managed through a dedicated Process admin page at Setup > Sitemap, separate from the Modules config page
- 8-tab settings UI using UIkit `uk-tab` + `uk-switcher`: General, Page Visibility, Templates, Priority & Freq, Extensions, Custom URLs, Automation, IndexNow
- CSRF protection on all POST actions (generate, delete files, create directory, save settings)
- PRG pattern on all form submissions
- `tpl_status[]` and `template_settings` read directly from `$_POST` to avoid ProcessWire WireInput nested array limitations
- `template_settings` validated as a JSON object (not array) before saving; `json_encode(new stdClass())` used to ensure `{}` output for empty settings
- `array_is_list()` check when loading `template_settings` to reject malformed indexed arrays from old saves
- Page selector uses `status!=hidden` and `status<unpublished` (correct PW selector syntax)
- Pagination over pages in chunks of 500 with `uncacheAll()` and `resetTrackChanges()` to control memory on large sites
- System template and admin page exclusion (`flagSystem`, `adminRootPageID`)
- `matchesExcludePattern()` uses `str_contains()` for substring match and `@preg_match()` for regex patterns with delimiters
- `ensureSitemapDir()` creates the output directory if missing, writes `.htaccess` to disable directory listing
- Lock file with 600-second expiry prevents concurrent generation
- `fmtBytes()` helper for human-readable file sizes in the dashboard
- `writeIndexNowKeyFile()` writes the IndexNow verification text file to the site root
