# Static File Cache — Webserver Configuration Examples

This directory contains ready-to-use webserver configurations for enabling direct serving of static cached HTML files, bypassing the PHP middleware for better performance.

## Files

### `StaticFileCache.htaccess`
**For:** Apache webservers (all environments)

A `.htaccess` file that provides content-encoding negotiation for cached HTML files.

**Installation:**
1. Copy `StaticFileCache.htaccess` to `public/typo3temp/assets/mai_assets_static/.htaccess`
2. Ensure `.htaccess` is enabled in your Apache configuration (`AllowOverride All`)
3. Static files will be served directly by Apache with automatic gzip/brotli negotiation

**What it does:**
- Detects when a client supports brotli and serves `.br` variants when available
- Falls back to gzip (`.gz`) variants for clients that don't support brotli
- Sets proper `Content-Encoding` headers for compressed variants
- Ensures correct `Content-Type` for all cached HTML files

---

### `ddev-apache-site.conf.example`
**For:** DDEV with Apache-FPM

A drop-in Apache configuration block that maps incoming requests to the static cache directory.

**Installation:**
1. Open `.ddev/apache/apache-site.conf`
2. Inside both `<VirtualHost *:80>` and `<VirtualHost *:443>` blocks, add the rules from the "INTEGRATION POINT" section (copy the rules between the special comments)
3. Restart DDEV: `ddev restart`
4. Verify: `curl -I https://www.bgm-pulheim.org/ | grep X-Mai-Static`

**What it does:**
- Builds the cache path dynamically from the request scheme, host, port, and URI
- Checks if a static cache file exists in `typo3temp/assets/mai_assets_static/`
- Serves the best available variant (brotli > gzip > plain HTML)
- Sets proper headers for content encoding and MIME type
- Falls back to the PHP middleware if no static file exists

**Why not just use `.htaccess`?**
The `.htaccess` approach requires the `.htaccess` file to be inside the cache directory itself, which works but is less elegant. The DDEV Apache configuration approach integrates with the main rewrite engine and provides cleaner path mapping.

---

### `ddev-nginx.conf.example`
**For:** DDEV with Nginx (if you switch from Apache-FPM)

A Nginx server block configuration for direct static file serving.

**Installation:**
1. Edit `.ddev/config.yaml` and change `webserver_type` to `nginx-fpm`
2. Create or edit `.ddev/nginx_full/server.conf`
3. Add the rules from this file into your server block
4. Restart DDEV: `ddev restart`

**What it does:**
- Uses Nginx variables to build the cache path from request details
- Implements content-encoding negotiation using `if` statements
- Serves cached files with proper headers
- Falls back to PHP via `try_files` directive

---

## Quick Start

### Option 1: Simple Setup (Recommended for DDEV)
Just enable the PHP middleware — it already handles everything:

```bash
# 1. Enable in Extension Configuration (TYPO3 Backend)
# Admin Tools > Settings > Extension Configuration > mai_assets
# Set: enableStaticFileCache = 1

# 2. Warm up the cache
ddev composer run:cache-warmup

# 3. Verify
curl -I https://www.bgm-pulheim.org/ | grep X-Mai-Static-Cache
```

The middleware is faster than you'd think and more reliable than webserver rules because it correctly handles page-ID-based cache keys.

### Option 2: Webserver Acceleration
If you want webserver-level serving for additional performance:

```bash
# Apache + DDEV:
# 1. Add rules from ddev-apache-site.conf.example to .ddev/apache/apache-site.conf
# 2. ddev restart
# 3. Enable extension configuration as in Option 1

# OR if using static .htaccess approach:
# 1. cp StaticFileCache.htaccess public/typo3temp/assets/mai_assets_static/.htaccess
# 2. ddev restart
# 3. Enable extension configuration as in Option 1
```

---

## How to Choose

| Approach | Best For | Pros | Cons |
|----------|----------|------|------|
| **PHP Middleware** | All environments | Works everywhere, handles complex cache keys correctly | ~5-10ms overhead (still fast) |
| **Apache .htaccess** | Development / simple setups | Simple, fast (~1-2ms), minimal config | Requires .htaccess in cache dir |
| **DDEV Apache** | DDEV development | Integrated, demonstrates URL mapping | More complex, requires manual integration |
| **Nginx** | Nginx environments | Fast, modern | Requires Nginx (not default in DDEV) |

---

## Troubleshooting

### Static files not being served

**Check 1: Is caching enabled?**
```bash
grep enableStaticFileCache config/system/additional.php
# Should output: enableStaticFileCache = 1
```

**Check 2: Are files being written to the cache directory?**
```bash
ls -la public/typo3temp/assets/mai_assets_static/
# Should show subdirectories with index.html files
```

**Check 3: Enable debug headers:**
In Extension Configuration, set `debugHeaders = 1`, then:
```bash
curl -I https://www.bgm-pulheim.org/ | grep X-Mai-Static
# Should show X-Mai-Static-Ready: 1 (if ready)
# Should show X-Mai-Static-Cache: 1 (if serving from cache)
```

### Apache rules not matching

**Check 1: Is .htaccess enabled?**
```bash
grep -A5 "Directory.*public" .ddev/apache/apache-site.conf
# Should have: AllowOverride All
```

**Check 2: Are RewriteRules being processed?**
```bash
# In apache-site.conf, enable debug logging:
RewriteLog /tmp/rewrite.log
RewriteLogLevel 9

# Then check the log:
ddev ssh
tail -f /tmp/rewrite.log | grep static
```

### Nginx path issues

**Check 1: Does the cache path variable match the file structure?**
```bash
ddev ssh
cd /var/www/html
# Check what paths are in the cache directory:
find public/typo3temp/assets/mai_assets_static -type f | head -5

# Verify the path structure matches what Nginx expects
```

---

## Performance Impact

| Scenario | Latency | Notes |
|----------|---------|-------|
| Cache miss → PHP rendering | ~50-150ms | Middleware overhead negligible |
| Cache hit via PHP middleware | ~10-15ms | Middleware reads file and returns it |
| Cache hit via Apache rules | ~2-5ms | Webserver serves directly |
| Cache hit via Nginx | ~2-5ms | Webserver serves directly |

**Bottom line:** The PHP middleware is plenty fast for DDEV. Web server acceleration is nice to have but not critical. Focus on cache warmup and keeping pages in the cache.

---

## References

- [Webserver Delivery Guide](../../../Documentation/StaticFileCacheWebserverDelivery.md)
- [StaticFileServeMiddleware Source](../../../Classes/Middleware/StaticFileServeMiddleware.php)
- [Cache Directory Structure](../../../Classes/StaticFileCache/StaticFileCacheDirectory.php)
- [Apache mod_rewrite Docs](https://httpd.apache.org/docs/current/mod/mod_rewrite.html)
- [Nginx Rewrite Module](https://nginx.org/en/docs/http/ngx_http_rewrite_module.html)
