# MySQL SchemaSync — AGENTS.md

> PHP 8.5+ FFI/libui native desktop GUI: MySQL database structure diff + migration SQL generation.

## Quick Commands

```bash
composer install                       # install deps
php bin/mysql-schema-sync.php          # run GUI (requires ext-ffi)
start.bat / start.sh                   # cross-platform launcher (tries php85, then php)
```

PHP 8.5 path (macOS Homebrew): `/Users/jay/Library/PhpWebStudy/alias/php85`

## CLI Utilities

```bash
php bin/dump-schema.php [conn_name] [-o out.json]   # export DB schema to JSON
php bin/compare-offline.php src.json tgt.json        # offline diff (no DB connection)
php bin/benchmark-async.php [conn_name]              # benchmark async query strategies
```

## Tech Stack

- **GUI**: `helgesverre/libui` (dev-main) — PHP FFI bindings for libui-ng native desktop controls
- **WebView**: `yangweijie/ui2` — WebViewUI (WebView2/WebKit) + native dialogs (MessageBox, DialogConfirm)
- **Async queries**: `yangweijie/think-orm-async` — AsyncContext for parallel mysqli queries
- **Required PHP extensions**: `ext-ffi`, `ext-pdo`, `ext-pdo_mysql`, `ext-openssl`, `ext-mysqli`
- **No external comparison library** — `StructSyncAdapter` + `DDLDefinitionParser` is fully self-contained

## Project Structure

```
bin/
  mysql-schema-sync.php         # GUI entry: FFI init + DllBootstrap + WebViewUI
  dump-schema.php               # CLI: export DB schema to JSON
  compare-offline.php           # CLI: diff two JSON dumps
  benchmark-async.php           # CLI: benchmark async strategies
src/
  Config/
    Connection.php              # Data class (id/name/host/port/user/password/database)
    ConfigStore.php             # Connection CRUD + AES-256-GCM encrypted persistence (~/.mysql-schema-sync/)
  Diff/
    StructSyncAdapter.php       # Core comparison engine (Navicat-style 4-phase algorithm)
    DDLDefinitionParser.php     # Semantic DDL column comparison (field-level diffs)
    AsyncStructureFetcher.php   # Batch SHOW CREATE TABLE via think-orm-async AsyncContext
    AsyncCompareRunner.php      # Non-blocking compare runner (work-queue for UI responsiveness)
    DiffResult.php              # Diff result data structure (17 diff types)
  SqlGen/
    Generator.php               # Generate final migration SQL from diffSql + structuredDiffs
  Gui/
    WebViewUI.php               # WebView2/WebKit main window + JS↔PHP bridge
    DllBootstrap.php            # Auto-restore native DLLs from bridge/ backup
    assets/                     # Frontend: app.html, app.css, app.js, init.js (loaded via file_get_contents)
bridge/                         # Native library backups (libui, webview_bridge, PebView, etc.)
stubs/think/                    # Minimal ThinkPHP stubs (Collection, db\Fetch, db\BaseQuery) for think-orm-async
```

## Key Architecture

### Comparison Flow (StructSyncAdapter + DDLDefinitionParser)
1. `AsyncCompareRunner` orchestrates the work-queue: list tables → fetch each via AsyncContext → compare
2. `StructSyncAdapter.compare()` calls `buildDiffSql()` using a Navicat-style algorithm:
   - Phase 1: Table name set diff → ADD_TABLE, DROP_TABLE, CANDIDATE
   - Phase 2: DDLDefinitionParser semantic column/constraint comparison (catches charset, collation, ON UPDATE, etc.)
3. Result packaged as `DiffResult` (17 diff types, each independent array)
4. `Generator` merges ALTERs and produces final SQL

### Direction: source vs target
- `source` = the "new" schema (user's desired state)
- `target` = the "old" schema (to be synced)
- Generated SQL makes target = source (ADD columns in target, DROP columns not in source)

### DllBootstrap (Native Library Recovery)
After `composer update` or `git clean`, vendor DLLs may be wiped. `DllBootstrap::ensure()` copies missing native libraries from `bridge/` to vendor paths. Runs automatically at startup.

### Async Work Queue (AsyncCompareRunner)
- PHP is single-threaded; `Loop::delay(ms, cb)` defers each query to the next event loop tick
- Work queue: `listTables()` → enqueue each table → `fetchOneTable()` per tick → `compare()`
- Advanced objects (VIEW/TRIGGER/EVENT/FUNCTION/PROCEDURE) fetched the same way
- Cancel button works between work-queue steps, not during individual queries

### Config Storage
- Directory: `~/.mysql-schema-sync/`
- Key: `~/.mysql-schema-sync/.key` (32 bytes random, auto-generated)
- Config: `~/.mysql-schema-sync/config.json` (passwords AES-256-GCM encrypted)
- Password format: `base64(IV(12) + tag(16) + ciphertext)`

### Exclusion Filtering
- User enters comma-separated glob patterns (e.g. `*_bak, *_backup*, tmp_*`)
- Stored in `config.json` → `settings.excludePatterns`
- Passed to StructSyncAdapter for SQL-layer `WHERE` filtering + PHP `fnmatch` fallback

## Gotchas

| Issue | Cause | Fix |
|-------|-------|-----|
| UI freezes during compare | Synchronous DB queries on main thread | Work-queue architecture: one query per Loop::delay tick |
| Native DLLs missing after composer update | Vendor cleaned by composer | DllBootstrap auto-restores from bridge/ at startup |
| Table shows no data on macOS | libui Table created after Window::show() | All Tables must be created before show() (handled in WebViewUI) |
| SQL has double semicolons | raw SQL already has `;`, Generator appends another | Generator uses `rtrim($sql, ';')` |
| Connection ID conflicts | Previously used name as ID | Now uses `random_bytes(8)` hex |

## Testing

No automated tests. Manual test:
1. Set up two MySQL databases (source structure + target structure)
2. Modify the source database schema
3. Run `php bin/mysql-schema-sync.php` and compare
4. Verify the diff table and generated SQL match expectations
