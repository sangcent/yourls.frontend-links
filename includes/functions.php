<?php
/**
 * Frontend Links - Core Functions
 * =================================
 *
 * All server-side logic for the plugin:
 *   - Helpers (escape, URL parsing, base path stripping)
 *   - Avatar management (upload, rotate, restore, delete)
 *   - Custom icon CRUD (SVG + image upload)
 *   - URL normalization (short keyword → full URL)
 *   - Short URL resolution (keyword lookup → redirect or 404)
 *   - Redirect & 404 page serving (loads templates with variables)
 *   - Homepage file management (auto-generated index.php + .htaccess)
 *   - Settings / Sections / Links CRUD (DB operations)
 *
 * Database: Uses YOURLS DB connection (Aura.SQL / PDO).
 * All tables are prefixed with FL_TABLE_PREFIX ("frontend_").
 *
 * @see plugin.php   Entry point that loads this file
 * @see ajax.php     AJAX endpoint that calls these functions
 *
 * @package FrontendLinks
 */

if (!defined('YOURLS_ABSPATH'))
    die();

// ─── Helpers ────────────────────────────────────────────────

function fl_table(string $name): string
{
    return FL_TABLE_PREFIX . $name;
}

function fl_escape(string $str): string
{
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

/**
 * Check whether the HTTP referrer is our frontend links homepage.
 *
 * Compares the referrer's host against the root URL (scheme + host,
 * without the YOURLS subdirectory) and verifies the path is "/".
 * Used to set the referrer to the homepage URL in YOURLS click logs.
 */
function fl_referrer_is_homepage(): bool
{
    if (empty($_SERVER['HTTP_REFERER']))
        return false;
    $ref = parse_url($_SERVER['HTTP_REFERER']);
    $root = parse_url(fl_get_root_url());
    return ($ref['host'] ?? '') === ($root['host'] ?? '')
        && rtrim($ref['path'] ?? '/', '/') === '';
}

/**
 * Extract the path from YOURLS_SITE
 * E.g.: "https://example.com/yourls" → "/yourls"
 * E.g.: "https://example.com" → ""
 */
function fl_get_yourls_base_path(): string
{
    $parsed = parse_url(YOURLS_SITE);
    return rtrim($parsed['path'] ?? '', '/');
}

/**
 * Get the root URL (scheme + host + port) without the YOURLS subdirectory.
 * E.g.: "https://example.com/yourls" → "https://example.com"
 * E.g.: "https://example.com:8080/yourls" → "https://example.com:8080"
 */
function fl_get_root_url(): string
{
    $parsed = parse_url(YOURLS_SITE);
    $scheme = $parsed['scheme'] ?? 'https';
    $host = $parsed['host'] ?? '';
    $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';
    return $scheme . '://' . $host . $port;
}

/**
 * Strip the YOURLS subdirectory from a URL if present.
 * Only modifies URLs on the same host as YOURLS_SITE.
 * E.g.: "https://example.com/yourls/git" → "https://example.com/git"
 * External URLs are returned unchanged.
 */
function fl_strip_base_path(string $url): string
{
    $basePath = fl_get_yourls_base_path();
    if ($basePath === '')
        return $url;

    $parsedSite = parse_url(YOURLS_SITE);
    $parsed = parse_url($url);

    // Only strip if same host
    if (($parsed['host'] ?? '') !== ($parsedSite['host'] ?? ''))
        return $url;

    // Strip the base path if present
    $path = $parsed['path'] ?? '';
    if ($path === $basePath || str_starts_with($path, $basePath . '/')) {
        $newPath = substr($path, strlen($basePath));
        if ($newPath === '')
            $newPath = '/';
        $result = ($parsed['scheme'] ?? 'https') . '://' . $parsed['host'];
        if (isset($parsed['port']))
            $result .= ':' . $parsed['port'];
        $result .= $newPath;
        if (isset($parsed['query']))
            $result .= '?' . $parsed['query'];
        if (isset($parsed['fragment']))
            $result .= '#' . $parsed['fragment'];
        return $result;
    }

    return $url;
}

/**
 * Check if the plugin tables exist in the database.
 * Checks the return value of query() explicitly to handle PDO ERRMODE_SILENT
 * (common on shared hosts) where errors return false instead of throwing.
 */
function fl_tables_exist(): bool
{
    try {
        $db    = yourls_get_db('read-fl_tables_exist');
        $table = fl_table('settings');
        $stmt  = $db->query("SELECT 1 FROM `$table` LIMIT 1");
        return $stmt !== false;
    }
    catch (\Throwable $e) {
        return false;
    }
}

// ─── SVG Sanitization ───────────────────────────────────────

/**
 * Sanitize SVG content by removing dangerous elements and attributes.
 * Prevents stored XSS via <script> tags, event handlers, javascript: URLs,
 * and <foreignObject> (which can embed arbitrary HTML).
 */
function fl_sanitize_svg(string $svg): string
{
    // Remove <script> tags and their content
    $svg = preg_replace('#<script[^>]*>.*?</script>#is', '', $svg);
    // Remove event handlers (onclick, onload, onerror, etc.)
    $svg = preg_replace('#\s+on\w+\s*=\s*["\'][^"\']*["\']#is', '', $svg);
    $svg = preg_replace('#\s+on\w+\s*=\s*[^\s>]+#is', '', $svg);
    // Remove javascript: and data: URLs in href/xlink:href
    $svg = preg_replace('#(href|xlink:href)\s*=\s*["\'](?:javascript|data):[^"\']*["\']#is', '', $svg);
    // Remove <foreignObject> (can contain arbitrary HTML/JS)
    $svg = preg_replace('#<foreignObject[^>]*>.*?</foreignObject>#is', '', $svg);
    // Remove <use> with external references (can bypass CSP)
    $svg = preg_replace('#<use[^>]+href\s*=\s*["\']https?://[^"\']*["\'][^>]*/?\s*>#is', '', $svg);
    return $svg;
}

// ─── Avatar Management ──────────────────────────────────────
// Only 2 files are kept in the uploads folder:
//   fl_avatars_current.<ext>   → active avatar
//   fl_avatars_previous.<ext>  → previous avatar (restorable)

/**
 * Find an avatar file by prefix (without extension)
 * Returns the full path or null
 */
function fl_find_avatar_file(string $name): ?string
{
    if (!is_dir(FL_UPLOADS_DIR))
        return null;
    $files = glob(FL_UPLOADS_DIR . '/' . $name . '.*');
    return $files ? $files[0] : null;
}

/**
 * Delete an avatar file by prefix
 */
function fl_delete_avatar_file(string $name): bool
{
    $file = fl_find_avatar_file($name);
    if ($file && file_exists($file)) {
        return unlink($file);
    }
    return false;
}

/**
 * Clean all files in uploads folder
 * except fl_avatars_current.* and fl_avatars_previous.*
 */
function fl_cleanup_uploads(): void
{
    if (!is_dir(FL_UPLOADS_DIR))
        return;
    $files = glob(FL_UPLOADS_DIR . '/*');
    foreach ($files as $file) {
        if (is_file($file)) {
            $basename = pathinfo($file, PATHINFO_FILENAME);
            if ($basename !== 'fl_avatars_current' && $basename !== 'fl_avatars_previous') {
                unlink($file);
            }
        }
    }
}

/**
 * Generate (or regenerate) the uploads/.htaccess with the correct RewriteBase
 * for this installation (root or subdirectory). Called on activation and install.
 */
function fl_write_uploads_htaccess(): void
{
    $parsed = parse_url(FL_UPLOADS_URL);
    $basePath = rtrim($parsed['path'] ?? '', '/') . '/';

    $content = "# Frontend Links - Uploads security\n"
        . "# Auto-generated by the Frontend Links plugin. Do not edit manually.\n\n"
        . "Options -Indexes\n\n"
        . "<IfModule mod_rewrite.c>\n"
        . "    RewriteEngine On\n"
        . "    RewriteBase " . $basePath . "\n"
        . "    RewriteCond %{REQUEST_FILENAME} -f\n"
        . "    RewriteRule ^ - [L]\n"
        . "</IfModule>\n\n"
        . "<FilesMatch \"\\.php$\">\n"
        . "    Require all denied\n"
        . "</FilesMatch>\n\n"
        . "<IfModule mod_headers.c>\n"
        . "    <FilesMatch \"\\.svg$\">\n"
        . "        Header set Content-Security-Policy \"default-src 'none'; style-src 'unsafe-inline'\"\n"
        . "        Header set X-Content-Type-Options \"nosniff\"\n"
        . "    </FilesMatch>\n"
        . "</IfModule>\n";

    file_put_contents(FL_UPLOADS_DIR . '/.htaccess', $content);
}

/**
 * Upload a new avatar with rotation:
 *  1. Old "previous" is deleted
 *  2. "current" becomes "previous"
 *  3. New upload becomes "current"
 *  4. Orphan files are cleaned up
 *
 * Returns the URL of the new avatar or false on error
 */
function fl_upload_avatar(array $file): string|false
{
    if ($file['error'] !== UPLOAD_ERR_OK)
        return false;

    $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    if (!in_array($mime, $allowed))
        return false;
    if ($file['size'] > 2 * 1024 * 1024)
        return false;

    if (!is_dir(FL_UPLOADS_DIR)) {
        mkdir(FL_UPLOADS_DIR, 0755, true);
    }

    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/svg+xml' => 'svg',
    ];
    $ext = $extensions[$mime] ?? 'bin';

    // 1. Delete old previous
    fl_delete_avatar_file('fl_avatars_previous');

    // 2. Rename current → previous
    $currentFile = fl_find_avatar_file('fl_avatars_current');
    if ($currentFile) {
        $curExt = pathinfo($currentFile, PATHINFO_EXTENSION);
        rename($currentFile, FL_UPLOADS_DIR . '/fl_avatars_previous.' . $curExt);
    }

    // 3. Save new upload as current
    $dest = FL_UPLOADS_DIR . '/fl_avatars_current.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        return false;
    }

    // 4. Clean up orphans (migration from old unique-name system)
    fl_cleanup_uploads();

    return FL_UPLOADS_URL . '/fl_avatars_current.' . $ext;
}

