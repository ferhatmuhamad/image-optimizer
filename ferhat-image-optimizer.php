<?php
/**
 * Plugin Name: Ferhat Image Optimizer
 * Plugin URI:  https://github.com/ferhatmuhamad/image-optimizer
 * Description: Convert JPG/PNG images to WebP and compress them. Free, self-hosted alternative to reSmush/TinyPNG/Squoosh. No API key required.
 * Version:     1.0.0
 * Author:      ferhatmuhamad
 * License:     GPL-2.0+
 * Text Domain: ferhat-image-optimizer
 */

if (!defined('ABSPATH')) {
    exit;
}

define('FIO_VERSION', '1.0.0');
define('FIO_PATH', plugin_dir_path(__FILE__));
define('FIO_URL', plugin_dir_url(__FILE__));

if (!function_exists('fio_delete_generated_file')) {
    function fio_delete_generated_file($path) {
        if (!$path || !file_exists($path)) {
            return;
        }

        if (!unlink($path)) {
            error_log(sprintf('Ferhat Image Optimizer: failed to delete generated file: %s', $path));
        }
    }
}

class Ferhat_Image_Optimizer {

    private $options;

    public function __construct() {
        $this->options = get_option('fio_settings', [
            'quality'      => 82,
            'auto_convert' => 1,
            'keep_original'=> 1,
            'serve_webp'   => 1,
            'engine'       => 'auto', // auto|gd|imagick
        ]);

        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'admin_assets']);

        if (!empty($this->options['auto_convert'])) {
            add_filter('wp_generate_attachment_metadata', [$this, 'on_upload'], 10, 2);
        }

        if (!empty($this->options['serve_webp'])) {
            add_filter('the_content', [$this, 'rewrite_content_images'], 99);
            add_filter('post_thumbnail_html', [$this, 'rewrite_content_images'], 99);
        }

        add_action('wp_ajax_fio_bulk_convert', [$this, 'ajax_bulk_convert']);
        add_action('wp_ajax_fio_get_images', [$this, 'ajax_get_images']);
        add_action('wp_ajax_fio_delete_webp', [$this, 'ajax_delete_webp']);
    }

    public function admin_menu() {
        add_options_page(
            'Ferhat Image Optimizer',
            'Image Optimizer',
            'manage_options',
            'ferhat-image-optimizer',
            [$this, 'render_settings_page']
        );
    }

    public function register_settings() {
        register_setting('fio_settings_group', 'fio_settings', [$this, 'sanitize_settings']);
    }

    public function sanitize_settings($input) {
        return [
            'quality'      => max(1, min(100, intval($input['quality'] ?? 82))),
            'auto_convert' => !empty($input['auto_convert']) ? 1 : 0,
            'keep_original'=> !empty($input['keep_original']) ? 1 : 0,
            'serve_webp'   => !empty($input['serve_webp']) ? 1 : 0,
            'engine'       => in_array($input['engine'] ?? 'auto', ['auto','gd','imagick'], true) ? $input['engine'] : 'auto',
        ];
    }

    public function admin_assets($hook) {
        if ($hook !== 'settings_page_ferhat-image-optimizer') {
            return;
        }
        wp_enqueue_script('jquery');
    }

    public function render_settings_page() {
        $opts = $this->options;
        $engine_status = $this->detect_engine_status();
        ?>
        <div class="wrap">
            <h1>🖼️ Ferhat Image Optimizer</h1>
            <p>Automatically convert JPG/PNG to <strong>WebP</strong>. Free, no API key required.</p>

            <h2 class="title">Engine Status</h2>
            <table class="widefat striped" style="max-width:600px">
                <tr><td><strong>GD Library</strong></td><td><?php echo $engine_status['gd'] ? '✅ Available (WebP: ' . ($engine_status['gd_webp'] ? 'Yes' : 'No') . ')' : '❌ Not installed'; ?></td></tr>
                <tr><td><strong>Imagick</strong></td><td><?php echo $engine_status['imagick'] ? '✅ Available (WebP: ' . ($engine_status['imagick_webp'] ? 'Yes' : 'No') . ')' : '❌ Not installed'; ?></td></tr>
            </table>

            <form method="post" action="options.php" style="margin-top:20px">
                <?php settings_fields('fio_settings_group'); ?>
                <table class="form-table">
                    <tr>
                        <th><label for="fio_quality">WebP Quality (1-100)</label></th>
                        <td>
                            <input type="number" id="fio_quality" name="fio_settings[quality]" min="1" max="100" value="<?php echo esc_attr($opts['quality']); ?>" />
                            <p class="description">Recommended 75–85. Lower values produce smaller files but reduced quality.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Engine</th>
                        <td>
                            <select name="fio_settings[engine]">
                                <option value="auto" <?php selected($opts['engine'], 'auto'); ?>>Auto (prefer Imagick)</option>
                                <option value="imagick" <?php selected($opts['engine'], 'imagick'); ?>>Imagick</option>
                                <option value="gd" <?php selected($opts['engine'], 'gd'); ?>>GD</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th>Auto Convert on Upload</th>
                        <td><label><input type="checkbox" name="fio_settings[auto_convert]" value="1" <?php checked($opts['auto_convert'], 1); ?> /> Automatically convert newly uploaded images</label></td>
                    </tr>
                    <tr>
                        <th>Keep Original</th>
                        <td><label><input type="checkbox" name="fio_settings[keep_original]" value="1" <?php checked($opts['keep_original'], 1); ?> /> Keep original files (recommended)</label></td>
                    </tr>
                    <tr>
                        <th>Serve WebP to Browsers</th>
                        <td><label><input type="checkbox" name="fio_settings[serve_webp]" value="1" <?php checked($opts['serve_webp'], 1); ?> /> Automatically serve WebP via &lt;picture&gt; tag</label></td>
                    </tr>
                </table>
                <?php submit_button('Save Settings'); ?>
            </form>

            <hr/>
            <h2>🔁 Bulk Convert Existing Images</h2>
            <p>Click the button below to convert all existing JPG/PNG images in the Media Library.</p>
            <button id="fio-start-bulk" class="button button-primary">Start Bulk Convert</button>
            <button id="fio-stop-bulk" class="button" style="display:none">Stop</button>

            <div id="fio-progress" style="margin-top:20px; display:none">
                <div style="background:#eee;border-radius:4px;overflow:hidden;height:24px;max-width:600px">
                    <div id="fio-bar" style="background:#2271b1;height:100%;width:0%;transition:width .3s;color:#fff;text-align:center;line-height:24px;font-size:12px">0%</div>
                </div>
                <p id="fio-status" style="margin-top:10px"></p>
                <div id="fio-log" style="max-height:300px;overflow:auto;background:#fff;border:1px solid #ddd;padding:10px;margin-top:10px;font-family:monospace;font-size:12px"></div>
            </div>
        </div>

        <script>
        jQuery(function($){
            let stop = false;
            let processed = 0, total = 0, saved = 0;

            $('#fio-start-bulk').on('click', function(){
                if (!confirm('Start converting all images? This process may take some time.')) return;
                stop = false;
                processed = 0; saved = 0;
                $('#fio-progress').show();
                $('#fio-log').empty();
                $('#fio-start-bulk').hide();
                $('#fio-stop-bulk').show();

                $.post(ajaxurl, { action: 'fio_get_images', _ajax_nonce: '<?php echo esc_js(wp_create_nonce('fio_nonce')); ?>' }, function(res){
                    if (!res.success) { alert('Failed to retrieve image list'); return; }
                    total = res.data.ids.length;
                    if (total === 0) { $('#fio-status').text('No images to process.'); return; }
                    processNext(res.data.ids);
                });
            });

            $('#fio-stop-bulk').on('click', function(){ stop = true; });

            function processNext(ids) {
                if (stop || ids.length === 0) {
                    $('#fio-status').html('<strong>Done!</strong> ' + processed + ' images processed. Total saved: ' + formatBytes(saved));
                    $('#fio-start-bulk').show();
                    $('#fio-stop-bulk').hide();
                    return;
                }
                const id = ids.shift();
                $.post(ajaxurl, {
                    action: 'fio_bulk_convert',
                    attachment_id: id,
                    _ajax_nonce: '<?php echo esc_js(wp_create_nonce('fio_nonce')); ?>'
                }, function(res){
                    processed++;
                    const pct = Math.round(processed / total * 100);
                    $('#fio-bar').css('width', pct + '%').text(pct + '%');
                    if (res.success) {
                        saved += res.data.saved || 0;
                        $('#fio-log').prepend('<div style="color:green">✓ ' + res.data.file + ' — saved ' + formatBytes(res.data.saved || 0) + '</div>');
                    } else {
                        $('#fio-log').prepend('<div style="color:#c00">✗ ID ' + id + ' — ' + (res.data && res.data.msg ? res.data.msg : 'failed') + '</div>');
                    }
                    $('#fio-status').text('Processing ' + processed + ' / ' + total);
                    processNext(ids);
                }).fail(function(){
                    processed++;
                    $('#fio-log').prepend('<div style="color:#c00">✗ ID ' + id + ' — request failed</div>');
                    processNext(ids);
                });
            }

            function formatBytes(b){
                if (b < 1024) return b + ' B';
                if (b < 1048576) return (b / 1024).toFixed(1) + ' KB';
                return (b / 1048576).toFixed(2) + ' MB';
            }
        });
        </script>
        <?php
    }

    private function detect_engine_status() {
        $imagick_available = extension_loaded('imagick');
        $imagick_webp = false;

        if ($imagick_available) {
            $imagick_webp = in_array('WEBP', (array) Imagick::queryFormats(), true);
        }

        return [
            'gd'           => extension_loaded('gd'),
            'gd_webp'      => extension_loaded('gd') && function_exists('imagewebp'),
            'imagick'      => $imagick_available,
            'imagick_webp' => $imagick_webp,
        ];
    }

    private function get_engine() {
        $status = $this->detect_engine_status();
        $pref = $this->options['engine'] ?? 'auto';
        if ($pref === 'imagick' && $status['imagick_webp']) {
            return 'imagick';
        }
        if ($pref === 'gd' && $status['gd_webp']) {
            return 'gd';
        }
        if ($status['imagick_webp']) {
            return 'imagick';
        }
        if ($status['gd_webp']) {
            return 'gd';
        }
        return false;
    }

    public function convert_to_webp($source_path) {
        if (!file_exists($source_path)) {
            return new WP_Error('not_found', 'Source file not found');
        }

        $info = pathinfo($source_path);
        $ext  = strtolower($info['extension'] ?? '');
        if (!in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
            return new WP_Error('unsupported', 'Only JPG/PNG supported');
        }

        $webp_path = $info['dirname'] . '/' . $info['filename'] . '.webp';
        $engine    = $this->get_engine();
        $quality   = intval($this->options['quality']);

        if (!$engine) {
            return new WP_Error('no_engine', 'No WebP-capable engine (GD/Imagick) found on server');
        }

        try {
            if ($engine === 'imagick') {
                $img = new Imagick($source_path);
                $img->setImageCompressionQuality($quality);
                $img->writeImage('webp:' . $webp_path);
                $img->clear();
                $img->destroy();
            } else {
                $img = null;
                switch ($ext) {
                    case 'jpg':
                    case 'jpeg':
                        $img = @imagecreatefromjpeg($source_path);
                        break;
                    case 'png':
                        $img = @imagecreatefrompng($source_path);
                        if ($img) {
                            imagepalettetotruecolor($img);
                            imagealphablending($img, true);
                            imagesavealpha($img, true);
                        }
                        break;
                }
                if (!$img) {
                    return new WP_Error('decode_fail', 'Failed to decode image');
                }
                imagewebp($img, $webp_path, $quality);
                imagedestroy($img);
            }
        } catch (Exception $e) {
            return new WP_Error('convert_fail', $e->getMessage());
        }

        if (!file_exists($webp_path)) {
            return new WP_Error('write_fail', 'WebP file not created');
        }

        return [
            'webp_path'     => $webp_path,
            'original_size' => filesize($source_path),
            'webp_size'     => filesize($webp_path),
            'saved'         => max(0, filesize($source_path) - filesize($webp_path)),
        ];
    }

    public function on_upload($metadata, $attachment_id) {
        $file = get_attached_file($attachment_id);
        if (!$file) {
            return $metadata;
        }

        $result = $this->convert_to_webp($file);
        if (!is_wp_error($result)) {
            update_post_meta($attachment_id, '_fio_webp', basename($result['webp_path']));
            update_post_meta($attachment_id, '_fio_saved', $result['saved']);
        }

        if (!empty($metadata['sizes'])) {
            $dir = dirname($file);
            foreach ($metadata['sizes'] as $size) {
                $size_path = $dir . '/' . $size['file'];
                if (file_exists($size_path)) {
                    $this->convert_to_webp($size_path);
                }
            }
        }

        return $metadata;
    }

    public function ajax_get_images() {
        check_ajax_referer('fio_nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error();
        }

        $q = new WP_Query([
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'post_mime_type' => ['image/jpeg', 'image/png'],
            'fields'         => 'ids',
            'posts_per_page' => -1,
            'meta_query'     => [
                [
                    'key'     => '_fio_webp',
                    'compare' => 'NOT EXISTS',
                ],
            ],
        ]);
        wp_send_json_success(['ids' => $q->posts]);
    }

    public function ajax_bulk_convert() {
        check_ajax_referer('fio_nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['msg' => 'forbidden']);
        }

        $id = intval($_POST['attachment_id'] ?? 0);
        if (!$id) {
            wp_send_json_error(['msg' => 'invalid id']);
        }

        $file = get_attached_file($id);
        if (!$file || !file_exists($file)) {
            wp_send_json_error(['msg' => 'file missing']);
        }

        $result = $this->convert_to_webp($file);
        if (is_wp_error($result)) {
            wp_send_json_error(['msg' => $result->get_error_message()]);
        }

        update_post_meta($id, '_fio_webp', basename($result['webp_path']));
        update_post_meta($id, '_fio_saved', $result['saved']);

        $meta = wp_get_attachment_metadata($id);
        if (!empty($meta['sizes'])) {
            $dir = dirname($file);
            foreach ($meta['sizes'] as $size) {
                $size_path = $dir . '/' . $size['file'];
                if (file_exists($size_path)) {
                    $this->convert_to_webp($size_path);
                }
            }
        }

        wp_send_json_success([
            'file'  => basename($file),
            'saved' => $result['saved'],
        ]);
    }

    public function ajax_delete_webp() {
        check_ajax_referer('fio_nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error();
        }
        $id = intval($_POST['attachment_id'] ?? 0);
        $file = get_attached_file($id);
        if ($file) {
            $webp = preg_replace('/\.(jpe?g|png)$/i', '.webp', $file);
            fio_delete_generated_file($webp);
        }
        delete_post_meta($id, '_fio_webp');
        delete_post_meta($id, '_fio_saved');
        wp_send_json_success();
    }

    public function rewrite_content_images($content) {
        if (empty($content) || is_admin()) {
            return $content;
        }

        return preg_replace_callback(
            '#<img([^>]+)src=["\']([^"\']+\.(?:jpe?g|png))["\']([^>]*)>#i',
            function($m) {
                $before = $m[1];
                $src    = $m[2];
                $after  = $m[3];

                $webp_url  = preg_replace('/\.(jpe?g|png)$/i', '.webp', $src);
                $webp_path = $this->url_to_path($webp_url);
                if (!$webp_path || !file_exists($webp_path)) {
                    return $m[0];
                }

                $img_tag = '<img' . $before . 'src="' . esc_url($src) . '"' . $after . '>';
                return '<picture>'
                    . '<source srcset="' . esc_url($webp_url) . '" type="image/webp">'
                    . $img_tag
                    . '</picture>';
            },
            $content
        );
    }

    private function url_to_path($url) {
        $uploads = wp_get_upload_dir();
        if (!empty($uploads['baseurl']) && strpos($url, $uploads['baseurl']) === 0) {
            return str_replace($uploads['baseurl'], $uploads['basedir'], $url);
        }
        $site = site_url();
        if (!empty($site) && strpos($url, $site) === 0) {
            return str_replace($site, untrailingslashit(ABSPATH), $url);
        }
        return false;
    }
}

new Ferhat_Image_Optimizer();

add_action('delete_attachment', function($id) {
    $file = get_attached_file($id);
    if ($file) {
        $webp = preg_replace('/\.(jpe?g|png)$/i', '.webp', $file);
        fio_delete_generated_file($webp);
    }
    $meta = wp_get_attachment_metadata($id);
    if ($file && !empty($meta['sizes'])) {
        $dir = dirname($file);
        foreach ($meta['sizes'] as $size) {
            $webp = preg_replace('/\.(jpe?g|png)$/i', '.webp', $dir . '/' . $size['file']);
            fio_delete_generated_file($webp);
        }
    }
});
