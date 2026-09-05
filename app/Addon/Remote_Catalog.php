<?php
/**
 * Safe, metadata-only client for the public error.bg product catalog.
 *
 * Remote data can change catalog presentation, but it cannot register modules,
 * install plugins, execute code or alter runtime compatibility decisions.
 *
 * @package BgCommerce3
 */

namespace BgCommerce3\Addon;

defined( 'ABSPATH' ) || exit;

final class Remote_Catalog {

	const SCHEMA_VERSION  = 1;
	const FEED_URL        = 'https://error.bg/wp-json/error-catalog/v1/feed';
	const OPTION          = 'bgcs3_catalog_feed_state';
	const SYNC_HOOK       = 'bgcs3_sync_product_catalog';
	const GROUP           = 'bgcs3';
	const INTERVAL        = 3600;
	const FRESH_FOR       = 300;
	const MAX_BYTES       = 262144;
	const SCHEDULE_OPTION = 'bgcs3_catalog_schedule_interval';
	const REFRESH_ACTION  = 'bgcs3_refresh_product_catalog';
	const REFRESH_NONCE   = 'bgcs3_refresh_product_catalog';

	/** Register scheduled and manual refresh entry points. */
	public static function init() {
		add_action( self::SYNC_HOOK, array( __CLASS__, 'run_scheduled_refresh' ) );
		add_action( 'init', array( __CLASS__, 'maybe_schedule' ) );
		add_action( 'admin_post_' . self::REFRESH_ACTION, array( __CLASS__, 'handle_manual_refresh' ) );
	}

	/** Ensure the shared catalog is refreshed hourly with startup jitter. */
	public static function maybe_schedule() {
		if ( ! function_exists( 'as_has_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}

		$stored_interval = (int) get_option( self::SCHEDULE_OPTION, 0 );
		if ( self::INTERVAL !== $stored_interval && function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::SYNC_HOOK, array(), self::GROUP );
			update_option( self::SCHEDULE_OPTION, self::INTERVAL, false );
		}

		if ( ! as_has_scheduled_action( self::SYNC_HOOK, array(), self::GROUP ) ) {
			$delay = function_exists( 'wp_rand' ) ? wp_rand( 300, 3600 ) : 900;
			as_schedule_recurring_action( time() + $delay, self::INTERVAL, self::SYNC_HOOK, array(), self::GROUP );
		}
	}

	/** Refresh from the scheduled worker without affecting the stored good feed on failure. */
	public static function run_scheduled_refresh() {
		$result = self::refresh();
		if ( is_wp_error( $result ) && function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->warning(
				$result->get_error_message(),
				array(
					'source' => 'bgcs-product-catalog',
					'code'   => $result->get_error_code(),
				)
			);
		}
	}

	/** Capability- and nonce-protected manual refresh from the Dashboard. */
	public static function handle_manual_refresh() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission for this action.', 'bg-commerce-suite' ) );
		}
		check_admin_referer( self::REFRESH_NONCE );