/**
 * Restore previous avatar as current
 * Returns the restored URL or false
 */
function fl_restore_previous_avatar(): string|false
{
    $previousFile = fl_find_avatar_file('fl_avatars_previous');
    if (!$previousFile)
        return false;

    // Delete current
    fl_delete_avatar_file('fl_avatars_current');

    // Rename previous → current
    $ext = pathinfo($previousFile, PATHINFO_EXTENSION);
    rename($previousFile, FL_UPLOADS_DIR . '/fl_avatars_current.' . $ext);

    return FL_UPLOADS_URL . '/fl_avatars_current.' . $ext;
}

/**
 * Delete current avatar (previous remains available for restoration)
 */
function fl_delete_current_avatar(): bool
{
    return fl_delete_avatar_file('fl_avatars_current');
}

// ─── Custom Icons ───────────────────────────────────────────

/**
 * Retrieve all custom icons from DB
 * Uses static cache to avoid multiple queries per page
 */
function fl_get_custom_icons(): array
{
    static $cache = null;
    if ($cache !== null)
        return $cache;
    try {
        $db    = yourls_get_db('read-fl_get_custom_icons');
        $table = fl_table('icons');
        $stmt  = $db->query("SELECT * FROM `$table` ORDER BY name ASC");
        $cache = ($stmt !== false) ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    }
    catch (\Throwable $e) {
        $cache = [];
    }
    return $cache;
}

/**
 * Retrieve custom icons indexed by name (cached)
 */
function fl_get_custom_icons_indexed(): array
{
    static $indexed = null;
    if ($indexed !== null)
        return $indexed;
    $indexed = [];
    foreach (fl_get_custom_icons() as $icon) {
        $indexed[$icon['name']] = $icon;
    }
    return $indexed;
}

/**
 * Invalidate icon cache (after add/delete)
 */
function fl_invalidate_icons_cache(): void
{
    global $_fl_icons_cache_invalid;
    $_fl_icons_cache_invalid = true;
}

function fl_create_custom_icon(array $data): int|false
{
    $db = yourls_get_db('write-fl_create_custom_icon');
    $table = fl_table('icons');
    $stmt = $db->prepare("INSERT INTO `$table` (name, type, content) VALUES (?, ?, ?)");
    if ($stmt === false) return false;
    $success = $stmt->execute([
        $data['name'],
        $data['type'],
        $data['content']
    ]);
    return $success ? (int)$db->lastInsertId() : false;
}

