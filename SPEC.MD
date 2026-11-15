# ROLE
You are a senior WordPress plugin engineer specialized in high-performance PHP, MySQL optimization, and privacy-friendly analytics. Build a production-grade, commercial WordPress plugin called **Rasad (رصد)**: an extremely lightweight, fully local visitor-statistics plugin with live details.

# PRODUCT GOALS
- Extremely light: near-zero impact on front-end speed and server load.
- 100% local: NO external services, NO external HTTP requests, NO CDN assets, NO cookies required.
- Cache-friendly: must work correctly behind page caching (WP Rocket, LiteSpeed, Cloudflare, etc.), so tracking is client-side via a beacon.
- Works on ordinary shared hosting first, but scales to high-traffic sites through pluggable buffer drivers.
- Persian-first: full RTL, Jalali (Shamsi) calendar support, Persian UI strings. English as secondary via i18n (text domain `rasad`).
- Privacy-friendly: no raw IP storage, no cookies, GDPR-friendly by design.

# TECH REQUIREMENTS
- PHP 7.4+ compatible (no PHP 8-only syntax), WordPress 5.8+, MySQL 5.7+/MariaDB 10.3+.
- Prefix for everything: `rasad_` / class prefix `Rasad_` / table prefix `{$wpdb->prefix}rsd_` / constants `RASAD_`.
- Follow WordPress Coding Standards: escaping, sanitization, nonces, capability checks, prepared statements everywhere.
- No jQuery, no heavy libraries. Admin charts: use uPlot (bundled locally) or pure SVG.
- Admin assets load ONLY on Rasad's own admin pages. Front-end loads only the tiny tracker script.
- Lazy-load classes (simple autoloader). Nothing heavy on `plugins_loaded`.
- Settings stored in ONE option with `autoload = no`.

# FILE STRUCTURE (follow exactly)
```
rasad/
├── rasad.php                     # header + minimal bootstrap
├── uninstall.php                 # optional full cleanup (respects a "delete data on uninstall" setting)
├── collect.php                   # standalone hit endpoint (SHORTINIT)
├── includes/
│   ├── class-plugin.php
│   ├── class-installer.php       # create tables, versioned migrations
│   ├── class-settings.php
│   ├── collect/
│   │   ├── class-request.php     # sanitize/validate input
│   │   ├── class-visitor.php     # daily hash, bot detection
│   │   ├── class-ua-parser.php   # lightweight self-written UA parser
│   │   └── buffer/
│   │       ├── interface-buffer.php
│   │       ├── class-buffer-redis.php
│   │       ├── class-buffer-apcu.php
│   │       ├── class-buffer-file.php
│   │       └── class-buffer-db.php
│   ├── process/
│   │   ├── class-aggregator.php
│   │   ├── class-geoip.php
│   │   ├── class-referrer.php
│   │   └── class-cleaner.php
│   ├── api/
│   │   ├── class-rest.php
│   │   └── class-live.php
│   ├── admin/
│   │   ├── class-admin.php
│   │   ├── class-dashboard-widget.php
│   │   └── class-post-column.php
│   ├── integrations/
│   │   └── class-woocommerce.php  # loaded only if WooCommerce active
│   └── helpers/
│       └── class-jdate.php        # Gregorian <-> Jalali, self-contained
├── assets/
│   ├── t.js                       # tracker, < 1KB minified
│   ├── admin.js / admin.css       # no jQuery, RTL-ready
│   └── vendor/uplot.min.js
├── data/                          # GeoIP DB location (downloaded on demand)
└── languages/
```

# DATA FLOW
Browser → `t.js` (sendBeacon) → `collect.php` (SHORTINIT, no full WP load) → Buffer Driver → (cron) Aggregator → summary tables → REST API (cached) → Admin dashboard.

# BUFFER DRIVERS (auto-detect, in priority order, overridable in settings)
1. Redis / Memcached (if available) — high-traffic.
2. APCu (if available).
3. Append-only file buffer in `wp-content/uploads/rasad/buffer/` (one JSON line per hit, `LOCK_EX`, protected with .htaccess + index.php) — DEFAULT for shared hosting.
4. DB fallback table.
All drivers implement `Rasad_Buffer_Interface` with: `push(array $hit)`, `pop_batch(int $limit)`, `count()`, `is_available()`.

