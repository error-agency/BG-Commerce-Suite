<?php
/**
 * Helper to store and retrieve courier shipping label PDFs locally.
 * Solves standard browser access to APIs requiring custom authentication headers
 * by saving files in a private directory and serving them through an
 * authenticated administrative endpoint.
 *
 * @package BgCommerce3
 */

namespace BgCommerce3\Support;

defined( 'ABSPATH' ) || exit;

class Label_Pdf_Store {

	/**
	 * Регистрира защитеното административно изтегляне.
	 */
	public static function register() {
		add_action( 'admin_post_bgcs3_label_pdf', array( __CLASS__, 'download' ) );
	}

	/**
	 * Базова директория извън публичната uploads папка.
	 *
	 * @return string
	 */
	private static function base_dir() {
		return WP_CONTENT_DIR . '/bgcs-private/labels';
	}

	/**
	 * Save raw binary PDF content to a private local file.
	 *
	 * @param string $courier     Courier ID (e.g. 'boxnow').
	 * @param string $filename    Name of the file (e.g. '8871644226.pdf').
	 * @param string $binary_data Raw PDF file binary string.
	 * @return string|null Authenticated admin URL to the PDF on success, or null on failure.
	 */
	public static function save( $courier, $filename, $binary_data ) {
		$courier_slug = sanitize_key( $courier );
		$file_slug    = sanitize_file_name( $filename );
		if ( '' === $courier_slug || '' === $file_slug || 'pdf' !== strtolower( pathinfo( $file_slug, PATHINFO_EXTENSION ) ) ) {
			return null;
		}

		$base_dir = self::base_dir();
		$dir      = $base_dir . '/' . $courier_slug;
		if ( ! wp_mkdir_p( $dir ) ) {
			return null;
		}

		// Prevent directory listing in the labels storage directories.
		foreach ( array( $base_dir, $dir ) as $target_dir ) {
			$index_file = $target_dir . '/index.html';
			if ( ! file_exists( $index_file ) ) {
				@file_put_contents( $index_file, '' );
			}
			$htaccess_file = $target_dir . '/.htaccess';
			if ( ! file_exists( $htaccess_file ) ) {
				$htaccess_content = '<IfModule authz_core_module>' . PHP_EOL . '    Require all denied' . PHP_EOL . '</IfModule>' . PHP_EOL . '<IfModule !authz_core_module>' . PHP_EOL . '    Deny from all' . PHP_EOL . '</IfModule>' . PHP_EOL . 'Options -Indexes' . PHP_EOL;
				@file_put_contents( $htaccess_file, $htaccess_content );
			}
		}

		// Use an unpredictable on-disk name. Apache deny rules remain defense in
		// depth, but Nginx does not read .htaccess; a keyed prefix prevents a
		// shipment barcode from also being a guessable public file path.
		$storage_slug = self::storage_file_slug( $courier_slug, $file_slug );
		$filepath     = $dir . '/' . $storage_slug;

		// Save binary contents.
		if ( false === file_put_contents( $filepath, $binary_data ) ) {
			return null;
		}

		return wp_nonce_url(
			add_query_arg(
				array(
					'action'  => 'bgcs3_label_pdf',
					'courier' => $courier_slug,
					'file'    => $storage_slug,
					'name'    => $file_slug,
				),
				admin_url( 'admin-post.php' )
			),
			'bgcs3_label_pdf_' . $courier_slug . '_' . $storage_slug
		);
	}


	/**
	 * Stable, non-guessable storage filename for a label.
	 *
	 * The original basename is retained after a keyed prefix so old salts can be
	 * handled during deletion by a suffix match, while direct URL guessing still
	 * requires knowledge of the keyed prefix.
	 *
	 * @param string $courier_slug Sanitized courier slug.
	 * @param string $file_slug    Sanitized original PDF basename.
	 * @return string
	 */
	private static function storage_file_slug( $courier_slug, $file_slug ) {
		if ( function_exists( 'wp_salt' ) ) {
			$secret = (string) wp_salt( 'auth' );
		} elseif ( defined( 'AUTH_SALT' ) ) {
			$secret = (string) AUTH_SALT;
		} else {
			// WordPress normally always provides salts. This fallback still avoids a
			// barcode-only filename on unusually minimal bootstrap environments.
			$secret = defined( 'ABSPATH' ) ? (string) ABSPATH : __FILE__;
		}

		$prefix = substr( hash_hmac( 'sha256', $courier_slug . '|' . $file_slug, $secret ), 0, 24 );
		return $prefix . '-' . $file_slug;
	}

	/**
	 * Изпраща PDF етикет само на потребител с права за WooCommerce.
	 *
	 * Rule 70/181 — preview (по подразбиране) праща `Content-Disposition: inline`
	 * за вграждане/преглед в браузъра; `?mode=download` праща `attachment` за
	 * изричен download. Един endpoint обслужва и двата режима.
	 */
	public static function download() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to download this label.', 'bg-commerce-suite' ), '', array( 'response' => 403 ) );
		}
		$courier = isset( $_GET['courier'] ) ? sanitize_key( wp_unslash( $_GET['courier'] ) ) : '';
		$file    = isset( $_GET['file'] ) ? sanitize_file_name( wp_unslash( $_GET['file'] ) ) : '';
		$name    = isset( $_GET['name'] ) ? sanitize_file_name( wp_unslash( $_GET['name'] ) ) : $file;
		check_admin_referer( 'bgcs3_label_pdf_' . $courier . '_' . $file );
		$path = self::base_dir() . '/' . $courier . '/' . $file;
		if ( '' === $courier || '' === $file || ! is_file( $path ) || 'pdf' !== strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) ) {
			wp_die( esc_html__( 'The PDF label was not found.', 'bg-commerce-suite' ), '', array( 'response' => 404 ) );
		}
		if ( 'pdf' !== strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ) ) {
			$name = 'label.pdf';
		}
		$disposition = ( isset( $_GET['mode'] ) && 'download' === $_GET['mode'] ) ? 'attachment' : 'inline';
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: ' . $disposition . '; filename="' . $name . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Streams the validated local PDF response.
		exit;
	}

	/**
	 * Delete a locally stored shipping label.
	 *
	 * @param string $courier  Courier ID.
	 * @param string $filename Name of the file.
	 * @return bool True on success, false on failure or if file doesn't exist.
	 */
	public static function delete( $courier, $filename ) {
		$courier_slug = sanitize_key( $courier );
		$file_slug    = sanitize_file_name( $filename );
		$dir          = self::base_dir() . '/' . $courier_slug;
		$candidates   = array(
			$dir . '/' . self::storage_file_slug( $courier_slug, $file_slug ),
			$dir . '/' . $file_slug, // Legacy pre-2.30 filename.
		);

		// Salt rotation changes the keyed prefix. The original sanitized basename
		// remains as a suffix so cancellation can still clean such an older file.
		$rotated = glob( $dir . '/*-' . $file_slug );
		if ( is_array( $rotated ) ) {
			$candidates = array_merge( $candidates, $rotated );
		}

		$deleted = false;
		foreach ( array_unique( $candidates ) as $filepath ) {
			if ( is_file( $filepath ) ) {
				wp_delete_file( $filepath );
				if ( ! is_file( $filepath ) ) {
					$deleted = true;
				}
			}
		}

		return $deleted;
	}
}
