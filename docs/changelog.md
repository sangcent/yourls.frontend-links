# Changelog

All notable changes to Frontend Links are documented here.

## [1.7] - 2026-02-23

### Fixed

- **HTTP 500 on activation and migration** (cPanel / shared hosts): PDO configured in `ERRMODE_SILENT` (the default on many shared hosts) returns `false` from `query()` and `prepare()` instead of throwing an exception. Calling `->fetch()`, `->fetchAll()`, or `->execute()` on `false` caused a PHP fatal error — visible as an HTTP 500 with no other output. All 17 affected database call sites in `functions.php` now check the return value before chaining method calls. This was the root cause of the activation failure and the migration 500 reported on cPanel.
- **Migration 500** (`migrate.php`): `fl_maybe_migrate_options()` now auto-creates the `frontend_settings` table when it is missing (upgrade without plugin reactivation) instead of bailing out silently. The `prepare()→execute()` chain is also guarded.
- **`fl_get_settings()` fatal on fresh table-less load**: `$stmt->fetch()` called on `false` when `frontend_settings` did not yet exist; the `$stmt !== false` guard is now applied before the fetch loop, and `catch (Exception)` widened to `catch (\Throwable)` to also intercept PHP `Error` subclasses.
- **`fl_get_custom_icons()` and `fl_build_robots_txt_content()`**: same `query()` null-check and `catch (\Throwable)` upgrade applied.
- **`.htaccess` content loss**: `fl_create_root_htaccess()` could silently overwrite the entire `.htaccess` with only the plugin's rules when `file_get_contents()` failed on an existing file (unreadable due to permissions). The function now returns an error in that case.
- **Wrong behavior on unreadable files**: `strpos()` called on a `false` return from `file_get_contents()` produced incorrect results (PHP 8 raises a `TypeError`). Guarded in `fl_create_homepage_file()`, `fl_create_yourls_root_index()`, `fl_delete_homepage_file()`, `fl_delete_root_htaccess()`, `fl_write_robots_txt()`, and `fl_delete_robots_txt()`.
- **Path traversal in icon deletion** (`fl_delete_custom_icon()`): the icon filename stored in the database was concatenated directly into the file path. A tampered `content` value such as `../../config.php` could escape the uploads directory. The path is now sanitized with `basename()`.
- **CSRF via GET on AJAX endpoint** (`ajax.php`): the nonce was read from `$_REQUEST`, which includes query-string parameters. A crafted link could trigger authenticated CRUD actions without a form submission. The nonce is now read exclusively from `$_POST`.

## [1.6] - 2026-02-23

### Added

- **Interactive settings migration** (`migrate.php`): upgrading from v1.5 or earlier is detected automatically. Authenticated admin users are redirected to a dedicated migration page that explains the storage change, lists the legacy `fl_*` options found in `yourls_options`, and offers two actions: move the settings to `frontend_settings` and continue, or decline and deactivate the plugin.
- **Settings cache**: all plugin settings are loaded from `frontend_settings` in a single query per request and cached in memory. Subsequent reads within the same request skip the database entirely. Cache is invalidated on write and refreshed after table installation.

### Changed

- **Settings storage**: all plugin configuration (`display_mode`, `active_theme`, `redirect_https`, `robots_txt_path`, etc.) is now stored exclusively in the `frontend_settings` table. Previously these values lived in `yourls_options` (the table shared with YOURLS core and other plugins), which caused naming conflicts and required a separate query per setting per page load.

### Fixed

- **Redirect loop** when a non-authenticated user visits the YOURLS admin while legacy `fl_*` options are present: the migration guard now skips unauthenticated requests instead of redirecting them to `migrate.php` (which would redirect back to `index.php` → infinite loop).
- **Login broken** after the auth guard was introduced: calling `yourls_is_valid_user()` on a `POST` login request during `plugins_loaded` consumed the form nonce before YOURLS's own auth handling ran, causing "Unauthorized action or expired link". The guard now exits immediately on `POST` requests so the login flow is never interrupted.

## [1.5] - 2026-02-23

### Added

- **robots.txt auto-generation** (auto mode only): the plugin creates a `robots.txt` at the document root alongside `index.php` and `.htaccess`. The file is deleted automatically when auto mode is disabled.
  - Lists every YOURLS short URL with a `# → destination` comment and an `Allow` or `Disallow` directive (configurable per-install).
  - Includes a `# Last updated: YYYY-MM-DD HH:MM:SS UTC` timestamp on every write.
  - Auto-regenerates on YOURLS link events (`add_new_link`, `delete_link`, `edit_link`); the file is only rewritten when content actually changes.
  - Conflict-safe: refuses to overwrite an existing `robots.txt` not created by this plugin (same marker-based strategy as `index.php`).
  - Path stored in `fl_robots_txt_path` YOURLS option for safe cleanup.
