## Phase 6: APCu/Redis/DB buffer drivers + health check panel

### New files
- `includes/collect/buffer/class-buffer-apcu.php` — APCu shared-memory buffer driver implementing `Rasad_Buffer_Interface`
- `includes/collect/buffer/class-buffer-redis.php` — Redis list buffer driver implementing `Rasad_Buffer_Interface`
- `includes/collect/buffer/class-buffer-db.php` — `rsd_buffer` table DB buffer driver implementing `Rasad_Buffer_Interface`
- `includes/process/class-health.php` — health report (`report()`, `cron_next()`, `table_sizes()`, `problems()`)

### Modified files
- `includes/class-installer.php` — added `rsd_buffer` table, `migrate_1_1_0()`, `RASAD_DB_VERSION` → `1.1.0`
- `includes/class-plugin.php` — hooked `rasad_aggregated` → `Rasad_Process_Health::mark_aggregated`
- `includes/class-settings.php` — `render_health_panel()` now uses `Rasad_Process_Health::report()`
- `rasad.php` — `RASAD_DB_VERSION` bumped to `1.1.0`

### Verification
- Test harness (`/tmp/phpcheck/test.js`, groups A–J): 28/28 PASS
- PHP 7.4 lint (`/tmp/phpcheck/lint.js`): 23/23 files OK
