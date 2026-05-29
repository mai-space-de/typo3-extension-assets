# Static File Cache — Webserver Delivery

This document provides Apache and Nginx configuration snippets for serving cached static HTML files directly from the webserver, bypassing the PHP middleware for faster delivery.

The static file cache is stored at:
```
public/typo3temp/assets/mai_assets_static/{pageUid}_{languageUid}/index.html
public/typo3temp/assets/mai_assets_static/{pageUid}_{languageUid}/index.html.gz
public/typo3temp/assets/mai_assets_static/{pageUid}_{languageUid}/index.html.br
```

---

## Apache Configuration

### Option 1: .htaccess in the cache directory

Place a `.htaccess` file in `public/typo3temp/assets/mai_assets_static/.htaccess`:

```apache
ForceType text/html; charset=utf-8

<IfModule mod_rewrite.c>
	RewriteEngine On
	RewriteBase /typo3temp/assets/mai_assets_static/

	# Block direct .htaccess access
	RewriteCond %{ENV:REDIRECT_STATUS} ^$
	RewriteRule ^\.htaccess$ - [F,L]

	# Serve gzip variant if client accepts it and file exists
	RewriteCond %{HTTP:Accept-Encoding} gzip
	RewriteCond %{REQUEST_FILENAME}.gz -f
	RewriteRule ^(.+)$ $1.gz [QSA,L]

	# Serve brotli variant if client accepts it and file exists
	RewriteCond %{HTTP:Accept-Encoding} br
	RewriteCond %{REQUEST_FILENAME}.br -f
	RewriteRule ^(.+)$ $1.br [QSA,L]
</IfModule>

<IfModule mod_headers.c>
	# Set proper Content-Type for HTML files
	<FilesMatch "\.html?$">
		Header set Content-Type "text/html; charset=utf-8"
	</FilesMatch>

	# Set proper Content-Encoding for compressed variants
	<FilesMatch "\.br$">
		Header set Content-Encoding "br"
		Header set Content-Type "text/html; charset=utf-8"
	</FilesMatch>

	<FilesMatch "\.gz$">
		Header set Content-Encoding "gzip"
		Header set Content-Type "text/html; charset=utf-8"
	</FilesMatch>
</IfModule>
```

### Option 2: VirtualHost configuration (recommended for DDEV)

Add these rules to `.ddev/apache/apache-site.conf` (for both `<VirtualHost *:80>` and `<VirtualHost *:443>` sections):

```apache
# Static File Cache Delivery
# Serve cached HTML pages directly from the filesystem before PHP processing
<IfModule mod_rewrite.c>
	# Map incoming requests to the static file cache directory
	# Cache structure: typo3temp/assets/mai_assets_static/{pageUid}_{languageUid}/index.html
	
	# Build the cache path from page/language identifiers (requires TYPO3 routing first)
	# For now, we use a simpler approach: check if static file exists in the documented cache location
	
	<Directory "/var/www/html/public/">
		# Only enable static file serving if the cache directory exists
		RewriteCond %{DOCUMENT_ROOT}/typo3temp/assets/mai_assets_static -d
		
		# Check for brotli variant with Accept-Encoding: br
		RewriteCond %{HTTP:Accept-Encoding} br
		RewriteCond %{DOCUMENT_ROOT}/typo3temp/assets/mai_assets_static -d
		RewriteRule ^(.*)$ /typo3temp/assets/mai_assets_static/$1 [C]
		RewriteCond %{DOCUMENT_ROOT}%{REQUEST_URI}.br -f
		RewriteRule ^/typo3temp/assets/mai_assets_static/(.*)$ /typo3temp/assets/mai_assets_static/$1.br [L]
		Header append Vary "Accept-Encoding" env=!static_cached
		Header set Content-Encoding "br" env=static_cached
		
		# Check for gzip variant with Accept-Encoding: gzip
		RewriteCond %{HTTP:Accept-Encoding} gzip
		RewriteCond %{DOCUMENT_ROOT}/typo3temp/assets/mai_assets_static -d
		RewriteRule ^(.*)$ /typo3temp/assets/mai_assets_static/$1 [C]
		RewriteCond %{DOCUMENT_ROOT}%{REQUEST_URI}.gz -f
		RewriteRule ^/typo3temp/assets/mai_assets_static/(.*)$ /typo3temp/assets/mai_assets_static/$1.gz [L]
		Header append Vary "Accept-Encoding" env=!static_cached
		Header set Content-Encoding "gzip" env=static_cached
	</Directory>
</IfModule>
```