		$result = self::refresh();
		$status = is_wp_error( $result ) ? 'failed' : ( isset( $result['status'] ) ? sanitize_key( (string) $result['status'] ) : 'updated' );
		$url    = add_query_arg(
			array(
				'page'            => 'bgcs3-settings',
				'tab'             => 'dashboard',
				'catalog_refresh' => $status,
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Fetch and persist one validated feed response.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function refresh() {
		$url = self::feed_url();
		if ( ! self::allowed_url( $url ) ) {
			return self::record_error( new \WP_Error( 'catalog_invalid_endpoint', __( 'The product catalog endpoint is not allowed.', 'bg-commerce-suite' ) ) );
		}

		$state   = self::state();
		$headers = array(
			'Accept'     => 'application/json',
			'User-Agent' => 'BG-Commerce-Suite-Catalog/' . self::SCHEMA_VERSION,
		);
		if ( ! empty( $state['etag'] ) ) {
			$headers['If-None-Match'] = (string) $state['etag'];
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'             => 5,
				'redirection'         => 0,
				'limit_response_size' => self::MAX_BYTES + 1,
				'reject_unsafe_urls'  => true,
				'sslverify'           => true,
				'headers'             => $headers,
			)
		);
		if ( is_wp_error( $response ) ) {
			return self::record_error( new \WP_Error( 'catalog_http_error', __( 'The product catalog could not be reached.', 'bg-commerce-suite' ) ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 304 === $code && ! empty( $state['payload'] ) ) {
			$state['last_attempt_at'] = time();
			$state['last_success_at'] = time();
			$state['last_error']      = '';
			update_option( self::OPTION, $state, false );
			return array( 'status' => 'unchanged' );
		}
		if ( 200 !== $code ) {
			return self::record_error( new \WP_Error( 'catalog_http_status', __( 'The product catalog returned an unexpected response.', 'bg-commerce-suite' ) ) );
		}

		$body = (string) wp_remote_retrieve_body( $response );
		if ( '' === $body || strlen( $body ) > self::MAX_BYTES ) {
			return self::record_error( new \WP_Error( 'catalog_invalid_size', __( 'The product catalog response has an invalid size.', 'bg-commerce-suite' ) ) );
		}

		$decoded = json_decode( $body, true, 32 );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
			return self::record_error( new \WP_Error( 'catalog_invalid_json', __( 'The product catalog response is not valid JSON.', 'bg-commerce-suite' ) ) );
		}

		$payload = self::normalize_payload( $decoded );
		if ( is_wp_error( $payload ) ) {
			return self::record_error( $payload );
		}

		$etag          = self::etag( wp_remote_retrieve_header( $response, 'etag' ) );
		$new_state     = array(
			'payload'         => $payload,
			'etag'            => $etag,
			'last_attempt_at' => time(),
			'last_success_at' => time(),
			'last_error'      => '',
		);
		update_option( self::OPTION, $new_state, false );

		return array(
			'status'   => 'updated',
			'revision' => $payload['revision'],
		);
	}

	/**
	 * Validate and normalize the complete v1 feed before it can replace cache.
	 *
	 * @param array<string,mixed> $data Decoded JSON.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function normalize_payload( array $data ) {
		if ( ! isset( $data['schema_version'] ) || ! is_int( $data['schema_version'] ) || self::SCHEMA_VERSION !== $data['schema_version'] ) {
			return new \WP_Error( 'catalog_schema_version', __( 'The product catalog schema is not supported.', 'bg-commerce-suite' ) );
		}

		$revision  = isset( $data['revision'] ) && is_string( $data['revision'] ) ? self::short_text( $data['revision'], 64 ) : '';
		$generated = self::timestamp( isset( $data['generated_at'] ) ? $data['generated_at'] : '' );
		$expires   = self::timestamp( isset( $data['expires_at'] ) ? $data['expires_at'] : '' );
		$products  = isset( $data['products'] ) && is_array( $data['products'] ) ? array_values( $data['products'] ) : null;
		$campaigns = isset( $data['campaigns'] ) && is_array( $data['campaigns'] ) ? array_values( $data['campaigns'] ) : null;

		if ( '' === $revision || false === $generated || false === $expires || $expires <= $generated || null === $products || null === $campaigns ) {
			return new \WP_Error( 'catalog_invalid_header', __( 'The product catalog header is invalid.', 'bg-commerce-suite' ) );
		}
		if ( count( $products ) > 100 || count( $campaigns ) > 100 ) {
			return new \WP_Error( 'catalog_too_many_items', __( 'The product catalog contains too many entries.', 'bg-commerce-suite' ) );
		}

		$normalized_products = array();
		foreach ( $products as $product ) {
			$normalized = is_array( $product ) ? self::normalize_product( $product ) : new \WP_Error( 'catalog_invalid_product' );
			if ( is_wp_error( $normalized ) ) {
				return new \WP_Error( 'catalog_invalid_product', __( 'The product catalog contains an invalid product.', 'bg-commerce-suite' ) );
			}
			if ( isset( $normalized_products[ $normalized['id'] ] ) ) {
				return new \WP_Error( 'catalog_duplicate_product', __( 'The product catalog contains a duplicate product id.', 'bg-commerce-suite' ) );
			}
			$normalized_products[ $normalized['id'] ] = $normalized;
		}

		$normalized_campaigns = array();
		$campaign_ids         = array();
		foreach ( $campaigns as $campaign ) {
			$normalized = is_array( $campaign ) ? self::normalize_campaign( $campaign ) : new \WP_Error( 'catalog_invalid_campaign' );
			if ( is_wp_error( $normalized ) || ! isset( $normalized_products[ $normalized['product_id'] ] ) ) {
				return new \WP_Error( 'catalog_invalid_campaign', __( 'The product catalog contains an invalid campaign.', 'bg-commerce-suite' ) );
			}
			if ( isset( $campaign_ids[ $normalized['id'] ] ) ) {
				return new \WP_Error( 'catalog_duplicate_campaign', __( 'The product catalog contains a duplicate campaign id.', 'bg-commerce-suite' ) );
			}
			$campaign_ids[ $normalized['id'] ] = true;
			$normalized_campaigns[]            = $normalized;
		}

		$normalized_products = array_values( $normalized_products );
		usort(
			$normalized_products,
			static function ( $a, $b ) {
				return $a['sort_order'] === $b['sort_order'] ? strcmp( $a['id'], $b['id'] ) : ( $a['sort_order'] <=> $b['sort_order'] );
			}
		);
		usort(
			$normalized_campaigns,
			static function ( $a, $b ) {
				return $a['priority'] === $b['priority'] ? strcmp( $a['id'], $b['id'] ) : ( $b['priority'] <=> $a['priority'] );
			}
		);

		return array(
			'schema_version' => self::SCHEMA_VERSION,
			'revision'       => $revision,
			'generated_at'   => gmdate( 'Y-m-d\TH:i:s\Z', $generated ),
			'expires_at'     => gmdate( 'Y-m-d\TH:i:s\Z', $expires ),
			'products'       => $normalized_products,
			'campaigns'      => $normalized_campaigns,
		);
	}

	/**
	 * Convert one validated payload into the existing presentation contract.
	 *
	 * @param array<string,mixed> $payload Normalized feed.
	 * @param int|null            $now     Current UTC timestamp for campaign tests.
	 * @return array<string,array<string,mixed>>
	 */
	public static function catalog_items_from_payload( array $payload, $now = null ) {
		$now       = null === $now ? time() : (int) $now;
		$campaigns = array();
		foreach ( isset( $payload['campaigns'] ) ? (array) $payload['campaigns'] : array() as $campaign ) {
			$start = self::timestamp( isset( $campaign['starts_at'] ) ? $campaign['starts_at'] : '' );
			$end   = self::timestamp( isset( $campaign['ends_at'] ) ? $campaign['ends_at'] : '' );
			$id    = isset( $campaign['product_id'] ) ? (string) $campaign['product_id'] : '';
			if ( false !== $start && false !== $end && $start <= $now && $now < $end && '' !== $id && ! isset( $campaigns[ $id ] ) ) {
				$campaigns[ $id ] = $campaign;
			}
		}

		$items = array();
		foreach ( isset( $payload['products'] ) ? (array) $payload['products'] : array() as $product ) {
			if ( ! is_array( $product ) || empty( $product['id'] ) ) {
				continue;
			}
			$id    = (string) $product['id'];
			$entry = array(
				'type'          => (string) $product['type'],
				'name'          => self::localized( $product['name'] ),
				'category'      => self::localized( $product['category'] ),
				'description'   => self::localized( $product['description'] ),
				'version'       => (string) $product['latest_version'],
				'price'         => self::localized( $product['price'] ),
				'regular_price' => self::localized( $product['price'] ),
				'url'           => (string) $product['product_url'],
				'plugin_file'   => (string) $product['plugin_file'],
				'requires_core' => (string) $product['requires_core'],
				'requires_api'  => (string) $product['requires_api'],
				'icon'          => (string) $product['icon'],
				'status'        => (string) $product['status'],
				'status_label'  => self::localized( $product['status_label'] ),
				'featured'      => ! empty( $product['featured'] ),
			);

			if ( isset( $campaigns[ $id ] ) ) {
				$campaign                    = $campaigns[ $id ];
				$entry['promotion_label']    = self::localized( $campaign['label'] );
				$entry['promotion_ends_at']  = (string) $campaign['ends_at'];
				$entry['cta_label']          = self::localized( $campaign['cta_label'] );
				$entry['featured']           = true;
				$campaign_price              = self::localized( $campaign['price'] );
				$entry['promotion_price']     = $campaign_price;
				$entry['price']               = '' !== $campaign_price ? $campaign_price : $entry['price'];
				$entry['url']                 = '' !== $campaign['cta_url'] ? (string) $campaign['cta_url'] : $entry['url'];
			}

			$items[ $id ] = $entry;
		}

		return $items;
	}

	/** Return current remote entries without making a network request. */
	public static function items() {
		$state = self::state();
		if ( empty( $state['payload'] ) || ! is_array( $state['payload'] ) ) {
			return array();
		}
		return self::catalog_items_from_payload( (array) $state['payload'] );
	}

	/** Return safe status metadata for the Dashboard extensions area. */
	public static function status() {
		$state        = self::state();
		$payload      = isset( $state['payload'] ) && is_array( $state['payload'] ) ? $state['payload'] : array();
		$last_success = isset( $state['last_success_at'] ) ? (int) $state['last_success_at'] : 0;
		$expires_at   = isset( $payload['expires_at'] ) ? (string) $payload['expires_at'] : '';
		$expires      = self::timestamp( $expires_at );
		$cache_status = 'empty';
		if ( ! empty( $payload ) ) {
			$cache_status = false !== $expires && $expires <= time()
				? 'expired'
				: ( $last_success >= time() - self::FRESH_FOR ? 'fresh' : 'stale' );
		}

		return array(
			'endpoint'        => self::feed_url(),
			'schema_version'  => isset( $payload['schema_version'] ) ? (int) $payload['schema_version'] : self::SCHEMA_VERSION,
			'last_attempt_at' => isset( $state['last_attempt_at'] ) ? (int) $state['last_attempt_at'] : 0,
			'last_success_at' => $last_success,
			'last_error'      => isset( $state['last_error'] ) ? sanitize_key( (string) $state['last_error'] ) : '',
			'revision'        => isset( $payload['revision'] ) ? self::short_text( $payload['revision'], 64 ) : '',
			'etag'            => isset( $state['etag'] ) ? self::etag( $state['etag'] ) : '',
			'generated_at'    => isset( $payload['generated_at'] ) ? (string) $payload['generated_at'] : '',
			'expires_at'      => $expires_at,
			'products_count'  => isset( $payload['products'] ) && is_array( $payload['products'] ) ? count( $payload['products'] ) : 0,
			'campaigns_count' => isset( $payload['campaigns'] ) && is_array( $payload['campaigns'] ) ? count( $payload['campaigns'] ) : 0,
			'cache_status'    => $cache_status,
			'is_usable'       => ! empty( $payload ),
		);
	}

	/** Build the nonce-protected manual refresh URL. */
	public static function refresh_url() {
		return wp_nonce_url(
			add_query_arg( 'action', self::REFRESH_ACTION, admin_url( 'admin-post.php' ) ),
			self::REFRESH_NONCE
		);
	}

	/** @return array<string,mixed> */
	private static function state() {
		$state = get_option( self::OPTION, array() );
		return is_array( $state ) ? $state : array();
	}

	/** Preserve the old payload while recording a safe error code. */
	private static function record_error( \WP_Error $error ) {
		$state                    = self::state();
		$state['last_attempt_at'] = time();
		$state['last_error']      = sanitize_key( (string) $error->get_error_code() );
		update_option( self::OPTION, $state, false );
		return $error;
	}

	/** Resolve the configurable endpoint. */
	private static function feed_url() {
		/**
		 * Filter the public metadata feed endpoint.
		 *
		 * @param string $url Default error.bg endpoint.
		 */
		$url = apply_filters( 'bgcs3_catalog_feed_url', self::FEED_URL );
		return is_string( $url ) ? esc_url_raw( $url ) : '';
	}

	/** Restrict feed and CTA URLs to configured HTTPS hosts. */
	private static function allowed_url( $url ) {
		if ( ! is_string( $url ) || 0 !== strpos( $url, 'https://' ) || ! wp_http_validate_url( $url ) ) {
			return false;
		}
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( '' === $host ) {
			return false;
		}

		/**
		 * Filter exact hosts allowed for catalog metadata and CTAs.
		 *
		 * @param string[] $hosts Allowed hosts.
		 */
		$hosts = (array) apply_filters( 'bgcs3_catalog_allowed_hosts', array( 'error.bg', 'www.error.bg' ) );
		foreach ( $hosts as $allowed ) {
			$allowed = strtolower( trim( (string) $allowed ) );
			if ( $host === $allowed || ( 'error.bg' === $allowed && substr( $host, -9 ) === '.error.bg' ) ) {
				return true;
			}
		}
		return false;
	}

	/** Normalize one product or reject the whole feed. */
	private static function normalize_product( array $product ) {
		$string_fields = array( 'id', 'type', 'latest_version', 'product_url', 'plugin_file', 'requires_core', 'requires_api', 'icon', 'status' );
		foreach ( $string_fields as $field ) {
			if ( array_key_exists( $field, $product ) && ! is_string( $product[ $field ] ) ) {
				return new \WP_Error( 'catalog_product_type' );
			}
		}
		foreach ( array( 'name', 'category', 'description', 'price', 'status_label' ) as $field ) {
			if ( array_key_exists( $field, $product ) && ! self::valid_localized_input( $product[ $field ] ) ) {
				return new \WP_Error( 'catalog_product_locale' );
			}
		}
		if ( array_key_exists( 'featured', $product ) && ! is_bool( $product['featured'] ) ) {
			return new \WP_Error( 'catalog_product_featured' );
		}
		if ( array_key_exists( 'sort_order', $product ) && ! is_int( $product['sort_order'] ) ) {
			return new \WP_Error( 'catalog_product_sort' );
		}

		$id = isset( $product['id'] ) ? $product['id'] : '';
		if ( ! preg_match( '/^[a-z0-9_-]{1,64}$/', $id ) ) {
			return new \WP_Error( 'catalog_product_id' );
		}

		$type    = isset( $product['type'] ) ? sanitize_key( (string) $product['type'] ) : 'standalone-plugin';
		$status  = isset( $product['status'] ) ? sanitize_key( (string) $product['status'] ) : 'coming_soon';
		$version = isset( $product['latest_version'] ) ? self::short_text( $product['latest_version'], 32 ) : '';
		$url     = isset( $product['product_url'] ) ? esc_url_raw( (string) $product['product_url'] ) : '';
		$file    = isset( $product['plugin_file'] ) ? strtolower( trim( (string) $product['plugin_file'] ) ) : '';
		$name    = self::localized_map( isset( $product['name'] ) ? $product['name'] : '', 120 );

		if ( ! in_array( $type, array( 'bgcs-addon', 'standalone-plugin', 'service' ), true ) || ! in_array( $status, array( 'available', 'beta', 'coming_soon', 'retired' ), true ) ) {
			return new \WP_Error( 'catalog_product_enum' );
		}
		if ( empty( $name ) || ( '' !== $version && ! preg_match( '/^(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)(?:-[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?$/', $version ) ) ) {
			return new \WP_Error( 'catalog_product_value' );
		}
		if ( '' !== $url && ! self::allowed_url( $url ) ) {
			return new \WP_Error( 'catalog_product_url' );
		}
		if ( '' !== $file && ! preg_match( '~^[a-z0-9._-]+/[a-z0-9._-]+\.php$~', $file ) ) {
			return new \WP_Error( 'catalog_plugin_file' );
		}

		$icons = array( 'plug', 'package', 'settings', 'activity', 'file-text', 'receipt', 'credit-card' );
		$icon  = isset( $product['icon'] ) ? sanitize_key( (string) $product['icon'] ) : 'plug';
		return array(
			'id'             => $id,
			'type'           => $type,
			'name'           => $name,
			'category'       => self::localized_map( isset( $product['category'] ) ? $product['category'] : '', 120 ),
			'description'    => self::localized_map( isset( $product['description'] ) ? $product['description'] : '', 600 ),
			'latest_version' => $version,
			'price'          => self::localized_map( isset( $product['price'] ) ? $product['price'] : '', 80 ),
			'product_url'    => $url,
			'plugin_file'    => $file,
			'requires_core'  => self::short_text( isset( $product['requires_core'] ) ? $product['requires_core'] : '', 40 ),
			'requires_api'   => self::short_text( isset( $product['requires_api'] ) ? $product['requires_api'] : '', 40 ),
			'icon'           => in_array( $icon, $icons, true ) ? $icon : 'plug',
			'status'         => $status,
			'status_label'   => self::localized_map( isset( $product['status_label'] ) ? $product['status_label'] : '', 80 ),
			'featured'       => ! empty( $product['featured'] ),
			'sort_order'     => min( 10000, max( 0, isset( $product['sort_order'] ) ? (int) $product['sort_order'] : 1000 ) ),
		);
	}

	/** Normalize one scheduled promotion. */
	private static function normalize_campaign( array $campaign ) {
		foreach ( array( 'id', 'product_id', 'cta_url', 'starts_at', 'ends_at' ) as $field ) {
			if ( array_key_exists( $field, $campaign ) && ! is_string( $campaign[ $field ] ) ) {
				return new \WP_Error( 'catalog_campaign_type' );
			}
		}
		foreach ( array( 'label', 'price', 'cta_label' ) as $field ) {
			if ( array_key_exists( $field, $campaign ) && ! self::valid_localized_input( $campaign[ $field ] ) ) {
				return new \WP_Error( 'catalog_campaign_locale' );
			}
		}
		if ( array_key_exists( 'priority', $campaign ) && ! is_int( $campaign['priority'] ) ) {
			return new \WP_Error( 'catalog_campaign_priority' );
		}

		$id         = isset( $campaign['id'] ) ? $campaign['id'] : '';
		$product_id = isset( $campaign['product_id'] ) ? $campaign['product_id'] : '';
		$start      = self::timestamp( isset( $campaign['starts_at'] ) ? $campaign['starts_at'] : '' );
		$end        = self::timestamp( isset( $campaign['ends_at'] ) ? $campaign['ends_at'] : '' );
		$url        = isset( $campaign['cta_url'] ) ? esc_url_raw( (string) $campaign['cta_url'] ) : '';

		if ( ! preg_match( '/^[a-z0-9_-]{1,64}$/', $id ) || ! preg_match( '/^[a-z0-9_-]{1,64}$/', $product_id ) || false === $start || false === $end || $end <= $start ) {
			return new \WP_Error( 'catalog_campaign_value' );
		}
		if ( '' !== $url && ! self::allowed_url( $url ) ) {
			return new \WP_Error( 'catalog_campaign_url' );
		}

		return array(
			'id'         => $id,
			'product_id' => $product_id,
			'label'      => self::localized_map( isset( $campaign['label'] ) ? $campaign['label'] : '', 100 ),
			'price'      => self::localized_map( isset( $campaign['price'] ) ? $campaign['price'] : '', 80 ),
			'cta_label'  => self::localized_map( isset( $campaign['cta_label'] ) ? $campaign['cta_label'] : '', 80 ),
			'cta_url'    => $url,
			'starts_at'  => gmdate( 'Y-m-d\TH:i:s\Z', $start ),
			'ends_at'    => gmdate( 'Y-m-d\TH:i:s\Z', $end ),
			'priority'   => isset( $campaign['priority'] ) ? (int) $campaign['priority'] : 0,
		);
	}

	/** Preserve plain localized strings for selection at render time. */
	private static function localized_map( $value, $limit ) {
		if ( is_string( $value ) ) {
			$text = self::short_text( $value, $limit );
			return '' === $text ? array() : array( 'en_US' => $text );
		}
		if ( ! is_array( $value ) || count( $value ) > 10 ) {
			return array();
		}
		$result = array();
		foreach ( $value as $locale => $text ) {
			$locale = (string) $locale;
			if ( ! preg_match( '/^[a-z]{2,3}(?:_[A-Z]{2})?$/', $locale ) || ! is_scalar( $text ) ) {
				continue;
			}
			$text = self::short_text( $text, $limit );
			if ( '' !== $text ) {
				$result[ $locale ] = $text;
			}
		}
		return $result;
	}

	/** Validate a localized plain-text input before normalization. */
	private static function valid_localized_input( $value ) {
		if ( is_string( $value ) ) {
			return true;
		}
		if ( ! is_array( $value ) || count( $value ) > 10 ) {
			return false;
		}
		foreach ( $value as $locale => $text ) {
			if ( ! is_string( $locale ) || ! preg_match( '/^[a-z]{2,3}(?:_[A-Z]{2})?$/', $locale ) || ! is_string( $text ) ) {
				return false;
			}
		}
		return true;
	}

	/** Select the current user's locale with stable fallbacks. */
	private static function localized( $map ) {
		if ( is_string( $map ) ) {
			return $map;
		}
		if ( ! is_array( $map ) || empty( $map ) ) {
			return '';
		}
		$locale = function_exists( 'get_user_locale' ) ? get_user_locale() : ( function_exists( 'determine_locale' ) ? determine_locale() : 'en_US' );
		if ( isset( $map[ $locale ] ) ) {
			return (string) $map[ $locale ];
		}
		return isset( $map['en_US'] ) ? (string) $map['en_US'] : (string) reset( $map );
	}

	/** Accept only a single safe HTTP entity tag value. */
	private static function etag( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$value = trim( (string) $value );
		return '' !== $value && preg_match( '/^(?:W\/)?"[\x21\x23-\x7e]*"$/D', $value ) ? $value : '';
	}

	/** Sanitize plain text and enforce a character limit. */
	private static function short_text( $value, $limit ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$text = sanitize_text_field( wp_strip_all_tags( (string) $value, true ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $limit ) : substr( $text, 0, $limit );
	}

	/** Parse a strict-enough ISO-8601 timestamp. */
	private static function timestamp( $value ) {
		if ( ! is_string( $value ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $value ) ) {
			return false;
		}
		$date   = \DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i:s\Z', $value, new \DateTimeZone( 'UTC' ) );
		$errors = \DateTimeImmutable::getLastErrors();
		if ( false === $date || ( is_array( $errors ) && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) ) || $date->format( 'Y-m-d\TH:i:s\Z' ) !== $value ) {
			return false;
		}
		return $date->getTimestamp();
	}
}
