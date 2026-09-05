<?php
/**
 * BG Commerce Suite add-on catalog and built-in module overview.
 *
 * @package BgCommerce3
 */

namespace BgCommerce3\Admin;

use BgCommerce3\Addon\Catalog;
use BgCommerce3\Addon\Installed_Product;
use BgCommerce3\Addon\Remote_Catalog;
use BgCommerce3\Container\Container;
use BgCommerce3\Module\Categories;

 defined( 'ABSPATH' ) || exit;

class Addons {

	/** @var Container */
	private $container;

	public function __construct( Container $container ) {
		$this->container = $container;
	}

	/**
	 * Stable catalog for modules that ship inside Core.
	 *
	 * These are not commercial add-ons. They stay visible here so existing
	 * enable/disable and settings workflows are preserved after introducing the
	 * separate promotional catalog.
	 *
	 * @return array<string,array<string,string>>
	 */
	public function built_in_catalog() {
		return array(
			'speedy' => array(
				'name'     => 'Speedy',
				'category' => __( 'Courier', 'bg-commerce-suite' ),
				'desc'     => __( 'Office, locker and address delivery — pricing, shipment labels and tracking with Speedy.', 'bg-commerce-suite' ),
				'icon'     => 'truck',
			),
			'econt' => array(
				'name'     => 'Econt',
				'category' => __( 'Courier', 'bg-commerce-suite' ),
				'desc'     => __( 'Econt integration for office, APS and address delivery, shipment labels and tracking.', 'bg-commerce-suite' ),
				'icon'     => 'truck',
			),
			'boxnow' => array(
				'name'     => 'BOX NOW',
				'category' => __( 'Courier', 'bg-commerce-suite' ),
				'desc'     => __( 'Delivery to BOX NOW lockers with the official widget, shipment labels and tracking.', 'bg-commerce-suite' ),
				'icon'     => 'package',
			),
			'pigeon' => array(
				'name'     => 'Pigeon Express',
				'category' => __( 'Courier', 'bg-commerce-suite' ),
				'desc'     => __( 'Pigeon Express — offices, lockers (APS), address delivery and shipment labels.', 'bg-commerce-suite' ),
				'icon'     => 'truck',
			),
			'cod_reports' => array(
				'name'     => __( 'COD reports (cash on delivery)', 'bg-commerce-suite' ),
				'category' => __( 'Accounting', 'bg-commerce-suite' ),
				'desc'     => __( 'Centralized tracking and reconciliation of courier COD payouts.', 'bg-commerce-suite' ),
				'icon'     => 'receipt',
			),
		);
	}

	/**
	 * Render the Dashboard extensions area.
	 */
	public function render() {
		$this->render_product_catalog();
		$this->render_built_in_modules();
	}