### Option 3: Main server configuration

For production, you may want to include this in your main Apache configuration rather than `.htaccess`:

```apache
<IfModule mod_rewrite.c>
	RewriteEngine On

	# Static File Cache: Check if a cached HTML file exists for this request
	# The cache directory structure is: typo3temp/assets/mai_assets_static/{pageUid}_{languageUid}/
	# For URL-based lookup: typo3temp/assets/mai_assets_static/{scheme}_{host}_{port}/{url-path}/
	
	# Define cache directory (adjust if staticFileCacheDir is overridden via extension config)
	RewriteRule ^(.+\.html?)$ - [E=STATIC_CACHE_DIR:typo3temp/assets/mai_assets_static]
	
	# Prefer brotli if client supports it
	RewriteCond %{HTTP:Accept-Encoding} br
	RewriteCond %{DOCUMENT_ROOT}%{ENV:STATIC_CACHE_DIR}$0/$1.br -f
	RewriteRule ^(.*)$ %{ENV:STATIC_CACHE_DIR}/$1.br [QSA,L,T=text/html]
	Header set Content-Encoding "br" env=!STATIC_FILE_HANDLED
	
	# Fall back to gzip
	RewriteCond %{HTTP:Accept-Encoding} gzip
	RewriteCond %{DOCUMENT_ROOT}%{ENV:STATIC_CACHE_DIR}$0/$1.gz -f
	RewriteRule ^(.*)$ %{ENV:STATIC_CACHE_DIR}/$1.gz [QSA,L,T=text/html]
	Header set Content-Encoding "gzip" env=!STATIC_FILE_HANDLED
	
	# Serve plain HTML if no compressed variant exists
	RewriteCond %{DOCUMENT_ROOT}%{ENV:STATIC_CACHE_DIR}$0/$1 -f
	RewriteRule ^(.*)$ %{ENV:STATIC_CACHE_DIR}/$1 [QSA,L,T=text/html]
</IfModule>
```

---

## Nginx Configuration

Add this to your Nginx `server` block (or include in a separate config file):

```nginx
# Static File Cache Delivery
# Serve cached HTML pages directly from the filesystem before PHP processing

set $static_cache_dir "typo3temp/assets/mai_assets_static";

# Try to serve static cache file with content encoding negotiation
location / {
	# First, try the original request against PHP
	# But before that, check if a static cache file exists
	
	# Prefer brotli variant
	if ($http_accept_encoding ~* br) {
		set $static_file "${static_cache_dir}/${request_uri}index.html.br";
		if (-f "$document_root/$static_file") {
			add_header Content-Encoding "br" always;
			add_header Vary "Accept-Encoding" always;
			add_header Content-Type "text/html; charset=utf-8" always;
			rewrite ^(.*)$ /$static_file last;
		}
	}
	
	# Fall back to gzip variant
	if ($http_accept_encoding ~* gzip) {
		set $static_file "${static_cache_dir}/${request_uri}index.html.gz";
		if (-f "$document_root/$static_file") {
			add_header Content-Encoding "gzip" always;
			add_header Vary "Accept-Encoding" always;
			add_header Content-Type "text/html; charset=utf-8" always;
			rewrite ^(.*)$ /$static_file last;
		}
	}
	
	# Check for plain HTML variant
	set $static_file "${static_cache_dir}/${request_uri}index.html";
	if (-f "$document_root/$static_file") {
		add_header Vary "Accept-Encoding" always;
		add_header Content-Type "text/html; charset=utf-8" always;
		rewrite ^(.*)$ /$static_file last;
	}
	
	# Fall through to PHP if no static cache file exists
	try_files $uri $uri/ /index.php?$args;
}

# Cache headers for static HTML files
location ~ ^/typo3temp/assets/mai_assets_static/ {
	# Allow the PHP middleware to handle cache expiration
	# Or implement your own expiration logic here
	expires 0;
	add_header Cache-Control "public, no-cache" always;
	add_header Vary "Accept-Encoding" always;
}
```

