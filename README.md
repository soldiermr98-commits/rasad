# rasad

رصد — آمار بازدیدکنندگان بسیار سبک، کاملاً محلی و بدون کوکی برای وردپرس.

## Transient storage & privacy

Rasad keeps raw IP addresses out of every persistent store. What is held
transiently (in memory or short-lived files) and what is never written:

### Visitor key (persistent-ish, but irreversible)

- `key = substr( HMAC-SHA256( ip + UA + day + secret, secret ), 0, 16 )`
- The secret lives in `wp-content/uploads/rasad/secret.key` (file, 0600) and
  rotates its daily component every day.
- The raw IP and full UA are **never** stored — only the 16-char hex key.

### Buffer line (transient, purged immediately after aggregation)

One JSON line per hit lives in `wp-content/uploads/rasad/buffer/hits-Ymd.jsonl`
until the aggregator consumes it (`pop_batch` removes processed lines and
deletes empty files). The line contains:

| field | stored? | notes |
|---|---|---|
| `ip` | **anonymized** | Last IPv4 octet zeroed / last 80 IPv6 bits zeroed (setting `ip_anonymize`, on by default). GeoIP country/city still works from the prefix. |
| `ua` | yes | Raw UA, disappears with the buffer file; never written to any DB table. |
| `p`, `t`, `r`, UTM, `w`, `l`, `tz` | yes | Page path, title, referrer, screen, language, timezone. Query-string is whitelisted (see below). |
| `vk` | yes | 16-hex visitor key (irreversible hash). |

### Query-string policy

The sanitizer keeps **only** the hard whitelist
`s, paged, page_id, p, cat, tag, post_type, product_cat` plus an
admin-editable extra whitelist. Everything else (`fbclid`, `gclid`, `key`,
`login`, `token`, order keys, …) is dropped. Fragments are stripped. Distinct
new page paths are capped per day and per hour; overflow collapses to
`/other`.

### Rate-limit buckets (transient)

One small JSON file per minute under `buffer/rate/` enforces 120 hits per
visitor per minute. Buckets older than 10 minutes are pruned opportunistically
and by the cleaner.

### Settings mirror

`wp-content/uploads/rasad/settings.json` mirrors the admin option so
`collect.php` (SHORTINIT) can validate and filter without touching the
database. It contains settings + site host + timestamp — no hit data.

### Health-check panel

The settings screen shows: buffer driver in use, buffer backlog size, IP
source setting, trusted proxies, trusted hosts, and the last aggregation
cron time.