	/**
	 * Render commercial/optional add-ons. This is deliberately display-only:
	 * no remote install/activation flow lives in BGCS Core.
	 */
	private function render_product_catalog() {
		$enabled          = Remote_Catalog::is_enabled();
		$registry         = $this->container['modules'];
		$items            = $enabled ? Catalog::items() : array();
		$feed_status      = Remote_Catalog::status();
		$plugin_inventory = $enabled ? Installed_Product::inventory() : array();

		echo '<section class="bgcs-addon-section-head bgcs-addon-section-head--catalog">';
		echo '<div><h2>' . esc_html__( 'Extensions', 'bg-commerce-suite' ) . '</h2>';
		echo '<p>' . esc_html__( 'Manage included modules and discover optional extensions in one place.', 'bg-commerce-suite' ) . '</p></div>';
		echo '<div class="bgcs-addon-section-actions">';
		if ( $enabled && current_user_can( 'manage_options' ) ) {
			echo '<a class="bgcs-btn bgcs-btn--outline bgcs-btn--sm" href="' . esc_url( Remote_Catalog::refresh_url() ) . '">' . Icons::svg( 'refresh', 16 ) . esc_html__( 'Refresh catalog', 'bg-commerce-suite' ) . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		if ( ! $enabled ) {
			echo '<span class="bgcs-addon-section-actions__sync">' . esc_html__( 'Optional catalog disabled', 'bg-commerce-suite' ) . '</span>';
		} elseif ( ! empty( $feed_status['is_usable'] ) && ! empty( $feed_status['last_success_at'] ) ) {
			/* translators: %s: human-readable time since the catalog was updated. */
			echo '<span class="bgcs-addon-section-actions__sync">' . esc_html( sprintf( __( 'Updated %s ago', 'bg-commerce-suite' ), human_time_diff( (int) $feed_status['last_success_at'], time() ) ) ) . '</span>';
		} else {
			echo '<span class="bgcs-addon-section-actions__sync">' . esc_html__( 'No catalog data has been loaded.', 'bg-commerce-suite' ) . '</span>';
		}
		echo '</div>';
		echo '</section>';

		if ( ! $enabled ) {
			$settings_url = add_query_arg( array( 'page' => \BgCommerce3\Admin\Settings\Settings_Page::MENU_SLUG, 'tab' => 'general' ), admin_url( 'admin.php' ) );
			echo '<div class="bgcs-alert bgcs-alert--info">' . Icons::svg( 'info', 18 ) . '<span>' . esc_html__( 'Optional Error Web Agency product offers are disabled. An administrator can opt in under General settings. Enabling makes an hourly server request to error.bg; no store, customer, order, credential or plugin-inventory data is sent.', 'bg-commerce-suite' ) . ' <a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'General settings', 'bg-commerce-suite' ) . '</a> · <a href="' . esc_url( Remote_Catalog::PRIVACY_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Privacy', 'bg-commerce-suite' ) . '</a> · <a href="' . esc_url( Remote_Catalog::TERMS_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Terms', 'bg-commerce-suite' ) . '</a></span></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- fixed presentation status after a nonce-protected action.
		$refresh_status = isset( $_GET['catalog_refresh'] ) ? sanitize_key( wp_unslash( $_GET['catalog_refresh'] ) ) : '';
		if ( 'updated' === $refresh_status ) {
			echo '<div class="bgcs-alert bgcs-alert--success">' . Icons::svg( 'check', 18 ) . '<span>' . esc_html__( 'The product catalog was updated.', 'bg-commerce-suite' ) . '</span></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} elseif ( 'unchanged' === $refresh_status ) {
			echo '<div class="bgcs-alert bgcs-alert--info">' . Icons::svg( 'info', 18 ) . '<span>' . esc_html__( 'The product catalog is already current.', 'bg-commerce-suite' ) . '</span></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} elseif ( 'failed' === $refresh_status ) {
			$message = ! empty( $feed_status['is_usable'] )
				? __( 'The catalog could not be refreshed. The last valid catalog remains active.', 'bg-commerce-suite' )
				: __( 'The remote catalog is unavailable. No remote products are shown.', 'bg-commerce-suite' );
			echo '<div class="bgcs-alert bgcs-alert--warning">' . Icons::svg( 'alert', 18 ) . '<span>' . esc_html( $message ) . '</span></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} elseif ( 'disabled' === $refresh_status ) {
			echo '<div class="bgcs-alert bgcs-alert--info">' . Icons::svg( 'info', 18 ) . '<span>' . esc_html__( 'The optional product catalog is disabled. No external request was made.', 'bg-commerce-suite' ) . '</span></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} elseif ( ! empty( $feed_status['last_error'] ) && ! empty( $feed_status['is_usable'] ) ) {
			$cached_at = ! empty( $feed_status['last_success_at'] ) ? $this->catalog_time( (int) $feed_status['last_success_at'] ) : __( 'an earlier successful refresh', 'bg-commerce-suite' );
			/* translators: %s: date or relative time of the last successful catalog refresh. */
			echo '<div class="bgcs-alert bgcs-alert--warning">' . Icons::svg( 'alert', 18 ) . '<span>' . esc_html( sprintf( __( 'Remote catalog could not be refreshed. Showing the last valid catalog from %s.', 'bg-commerce-suite' ), $cached_at ) ) . '</span></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} elseif ( ! empty( $feed_status['last_error'] ) ) {
			echo '<div class="bgcs-alert bgcs-alert--warning">' . Icons::svg( 'alert', 18 ) . '<span>' . esc_html__( 'The remote catalog is unavailable. No remote products are shown.', 'bg-commerce-suite' ) . '</span></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} elseif ( 'expired' === $feed_status['cache_status'] ) {
			echo '<div class="bgcs-alert bgcs-alert--warning">' . Icons::svg( 'alert', 18 ) . '<span>' . esc_html__( 'The remote feed has expired. The last valid catalog remains visible while refresh is retried.', 'bg-commerce-suite' ) . '</span></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		if ( $this->show_catalog_diagnostics() ) {
			$this->render_catalog_diagnostics( $feed_status );
		}

		if ( empty( $items ) ) {
			echo '<div class="bgcs-alert bgcs-alert--info">' . Icons::svg( 'info', 18 ) . '<span>' . esc_html__( 'No extensions are currently published in the remote catalog.', 'bg-commerce-suite' ) . '</span></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			return;
		}

		echo '<div class="bgcs-addon-products">';
		foreach ( $items as $catalog_id => $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['name'] ) ) {
				continue;
			}

			$name            = (string) $entry['name'];
			$category        = isset( $entry['category'] ) ? (string) $entry['category'] : '';
			$description     = isset( $entry['description'] ) ? (string) $entry['description'] : '';
			$version         = isset( $entry['version'] ) ? trim( (string) $entry['version'] ) : '';
			$price           = isset( $entry['price'] ) ? trim( (string) $entry['price'] ) : '';
			$regular_price   = isset( $entry['regular_price'] ) ? trim( (string) $entry['regular_price'] ) : $price;
			$promotion_price = isset( $entry['promotion_price'] ) ? trim( (string) $entry['promotion_price'] ) : '';
			$url             = isset( $entry['url'] ) ? trim( (string) $entry['url'] ) : '';
			$module_id       = isset( $entry['module_id'] ) ? sanitize_key( (string) $entry['module_id'] ) : '';
			$plugin_file     = Installed_Product::resolve_plugin_file( isset( $entry['plugin_file'] ) ? $entry['plugin_file'] : '', $catalog_id, $plugin_inventory );
			$requires_api    = isset( $entry['requires_api'] ) ? trim( (string) $entry['requires_api'] ) : '';
			$requires_core   = isset( $entry['requires_core'] ) ? trim( (string) $entry['requires_core'] ) : '';
			$icon            = isset( $entry['icon'] ) ? sanitize_key( (string) $entry['icon'] ) : 'plug';
			$status          = isset( $entry['status'] ) ? sanitize_key( (string) $entry['status'] ) : 'coming_soon';
			$product_status_label = $this->catalog_status_label( $status, $entry );
			$featured            = ! empty( $entry['featured'] );
			$cta_label           = isset( $entry['cta_label'] ) ? trim( (string) $entry['cta_label'] ) : '';
			$promotion           = isset( $entry['promotion_label'] ) ? trim( (string) $entry['promotion_label'] ) : '';
			$promotion_end       = isset( $entry['promotion_ends_at'] ) ? trim( (string) $entry['promotion_ends_at'] ) : '';
			$module              = '' !== $module_id ? $registry->get( $module_id ) : null;
			if ( ! $module && '' !== $plugin_file ) {
				$module = Installed_Product::module_for_plugin( $plugin_file, $registry );
			}
			$module_id           = $module ? $module->id() : $module_id;
			$incompatible        = '' !== $module_id && isset( $registry->incompatible()[ $module_id ] );
			$local               = Installed_Product::detect( $plugin_file, $version, (bool) $module, $plugin_inventory );
			$installed           = $local['installed'];
			$installed_version = $local['version'];
			$installed_state   = $local['state'];

			$active = $module && $module->is_enabled() && ! $incompatible;
			if ( $incompatible ) {
				$badge_class = 'bgcs-badge--mismatch';
				$badge_label = __( 'Incompatible', 'bg-commerce-suite' );
			} elseif ( $module ) {
				$badge_class = $active ? 'bgcs-badge--active' : 'bgcs-badge--soon';
				$badge_label = $active ? __( 'Active', 'bg-commerce-suite' ) : __( 'Disabled', 'bg-commerce-suite' );
			} elseif ( Installed_Product::UPDATE_AVAILABLE === $installed_state ) {
				$badge_class = 'bgcs-badge--available';
				$badge_label = __( 'Update available', 'bg-commerce-suite' );
			} elseif ( Installed_Product::LOCAL_NEWER === $installed_state ) {
				$badge_class = 'bgcs-badge--available';
				$badge_label = __( 'Local version newer', 'bg-commerce-suite' );
			} elseif ( $installed ) {
				$badge_class = 'bgcs-badge--available';
				$badge_label = __( 'Installed', 'bg-commerce-suite' );
			} elseif ( in_array( $status, array( 'available', 'beta' ), true ) ) {
				$badge_class = 'bgcs-badge--available';
				$badge_label = $product_status_label;
			} elseif ( 'retired' === $status ) {
				$badge_class = 'bgcs-badge--mismatch';
				$badge_label = $product_status_label;
			} else {
				$badge_class = 'bgcs-badge--soon';
				$badge_label = $product_status_label;
			}

			echo '<article class="bgcs-card bgcs-addon-product' . ( $featured ? ' is-featured' : '' ) . '" data-addon="' . esc_attr( sanitize_key( (string) $catalog_id ) ) . '"' . ( $module ? ' data-bgcs-module-card="' . esc_attr( $module->id() ) . '"' : '' ) . '>';
			echo '<div class="bgcs-card__head">';
			echo '<span class="bgcs-card__icon">' . Icons::svg( $icon, 20 ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<div class="bgcs-card__titles"><h3 class="bgcs-card__title">' . esc_html( $name ) . '</h3>';
			if ( '' !== $category ) {
				echo '<p class="bgcs-card__desc">' . esc_html( $category ) . '</p>';
			}
			$dynamic_badge = $module && ! $incompatible;
			echo '</div><span class="bgcs-badge ' . esc_attr( $badge_class ) . '"' . ( $dynamic_badge ? ' data-bgcs-module-status data-bgcs-on-label="' . esc_attr__( 'Active', 'bg-commerce-suite' ) . '" data-bgcs-off-label="' . esc_attr__( 'Installed', 'bg-commerce-suite' ) . '" data-bgcs-on-class="bgcs-badge--active" data-bgcs-off-class="bgcs-badge--available"' : '' ) . '>' . esc_html( $badge_label ) . '</span></div>';
			echo '<div class="bgcs-card__body">';
			if ( '' !== $description ) {
				echo '<p class="bgcs-addon__desc">' . esc_html( $description ) . '</p>';
			}
			if ( '' !== $promotion ) {
				echo '<p class="bgcs-addon-product__promo">' . esc_html( $promotion ) . '</p>';
			}
			echo '<dl class="bgcs-addon-meta">';
			echo '<div><dt>' . esc_html__( 'Product status', 'bg-commerce-suite' ) . '</dt><dd>' . esc_html( $product_status_label ) . '</dd></div>';
			if ( '' !== $plugin_file || $module ) {
				echo '<div><dt>' . esc_html__( 'Version status', 'bg-commerce-suite' ) . '</dt><dd>' . esc_html( $this->installed_state_label( $installed_state ) ) . '</dd></div>';
			}
			if ( '' !== $version ) {
				echo '<div><dt>' . esc_html__( 'Latest version', 'bg-commerce-suite' ) . '</dt><dd>' . esc_html( $version ) . '</dd></div>';
			}
			if ( '' !== $installed_version ) {
				echo '<div><dt>' . esc_html__( 'Installed version', 'bg-commerce-suite' ) . '</dt><dd>' . esc_html( $installed_version ) . '</dd></div>';
			}
			if ( '' !== $promotion_price ) {
				if ( '' !== $regular_price ) {
					echo '<div><dt>' . esc_html__( 'Normal price', 'bg-commerce-suite' ) . '</dt><dd>' . esc_html( $regular_price ) . '</dd></div>';
				}
				echo '<div><dt>' . esc_html__( 'Promotion price', 'bg-commerce-suite' ) . '</dt><dd>' . esc_html( $promotion_price ) . '</dd></div>';
			} elseif ( '' !== $price ) {
				echo '<div><dt>' . esc_html__( 'Price', 'bg-commerce-suite' ) . '</dt><dd>' . esc_html( $price ) . '</dd></div>';
			}
			if ( '' !== $requires_api ) {
				echo '<div><dt>' . esc_html__( 'Module API', 'bg-commerce-suite' ) . '</dt><dd>&ge; ' . esc_html( $requires_api ) . '</dd></div>';
			}
			if ( '' !== $requires_core ) {
				echo '<div><dt>' . esc_html__( 'BGCS Core', 'bg-commerce-suite' ) . '</dt><dd>' . esc_html( $requires_core ) . '</dd></div>';
			}
			if ( '' !== $promotion_end ) {
				$end_timestamp = strtotime( $promotion_end );
				if ( false !== $end_timestamp ) {
					echo '<div><dt>' . esc_html__( 'Offer ends', 'bg-commerce-suite' ) . '</dt><dd>' . esc_html( wp_date( get_option( 'date_format' ), $end_timestamp ) ) . '</dd></div>';
				}
			}
			echo '</dl></div>';
			echo '<div class="bgcs-card__foot bgcs-addon-product__actions">';

			if ( $module ) {
				if ( ! $incompatible && current_user_can( 'manage_woocommerce' ) ) {
					$toggle_url = wp_nonce_url(
						add_query_arg(
							array(
								'action'     => 'bgcs3_toggle_addon',
								'module'     => $module->id(),
								'return_tab' => 'dashboard',
							),
							admin_url( 'admin-post.php' )
						),
						'bgcs3_toggle_addon'
					);
					echo '<a class="bgcs-switch' . ( $active ? ' is-on' : '' ) . '" href="' . esc_url( $toggle_url ) . '" role="switch" aria-checked="' . ( $active ? 'true' : 'false' ) . '" data-bgcs-module-toggle data-bgcs-module-id="' . esc_attr( $module->id() ) . '" data-bgcs-enabled="' . ( $active ? 'yes' : 'no' ) . '">';
					echo '<span class="bgcs-switch__knob"></span><span class="bgcs-switch__text">' . esc_html( $active ? __( 'Enabled', 'bg-commerce-suite' ) : __( 'Disabled', 'bg-commerce-suite' ) ) . '</span></a>';
				}

				$settings_url = add_query_arg( 'tab', $module->id(), admin_url( 'admin.php?page=' . \BgCommerce3\Admin\Settings\Settings_Page::MENU_SLUG ) );
				echo '<a class="bgcs-btn bgcs-btn--sm" href="' . esc_url( $settings_url ) . '">' . Icons::svg( 'settings', 16 ) . esc_html__( 'Open module', 'bg-commerce-suite' ) . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			} elseif ( '' !== $url && 'retired' !== $status ) {
				$button_label = '' !== $cta_label ? $cta_label : __( 'Learn more', 'bg-commerce-suite' );
				echo '<a class="bgcs-btn bgcs-btn--sm bgcs-btn--primary" href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $button_label ) . Icons::svg( 'external', 15 ) . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			} elseif ( 'retired' === $status ) {
				echo '<span class="bgcs-addon-product__note">' . esc_html__( 'This product is no longer offered.', 'bg-commerce-suite' ) . '</span>';
			} else {
				echo '<span class="bgcs-addon-product__note">' . esc_html__( 'Product page will be added when the add-on is released.', 'bg-commerce-suite' ) . '</span>';
			}

			echo '</div></article>';
		}
		echo '</div>';
	}