---

## DDEV Setup

For DDEV, the recommended approach is to use the PHP middleware (StaticFileServeMiddleware) by default, which provides:

1. **Fail-safe delivery** — falls back to PHP rendering if static file not found
2. **Proper cache key mapping** — handles page UID + language combinations correctly
3. **Content encoding negotiation** — automatically serves gzip/brotli variants
4. **Early Hints support** — includes HTTP/103 preload hints alongside the 200 response

To enable static file cache in DDEV:

```bash
cd packages/typo3-extension-assets

# In the TYPO3 Backend:
# 1. Admin Tools > Settings > Extension Configuration > mai_assets
# 2. Set "enableStaticFileCache" to 1
# 3. Optionally override "staticFileCacheDir" if needed

# Or in config/system/additional.php:
$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mai_assets'] = [
    'enableStaticFileCache' => 1,
];
```

Then warm up the cache:
```bash
ddev composer run:warmup
```

To verify the middleware is serving files:
```bash
# Enable debug headers:
# Set "debugHeaders" to 1 in Extension Configuration

# Then check response headers:
curl -I https://www.bgm-pulheim.org/
# Look for: X-Mai-Static-Cache: 1 (when serving from cache)
# Look for: X-Mai-Static-Ready: 1 (when page is ready to cache)
```

---

## Performance Characteristics

| Delivery Method | Latency | CPU | Notes |
|---|---|---|---|
| PHP Middleware | ~5-10ms | Low | Recommended for DDEV; handles complex cache key mapping |
| Web Server Rules | ~1-2ms | Very Low | Faster but requires correct path mapping; may miss page-ID-based cache keys |

---

## Troubleshooting

### Static cache not being served

1. **Verify cache is enabled:**
   ```bash
   ddev composer run:cache-warmup
   ```

2. **Check cache directory:**
   ```bash
   ls -la public/typo3temp/assets/mai_assets_static/
   ```

3. **Verify middleware is configured:**
   ```bash
   cat config/system/additional.php | grep -i staticfilecache
   ```

4. **Check debug headers:**
   ```bash
   curl -I https://www.bgm-pulheim.org/ | grep X-Mai-Static
   ```

### Web server rules not matching

1. **Verify cache directory exists in the correct location:**
   ```bash
   ls -la public/typo3temp/assets/mai_assets_static/
   ```

2. **Check that AllowOverride is enabled:**
   ```apache
   <Directory "/var/www/html/public/">
       AllowOverride All
   </Directory>
   ```

3. **Test rewrite conditions:**
   ```bash
   # In Apache:
   RewriteLog /tmp/rewrite.log
   RewriteLogLevel 9
   ```

4. **For Nginx, use access logs:**
   ```bash
   tail -f /var/log/nginx/access.log | grep typo3temp
   ```

---

## References

- [Static File Cache Implementation](../../../Classes/StaticFileCache/)
- [Middleware Implementation](../../../Classes/Middleware/StaticFileServeMiddleware.php)
- [Cache Directory Structure](../../../Classes/StaticFileCache/StaticFileCacheDirectory.php)
- [Apache mod_rewrite Documentation](https://httpd.apache.org/docs/current/mod/mod_rewrite.html)
- [Nginx Rewrite Module](https://nginx.org/en/docs/http/ngx_http_rewrite_module.html)