# DATABASE SCHEMA (create via dbDelta, InnoDB, utf8mb4)
Use compact numeric IDs and lookup tables. Tables:

- `rsd_pages` (id, hash CHAR(16) UNIQUE, path VARCHAR(500), post_id NULL, post_type NULL, title NULL)
- `rsd_referrers` (id, domain UNIQUE, type TINYINT [1 direct,2 search,3 social,4 other,5 campaign], name NULL)
- `rsd_sessions` (id, visitor_key BINARY(8), started_at, last_seen, pageviews, duration, entry_page, exit_page, referrer_id, country CHAR(2), city_id, device TINYINT, browser TINYINT, os TINYINT, is_bounce; UNIQUE(visitor_key, started_at), KEY(last_seen))
- `rsd_hits` (id, session_id, page_id, ts; KEY ts, KEY session_id) — raw, auto-purged after N days (default 30)
- `rsd_daily` (day PK, views, visitors, sessions, bounces, total_duration)
- `rsd_hourly` (hour_ts PK, views, visitors)
- `rsd_daily_pages` (day, page_id, views, visitors, entries, exits; PK(day,page_id))
- `rsd_daily_dim` (day, dim TINYINT [1 referrer,2 country,3 city,4 browser,5 os,6 device,7 search_term,8 utm_campaign], dim_id, views, visitors; PK(day,dim,dim_id))
- `rsd_events` (id, day, type, page_id, value DECIMAL NULL; KEY(day,type)) — for outbound clicks, downloads, WooCommerce events

Keep indexes minimal on write-heavy tables. Implement a versioned migration system in the installer.

# TRACKER (`assets/t.js`)
- Under 1KB minified, no dependencies, async, inline-able via `wp_add_inline_script` or a tiny external file with `defer`.
- Sends via `navigator.sendBeacon` (fallback: `fetch` with `keepalive`, then image pixel).
- Payload: page path, title, referrer, screen width, language, UTM params, timezone offset.
- Sends a second lightweight ping on `visibilitychange`/`pagehide` with active duration.
- Respects Do-Not-Track if the setting is enabled.
- Skips: admin pages, logged-in users with excluded roles, preview, customizer, prefetch/prerender.

# COLLECT ENDPOINT (`collect.php`)
- Define `SHORTINIT` and load only what's needed; if impossible on a host, auto-fallback to a minimal REST route (`/wp-json/rasad/v1/hit`) — implement BOTH and let the tracker use the one that works (settings + self-test).
- Validate origin/host, max payload size, rate limit per visitor_key, reject bots (UA list + header heuristics + known datacenter patterns where cheap).
- Visitor key = hash(IP + UA + daily rotating salt). Never store raw IP or full UA.
- Return `204 No Content` immediately; push to buffer; do NOT touch the DB on the hot path when a non-DB buffer is available.
- If buffer is large or last aggregation is stale, trigger a non-blocking, lock-protected aggregation.

# AGGREGATOR & CRON
- Runs every minute via WP-Cron (and supports a real server cron hitting a secret-key URL / WP-CLI command `wp rasad aggregate`).
- Uses `GET_LOCK` or lock file to prevent concurrent runs.
- Processes buffer in batches (default 500–2000 per run, configurable), uses multi-row INSERT ... ON DUPLICATE KEY UPDATE.
- Resolves referrer type/name (search engines incl. Google, Bing, Yandex, DuckDuckGo; social incl. Instagram, Telegram, WhatsApp, Eitaa, Rubika, Aparat, X, Facebook, LinkedIn, Pinterest), parses UA, resolves GeoIP, extracts internal site search terms.
- Cleaner: purge raw hits/sessions older than N days, optimize occasionally, cap buffer file size.

# LIVE FEATURES
- "Online now" = sessions with `last_seen` within last 5 minutes.
- Live panel: current visitors, what page each is viewing, country/city, device, referrer, and a short real-time feed of the latest hits.
- Admin polls every 15s ONLY when the tab is visible (Page Visibility API) and the Rasad page is open; exponential backoff on errors.
- Optional: SSE endpoint disabled by default (warn about shared hosting limits).