function fl_delete_custom_icon(int $id): bool
{
    $db = yourls_get_db('write-fl_delete_custom_icon');
    $table = fl_table('icons');

    // Retrieve icon to delete image file if needed
    $stmt = $db->prepare("SELECT * FROM `$table` WHERE id = ?");
    if ($stmt !== false) {
        $stmt->execute([$id]);
        $icon = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($icon && $icon['type'] === 'image') {
            // basename() prevents path traversal if content is ever tampered
            $filePath = FL_ICONS_DIR . '/' . basename($icon['content']);
            if (file_exists($filePath)) {
                unlink($filePath);
            }
        }
    }

    $stmt = $db->prepare("DELETE FROM `$table` WHERE id = ?");
    return $stmt !== false && $stmt->execute([$id]);
}

/**
 * Upload an image for custom icon
 * Returns the filename or false
 */
function fl_upload_icon_image(array $file, string $name): string|false
{
    if ($file['error'] !== UPLOAD_ERR_OK)
        return false;

    $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    if (!in_array($mime, $allowed))
        return false;
    if ($file['size'] > 1024 * 1024)
        return false; // 1 MB max

    if (!is_dir(FL_ICONS_DIR)) {
        mkdir(FL_ICONS_DIR, 0755, true);
    }

    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/svg+xml' => 'svg',
    ];
    $ext = $extensions[$mime] ?? 'bin';

    $safeName = preg_replace('/[^a-z0-9_-]/', '', strtolower($name));
    if ($safeName === '')
        $safeName = uniqid('icon_');
    $filename = 'icon_' . $safeName . '.' . $ext;
    $dest = FL_ICONS_DIR . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        return false;
    }

    // Sanitize uploaded SVG files to prevent stored XSS
    if ($mime === 'image/svg+xml') {
        $svgContent = file_get_contents($dest);
        if ($svgContent !== false) {
            file_put_contents($dest, fl_sanitize_svg($svgContent));
        }
    }

    return $filename;
}

// ─── URL Normalization ──────────────────────────────────────

/**
 * Normalize a user-entered URL.
 * If the URL has no protocol (e.g.: "git", "mylink"),
 * it is transformed into a YOURLS short URL by prepending the domain.
 * The fl_shorturl_include_path option determines whether the YOURLS
 * subdirectory is included in the generated URL.
 *
 * Examples (YOURLS_SITE = "https://example.com/yourls"):
 *   "git"                  → "https://example.com/git"        (include_path = 0)
 *   "git"                  → "https://example.com/yourls/git" (include_path = 1)
 *   "https://github.com"   → "https://github.com"             (unchanged)
 *   "/page"                → "/page"                          (unchanged)
 */
function fl_normalize_url(string $url): string
{
    $url = trim($url);
    if ($url === '')
        return '';

    // If the URL already has a protocol or starts with / or #, don't modify
    if (preg_match('#^(https?://|ftp://|mailto:|tel:|/|\#)#i', $url)) {
        return $url;
    }

    // Build base URL from YOURLS_SITE
    $parsed = parse_url(YOURLS_SITE);
    $scheme = $parsed['scheme'] ?? 'https';
    $host = $parsed['host'] ?? '';
    $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';
    $baseUrl = $scheme . '://' . $host . $port;

    // Include YOURLS path if option is enabled
    $includePath = fl_get_setting('shorturl_include_path', '0');
    if ($includePath === '1') {
        $path = rtrim($parsed['path'] ?? '', '/');
        $baseUrl .= $path;
    }

    return $baseUrl . '/' . ltrim($url, '/');
}

// ─── Target URL Metadata ────────────────────────────────────

/**
 * Fetch OG metadata from a target URL.
 *
 * Extracts: <title>, og:image, og:type, og:description (or meta description),
 * and theme-color. Uses a short timeout to avoid blocking the redirect page.
 *
 * @return array{title:string, description:string, image:string, type:string, theme_color:string}
 */
function fl_fetch_target_meta(string $url): array
{
    $result = [
        'title' => '',
        'description' => '',
        'image' => '',
        'type' => '',
        'theme_color' => '',
    ];

    if (!function_exists('curl_init'))
        return $result;

    // SSRF protection: only allow http(s) and block private/reserved IPs
    $parsed = parse_url($url);
    if (!in_array($parsed['scheme'] ?? '', ['http', 'https']))
        return $result;
    $host = $parsed['host'] ?? '';
    if ($host === '')
        return $result;
    $ip = gethostbyname($host);
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
        return $result;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_TIMEOUT => 2,
        CURLOPT_CONNECTTIMEOUT => 1,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; FrontendLinks/' . FL_VERSION . '; +' . YOURLS_SITE . ')',
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $html = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if (!$html || $httpCode >= 400)
        return $result;

    // Only parse <head> for performance
    if (preg_match('#<head[^>]*>(.*?)</head>#is', $html, $m)) {
        $head = $m[1];
    }
    else {
        return $result;
    }

    // <title> tag
    if (preg_match('#<title[^>]*>(.*?)</title>#is', $head, $m)) {
        $result['title'] = html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
    }

    // Collect all <meta> tags into a property → content map
    $metas = [];
    preg_match_all('#<meta\s+([^>]+)>#is', $head, $tags);
    foreach ($tags[1] as $attrs) {
        $key = '';
        $content = '';
        if (preg_match('#(?:property|name)\s*=\s*["\']([^"\']+)["\']#i', $attrs, $a)) {
            $key = strtolower($a[1]);
        }
        if (preg_match('#content\s*=\s*["\']([^"\']*)["\']#i', $attrs, $a)) {
            $content = $a[1];
        }
        if ($key !== '' && $content !== '') {
            $metas[$key] = html_entity_decode($content, ENT_QUOTES, 'UTF-8');
        }
    }

    if (!empty($metas['og:description']))
        $result['description'] = $metas['og:description'];
    elseif (!empty($metas['description']))
        $result['description'] = $metas['description'];

    if (!empty($metas['og:image']))
        $result['image'] = $metas['og:image'];
    if (!empty($metas['og:type']))
        $result['type'] = $metas['og:type'];
    if (!empty($metas['theme-color']))
        $result['theme_color'] = $metas['theme-color'];

    return $result;
}

/**
 * Inject the plugin generator <meta> tag before </head>.
 * Applied to every frontend page via output buffering — theme-independent.
 */
