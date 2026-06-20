# Sitemap

XML Sitemap generator module for [ProcessWire CMS](https://processwire.com).

![Sitemap](assets/Sitemap.png)

Generates standard-compliant XML sitemaps with per-template configuration, sitemap index support for large sites, image extension, hreflang for multilanguage sites, IndexNow submission, and LazyCron-based auto-regeneration.

- **Repository:** [github.com/mxmsmnv/Sitemap](https://github.com/mxmsmnv/Sitemap)

**Author:** Maxim Semenov  
**Website:** [smnv.org](https://smnv.org)  
**Email:** [maxim@smnv.org](mailto:maxim@smnv.org)

If this project helps your work, consider supporting future development: [GitHub Sponsors](https://github.com/sponsors/mxmsmnv) or [smnv.org/sponsor](https://smnv.org/sponsor/).

- **License:** MIT
- **Requires:** ProcessWire 3.0.200+, PHP 8.1+

---

## Features

- Per-template sitemaps — each template gets its own named file (`sitemap-product.xml`, `sitemap-blog.xml`, etc.)
- Sitemap index (`sitemap.xml`) referencing all child files, with automatic overflow numbering when a template exceeds the URL limit
- Per-template `changefreq` and `priority` overrides via admin UI
- Configurable homepage priority and changefreq
- Image sitemap extension (`<image:image>`) for Google Images
- Hreflang `<xhtml:link rel="alternate">` for multilanguage sites (requires LanguageSupport)
- Custom URL entries via JSON
- URL exclusion by substring or regex pattern
- noindex field compatibility (WireSEO and common custom SEO field names)
- Auto-regeneration via LazyCron with configurable interval (1 minute to 4 weeks)
- LazyCron slot is chosen dynamically to match the configured interval
- Cache invalidation on page save, trash, and delete
- IndexNow submission after generation (Bing, Yandex, DuckDuckGo)
- Automatic `Sitemap:` directive written to `robots.txt` on save and generation
- Admin dashboard with per-file URL count, size, and last modified
- Lock file prevents concurrent generation
- All settings stored in a dedicated DB table — no PW config size limits
- Generation log via PW native log system (`Setup > Logs > sitemap`)

---

## Requirements

- ProcessWire 3.0.200 or newer
- PHP 8.1 or newer
- [LazyCron](https://processwire.com/modules/lazy-cron/) module (core, requires manual install) for auto-regeneration

---

## Installation

1. Copy the `Sitemap` folder to `/site/modules/`
2. Go to **Modules > Refresh** in the admin
3. Install **Sitemap** — also install **ProcessSitemap** if prompted
4. Optionally install **LazyCron** (Admin > Modules > Core) to enable auto-regeneration
5. Go to **Setup > Sitemap** to configure and generate

---

## Sitemap URLs

The sitemap index is always served at:

```
https://yourdomain.com/[sitemap_dir]/sitemap.xml
```

Default directory: `sitemaps`. Individual template sitemaps are served at:

```
https://yourdomain.com/sitemaps/sitemap-product.xml
https://yourdomain.com/sitemaps/sitemap-brand.xml
...
```

When a template exceeds the URLs-per-file limit, overflow files are numbered:

```
sitemap-product-1.xml
sitemap-product-2.xml
...
```

---

## Configuration

All settings are managed in the admin at **Setup > Sitemap > Settings**.

### General

| Setting | Default | Description |
|---|---|---|
| Sitemap directory | `sitemaps` | Directory relative to site root where files are written |
| URLs per file | `1000` | Max URLs per child sitemap; triggers numbered overflow above this |
| Last modified format | `Y-m-d` | PHP date format for `<lastmod>` |

### Page Visibility

| Setting | Default | Description |
|---|---|---|
| Include hidden pages | off | Include pages with hidden status |
| Include unpublished | off | Include unpublished pages |
| Respect noindex | on | Skip pages with a noindex SEO field set |

### Templates

Each template can be set to **Include**, **Exclude**, or **Ignore** (default). Per-template `changefreq` and `priority` overrides are set here.

- **Include** — only included templates appear in the sitemap
- **Exclude** — always skipped, even if other rules would include them
- **Ignore** — uses the default include/exclude logic

### Priority & Frequency

| Setting | Default | Description |
|---|---|---|
| Homepage priority | `1.0` | |
| Homepage changefreq | `daily` | |
| Default priority | `0.5` | Applies when no template override is set |
| Default changefreq | `weekly` | Applies when no template override is set |

### Extensions

| Setting | Default | Description |
|---|---|---|
| Image sitemap extension | off | Adds `<image:image>` for all image fields on each page |
| Hreflang alternate links | off | Adds `<xhtml:link rel="alternate">` (requires LanguageSupport) |

### Custom URLs

JSON array of extra URLs to append to the sitemap regardless of page structure. Example:

```json
[
  {"loc": "https://example.com/custom-page", "changefreq": "monthly", "priority": "0.5"},
  {"loc": "https://example.com/landing"}
]
```

URL exclusion patterns (one per line) exclude pages whose URL contains the pattern or matches the regex.

### Automation

| Setting | Default | Description |
|---|---|---|
| Auto-regenerate via LazyCron | on | Requires LazyCron module installed |
| Regeneration interval | Every 24 hours | From every minute to every 4 weeks |
| Add sitemap reference to robots.txt | on | Writes `Sitemap:` directive to the physical `robots.txt` file |

### IndexNow

| Setting | Default | Description |
|---|---|---|
| Submit URLs to IndexNow | off | POSTs all URLs to `api.indexnow.org` after generation |
| API key | — | 32-character hex key; click Generate to create one |

After saving the key, a verification file `[key].txt` is written to the site root automatically.

---

## robots.txt

When **Add sitemap reference to robots.txt** is enabled, the module writes a `Sitemap:` directive directly to the physical `robots.txt` file on disk. This happens on every **Save Settings** and every **Generate Now**. The directive is replaced (not duplicated) on subsequent writes.

---

## IndexNow

[IndexNow](https://www.indexnow.org) is supported by Bing, Yandex, DuckDuckGo and others. Google and Bing deprecated anonymous sitemap ping endpoints in 2022–2023; IndexNow is the recommended replacement.

URLs are submitted in batches of up to 10,000. The HTTP response code is logged to `Setup > Logs > sitemap`.

---

## Logs

All significant events are logged to the `sitemap` log, visible at **Setup > Logs > sitemap**:

- LazyCron fire, skip, and generation start
- `collectUrls` — URL count and template distribution
- `writeFiles` — list of generated files
- `generate()` — completion time
- `updateRobotsTxt` — result
- `IndexNow` — submission response code
- `writeIndexNowKeyFile` — result

---

## License

MIT License. Copyright 2025 Maxim Semenov.
