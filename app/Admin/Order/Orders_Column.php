<?php
/**
 * Колона за товарителница в списъка с поръчки на WooCommerce (Classic + HPOS).
 *
 * Показва куриерското лого/текст и номера на генерираната товарителница с линк за проследяване,
 * или бутон за бързо генериране чрез AJAX.
 *
 * @package BgCommerce3\Admin\Order
 */

namespace BgCommerce3\Admin\Order;

use BgCommerce3\Admin\Admin_Screen;
use BgCommerce3\Admin\Icons;
use BgCommerce3\Container\Container;
use BgCommerce3\Modules\Shipping\Courier_Interface;
use BgCommerce3\Shipping\Creation_Lock;
use BgCommerce3\Shipping\Label_Snapshot;
use BgCommerce3\Shipping\Shipment_Creation;

defined( 'ABSPATH' ) || exit;

class Orders_Column {

	const NONCE = 'bgcs3_orders_list';
	const COLUMN_KEY = 'bgcs3_waybill';

	/** @var Container */
	private $container;

	/**
	 * @param Container $container Core DI container.
	 */
	public function __construct( Container $container ) {
		$this->container = $container;
	}

	/**
	 * Инициализация на филтри и действия.
	 */
	public function init() {
		// Регистрация на колоната в списъка с поръчки (Classic CPT + HPOS).
		add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_column' ), 20 );
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( $this, 'add_column' ), 20 );

