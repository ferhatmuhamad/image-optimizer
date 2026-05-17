<?php
/**
 * Plugin Name: Ferhat Image Optimizer
 * Plugin URI:  https://github.com/ferhatmuhamad/image-optimizer
 * Description: Converts uploaded images to WebP and serves them site-wide via HTML rewrite or .htaccess. Includes bulk convert, force re-convert, orphan cleanup, and a statistics dashboard.
 * Version:     1.1.0
 * Author:      Ferhat Muhamad
 * License:     GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: ferhat-image-optimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'FIO_VERSION', '1.1.0' );
define( 'FIO_PLUGIN_FILE', __FILE__ );

// ---------------------------------------------------------------------------
// Activation / Deactivation (must be outside the class)
// ---------------------------------------------------------------------------

register_activation_hook( __FILE__, 'fio_activate' );
register_deactivation_hook( __FILE__, 'fio_deactivate' );

function fio_activate() {
	$defaults = array(
		'quality'           => 80,
		'engine'            => 'auto',
		'delivery_mode'     => 'both',
		'convert_on_upload' => '1',
	);
	if ( ! get_option( 'fio_settings' ) ) {
		add_option( 'fio_settings', $defaults );
	}
}

function fio_deactivate() {
	require_once ABSPATH . 'wp-admin/includes/misc.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	$htaccess = get_home_path() . '.htaccess';
	if ( file_exists( $htaccess ) && is_writable( $htaccess ) ) {
		insert_with_markers( $htaccess, 'Ferhat Image Optimizer', array() );
	}
	delete_transient( 'fio_stats' );
}

// ---------------------------------------------------------------------------
// Main Plugin Class
// ---------------------------------------------------------------------------

class Ferhat_Image_Optimizer {

	/** @var array Current plugin options. */
	private $options = array();

	public function __construct() {
		$this->options = (array) get_option( 'fio_settings', array() );
		$this->init_hooks();
	}

	// -----------------------------------------------------------------------
	// Hook registration
	// -----------------------------------------------------------------------

	private function init_hooks() {
		// Conversion on upload / delete.
		add_action( 'add_attachment',    array( $this, 'on_upload' ) );
		add_action( 'delete_attachment', array( $this, 'delete_attachment' ) );

		// Admin UI.
		add_action( 'admin_menu',    array( $this, 'add_admin_menu' ) );
		add_action( 'admin_init',    array( $this, 'register_settings' ) );
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );

		// AJAX endpoints.
		add_action( 'wp_ajax_fio_bulk_convert',     array( $this, 'ajax_bulk_convert' ) );
		add_action( 'wp_ajax_fio_get_images',       array( $this, 'ajax_get_images' ) );
		add_action( 'wp_ajax_fio_get_images_force', array( $this, 'ajax_get_images_force' ) );
		add_action( 'wp_ajax_fio_delete_webp',      array( $this, 'ajax_delete_webp' ) );
		add_action( 'wp_ajax_fio_cleanup_orphans',  array( $this, 'ajax_cleanup_orphans' ) );
		add_action( 'wp_ajax_fio_refresh_stats',    array( $this, 'ajax_refresh_stats' ) );

		// Frontend delivery — Option A: output buffer over the full page.
		$mode = $this->get_delivery_mode();
		if ( in_array( $mode, array( 'picture', 'both' ), true ) ) {
			add_action( 'template_redirect', array( $this, 'start_output_buffer' ), 1 );
		}

		// React to settings changes to update .htaccess — Option B.
		add_action( 'update_option_fio_settings', array( $this, 'on_settings_update' ), 10, 2 );
		add_action( 'added_option',               array( $this, 'on_option_added' ), 10, 2 );
	}

	// -----------------------------------------------------------------------
	// Small helpers
	// -----------------------------------------------------------------------

	/**
	 * Return the active delivery mode, honouring the legacy 'serve_webp' flag.
	 */
	private function get_delivery_mode() {
		$opts = (array) get_option( 'fio_settings', array() );

		// Backward compat: old single checkbox.
		if ( isset( $opts['serve_webp'] ) && empty( $opts['serve_webp'] ) && ! isset( $opts['delivery_mode'] ) ) {
			return 'off';
		}

		return isset( $opts['delivery_mode'] ) ? (string) $opts['delivery_mode'] : 'both';
	}

	/**
	 * Convert an absolute URL to a local filesystem path.
	 *
	 * @param  string $url Absolute URL.
	 * @return string|false Filesystem path, or false if not mappable.
	 */
	public function url_to_path( $url ) {
		$url        = (string) strtok( (string) $url, '?' );
		$upload_dir = wp_upload_dir();
		$base_url   = $upload_dir['baseurl'];
		$base_dir   = $upload_dir['basedir'];

		if ( 0 === strpos( $url, $base_url ) ) {
			return str_replace( $base_url, $base_dir, $url );
		}

		$site_url = rtrim( site_url(), '/' );
		$doc_root = rtrim( ABSPATH, '/' );

		if ( 0 === strpos( $url, $site_url ) ) {
			return $doc_root . substr( $url, strlen( $site_url ) );
		}

		return false;
	}

	/**
	 * Return true when the server is Apache or LiteSpeed.
	 */
	private function is_apache_server() {
		$sw = isset( $_SERVER['SERVER_SOFTWARE'] )
			? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) )
			: '';
		return stripos( $sw, 'apache' ) !== false || stripos( $sw, 'litespeed' ) !== false;
	}

	// -----------------------------------------------------------------------
	// Engine detection
	// -----------------------------------------------------------------------

	/**
	 * Return availability status for each supported conversion engine.
	 *
	 * @return array { imagick: bool, gd: bool }
	 */
	public function detect_engine_status() {
		$status = array(
			'imagick' => false,
			'gd'      => false,
		);

		if ( extension_loaded( 'imagick' ) && class_exists( 'Imagick' ) ) {
			try {
				$im               = new Imagick();
				$formats          = $im->queryFormats( 'WEBP' );
				$status['imagick'] = ! empty( $formats );
			} catch ( Exception $e ) {
				$status['imagick'] = false;
			}
		}

		if ( extension_loaded( 'gd' ) && function_exists( 'imagewebp' ) ) {
			$status['gd'] = true;
		}

		return $status;
	}

	/**
	 * Return the engine key that will be used for conversion: 'imagick', 'gd', or 'none'.
	 */
	public function get_engine() {
		$opts      = (array) get_option( 'fio_settings', array() );
		$preferred = isset( $opts['engine'] ) ? (string) $opts['engine'] : 'auto';
		$status    = $this->detect_engine_status();

		if ( 'imagick' === $preferred && $status['imagick'] ) {
			return 'imagick';
		}
		if ( 'gd' === $preferred && $status['gd'] ) {
			return 'gd';
		}
		// Auto — prefer Imagick.
		if ( $status['imagick'] ) {
			return 'imagick';
		}
		if ( $status['gd'] ) {
			return 'gd';
		}
		return 'none';
	}

	// -----------------------------------------------------------------------
	// Image conversion
	// -----------------------------------------------------------------------

	/**
	 * Convert a JPG or PNG file to WebP.
	 *
	 * @param  string $source_path Absolute path to the source image.
	 * @param  int    $quality     WebP quality 1-100.
	 * @return array|WP_Error  On success: { path, original_size, webp_size, saved }.
	 */
	public function convert_to_webp( $source_path, $quality = 80 ) {
		$source_path = (string) $source_path;
		$quality     = max( 1, min( 100, (int) $quality ) );

		if ( ! file_exists( $source_path ) ) {
			return new WP_Error( 'file_not_found', 'Source file not found: ' . $source_path );
		}

		$engine = $this->get_engine();
		if ( 'none' === $engine ) {
			return new WP_Error( 'no_engine', 'No WebP-capable image engine found. Install Imagick or GD with WebP support.' );
		}

		// Build output path: replace .jpg/.jpeg/.png extension with .webp.
		$dest_path = (string) preg_replace( '/\.(jpe?g|png)$/i', '.webp', $source_path );
		if ( $dest_path === $source_path ) {
			return new WP_Error( 'invalid_type', 'Source file must be a JPG or PNG.' );
		}

		$original_size = (int) filesize( $source_path );

		if ( 'imagick' === $engine ) {
			try {
				$imagick = new Imagick( $source_path );
				$imagick->setImageFormat( 'WEBP' );
				$imagick->setImageCompressionQuality( $quality );
				$imagick->setOption( 'webp:lossless', 'false' );
				$imagick->writeImage( $dest_path );
				$imagick->destroy();
			} catch ( Exception $e ) {
				return new WP_Error( 'imagick_error', $e->getMessage() );
			}
		} else {
			// GD path.
			$ext   = strtolower( (string) pathinfo( $source_path, PATHINFO_EXTENSION ) );
			$image = null;

			if ( 'jpg' === $ext || 'jpeg' === $ext ) {
				$image = imagecreatefromjpeg( $source_path );
			} elseif ( 'png' === $ext ) {
				$image = imagecreatefrompng( $source_path );
				if ( $image ) {
					imagepalettetotruecolor( $image );
					imagealphablending( $image, true );
					imagesavealpha( $image, true );
				}
			}

			if ( ! $image ) {
				return new WP_Error( 'gd_create', 'GD failed to load source image.' );
			}

			$ok = imagewebp( $image, $dest_path, $quality );
			imagedestroy( $image );

			if ( ! $ok ) {
				return new WP_Error( 'gd_webp', 'GD imagewebp() failed.' );
			}
		}

		if ( ! file_exists( $dest_path ) ) {
			return new WP_Error( 'no_output', 'Conversion finished but no output file was created.' );
		}

		$webp_size = (int) filesize( $dest_path );
		$saved     = max( 0, $original_size - $webp_size );

		return array(
			'path'          => $dest_path,
			'original_size' => $original_size,
			'webp_size'     => $webp_size,
			'saved'         => $saved,
		);
	}

	/**
	 * Hook: convert newly uploaded images automatically.
	 *
	 * @param int $attachment_id
	 */
	public function on_upload( $attachment_id ) {
		$opts = (array) get_option( 'fio_settings', array() );

		// Respect the "convert on upload" toggle (default on).
		if ( isset( $opts['convert_on_upload'] ) && '0' === (string) $opts['convert_on_upload'] ) {
			return;
		}

		$mime = (string) get_post_mime_type( $attachment_id );
		if ( ! in_array( $mime, array( 'image/jpeg', 'image/png' ), true ) ) {
			return;
		}

		$file = (string) get_attached_file( $attachment_id );
		if ( ! $file || ! file_exists( $file ) ) {
			return;
		}

		$quality = isset( $opts['quality'] ) ? (int) $opts['quality'] : 80;
		$result  = $this->convert_to_webp( $file, $quality );

		if ( ! is_wp_error( $result ) ) {
			update_post_meta( $attachment_id, '_fio_webp',  $result['path'] );
			update_post_meta( $attachment_id, '_fio_saved', $result['saved'] );
			delete_transient( 'fio_stats' );
		}
	}

	/**
	 * Hook: delete the sibling WebP when an attachment is removed.
	 *
	 * @param int $attachment_id
	 */
	public function delete_attachment( $attachment_id ) {
		$webp = (string) get_post_meta( $attachment_id, '_fio_webp', true );
		if ( $webp && file_exists( $webp ) ) {
			wp_delete_file( $webp );
		}
	}

	// -----------------------------------------------------------------------
	// Option A — Output buffer / full-page HTML rewrite
	// -----------------------------------------------------------------------

	/**
	 * Start an output buffer over the entire page response.
	 * The callback `rewrite_content_images` will process the HTML before it is sent.
	 */
	public function start_output_buffer() {
		if ( is_admin() ) {
			return;
		}
		if ( is_feed() ) {
			return;
		}
		if ( function_exists( 'is_robots' ) && is_robots() ) {
			return;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}
		if ( wp_doing_ajax() ) {
			return;
		}
		if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
			return;
		}

		ob_start( array( $this, 'rewrite_content_images' ) );
	}

	/**
	 * Replace `<img src="*.jpg/png">` with a `<picture>` element that includes
	 * a WebP `<source>` when the corresponding .webp file exists on disk.
	 *
	 * Existing `<picture>` blocks are left untouched.
	 *
	 * @param  string $content Full HTML string.
	 * @return string Modified HTML.
	 */
	public function rewrite_content_images( $content ) {
		if ( empty( $content ) ) {
			return (string) $content;
		}

		$original = $content;

		// ---- Pre-pass: protect existing <picture>…</picture> blocks --------
		$pictures = array();
		$content  = preg_replace_callback(
			'/<picture\b[^>]*>[\s\S]*?<\/picture>/i',
			function ( $m ) use ( &$pictures ) {
				$idx               = count( $pictures );
				$ph                = '<!--FIO_PIC_' . $idx . '-->';
				$pictures[ $ph ]   = $m[0];
				return $ph;
			},
			$content
		);

		if ( null === $content ) {
			return $original;
		}

		// ---- Rewrite <img> tags --------------------------------------------
		$upload_dir = wp_upload_dir();
		$base_url   = $upload_dir['baseurl'];
		$base_dir   = $upload_dir['basedir'];

		$result = preg_replace_callback(
			'/<img\b([^>]+?)(\s*\/)?>/is',
			function ( $m ) use ( $base_url, $base_dir ) {
				$img_tag = $m[0];
				$attrs   = $m[1];

				// Must have a src pointing to a JPG or PNG.
				if ( ! preg_match( '/\bsrc=(["\'])([^"\']+\.(?:jpe?g|png))\1/i', $attrs, $src_m ) ) {
					return $img_tag;
				}

				$src = $src_m[2];

				// Only rewrite images served from the uploads directory.
				if ( 0 !== strpos( $src, $base_url ) ) {
					return $img_tag;
				}

				$webp_url  = (string) preg_replace( '/\.(?:jpe?g|png)$/i', '.webp', $src );
				$webp_path = str_replace( $base_url, $base_dir, $webp_url );

				if ( ! file_exists( $webp_path ) ) {
					return $img_tag;
				}

				$orig_mime = preg_match( '/\.png$/i', $src ) ? 'image/png' : 'image/jpeg';

				return '<picture>'
					. '<source srcset="' . esc_url( $webp_url ) . '" type="image/webp">'
					. '<source srcset="' . esc_url( $src ) . '" type="' . $orig_mime . '">'
					. $img_tag
					. '</picture>';
			},
			$content
		);

		if ( null === $result ) {
			$result = $content;
		}

		// ---- Restore saved <picture> blocks --------------------------------
		if ( ! empty( $pictures ) ) {
			$result = str_replace( array_keys( $pictures ), array_values( $pictures ), $result );
		}

		return $result;
	}

	// -----------------------------------------------------------------------
	// Option B — .htaccess rule management
	// -----------------------------------------------------------------------

	/**
	 * Return the Apache/LiteSpeed rewrite rules as an array of lines.
	 *
	 * @return string[]
	 */
	private function get_htaccess_rules() {
		return array(
			'<IfModule mod_rewrite.c>',
			'    RewriteEngine On',
			'    RewriteCond %{HTTP_ACCEPT} image/webp',
			'    RewriteCond %{DOCUMENT_ROOT}/$1.webp -f',
			'    RewriteRule (wp-content/uploads/.+)\.(jpe?g|png)$ $1.webp [T=image/webp,L]',
			'</IfModule>',
			'<IfModule mod_headers.c>',
			'    <FilesMatch "\.(jpe?g|png|webp)$">',
			'        Header append Vary Accept',
			'    </FilesMatch>',
			'</IfModule>',
			'AddType image/webp .webp',
		);
	}

	/**
	 * Write (or remove) the .htaccess block, depending on the current delivery mode.
	 *
	 * @return bool|string true on success, 'nginx' if Nginx detected, 'not_writable' if file not writable.
	 */
	public function write_htaccess_rules() {
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';

		$htaccess = get_home_path() . '.htaccess';
		$mode     = $this->get_delivery_mode();

		if ( in_array( $mode, array( 'htaccess', 'both' ), true ) ) {
			if ( ! $this->is_apache_server() ) {
				return 'nginx';
			}
			if ( file_exists( $htaccess ) && ! is_writable( $htaccess ) ) {
				return 'not_writable';
			}
			if ( ! file_exists( $htaccess ) && ! is_writable( dirname( $htaccess ) ) ) {
				return 'not_writable';
			}
			return insert_with_markers( $htaccess, 'Ferhat Image Optimizer', $this->get_htaccess_rules() );
		}

		// Delivery mode is 'picture' or 'off' — remove any existing rules.
		if ( file_exists( $htaccess ) && is_writable( $htaccess ) ) {
			return insert_with_markers( $htaccess, 'Ferhat Image Optimizer', array() );
		}

		return false;
	}

	/**
	 * Called after `fio_settings` is updated in the database.
	 *
	 * @param mixed $old_value
	 * @param mixed $new_value
	 */
	public function on_settings_update( $old_value, $new_value ) {
		if ( is_array( $new_value ) ) {
			$this->options = $new_value;
		}
		$this->write_htaccess_rules();
		delete_transient( 'fio_stats' );
	}

	/**
	 * Called after any option is added for the first time; used to catch
	 * the very first save of `fio_settings`.
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  New value.
	 */
	public function on_option_added( $option, $value ) {
		if ( 'fio_settings' !== $option ) {
			return;
		}
		if ( is_array( $value ) ) {
			$this->options = $value;
		}
		$this->write_htaccess_rules();
		delete_transient( 'fio_stats' );
	}

	// -----------------------------------------------------------------------
	// Statistics
	// -----------------------------------------------------------------------

	/**
	 * Return stats array, cached in a transient for 5 minutes.
	 *
	 * @return array { total, converted, pending, saved, engine }
	 */
	private function get_stats() {
		$cached = get_transient( 'fio_stats' );
		if ( false !== $cached ) {
			return (array) $cached;
		}

		// Total JPG/PNG attachments.
		$total_q = new WP_Query( array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => array( 'image/jpeg', 'image/png' ),
			'fields'         => 'ids',
			'posts_per_page' => -1,
			'no_found_rows'  => false,
		) );
		$total = (int) $total_q->found_posts;

		// Attachments that already have a WebP conversion.
		$conv_q = new WP_Query( array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => array( 'image/jpeg', 'image/png' ),
			'fields'         => 'ids',
			'posts_per_page' => -1,
			'no_found_rows'  => false,
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'     => '_fio_webp',
					'compare' => 'EXISTS',
				),
			),
		) );
		$converted = (int) $conv_q->found_posts;

		// Sum of bytes saved.
		global $wpdb;
		$saved_raw = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT SUM(meta_value+0) FROM {$wpdb->postmeta} WHERE meta_key = '_fio_saved'"
		);
		$saved = $saved_raw ? (int) $saved_raw : 0;

		$stats = array(
			'total'     => $total,
			'converted' => $converted,
			'pending'   => max( 0, $total - $converted ),
			'saved'     => $saved,
			'engine'    => $this->get_engine(),
		);

		set_transient( 'fio_stats', $stats, 5 * MINUTE_IN_SECONDS );

		return $stats;
	}

	// -----------------------------------------------------------------------
	// Admin menu & settings registration
	// -----------------------------------------------------------------------

	public function add_admin_menu() {
		add_options_page(
			__( 'Ferhat Image Optimizer', 'ferhat-image-optimizer' ),
			__( 'Image Optimizer', 'ferhat-image-optimizer' ),
			'manage_options',
			'ferhat-image-optimizer',
			array( $this, 'render_settings_page' )
		);
	}

	public function register_settings() {
		register_setting(
			'fio_settings_group',
			'fio_settings',
			array( $this, 'sanitize_settings' )
		);
	}

	/**
	 * Sanitize and validate settings input.
	 *
	 * @param  array $input Raw POST data.
	 * @return array Sanitized options.
	 */
	public function sanitize_settings( $input ) {
		$input     = is_array( $input ) ? $input : array();
		$sanitized = array();

		$sanitized['quality'] = isset( $input['quality'] )
			? max( 1, min( 100, (int) $input['quality'] ) )
			: 80;

		$sanitized['engine'] = ( isset( $input['engine'] ) && in_array( $input['engine'], array( 'auto', 'imagick', 'gd' ), true ) )
			? $input['engine']
			: 'auto';

		$sanitized['delivery_mode'] = ( isset( $input['delivery_mode'] ) && in_array( $input['delivery_mode'], array( 'picture', 'htaccess', 'both', 'off' ), true ) )
			? $input['delivery_mode']
			: 'both';

		$sanitized['convert_on_upload'] = ! empty( $input['convert_on_upload'] ) ? '1' : '0';

		return $sanitized;
	}

	// -----------------------------------------------------------------------
	// Admin notices
	// -----------------------------------------------------------------------

	public function admin_notices() {
		$notice = get_transient( 'fio_admin_notice' );
		if ( $notice ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $notice ) . '</p></div>';
			delete_transient( 'fio_admin_notice' );
		}
	}

	// -----------------------------------------------------------------------
	// Settings page HTML
	// -----------------------------------------------------------------------

	/**
	 * Render the plugin settings page.
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$opts            = (array) get_option( 'fio_settings', array() );
		$quality         = isset( $opts['quality'] ) ? (int) $opts['quality'] : 80;
		$engine_pref     = isset( $opts['engine'] ) ? $opts['engine'] : 'auto';
		$delivery_mode   = $this->get_delivery_mode();
		$convert_upload  = isset( $opts['convert_on_upload'] ) ? $opts['convert_on_upload'] : '1';
		$engine_status   = $this->detect_engine_status();
		$current_engine  = $this->get_engine();
		$stats           = $this->get_stats();
		$nonce           = wp_create_nonce( 'fio_nonce' );

		$card_style = 'background:#fff;border:1px solid #ccd0d4;border-radius:4px;padding:20px 24px;'
			. 'min-width:140px;text-align:center;box-shadow:0 1px 3px rgba(0,0,0,.04);';
		$val_style  = 'font-size:2em;font-weight:700;';
		$lbl_style  = 'color:#555;margin-top:6px;font-size:.85em;';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Ferhat Image Optimizer', 'ferhat-image-optimizer' ); ?></h1>
			<p><?php esc_html_e( 'Automatically convert your images to WebP format to reduce file sizes and speed up your website.', 'ferhat-image-optimizer' ); ?></p>

			<!-- ── 1. Statistics dashboard ──────────────────────────────────── -->
			<h2 style="margin-top:24px;"><?php esc_html_e( 'Statistics', 'ferhat-image-optimizer' ); ?></h2>
			<div id="fio-stats-wrap" style="display:flex;gap:16px;flex-wrap:wrap;margin:12px 0 16px;">
				<div class="fio-stat-card" style="<?php echo esc_attr( $card_style ); ?>">
					<div class="fio-stat-val" style="<?php echo esc_attr( $val_style ); ?>color:#0073aa;"
						data-stat="total"><?php echo esc_html( number_format_i18n( $stats['total'] ) ); ?></div>
					<div style="<?php echo esc_attr( $lbl_style ); ?>"><?php esc_html_e( 'Total Images', 'ferhat-image-optimizer' ); ?></div>
				</div>
				<div class="fio-stat-card" style="<?php echo esc_attr( $card_style ); ?>">
					<div class="fio-stat-val" style="<?php echo esc_attr( $val_style ); ?>color:#46b450;"
						data-stat="converted"><?php echo esc_html( number_format_i18n( $stats['converted'] ) ); ?></div>
					<div style="<?php echo esc_attr( $lbl_style ); ?>"><?php esc_html_e( 'Converted', 'ferhat-image-optimizer' ); ?></div>
				</div>
				<div class="fio-stat-card" style="<?php echo esc_attr( $card_style ); ?>">
					<div class="fio-stat-val" style="<?php echo esc_attr( $val_style ); ?>color:#dc3232;"
						data-stat="pending"><?php echo esc_html( number_format_i18n( $stats['pending'] ) ); ?></div>
					<div style="<?php echo esc_attr( $lbl_style ); ?>"><?php esc_html_e( 'Pending', 'ferhat-image-optimizer' ); ?></div>
				</div>
				<div class="fio-stat-card" style="<?php echo esc_attr( $card_style ); ?>">
					<div class="fio-stat-val" style="<?php echo esc_attr( $val_style ); ?>color:#826eb4;"
						data-stat="saved_fmt"><?php echo esc_html( size_format( $stats['saved'] ) ); ?></div>
					<div style="<?php echo esc_attr( $lbl_style ); ?>"><?php esc_html_e( 'Bytes Saved', 'ferhat-image-optimizer' ); ?></div>
				</div>
				<div class="fio-stat-card" style="<?php echo esc_attr( $card_style ); ?>">
					<div class="fio-stat-val" style="font-size:1.3em;font-weight:700;color:#333;text-transform:uppercase;"
						data-stat="engine"><?php echo esc_html( $current_engine ); ?></div>
					<div style="<?php echo esc_attr( $lbl_style ); ?>"><?php esc_html_e( 'Engine', 'ferhat-image-optimizer' ); ?></div>
				</div>
			</div>
			<button type="button" id="fio-refresh-stats" class="button"
				data-nonce="<?php echo esc_attr( $nonce ); ?>">
				<?php esc_html_e( 'Refresh Stats', 'ferhat-image-optimizer' ); ?>
			</button>

			<hr style="margin:24px 0;">

			<!-- ── 2. Engine status ─────────────────────────────────────────── -->
			<h2><?php esc_html_e( 'Engine Status', 'ferhat-image-optimizer' ); ?></h2>
			<table class="widefat" style="max-width:440px;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Engine', 'ferhat-image-optimizer' ); ?></th>
						<th><?php esc_html_e( 'Available', 'ferhat-image-optimizer' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td>Imagick</td>
						<td><?php
							if ( $engine_status['imagick'] ) {
								echo '<span style="color:#46b450;">&#10004; ' . esc_html__( 'Yes', 'ferhat-image-optimizer' ) . '</span>';
							} else {
								echo '<span style="color:#dc3232;">&#10008; ' . esc_html__( 'No', 'ferhat-image-optimizer' ) . '</span>';
							}
						?></td>
					</tr>
					<tr>
						<td>GD Library</td>
						<td><?php
							if ( $engine_status['gd'] ) {
								echo '<span style="color:#46b450;">&#10004; ' . esc_html__( 'Yes', 'ferhat-image-optimizer' ) . '</span>';
							} else {
								echo '<span style="color:#dc3232;">&#10008; ' . esc_html__( 'No', 'ferhat-image-optimizer' ) . '</span>';
							}
						?></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Active Engine', 'ferhat-image-optimizer' ); ?></strong></td>
						<td><strong><?php echo esc_html( strtoupper( $current_engine ) ); ?></strong></td>
					</tr>
				</tbody>
			</table>

			<hr style="margin:24px 0;">

			<!-- ── 3. Settings form ─────────────────────────────────────────── -->
			<h2><?php esc_html_e( 'Settings', 'ferhat-image-optimizer' ); ?></h2>
			<form method="post" action="options.php">
				<?php settings_fields( 'fio_settings_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="fio_quality"><?php esc_html_e( 'WebP Quality', 'ferhat-image-optimizer' ); ?></label>
						</th>
						<td>
							<input type="number" id="fio_quality" name="fio_settings[quality]"
								value="<?php echo esc_attr( $quality ); ?>" min="1" max="100" class="small-text">
							<p class="description"><?php esc_html_e( '1–100. Recommended: 80.', 'ferhat-image-optimizer' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="fio_engine"><?php esc_html_e( 'Preferred Engine', 'ferhat-image-optimizer' ); ?></label>
						</th>
						<td>
							<select id="fio_engine" name="fio_settings[engine]">
								<option value="auto"    <?php selected( $engine_pref, 'auto' ); ?>><?php esc_html_e( 'Auto (Imagick preferred)', 'ferhat-image-optimizer' ); ?></option>
								<option value="imagick" <?php selected( $engine_pref, 'imagick' ); ?>>Imagick</option>
								<option value="gd"      <?php selected( $engine_pref, 'gd' ); ?>>GD</option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="fio_delivery"><?php esc_html_e( 'Delivery Mode', 'ferhat-image-optimizer' ); ?></label>
						</th>
						<td>
							<select id="fio_delivery" name="fio_settings[delivery_mode]">
								<option value="both"     <?php selected( $delivery_mode, 'both' ); ?>><?php esc_html_e( 'Both — HTML rewrite + .htaccess (recommended)', 'ferhat-image-optimizer' ); ?></option>
								<option value="picture"  <?php selected( $delivery_mode, 'picture' ); ?>><?php esc_html_e( 'HTML Rewrite only (picture tag, any server)', 'ferhat-image-optimizer' ); ?></option>
								<option value="htaccess" <?php selected( $delivery_mode, 'htaccess' ); ?>><?php esc_html_e( '.htaccess rewrite only (Apache / LiteSpeed)', 'ferhat-image-optimizer' ); ?></option>
								<option value="off"      <?php selected( $delivery_mode, 'off' ); ?>><?php esc_html_e( 'Off — do not serve WebP automatically', 'ferhat-image-optimizer' ); ?></option>
							</select>
							<p class="description">
								<?php esc_html_e( '"Both" uses an output buffer as a fallback when .htaccess is not honoured. ".htaccess" is fastest on Apache/LiteSpeed only.', 'ferhat-image-optimizer' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Convert on Upload', 'ferhat-image-optimizer' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="fio_settings[convert_on_upload]"
									value="1" <?php checked( $convert_upload, '1' ); ?>>
								<?php esc_html_e( 'Automatically convert new uploads to WebP', 'ferhat-image-optimizer' ); ?>
							</label>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save Settings', 'ferhat-image-optimizer' ) ); ?>
			</form>

			<hr style="margin:24px 0;">

			<!-- ── 4. Bulk operations ───────────────────────────────────────── -->
			<h2><?php esc_html_e( 'Bulk Convert Existing Images', 'ferhat-image-optimizer' ); ?></h2>
			<p>
				<?php esc_html_e( 'Convert all existing JPG/PNG images in your Media Library to WebP.', 'ferhat-image-optimizer' ); ?>
			</p>
			<div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:16px;align-items:center;">
				<button type="button" id="fio-bulk-start" class="button button-primary"
					data-nonce="<?php echo esc_attr( $nonce ); ?>">
					<?php esc_html_e( 'Start Bulk Convert', 'ferhat-image-optimizer' ); ?>
				</button>
				<button type="button" id="fio-bulk-force" class="button"
					data-nonce="<?php echo esc_attr( $nonce ); ?>"
					style="background:#f0ad4e;border-color:#eaa34a;color:#fff;">
					<?php esc_html_e( 'Re-Convert All (Force)', 'ferhat-image-optimizer' ); ?>
				</button>
				<button type="button" id="fio-cleanup-orphans" class="button button-secondary"
					data-nonce="<?php echo esc_attr( $nonce ); ?>">
					<?php esc_html_e( 'Cleanup Orphan WebP Files', 'ferhat-image-optimizer' ); ?>
				</button>
			</div>

			<!-- Shared progress bar -->
			<div id="fio-progress-wrap" style="display:none;margin-top:8px;">
				<div style="background:#e5e5e5;border-radius:3px;height:22px;overflow:hidden;margin-bottom:6px;">
					<div id="fio-progress-bar"
						style="background:#0073aa;height:100%;width:0%;transition:width .25s;"></div>
				</div>
				<p id="fio-progress-text" style="margin:2px 0;font-style:italic;color:#555;"></p>
			</div>
			<div id="fio-log"
				style="display:none;background:#f9f9f9;border:1px solid #ddd;border-radius:3px;
					padding:10px;max-height:260px;overflow-y:auto;
					font-size:12px;font-family:monospace;margin-top:8px;white-space:pre-wrap;"></div>

			<?php
			// ── 5. Server-config notice (Nginx / unwritable .htaccess) ─────
			if ( in_array( $delivery_mode, array( 'htaccess', 'both' ), true ) ) {
				$this->render_server_notice();
			}
			?>
		</div><!-- .wrap -->

		<?php $this->render_admin_js( $nonce ); ?>
		<?php
	}

	/**
	 * Output the inline JS that powers the admin page interactions.
	 *
	 * @param string $nonce WP nonce.
	 */
	private function render_admin_js( $nonce ) {
		$ajax_url     = esc_js( admin_url( 'admin-ajax.php' ) );
		$nonce_js     = esc_js( $nonce );
		$lbl_refresh  = esc_js( __( 'Refresh Stats',   'ferhat-image-optimizer' ) );
		$lbl_refreshing = esc_js( __( 'Refreshing…',   'ferhat-image-optimizer' ) );
		$lbl_starting = esc_js( __( 'Starting…',       'ferhat-image-optimizer' ) );
		$lbl_no_imgs  = esc_js( __( 'No images to convert.', 'ferhat-image-optimizer' ) );
		$lbl_confirm_force   = esc_js( __( 'This will re-convert ALL JPG/PNG images and overwrite existing WebP files. Continue?', 'ferhat-image-optimizer' ) );
		$lbl_confirm_orphans = esc_js( __( 'This will permanently delete .webp files whose original JPG/PNG is missing. Continue?', 'ferhat-image-optimizer' ) );
		$lbl_scanning = esc_js( __( 'Scanning for orphan WebP files…', 'ferhat-image-optimizer' ) );
		?>
		<script>
		/* global jQuery */
		(function( $ ) {
			var ajaxUrl = '<?php echo $ajax_url; // phpcs:ignore WordPress.Security.EscapeOutput ?>';
			var nonce   = '<?php echo $nonce_js; // phpcs:ignore WordPress.Security.EscapeOutput ?>';

			// ── Refresh Stats ──────────────────────────────────────────────
			$( '#fio-refresh-stats' ).on( 'click', function () {
				var $btn = $( this ).prop( 'disabled', true )
					.text( '<?php echo $lbl_refreshing; // phpcs:ignore WordPress.Security.EscapeOutput ?>' );
				$.post( ajaxUrl, { action: 'fio_refresh_stats', nonce: nonce }, function ( res ) {
					if ( res.success ) {
						var s = res.data;
						$( '[data-stat="total"]' ).text( s.total.toLocaleString() );
						$( '[data-stat="converted"]' ).text( s.converted.toLocaleString() );
						$( '[data-stat="pending"]' ).text( s.pending.toLocaleString() );
						$( '[data-stat="saved_fmt"]' ).text( s.saved_fmt );
						$( '[data-stat="engine"]' ).text( s.engine.toUpperCase() );
					}
				} ).always( function () {
					$btn.prop( 'disabled', false )
						.text( '<?php echo $lbl_refresh; // phpcs:ignore WordPress.Security.EscapeOutput ?>' );
				} );
			} );

			// ── Shared progress helpers ────────────────────────────────────
			function setProgress( done, total, msg ) {
				var pct = total > 0 ? Math.round( done / total * 100 ) : 0;
				$( '#fio-progress-bar' ).css( 'width', pct + '%' );
				$( '#fio-progress-text' ).text( ( msg || '' ) + ' (' + pct + '%)' );
			}

			function appendLog( msg ) {
				var $log = $( '#fio-log' );
				$log.append( msg + '\n' );
				$log[ 0 ].scrollTop = $log[ 0 ].scrollHeight;
			}

			function runBulk( ids, label ) {
				if ( ! ids.length ) {
					alert( '<?php echo $lbl_no_imgs; // phpcs:ignore WordPress.Security.EscapeOutput ?>' );
					return;
				}
				var total = ids.length;
				var done  = 0;
				$( '#fio-progress-wrap, #fio-log' ).show();
				$( '#fio-log' ).empty();
				setProgress( 0, total, '<?php echo $lbl_starting; // phpcs:ignore WordPress.Security.EscapeOutput ?>' );

				function next() {
					if ( ! ids.length ) {
						setProgress( total, total, label + ' complete: ' + done + '/' + total );
						$.post( ajaxUrl, { action: 'fio_refresh_stats', nonce: nonce }, function ( res ) {
							if ( res.success ) {
								var s = res.data;
								$( '[data-stat="total"]' ).text( s.total.toLocaleString() );
								$( '[data-stat="converted"]' ).text( s.converted.toLocaleString() );
								$( '[data-stat="pending"]' ).text( s.pending.toLocaleString() );
								$( '[data-stat="saved_fmt"]' ).text( s.saved_fmt );
							}
						} );
						return;
					}
					var id = ids.shift();
					done++;
					$.post(
						ajaxUrl,
						{ action: 'fio_bulk_convert', id: id, nonce: nonce },
						function ( res ) {
							var msg = res.success
								? ( '\u2713 #' + id + ' \u2014 ' + res.data.message )
								: ( '\u2717 #' + id + ' \u2014 ' + ( res.data || 'Error' ) );
							appendLog( msg );
							setProgress( done, total, done + '/' + total );
							next();
						}
					).fail( function () {
						appendLog( '\u2717 #' + id + ' \u2014 Request failed' );
						setProgress( done, total, done + '/' + total );
						next();
					} );
				}

				next();
			}

			// ── Start Bulk Convert ─────────────────────────────────────────
			$( '#fio-bulk-start' ).on( 'click', function () {
				var $btn = $( this ).prop( 'disabled', true );
				$.post( ajaxUrl, { action: 'fio_get_images', nonce: nonce }, function ( res ) {
					$btn.prop( 'disabled', false );
					if ( res.success ) { runBulk( res.data.ids, 'Bulk Convert' ); }
				} ).fail( function () { $btn.prop( 'disabled', false ); } );
			} );

			// ── Re-Convert All (Force) ─────────────────────────────────────
			$( '#fio-bulk-force' ).on( 'click', function () {
				if ( ! confirm( '<?php echo $lbl_confirm_force; // phpcs:ignore WordPress.Security.EscapeOutput ?>' ) ) { return; }
				var $btn = $( this ).prop( 'disabled', true );
				$.post( ajaxUrl, { action: 'fio_get_images_force', nonce: nonce }, function ( res ) {
					$btn.prop( 'disabled', false );
					if ( res.success ) { runBulk( res.data.ids, 'Re-Convert (Force)' ); }
				} ).fail( function () { $btn.prop( 'disabled', false ); } );
			} );

			// ── Cleanup Orphans ────────────────────────────────────────────
			$( '#fio-cleanup-orphans' ).on( 'click', function () {
				if ( ! confirm( '<?php echo $lbl_confirm_orphans; // phpcs:ignore WordPress.Security.EscapeOutput ?>' ) ) { return; }
				var $btn = $( this ).prop( 'disabled', true );
				$( '#fio-progress-wrap, #fio-log' ).show();
				$( '#fio-log' ).empty();
				$( '#fio-progress-text' ).text( '<?php echo $lbl_scanning; // phpcs:ignore WordPress.Security.EscapeOutput ?>' );
				$.post( ajaxUrl, { action: 'fio_cleanup_orphans', nonce: nonce }, function ( res ) {
					$btn.prop( 'disabled', false );
					if ( res.success ) {
						appendLog( res.data.message );
						$( '#fio-progress-text' ).text( res.data.message );
						$( '#fio-progress-bar' ).css( 'width', '100%' );
					} else {
						appendLog( 'Error: ' + ( res.data || 'Unknown error' ) );
						$( '#fio-progress-text' ).text( 'Error' );
					}
				} ).fail( function () {
					$btn.prop( 'disabled', false );
					$( '#fio-progress-text' ).text( 'Request failed' );
				} );
			} );

		}( jQuery ) );
		</script>
		<?php
	}

	/**
	 * Render server-specific .htaccess / Nginx notice inside the settings page.
	 */
	private function render_server_notice() {
		if ( ! $this->is_apache_server() ) {
			?>
			<div class="notice notice-info inline" style="margin-top:20px;">
				<p>
					<strong><?php esc_html_e( 'Nginx Detected', 'ferhat-image-optimizer' ); ?></strong>
					&mdash;
					<?php esc_html_e( 'The .htaccess delivery mode is Apache/LiteSpeed-only. Add the following to your Nginx server block:', 'ferhat-image-optimizer' ); ?>
				</p>
				<pre style="background:#f9f9f9;padding:12px;overflow:auto;border:1px solid #ddd;">map $http_accept $webp_suffix {
    default   "";
    "~*image/webp" ".webp";
}

server {
    # ... your existing config ...

    location ~* ^/wp-content/uploads/.+\.(png|jpe?g)$ {
        add_header Vary Accept;
        try_files $uri$webp_suffix $uri =404;
    }
}</pre>
			</div>
			<?php
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		$htaccess = get_home_path() . '.htaccess';
		if ( file_exists( $htaccess ) && ! is_writable( $htaccess ) ) {
			$rules_text = '# BEGIN Ferhat Image Optimizer' . PHP_EOL
				. implode( PHP_EOL, $this->get_htaccess_rules() ) . PHP_EOL
				. '# END Ferhat Image Optimizer';
			?>
			<div class="notice notice-warning inline" style="margin-top:20px;">
				<p>
					<strong><?php esc_html_e( '.htaccess Not Writable', 'ferhat-image-optimizer' ); ?></strong>
					&mdash;
					<?php esc_html_e( 'The plugin could not write to your .htaccess file. Please add these rules manually:', 'ferhat-image-optimizer' ); ?>
				</p>
				<pre style="background:#f9f9f9;padding:12px;overflow:auto;border:1px solid #ddd;"><?php echo esc_html( $rules_text ); ?></pre>
			</div>
			<?php
		}
	}

	// -----------------------------------------------------------------------
	// AJAX handlers
	// -----------------------------------------------------------------------

	/** Convert a single attachment to WebP. */
	public function ajax_bulk_convert() {
		check_ajax_referer( 'fio_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions.' );
		}

		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		if ( ! $id ) {
			wp_send_json_error( 'Invalid attachment ID.' );
		}

		$file = (string) get_attached_file( $id );
		if ( ! $file || ! file_exists( $file ) ) {
			wp_send_json_error( 'File not found on disk.' );
		}

		$opts    = (array) get_option( 'fio_settings', array() );
		$quality = isset( $opts['quality'] ) ? (int) $opts['quality'] : 80;
		$result  = $this->convert_to_webp( $file, $quality );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		update_post_meta( $id, '_fio_webp',  $result['path'] );
		update_post_meta( $id, '_fio_saved', $result['saved'] );
		delete_transient( 'fio_stats' );

		wp_send_json_success( array(
			'message' => sprintf(
				/* translators: %s: human-readable file size */
				__( 'Converted. Saved %s.', 'ferhat-image-optimizer' ),
				size_format( $result['saved'] )
			),
			'saved'   => $result['saved'],
		) );
	}

	/** Return IDs of unconverted JPG/PNG attachments. */
	public function ajax_get_images() {
		check_ajax_referer( 'fio_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions.' );
		}

		$q = new WP_Query( array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => array( 'image/jpeg', 'image/png' ),
			'fields'         => 'ids',
			'posts_per_page' => -1,
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'     => '_fio_webp',
					'compare' => 'NOT EXISTS',
				),
			),
		) );

		wp_send_json_success( array( 'ids' => $q->posts ) );
	}

	/** Return IDs of ALL JPG/PNG attachments (for force re-convert). */
	public function ajax_get_images_force() {
		check_ajax_referer( 'fio_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions.' );
		}

		$q = new WP_Query( array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => array( 'image/jpeg', 'image/png' ),
			'fields'         => 'ids',
			'posts_per_page' => -1,
		) );

		wp_send_json_success( array( 'ids' => $q->posts ) );
	}

	/** Delete the WebP for a single attachment. */
	public function ajax_delete_webp() {
		check_ajax_referer( 'fio_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions.' );
		}

		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		if ( ! $id ) {
			wp_send_json_error( 'Invalid attachment ID.' );
		}

		$webp = (string) get_post_meta( $id, '_fio_webp', true );
		if ( $webp && file_exists( $webp ) ) {
			wp_delete_file( $webp );
		}

		delete_post_meta( $id, '_fio_webp' );
		delete_post_meta( $id, '_fio_saved' );
		delete_transient( 'fio_stats' );

		wp_send_json_success( array( 'message' => __( 'WebP deleted.', 'ferhat-image-optimizer' ) ) );
	}

	/**
	 * Scan uploads dir for .webp files whose original JPG/PNG is missing, and
	 * delete them.
	 */
	public function ajax_cleanup_orphans() {
		check_ajax_referer( 'fio_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions.' );
		}

		$upload_dir = wp_upload_dir();
		$base_dir   = $upload_dir['basedir'];

		if ( ! is_dir( $base_dir ) ) {
			wp_send_json_error( 'Uploads directory not found.' );
		}

		$deleted  = 0;
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $base_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::LEAVES_ONLY
		);

		foreach ( $iterator as $file_info ) {
			if ( 'webp' !== strtolower( $file_info->getExtension() ) ) {
				continue;
			}

			$webp_path = $file_info->getRealPath();
			if ( ! $webp_path ) {
				continue;
			}

			$base_name    = (string) preg_replace( '/\.webp$/i', '', $webp_path );
			$has_original = (
				file_exists( $base_name . '.jpg' )  ||
				file_exists( $base_name . '.jpeg' ) ||
				file_exists( $base_name . '.png' )  ||
				file_exists( $base_name . '.JPG' )  ||
				file_exists( $base_name . '.JPEG' ) ||
				file_exists( $base_name . '.PNG' )
			);

			if ( ! $has_original ) {
				wp_delete_file( $webp_path );
				$deleted++;
			}
		}

		delete_transient( 'fio_stats' );

		wp_send_json_success( array(
			'message' => sprintf(
				/* translators: %d: number of deleted .webp files */
				_n(
					'Deleted %d orphan WebP file.',
					'Deleted %d orphan WebP files.',
					$deleted,
					'ferhat-image-optimizer'
				),
				$deleted
			),
			'deleted' => $deleted,
		) );
	}

	/** Clear the stats transient and return fresh numbers. */
	public function ajax_refresh_stats() {
		check_ajax_referer( 'fio_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions.' );
		}

		delete_transient( 'fio_stats' );
		$stats = $this->get_stats();

		wp_send_json_success( array(
			'total'     => $stats['total'],
			'converted' => $stats['converted'],
			'pending'   => $stats['pending'],
			'saved'     => $stats['saved'],
			'saved_fmt' => size_format( $stats['saved'] ),
			'engine'    => $stats['engine'],
		) );
	}
}

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------

new Ferhat_Image_Optimizer();
