<?php
/**
 * Settings page: a WooCommerce submenu that lists registered courier modules
 * (tabs) and their options as cards on a responsive grid. UI only — option
 * names, nonces and the save/sync handlers are unchanged.
 *
 * @package BgCommerce3
 */

namespace BgCommerce3\Admin\Settings;

use BgCommerce3\Addon\Remote_Catalog;
use BgCommerce3\Admin\Icons;
use BgCommerce3\Container\Container;
use BgCommerce3\Module\Categories;
use BgCommerce3\Module\Module_Registry;
use BgCommerce3\Shipping\Tracking_Unmapped_Registry;
use BgCommerce3\Support\Options;
use BgCommerce3\Support\Sync_Result;
use BgCommerce3\Support\Module_Settings;

defined( 'ABSPATH' ) || exit;

class Settings_Page {

	const MENU_SLUG  = 'bgcs3-settings';
	const NONCE      = 'bgcs3_save_settings';
	const SAVE_ACTION = 'bgcs3_save_settings';
	const SYNC_ACTION = 'bgcs3_sync_module';
	const SYNC_NONCE  = 'bgcs3_sync_module';
	const SENDER_SYNC_ACTION = 'bgcs3_sync_sender';
	const SENDER_SYNC_NONCE  = 'bgcs3_sync_sender';
	const CHECK_ACTION       = 'bgcs3_check_connection';
	const CHECK_NONCE        = 'bgcs3_check_connection';
	const TOGGLE_AJAX_ACTION = 'bgcs3_set_module_enabled';
	const TOGGLE_AJAX_NONCE  = 'bgcs3_set_module_enabled';

	/** @var Container */
	private $container;

	/** @var float */
	private $render_started_at = 0.0;

	/** @var int */
	private $render_query_start = 0;

	/**
	 * @param Container $container Core DI container.
	 */
	public function __construct( Container $container ) {
		$this->container = $container;
	}