function fl_inject_generator(string $html): string
{
    $tag = '<meta name="generator" content="Frontend Links ' . FL_VERSION . ' by Sangcent @ github.com/sangcent">';
    return str_replace('</head>', $tag . "\n</head>", $html);
}

// ─── Short URL Resolution ───────────────────────────────────

/**
 * Serve a short URL request (called from generated index.php).
 * - keyword not found → 404
 * - Otherwise → delegate to mini redirect page
 *
 * Note: Stats (keyword+) are only accessible via the YOURLS admin
 * subdirectory (e.g. /-/keyword+). Requests without the subdirectory
 * are intentionally NOT redirected to stats for security.
 */
function fl_serve_short_url(string $request): void
{
    // Stats format (keyword+) → 404 at root level
    if (str_contains($request, '+')) {
        fl_serve_404_page($request);
        exit;
    }

    $keyword = yourls_sanitize_keyword($request);
    $url = yourls_get_keyword_longurl($keyword);

    if (!$url) {
        fl_serve_404_page($request);
        exit;
    }

    // Normalize referrer to the canonical homepage URL if click came from our page.
    // Avoids raw domain variants (with/without trailing slash, http vs https)
    // and ensures YOURLS stats show a valid clickable URL.
    if (fl_referrer_is_homepage()) {
        $_SERVER['HTTP_REFERER'] = fl_get_root_url() . '/?ref=fl-homepage';
    }

    // Count and log the click (mirrors what yourls_redirect_shorturl() does)
    yourls_update_clicks($keyword);
    yourls_log_redirect($keyword);

    fl_serve_redirect_page($keyword, $url);
    exit;
}

/**
 * Render the branded mini redirect page with OG metadata.
 * Called either from fl_serve_short_url() or from the
 * redirect_shorturl hook in plugin.php.
 *
 * Template: templates/redirect.php (edit that file to customize)
 */
