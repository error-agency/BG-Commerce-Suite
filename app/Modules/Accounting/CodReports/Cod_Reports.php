<?php
/**
 * COD reports module: a full-page tab (Core render_page support) that tracks
 * cash-on-delivery amounts across ALL couriers — which payouts are still
 * pending, which are received, per-period totals and CSV export.
 *
 * Data model: no custom tables. Every order that has a waybill
 * (`_bgcs3_label`) and a COD payment method is a report row; the payout state
 * lives on the order itself:
 *   - `_bgcs3_cod_paid`      'yes' when the courier paid the amount out,
 *   - `_bgcs3_cod_paid_date` ISO date of the payout mark.
 *
 * Reconciliation supports manual row actions and a two-step courier CSV flow:
 * preview/validation first, then explicit application. Direct retrieval of
 * payout reports from courier APIs remains outside this add-on version.
 *
 * This class is the composition root and full-page renderer. Order queries and
 * waybill indexing live in {@see Report_Repository}; the `admin_post` request
 * handling (mark / export / import) lives in {@see Report_Controller}.
 *
 * @package BgCommerce3\CodReports
 */

namespace BgCommerce3\Modules\Accounting\CodReports;

use BgCommerce3\Admin\Icons;
use BgCommerce3\Container\Container;
use BgCommerce3\Module\Abstract_Module;
use BgCommerce3\Module\Categories;

defined( 'ABSPATH' ) || exit;

class Cod_Reports extends Abstract_Module {

	const ID = 'cod_reports';

	const META_PAID      = '_bgcs3_cod_paid';
	const META_PAID_DATE = '_bgcs3_cod_paid_date';

	/** @var Report_Repository */
	private $repository;

	/** @var Report_Controller */
	private $controller;

	/** @var Import_Renderer */
	private $import_renderer;

	public function __construct() {
		$this->repository      = new Report_Repository();
		$this->controller      = new Report_Controller( $this->repository );
		$this->import_renderer = new Import_Renderer();
	}

	public function id() {
		return self::ID;
	}

	public function name() {
		return __( 'COD reports', 'bg-commerce-suite' );
	}

	public function category() {
		return Categories::ACCOUNTING;
	}

	/**
	 * COD Reports follows the same internal-module lifecycle as the other
	 * unified modules. Disabled means visible/configurable in the BGCS admin
	 * catalog, but no report controllers/actions are registered.
	 *
	 * The enable flag itself is provided by Abstract_Module::is_enabled().
	 */

	public function settings_tab() {
		return array(
			'id'    => self::ID,
			'title' => $this->name(),
			'group' => self::ID,
		);
	}

	/**
	 * @param Container $container Core container.
	 */
	public function register( Container $container ) {
		$this->controller->register();
	}