		// Рендиране на съдържанието на колоната (Classic CPT + HPOS).
		add_action( 'manage_shop_order_posts_custom_column', array( $this, 'render_column' ), 20, 2 );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( $this, 'render_column' ), 20, 2 );

		// Зареждане на скриптове и стилове в списъка с поръчки.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );

		// AJAX действие за генериране на товарителница от списъка.
		add_action( 'wp_ajax_bgcs3_quick_create_label', array( $this, 'ajax_quick_create_label' ) );
	}

	/**
	 * Добавяне на колоната в таблицата с поръчки.
	 *
	 * @param array<string,string> $columns Съществуващи колони.
	 * @return array<string,string>
	 */
	public function add_column( $columns ) {
		$new          = array();
		$inserted     = false;
		$target_after = 'order_status';

		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( $key === $target_after ) {
				$new[ self::COLUMN_KEY ] = __( 'Shipment label', 'bg-commerce-suite' );
				$inserted                = true;
			}
		}

		if ( ! $inserted ) {
			$new[ self::COLUMN_KEY ] = __( 'Shipment label', 'bg-commerce-suite' );
		}

		return $new;
	}

	/**
	 * Рендиране на клетката за конкретна поръчка.
	 *
	 * @param string $column Ключ на колоната.
	 * @param mixed  $ref    Post ID (Classic) или WC_Order обект (HPOS).
	 */
	public function render_column( $column, $ref ) {
		if ( self::COLUMN_KEY !== $column ) {
			return;
		}

		$order = ( $ref instanceof \WC_Order ) ? $ref : wc_get_order( $ref );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		echo $this->render_cell_content( $order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Генериране на HTML съдържанието на клетката.
	 *
	 * @param \WC_Order $order Поръчка.
	 * @return string
	 */
	public function render_cell_content( \WC_Order $order ) {
		$selection  = $order->get_meta( '_bgcs3_selection' );
		$courier_id = ( is_array( $selection ) && ! empty( $selection['courier'] ) ) ? sanitize_key( $selection['courier'] ) : '';

		if ( '' === $courier_id ) {
			return '<span class="bgcs-wb-empty">—</span>';
		}

		$label     = $order->get_meta( '_bgcs3_label' );
		$wb_number = ( is_array( $label ) && ! empty( $label['number'] ) ) ? (string) $label['number'] : '';

		$module       = $this->container['modules']->get( $courier_id );
		$courier_name = $module ? $module->name() : $courier_id;
		$courier_logo = Icons::courier_logo( $courier_id, $courier_name );

		$courier_html = '<div class="bgcs-wb-cell__courier" title="' . esc_attr( $courier_name ) . '">';
		if ( ! empty( $courier_logo ) ) {
			$courier_html .= $courier_logo;
		} else {
			$courier_html .= '<span class="bgcs-wb-cell__courier-text">' . esc_html( $courier_name ) . '</span>';
		}
		$courier_html .= '</div>';

		$order_id = $order->get_id();

		if ( '' !== $wb_number ) {
			$track_url = ( $module && method_exists( $module, 'tracking_url' ) ) ? $module->tracking_url( $wb_number ) : '';

			if ( ! empty( $track_url ) ) {
				$number_html = sprintf(
					'<a href="%s" class="bgcs-wb-cell__number bgcs-wb-cell__number--link" target="_blank" rel="noopener noreferrer" title="%s">%s</a>',
					esc_url( $track_url ),
					esc_attr__( 'Track shipment', 'bg-commerce-suite' ),
					esc_html( $wb_number )
				);
			} else {
				$number_html = sprintf(
					'<span class="bgcs-wb-cell__number">%s</span>',
					esc_html( $wb_number )
				);
			}

			return sprintf(
				'<div class="bgcs-wb-cell bgcs-wb-cell--has-wb" data-order-id="%d">%s%s</div>',
				(int) $order_id,
				$courier_html,
				$number_html
			);
		}

		// Няма товарителница — бутон за създаване чрез AJAX.
		$button_html = sprintf(
			'<button type="button" class="button button-small bgcs-wb-create-btn" data-order-id="%d">%s</button>',
			(int) $order_id,
			esc_html__( 'Generate', 'bg-commerce-suite' )
		);

		return sprintf(
			'<div class="bgcs-wb-cell bgcs-wb-cell--no-wb" data-order-id="%d">%s%s<span class="bgcs-wb-cell__msg" aria-live="polite"></span></div>',
			(int) $order_id,
			$courier_html,
			$button_html
		);
	}

	/**
	 * Зареждане на активи (JS/CSS) в списъка с поръчки.
	 *
	 * @param string $hook Име на текущия хук/екран.
	 */
	public function enqueue( $hook ) {
		if ( ! Admin_Screen::is_any_order() ) {
			return;
		}

		wp_enqueue_style( 'bgcs-admin', BGCS3_URL . 'assets/admin/admin.css', array(), BGCS3_VERSION );
		wp_enqueue_script( 'bgcs-orders-list', BGCS3_URL . 'assets/admin/orders-list.js', array( 'jquery' ), BGCS3_VERSION, true );
		wp_localize_script(
			'bgcs-orders-list',
			'bgcsOrdersList',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE ),
				'i18n'    => array(
					'generating' => __( 'Generating…', 'bg-commerce-suite' ),
					'generate'   => __( 'Generate', 'bg-commerce-suite' ),
					'error'      => __( 'Generation error.', 'bg-commerce-suite' ),
				),
			)
		);
	}

	/**
	 * AJAX обработчик за създаване на товарителница от списъка с поръчки.
	 */
	public function ajax_quick_create_label() {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission for this action.', 'bg-commerce-suite' ) ) );
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$order    = wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order ) {
			wp_send_json_error( array( 'message' => __( 'Invalid order.', 'bg-commerce-suite' ) ) );
		}

		$existing = $order->get_meta( '_bgcs3_label' );
		if ( is_array( $existing ) && ! empty( $existing['number'] ) ) {
			wp_send_json_error( array( 'message' => __( 'A shipment label has already been created for this order.', 'bg-commerce-suite' ) ) );
		}

		$selection  = $order->get_meta( '_bgcs3_selection' );
		$courier_id = ( is_array( $selection ) && ! empty( $selection['courier'] ) ) ? sanitize_key( $selection['courier'] ) : '';
		$courier    = $this->container['modules']->get( $courier_id );

		if ( ! $courier instanceof Courier_Interface ) {
			wp_send_json_error( array( 'message' => __( 'The courier module is not available or active.', 'bg-commerce-suite' ) ) );
		}

		// Rule 25 — double-click / concurrent-request protection (BGCS-AUDIT-001).
		// Същият примитив и същият критичен участък като в `MetaBox` — ключалката
		// се държи и докато `_bgcs3_label` се записва, иначе втора заявка влиза
		// точно в процепа между връщането на куриера и записа. `wp_send_json_*`
		// приключва заявката през wp_die(), който не изпълнява `finally`, затова
		// всеки изход освобождава ключалката изрично.
		$lock_key = 'bgcs3_create_lock_' . $order->get_id();
		$lock     = new Creation_Lock();
		$owner    = $lock->acquire( $lock_key );
		if ( false === $owner ) {
			wp_send_json_error( array( 'message' => __( 'Shipment label creation is already in progress for this order. Wait a few seconds.', 'bg-commerce-suite' ) ) );
		}
		$creation = Shipment_Creation::start( $order, $courier );
		if ( true !== $creation ) {
			$lock->release( $lock_key, $owner );
			wp_send_json_error( array( 'message' => implode( ' ', (array) $creation->errors ) ) );
		}

		try {
			$result = $courier->create_label( $order );

			if ( ! $result->success ) {
				$result = Shipment_Creation::finalize_failure( $order, $result );
				$errors = implode( ' ', $result->errors );
				$lock->release( $lock_key, $owner );
				wp_send_json_error( array( 'message' => $errors ) );
			}

			Label_Snapshot::apply( $result, $order, $courier );
			Shipment_Creation::complete( $order, $result );

			$order->update_meta_data( '_bgcs3_label', $result->to_array() );
			/* translators: %s: newly created shipment label number. */
			$order->add_order_note( sprintf( __( 'Shipment label created: %s', 'bg-commerce-suite' ), $result->number ) );
			$order->save();
		} catch ( \Throwable $e ) {
			Shipment_Creation::finalize_exception( $order );
			$lock->release( $lock_key, $owner );
			throw $e;
		}

		$lock->release( $lock_key, $owner );

		// Автоматизации след създаване: статус на поръчката + tracking имейл към клиент.
		\BgCommerce3\Shipping\Pricing::after_label_created( $order, $courier, $result->number );

		wp_send_json_success( array(
			'number' => $result->number,
			'html'   => $this->render_cell_content( $order ),
		) );
	}

}
