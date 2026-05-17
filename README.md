# Ferhat Image Optimizer

A free WordPress plugin that converts JPG/PNG images to WebP and compresses them.
Self-hosted alternative to reSmush, TinyPNG, and Squoosh — **no API key, no usage limits**.

## Features
- Auto-convert images on upload
- Bulk convert existing media library images (with progress bar)
- Automatically serve WebP to browsers via `<picture>` tag (with JPG/PNG fallback)
- Uses PHP GD or Imagick (whichever is available)
- Configurable WebP quality (1–100)
- Keeps original files (safe rollback)
- Cleans up WebP files when attachments are deleted

## Requirements
- WordPress 5.0+
- PHP 7.4+
- PHP extension `gd` (with WebP support) **or** `imagick` (with WebP support)

Engine status is shown on the settings page.

## Installation
1. Download or clone this repo into `wp-content/plugins/image-optimizer/`
2. Activate the plugin from **Plugins → Installed Plugins**
3. Go to **Settings → Image Optimizer** to configure and run bulk conversion

## Usage
- New uploads are converted automatically (if enabled).
- Use the **Start Bulk Convert** button to process existing images.
- Front-end images are automatically wrapped in a `<picture>` element so modern browsers receive the WebP and older browsers fall back to the original.

## License
GPL-2.0+