- **robots.txt admin UI**: status indicator (active path / not generated), Allow/Disallow toggle for short URL indexing with "Save & regenerate" button. Section visible only in auto mode.
- **Redirect rules in `.htaccess`** (auto mode, Apache only): optional redirect rules injected at the top of the generated `.htaccess` block, applied immediately on save.
  - **HTTP → HTTPS**: `RewriteCond %{HTTPS} off` → `https://%{HTTP_HOST}%{REQUEST_URI}` (301).
  - **WWW canonical**: direction auto-detected from `YOURLS_SITE` (www site → redirect non-www → www, non-www site → redirect www → non-www). Redirect target always uses the scheme from `YOURLS_SITE`, so `http://www.example.com` is correctly redirected to `https://example.com` without a double hop.
  - **Combined rule**: when both HTTPS and WWW are active, a single `301` handles all variants (e.g. `http://www.example.com` → `https://example.com` in one redirect).
  - Admin UI: HTTPS checkbox + WWW checkbox with canonical direction shown inline (`www.github.com/sangcent → github.com/sangcent — auto-detected from YOURLS_SITE`).

## [1.4] - 2026-02-19

### Added

- **Referrer tracking**: links on the home page now send the HTTP referrer (removed `noreferrer` from `rel`). Clicks originating from the link-in-bio page are logged with `?ref=fl-homepage` appended to the root URL, making them identifiable in YOURLS Traffic Sources. Clicks from other sources keep their original referrer unchanged.

### Fixed

- **Click counting broken**: the `redirect_shorturl` hook exited before YOURLS could call `yourls_update_clicks()` and `yourls_log_redirect()`, so no click was ever recorded since the plugin was installed. Replaced the single hook with a two-hook strategy: `redirect_shorturl` captures keyword and URL without exiting (letting YOURLS count the click naturally), then `pre_redirect` serves the branded page just before the HTTP `Location` header is sent.
- **Click counter not incremented** in `fl_serve_short_url()` (generated `index.php` path): `yourls_log_redirect()` was called (log table) but `yourls_update_clicks()` was missing (click counter column), so the counter stayed at 0.

## [1.3] - 2026-02-18

### Added

- **Theme system**: themes live in `themes/<slug>/` with a `theme.json` manifest, `templates/` (home, redirect, 404) and `assets/`. Active theme is selected from the Options panel in the admin.
- **Default theme**: minimal responsive design using CSS custom properties — automatically adapts to light/dark system preference via `prefers-color-scheme`.
- **Sangcent Original theme**: previous design preserved as a dedicated theme (dark glassmorphism with particle system).
- **Dark mode** for the default theme — all colors defined as CSS custom properties, a single `@media (prefers-color-scheme: dark)` block overrides the root variables, no CSS duplication.
- **Generator meta tag**: `<meta name="generator" content="Frontend Links 1.3 by github.com/sangcent">` injected via output buffering on every frontend page (home, redirect, 404), independent of the active theme.
- **PHP version & extensions** displayed in the Information panel with green/red status indicators (`fileinfo`, `curl`).
- **Uploads `.htaccess` auto-generation**: `fl_write_uploads_htaccess()` generates the security file at activation with the correct `RewriteBase` for root and subdirectory installs. No longer a static committed file.

### Changed

- `FL_VERSION` constant defined in `plugin.php` — single source of truth for the version number.
- `includes/themes.php` added to the plugin bootstrap.

### Fixed

- **PHP 8.5 compatibility**: replaced deprecated procedural `finfo_open()` / `finfo_close()` with OOP `new finfo()` (auto-freed). Removed deprecated `curl_close()` call (no-op since PHP 8.0).
- **`yourls_get_db()` context**: all 23 database calls now pass the required `"(read|write)-description"` context string, eliminating PHP notices that corrupted AJAX JSON responses.

## [1.2]

### Added

- Redirect page fetches OG metadata from target URL (image, type, description, theme-color, title) for accurate social link previews (Discord, Slack, Twitter, Facebook).
- Branded redirect interstitial page with target page metadata.
- Branded 404 error page.
- Feature toggles to disable branded redirect and/or 404 pages.
- Security: SVG sanitization (XSS prevention), SSRF protection on URL fetching, uploads directory lockdown.
- Comprehensive file header comments on all source files.

### Changed

- Admin page renamed to "Frontend Administration".
- Author in meta tags is now the shortener domain (e.g. `github.com/sangcent`).
- All CSS and JS externalized to separate asset files (CSP compliant with YOURLS 1.10+).
- New templates directory: `home.php`, `admin.php`, `redirect.php`, `404.php`.

### Fixed

- Stats links corrected to include YOURLS subdirectory when stripped by the short URL filter.

## [1.1]

### Added

- Subdirectory support: short URLs now resolve at the root domain.
- Auto mode generates `.htaccess` with rewrite rules for short URL resolution.

### Changed

- JSON-LD, canonical, and Open Graph URLs use the root domain (without subdirectory).
- Link URLs displayed in admin and frontend strip the YOURLS subdirectory.

## [1.0]

- Initial release.
