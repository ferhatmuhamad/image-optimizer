# Ferhat Image Optimizer

A free, single-file WordPress plugin that automatically converts your uploaded JPG/PNG images to the WebP format and serves them to browsers that support it — all without any paid subscription or external API.

## Features

- **Auto-convert on upload** — every new JPEG/PNG is immediately converted to WebP using Imagick or GD (whichever is available).
- **Bulk convert** — convert your entire existing media library in one click with a live progress bar.
- **Re-Convert All (Force)** — re-generate WebP for every image, ignoring previously converted ones (useful after changing quality).
- **Cleanup Orphan WebP Files** — scan the uploads directory and delete `.webp` files whose original has been removed.
- **Statistics dashboard** — see at a glance how many images are converted, how many are pending, and the total bytes saved.
- **Two delivery modes** — choose how WebP files are served to browsers (see below).

## Delivery Modes

| Mode | How it works | Works on |
|------|-------------|----------|
| **Both** *(default)* | `.htaccess` rewrite **+** full-page HTML output buffer | Apache / LiteSpeed (htaccess) + any server (HTML) |
| **HTML Rewrite** | Wraps `<img>` tags in `<picture>` elements via an output buffer covering the whole page | Any server (Apache, Nginx, LiteSpeed) |
| **.htaccess Rewrite** | Transparently serves `.webp` when the browser sends `Accept: image/webp` | Apache / LiteSpeed only |
| **Off** | No automatic serving — images are still converted on upload | — |

### HTML Rewrite (Option A)

The plugin starts an output buffer on `template_redirect` and rewrites every `<img src="….(jpg|png)">` that has a corresponding `.webp` file on disk into a `<picture>` element:

```html
<picture>
  <source srcset="image.webp" type="image/webp">
  <source srcset="image.jpg"  type="image/jpeg">
  <img src="image.jpg" alt="…">
</picture>
```

This covers logos, headers, footers, widgets, theme images — **the entire rendered page**, not just post content.

### .htaccess Rewrite (Option B)

On Apache / LiteSpeed the plugin writes the following block between `# BEGIN Ferhat Image Optimizer` / `# END Ferhat Image Optimizer` markers in your root `.htaccess`:

```apache
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteCond %{HTTP_ACCEPT} image/webp
    RewriteCond %{DOCUMENT_ROOT}/$1.webp -f
    RewriteRule (wp-content/uploads/.+)\.(jpe?g|png)$ $1.webp [T=image/webp,L]
</IfModule>
<IfModule mod_headers.c>
    <FilesMatch "\.(jpe?g|png|webp)$">
        Header append Vary Accept
    </FilesMatch>
</IfModule>
AddType image/webp .webp
```

The rules are removed automatically when the plugin is deactivated.

#### Nginx

Nginx does not use `.htaccess`. If Nginx is detected, the settings page shows the equivalent server-block snippet:

```nginx
map $http_accept $webp_suffix {
    default   "";
    "~*image/webp" ".webp";
}

server {
    location ~* ^/wp-content/uploads/.+\.(png|jpe?g)$ {
        add_header Vary Accept;
        try_files $uri$webp_suffix $uri =404;
    }
}
```

## Requirements

- WordPress 5.0+
- PHP 7.4+
- **Imagick** extension with WebP support **or** **GD** library compiled with `imagewebp()` support

## Installation

1. Copy `ferhat-image-optimizer.php` to your `wp-content/plugins/` directory.
2. Activate the plugin from **Plugins → Installed Plugins**.
3. Go to **Settings → Image Optimizer** to configure quality, engine, and delivery mode.

## Settings

| Setting | Description |
|---------|-------------|
| **WebP Quality** | 1–100, default 80. Lower = smaller file, higher = better fidelity. |
| **Preferred Engine** | Auto (Imagick preferred), Imagick, or GD. |
| **Delivery Mode** | How WebP files are served (see table above). |
| **Convert on Upload** | Toggle auto-conversion for new uploads. |

## Troubleshooting

### Images on my site are still .jpg / .png

1. **Clear your page cache.** LiteSpeed Cache, W3 Total Cache, WP Super Cache, and similar plugins cache the HTML *before* the output buffer runs. Purge the cache after enabling or changing the delivery mode.
2. **Check the delivery mode.** If you are on Nginx, select **HTML Rewrite** (not `.htaccess`) as your delivery mode.
3. **Run Bulk Convert.** If images were uploaded before the plugin was activated, use the **Start Bulk Convert** button to generate `.webp` files for them.
4. **Confirm engine availability.** Check the *Engine Status* table on the settings page. If both Imagick and GD show "No", ask your host to enable one.

### .htaccess is not writable

The plugin settings page will display the rule block to paste manually when it cannot write `.htaccess`.

### Re-converting after quality change

Use **Re-Convert All (Force)** to regenerate `.webp` files with the new quality setting. Existing `.webp` files will be overwritten.

## Changelog

### 1.1.0
- Full-page HTML rewrite via output buffer (covers logos, headers, footers, widgets, theme images).
- New `.htaccess` delivery mode with automatic write/remove using WordPress `insert_with_markers`.
- Delivery mode setting (`picture` / `htaccess` / `both` / `off`) replaces the old checkbox.
- Statistics dashboard (total, converted, pending, bytes saved, engine).
- Re-Convert All (Force) button.
- Cleanup Orphan WebP Files button.
- Activation hook sets default options; deactivation hook removes `.htaccess` markers.

### 1.0.0
- Initial release — converts on upload, bulk convert, settings page.