function fl_serve_redirect_page(string $keyword, string $url): void
{
    // If branded redirect page is disabled, do a direct redirect
    if (fl_get_setting('disable_redirect_page') === '1') {
        header('Location: ' . $url, true, 302);
        exit;
    }

    // Get title from YOURLS DB
    $db = yourls_get_db('read-fl_serve_redirect_page');
    $table = YOURLS_DB_TABLE_URL;
    $stmt      = $db->prepare("SELECT title FROM `$table` WHERE keyword = ? LIMIT 1");
    $linkTitle = $keyword;
    if ($stmt !== false) {
        $stmt->execute([$keyword]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $linkTitle = !empty($row['title']) ? $row['title'] : $keyword;
    }

    // Fetch OG metadata from the target page
    $targetMeta = fl_fetch_target_meta($url);

    // Template variables
    $shortUrl = fl_get_root_url() . '/' . $keyword;
    $settings = fl_tables_exist() ? fl_get_settings() : [];
    $authorName = $settings['profile_name'] ?? parse_url(YOURLS_SITE, PHP_URL_HOST);
    $e = 'fl_escape';

    // Meta tags: identity of the target page, authored by our domain
    $metaAuthor = parse_url(YOURLS_SITE, PHP_URL_HOST);
    $metaTitle = $targetMeta['title'] ?: $linkTitle;
    $metaDescription = $targetMeta['description'] ?: $linkTitle;
    $metaImage = $targetMeta['image'] ?: ($settings['profile_avatar'] ?? '');
    $metaType = $targetMeta['type'] ?: 'website';
    $metaThemeColor = $targetMeta['theme_color'];
    $twitterCard = $metaImage ? 'summary_large_image' : 'summary';

    // Clean URLs for display (strip protocol, query string, trailing slash)
    $cleanShort = preg_replace('#^https?://#', '', rtrim($shortUrl, '/'));
    $cleanDest = preg_replace('#^https?://#', '', preg_replace('/[?#].*$/', '', rtrim($url, '/')));

    // Assets URLs for external CSS/JS references
    $sharedAssetsUrl = yourls_plugin_url(FL_PLUGIN_DIR) . '/assets';
    $themeAssetsUrl = fl_get_theme_assets_url();
    $assetsUrl = $sharedAssetsUrl;

    header('Content-Type: text/html; charset=UTF-8');
    ob_start();
    require fl_get_theme_template('redirect.php');
    echo fl_inject_generator(ob_get_clean());
    exit;
}

/**
 * Render a branded 404 page.
 * Template: templates/404.php (edit that file to customize)
 */
function fl_serve_404_page(string $request): void
{
    // If branded 404 page is disabled, send a basic 404 and stop
    if (fl_get_setting('disable_404_page') === '1') {
        header($_SERVER['SERVER_PROTOCOL'] . ' 404 Not Found');
        exit;
    }

    $homeUrl = fl_get_root_url() . '/';
    $settings = fl_tables_exist() ? fl_get_settings() : [];
    $authorName = $settings['profile_name'] ?? parse_url(YOURLS_SITE, PHP_URL_HOST);
    $e = 'fl_escape';

    // Assets URLs for external CSS/JS references
    $sharedAssetsUrl = yourls_plugin_url(FL_PLUGIN_DIR) . '/assets';
    $themeAssetsUrl = fl_get_theme_assets_url();
    $assetsUrl = $sharedAssetsUrl;

    header($_SERVER['SERVER_PROTOCOL'] . ' 404 Not Found');
    header('Content-Type: text/html; charset=UTF-8');
    ob_start();
    require fl_get_theme_template('404.php');
    echo fl_inject_generator(ob_get_clean());
    exit;
}

// ─── Homepage File Management ───────────────────────────────

/**
 * Create an index.php file at the document root
 * to serve the links page in automatic mode.
 * The file contains a marker so it can be properly removed.
 *
 * Also creates a security index.php inside the YOURLS subdirectory
 * (if any) to redirect to admin.
 */
function fl_create_homepage_file(): array
{
    $yourlsBasePath = fl_get_yourls_base_path();

    if ($yourlsBasePath !== '') {
        // YOURLS is in a subdirectory: create index.php at the document root
        $segments = array_filter(explode('/', trim($yourlsBasePath, '/')));
        $docRoot = YOURLS_ABSPATH;
        for ($i = 0, $n = count($segments); $i < $n; $i++) {
            $docRoot = dirname($docRoot);
        }
    }
    else {
        // YOURLS is at the root: create index.php in YOURLS directory
        $docRoot = YOURLS_ABSPATH;
    }

    $filePath = rtrim($docRoot, '/\\') . '/index.php';

    // Use absolute path to load-yourls.php (no relative ./ ambiguity)
    $yourlsLoadPath = rtrim(YOURLS_ABSPATH, '/\\') . '/includes/load-yourls.php';
    // Normalize to forward slashes for cross-platform compatibility
    $yourlsLoadPath = str_replace('\\', '/', $yourlsLoadPath);

    $marker = '/* FRONTEND_LINKS_AUTO_GENERATED */';
    $content = "<?php\n"
        . "$marker\n"
        . "// Auto-generated file by the Frontend Links plugin.\n"
        . "// Do not modify - it will be deleted if you switch to manual mode.\n"
        . "require_once '$yourlsLoadPath';\n"
        . "\n"
        . "\$request = trim(parse_url(\$_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');\n"
        . "\n"
        . "if (\$request === '') {\n"
        . "    fl_render_page();\n"
        . "} else {\n"
        . "    fl_serve_short_url(\$request);\n"
        . "}\n";

    // Check if an index.php already exists and is not ours
    if (file_exists($filePath)) {
        $existing = file_get_contents($filePath);
        if ($existing === false || strpos($existing, $marker) === false) {
            return [
                'success' => false,
                'message' => yourls__('An index.php file already exists at the root and was not created by this plugin. Delete it manually or use manual mode.', 'frontend-links')
            ];
        }
    }

    if (file_put_contents($filePath, $content) === false) {
        return [
            'success' => false,
            'message' => yourls__('Unable to write the index.php file. Check folder permissions.', 'frontend-links')
        ];
    }

    // Store the path for later cleanup
    fl_update_setting('homepage_file_path', $filePath);

    // Create security redirect in YOURLS subdirectory (only if there is one)
    if ($yourlsBasePath !== '') {
        fl_create_yourls_root_index();
    }

    // Create .htaccess at the document root for short URL rewriting
    $htaccessResult = fl_create_root_htaccess($docRoot, $yourlsBasePath);

    // Create robots.txt at the document root
    fl_write_robots_txt();

    $msg = sprintf(yourls__('index.php file created: %s', 'frontend-links'), $filePath);
    if (!$htaccessResult['success']) {
        $msg .= ' ' . $htaccessResult['message'];
    }

    return [
        'success' => true,
        'message' => $msg
    ];
}

/**
 * Build Apache redirect rules for HTTP→HTTPS and www canonicalization.
 *
 * The www redirect direction is auto-detected from YOURLS_SITE:
 *   - YOURLS_SITE has no www  → redirect www.domain → domain
 *   - YOURLS_SITE has www     → redirect domain → www.domain
 *
 * When both options are active, they collapse into a single 301 to avoid
 * redirect chains (e.g. http://www.example.com → https://example.com in one hop).
 *
 * @param string $redirectHttps '1' to force HTTPS, '0' to skip
 * @param string $redirectWww   '1' to redirect alternate variant, '0' to skip
 */
function fl_build_redirect_rules(string $redirectHttps, string $redirectWww): string
{
    $httpsOn = $redirectHttps === '1';
    $wwwOn   = $redirectWww === '1';

    if (!$httpsOn && !$wwwOn) return '';

    $rootHost  = parse_url(fl_get_root_url(), PHP_URL_HOST) ?? '';
    $isWwwSite = str_starts_with($rootHost, 'www.');

    // Combined: both active → single 301 to canonical
    if ($httpsOn && $wwwOn) {
        if ($isWwwSite) {
            // Canonical = www.example.com → catch http OR non-www
            return "# Redirect: enforce HTTPS + www canonical\n"
                . "RewriteCond %{HTTPS} off [OR]\n"
                . "RewriteCond %{HTTP_HOST} !^www\\. [NC]\n"
                . "RewriteRule ^ https://" . $rootHost . "%{REQUEST_URI} [L,R=301]\n";
        }
        // Canonical = example.com → catch http OR www
        return "# Redirect: enforce HTTPS + non-www canonical\n"
            . "RewriteCond %{HTTPS} off [OR]\n"
            . "RewriteCond %{HTTP_HOST} ^www\\. [NC]\n"
            . "RewriteRule ^ https://" . $rootHost . "%{REQUEST_URI} [L,R=301]\n";
    }

    // www only → use canonical scheme from YOURLS_SITE
    // (e.g. YOURLS_SITE = https://example.com → http://www.example.com redirects to https://example.com)
    if ($wwwOn) {
        $scheme = parse_url(YOURLS_SITE, PHP_URL_SCHEME) ?? 'https';
        if ($isWwwSite) {
            return "# Redirect: non-www → www canonical\n"
                . "RewriteCond %{HTTP_HOST} !^www\\. [NC]\n"
                . "RewriteRule ^ " . $scheme . "://" . $rootHost . "%{REQUEST_URI} [L,R=301]\n";
        }
        return "# Redirect: www → non-www canonical\n"
            . "RewriteCond %{HTTP_HOST} ^www\\.(.+)$ [NC]\n"
            . "RewriteRule ^ " . $scheme . "://%1%{REQUEST_URI} [L,R=301]\n";
    }

    // HTTPS only
    return "# Redirect: HTTP → HTTPS\n"
        . "RewriteCond %{HTTPS} off\n"
        . "RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]\n";
}

/**
 * Create or update .htaccess at the document root to rewrite short URLs.
 *
 * Rules generated (in order):
 *  1. Optional redirect rules (HTTPS, www canonical) from plugin options
 *  2. YOURLS subdirectory passthrough (if subdirectory exists)
 *  3. Existing files/directories → serve as-is
 *  4. Everything else → index.php (Frontend Links handler)
 */
function fl_create_root_htaccess(string $docRoot, string $yourlsBasePath): array
{
    $htaccessPath = rtrim($docRoot, '/\\') . '/.htaccess';
    $marker = '# BEGIN Frontend Links';
    $markerEnd = '# END Frontend Links';

    // Build the rules
    $rules = "$marker\n"
        . "<IfModule mod_rewrite.c>\n"
        . "RewriteEngine On\n"
        . "RewriteBase /\n";

    // Redirect rules (HTTPS / www canonical)
    $redirectRules = fl_build_redirect_rules(
        fl_get_setting('redirect_https', '0'),
        fl_get_setting('redirect_www', '0')
    );
    if ($redirectRules !== '') {
        $rules .= $redirectRules . "\n";
    }

    // If YOURLS is in a subdirectory, add stats routing + passthrough
    if ($yourlsBasePath !== '') {
        // Trim leading slash for .htaccess pattern matching
        $pathForRegex = ltrim($yourlsBasePath, '/');

        // Let YOURLS handle its own subdirectory (admin, loader, stats, etc.)
        $rules .= "# Let YOURLS handle its subdirectory\n"
            . "RewriteRule ^" . $pathForRegex . "/ - [L]\n";
    }

    $rules .= "# Existing files/directories pass through\n"
        . "RewriteCond %{REQUEST_FILENAME} -f [OR]\n"
        . "RewriteCond %{REQUEST_FILENAME} -d\n"
        . "RewriteRule ^ - [L]\n"
        . "# Everything else -> Frontend Links handler\n"
        . "RewriteRule ^(.*)$ index.php [L]\n"
        . "</IfModule>\n"
        . "$markerEnd\n";

    // If .htaccess exists, replace our block or append
    if (file_exists($htaccessPath)) {
        $existing = file_get_contents($htaccessPath);
        if ($existing === false) {
            return [
                'success' => false,
                'message' => yourls__('Unable to read the existing .htaccess file. Check folder permissions.', 'frontend-links')
            ];
        }

        if (strpos($existing, $marker) !== false) {
            // Replace existing block
            $pattern = '/' . preg_quote($marker, '/') . '.*?' . preg_quote($markerEnd, '/') . '\n?/s';
            $content = preg_replace($pattern, $rules, $existing);
        }
        else {
            // Append our block
            $content = rtrim($existing) . "\n\n" . $rules;
        }
    }
    else {
        $content = $rules;
    }

    if (file_put_contents($htaccessPath, $content) === false) {
        return [
            'success' => false,
            'message' => yourls__('Unable to write the .htaccess file. Short URLs at the root may not work.', 'frontend-links')
        ];
    }

    fl_update_setting('htaccess_file_path', $htaccessPath);

    return ['success' => true, 'message' => ''];
}

/**
 * Create a security index.php inside the YOURLS subdirectory
 * that redirects to admin. Prevents directory listing and
 * secures the YOURLS root when the frontend page is at /.
 * Only creates the file if none exists or if it was created by us.
 */
function fl_create_yourls_root_index(): void
{
    $filePath = rtrim(YOURLS_ABSPATH, '/\\') . '/index.php';
    $marker = '/* FRONTEND_LINKS_YOURLS_REDIRECT */';

    // Don't overwrite existing files not created by us
    if (file_exists($filePath)) {
        $existing = file_get_contents($filePath);
        if ($existing === false || strpos($existing, $marker) === false) {
            return;
        }
    }

    $content = "<?php\n"
        . "$marker\n"
        . "header('Location: admin/');\n"
        . "exit;\n";

    if (file_put_contents($filePath, $content) !== false) {
        fl_update_setting('yourls_root_index_path', $filePath);
    }
}

/**
 * Delete the index.php files created by fl_create_homepage_file()
 * Only deletes if the files contain the plugin markers.
 */
function fl_delete_homepage_file(): array
{
    // Delete the document root index.php
    $filePath = fl_get_setting('homepage_file_path', '');

    if (!empty($filePath) && file_exists($filePath)) {
        $content = file_get_contents($filePath);
        $marker  = '/* FRONTEND_LINKS_AUTO_GENERATED */';

        if ($content === false || strpos($content, $marker) === false) {
            return [
                'success' => false,
                'message' => yourls__('The index.php file has been manually modified. Deletion cancelled for safety.', 'frontend-links')
            ];
        }

        if (!unlink($filePath)) {
            return [
                'success' => false,
                'message' => yourls__('Unable to delete the file. Check permissions.', 'frontend-links')
            ];
        }
    }

    fl_update_setting('homepage_file_path', '');

    // Delete the YOURLS subdirectory security index.php
    $yourlsIndexPath = fl_get_setting('yourls_root_index_path', '');
    if (!empty($yourlsIndexPath) && file_exists($yourlsIndexPath)) {
        $existing = file_get_contents($yourlsIndexPath);
        if ($existing !== false && strpos($existing, '/* FRONTEND_LINKS_YOURLS_REDIRECT */') !== false) {
            unlink($yourlsIndexPath);
        }
    }
    fl_update_setting('yourls_root_index_path', '');

    // Remove .htaccess rules
    fl_delete_root_htaccess();

    // Remove robots.txt
    fl_delete_robots_txt();

    return ['success' => true, 'message' => yourls__('index.php file deleted.', 'frontend-links')];
}

/**
 * Remove the Frontend Links rewrite block from the root .htaccess.
 * If the file only contains our block, delete it entirely.
 */
function fl_delete_root_htaccess(): void
{
    $htaccessPath = fl_get_setting('htaccess_file_path', '');
    if (empty($htaccessPath) || !file_exists($htaccessPath))
        return;

    $content   = file_get_contents($htaccessPath);
    $marker    = '# BEGIN Frontend Links';
    $markerEnd = '# END Frontend Links';

    if ($content === false || strpos($content, $marker) === false)
        return;

    // Remove our block
    $pattern = '/' . preg_quote($marker, '/') . '.*?' . preg_quote($markerEnd, '/') . '\n?/s';
    $cleaned = preg_replace($pattern, '', $content);
    $cleaned = trim($cleaned);

    if ($cleaned === '') {
        // File only contained our rules, delete it
        unlink($htaccessPath);
    }
    else {
        file_put_contents($htaccessPath, $cleaned . "\n");
    }

    fl_update_setting('htaccess_file_path', '');
}

// ─── robots.txt File Management ─────────────────────────────

/**
 * Build the robots.txt content.
 *
 * Lists all YOURLS short URLs under Disallow (they are redirect pages;
 * blocking them preserves crawl budget — Google indexes the destination
 * directly). A comment on each entry shows where the keyword redirects.
 */
function fl_build_robots_txt_content(): string
{
    $marker = '# FRONTEND_LINKS_ROBOTS_TXT';
    $yourlsBasePath = fl_get_yourls_base_path();

    $lines = [
        $marker,
        '# Auto-generated by Frontend Links v' . FL_VERSION,
        '# Do not modify — this file is managed by the Frontend Links plugin.',
        '# It will be regenerated or deleted when auto mode is disabled.',
        '# Last updated: ' . gmdate('Y-m-d H:i:s') . ' UTC',
        '',
        'User-agent: *',
        '',
        '# Homepage',
        'Allow: /',
        '',
        '# Admin & backend',
    ];

    if ($yourlsBasePath !== '') {
        $lines[] = 'Disallow: ' . $yourlsBasePath . '/';
    }
    else {
        $lines[] = 'Disallow: /admin/';
    }

    // Fetch all YOURLS short URLs
    try {
        $db    = yourls_get_db('read-fl_build_robots_txt');
        $table = YOURLS_DB_TABLE_URL;
        $stmt  = $db->query("SELECT keyword, url FROM `$table` ORDER BY keyword ASC");
        $rows  = ($stmt !== false) ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

        if (!empty($rows)) {
            $rule = fl_get_setting('robots_shorturl_index', 'disallow') === 'allow' ? 'Allow' : 'Disallow';
            $lines[] = '';
            $lines[] = '# Short URLs — these pages redirect to external destinations';
            foreach ($rows as $row) {
                $lines[] = '# → ' . $row['url'];
                $lines[] = $rule . ': /' . $row['keyword'];
            }
        }
    }
    catch (\Throwable $e) {
        // DB unavailable, skip short URLs section
    }

    $lines[] = '';
    return implode("\n", $lines) . "\n";
}

/**
 * Write the robots.txt to the document root (same directory as index.php).
 * Refuses to overwrite an existing file not created by this plugin.
 */
function fl_write_robots_txt(): array
{
    $homepagePath = fl_get_setting('homepage_file_path', '');
    if (empty($homepagePath)) {
        return ['success' => false, 'message' => yourls__('Auto mode index.php not found. Enable auto mode first.', 'frontend-links')];
    }

    $filePath = dirname($homepagePath) . '/robots.txt';
    $marker = '# FRONTEND_LINKS_ROBOTS_TXT';

    // Don't overwrite a robots.txt not created by us
    if (file_exists($filePath)) {
        $existing = file_get_contents($filePath);
        if ($existing === false || strpos($existing, $marker) === false) {
            return [
                'success' => false,
                'message' => yourls__('A robots.txt already exists and was not created by this plugin. Delete it manually to enable auto-generation.', 'frontend-links'),
            ];
        }
    }

    $content = fl_build_robots_txt_content();
    if (file_put_contents($filePath, $content) === false) {
        return [
            'success' => false,
            'message' => yourls__('Unable to write the robots.txt file. Check folder permissions.', 'frontend-links'),
        ];
    }

    fl_update_setting('robots_txt_path', $filePath);
    return [
        'success' => true,
        'message' => sprintf(yourls__('robots.txt generated: %s', 'frontend-links'), $filePath),
    ];
}

/**
 * Delete the robots.txt file if it was created by this plugin.
 */
function fl_delete_robots_txt(): void
{
    $filePath = fl_get_setting('robots_txt_path', '');
    if (empty($filePath) || !file_exists($filePath)) {
        fl_update_setting('robots_txt_path', '');
        return;
    }

    $content = file_get_contents($filePath);
    if ($content === false || strpos($content, '# FRONTEND_LINKS_ROBOTS_TXT') === false) {
        return; // Not ours (or unreadable) — leave it untouched
    }

    @unlink($filePath);
    fl_update_setting('robots_txt_path', '');
}

// ─── Settings ───────────────────────────────────────────────

/**
 * Load all settings from frontend_settings into a global cache.
 * Subsequent reads within the same request hit the cache — no extra queries.
 * Try/catch ensures the table-not-yet-created edge case (fresh install) is safe.
 */
function fl_get_settings(): array
{
    global $fl_settings_cache;
    if ($fl_settings_cache !== null)
        return $fl_settings_cache;
    try {
        $db    = yourls_get_db('read-fl_get_settings');
        $table = fl_table('settings');
        $stmt  = $db->query("SELECT setting_key, setting_value FROM `$table`");
        $fl_settings_cache = [];
        // $stmt can be false on ERRMODE_SILENT when the table doesn't exist yet
        if ($stmt !== false) {
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $fl_settings_cache[$row['setting_key']] = $row['setting_value'];
            }
        }
    }
    catch (\Throwable $e) {
        $fl_settings_cache = [];
    }
    return $fl_settings_cache;
}