# GEOIP
- Local database only (DB-IP Lite or MaxMind GeoLite2 format), downloaded by admin action, stored in `data/`. Pure-PHP reader (no extension requirement), lookup only inside the aggregator (never in the hot path unless cheap).
- Feature degrades gracefully if no DB is present.

# ADMIN DASHBOARD (REST-driven, fast)
All numbers come from summary tables only, with transient caching (short TTL for today, long for past days). Include:
- Overview: views, visitors, sessions, bounce rate, avg duration, with comparison vs previous period; today/yesterday/7d/30d/90d/custom range.
- Time-series chart (hourly for ≤2 days, daily otherwise).
- Top pages, entry pages, exit pages, top posts, post-type breakdown.
- Referrers: grouped by direct/search/social/other, search engines, social networks, campaigns (UTM).
- Geography: countries and cities.
- Devices, browsers, operating systems.
- Internal site search terms.
- Live tab (above).
- Jalali date display + date picker (setting: Jalali / Gregorian), correct week start (Saturday for fa_IR).
- CSV export (streamed, respects capabilities).
- Dashboard widget (mini sparkline + online count) and a views column in posts/pages list tables.
- UI must be RTL-ready, clean, modern, minimal JS, dark mode via CSS variables (prepare structure; final visual design will be supplied later, so keep markup/CSS class names semantic and the styling layer isolated in `admin.css`).

# SETTINGS PAGE
Buffer driver (auto/manual + self-test), data retention days, excluded roles, excluded IPs/paths/UAs, bot filtering toggle, honor DNT, tracker endpoint mode (collect.php / REST / auto), GeoIP management, calendar (Jalali/Gregorian), delete-data-on-uninstall, "health check" panel showing: buffer driver in use, buffer backlog size, last aggregation time, cron status, DB table sizes, and detected problems with fix hints.

# WOOCOMMERCE (conditional)
When active: track product views, add-to-cart, checkout start, purchases (order count/revenue per day, per product, per referrer). Zero overhead when WooCommerce is absent.

# SECURITY & QUALITY
- Capability check `manage_options` (filterable) on all admin/REST endpoints; nonces on admin actions; strict input sanitization; prepared statements; no direct file access (`ABSPATH` check) except `collect.php` which must be hardened separately.
- Buffer directory protected; no sensitive data in logs.
- Multisite-aware activation (per-site tables).
- Add hooks/filters for extensibility (`rasad_should_track`, `rasad_hit_data`, `rasad_buffer_driver`, etc.).
- Add PHPDoc, i18n for every string (`__('...', 'rasad')`), and provide `.pot` file.
- Provide basic automated tests for: UA parser, referrer classifier, Jalali converter, aggregator math (bounce, duration, unique visitors), buffer drivers.

# PERFORMANCE BUDGET (must be verified)
- Front-end: tracker < 1KB, 0 render-blocking, 0 external requests.
- Hot path (`collect.php`) target < 10ms with file/APCu/Redis buffer.
- Admin dashboard queries only on aggregated tables, each < 50ms on 1M raw hits.
- Add a short "Performance notes" section in README explaining how each target is met.

# DELIVERY PROCESS (IMPORTANT)
Do NOT dump everything at once. Work in phases, finish and show complete working code for each phase, then wait for my "continue":

1. Plugin skeleton + installer + DB schema + settings + autoloader.
2. `t.js` + `collect.php` + REST fallback + File buffer driver + visitor hashing + bot filter.
3. Aggregator + cron + cleaner + lock + referrer classifier + UA parser.
4. REST API + cache layer + minimal functional admin dashboard (numbers + one chart) to validate data accuracy.
5. Live module + GeoIP.
6. APCu/Redis/DB buffer drivers + health check panel.
7. Jalali support, CSV export, dashboard widget, posts column.
8. WooCommerce integration, tests, README, .pot, polish.

Write complete files (no placeholders, no "...", no TODO stubs). Before Phase 1, briefly list any assumptions or risks you see, then start.