	/** Render safe feed/cache metadata inside the Dashboard extensions area. */
	private function render_catalog_diagnostics( array $status ) {
		$cache_labels = array(
			'disabled' => __( 'Disabled', 'bg-commerce-suite' ),
			'empty'   => __( 'Empty', 'bg-commerce-suite' ),
			'fresh'   => __( 'Fresh', 'bg-commerce-suite' ),
			'stale'   => __( 'Stale', 'bg-commerce-suite' ),
			'expired' => __( 'Expired', 'bg-commerce-suite' ),
		);
		$cache_status = isset( $status['cache_status'] ) ? sanitize_key( (string) $status['cache_status'] ) : 'empty';
		$rows         = array(
			__( 'Catalog endpoint', 'bg-commerce-suite' )       => isset( $status['endpoint'] ) ? (string) $status['endpoint'] : Remote_Catalog::FEED_URL,
			__( 'Schema version', 'bg-commerce-suite' )         => isset( $status['schema_version'] ) ? (string) $status['schema_version'] : (string) Remote_Catalog::SCHEMA_VERSION,
			__( 'Catalog revision', 'bg-commerce-suite' )       => ! empty( $status['revision'] ) ? (string) $status['revision'] : __( 'Not available', 'bg-commerce-suite' ),
			__( 'ETag', 'bg-commerce-suite' )                   => ! empty( $status['etag'] ) ? (string) $status['etag'] : __( 'Not available', 'bg-commerce-suite' ),
			__( 'Last successful refresh', 'bg-commerce-suite' ) => ! empty( $status['last_success_at'] ) ? $this->catalog_time( (int) $status['last_success_at'] ) : __( 'Not available', 'bg-commerce-suite' ),
			__( 'Last refresh attempt', 'bg-commerce-suite' )   => ! empty( $status['last_attempt_at'] ) ? $this->catalog_time( (int) $status['last_attempt_at'] ) : __( 'Not available', 'bg-commerce-suite' ),
			__( 'Feed generated at', 'bg-commerce-suite' )      => ! empty( $status['generated_at'] ) ? (string) $status['generated_at'] : __( 'Not available', 'bg-commerce-suite' ),
			__( 'Feed expires at', 'bg-commerce-suite' )        => ! empty( $status['expires_at'] ) ? (string) $status['expires_at'] : __( 'Not available', 'bg-commerce-suite' ),
			__( 'Products count', 'bg-commerce-suite' )         => isset( $status['products_count'] ) ? (string) (int) $status['products_count'] : '0',
			__( 'Campaigns count', 'bg-commerce-suite' )        => isset( $status['campaigns_count'] ) ? (string) (int) $status['campaigns_count'] : '0',
			__( 'Cache status', 'bg-commerce-suite' )           => isset( $cache_labels[ $cache_status ] ) ? $cache_labels[ $cache_status ] : $cache_status,
			__( 'Last error', 'bg-commerce-suite' )             => ! empty( $status['last_error'] ) ? (string) $status['last_error'] : __( 'None', 'bg-commerce-suite' ),
		);

		echo '<details class="bgcs-card bgcs-addon-diagnostics">';
		echo '<summary>' . Icons::svg( 'activity', 18 ) . '<span>' . esc_html__( 'Catalog diagnostics', 'bg-commerce-suite' ) . '</span></summary>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<dl class="bgcs-addon-diagnostics__grid">';
		foreach ( $rows as $label => $value ) {
			echo '<div><dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( $value ) . '</dd></div>';
		}
		echo '</dl></details>';
	}