/**
 * Return a single setting value from the cache.
 * Returns $default when the key is absent.
 */
function fl_get_setting(string $key, string $default = ''): string
{
    $settings = fl_get_settings();
    return isset($settings[$key]) ? (string)$settings[$key] : $default;
}

/**
 * Persist a setting and update the in-memory cache immediately.
 */
function fl_update_setting(string $key, string $value): bool
{
    global $fl_settings_cache;
    $db = yourls_get_db('write-fl_update_setting');
    $table = fl_table('settings');
    $stmt = $db->prepare("INSERT INTO `$table` (setting_key, setting_value) VALUES (?, ?)
                           ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    if ($stmt === false) return false;
    $result = $stmt->execute([$key, $value]);
    if ($result && $fl_settings_cache !== null) {
        $fl_settings_cache[$key] = $value;
    }
    return $result;
}

/**
 * Invalidate the settings cache so the next fl_get_settings() re-queries the DB.
 * Call after fl_install_tables() or any bulk write that bypasses fl_update_setting().
 */
function fl_invalidate_settings_cache(): void
{
    global $fl_settings_cache;
    $fl_settings_cache = null;
}

/**
 * One-time migration: copy fl_* options from yourls_options to frontend_settings.
 *
 * Runs at plugin load time. If fl_display_mode is no longer in yourls_options
 * (either never existed or was already migrated), exits immediately with no DB cost.
 * Uses INSERT IGNORE so existing frontend_settings values are never overwritten.
 */
function fl_maybe_migrate_options(): void
{
    // Fast-exit: migration already done (or fresh install — nothing to migrate)
    if (yourls_get_option('fl_display_mode') === false)
        return;

    // Create the tables if they were never created (upgrade without reactivation)
    if (!fl_tables_exist()) {
        require_once FL_PLUGIN_DIR . '/includes/install.php';
        fl_install_tables();
        // If still missing after creation attempt, bail safely
        if (!fl_tables_exist())
            return;
    }

    $db    = yourls_get_db('write-fl_maybe_migrate_options');
    $table = fl_table('settings');
    $keys  = [
        'display_mode', 'homepage_file_path', 'disable_redirect_page',
        'htaccess_version', 'robots_txt_path', 'active_theme',
        'shorturl_include_path', 'redirect_https', 'redirect_www',
        'robots_shorturl_index', 'disable_404_page', 'htaccess_file_path',
        'yourls_root_index_path',
    ];
    foreach ($keys as $key) {
        $value = yourls_get_option('fl_' . $key);
        if ($value !== false) {
            $stmt = $db->prepare("INSERT IGNORE INTO `$table` (setting_key, setting_value) VALUES (?, ?)");
            if ($stmt !== false) {
                $stmt->execute([$key, (string)$value]);
            }
            yourls_delete_option('fl_' . $key);
        }
    }
    fl_invalidate_settings_cache();
}

// ─── Sections ───────────────────────────────────────────────

function fl_get_sections(bool $activeOnly = true): array
{
    $db = yourls_get_db('read-fl_get_sections');
    $table = fl_table('sections');
    $sql = "SELECT * FROM `$table`";
    if ($activeOnly) {
        $sql .= " WHERE is_active = 1";
    }
    $sql .= " ORDER BY sort_order ASC";
    $stmt = $db->query($sql);
    return ($stmt !== false) ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

function fl_get_section(int $id): ?array
{
    $db    = yourls_get_db('read-fl_get_section');
    $table = fl_table('sections');
    $stmt  = $db->prepare("SELECT * FROM `$table` WHERE id = ?");
    if ($stmt === false) return null;
    $stmt->execute([$id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result ?: null;
}

function fl_create_section(array $data): int|false
{
    $db    = yourls_get_db('write-fl_create_section');
    $table = fl_table('sections');
    $stmt  = $db->prepare("INSERT INTO `$table` (section_key, title, sort_order, is_active)
                           VALUES (?, ?, ?, ?)");
    if ($stmt === false) return false;
    $success = $stmt->execute([
        $data['section_key'],
        $data['title'],
        $data['sort_order'] ?? 0,
        $data['is_active'] ?? 1
    ]);
    return $success ? (int)$db->lastInsertId() : false;
}

function fl_update_section(int $id, array $data): bool
{
    $db     = yourls_get_db('write-fl_update_section');
    $table  = fl_table('sections');
    $fields = [];
    $values = [];

    foreach (['section_key', 'title', 'sort_order', 'is_active'] as $field) {
        if (isset($data[$field])) {
            $fields[] = "`$field` = ?";
            $values[] = $data[$field];
        }
    }

    if (empty($fields))
        return false;

    $values[] = $id;
    $sql  = "UPDATE `$table` SET " . implode(', ', $fields) . " WHERE id = ?";
    $stmt = $db->prepare($sql);
    return $stmt !== false && $stmt->execute($values);
}

function fl_delete_section(int $id): bool
{
    $db    = yourls_get_db('write-fl_delete_section');
    $table = fl_table('sections');
    $stmt  = $db->prepare("DELETE FROM `$table` WHERE id = ?");
    return $stmt !== false && $stmt->execute([$id]);
}

// ─── Links ──────────────────────────────────────────────────

function fl_get_links(bool $activeOnly = true): array
{
    $db = yourls_get_db('read-fl_get_links');
    $links = fl_table('links');
    $sections = fl_table('sections');
    $sql = "SELECT l.*, s.section_key, s.title as section_title
            FROM `$links` l
            JOIN `$sections` s ON l.section_id = s.id";
    if ($activeOnly) {
        $sql .= " WHERE l.is_active = 1 AND s.is_active = 1";
    }
    $sql .= " ORDER BY s.sort_order ASC, l.sort_order ASC";
    $stmt = $db->query($sql);
    return ($stmt !== false) ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

function fl_get_link(int $id): ?array
{
    $db    = yourls_get_db('read-fl_get_link');
    $table = fl_table('links');
    $stmt  = $db->prepare("SELECT * FROM `$table` WHERE id = ?");
    if ($stmt === false) return null;
    $stmt->execute([$id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result ?: null;
}

function fl_get_links_by_section(int $sectionId, bool $activeOnly = true): array
{
    $db = yourls_get_db('read-fl_get_links_by_section');
    $table = fl_table('links');
    $sql = "SELECT * FROM `$table` WHERE section_id = ?";
    if ($activeOnly) {
        $sql .= " AND is_active = 1";
    }
    $sql .= " ORDER BY sort_order ASC";
    $stmt = $db->prepare($sql);
    if ($stmt === false) return [];
    $stmt->execute([$sectionId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function fl_create_link(array $data): int|false
{
    $db    = yourls_get_db('write-fl_create_link');
    $table = fl_table('links');
    $stmt  = $db->prepare("INSERT INTO `$table` (section_id, label, url, icon, sort_order, is_active)
                           VALUES (?, ?, ?, ?, ?, ?)");
    if ($stmt === false) return false;
    $success = $stmt->execute([
        $data['section_id'],
        $data['label'],
        $data['url'],
        $data['icon'] ?? 'globe',
        $data['sort_order'] ?? 0,
        $data['is_active'] ?? 1
    ]);
    return $success ? (int)$db->lastInsertId() : false;
}

function fl_update_link(int $id, array $data): bool
{
    $db     = yourls_get_db('write-fl_update_link');
    $table  = fl_table('links');
    $fields = [];
    $values = [];

    foreach (['section_id', 'label', 'url', 'icon', 'sort_order', 'is_active'] as $field) {
        if (isset($data[$field])) {
            $fields[] = "`$field` = ?";
            $values[] = $data[$field];
        }
    }

    if (empty($fields))
        return false;

    $values[] = $id;
    $sql  = "UPDATE `$table` SET " . implode(', ', $fields) . " WHERE id = ?";
    $stmt = $db->prepare($sql);
    return $stmt !== false && $stmt->execute($values);
}

function fl_delete_link(int $id): bool
{
    $db    = yourls_get_db('write-fl_delete_link');
    $table = fl_table('links');
    $stmt  = $db->prepare("DELETE FROM `$table` WHERE id = ?");
    return $stmt !== false && $stmt->execute([$id]);
}