	/* ------------------------------------------------------------------ */
	/* Page                                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * Full-page tab content (rendered by Core inside the shell, no form).
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$f        = $this->controller->filters();
		$rows     = $this->repository->rows( $f );
		$couriers = $this->controller->couriers();

		// Totals over the whole period (before the status filter).
		$pending_sum   = 0.0;
		$paid_sum      = 0.0;
		$pending_count = 0;
		$paid_count    = 0;
		$currency      = get_woocommerce_currency();
		foreach ( $rows as $row ) {
			$currency = $row['currency'];
			if ( $row['paid'] ) {
				$paid_sum += $row['amount'];
				$paid_count++;
			} else {
				$pending_sum += $row['amount'];
				$pending_count++;
			}
		}

		// ---- Stat tiles ------------------------------------------------------
		echo '<div class="bgcs-stats">';
		$tiles = array(
			array( 'banknote', wc_price( $pending_sum, array( 'currency' => $currency ) ), sprintf( /* translators: %d: count */ __( 'Pending COD (%d shipments)', 'bg-commerce-suite' ), $pending_count ) ),
			array( 'check', wc_price( $paid_sum, array( 'currency' => $currency ) ), sprintf( /* translators: %d: count */ __( 'Paid COD (%d shipments)', 'bg-commerce-suite' ), $paid_count ) ),
			array( 'receipt', (string) count( $rows ), __( 'COD shipments for the period', 'bg-commerce-suite' ) ),
		);
		foreach ( $tiles as $tile ) {
			echo '<div class="bgcs-stat">';
			echo '<span class="bgcs-stat__icon">' . Icons::svg( $tile[0], 20 ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<div class="bgcs-stat__body"><div class="bgcs-stat__value">' . wp_kses_post( $tile[1] ) . '</div>';
			echo '<div class="bgcs-stat__label">' . esc_html( $tile[2] ) . '</div></div>';
			echo '</div>';
		}
		echo '</div>';

		// ---- Courier payout import -------------------------------------------
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only feedback after nonce-protected redirects.
		$error_code = isset( $_GET['cod_import_error'] ) ? sanitize_key( wp_unslash( $_GET['cod_import_error'] ) ) : '';
		$has_result = isset( $_GET['imported_cnt'] ) || isset( $_GET['skipped_cnt'] ) || isset( $_GET['conflict_cnt'] ) || isset( $_GET['unmatched_cnt'] ) || isset( $_GET['already_paid_cnt'] ) || isset( $_GET['duplicate_cnt'] );
		$result     = $has_result
			? array(
				'updated'      => isset( $_GET['imported_cnt'] ) ? absint( $_GET['imported_cnt'] ) : 0,
				'skipped'      => isset( $_GET['skipped_cnt'] ) ? absint( $_GET['skipped_cnt'] ) : 0,
				'conflicts'    => isset( $_GET['conflict_cnt'] ) ? absint( $_GET['conflict_cnt'] ) : 0,
				'unmatched'    => isset( $_GET['unmatched_cnt'] ) ? absint( $_GET['unmatched_cnt'] ) : 0,
				'already_paid' => isset( $_GET['already_paid_cnt'] ) ? absint( $_GET['already_paid_cnt'] ) : 0,
				'duplicates'   => isset( $_GET['duplicate_cnt'] ) ? absint( $_GET['duplicate_cnt'] ) : 0,
			)
			: array();
		// phpcs:enable
		echo $this->import_renderer->render_feedback( $error_code, $result ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- renderer escapes every dynamic value.

		$import_preview = $this->controller->requested_import_preview();
		if ( null === $import_preview ) {
			// Offered first: a courier that can report its own payouts spares
			// the merchant the file entirely. Renders nothing when no installed
			// courier offers it.
			echo $this->import_renderer->render_fetch_form( $this->controller->payout_couriers() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- renderer-owned safe HTML.
			echo $this->import_renderer->render_upload_form(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- renderer-owned safe HTML.
		} else {
			echo $this->import_renderer->render_preview( $import_preview['preview'], $import_preview['token'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- renderer escapes every dynamic value.
		}

		// ---- Filter bar (plain GET form back to this tab) --------------------
		echo '<form class="bgcs-filters" method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
		echo '<input type="hidden" name="page" value="bgcs3-settings" /><input type="hidden" name="tab" value="' . esc_attr( self::ID ) . '" />';

		echo '<div class="bgcs-filters__field"><label>' . esc_html__( 'From date', 'bg-commerce-suite' ) . '</label>';
		echo '<input type="date" name="from" value="' . esc_attr( $f['from'] ) . '" /></div>';

		echo '<div class="bgcs-filters__field"><label>' . esc_html__( 'To date', 'bg-commerce-suite' ) . '</label>';
		echo '<input type="date" name="to" value="' . esc_attr( $f['to'] ) . '" /></div>';

		echo '<div class="bgcs-filters__field"><label>' . esc_html__( 'Courier', 'bg-commerce-suite' ) . '</label><select name="courier">';
		echo '<option value="">' . esc_html__( 'All', 'bg-commerce-suite' ) . '</option>';
		foreach ( $couriers as $cid => $cname ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( $cid ), selected( $f['courier'], $cid, false ), esc_html( $cname ) );
		}
		echo '</select></div>';

		echo '<div class="bgcs-filters__field"><label>' . esc_html__( 'Status', 'bg-commerce-suite' ) . '</label><select name="status">';
		foreach ( array(
			''        => __( 'All', 'bg-commerce-suite' ),
			'pending' => __( 'Pending', 'bg-commerce-suite' ),
			'paid'    => __( 'Paid', 'bg-commerce-suite' ),
		) as $val => $lbl ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( $val ), selected( $f['status'], $val, false ), esc_html( $lbl ) );
		}
		echo '</select></div>';

		echo '<button type="submit" class="bgcs-btn bgcs-btn--primary">' . esc_html__( 'Filter', 'bg-commerce-suite' ) . '</button>';

		echo '<div class="bgcs-filters__spacer"></div>';

		$export_url = wp_nonce_url(
			add_query_arg(
				array(
					'action'  => 'bgcs3_cod_export',
					'from'    => $f['from'],
					'to'      => $f['to'],
					'courier' => $f['courier'],
					'status'  => $f['status'],
				),
				admin_url( 'admin-post.php' )
			),
			'bgcs3_cod_export'
		);
		echo '<a class="bgcs-btn bgcs-btn--outline" href="' . esc_url( $export_url ) . '">' . Icons::svg( 'file-text', 16 ) . esc_html__( 'Export CSV', 'bg-commerce-suite' ) . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</form>';

		// ---- Table -----------------------------------------------------------
		$visible = array_values(
			array_filter(
				$rows,
				static function ( $row ) use ( $f ) {
					if ( 'pending' === $f['status'] ) {
						return ! $row['paid'];
					}
					if ( 'paid' === $f['status'] ) {
						return $row['paid'];
					}
					return true;
				}
			)
		);

		echo '<div class="bgcs-table-wrap"><table class="bgcs-table">';
		echo '<thead><tr>';
		foreach ( array(
			__( 'Order', 'bg-commerce-suite' ),
			__( 'Date', 'bg-commerce-suite' ),
			__( 'Customer', 'bg-commerce-suite' ),
			__( 'Courier', 'bg-commerce-suite' ),
			__( 'Shipment label', 'bg-commerce-suite' ),
			__( 'COD amount', 'bg-commerce-suite' ),
			__( 'Status', 'bg-commerce-suite' ),
			__( 'Action', 'bg-commerce-suite' ),
		) as $th ) {
			echo '<th>' . esc_html( $th ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		if ( empty( $visible ) ) {
			echo '<tr><td colspan="8" class="bgcs-table__empty">' . esc_html__( 'No cash-on-delivery payments were found for the selected period/filters.', 'bg-commerce-suite' ) . '</td></tr>';
		}

		foreach ( $visible as $row ) {
			/** @var \WC_Order $order */
			$order = $row['order'];

			$logo  = Icons::courier_logo( $row['courier'], $row['courier'] );
			$cname = isset( $couriers[ $row['courier'] ] ) ? $couriers[ $row['courier'] ] : $row['courier'];

			$mark_url = wp_nonce_url(
				add_query_arg(
					array(
						'action'  => 'bgcs3_cod_mark',
						'order'   => $order->get_id(),
						'state'   => $row['paid'] ? 'pending' : 'paid',
						'from'    => $f['from'],
						'to'      => $f['to'],
						'courier' => $f['courier'],
						'status'  => $f['status'],
					),
					admin_url( 'admin-post.php' )
				),
				'bgcs3_cod_mark'
			);

			echo '<tr>';
			echo '<td><a href="' . esc_url( $order->get_edit_order_url() ) . '">#' . esc_html( $order->get_order_number() ) . '</a></td>';
			echo '<td>' . esc_html( $order->get_date_created() ? $order->get_date_created()->date_i18n( 'd.m.Y' ) : '—' ) . '</td>';
			echo '<td>' . esc_html( trim( $order->get_formatted_billing_full_name() ) ) . '</td>';
			echo '<td>' . ( '' !== $logo ? $logo : esc_html( $cname ) ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<td>' . esc_html( '' !== $row['waybill'] ? $row['waybill'] : '—' ) . '</td>';
			echo '<td class="bgcs-table__num">' . wp_kses_post( wc_price( $row['amount'], array( 'currency' => $row['currency'] ) ) );
			if ( ! empty( $row['mismatch'] ) ) {
				// Rule 78 — не скривай anomaly: order-ът е с наложен платеж, но
				// снимката на пратката показва, че реално е създадена БЕЗ НП.
				echo ' <span class="bgcs-badge bgcs-badge--mismatch" title="' . esc_attr__( 'Mismatch: the order uses cash on delivery, but the shipment data indicates it was created without COD. Check the courier shipment label.', 'bg-commerce-suite' ) . '">⚠ ' . esc_html__( 'COD mismatch', 'bg-commerce-suite' ) . '</span>';
			}
			echo '</td>';

			if ( $row['paid'] ) {
				$title = '' !== $row['paid_date'] ? gmdate( 'd.m.Y', strtotime( $row['paid_date'] ) ) : '';
				echo '<td><span class="bgcs-badge bgcs-badge--active" title="' . esc_attr( $title ) . '">' . esc_html__( 'Paid', 'bg-commerce-suite' ) . '</span></td>';
				echo '<td><a class="bgcs-btn bgcs-btn--sm bgcs-btn--outline" href="' . esc_url( $mark_url ) . '">' . esc_html__( 'Move back to pending', 'bg-commerce-suite' ) . '</a></td>';
			} else {
				echo '<td><span class="bgcs-badge bgcs-badge--available">' . esc_html__( 'Awaiting payout', 'bg-commerce-suite' ) . '</span></td>';
				echo '<td><a class="bgcs-btn bgcs-btn--sm bgcs-btn--primary" href="' . esc_url( $mark_url ) . '">' . esc_html__( 'Mark as paid', 'bg-commerce-suite' ) . '</a></td>';
			}
			echo '</tr>';
		}

		echo '</tbody></table></div>';

		echo '<p class="bgcs-help" style="margin-top:10px">' . esc_html__( 'Shows all orders with a shipment label and cash on delivery for the selected period. CSV reconciliation compares shipment label, amount, currency and courier when those columns are present in the report.', 'bg-commerce-suite' ) . '</p>';
	}
}