	/** Show catalog diagnostics only in an explicitly requested debug session. */
	private function show_catalog_diagnostics() {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG || ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only developer diagnostics toggle.
		return isset( $_GET['bgcs_debug'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['bgcs_debug'] ) );
	}

	/** Resolve one catalog status label, preferring localized remote metadata. */
	private function catalog_status_label( $status, array $entry ) {
		if ( ! empty( $entry['status_label'] ) ) {
			return (string) $entry['status_label'];
		}
		$labels = array(
			'available'   => __( 'Available', 'bg-commerce-suite' ),
			'beta'        => __( 'Beta', 'bg-commerce-suite' ),
			'coming_soon' => __( 'Coming soon', 'bg-commerce-suite' ),
			'retired'     => __( 'Retired', 'bg-commerce-suite' ),
		);
		return isset( $labels[ $status ] ) ? $labels[ $status ] : $labels['coming_soon'];
	}

	/** Resolve the read-only local plugin state for presentation. */
	private function installed_state_label( $state ) {
		$labels = array(
			Installed_Product::NOT_INSTALLED    => __( 'Not installed', 'bg-commerce-suite' ),
			Installed_Product::INSTALLED_LATEST => __( 'Up to date', 'bg-commerce-suite' ),
			Installed_Product::UPDATE_AVAILABLE => __( 'Update available', 'bg-commerce-suite' ),
			Installed_Product::LOCAL_NEWER      => __( 'Local version newer', 'bg-commerce-suite' ),
			Installed_Product::VERSION_UNKNOWN  => __( 'Version unknown', 'bg-commerce-suite' ),
		);
		return isset( $labels[ $state ] ) ? $labels[ $state ] : $labels[ Installed_Product::NOT_INSTALLED ];
	}