	public function init() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_' . self::SAVE_ACTION, array( $this, 'handle_save' ) );
		add_action( 'admin_post_' . self::SYNC_ACTION, array( $this, 'handle_sync' ) );
		add_action( 'admin_post_' . self::SENDER_SYNC_ACTION, array( $this, 'handle_sender_sync' ) );
		add_action( 'admin_post_' . self::CHECK_ACTION, array( $this, 'handle_check_connection' ) );
		add_action( 'admin_post_bgcs3_toggle_addon', array( $this, 'handle_toggle_addon' ) );
		add_action( 'wp_ajax_' . self::TOGGLE_AJAX_ACTION, array( $this, 'ajax_set_module_enabled' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Toggle an internal BGCS 3 module from the "Разширения" grid.
	 * Runtime hooks are reconciled on the following request; no external plugin
	 * files are activated/deactivated and legacy plugins are never touched.
	 */
	public function handle_toggle_addon() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission for this action.', 'bg-commerce-suite' ) );
		}
		check_admin_referer( 'bgcs3_toggle_addon' );

		$module_id = isset( $_GET['module'] ) ? sanitize_key( wp_unslash( $_GET['module'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce checked above.
		$registry  = $this->container['modules'];
		$module    = $registry->get( $module_id );

		if ( ! $module ) {
			wp_die( esc_html__( 'Unknown module.', 'bg-commerce-suite' ) );
		}

		$stored_enabled = 'yes' === bgcs3_get_option( $module_id, 'enable', 'no' );
		$this->set_module_enabled( $module, ! $stored_enabled );

		$return_tab = isset( $_GET['return_tab'] ) ? sanitize_key( wp_unslash( $_GET['return_tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce checked above.
		if ( '' === $return_tab || ( 'dashboard' !== $return_tab && ! $registry->get( $return_tab ) ) ) {
			$return_tab = 'dashboard';
		}

		wp_safe_redirect( add_query_arg( 'tab', $return_tab, admin_url( 'admin.php?page=' . self::MENU_SLUG ) ) );
		exit;
	}

	/**
	 * Persist a module enable/disable switch immediately from the BGCS admin UI.
	 *
	 * The requested target state is explicit rather than a blind toggle, so a
	 * delayed or duplicate request cannot accidentally invert the merchant's
	 * choice. The ordinary admin-post URL remains as the no-JavaScript fallback.
	 */
	public function ajax_set_module_enabled() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission for this action.', 'bg-commerce-suite' ) ), 403 );
		}

		check_ajax_referer( self::TOGGLE_AJAX_NONCE, 'nonce' );

		$module_id = isset( $_POST['module'] ) ? sanitize_key( wp_unslash( $_POST['module'] ) ) : '';
		$enabled   = isset( $_POST['enabled'] ) && 'yes' === sanitize_key( wp_unslash( $_POST['enabled'] ) );
		$registry  = $this->container['modules'];
		$module    = $registry->get( $module_id );

		if ( ! $module ) {
			wp_send_json_error( array( 'message' => __( 'Unknown module.', 'bg-commerce-suite' ) ), 404 );
		}

		$incompatible = $registry->incompatible();
		if ( $enabled && isset( $incompatible[ $module_id ] ) ) {
			wp_send_json_error( array( 'message' => (string) $incompatible[ $module_id ] ), 409 );
		}

		$this->set_module_enabled( $module, $enabled );

		// A licensing/compatibility filter may still prevent an enabled flag from
		// becoming effective. Do not show a false positive in the switch UI.
		$effective = $module->is_enabled();
		if ( $enabled && ! $effective ) {
			Options::set( $module_id, 'enable', 'no' );
			wp_send_json_error( array( 'message' => __( 'The module cannot be enabled in the current configuration.', 'bg-commerce-suite' ) ), 409 );
		}

		wp_send_json_success(
			array(
				'module'  => $module_id,
				'enabled' => $effective,
				'label'   => $effective ? __( 'Enabled', 'bg-commerce-suite' ) : __( 'Disabled', 'bg-commerce-suite' ),
				'message' => $effective ? __( 'Module enabled.', 'bg-commerce-suite' ) : __( 'Module disabled.', 'bg-commerce-suite' ),
			)
		);
	}

	/**
	 * Store one module's enable flag and reconcile shared Core side effects.
	 *
	 * @param \BgCommerce3\Module\Module_Interface $module  Registered module.
	 * @param bool                                      $enabled Requested state.
	 */
	private function set_module_enabled( $module, $enabled ) {
		$module_id        = $module->id();
		$previous_enabled = 'yes' === bgcs3_get_option( $module_id, 'enable', 'no' );

		if ( $previous_enabled === (bool) $enabled ) {
			return;
		}

		Options::set( $module_id, 'enable', $enabled ? 'yes' : 'no' );

		if ( 'yes' === bgcs3_get_option( 'checkout', 'shipping_zone_fallback', 'no' ) && class_exists( '\WC_Cache_Helper' ) ) {
			\WC_Cache_Helper::get_transient_version( 'shipping', true );
		}

		/**
		 * Fires after a registered BGCS module changes enabled state.
		 *
		 * Add-ons may use this for lifecycle cleanup that must happen in the same
		 * request; normal runtime hooks are still reconciled on the next request.
		 *
		 * @param string $module_id        Module id.
		 * @param bool   $enabled          New state.
		 * @param bool   $previous_enabled Previous stored state.
		 * @param object $module           Registered module instance.
		 */
		do_action( 'bgcs3_module_enabled_changed', $module_id, (bool) $enabled, $previous_enabled, $module );
	}

	/**
	 * Load admin styles on our settings page only.
	 */
	public function enqueue() {
		if ( ! \BgCommerce3\Admin\Admin_Screen::is_bgcs3_settings() ) {
			return;
		}

		// Keep the shared BGCS shell light. Heavy Woo/select assets are loaded only
		// on courier account pages that actually contain enhanced selectors.
		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'dashboard'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only UI routing.
		$needs_enhanced_select = in_array( $active_tab, array( 'speedy', 'econt', 'boxnow', 'pigeon' ), true );

		wp_enqueue_style( 'bgcs-admin', BGCS3_URL . 'assets/admin/admin.css', array(), BGCS3_VERSION );
		$deps = array( 'jquery' );
		if ( $needs_enhanced_select ) {
			wp_enqueue_style( 'woocommerce_admin_styles' );
			wp_enqueue_script( 'wc-enhanced-select' );
			$deps[] = 'wc-enhanced-select';
		}

		wp_enqueue_script(
			'bgcs-admin-settings',
			BGCS3_URL . 'assets/admin/settings.js',
			$deps,
			BGCS3_VERSION,
			array( 'in_footer' => true, 'strategy' => 'defer' )
		);
		wp_localize_script(
			'bgcs-admin-settings',
			'bgcsSettings',
			array(
				'restUrl'      => esc_url_raw( rest_url( 'bg-commerce-suite/v3/admin/' ) ),
				'restNonce'    => wp_create_nonce( 'wp_rest' ),
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'toggleAction' => self::TOGGLE_AJAX_ACTION,
				'toggleNonce'  => wp_create_nonce( self::TOGGLE_AJAX_NONCE ),
				'i18n'      => array(
					'searching' => __( 'Searching…', 'bg-commerce-suite' ),
					'noResults' => __( 'No results found.', 'bg-commerce-suite' ),
					'error'     => __( 'The data could not be loaded. The saved selection has been preserved.', 'bg-commerce-suite' ),
					'showMoreInfo' => __( 'Show more information', 'bg-commerce-suite' ),
					'senderRefreshConfirm' => __( 'This will replace only the data currently provided by the selected courier profile. Manual fields without an API value will be preserved. Continue?', 'bg-commerce-suite' ),
					'enabled' => __( 'Enabled', 'bg-commerce-suite' ),
					'disabled' => __( 'Disabled', 'bg-commerce-suite' ),
					'saving' => __( 'Saving…', 'bg-commerce-suite' ),
					'toggleError' => __( 'The module state could not be saved. Please try again.', 'bg-commerce-suite' ),
					'selectImage' => __( 'Select image', 'bg-commerce-suite' ),
					'copied' => __( 'Copied', 'bg-commerce-suite' ),
				),
			)
		);
	}

	public function add_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'BG Commerce Suite', 'bg-commerce-suite' ),
			__( 'BG Commerce Suite', 'bg-commerce-suite' ),
			'manage_woocommerce',
			self::MENU_SLUG,
			array( $this, 'render' )
		);
	}

	public function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$this->render_started_at = microtime( true );
		$this->render_query_start = function_exists( 'get_num_queries' ) ? (int) get_num_queries() : 0;

		/** @var Module_Registry $registry */
		$registry = $this->container['modules'];

		/**
		 * Tab order: General first, then module categories in a fixed order —
		 * checkout tools before the couriers, couriers grouped together.
		 * Alphabetical inside a category.
		 *
		 * @param string[] $order Category keys, in display order.
		 */
		$category_order = (array) apply_filters(
			'bgcs3_settings_tab_category_order',
			array( Categories::CHECKOUT, Categories::SHIPPING, Categories::REPUTATION, Categories::ACCOUNTING, Categories::OTHER )
		);

		// Flat tab map (id => title) + grouped nav (TeamHub-style left menu).
		$tabs    = array(
			'dashboard' => __( 'Dashboard', 'bg-commerce-suite' ),
			'general'   => __( 'General settings', 'bg-commerce-suite' ),
		);
		$groups  = array(
			array(
				'label' => '',
				'items' => array( 'dashboard', 'general' ),
			),
		);
		$grouped    = $registry->by_category();
		$cat_labels = Categories::labels();

		foreach ( $category_order as $category ) {
			if ( empty( $grouped[ $category ] ) ) {
				continue;
			}
			$modules = $grouped[ $category ];
			usort(
				$modules,
				static function ( $a, $b ) {
					return strcasecmp( $a->name(), $b->name() );
				}
			);
			$items = array();
			foreach ( $modules as $module ) {
				$tabs[ $module->id() ] = $module->name();
				$items[]               = $module->id();
			}
			$groups[] = array(
				'label' => isset( $cat_labels[ $category ] ) ? $cat_labels[ $category ] : '',
				'items' => $items,
			);
		}

		// Modules in categories outside the ordered list are appended at the end.
		$leftover = array();
		foreach ( $registry->all() as $module ) {
			if ( ! isset( $tabs[ $module->id() ] ) ) {
				$tabs[ $module->id() ] = $module->name();
				$leftover[]            = $module->id();
			}
		}
		if ( ! empty( $leftover ) ) {
			$groups[] = array(
				'label' => isset( $cat_labels[ Categories::OTHER ] ) ? $cat_labels[ Categories::OTHER ] : '',
				'items' => $leftover,
			);
		}

		/**
		 * Final say over the settings tabs (id => title, order preserved).
		 *
		 * @param array<string,string> $tabs Tabs.
		 */
		$tabs = (array) apply_filters( 'bgcs3_settings_tabs', $tabs );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'dashboard';
		if ( 'addons' === $active_tab ) {
			$active_tab = 'dashboard';
		}
		if ( ! array_key_exists( $active_tab, $tabs ) ) {
			$active_tab = 'dashboard';
		}

		echo '<div class="wrap bgcs-settings">';
		echo '<div class="bgcs-shell">';

		// ---- Left sidebar (brand + grouped nav) -----------------------------
		echo '<aside class="bgcs-sidebar">';
		echo '<div class="bgcs-sidebar__brand">' . Icons::svg( 'truck', 22 ) . '<span>BG Commerce Suite</span></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		echo '<nav class="bgcs-nav">';
		foreach ( $groups as $group ) {
			$printable = array_values( array_filter( $group['items'], static function ( $id ) use ( $tabs ) {
				return isset( $tabs[ $id ] );
			} ) );
			if ( empty( $printable ) ) {
				continue;
			}
			if ( '' !== $group['label'] ) {
				echo '<div class="bgcs-nav__label">' . esc_html( $group['label'] ) . '</div>';
			}
			foreach ( $printable as $tab_id ) {
				$tab_title = $tabs[ $tab_id ];
				$url       = add_query_arg( 'tab', $tab_id, admin_url( 'admin.php?page=' . self::MENU_SLUG ) );
				$class     = ( $tab_id === $active_tab ) ? 'bgcs-nav__item is-active' : 'bgcs-nav__item';
				$logo      = ! in_array( $tab_id, array( 'dashboard', 'general' ), true ) ? Icons::courier_logo( $tab_id, $tab_title ) : '';
				$mark      = '' !== $logo ? $logo : Icons::svg( $this->tab_icon( $tab_id ), 17 );
				printf(
					'<a href="%s" class="%s">%s<span>%s</span></a>',
					esc_url( $url ),
					esc_attr( $class ),
					$mark, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					esc_html( $tab_title )
				);
			}
		}
		echo '</nav>';
		echo '</aside>';

		// ---- Main column -----------------------------------------------------
		echo '<main class="bgcs-main">';
		echo '<header class="bgcs-topbar"><h1 class="bgcs-topbar__title">' . esc_html( $tabs[ $active_tab ] ) . '</h1>';
		$topbar_module = ! in_array( $active_tab, array( 'dashboard', 'general' ), true ) ? $registry->get( $active_tab ) : null;
		if ( $topbar_module ) {
			if ( method_exists( $topbar_module, 'render_page' ) ) {
				$this->render_page_module_toggle( $topbar_module );
			} else {
				$this->render_module_toggle( $topbar_module );
			}
		}
		echo '</header>';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['updated'] ) ) {
			$this->alert( 'success', __( 'Settings saved.', 'bg-commerce-suite' ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['settings_error'] ) ) {
			$errors = get_transient( 'bgcs3_settings_errors_' . get_current_user_id() );
			delete_transient( 'bgcs3_settings_errors_' . get_current_user_id() );
			if ( is_array( $errors ) && ! empty( $errors ) ) {
				$this->alert(
					'danger',
					__( 'The settings were NOT saved — fix the following errors:', 'bg-commerce-suite' ) . ' ' . implode( ' ', array_map( 'sanitize_text_field', $errors ) )
				);
			}
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['synced'] ) ) {
			$sync_msg = get_transient( 'bgcs3_sync_msg_' . get_current_user_id() );
			delete_transient( 'bgcs3_sync_msg_' . get_current_user_id() );
			if ( is_array( $sync_msg ) ) {
				$this->sync_alert( $sync_msg );
			}
		}

		// Dashboard owns the overview and extensions catalog; no settings form.
		if ( 'dashboard' === $active_tab ) {
			$this->render_dashboard();
			$this->render_performance_diagnostics();
			echo '</main></div></div>';
			return;
		}

		// Full-page modules (reports, dashboards…) own their tab entirely —
		// no settings form, they render filters/tables/actions themselves.
		$page_module = $registry->get( $active_tab );
		if ( $page_module && method_exists( $page_module, 'render_page' ) ) {
			if ( ! $page_module->is_enabled() ) {
				echo '<section class="bgcs-card bgcs-card--standalone"><div class="bgcs-card__body">';
				echo '<p><strong>' . esc_html__( 'The module is disabled.', 'bg-commerce-suite' ) . '</strong></p>';
				echo '<p>' . esc_html__( 'Enable it using the switch at the top right to use its reports and actions.', 'bg-commerce-suite' ) . '</p>';
				echo '</div></section>';
			} else {
				$page_module->render_page();
			}
			$this->render_performance_diagnostics();
			echo '</main></div></div>';
			return;
		}

		// Setup status (§9): a real readiness checklist for the active courier,
		// shown above the settings form so the admin sees it before editing.
		if ( 'general' !== $active_tab ) {
			$status_module = $registry->get( $active_tab );
			if ( $status_module && method_exists( $status_module, 'setup_status' ) ) {
				$this->render_setup_status( $status_module );
			}
			// "Свържи със Speedy" / "Провери връзката" (§6): validates credentials
			// with one non-destructive call — no shipment, no raw secret echoed.
			if ( ! in_array( $active_tab, array( 'speedy', 'econt', 'boxnow', 'pigeon' ), true ) && $status_module && method_exists( $status_module, 'check_connection' ) ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="bgcs-connection-check-form">';
				echo '<input type="hidden" name="action" value="' . esc_attr( self::CHECK_ACTION ) . '" />';
				echo '<input type="hidden" name="module" value="' . esc_attr( $status_module->id() ) . '" />';
				wp_nonce_field( self::CHECK_NONCE );
				$already_connected = method_exists( $status_module, 'has_credentials' ) && $status_module->has_credentials();
				$connect_label     = $already_connected
					? __( 'Check connection', 'bg-commerce-suite' )
					/* translators: %s: courier name, e.g. "Speedy". */
					: sprintf( __( 'Connect to %s', 'bg-commerce-suite' ), $status_module->name() );
				echo '<button type="submit" class="bgcs-btn bgcs-btn--outline">' . Icons::svg( 'plug', 16 ) . esc_html( $connect_label ) . '</button>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo '</form>';
			}
		}

		// Settings form.
		echo '<form id="bgcs-settings-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::SAVE_ACTION ) . '" />';
		echo '<input type="hidden" name="tab" value="' . esc_attr( $active_tab ) . '" />';
		$initial_task_scope = in_array( $active_tab, array( 'speedy', 'econt', 'boxnow', 'pigeon' ), true ) ? 'account' : '';
		echo '<input type="hidden" id="bgcs-active-task-scope" name="bgcs_active_task_scope" value="' . esc_attr( $initial_task_scope ) . '" />';
		wp_nonce_field( self::NONCE );

		echo '<div class="bgcs-grid">';
		if ( 'general' === $active_tab ) {
			$this->render_general_cards();
		} else {
			$module = $registry->get( $active_tab );
			if ( $module ) {
				if ( in_array( $module->id(), array( 'speedy', 'econt', 'boxnow', 'pigeon' ), true ) ) {
					$this->render_courier_workspace( $module );
				} else {
					$this->render_courier_cards( $module );
				}
			} else {
				echo '<p class="bgcs-empty">' . esc_html__( 'The module was not found.', 'bg-commerce-suite' ) . '</p>';
			}
		}
		echo '</div>';

		// Module-rendered custom settings UI (e.g. a field-editor table). Rendered
		// INSIDE the form so its inputs are submitted with the same save action.
		if ( 'general' !== $active_tab ) {
			$module = $registry->get( $active_tab );
			if ( $module && ! in_array( $module->id(), array( 'speedy', 'econt', 'boxnow', 'pigeon' ), true ) && method_exists( $module, 'render_settings_custom' ) ) {
				$module->render_settings_custom();
			}
		}

		if ( ! in_array( $active_tab, array( 'speedy', 'econt', 'boxnow', 'pigeon' ), true ) ) {
			echo '<div class="bgcs-actionsbar">';
			echo '<button type="submit" class="bgcs-btn bgcs-btn--primary bgcs-btn--lg">' . esc_html__( 'Save changes', 'bg-commerce-suite' ) . '</button>';
			echo '</div>';
		}
		echo '</form>';

		// Courier directory sync uses an external form because it does not save
		// settings. Sender refresh submits the main form so current values are
		// persisted before the courier validates them.
		if ( in_array( $active_tab, array( 'speedy', 'econt', 'boxnow', 'pigeon' ), true ) ) {
			$module = $registry->get( $active_tab );
			if ( $module ) {
				$this->render_task_aux_forms( $module );
			}
		}

		// Manual data-sync for the active courier (separate form — cannot nest).
		if ( 'general' !== $active_tab && ! in_array( $active_tab, array( 'speedy', 'econt', 'boxnow', 'pigeon' ), true ) ) {
			$module = $registry->get( $active_tab );
			if ( $module && method_exists( $module, 'sync_data' ) ) {
				$this->accordion_open(
					$module->id() . '-sync',
					'refresh',
					__( 'Data synchronization', 'bg-commerce-suite' ),
					__( 'Download offices/lockers and reference data (services, contracts) again from the courier API.', 'bg-commerce-suite' )
				);

				// Merchant-facing locations status (§30): офиси/автомати counts +
				// next scheduled Action Scheduler run. No cron interval, batch size,
				// pagination or cache keys — those stay internal implementation detail.
				if ( $module instanceof \BgCommerce3\Modules\Shipping\Courier_Interface ) {
					$this->render_locations_status( $module );
				}

				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
				echo '<input type="hidden" name="action" value="' . esc_attr( self::SYNC_ACTION ) . '" />';
				echo '<input type="hidden" name="module" value="' . esc_attr( $module->id() ) . '" />';
				wp_nonce_field( self::SYNC_NONCE );
				echo '<button type="submit" class="bgcs-btn bgcs-btn--outline">' . Icons::svg( 'refresh', 16 ) . esc_html__( 'Sync directories', 'bg-commerce-suite' ) . '</button>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo '</form>';
				$last_sync = (int) bgcs3_get_option( $module->id(), '_last_sync_at', 0 );
				/* translators: %s: date and time of the last successful location update. */
				echo '<p class="bgcs-sync-status">' . esc_html( $last_sync ? sprintf( __( 'Last successful update: %s', 'bg-commerce-suite' ), wp_date( 'd.m.Y H:i', $last_sync ) ) : __( 'No successful update.', 'bg-commerce-suite' ) ) . '</p>';

				if ( method_exists( $module, 'supports_sender_refresh' ) && $module->supports_sender_refresh() ) {
					echo '<div class="bgcs-sender-refresh-form"><button type="submit" form="bgcs-settings-form" name="bgcs_task_action" value="refresh_sender" formnovalidate class="bgcs-btn bgcs-btn--outline">' . Icons::svg( 'user', 16 ) . esc_html( $module->sender_refresh_label() ) . '</button></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					$last_sender_sync = (int) bgcs3_get_option( $module->id(), '_last_sender_sync_at', 0 );
					/* translators: %s: date and time of the last successful sender-profile update. */
					echo '<p class="bgcs-sync-status">' . esc_html( $last_sender_sync ? sprintf( __( 'Last sender update: %s', 'bg-commerce-suite' ), wp_date( 'd.m.Y H:i', $last_sender_sync ) ) : __( 'No successful sender update.', 'bg-commerce-suite' ) ) . '</p>';
				}
				$this->accordion_close();
			}
		}

		$this->render_performance_diagnostics();
		echo '</main></div></div>';
	}


	/**
	 * Lightweight developer-only request diagnostics. Enabled only with WP_DEBUG
	 * and ?bgcs_debug=1 so customers never see implementation metrics by default.
	 */
	private function render_performance_diagnostics() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only developer diagnostics toggle.
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG || ! isset( $_GET['bgcs_debug'] ) || '1' !== sanitize_text_field( wp_unslash( $_GET['bgcs_debug'] ) ) ) {
			return;
		}

		$elapsed_ms = $this->render_started_at > 0 ? ( microtime( true ) - $this->render_started_at ) * 1000 : 0;
		$queries = function_exists( 'get_num_queries' ) ? max( 0, (int) get_num_queries() - $this->render_query_start ) : 0;
		$provider_calls = class_exists( '\\BgCommerce3\\Modules\\Shipping\\Abstract_Client' )
			? \BgCommerce3\Modules\Shipping\Abstract_Client::request_count()
			: 0;

		echo '<section class="bgcs-card bgcs-card--wide" aria-label="' . esc_attr__( 'BGCS Performance Diagnostics', 'bg-commerce-suite' ) . '">';
		echo '<div class="bgcs-card__head"><div><h2>' . esc_html__( 'Performance diagnostics', 'bg-commerce-suite' ) . '</h2><p>' . esc_html__( 'Shown only when WP_DEBUG and bgcs_debug=1 are enabled.', 'bg-commerce-suite' ) . '</p></div></div>';
		echo '<div class="bgcs-location-metrics">';
		$this->location_metric( __( 'PHP render', 'bg-commerce-suite' ), number_format_i18n( $elapsed_ms, 1 ) . ' ms', __( 'Current BGCS admin request', 'bg-commerce-suite' ), 'activity' );
		$this->location_metric( __( 'DB queries', 'bg-commerce-suite' ), number_format_i18n( $queries ), __( 'Queries since BGCS render started', 'bg-commerce-suite' ), 'file-text' );
		$this->location_metric( __( 'Provider calls', 'bg-commerce-suite' ), number_format_i18n( $provider_calls ), $provider_calls ? __( 'Check why render reached the provider', 'bg-commerce-suite' ) : __( 'No remote API requests during passive render', 'bg-commerce-suite' ), 'plug' );
		echo '<div class="bgcs-location-metric"><span class="bgcs-location-metric__icon">' . Icons::svg( 'sliders', 18 ) . '</span><div><span class="bgcs-location-metric__label">' . esc_html__( 'Tab switch', 'bg-commerce-suite' ) . '</span><strong class="bgcs-location-metric__value" id="bgcs-perf-tab-switch">—</strong><span class="bgcs-location-metric__meta">' . esc_html__( 'Client-side task tab switching', 'bg-commerce-suite' ) . '</span></div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div></section>';
	}

	/**
	 * "Табло" — compact store stats followed by the complete extensions catalog.
	 */
	private function render_dashboard() {
		/** @var Module_Registry $registry */
		$registry = $this->container['modules'];
		$modules  = $registry->all();

		$total  = count( $modules );
		$active = 0;
		foreach ( $modules as $module ) {
			if ( $module->is_enabled() ) {
				$active++;
			}
		}

		// Offices/lockers stored locally (fast — reads only the meta options).
		$offices = 0;
		foreach ( $modules as $module ) {
			if ( ! $module instanceof \BgCommerce3\Modules\Shipping\Courier_Interface || ! $module->is_enabled() ) {
				continue;
			}
			foreach ( array( 'office', 'locker' ) as $type ) {
				$meta     = \BgCommerce3\Shipping\Office_Store::meta( $module->id(), $type );
				$offices += $meta['count'];
			}
		}

		// Orders with a waybill in the last 30 days (cached 15 min).
		$labels = get_transient( 'bgcs3_dash_labels30' );
		if ( false === $labels && function_exists( 'wc_get_orders' ) ) {
			$ids    = wc_get_orders(
				array(
					'limit'        => 500,
					'return'       => 'ids',
					'date_created' => '>' . gmdate( 'Y-m-d', strtotime( '-30 days' ) ),
					'meta_key'     => '_bgcs3_label', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_compare' => 'EXISTS',
				)
			);
			$labels = is_array( $ids ) ? count( $ids ) : 0;
			set_transient( 'bgcs3_dash_labels30', $labels, 15 * MINUTE_IN_SECONDS );
		}
		$labels = (int) $labels;

		// ---- Stat tiles ------------------------------------------------------
		echo '<div class="bgcs-stats">';
		$tiles = array(
			array( 'plug', (string) $active . ' / ' . (string) $total, __( 'Active modules', 'bg-commerce-suite' ) ),
			array( 'truck', (string) $labels, __( 'Shipment labels (30 days)', 'bg-commerce-suite' ) ),
			array( 'map-pin', number_format_i18n( $offices ), __( 'Offices/lockers in the database', 'bg-commerce-suite' ) ),
			array( 'package', (string) BGCS3_VERSION, __( 'Platform version', 'bg-commerce-suite' ) ),
		);
		foreach ( $tiles as $tile ) {
			echo '<div class="bgcs-stat">';
			echo '<span class="bgcs-stat__icon">' . Icons::svg( $tile[0], 20 ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<div class="bgcs-stat__body"><div class="bgcs-stat__value">' . esc_html( $tile[1] ) . '</div>';
			echo '<div class="bgcs-stat__label">' . esc_html( $tile[2] ) . '</div></div>';
			echo '</div>';
		}
		echo '</div>';

		( new \BgCommerce3\Admin\Addons( $this->container ) )->render();
	}

	/**
	 * Render the general settings cards.
	 */
	private function render_general_cards() {
		// Checkout card.
		$this->card_open( 'sliders', __( 'Checkout', 'bg-commerce-suite' ), __( 'Order form behavior.', 'bg-commerce-suite' ) );
		$this->checkbox(
			'checkout[hide_fields]',
			'yes' === bgcs3_get_option( 'checkout', 'hide_fields', 'no' ),
			__( 'Clean checkout', 'bg-commerce-suite' ),
			__( 'Hide unnecessary address fields — they are filled automatically from the selection. Classic checkout only.', 'bg-commerce-suite' )
		);
		$this->checkbox(
			'checkout[show_map]',
			'yes' === bgcs3_get_option( 'checkout', 'show_map', 'yes' ),
			__( 'Office map', 'bg-commerce-suite' ),
			__( 'Show a map with offices/lockers — the customer selects directly from it.', 'bg-commerce-suite' )
		);
		$this->checkbox(
			'checkout[remember_selection]',
			'yes' === bgcs3_get_option( 'checkout', 'remember_selection', 'yes' ),
			__( 'Remember the customer\'s last selection', 'bg-commerce-suite' ),
			__( 'Keep the last chosen office/locker/address in the customer\'s own browser so it is offered again on the next order. Switch off if you do not want delivery choices stored on shared or public computers — existing stored selections are then removed as well.', 'bg-commerce-suite' )
		);
		$this->checkbox(
			'checkout[shipping_zone_fallback]',
			'yes' === bgcs3_get_option( 'checkout', 'shipping_zone_fallback', 'no' ),
			__( 'Fallback mode without a WooCommerce zone', 'bg-commerce-suite' ),
			__( 'If the matched WooCommerce zone has no BGCS method configured, use Bulgaria as the fallback country and temporarily show active BGCS couriers. An explicitly selected different country and any manually configured BGCS zone settings take priority.', 'bg-commerce-suite' )
		);
		$this->card_close();

		// Optional product discovery is an external-service connection and remains
		// off until an administrator explicitly opts in.
		$this->card_open( 'package', __( 'Optional product catalog', 'bg-commerce-suite' ), __( 'Product news and extensions from Error Web Agency.', 'bg-commerce-suite' ) );
		if ( current_user_can( 'manage_options' ) ) {
			$this->checkbox(
				'catalog[enabled]',
				Remote_Catalog::is_enabled(),
				__( 'Enable the Error Web Agency product catalog', 'bg-commerce-suite' ),
				__( 'Fetch validated product names, versions, prices, links and promotions from error.bg. Disabled by default. No store, customer, order, credential or plugin-inventory data is sent; the remote server necessarily receives the connecting server IP address.', 'bg-commerce-suite' )
			);
		} else {
			echo '<p class="bgcs-help">' . esc_html__( 'Only a site administrator can enable or disable the external product catalog.', 'bg-commerce-suite' ) . '</p>';
		}
		echo '<p class="bgcs-help" style="margin:10px 0 0"><a href="' . esc_url( Remote_Catalog::PRIVACY_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Privacy notice', 'bg-commerce-suite' ) . '</a> · <a href="' . esc_url( Remote_Catalog::TERMS_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Catalog service terms', 'bg-commerce-suite' ) . '</a></p>';
		$this->card_close();

		// Automation card.
		$this->card_open( 'refresh', __( 'Automation', 'bg-commerce-suite' ), __( 'Background tasks via Action Scheduler.', 'bg-commerce-suite' ) );
		$this->checkbox(
			'checkout[update_order_statuses]',
			'yes' === bgcs3_get_option( 'checkout', 'update_order_statuses', 'no' ),
			__( 'Automatic status updates', 'bg-commerce-suite' ),
			__( 'Allow BGCS to change the WooCommerce status based on the normalized courier status. Specific actions and tracking check frequency are configured separately for each courier.', 'bg-commerce-suite' )
		);


		echo '<p class="bgcs-help" style="margin:10px 0 0">' . esc_html__( 'The WooCommerce status an order should move to is configured separately for each courier in its “Tracking” tab. Only BGCS states actually supported by that courier are shown.', 'bg-commerce-suite' ) . '</p>';

		$this->checkbox(
			'checkout[auto_sync_locations]',
			'yes' === bgcs3_get_option( 'checkout', 'auto_sync_locations', 'no' ),
			__( 'Automatic data synchronization', 'bg-commerce-suite' ),
			__( 'Update offices/lockers and data for all active couriers daily.', 'bg-commerce-suite' )
		);
		$this->card_close();

		// Courier shipment workspaces now use a fixed low-click layout: the panel is
		// open on page load and chooses the useful tab from shipment state. Keep the
		// independent document preference for document add-ons only.
		$this->card_open( 'settings', __( 'Documents', 'bg-commerce-suite' ), __( 'Behavior of additional document sections in the order.', 'bg-commerce-suite' ) );
		$this->checkbox(
			'ui[document_accordion_open]',
			'yes' === bgcs3_get_option( 'ui', 'document_accordion_open', 'no' ),
			__( 'Open document sections by default', 'bg-commerce-suite' ),
			__( 'Open the Ordinance N-18 sections (and future document add-ons) in the order when the page loads.', 'bg-commerce-suite' )
		);
		$this->card_close();

		// A courier answering "created" is not the same as a courier applying every
		// option that was asked for. When this is on, each create attempt records
		// the whole chain on the order so a missing option can be traced to the
		// exact step it was lost at instead of being guessed at from the result.
		$this->card_open( 'activity', __( 'Diagnostics', 'bg-commerce-suite' ), __( 'Troubleshooting for shipment creation. Keep this off during normal operation.', 'bg-commerce-suite' ) );
		$this->checkbox(
			'debug[shipment_snapshot]',
			'yes' === bgcs3_get_option( 'debug', 'shipment_snapshot', 'no' ),
			__( 'Record shipment creation snapshots', 'bg-commerce-suite' ),
			__( 'Store what was requested, what the courier said was available for the destination, what was validated, what was sent and what came back. Shown in the order under Details. Credentials are never recorded and customer names, phones, emails and addresses are stored only as a length preview.', 'bg-commerce-suite' )
		);
		echo '<p class="bgcs-help" style="margin:10px 0 0">' . esc_html__( 'Each order keeps only its last three create attempts — older ones are discarded automatically, so this cannot grow without limit. Switching it off stops new snapshots; the ones already recorded stay readable in their orders.', 'bg-commerce-suite' ) . '</p>';
		$this->card_close();
	}

	/**
	 * External forms targeted by tab-local action buttons.
	 *
	 * @param \BgCommerce3\Module\Module_Interface $module Courier module.
	 */
	private function render_task_aux_forms( $module ) {
		$prefix = 'bgcs-' . sanitize_html_class( $module->id() );
		echo '<form id="' . esc_attr( $prefix . '-sync-form' ) . '" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" hidden>';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::SYNC_ACTION ) . '" /><input type="hidden" name="module" value="' . esc_attr( $module->id() ) . '" />';
		wp_nonce_field( self::SYNC_NONCE );
		echo '</form>';

	}

	/**
	 * Shared task-oriented courier workspace used by Speedy, Econt, BOX NOW and
	 * Pigeon. Provider differences stay in the field map/module contracts.
	 */
	private function render_courier_workspace( $module ) {
		$id     = $module->id();
		$name   = $module->name();
		$fields = self::module_fields( $module );
		$maps   = $this->courier_workspace_field_map( $id, $fields );

		echo '<div class="bgcs-speedy-workspace bgcs-card--wide" data-bgcs-task-tabs="' . esc_attr( $id ) . '">';
		/* translators: %s: courier name. */
		echo '<div class="bgcs-task-tabs" role="tablist" aria-label="' . esc_attr( sprintf( __( '%s settings', 'bg-commerce-suite' ), $name ) ) . '">';
		$this->task_tab_button( $id, 'account', __( 'Account', 'bg-commerce-suite' ), 'user', true );
		$this->task_tab_button( $id, 'locations', __( 'Locations', 'bg-commerce-suite' ), 'map-pin' );
		$this->task_tab_button( $id, 'methods', __( 'Shipping methods', 'bg-commerce-suite' ), 'truck' );
		$this->task_tab_button( $id, 'tracking', __( 'Tracking', 'bg-commerce-suite' ), 'activity' );
		$this->task_tab_button( $id, 'diagnostics', __( 'Diagnostics', 'bg-commerce-suite' ), 'sliders' );
		echo '</div>';

		// Account -----------------------------------------------------------
		$this->task_panel_open( $id, 'account', true );
		echo '<div class="bgcs-speedy-section-grid">';
		/* translators: %s: courier name. */
		$this->card_open( 'plug', __( 'Account and API', 'bg-commerce-suite' ), sprintf( __( 'Credentials and environment for %s.', 'bg-commerce-suite' ), $name ) );
		$this->render_field_run( $id, $maps['account'], $fields );
		if ( method_exists( $module, 'check_connection' ) ) {
			echo '<div class="bgcs-speedy-inline-actions"><button type="submit" form="bgcs-settings-form" name="bgcs_task_action" value="check_connection" formnovalidate class="bgcs-btn bgcs-btn--outline">' . Icons::svg( 'plug', 16 ) . esc_html__( 'Save and check connection', 'bg-commerce-suite' ) . '</button></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		if ( 'boxnow' === $id && method_exists( $module, 'account_profile' ) ) {
			$this->render_boxnow_account_summary( $module->account_profile() );
		}
		$this->card_close();

		$this->card_open( 'user', __( 'Sender', 'bg-commerce-suite' ), __( 'Shipment drop-off/pickup details.', 'bg-commerce-suite' ) );
		$this->render_field_run( $id, $maps['sender'], $fields );
		if ( method_exists( $module, 'supports_sender_refresh' ) && $module->supports_sender_refresh() ) {
			echo '<div class="bgcs-speedy-inline-actions"><button type="submit" form="bgcs-settings-form" name="bgcs_task_action" value="refresh_sender" formnovalidate class="bgcs-btn bgcs-btn--outline">' . Icons::svg( 'refresh', 16 ) . esc_html( method_exists( $module, 'sender_refresh_label' ) ? $module->sender_refresh_label() : __( 'Update sender details', 'bg-commerce-suite' ) ) . '</button></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		$this->card_close();
		echo '</div>';
		// `method_exists` is the real gate; the courier id was a redundant second
		// one that quietly excluded every other module from an extension point
		// Core already offers (TASK-S1 needed it for Speedy).
		if ( method_exists( $module, 'render_account_custom' ) ) {
			$module->render_account_custom();
		}
		$this->task_save_footer( 'account' );
		$this->task_panel_close();

		// Locations ---------------------------------------------------------
		$this->task_panel_open( $id, 'locations' );
		/* translators: %s: courier name. */
		$this->card_open( 'map-pin', __( 'Locations and reference data', 'bg-commerce-suite' ), sprintf( __( 'Up-to-date %s locations used by checkout.', 'bg-commerce-suite' ), $name ) );
		$this->render_courier_location_metrics( $module );
		if ( method_exists( $module, 'sync_data' ) ) {
			echo '<div class="bgcs-speedy-inline-actions bgcs-speedy-inline-actions--spaced"><button type="submit" form="' . esc_attr( 'bgcs-' . $id . '-sync-form' ) . '" class="bgcs-btn bgcs-btn--primary">' . Icons::svg( 'refresh', 16 ) . esc_html__( 'Sync now', 'bg-commerce-suite' ) . '</button></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		$this->card_close();
		$this->task_panel_close();

		// Methods -----------------------------------------------------------
		$this->task_panel_open( $id, 'methods' );
		$this->render_courier_shipping_instances( $module );
		if ( 'speedy' === $id ) {
			echo '<div class="bgcs-speedy-scope-note">' . Icons::svg( 'info', 17 ) . '<p>' . esc_html__( 'Pricing and COD are stored globally for Speedy; per-WooCommerce-shipping-method-instance separation is not active in the current Core contract.', 'bg-commerce-suite' ) . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '<div class="bgcs-speedy-business-grid">';
		$methods_custom_rendered = false;
		foreach ( $maps['method_cards'] as $card ) {
			$this->card_open( $card['icon'], $card['title'], $card['desc'] );
			$this->render_field_run( $id, $card['fields'], $fields );
			if ( ! empty( $card['render_methods_custom'] ) && method_exists( $module, 'render_methods_custom' ) ) {
				$module->render_methods_custom();
				$methods_custom_rendered = true;
			}
			$this->card_close();
		}
		if ( ! $methods_custom_rendered && method_exists( $module, 'render_methods_custom' ) ) {
			$module->render_methods_custom();
		}
		echo '</div>';
		$this->task_save_footer( 'methods' );
		$this->task_panel_close();

		// Tracking ----------------------------------------------------------
		$this->task_panel_open( $id, 'tracking' );
		$this->card_open( 'activity', __( 'Tracking', 'bg-commerce-suite' ), __( 'Background updates, webhook (when offered by the courier), and an action immediately after a shipment is created.', 'bg-commerce-suite' ) );
		$this->render_field_run( $id, array_unique( array_merge( $maps['tracking'], array( 'tracking_sync_enabled', 'tracking_sync_interval', 'status_after_label' ), \BgCommerce3\Shipping\Cod_Payout_Sync_Settings::field_keys_for( $module ) ) ), $fields );
		$this->card_close();
		$this->render_tracking_policy_card( $module, $fields );
		$this->render_native_shipment_email_notice();
		$this->task_save_footer( 'tracking' );
		$this->task_panel_close();

		// Diagnostics -------------------------------------------------------
		$this->task_panel_open( $id, 'diagnostics' );
		echo '<div class="bgcs-speedy-diagnostics-intro">' . Icons::svg( 'info', 18 ) . '<p>' . esc_html__( 'Only technical and rarely used settings are shown here. Pricing, COD and services are under Shipping methods.', 'bg-commerce-suite' ) . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		if ( 'boxnow' === $id ) {
			$this->render_boxnow_webhook_diagnostics( $fields );
		} else {
			$this->accordion_open( $id . '-advanced', 'sliders', __( 'Technical and advanced settings', 'bg-commerce-suite' ), __( 'Compatibility, diagnostics and special-case settings.', 'bg-commerce-suite' ) );
			$this->render_field_run( $id, $maps['diagnostics'], $fields );
			$this->accordion_close();
		}
		$this->render_unmapped_tracking_statuses( $module );
		$this->task_save_footer( 'diagnostics' );
		$this->task_panel_close();
		echo '</div>';
	}

	private function courier_workspace_field_map( $id, array $fields ) {
		$common_prices = array( 'pricing_mode', 'pricing_rules', 'contract_currency', 'free_over_office', 'free_over_locker', 'free_over_address', 'method_title', 'method_description' );
		$map = array(
			'speedy' => array(
				'account' => array( 'username', 'password' ),
				'sender' => array( 'client_id', 'sender_handover', 'dropoff_office_id' ),
				'method_cards' => array(
					array( 'icon'=>'truck', 'title'=>__( 'General', 'bg-commerce-suite' ), 'desc'=>__( 'Service, allowed delivery types and courier service payer.', 'bg-commerce-suite' ), 'fields'=>array( 'service_id','show_office','show_locker','show_address','locker_capacity_policy','locker_capacity_note','service_payer','third_party_client_id','own_prices_sender_note' ) ),
					array( 'icon'=>'credit-card', 'title'=>__( 'Pricing and free shipping', 'bg-commerce-suite' ), 'desc'=>__( 'Choose API pricing or custom rules and set free-shipping thresholds.', 'bg-commerce-suite' ), 'fields'=>array( 'pricing_mode','pricing_rules','contract_currency','free_over_office','free_over_locker','free_over_address','administrative_fee' ) ),
					array( 'icon'=>'credit-card', 'title'=>__( 'Cash on delivery', 'bg-commerce-suite' ), 'desc'=>__( 'COD and postal money transfer fee (PMT) settings.', 'bg-commerce-suite' ), 'fields'=>array( 'cod_processing','cod_pmt_fee_payer','cod_pmt_on_free_shipping','cod_pmt_percentage','cod_pmt_min_amount' ) ),
					array( 'icon'=>'package', 'title'=>__( 'Additional services and shipment', 'bg-commerce-suite' ), 'desc'=>__( 'Insurance, review/test, return, dimensions, packaging and printing.', 'bg-commerce-suite' ), 'fields'=>array( 'declared_value','fragile','saturday_delivery','deferred_days','obp_option','obp_return_service_id','obp_return_payer','return_voucher','return_voucher_service_id','return_voucher_payer','return_voucher_validity','return_of_documents','default_weight','default_width','default_depth','default_height','default_package','print_paper_size' ) ),
					array( 'icon'=>'settings', 'title'=>__( 'Checkout presentation', 'bg-commerce-suite' ), 'desc'=>__( 'Title and short description shown to the customer when selecting Speedy.', 'bg-commerce-suite' ), 'fields'=>array( 'method_title','method_description' ) ),
				),
				'tracking' => array(),
				'diagnostics' => array( 'language' ),
			),
			'econt' => array(
				'account' => array( 'env', 'user', 'password' ),
				'sender' => array( 'econt_profile_id', 'sender_company', 'sender_name', 'sender_phone', 'sender_email', 'sender_handover', 'sender_address_key', 'sender_office_code' ),
				'method_cards' => array(
					array( 'icon'=>'truck', 'title'=>__( 'General', 'bg-commerce-suite' ), 'desc'=>__( 'Delivery types.', 'bg-commerce-suite' ), 'fields'=>array( 'show_office','show_locker','show_address' ) ),
					array( 'icon'=>'credit-card', 'title'=>__( 'Pricing and free shipping', 'bg-commerce-suite' ), 'desc'=>__( 'API/custom prices and free-shipping thresholds.', 'bg-commerce-suite' ), 'fields'=>$common_prices ),
					array( 'icon'=>'credit-card', 'title'=>__( 'Cash on delivery', 'bg-commerce-suite' ), 'desc'=>__( 'COD agreement, payout and sender payment method.', 'bg-commerce-suite' ), 'fields'=>array( 'cd_enabled','cd_pay_options','payment_type','invoice_before_payment','pay_after' ) ),
					array( 'icon'=>'package', 'title'=>__( 'Additional services and shipment', 'bg-commerce-suite' ), 'desc'=>__( 'Notifications, services, shipment type, Econt packaging, instructions and dimensions.', 'bg-commerce-suite' ), 'fields'=>array( 'shipment_type','sms_notification','email_on_delivery','declared_value','delivery_receipt','digital_receipt','goods_receipt','two_way_shipment','delivery_to_floor','econt_pack5','econt_pack6','econt_pack8','econt_pack9','econt_pack10','econt_pack12','econt_refrigerated_pack','only_courier_request','courier_request_time_from','courier_request_time_to','instructions_take','instructions_give','instructions_return','keep_upright','partial_delivery','priority_time_from','priority_time_to','default_length','default_width','default_height' ) ),
				),
				'tracking' => array(),
				'diagnostics' => array(),
			),
			'boxnow' => array(
				'account' => array( 'env', 'client_id', 'client_secret', 'partner_id' ),
				'sender' => array( 'warehouse_id', 'sender_name', 'sender_phone', 'sender_email' ),
				'method_cards' => array(
					array( 'icon'=>'truck', 'title'=>__( 'General', 'bg-commerce-suite' ), 'desc'=>__( 'Locker, compartment size and service type.', 'bg-commerce-suite' ), 'fields'=>array( 'show_locker','default_size','default_weight','type_of_service','allow_returns','return_location_id','show_recipient_info' ) ),
					array( 'icon'=>'credit-card', 'title'=>__( 'Pricing and free shipping', 'bg-commerce-suite' ), 'desc'=>__( 'One BOX NOW pricing configuration: weight ranges and an optional free-shipping threshold.', 'bg-commerce-suite' ), 'fields'=>array( 'free_over_locker' ), 'render_methods_custom'=>true ),
					array( 'icon'=>'package', 'title'=>__( 'Additional services and shipment', 'bg-commerce-suite' ), 'desc'=>__( 'Notifications, voucher and label information.', 'bg-commerce-suite' ), 'fields'=>array( 'voucher_email','notify_sms','label_row1','label_row2','label_row3','label_row4' ) ),
					array( 'icon'=>'settings', 'title'=>__( 'Checkout presentation', 'bg-commerce-suite' ), 'desc'=>__( 'The official BOX NOW widget and locker-selection behavior.', 'bg-commerce-suite' ), 'fields'=>array( 'widget_gps','method_title','method_description' ) ),
				),
				'tracking' => array(),
				'diagnostics' => array( 'webhook_secret' ),
			),
			'pigeon' => array(
				'account' => array( 'sandbox', 'api_key', 'api_secret' ),
				'sender' => array( 'pickup_type','sender_office_id','sender_city_id','sender_street_id','sender_address','sender_name','sender_phone','sender_email' ),
				'method_cards' => array(
					array( 'icon'=>'truck', 'title'=>__( 'General', 'bg-commerce-suite' ), 'desc'=>__( 'Delivery types, service and payer.', 'bg-commerce-suite' ), 'fields'=>array( 'show_office','show_locker','show_address','service_type','return_at_my_expense','who_pays' ) ),
					array( 'icon'=>'credit-card', 'title'=>__( 'Pricing and free shipping', 'bg-commerce-suite' ), 'desc'=>__( 'API/custom prices and thresholds.', 'bg-commerce-suite' ), 'fields'=>$common_prices ),
					array( 'icon'=>'package', 'title'=>__( 'Additional services and shipment', 'bg-commerce-suite' ), 'desc'=>__( 'Dimensions, label format and services from the profile.', 'bg-commerce-suite' ), 'fields'=>array_merge( array( 'default_width','default_length','default_height','label_format' ), array_values( array_filter( array_keys( $fields ), static function( $key ) { return 0 === strpos( $key, 'service_' ); } ) ) ) ),
					array( 'icon'=>'settings', 'title'=>__( 'Checkout presentation', 'bg-commerce-suite' ), 'desc'=>__( 'Method title and description.', 'bg-commerce-suite' ), 'fields'=>array( 'method_title','method_description' ) ),
				),
				'tracking' => array(),
				'diagnostics' => array(),
			),
		);
		return isset( $map[ $id ] ) ? $map[ $id ] : array( 'account'=>array(), 'sender'=>array(), 'method_cards'=>array(), 'tracking'=>array(), 'diagnostics'=>array() );
	}

	private function task_tab_button( $workspace_id, $id, $label, $icon, $active = false ) {
		$panel_id = 'bgcs-' . sanitize_html_class( $workspace_id ) . '-panel-' . sanitize_html_class( $id );
		echo '<button type="button" class="bgcs-task-tab' . ( $active ? ' is-active' : '' ) . '" role="tab" aria-selected="' . ( $active ? 'true' : 'false' ) . '" aria-controls="' . esc_attr( $panel_id ) . '" data-bgcs-task-tab="' . esc_attr( $id ) . '">' . Icons::svg( $icon, 17 ) . '<span>' . esc_html( $label ) . '</span></button>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	private function task_panel_open( $workspace_id, $id, $active = false ) {
		echo '<section id="bgcs-' . esc_attr( sanitize_html_class( $workspace_id ) ) . '-panel-' . esc_attr( sanitize_html_class( $id ) ) . '" class="bgcs-task-panel' . ( $active ? ' is-active' : '' ) . '" role="tabpanel" data-bgcs-task-panel="' . esc_attr( $id ) . '"' . ( $active ? '' : ' hidden' ) . '>';
	}

	private function task_panel_close() { echo '</section>'; }
	private function task_save_footer( $scope ) { echo '<div class="bgcs-speedy-action-footer"><button type="submit" name="bgcs_task_scope" value="' . esc_attr( sanitize_key( $scope ) ) . '" formnovalidate class="bgcs-btn bgcs-btn--primary bgcs-btn--lg">' . esc_html__( 'Save changes', 'bg-commerce-suite' ) . '</button></div>'; }

	/**
	 * BOX NOW webhook connection details for the merchant/provider.
	 *
	 * The endpoint is generated with rest_url() so it stays correct when the
	 * WordPress installation lives in a subdirectory or uses a non-default
	 * REST base URL. The shared secret is never printed back to the screen.
	 *
	 * @param array<string,array<string,mixed>> $fields Module field definitions.
	 * @return void
	 */
	private function render_boxnow_webhook_diagnostics( array $fields ) {
		$url = rest_url( 'bg-commerce-suite/v3/webhook/boxnow' );
		$has_secret = '' !== trim( (string) Module_Settings::get( 'boxnow', 'webhook_secret' ) );

		$this->card_open(
			'activity',
			__( 'BOX NOW Webhook', 'bg-commerce-suite' ),
			__( 'The address provided to BOX NOW for sending shipment status change events.', 'bg-commerce-suite' )
		);

		echo '<div class="bgcs-field bgcs-field--full">';
		echo '<span class="bgcs-field__heading"><label class="bgcs-field__label" for="bgcs-boxnow-webhook-url">' . esc_html__( 'Webhook URL', 'bg-commerce-suite' ) . '</label></span>';
		echo '<div class="bgcs-webhook-url-row">';
		echo '<input id="bgcs-boxnow-webhook-url" type="text" readonly value="' . esc_attr( $url ) . '" autocomplete="off">';
		echo '<button type="button" class="bgcs-btn bgcs-btn--outline bgcs-copy-text" data-copy="' . esc_attr( $url ) . '">' . Icons::svg( 'copy', 15 ) . esc_html__( 'Copy', 'bg-commerce-suite' ) . '</button>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div>';
		echo '<span class="bgcs-field__desc">' . esc_html__( 'HTTP method: POST. The endpoint is public by necessity, but accepts only BOX NOW events with a valid HMAC-SHA256 datasignature.', 'bg-commerce-suite' ) . '</span>';
		echo '</div>';

		echo '<div class="bgcs-webhook-meta">';
		echo '<div><strong>' . esc_html__( 'REST route', 'bg-commerce-suite' ) . '</strong><span>/bg-commerce-suite/v3/webhook/boxnow</span></div>';
		echo '<div><strong>' . esc_html__( 'Signature', 'bg-commerce-suite' ) . '</strong><span>HMAC-SHA256 / datasignature</span></div>';
		echo '<div><strong>' . esc_html__( 'Webhook Secret', 'bg-commerce-suite' ) . '</strong><span>' . esc_html( $has_secret ? __( 'Configured', 'bg-commerce-suite' ) : __( 'Not configured', 'bg-commerce-suite' ) ) . '</span></div>';
		echo '</div>';

		$this->render_field_run( 'boxnow', array( 'webhook_secret' ), $fields );
		echo '<p class="description">' . esc_html__( 'The secret must match the shared secret used by BOX NOW to sign the webhook payload. The saved value is not shown again after reloading.', 'bg-commerce-suite' ) . '</p>';

		$this->render_boxnow_webhook_history();
		$this->card_close();
	}


	/**
	 * Recent verified BOX NOW webhook events, intentionally PII-free.
	 *
	 * @return void
	 */
	private function render_boxnow_webhook_history() {
		$history = bgcs3_get_option( 'boxnow', '_webhook_history', array() );
		$history = is_array( $history ) ? array_slice( $history, 0, 20 ) : array();

		echo '<div class="bgcs-field bgcs-field--full" style="margin-top:20px">';
		echo '<span class="bgcs-field__heading"><span class="bgcs-field__label">' . esc_html__( 'Recent webhook events', 'bg-commerce-suite' ) . '</span></span>';
		if ( empty( $history ) ) {
			echo '<p class="description">' . esc_html__( 'No BOX NOW webhook events have been accepted yet.', 'bg-commerce-suite' ) . '</p>';
			echo '</div>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'Received', 'bg-commerce-suite' ), __( 'Order', 'bg-commerce-suite' ), __( 'Event', 'bg-commerce-suite' ), __( 'BGCS state', 'bg-commerce-suite' ), __( 'Result', 'bg-commerce-suite' ) ) as $heading ) {
			echo '<th>' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $history as $row ) {
			if ( ! is_array( $row ) ) { continue; }
			$received = ! empty( $row['received_at'] ) ? wp_date( 'd.m.Y H:i:s', (int) $row['received_at'] ) : '—';
			$order_id   = ! empty( $row['order_id'] ) ? (int) $row['order_id'] : 0;
			$event_order = $order_id && function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;
			$order_link  = $event_order && is_callable( array( $event_order, 'get_edit_order_url' ) ) ? $event_order->get_edit_order_url() : '';
			echo '<tr>';
			echo '<td>' . esc_html( $received ) . '</td>';
			echo '<td>' . ( $order_link ? '<a href="' . esc_url( $order_link ) . '">#' . esc_html( (string) $order_id ) . '</a>' : esc_html( $order_id ? '#' . $order_id : '—' ) ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<td><code>' . esc_html( isset( $row['event'] ) ? (string) $row['event'] : '' ) . '</code></td>';
			echo '<td><code>' . esc_html( isset( $row['state'] ) ? (string) $row['state'] : '' ) . '</code></td>';
			echo '<td><code>' . esc_html( isset( $row['action'] ) ? (string) $row['action'] : '' ) . '</code></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'applied = the event was applied; duplicate_ignored = this message has already been handled for the order; expired_ignored = the event is older than the freshness window, so it is history being replayed rather than news; stale_ignored = the event is older than one already accepted for this order.', 'bg-commerce-suite' ) . '</p>';
		echo '</div>';
	}

	/**
	 * Per-courier normalized shipment state -> WooCommerce status policy.
	 *
	 * @param \BgCommerce3\Modules\Shipping\Courier_Interface $module Courier.
	 * @param array<string,array<string,mixed>> $fields Declared fields.
	 * @return void
	 */
	private function render_tracking_policy_card( $module, array $fields ) {
		$this->card_open( 'refresh', __( 'WooCommerce status automation', 'bg-commerce-suite' ), __( 'Each courier maps its own tracking codes to normalized BGCS states. Here you choose what WooCommerce should do for the states that this courier actually supports.', 'bg-commerce-suite' ) );
		if ( 'yes' !== bgcs3_get_option( 'checkout', 'update_order_statuses', 'no' ) ) {
			$general_url = add_query_arg( array( 'page' => 'bgcs3-settings', 'tab' => 'general' ), admin_url( 'admin.php' ) );
			echo '<div class="bgcs-alert bgcs-alert--warning"><div><strong>' . esc_html__( 'Automatic WooCommerce status updates are disabled globally.', 'bg-commerce-suite' ) . '</strong><br>' . esc_html__( 'You can prepare the mapping here; it will start applying only after global automation is enabled.', 'bg-commerce-suite' ) . ' <a href="' . esc_url( $general_url ) . '">' . esc_html__( 'General settings', 'bg-commerce-suite' ) . '</a></div></div>';
		}
		$policy_keys = \BgCommerce3\Shipping\Tracking_Status_Policy::field_keys_for( $module );
		$this->render_field_run( $module->id(), $policy_keys, $fields );
		$provider_note = \BgCommerce3\Shipping\Tracking_Status_Policy::provider_detail_note( $module->id() );
		if ( '' !== $provider_note ) {
			echo '<div class="bgcs-alert bgcs-alert--info" style="margin-top:12px"><div>' . esc_html( $provider_note ) . '</div></div>';
		}

		// BGCS-AUDIT-008 — the merchant has to be able to predict this. Without
		// it, a refunded order that quietly stays refunded looks like the mapping
		// is broken rather than deliberately declined.
		echo '<p class="bgcs-help" style="margin:12px 0 0">' . esc_html__( 'Orders that are Refunded, Cancelled or Failed are never moved by these rules. A late or repeated courier event cannot undo a decision you have already taken about the money — the event is still recorded on the order, with a note explaining which change was declined.', 'bg-commerce-suite' ) . '</p>';

		$this->card_close();
	}

	/** Native WooCommerce shipment-email handoff notice. */
	/**
	 * Show provider status codes Core has observed but cannot normalize yet.
	 * The registry deliberately stores no payload or customer/order data.
	 *
	 * @param \BgCommerce3\Modules\Shipping\Courier_Interface $module Courier.
	 */
	private function render_unmapped_tracking_statuses( $module ) {
		$items = Tracking_Unmapped_Registry::for_courier( $module->id() );
		$this->card_open(
			'alert',
			__( 'Unmapped tracking statuses', 'bg-commerce-suite' ),
			/* translators: %s: courier name. */
			sprintf( __( 'New/unknown codes observed only for %s. They do not change the WooCommerce status until they are normalized in Core.', 'bg-commerce-suite' ), $module->name() )
		);
		if ( empty( $items ) ) {
			echo '<p class="bgcs-help" style="margin:0">' . esc_html__( 'No unmapped statuses have been observed.', 'bg-commerce-suite' ) . '</p>';
			$this->card_close();
			return;
		}

		echo '<div class="bgcs-table-wrap"><table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Courier status', 'bg-commerce-suite' ) . '</th>';
		echo '<th>' . esc_html__( 'Count', 'bg-commerce-suite' ) . '</th>';
		echo '<th>' . esc_html__( 'First seen', 'bg-commerce-suite' ) . '</th>';
		echo '<th>' . esc_html__( 'Last seen', 'bg-commerce-suite' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( array_slice( $items, 0, 20 ) as $item ) {
			$first = ! empty( $item['first_seen'] ) ? wp_date( 'd.m.Y H:i', (int) $item['first_seen'] ) : '—';
			$last  = ! empty( $item['last_seen'] ) ? wp_date( 'd.m.Y H:i', (int) $item['last_seen'] ) : '—';
			echo '<tr><td><code>' . esc_html( (string) ( $item['status'] ?? '' ) ) . '</code></td><td>' . esc_html( number_format_i18n( (int) ( $item['count'] ?? 0 ) ) ) . '</td><td>' . esc_html( $first ) . '</td><td>' . esc_html( $last ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
		echo '<p class="bgcs-help">' . esc_html__( 'These codes are diagnostic and specific to the current courier. Send them when needed so a safe BGCS mapping can be added.', 'bg-commerce-suite' ) . '</p>';
		$this->card_close();
	}

	private function render_native_shipment_email_notice() {
		$email_url = add_query_arg( array( 'page' => 'wc-settings', 'tab' => 'email', 'section' => 'bgcs3_shipment_created' ), admin_url( 'admin.php' ) );
		$this->card_open( 'file-text', __( 'Shipment label created email', 'bg-commerce-suite' ), __( 'BGCS uses a WooCommerce transactional email instead of its own mail transport.', 'bg-commerce-suite' ) );
		echo '<div class="bgcs-alert bgcs-alert--info"><div><strong>' . esc_html__( 'Managed entirely by WooCommerce.', 'bg-commerce-suite' ) . '</strong><br>' . esc_html__( 'Enablement, Subject, Heading, Additional content, HTML/Plain/Multipart, sender, CC/BCC, preheader and the other available WooCommerce email features follow the active WooCommerce version and email features.', 'bg-commerce-suite' ) . ' <a href="' . esc_url( $email_url ) . '">' . esc_html__( 'Open WooCommerce → Settings → Emails', 'bg-commerce-suite' ) . '</a></div></div>';
		$this->card_close();
	}

	private function render_courier_location_metrics( $module ) {
		$id           = $module->id();
		$office_count = (int) \BgCommerce3\Shipping\Office_Store::meta( $id, 'office' )['count'];
		$locker_count = (int) \BgCommerce3\Shipping\Office_Store::meta( $id, 'locker' )['count'];
		$last_sync    = (int) bgcs3_get_option( $id, '_last_sync_at', 0 );
		$next_text    = __( 'Not scheduled', 'bg-commerce-suite' );

		if ( function_exists( 'as_next_scheduled_action' ) && 'yes' === bgcs3_get_option( 'checkout', 'auto_sync_locations', 'no' ) ) {
			$next = as_next_scheduled_action( \BgCommerce3\Background\Locations_Sync::HOOK, array(), \BgCommerce3\Background\Locations_Sync::GROUP );
			if ( $next ) {
				$next_text = wp_date( 'd.m.Y H:i', (int) $next );
			}
		}

		echo '<div class="bgcs-location-metrics">';
		$this->location_metric( __( 'Offices', 'bg-commerce-suite' ), number_format_i18n( $office_count ), $office_count ? __( 'Up-to-date data', 'bg-commerce-suite' ) : __( 'No data', 'bg-commerce-suite' ), 'map-pin' );
		$this->location_metric( __( 'Lockers', 'bg-commerce-suite' ), number_format_i18n( $locker_count ), $locker_count ? __( 'Up-to-date data', 'bg-commerce-suite' ) : __( 'No data', 'bg-commerce-suite' ), 'package' );
		$this->location_metric( __( 'Last synchronization', 'bg-commerce-suite' ), $last_sync ? wp_date( 'd.m.Y H:i', $last_sync ) : '—', $last_sync ? __( 'Successful update', 'bg-commerce-suite' ) : __( 'No successful update yet', 'bg-commerce-suite' ), 'check' );
		$this->location_metric( __( 'Next synchronization', 'bg-commerce-suite' ), $next_text, __( 'Automatic updates', 'bg-commerce-suite' ), 'refresh' );
		echo '</div>';
	}

	private function location_metric( $label, $value, $meta, $icon ) {
		echo '<div class="bgcs-location-metric">';
		echo '<span class="bgcs-location-metric__icon">' . Icons::svg( $icon, 18 ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<div><span class="bgcs-location-metric__label">' . esc_html( $label ) . '</span><strong class="bgcs-location-metric__value">' . esc_html( $value ) . '</strong><span class="bgcs-location-metric__meta">' . esc_html( $meta ) . '</span></div>';
		echo '</div>';
	}

	private function render_courier_shipping_instances( $module ) {
		$id = $module->id();
		$name = $module->name();
		/* translators: %s: courier name. */
		$this->card_open( 'truck', __( 'Shipping methods', 'bg-commerce-suite' ), sprintf( __( '%s is added to a WooCommerce zone. Each instance can have its own configuration.', 'bg-commerce-suite' ), $name ) );
		$instances = array();
		if ( class_exists( '\\WC_Shipping_Zones' ) && class_exists( '\\WC_Shipping_Zone' ) ) {
			$zones = \WC_Shipping_Zones::get_zones();
			$zones[0] = array( 'zone_id'=>0, 'zone_name'=>__( 'Locations not covered by your other zones', 'bg-commerce-suite' ) );
			foreach ( $zones as $zone_key => $zone_data ) {
				$zone_id = isset( $zone_data['zone_id'] ) ? absint( $zone_data['zone_id'] ) : absint( $zone_key );
				$zone = new \WC_Shipping_Zone( $zone_id );
				foreach ( $zone->get_shipping_methods( false ) as $method ) {
					if ( ! is_object( $method ) || 'bgcs3_' . $id !== (string) $method->id ) { continue; }
					$types = method_exists( $method, 'get_option' ) ? (array) $method->get_option( 'delivery_types', array() ) : array();
					$instances[] = array( 'zone_id'=>$zone_id, 'zone'=>$zone->get_zone_name(), 'id'=>isset($method->instance_id)?absint($method->instance_id):0, 'title'=>isset($method->title)?(string)$method->title:$name, 'enabled'=>isset($method->enabled)?'yes'===$method->enabled:true, 'types'=>$types );
				}
			}
		}
		if ( empty( $instances ) ) {
			/* translators: %s: courier name. */
			echo '<div class="bgcs-method-empty"><span class="bgcs-method-empty__icon">' . Icons::svg( 'alert', 20 ) . '</span><div><strong>' . esc_html( sprintf( __( 'No %s method has been added to a WooCommerce zone.', 'bg-commerce-suite' ), $name ) ) . '</strong><p>' . esc_html__( 'The account may be connected, but checkout will not offer this courier until you add the method to a shipping zone.', 'bg-commerce-suite' ) . '</p></div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} else {
			echo '<div class="bgcs-method-list">';
			$labels = array( 'office' => __( 'Office', 'bg-commerce-suite' ), 'locker' => __( 'Locker', 'bg-commerce-suite' ), 'address' => __( 'Address', 'bg-commerce-suite' ) );
			foreach ( $instances as $instance ) {
				$type_labels = array();
				foreach ( $instance['types'] as $type ) {
					$type_labels[] = isset( $labels[ $type ] ) ? $labels[ $type ] : $type;
				}
				$edit_url = add_query_arg( array( 'page'=>'wc-settings', 'tab'=>'shipping', 'zone_id'=>$instance['zone_id'] ), admin_url( 'admin.php' ) );
				echo '<div class="bgcs-method-row"><div class="bgcs-method-row__main"><strong>' . esc_html( $instance['zone'] ) . '</strong><span>' . esc_html( $instance['title'] ) . ' · #' . esc_html( $instance['id'] ) . '</span>';
				if ( ! empty( $type_labels ) ) {
					echo '<span class="bgcs-method-row__types">' . esc_html( implode( ' · ', $type_labels ) ) . '</span>';
				}
				echo '</div><div class="bgcs-method-row__actions"><span class="bgcs-badge ' . ( $instance['enabled'] ? 'bgcs-badge--active' : 'bgcs-badge--soon' ) . '">' . esc_html( $instance['enabled'] ? __( 'Active', 'bg-commerce-suite' ) : __( 'Disabled', 'bg-commerce-suite' ) ) . '</span><a class="bgcs-btn bgcs-btn--outline" href="' . esc_url( $edit_url ) . '">' . Icons::svg( 'settings', 15 ) . esc_html__( 'Settings', 'bg-commerce-suite' ) . '</a></div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			echo '</div>';
		}
		$zones_url = add_query_arg( array( 'page'=>'wc-settings', 'tab'=>'shipping' ), admin_url( 'admin.php' ) );
		/* translators: %s: courier name. */
		echo '<div class="bgcs-speedy-inline-actions bgcs-speedy-inline-actions--spaced"><a class="bgcs-btn bgcs-btn--outline" href="' . esc_url( $zones_url ) . '">' . Icons::svg( 'plus', 15 ) . esc_html( sprintf( __( 'Add %s to zone', 'bg-commerce-suite' ), $name ) ) . '</a></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		$this->card_close();
	}

	private function render_boxnow_account_summary( array $profile ) {
		if ( empty( $profile ) ) { return; }
		echo '<div class="bgcs-account-summary"><dl class="bgcs-summary">';
		$rows = array( __( 'Partner', 'bg-commerce-suite' ) => isset($profile['display_name'])?$profile['display_name']:'', __( 'Email', 'bg-commerce-suite' ) => isset($profile['email'])?$profile['email']:'', __( 'Phone', 'bg-commerce-suite' ) => isset($profile['phone'])?$profile['phone']:'', __( 'Currency', 'bg-commerce-suite' ) => isset($profile['currency'])?$profile['currency']:'' );
		foreach ( $rows as $label=>$value ) { if ( '' !== (string)$value ) { echo '<dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( $value ) . '</dd>'; } }
		echo '</dl></div>';
	}

	/**
	 * Render a courier's cards: an enable card + grouped option cards.
	 *
	 * @param \BgCommerce3\Module\Module_Interface $module Module.
	 */
	private function render_courier_cards( $module ) {
		$module_id = $module->id();

		$fields   = self::module_fields( $module );
		$sections = method_exists( $module, 'settings_sections' ) ? (array) $module->settings_sections() : array();

		// A module may own its complete settings UI through
		// render_settings_custom(). In that case there is no generic accordion.
		if ( empty( $fields ) ) {
			return;
		}

		// Core-injected sections (pricing / presentation / tracking sync) for couriers.
		if ( $module instanceof \BgCommerce3\Modules\Shipping\Courier_Interface && ! empty( $sections ) ) {
			$sections = array_merge(
				$sections,
				\BgCommerce3\Shipping\Pricing::sections_for( $module ),
				\BgCommerce3\Shipping\Tracking_Sync::sections_for( $module ),
				\BgCommerce3\Shipping\Cod_Payout_Sync_Settings::sections_for( $module )
			);
		}

		if ( ! empty( $sections ) ) {
			$used               = array();
			$advanced_sections  = array();

			// Advanced sections (§33/§34) are pulled out of their normal position
			// and rendered together, once, after everything else — so the main
			// screen never looks like an API debug panel by default.
			foreach ( $sections as $section ) {
				$keys = isset( $section['fields'] ) ? (array) $section['fields'] : array();
				$keys = array_values( array_filter( $keys, static function ( $k ) use ( $fields ) {
					return isset( $fields[ $k ] );
				} ) );
				if ( empty( $keys ) ) {
					continue;
				}

				if ( ! empty( $section['advanced'] ) ) {
					$advanced_sections[] = array( 'section' => $section, 'keys' => $keys );
					$used = array_merge( $used, $keys );
					continue;
				}

				$icon = isset( $section['icon'] ) ? $section['icon'] : 'settings';
				$desc = isset( $section['desc'] ) ? $section['desc'] : '';
				$this->accordion_open(
					$module_id . '-' . ( isset( $section['id'] ) ? sanitize_key( $section['id'] ) : sanitize_title( isset( $section['title'] ) ? $section['title'] : $icon ) ),
					$icon,
					isset( $section['title'] ) ? $section['title'] : '',
					$desc
				);
				$this->render_field_run( $module_id, $keys, $fields );
				$used = array_merge( $used, $keys );
				$this->accordion_close();
			}

			if ( ! empty( $advanced_sections ) ) {
				$this->accordion_open(
					$module_id . '-advanced',
					'sliders',
					__( 'Advanced settings', 'bg-commerce-suite' ),
					__( 'Technical and rarely used settings — you usually do not need to change them.', 'bg-commerce-suite' )
				);
				foreach ( $advanced_sections as $advanced ) {
					$section = $advanced['section'];
					$title   = isset( $section['title'] ) ? (string) $section['title'] : '';
					$desc    = isset( $section['desc'] ) ? (string) $section['desc'] : '';
					if ( '' !== $title ) {
						echo '<h3 class="bgcs-advanced-subsection__title">' . esc_html( $title ) . '</h3>';
					}
					if ( '' !== $desc ) {
						echo '<p class="bgcs-advanced-subsection__desc">' . esc_html( $desc ) . '</p>';
					}
					$this->render_field_run( $module_id, $advanced['keys'], $fields );
				}
				$this->accordion_close();
			}

			$leftover = array_diff( array_keys( $fields ), $used );
			if ( ! empty( $leftover ) ) {
				$this->accordion_open( $module_id . '-other', 'sliders', __( 'Other', 'bg-commerce-suite' ), '' );
				$this->render_field_run( $module_id, $leftover, $fields );
				$this->accordion_close();
			}
		} else {
			$this->accordion_open( $module_id . '-configuration', 'settings', __( 'Configuration', 'bg-commerce-suite' ), '' );
			$this->render_field_run( $module_id, array_keys( $fields ), $fields );
			$this->accordion_close();
		}
	}

	/**
	 * Renders a card's fields, splitting them into consecutive runs by shape.
	 *
	 * A checkbox renders as a full-width statement (`bgcs-field--full`), every
	 * other control as one cell of a `repeat(auto-fit, minmax(200px, 1fr))`
	 * grid. Putting both in ONE grid makes CSS Grid's auto-placement break the
	 * flow at each full-width item: the partially filled row before it keeps its
	 * empty cells, and the next normal field starts a fresh row. A card declared
	 * as `select, select, select, checkbox, select, checkbox, checkbox` rendered
	 * as three inputs, then a lone input stranded beside three empty cells.
	 *
	 * So each consecutive run gets its own container — grid fields in a grid,
	 * checkboxes in a stack — and neither can punch holes in the other. This is
	 * purely presentational: the declared order is preserved exactly, and no
	 * module has to change anything.
	 *
	 * @param string                                $module_id Module id.
	 * @param string[]                              $keys      Field keys, in declaration order.
	 * @param array<string,array<string,mixed>>     $fields    Field definitions.
	 */
	private function render_field_run( $module_id, array $keys, array $fields ) {
		$open = '';

		foreach ( $keys as $key ) {
			if ( ! isset( $fields[ $key ] ) ) {
				continue;
			}

			$type = isset( $fields[ $key ]['type'] ) ? $fields[ $key ]['type'] : 'text';
			$want = in_array( $type, array( 'checkbox', 'note' ), true ) ? 'stack' : 'grid';

			if ( $want !== $open ) {
				if ( '' !== $open ) {
					echo '</div>';
				}
				echo ( 'grid' === $want ) ? '<div class="bgcs-fieldgrid">' : '<div class="bgcs-checkstack">';
				$open = $want;
			}

			$this->field( $module_id, $key, $fields[ $key ], $fields );
		}

		if ( '' !== $open ) {
			echo '</div>';
		}
	}

	/* ----------------------------------------------------------------- */
	/* Reusable UI helpers                                                */
	/* ----------------------------------------------------------------- */

	/**
	 * A module's settings fields + the Core-injected generic courier fields
	 * (pricing ladder, presentation, post-label automation). Used by both the
	 * renderer and the save handler so they always agree.
	 *
	 * @param \BgCommerce3\Module\Module_Interface $module Module.
	 * @return array<string,array<string,mixed>>
	 */
	private static function module_fields( $module ) {
		// BGCS-AUDIT-003/-005/-016 — the composition lives in `Module_Settings`
		// so that the field set the panel renders and the field set every runtime
		// default is resolved from are, by construction, the same array.
		return \BgCommerce3\Support\Module_Settings::fields( $module );
	}

	/**
	 * Compact alert.
	 *
	 * @param string $type    success|warning|danger|info.
	 * @param string $message Message.
	 */
	private function alert( $type, $message ) {
		$icons = array(
			'success' => 'check',
			'warning' => 'alert',
			'danger'  => 'x-circle',
			'info'    => 'info',
		);
		$icon = isset( $icons[ $type ] ) ? $icons[ $type ] : 'info';
		echo '<div class="bgcs-alert bgcs-alert--' . esc_attr( $type ) . '">' . Icons::svg( $icon, 18 ) . '<span>' . esc_html( $message ) . '</span></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Render a structured synchronization result.
	 *
	 * @param array<string,mixed> $result Result.
	 */
	private function sync_alert( array $result ) {
		$level = isset( $result['level'] ) ? $result['level'] : 'error';
		$type  = 'error' === $level ? 'danger' : $level;
		$parts = array();

		if ( ! empty( $result['message'] ) ) {
			$parts[] = (string) $result['message'];
		}
		if ( ! empty( $result['counts'] ) && is_array( $result['counts'] ) ) {
			$counts = array();
			foreach ( $result['counts'] as $name => $count ) {
				$counts[] = sanitize_text_field( (string) $name ) . ': ' . absint( $count );
			}
			$parts[] = implode( ', ', $counts );
		}
		if ( ! empty( $result['updated'] ) ) {
			/* translators: %s: comma-separated list of updated datasets. */
			$parts[] = sprintf( __( 'Updated: %s', 'bg-commerce-suite' ), implode( ', ', array_map( 'sanitize_text_field', (array) $result['updated'] ) ) );
		}
		if ( ! empty( $result['preserved'] ) ) {
			/* translators: %s: comma-separated list of preserved datasets. */
			$parts[] = sprintf( __( 'Saved: %s', 'bg-commerce-suite' ), implode( ', ', array_map( 'sanitize_text_field', (array) $result['preserved'] ) ) );
		}
		if ( ! empty( $result['errors'] ) ) {
			/* translators: %s: comma-separated list of synchronization issues. */
			$parts[] = sprintf( __( 'Issues: %s', 'bg-commerce-suite' ), implode( ', ', array_map( 'sanitize_text_field', (array) $result['errors'] ) ) );
		}

		$this->alert( $type, implode( ' ', $parts ) );
	}

	/**
	 * Open a card with an icon header.
	 *
	 * @param string $icon  Icon name.
	 * @param string $title Title.
	 * @param string $desc  Optional description.
	 */
	private function card_open( $icon, $title, $desc = '', $logo_html = '' ) {
		echo '<section class="bgcs-card">';
		echo '<div class="bgcs-card__head">';
		if ( '' !== $logo_html ) {
			echo '<span class="bgcs-card__icon bgcs-card__icon--logo">' . $logo_html . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} else {
			echo '<span class="bgcs-card__icon">' . Icons::svg( $icon, 20 ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '<div class="bgcs-card__titles"><h2 class="bgcs-card__title">' . esc_html( $title ) . '</h2>';
		if ( '' !== $desc ) {
			echo '<p class="bgcs-card__desc">' . esc_html( $desc ) . '</p>';
		}
		echo '</div></div>';
		echo '<div class="bgcs-card__body">';
	}

	private function card_close() {
		echo '</div></section>';
	}

	/**
	 * Render a courier's real-check readiness list (Master Instruction §9):
	 * "SPEEDY STATUS" with one row per real check (API, sender, locations,
	 * pricing, COD, shipping method, …) instead of a decorative checklist.
	 *
	 * @param \BgCommerce3\Module\Module_Interface $module Module implementing setup_status().
	 */
	private function render_setup_status( $module ) {
		$rows = (array) $module->setup_status();
		if ( empty( $rows ) ) {
			return;
		}

		$all_ok = \BgCommerce3\Shipping\Setup_Status::all_ok( $rows );
		if ( $all_ok ) {
			return;
		}

		$ready = \BgCommerce3\Shipping\Setup_Status::is_ready( $rows );
		$icons = array(
			\BgCommerce3\Shipping\Setup_Status::STATE_OK   => array( 'check', 'success' ),
			\BgCommerce3\Shipping\Setup_Status::STATE_WARN => array( 'alert', 'warning' ),
			\BgCommerce3\Shipping\Setup_Status::STATE_FAIL => array( 'x-circle', 'danger' ),
		);

		echo '<section class="bgcs-card bgcs-setup-status">';
		echo '<div class="bgcs-card__head"><span class="bgcs-card__icon">' . Icons::svg( $ready ? 'check' : 'alert', 20 ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<div class="bgcs-card__titles"><h2 class="bgcs-card__title">' . esc_html( $module->name() ) . ' — ' . esc_html__( 'readiness', 'bg-commerce-suite' ) . '</h2>';
		echo '<p class="bgcs-card__desc">' . esc_html( $ready
			? __( 'The main configuration is ready, but there are still recommended steps.', 'bg-commerce-suite' )
			: __( 'Not ready to use yet — see what is missing below.', 'bg-commerce-suite' ) ) . '</p></div></div>';
		echo '<div class="bgcs-card__body"><ul class="bgcs-setup-status__list">';
		foreach ( $rows as $row ) {
			$state = isset( $row['state'] ) ? $row['state'] : \BgCommerce3\Shipping\Setup_Status::STATE_FAIL;
			list( $icon, $tone ) = isset( $icons[ $state ] ) ? $icons[ $state ] : $icons[ \BgCommerce3\Shipping\Setup_Status::STATE_FAIL ];
			echo '<li class="bgcs-setup-status__row bgcs-tone--' . esc_attr( $tone ) . '">';
			echo '<span class="bgcs-setup-status__icon">' . Icons::svg( $icon, 16 ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<span class="bgcs-setup-status__label">' . esc_html( isset( $row['label'] ) ? $row['label'] : '' ) . '</span>';
			if ( ! empty( $row['hint'] ) ) {
				echo '<span class="bgcs-setup-status__hint">' . esc_html( $row['hint'] ) . '</span>';
			}
			if ( ! empty( $row['id'] ) ) {
				$targets = array(
					'api'       => 'account',
					'account'   => 'account',
					'partner'   => 'account',
					'sender'    => 'account',
					'locations' => 'locations',
					'pricing'   => 'methods',
					'cod'       => 'methods',
					'method'    => 'methods',
					'delivery'  => 'methods',
					'tracking'  => 'tracking',
				);
				if ( isset( $targets[ $row['id'] ] ) ) {
					echo '<a class="bgcs-setup-status__action" href="#bgcs-' . esc_attr( $module->id() ) . '-' . esc_attr( $targets[ $row['id'] ] ) . '">' . esc_html__( 'Overview', 'bg-commerce-suite' ) . '</a>';
				}
			}
			echo '</li>';
		}
		echo '</ul></div></section>';
	}

	/**
	 * "Локации на {куриер}" status (Master Instruction §30): офиси/автомати
	 * counts, синхронизирани/не status and the next automatic Action Scheduler
	 * run — merchant-facing only, no cron internals.
	 *
	 * @param \BgCommerce3\Modules\Shipping\Courier_Interface $module Courier module.
	 */
	private function render_locations_status( $module ) {
		$courier_id    = $module->id();
		$office_count  = (int) \BgCommerce3\Shipping\Office_Store::meta( $courier_id, 'office' )['count'];
		$locker_count  = (int) \BgCommerce3\Shipping\Office_Store::meta( $courier_id, 'locker' )['count'];
		$has_locations = $office_count > 0 || $locker_count > 0;
		if ( $has_locations ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icons::svg() returns allowlisted static markup; the text is escaped here.
			$status_markup = Icons::svg( 'check', 16 ) . '<strong>' . esc_html__( 'Synchronized', 'bg-commerce-suite' ) . '</strong>';
		} else {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icons::svg() returns allowlisted static markup; the text is escaped here.
			$status_markup = Icons::svg( 'alert', 16 ) . '<strong>' . esc_html__( 'No locations have been synchronized yet', 'bg-commerce-suite' ) . '</strong>';
		}

		echo '<div class="bgcs-locations-status">';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $status_markup contains allowlisted static SVG markup and escaped text.
		echo '<p class="bgcs-locations-status__line">' . $status_markup . '</p>';
		/* translators: %d: number of synchronized courier offices. */
		echo '<p class="bgcs-locations-status__line">' . esc_html( sprintf( __( 'Offices: %d', 'bg-commerce-suite' ), $office_count ) ) . '</p>';
		/* translators: %d: number of synchronized courier lockers. */
		echo '<p class="bgcs-locations-status__line">' . esc_html( sprintf( __( 'Lockers: %d', 'bg-commerce-suite' ), $locker_count ) ) . '</p>';

		if ( function_exists( 'as_next_scheduled_action' ) && 'yes' === bgcs3_get_option( 'checkout', 'auto_sync_locations', 'no' ) ) {
			$next = as_next_scheduled_action( \BgCommerce3\Background\Locations_Sync::HOOK, array(), \BgCommerce3\Background\Locations_Sync::GROUP );
			if ( $next ) {
				/* translators: %s: date and time of the next automatic location update. */
				echo '<p class="bgcs-locations-status__line">' . esc_html( sprintf( __( 'Next automatic update: %s', 'bg-commerce-suite' ), wp_date( 'd.m.Y H:i', (int) $next ) ) ) . '</p>';
			}
		}
		echo '</div>';
	}

	/**
	 * Activation control for full-page modules that do not have the shared
	 * settings form (for example COD Reports).
	 *
	 * @param \BgCommerce3\Module\Module_Interface $module Module.
	 */
	private function render_page_module_toggle( $module ) {
		$toggle_url = wp_nonce_url(
			add_query_arg(
				array(
					'action'     => 'bgcs3_toggle_addon',
					'module'     => $module->id(),
					'return_tab' => $module->id(),
				),
				admin_url( 'admin-post.php' )
			),
			'bgcs3_toggle_addon'
		);

		echo '<a class="bgcs-switch' . ( $module->is_enabled() ? ' is-on' : '' ) . '" href="' . esc_url( $toggle_url ) . '" role="switch" aria-checked="' . ( $module->is_enabled() ? 'true' : 'false' ) . '" data-bgcs-module-toggle data-bgcs-module-id="' . esc_attr( $module->id() ) . '" data-bgcs-enabled="' . ( $module->is_enabled() ? 'yes' : 'no' ) . '">';
		echo '<span class="bgcs-switch__knob"></span><span class="bgcs-switch__text">' . esc_html( $module->is_enabled() ? __( 'Enabled', 'bg-commerce-suite' ) : __( 'Disabled', 'bg-commerce-suite' ) ) . '</span></a>';
	}

	/**
	 * Compact module activation control associated with the settings form.
	 *
	 * @param \BgCommerce3\Module\Module_Interface $module Module.
	 */
	private function render_module_toggle( $module ) {
		echo '<label class="bgcs-module-toggle">';
		echo '<input form="bgcs-settings-form" type="checkbox" name="' . esc_attr( 'modules[' . $module->id() . ']' ) . '" value="yes" data-bgcs-module-toggle data-bgcs-module-id="' . esc_attr( $module->id() ) . '" data-bgcs-enabled="' . ( $module->is_enabled() ? 'yes' : 'no' ) . '" ' . checked( $module->is_enabled(), true, false ) . '>';
		echo '<span class="bgcs-module-toggle__control" aria-hidden="true"></span>';
		echo '<span class="bgcs-module-toggle__label">' . esc_html( $module->is_enabled() ? __( 'Enabled', 'bg-commerce-suite' ) : __( 'Disabled', 'bg-commerce-suite' ) ) . '</span>';
		echo '</label>';
	}

	/**
	 * Open an accessible, collapsed settings section.
	 *
	 * @param string $id     Stable section id.
	 * @param string $icon   Icon name.
	 * @param string $title  Section title.
	 * @param string $desc   Section description.
	 * @param string $status Optional complete|attention status.
	 */
	public function accordion_open( $id, $icon, $title, $desc = '', $status = '' ) {
		$section_id = sanitize_html_class( $id );
		$panel_id   = 'bgcs-accordion-' . $section_id;

		echo '<section class="bgcs-card bgcs-accordion" data-bgcs-accordion="' . esc_attr( $section_id ) . '" data-status="' . esc_attr( $status ) . '">';
		echo '<button type="button" class="bgcs-card__head bgcs-accordion__trigger" aria-expanded="false" aria-controls="' . esc_attr( $panel_id ) . '">';
		echo '<span class="bgcs-card__icon">' . Icons::svg( $icon, 20 ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<span class="bgcs-card__titles"><span class="bgcs-card__title">' . esc_html( $title ) . '</span>';
		if ( '' !== $desc ) {
			echo '<span class="bgcs-card__desc">' . esc_html( $desc ) . '</span>';
		}
		echo '</span><span class="bgcs-accordion__chevron" aria-hidden="true">' . Icons::svg( 'chevron-down', 18 ) . '</span></button>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<div id="' . esc_attr( $panel_id ) . '" class="bgcs-card__body bgcs-accordion__panel" hidden>';
	}

	/**
	 * Close an accordion section.
	 */
	public function accordion_close() {
		echo '</div></section>';
	}

	/**
	 * A single setting field (text / password / select). Preserves the original
	 * field name: settings[<module>][<key>].
	 *
	 * @param string              $module_id Module id.
	 * @param string              $key       Field key.
	 * @param array<string,mixed> $def       Field definition.
	 */
	private function field( $module_id, $key, $def, array $all_fields = array() ) {
		$type    = isset( $def['type'] ) ? $def['type'] : 'text';
		$label   = isset( $def['label'] ) ? $def['label'] : $key;
		$default = isset( $def['default'] ) ? $def['default'] : '';
		$desc    = isset( $def['description'] ) ? $def['description'] : '';
		$value   = bgcs3_get_option( $module_id, $key, $default );
		$name    = sprintf( 'settings[%s][%s]', $module_id, $key );

		// Conditional visibility: hide this field until a sibling field matches.
		// def['show_if'] = array( 'other_key' => 'value' | array( 'v1', 'v2' ) ).
		$show_if = '';
		if ( ! empty( $def['show_if'] ) && is_array( $def['show_if'] ) ) {
			$conds             = array();
			$initially_visible = true;
			foreach ( $def['show_if'] as $dep_key => $dep_vals ) {
				$dep_key_clean = sanitize_key( $dep_key );
				$wanted        = array_map( 'strval', (array) $dep_vals );
				$conds[ $dep_key_clean ] = $wanted;

				$dep_default = isset( $all_fields[ $dep_key_clean ]['default'] ) ? $all_fields[ $dep_key_clean ]['default'] : '';
				$actual      = (string) bgcs3_get_option( $module_id, $dep_key_clean, $dep_default );
				if ( ! in_array( $actual, $wanted, true ) ) {
					$initially_visible = false;
				}
			}
			$show_if = ' data-show-if="' . esc_attr( wp_json_encode( $conds ) ) . '" aria-hidden="' . ( $initially_visible ? 'false' : 'true' ) . '"' . ( $initially_visible ? '' : ' hidden' );
		}

		if ( 'note' === $type ) {
			// A read-only informational line — no input, no label, just a
			// conditional explanation (e.g. "own prices → sender pays", §12).
			echo '<div class="bgcs-field bgcs-field--full bgcs-field--note"' . $show_if . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<p class="bgcs-field__note">' . esc_html( $desc ) . '</p>';
			echo '</div>';
			return;
		}

		if ( 'checkbox' === $type ) {
			$checkbox_label = isset( $def['checkbox_label'] ) ? $def['checkbox_label'] : __( 'Active', 'bg-commerce-suite' );
			echo '<div class="bgcs-field bgcs-field--full"' . $show_if . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			$this->checkbox( $name, 'yes' === (string) $value, $label, $desc, $checkbox_label );
			echo '</div>';
			return;
		}

		if ( 'pricing_rules' === $type ) {
			$rules = \BgCommerce3\Shipping\Pricing::sanitize_rules(
				is_array( $value ) ? $value : array(),
				isset( $def['delivery_types'] ) && is_array( $def['delivery_types'] ) ? array_keys( $def['delivery_types'] ) : array()
			);
			$types = isset( $def['delivery_types'] ) && is_array( $def['delivery_types'] )
				? $def['delivery_types']
				: \BgCommerce3\Shipping\Pricing::type_labels();
			$id      = 'bgcs-f-' . sanitize_html_class( $module_id . '-' . $key );
			$help_id = 'bgcs-help-' . sanitize_html_class( $module_id . '-' . $key );

			echo '<div class="bgcs-field bgcs-field--full"' . $show_if . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<span class="bgcs-field__heading"><label class="bgcs-field__label" for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>';
			if ( '' !== $desc ) {
				$this->help_disclosure( $help_id, $desc, 'button' );
			}
			echo '</span>';
			echo '<div id="' . esc_attr( $id ) . '" class="bgcs-pricing-rules" data-input-prefix="' . esc_attr( $name ) . '">';
			echo '<div class="bgcs-pricing-rules__head" aria-hidden="true">';
			echo '<span>' . esc_html__( 'Type', 'bg-commerce-suite' ) . '</span>';
			echo '<span>' . esc_html__( 'Up to kg', 'bg-commerce-suite' ) . '</span>';
			echo '<span>' . esc_html__( 'Up to goods value', 'bg-commerce-suite' ) . '</span>';
			echo '<span>' . esc_html__( 'Price', 'bg-commerce-suite' ) . '</span>';
			echo '<span>' . esc_html__( 'Currency', 'bg-commerce-suite' ) . '</span>';
			echo '<span></span></div>';
			echo '<div class="bgcs-pricing-rules__rows">';

			foreach ( $rules as $index => $rule ) {
				$this->pricing_rule_row( $name, (string) $index, $rule, $types );
			}
			echo '</div>';

			// The template is inert HTML; JS replaces __INDEX__ before inserting it.
			echo '<template class="bgcs-pricing-rule-template">';
			$this->pricing_rule_row(
				$name,
				'__INDEX__',
				array(
					'id'              => '',
					'type'            => (string) key( $types ),
					'max_weight'      => 0,
					'max_order_total' => 0,
					'price'           => '',
					'currency'        => '',
				),
				$types
			);
			echo '</template>';
			echo '<button type="button" class="bgcs-btn bgcs-btn--sm bgcs-pricing-rule-add">+ ' . esc_html__( 'Add rule', 'bg-commerce-suite' ) . '</button>';
			echo '</div>';
			if ( '' !== $desc ) {
				$this->help_disclosure( $help_id, $desc, 'panel' );
			}
			echo '</div>';
			return;
		}

		$id = 'bgcs-f-' . sanitize_html_class( $module_id . '-' . $key );
		$help_id = 'bgcs-help-' . sanitize_html_class( $module_id . '-' . $key );
		echo '<div class="bgcs-field"' . $show_if . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<span class="bgcs-field__heading"><label class="bgcs-field__label" for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>';
		if ( '' !== $desc ) {
			$this->help_disclosure( $help_id, $desc, 'button' );
		}
		echo '</span>';

		if ( 'select' === $type && ! empty( $def['options'] ) ) {
			$options    = (array) $def['options'];
			$searchable = ! empty( $def['searchable'] );
			$label_key  = isset( $def['label_key'] ) ? sanitize_key( $def['label_key'] ) : '';
			$saved_label = $label_key ? (string) bgcs3_get_option( $module_id, $label_key, '' ) : '';
			$class      = $searchable ? ' class="bgcs-searchable-select"' : '';
			$placeholder = isset( $def['placeholder'] ) ? $def['placeholder'] : '';

			if ( '' !== (string) $value && ! array_key_exists( (string) $value, $options ) && '' !== $saved_label ) {
				$options[ (string) $value ] = $saved_label;
			}

			echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '"' . $class . ' data-placeholder="' . esc_attr( $placeholder ) . '"' . ( $label_key ? ' data-label-input="' . esc_attr( 'bgcs-f-' . $module_id . '-' . $label_key ) . '"' : '' ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			foreach ( $options as $opt_value => $opt_label ) {
				printf(
					'<option value="%s" %s>%s</option>',
					esc_attr( $opt_value ),
					selected( (string) $value, (string) $opt_value, false ),
					esc_html( $opt_label )
				);
			}
			echo '</select>';
			if ( $label_key ) {
				echo '<input type="hidden" id="' . esc_attr( 'bgcs-f-' . $module_id . '-' . $label_key ) . '" name="' . esc_attr( sprintf( 'settings[%s][%s]', $module_id, $label_key ) ) . '" value="' . esc_attr( $saved_label ) . '" />';
			}
		} elseif ( 'remote_select' === $type ) {
			$label_key   = isset( $def['label_key'] ) ? sanitize_key( $def['label_key'] ) : $key . '_label';
			$saved_label = (string) bgcs3_get_option( $module_id, $label_key, '' );
			$resource    = isset( $def['resource'] ) ? sanitize_key( $def['resource'] ) : 'offices';
			$depends_on  = isset( $def['depends_on'] ) ? sanitize_key( $def['depends_on'] ) : '';
			$minimum     = isset( $def['minimum_input_length'] ) ? absint( $def['minimum_input_length'] ) : 2;
			echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" class="bgcs-remote-select" data-module="' . esc_attr( $module_id ) . '" data-resource="' . esc_attr( $resource ) . '" data-depends-on="' . esc_attr( $depends_on ) . '" data-minimum-input-length="' . esc_attr( $minimum ) . '" data-label-input="' . esc_attr( 'bgcs-f-' . $module_id . '-' . $label_key ) . '">';
			if ( '' !== (string) $value ) {
				echo '<option value="' . esc_attr( (string) $value ) . '" selected>' . esc_html( $saved_label ? $saved_label : (string) $value ) . '</option>';
			}
			echo '</select>';
			echo '<input type="hidden" id="' . esc_attr( 'bgcs-f-' . $module_id . '-' . $label_key ) . '" name="' . esc_attr( sprintf( 'settings[%s][%s]', $module_id, $label_key ) ) . '" value="' . esc_attr( $saved_label ) . '" />';
		} elseif ( 'color' === $type ) {
			$hex = '' !== (string) $value ? (string) $value : ( isset( $def['default'] ) ? (string) $def['default'] : '#000000' );
			echo '<span class="bgcs-color">';
			echo '<input type="color" class="bgcs-color__swatch" value="' . esc_attr( $hex ) . '" data-target="' . esc_attr( $id ) . '" />';
			echo '<input type="text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '" class="bgcs-color__hex" placeholder="#RRGGBB" autocomplete="off" />';
			echo '</span>';
		} elseif ( 'media' === $type ) {
			echo '<span class="bgcs-media">';
			echo '<input type="text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '" class="bgcs-media__url" placeholder="https://…" autocomplete="off" />';
			echo '<button type="button" class="bgcs-btn bgcs-btn--sm bgcs-media__pick" data-target="' . esc_attr( $id ) . '">' . esc_html__( 'Select', 'bg-commerce-suite' ) . '</button>';
			echo '</span>';
		} else {
			$is_password      = 'password' === $type;
			$has_saved_secret = $is_password && '' !== trim( (string) $value );
			$input_type       = in_array( $type, array( 'password', 'number' ), true ) ? $type : 'text';
			$input_value      = $is_password ? '' : (string) $value;
			$placeholder      = $has_saved_secret ? '••••••••' : '';
			$status_id        = $id . '-saved-status';
			$number_attributes = '';
			if ( 'number' === $type ) {
				$number_attributes .= isset( $def['min'] ) ? ' min="' . esc_attr( $def['min'] ) . '"' : '';
				$number_attributes .= isset( $def['max'] ) ? ' max="' . esc_attr( $def['max'] ) . '"' : '';
				$number_attributes .= isset( $def['step'] ) ? ' step="' . esc_attr( $def['step'] ) . '"' : ' step="1"';
			}
			if ( $is_password ) {
				echo '<span class="bgcs-secret-field">';
			}
			printf(
				'<input type="%s" id="%s" name="%s" value="%s" autocomplete="%s" placeholder="%s"%s%s />',
				esc_attr( $input_type ),
				esc_attr( $id ),
				esc_attr( $name ),
				esc_attr( $input_value ),
				esc_attr( $is_password ? 'new-password' : 'off' ),
				esc_attr( $placeholder ),
				$has_saved_secret ? ' aria-describedby="' . esc_attr( $status_id ) . '"' : '',
				$number_attributes // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Атрибутите са екранирани поотделно.
			);
			if ( $has_saved_secret ) {
				echo '<span id="' . esc_attr( $status_id ) . '" class="bgcs-secret-field__status"><span class="bgcs-secret-field__check" aria-hidden="true">✓</span><span><strong>' . esc_html__( 'Saved.', 'bg-commerce-suite' ) . '</strong> ' . esc_html__( 'Leave empty to keep the current value, or enter a new one to replace it.', 'bg-commerce-suite' ) . '</span></span>';
			}
			if ( $is_password ) {
				echo '</span>';
			}
		}

		if ( '' !== $desc ) {
			$this->help_disclosure( $help_id, $desc, 'panel' );
		}
		echo '</div>';
	}

	/**
	 * Render one row of the generic static-price repeater.
	 *
	 * @param string               $base_name Base input name.
	 * @param string               $index     Numeric index or template placeholder.
	 * @param array<string,mixed>  $rule      Rule values.
	 * @param array<string,string> $types     Delivery type options.
	 */
	private function pricing_rule_row( $base_name, $index, array $rule, array $types ) {
		$prefix = $base_name . '[' . $index . ']';
		$id     = isset( $rule['id'] ) ? (string) $rule['id'] : '';
		$type   = isset( $rule['type'] ) ? (string) $rule['type'] : '';
		$weight = isset( $rule['max_weight'] ) && (float) $rule['max_weight'] > 0 ? (string) $rule['max_weight'] : '';
		$total  = isset( $rule['max_order_total'] ) && (float) $rule['max_order_total'] > 0 ? (string) $rule['max_order_total'] : '';
		$price  = isset( $rule['price'] ) ? (string) $rule['price'] : '';
		$curr   = isset( $rule['currency'] ) ? (string) $rule['currency'] : '';

		echo '<div class="bgcs-pricing-rule">';
		echo '<input type="hidden" data-rule-field="id" name="' . esc_attr( $prefix . '[id]' ) . '" value="' . esc_attr( $id ) . '" />';
		echo '<label><span class="screen-reader-text">' . esc_html__( 'Delivery type', 'bg-commerce-suite' ) . '</span><select data-rule-field="type" name="' . esc_attr( $prefix . '[type]' ) . '">';
		foreach ( $types as $value => $type_label ) {
			echo '<option value="' . esc_attr( $value ) . '" ' . selected( $type, (string) $value, false ) . '>' . esc_html( $type_label ) . '</option>';
		}
		echo '</select></label>';
		echo '<label><span class="screen-reader-text">' . esc_html__( 'Maximum weight in kilograms', 'bg-commerce-suite' ) . '</span><input data-rule-field="max_weight" type="number" min="0" step="0.01" name="' . esc_attr( $prefix . '[max_weight]' ) . '" value="' . esc_attr( $weight ) . '" placeholder="∞" /></label>';
		echo '<label><span class="screen-reader-text">' . esc_html__( 'Maximum goods value after discounts and taxes', 'bg-commerce-suite' ) . '</span><input data-rule-field="max_order_total" type="number" min="0" step="0.01" name="' . esc_attr( $prefix . '[max_order_total]' ) . '" value="' . esc_attr( $total ) . '" placeholder="∞" /></label>';
		echo '<label><span class="screen-reader-text">' . esc_html__( 'Final price incl. VAT', 'bg-commerce-suite' ) . '</span><input data-rule-field="price" type="number" min="0" step="0.01" name="' . esc_attr( $prefix . '[price]' ) . '" value="' . esc_attr( $price ) . '" placeholder="0.00" required /></label>';
		echo '<label><span class="screen-reader-text">' . esc_html__( 'Currency', 'bg-commerce-suite' ) . '</span><input data-rule-field="currency" type="text" maxlength="3" name="' . esc_attr( $prefix . '[currency]' ) . '" value="' . esc_attr( $curr ) . '" placeholder="EUR" /></label>';
		echo '<button type="button" class="bgcs-btn bgcs-btn--sm bgcs-btn--danger bgcs-pricing-rule-remove" aria-label="' . esc_attr__( 'Remove rule', 'bg-commerce-suite' ) . '">×</button>';
		echo '</div>';
	}

	/**
	 * Достъпна info контрола за помощния текст на поле.
	 *
	 * Описанието не е скрито от PHP, за да остане четимо без JavaScript.
	 *
	 * @param string $id          Уникален id на панела.
	 * @param string $description Помощен текст.
	 * @param string $part        button|panel.
	 */
	private function help_disclosure( $id, $description, $part ) {
		if ( 'button' === $part ) {
			echo '<button type="button" class="bgcs-help-toggle" aria-expanded="false" aria-controls="' . esc_attr( $id ) . '" aria-label="' . esc_attr__( 'Show more information', 'bg-commerce-suite' ) . '">' . Icons::svg( 'info', 16 ) . '</button>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			return;
		}
		echo '<span id="' . esc_attr( $id ) . '" class="bgcs-field__desc bgcs-help-panel">' . esc_html( $description ) . '</span>';
	}

	/**
	 * A checkbox row. Preserves the given field name.
	 *
	 * @param string $name    Input name (value posted as "yes").
	 * @param bool   $checked Checked.
	 * @param string $title   Bold label.
	 * @param string $desc    Optional description.
	 * @param string $inline  Optional inline text shown next to the checkbox.
	 */
	private function checkbox( $name, $checked, $title, $desc = '', $inline = '' ) {
		$text    = '' !== $inline ? $inline : $title;
		$help_id = 'bgcs-help-' . substr( md5( $name ), 0, 12 );
		echo '<div class="bgcs-check-wrap">';
		echo '<div class="bgcs-check__heading">';
		echo '<label class="bgcs-check">';
		echo '<input type="checkbox" name="' . esc_attr( $name ) . '" value="yes" ' . checked( $checked, true, false ) . ' />';
		echo '<span class="bgcs-check__text"><span>' . esc_html( $text ) . '</span></span>';
		echo '</label>';
		if ( '' !== $desc ) {
			$this->help_disclosure( $help_id, $desc, 'button' );
		}
		echo '</div>';
		if ( '' !== $desc ) {
			echo '<span id="' . esc_attr( $help_id ) . '" class="bgcs-check__desc bgcs-help-panel">' . esc_html( $desc ) . '</span>';
		}
		echo '</div>';
	}

	/**
	 * Tab → icon name.
	 *
	 * @param string $tab_id Tab id.
	 * @return string
	 */
	private function tab_icon( $tab_id ) {
		$builtin = array(
			'dashboard' => 'activity',
			'general'   => 'settings',
		);
		$default = isset( $builtin[ $tab_id ] ) ? $builtin[ $tab_id ] : 'truck';

		/**
		 * Filter the Lucide icon name used for a settings tab. Add-ons may set
		 * their own (used only when the module has no brand logo).
		 *
		 * @param string $icon   Icon name (see Icons::PATHS).
		 * @param string $tab_id Tab / module id.
		 */
		return (string) apply_filters( 'bgcs3_module_icon', $default, $tab_id );
	}

	/**
	 * Manually refresh a courier's cached reference data (offices/services/…).
	 */
	public function handle_sync() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission for this action.', 'bg-commerce-suite' ) );
		}

		check_admin_referer( self::SYNC_NONCE );

		/** @var Module_Registry $registry */
		$registry  = $this->container['modules'];
		$module_id = isset( $_POST['module'] ) ? sanitize_key( wp_unslash( $_POST['module'] ) ) : '';
		$module    = $registry->get( $module_id );

		$result = Sync_Result::error( __( 'The module was not found.', 'bg-commerce-suite' ) );
		if ( $module && method_exists( $module, 'sync_data' ) ) {
			$result = Sync_Result::from_mixed( $module->sync_data() );
			if ( $result->is_success() ) {
				Options::set( $module_id, '_last_sync_at', time() );
			}
		}

		set_transient( 'bgcs3_sync_msg_' . get_current_user_id(), $result->to_array(), 60 );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'   => self::MENU_SLUG,
					'tab'    => $module_id,
					'synced' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Refresh the selected sender profile/location without changing selection.
	 */
	public function handle_sender_sync() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission for this action.', 'bg-commerce-suite' ) );
		}

		check_admin_referer( self::SENDER_SYNC_NONCE );

		/** @var Module_Registry $registry */
		$registry  = $this->container['modules'];
		$module_id = isset( $_POST['module'] ) ? sanitize_key( wp_unslash( $_POST['module'] ) ) : '';
		$module    = $registry->get( $module_id );
		$result    = Sync_Result::error( __( 'The module was not found.', 'bg-commerce-suite' ) );

		if ( $module && method_exists( $module, 'refresh_sender_data' ) ) {
			$result = Sync_Result::from_mixed( $module->refresh_sender_data() );
			if ( $result->is_success() ) {
				Options::set( $module_id, '_last_sender_sync_at', time() );
			}
		}

		set_transient( 'bgcs3_sync_msg_' . get_current_user_id(), $result->to_array(), 60 );
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'   => self::MENU_SLUG,
					'tab'    => $module_id,
					'synced' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * "Свържи със Speedy" / "Провери връзката" (§6): validate credentials with a
	 * single non-destructive API call, no shipment created. Any courier module
	 * can opt in by implementing check_connection(); Core stays credential-agnostic.
	 */
	public function handle_check_connection() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission for this action.', 'bg-commerce-suite' ) );
		}

		check_admin_referer( self::CHECK_NONCE );

		/** @var Module_Registry $registry */
		$registry  = $this->container['modules'];
		$module_id = isset( $_POST['module'] ) ? sanitize_key( wp_unslash( $_POST['module'] ) ) : '';
		$module    = $registry->get( $module_id );
		$result    = Sync_Result::error( __( 'The module was not found.', 'bg-commerce-suite' ) );

		if ( $module && method_exists( $module, 'check_connection' ) ) {
			$result = Sync_Result::from_mixed( $module->check_connection() );
		}

		set_transient( 'bgcs3_sync_msg_' . get_current_user_id(), $result->to_array(), 60 );
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'   => self::MENU_SLUG,
					'tab'    => $module_id,
					'synced' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Fields owned by the task tab that initiated a save. Hidden tabs live in the
	 * same HTML form for a stable UX, but they must never be written merely
	 * because another tab was saved. Connection checks intentionally persist only
	 * the credentials/account fields they need before the provider call.
	 *
	 * @param \BgCommerce3\Module\Module_Interface $module Module.
	 * @param string                                  $scope  Task scope or connection.
	 * @param array<string,array<string,mixed>>       $fields All declared fields.
	 * @return string[]
	 */
	private function task_scope_field_keys( $module, $scope, array $fields ) {
		$scope = sanitize_key( (string) $scope );
		$id    = $module->id();
		if ( '' === $scope ) {
			return in_array( $id, array( 'speedy', 'econt', 'boxnow', 'pigeon' ), true ) ? array() : array_keys( $fields );
		}

		if ( in_array( $id, array( 'speedy', 'econt', 'boxnow', 'pigeon' ), true ) ) {
			$maps = $this->courier_workspace_field_map( $id, $fields );
			if ( 'connection' === $scope ) {
				return array_values( array_intersect( $maps['account'], array_keys( $fields ) ) );
			}
			if ( 'account' === $scope ) {
				return array_values( array_intersect( array_merge( $maps['account'], $maps['sender'] ), array_keys( $fields ) ) );
			}
			if ( 'methods' === $scope ) {
				$keys = array();
				foreach ( $maps['method_cards'] as $card ) {
					$keys = array_merge( $keys, isset( $card['fields'] ) ? (array) $card['fields'] : array() );
				}
				return array_values( array_intersect( array_unique( $keys ), array_keys( $fields ) ) );
			}
			if ( 'tracking' === $scope ) {
				return array_values( array_intersect( array_merge( $maps['tracking'], array( 'tracking_sync_enabled', 'tracking_sync_interval', 'status_after_label' ), \BgCommerce3\Shipping\Cod_Payout_Sync_Settings::field_keys_for( $module ), \BgCommerce3\Shipping\Tracking_Status_Policy::field_keys_for( $module ) ), array_keys( $fields ) ) );
			}
			if ( 'diagnostics' === $scope ) {
				return array_values( array_intersect( $maps['diagnostics'], array_keys( $fields ) ) );
			}

			return array();
		}


		return array_keys( $fields );
	}

	/**
	 * Whether a declared field is active for the values submitted in this save.
	 * Hidden branches are presentation AND data-contract state: they are not
	 * validated and are not overwritten while inactive.
	 *
	 * @param \BgCommerce3\Module\Module_Interface $module Module.
	 * @param array<string,mixed> $def Field definition.
	 * @param array<string,mixed> $module_input Submitted module values.
	 * @param string[] $allowed_keys Keys owned by the active task.
	 * @param array<string,array<string,mixed>> $fields All fields.
	 * @return bool
	 */
	private function conditional_field_is_active( $module, array $def, array $module_input, array $allowed_keys, array $fields ) {
		if ( empty( $def['show_if'] ) || ! is_array( $def['show_if'] ) ) {
			return true;
		}

		foreach ( $def['show_if'] as $dep_key => $wanted_values ) {
			$dep_key = sanitize_key( (string) $dep_key );
			$actual  = null;

			if ( array_key_exists( $dep_key, $module_input ) && is_scalar( $module_input[ $dep_key ] ) ) {
				$actual = (string) $module_input[ $dep_key ];
			} elseif ( in_array( $dep_key, $allowed_keys, true ) && isset( $fields[ $dep_key ] ) && 'checkbox' === ( isset( $fields[ $dep_key ]['type'] ) ? $fields[ $dep_key ]['type'] : '' ) ) {
				// An unchecked checkbox is intentionally absent from POST.
				$actual = 'no';
			} else {
				$dep_default = isset( $fields[ $dep_key ]['default'] ) ? $fields[ $dep_key ]['default'] : '';
				$actual      = (string) bgcs3_get_option( $module->id(), $dep_key, $dep_default );
			}

			$wanted_values = array_map( 'strval', (array) $wanted_values );
			if ( ! in_array( (string) $actual, $wanted_values, true ) ) {
				return false;
			}
		}

		return true;
	}

	/** Persist module-owned custom data only for the initiating task scope. */
	private function save_module_custom_scope( $module, $scope ) {
		if ( ! method_exists( $module, 'save_settings_custom' ) ) {
			return;
		}
		$method = new \ReflectionMethod( $module, 'save_settings_custom' );
		if ( $method->getNumberOfParameters() > 0 ) {
			$module->save_settings_custom( $scope );
		} else {
			$module->save_settings_custom();
		}
	}

	/** PII-free in-memory identity for account/environment settings. */
	private function account_settings_fingerprint( $module_id, array $keys, array $fields ) {
		$values = array();
		foreach ( $keys as $key ) {
			$default        = isset( $fields[ $key ]['default'] ) ? $fields[ $key ]['default'] : '';
			$values[ $key ] = bgcs3_get_option( $module_id, $key, $default );
		}
		return hash( 'sha256', wp_json_encode( $values ) );
	}

	public function handle_save() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission for this action.', 'bg-commerce-suite' ) );
		}

		check_admin_referer( self::NONCE );

		/** @var Module_Registry $registry */
		$registry = $this->container['modules'];

		$active_tab   = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : 'general';
		$button_scope = isset( $_POST['bgcs_task_scope'] ) ? sanitize_key( wp_unslash( $_POST['bgcs_task_scope'] ) ) : '';
		$active_scope = isset( $_POST['bgcs_active_task_scope'] ) ? sanitize_key( wp_unslash( $_POST['bgcs_active_task_scope'] ) ) : '';
		$task_scope   = '' !== $button_scope ? $button_scope : $active_scope;
		$task_action  = isset( $_POST['bgcs_task_action'] ) ? sanitize_key( wp_unslash( $_POST['bgcs_task_action'] ) ) : '';
		if ( 'check_connection' === $task_action ) {
			$task_scope = 'connection';
		} elseif ( 'refresh_sender' === $task_action && in_array( $active_tab, array( 'speedy', 'econt', 'boxnow', 'pigeon' ), true ) ) {
			$task_scope = 'account';
		}

		if ( 'general' === $active_tab ) {
			// Save checkout general options.
			$hide_fields = isset( $_POST['checkout']['hide_fields'] ) && 'yes' === $_POST['checkout']['hide_fields'];
			Options::set( 'checkout', 'hide_fields', $hide_fields ? 'yes' : 'no' );

			$show_map = isset( $_POST['checkout']['show_map'] ) && 'yes' === $_POST['checkout']['show_map'];
			Options::set( 'checkout', 'show_map', $show_map ? 'yes' : 'no' );

			$shipping_zone_fallback = isset( $_POST['checkout']['shipping_zone_fallback'] ) && 'yes' === $_POST['checkout']['shipping_zone_fallback'];
			$previous_fallback      = 'yes' === bgcs3_get_option( 'checkout', 'shipping_zone_fallback', 'no' );
			Options::set( 'checkout', 'shipping_zone_fallback', $shipping_zone_fallback ? 'yes' : 'no' );
			if ( $previous_fallback !== $shipping_zone_fallback && class_exists( '\WC_Cache_Helper' ) ) {
				\WC_Cache_Helper::get_transient_version( 'shipping', true );
			}

			$remember_selection = isset( $_POST['checkout']['remember_selection'] ) && 'yes' === $_POST['checkout']['remember_selection'];
			Options::set( 'checkout', 'remember_selection', $remember_selection ? 'yes' : 'no' );

			if ( current_user_can( 'manage_options' ) ) {
				$catalog_enabled = isset( $_POST['catalog']['enabled'] ) && 'yes' === $_POST['catalog']['enabled'];
				if ( $catalog_enabled ) {
					Options::set( 'catalog', 'enabled', 'yes' );
					Remote_Catalog::maybe_schedule();
				} else {
					Remote_Catalog::disable();
				}
			}

			$update_order_statuses = isset( $_POST['checkout']['update_order_statuses'] ) && 'yes' === $_POST['checkout']['update_order_statuses'];
			Options::set( 'checkout', 'update_order_statuses', $update_order_statuses ? 'yes' : 'no' );


			$auto_sync = isset( $_POST['checkout']['auto_sync_locations'] ) && 'yes' === $_POST['checkout']['auto_sync_locations'];
			Options::set( 'checkout', 'auto_sync_locations', $auto_sync ? 'yes' : 'no' );

			// Courier shipment panels have a fixed low-click initial state since 3.0.17.
			// Only document add-ons keep a configurable default-open preference.
			$document_accordion_open = isset( $_POST['ui']['document_accordion_open'] ) && 'yes' === $_POST['ui']['document_accordion_open'];
			Options::set( 'ui', 'document_accordion_open', $document_accordion_open ? 'yes' : 'no' );

			$shipment_snapshot = isset( $_POST['debug']['shipment_snapshot'] ) && 'yes' === $_POST['debug']['shipment_snapshot'];
			Options::set( 'debug', 'shipment_snapshot', $shipment_snapshot ? 'yes' : 'no' );
		} else {
			// Save fields only for the active courier tab.
			$module = $registry->get( $active_tab );
			if ( $module ) {
				// A saved value can change which fields a module declares (Econt's
				// dropdowns depend on the selected profile), so the memoized set is
				// dropped rather than serving one composed earlier in this request.
				\BgCommerce3\Support\Module_Settings::flush( $module->id() );

				$fields = self::module_fields( $module );

				if ( in_array( $module->id(), array( 'speedy', 'econt', 'boxnow', 'pigeon' ), true )
					&& ! in_array( $task_scope, array( 'account', 'locations', 'methods', 'tracking', 'diagnostics', 'connection' ), true ) ) {
					set_transient(
						'bgcs3_settings_errors_' . get_current_user_id(),
						array( __( 'The active section could not be identified. Nothing was saved. Open the intended section and try again.', 'bg-commerce-suite' ) ),
						60
					);
					wp_safe_redirect(
						add_query_arg(
							array( 'page' => self::MENU_SLUG, 'tab' => $active_tab, 'settings_error' => '1' ),
							admin_url( 'admin.php' )
						)
					);
					exit;
				}

				$settings_raw = isset( $_POST['settings'] ) && is_array( $_POST['settings'] )
					? map_deep( wp_unslash( $_POST['settings'] ), 'sanitize_text_field' )
					: array();
				$module_input = isset( $settings_raw[ $module->id() ] ) && is_array( $settings_raw[ $module->id() ] )
					? $settings_raw[ $module->id() ]
					: array();
				$allowed_keys = $this->task_scope_field_keys( $module, $task_scope, $fields );
				$account_keys = array();
				$account_fingerprint_before = '';
				if ( in_array( $task_scope, array( 'account', 'connection' ), true ) && in_array( $module->id(), array( 'speedy', 'econt', 'boxnow', 'pigeon' ), true ) ) {
					$workspace_maps = $this->courier_workspace_field_map( $module->id(), $fields );
					$account_keys   = array_values( array_intersect( $workspace_maps['account'], array_keys( $fields ) ) );
					$account_fingerprint_before = $this->account_settings_fingerprint( $module->id(), $account_keys, $fields );
				}

				// Validate BEFORE writing anything (§39/§40): an invalid own-prices
				// rule must never be silently persisted, and a rejected save must not
				// leave the tab half-written either.
				$validation_errors = array();
				foreach ( $fields as $field_key => $field_def ) {
					if ( ! in_array( $field_key, $allowed_keys, true ) ) {
						continue;
					}
					if ( ! $this->conditional_field_is_active( $module, $field_def, $module_input, $allowed_keys, $fields ) ) {
						continue;
					}
					if ( 'pricing_rules' !== ( isset( $field_def['type'] ) ? $field_def['type'] : '' ) ) {
						continue;
					}
					$raw_rules           = isset( $module_input[ $field_key ] ) && is_array( $module_input[ $field_key ] ) ? $module_input[ $field_key ] : array();
					$validation_errors = array_merge( $validation_errors, \BgCommerce3\Shipping\Pricing::validate_rules( $raw_rules ) );
				}

				if ( ! empty( $validation_errors ) ) {
					set_transient( 'bgcs3_settings_errors_' . get_current_user_id(), $validation_errors, 60 );
					wp_safe_redirect(
						add_query_arg(
							array(
								'page'           => self::MENU_SLUG,
								'tab'            => $active_tab,
								'settings_error' => '1',
							),
							admin_url( 'admin.php' )
						)
					);
					exit;
				}

				$submitted = isset( $_POST['modules'] ) && is_array( $_POST['modules'] )
					? array_map( 'sanitize_text_field', wp_unslash( $_POST['modules'] ) )
					: array();

				$enabled = isset( $submitted[ $module->id() ] ) && 'yes' === $submitted[ $module->id() ];
				$this->set_module_enabled( $module, $enabled );

				if ( ! empty( $fields ) ) {
					foreach ( $fields as $key => $def ) {
						if ( ! in_array( $key, $allowed_keys, true ) ) {
							continue;
						}
						if ( ! $this->conditional_field_is_active( $module, $def, $module_input, $allowed_keys, $fields ) ) {
							continue;
						}
						$field_type = isset( $def['type'] ) ? $def['type'] : 'text';
						$raw        = isset( $module_input[ $key ] ) ? $module_input[ $key ] : '';
						$current    = bgcs3_get_option( $module->id(), $key, isset( $def['default'] ) ? $def['default'] : '' );

						if ( 'note' === $field_type ) {
							// Read-only informational line — nothing is submitted, nothing to save.
							continue;
						} elseif ( 'pricing_rules' === $field_type ) {
							$raw = \BgCommerce3\Shipping\Pricing::sanitize_rules(
								is_array( $raw ) ? $raw : array(),
								$module instanceof \BgCommerce3\Modules\Shipping\Courier_Interface ? \BgCommerce3\Shipping\Pricing::supported_types( $module ) : array()
							);
							Options::set( $module->id(), $key, $raw );
							continue;
						} elseif ( 'checkbox' === $field_type ) {
							$raw = ( 'yes' === $raw ) ? 'yes' : 'no';
						} elseif ( 'select' === $field_type && ! empty( $def['options'] ) ) {
							$raw = array_key_exists( $raw, $def['options'] ) || (string) $raw === (string) $current
								? $raw
								: ( isset( $def['default'] ) ? $def['default'] : '' );
						} elseif ( 'remote_select' === $field_type ) {
							$raw = sanitize_text_field( (string) $raw );
						} elseif ( 'color' === $field_type ) {
							$hex = sanitize_hex_color( (string) $raw );
							$raw = ( null === $hex ) ? '' : $hex;
						} elseif ( 'media' === $field_type ) {
							$raw = esc_url_raw( (string) $raw );
						} elseif ( 'number' === $field_type ) {
							$raw = is_numeric( $raw ) ? (string) $raw : ( isset( $def['default'] ) ? (string) $def['default'] : '0' );
							if ( isset( $def['min'] ) ) {
								$raw = (string) max( (float) $def['min'], (float) $raw );
							}
							if ( isset( $def['max'] ) ) {
								$raw = (string) min( (float) $def['max'], (float) $raw );
							}
						} elseif ( 'password' === $field_type && '' === trim( (string) $raw ) ) {
							// Празно поле не изтрива вече записана тайна и тя никога не се връща в HTML.
							$raw = $current;
						}

						Options::set( $module->id(), $key, sanitize_text_field( (string) $raw ) );

						if ( in_array( $field_type, array( 'select', 'remote_select' ), true ) && ! empty( $def['label_key'] ) ) {
							$label_key = sanitize_key( $def['label_key'] );
							$label     = isset( $module_input[ $label_key ] ) ? sanitize_text_field( (string) $module_input[ $label_key ] ) : '';
							Options::set( $module->id(), $label_key, $label );
						}
					}
				}

				// Module-owned custom settings are task-scoped too. A connection
				// check must never validate or overwrite hidden shipping-price fields.
				$this->save_module_custom_scope( $module, $task_scope );

				// Anything read after this point in the request (sync, connection
				// check, diagnostics) must see the field set the new values imply.
				\BgCommerce3\Support\Module_Settings::flush( $module->id() );

				if ( ! empty( $account_keys ) ) {
					$account_fingerprint_after = $this->account_settings_fingerprint( $module->id(), $account_keys, $fields );
					if ( ! hash_equals( $account_fingerprint_before, $account_fingerprint_after ) ) {
						\BgCommerce3\Support\Cache::flush_courier( $module->id() );
						\BgCommerce3\Shipping\Office_Store::forget( $module->id() );
					}
				}

				if ( 'check_connection' === $task_action ) {
					$result = Sync_Result::error( __( 'This module does not support connection checks.', 'bg-commerce-suite' ) );
					if ( method_exists( $module, 'check_connection' ) ) {
						$result = Sync_Result::from_mixed( $module->check_connection() );
					}
					set_transient( 'bgcs3_sync_msg_' . get_current_user_id(), $result->to_array(), 60 );
					$target = add_query_arg(
						array(
							'page'    => self::MENU_SLUG,
							'tab'     => $active_tab,
							'updated' => '1',
							'synced'  => '1',
						),
						admin_url( 'admin.php' )
					);
					wp_safe_redirect( $target . '#bgcs-' . sanitize_html_class( $active_tab ) . '-account' );
					exit;
				}

				if ( 'refresh_sender' === $task_action ) {
					$result = Sync_Result::error( __( 'This module does not support sender refresh.', 'bg-commerce-suite' ) );
					if ( method_exists( $module, 'refresh_sender_data' ) ) {
						$result = Sync_Result::from_mixed( $module->refresh_sender_data() );
						if ( $result->is_success() ) {
							Options::set( $module->id(), '_last_sender_sync_at', time() );
						}
					}
					set_transient( 'bgcs3_sync_msg_' . get_current_user_id(), $result->to_array(), 60 );
					$target = add_query_arg(
						array(
							'page'    => self::MENU_SLUG,
							'tab'     => $active_tab,
							'updated' => '1',
							'synced'  => '1',
						),
						admin_url( 'admin.php' )
					);
					$anchor = in_array( $active_tab, array( 'speedy', 'econt', 'boxnow', 'pigeon' ), true )
						? '#bgcs-' . sanitize_html_class( $active_tab ) . '-account'
						: '';
					wp_safe_redirect( $target . $anchor );
					exit;
				}
			}
		}

		$target = add_query_arg(
			array(
				'page'    => self::MENU_SLUG,
				'tab'     => $active_tab,
				'updated' => '1',
			),
			admin_url( 'admin.php' )
		);
		if ( '' !== $task_scope && 'general' !== $active_tab ) {
			$target .= '#bgcs-' . sanitize_html_class( $active_tab ) . '-' . sanitize_html_class( $task_scope );
		}
		wp_safe_redirect( $target );
		exit;
	}
}