	/** Format a local cache event timestamp using the site's admin date format. */
	private function catalog_time( $timestamp ) {
		$format = trim( (string) get_option( 'date_format', 'Y-m-d' ) . ' ' . (string) get_option( 'time_format', 'H:i' ) );
		return wp_date( $format, (int) $timestamp );
	}

	/**
	 * Render Core modules using the previous Add-ons controls.
	 */
	private function render_built_in_modules() {
		$registry = $this->container['modules'];

		echo '<section class="bgcs-addon-section-head">';
		echo '<div><h2>' . esc_html__( 'Built-in modules', 'bg-commerce-suite' ) . '</h2>';
		echo '<p>' . esc_html__( 'These modules are included with BG Commerce Suite. Enable only the integrations you use.', 'bg-commerce-suite' ) . '</p></div>';
		echo '</section>';
		echo '<div class="bgcs-grid">';

		foreach ( $this->built_in_catalog() as $id => $entry ) {
			$module  = $registry->get( $id );
			$enabled = $module && $module->is_enabled();
			$logo    = Icons::courier_logo( $id, $entry['name'] );
			$mark    = '' !== $logo ? $logo : Icons::svg( $entry['icon'], 20 );

			echo '<section class="bgcs-card bgcs-addon" data-bgcs-module-card="' . esc_attr( $id ) . '">';
			echo '<div class="bgcs-card__head">';
			echo '<span class="bgcs-card__icon bgcs-card__icon--logo">' . $mark . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<div class="bgcs-card__titles"><h2 class="bgcs-card__title">' . esc_html( $entry['name'] ) . '</h2>';
			echo '<p class="bgcs-card__desc">' . esc_html( $entry['category'] ) . '</p></div>';
			echo $enabled
				? '<span class="bgcs-badge bgcs-badge--active" data-bgcs-module-status data-bgcs-on-label="' . esc_attr__( 'Active', 'bg-commerce-suite' ) . '" data-bgcs-off-label="' . esc_attr__( 'Disabled', 'bg-commerce-suite' ) . '" data-bgcs-on-class="bgcs-badge--active" data-bgcs-off-class="bgcs-badge--soon">' . esc_html__( 'Active', 'bg-commerce-suite' ) . '</span>'
				: '<span class="bgcs-badge bgcs-badge--soon" data-bgcs-module-status data-bgcs-on-label="' . esc_attr__( 'Active', 'bg-commerce-suite' ) . '" data-bgcs-off-label="' . esc_attr__( 'Disabled', 'bg-commerce-suite' ) . '" data-bgcs-on-class="bgcs-badge--active" data-bgcs-off-class="bgcs-badge--soon">' . esc_html__( 'Disabled', 'bg-commerce-suite' ) . '</span>';
			echo '</div>';
			echo '<div class="bgcs-card__body"><p class="bgcs-addon__desc">' . esc_html( $entry['desc'] ) . '</p></div>';
			echo '<div class="bgcs-card__foot">';

			if ( $module && current_user_can( 'manage_woocommerce' ) ) {
				$toggle_url = wp_nonce_url(
					add_query_arg(
						array(
							'action' => 'bgcs3_toggle_addon',
							'module' => $id,
						),
						admin_url( 'admin-post.php' )
					),
					'bgcs3_toggle_addon'
				);
				echo '<a class="bgcs-switch' . ( $enabled ? ' is-on' : '' ) . '" href="' . esc_url( $toggle_url ) . '" role="switch" aria-checked="' . ( $enabled ? 'true' : 'false' ) . '" data-bgcs-module-toggle data-bgcs-module-id="' . esc_attr( $id ) . '" data-bgcs-enabled="' . ( $enabled ? 'yes' : 'no' ) . '">';
				echo '<span class="bgcs-switch__knob"></span><span class="bgcs-switch__text">' . esc_html( $enabled ? __( 'Enabled', 'bg-commerce-suite' ) : __( 'Disabled', 'bg-commerce-suite' ) ) . '</span></a>';

				$settings_url = add_query_arg( 'tab', $id, admin_url( 'admin.php?page=' . \BgCommerce3\Admin\Settings\Settings_Page::MENU_SLUG ) );
				echo '<a class="bgcs-btn bgcs-btn--sm" href="' . esc_url( $settings_url ) . '">' . Icons::svg( 'settings', 16 ) . esc_html__( 'Settings', 'bg-commerce-suite' ) . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}

			echo '</div></section>';
		}

		echo '</div>';
	}

}
