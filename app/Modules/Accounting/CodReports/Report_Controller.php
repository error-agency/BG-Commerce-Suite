<?php
/**
 * Admin-post контролер за COD отчетите.
 *
 * Поема регистрацията на `admin_post` action-ите и оркестрацията на
 * маркиране изплатен/чакащ, CSV експорт и двустъпков импорт на куриерски
 * отчет: non-mutating preview и отделно потвърдено изравняване. `Cod_Reports`
 * остава composition root и рендер на страницата; тук стои единствено
 * request handling-ът (nonce, capability, redirects, CSV headers, upload
 * лимити), а достъпът до данните минава през `Report_Repository`.
 *
 * @package BgCommerce3\CodReports
 */

namespace BgCommerce3\Modules\Accounting\CodReports;

defined( 'ABSPATH' ) || exit;

class Report_Controller {
	/** @var Report_Repository */
	private $repository;

	/** @var Report_Importer */
	private $importer;

	/**
	 * @param Report_Repository $repository Собственик на order queries за отчета.
	 */
	public function __construct( Report_Repository $repository ) {
		$this->repository = $repository;
		$this->importer   = new Report_Importer( $repository );
	}

	/**
	 * Регистрира admin-post handler-ите.
	 */
	public function register() {
		add_action( 'admin_post_bgcs3_cod_mark', array( $this, 'handle_mark' ) );
		add_action( 'admin_post_bgcs3_cod_export', array( $this, 'handle_export' ) );
		add_action( 'admin_post_bgcs3_cod_import', array( $this, 'handle_import' ) );
		add_action( 'admin_post_bgcs3_cod_fetch', array( $this, 'handle_fetch' ) );
		add_action( 'admin_post_bgcs3_cod_import_apply', array( $this, 'handle_import_apply' ) );
	}

	/**
	 * Store a short-lived import preview for one administrator.
	 *
	 * @param array<string,mixed> $preview Preview data.
	 * @param int                 $user_id Administrator ID.
	 * @param string|null         $token   Optional deterministic token for tests.
	 * @return string
	 */
	public function store_import_preview( array $preview, $user_id, $token = null ) {
		$token = null === $token ? wp_generate_password( 32, false, false ) : (string) $token;
		$token = preg_replace( '/[^A-Za-z0-9_-]/', '', $token );
		set_transient(
			$this->preview_transient_key( $user_id ),
			array(
				'owner'   => (int) $user_id,
				'token'   => $token,
				'preview' => $preview,
			),
			15 * MINUTE_IN_SECONDS
		);
		return $token;
	}

	/**
	 * Load a preview only for its owner and exact opaque token.
	 *
	 * @param string $token   Preview token.
	 * @param int    $user_id Administrator ID.
	 * @return array<string,mixed>|null
	 */
	public function get_import_preview( $token, $user_id ) {
		$stored = get_transient( $this->preview_transient_key( $user_id ) );
		if ( ! is_array( $stored ) || ! isset( $stored['owner'], $stored['token'], $stored['preview'] ) ) {
			return null;
		}
		if ( (int) $stored['owner'] !== (int) $user_id || ! hash_equals( (string) $stored['token'], (string) $token ) ) {
			return null;
		}
		return is_array( $stored['preview'] ) ? $stored['preview'] : null;
	}

	/**
	 * Delete the current administrator's preview after apply.
	 *
	 * @param int $user_id Administrator ID.
	 */
	public function delete_import_preview( $user_id ) {
		delete_transient( $this->preview_transient_key( $user_id ) );
	}

	/**
	 * Apply and consume an owned preview exactly once.
	 *
	 * @param string   $token        Preview token.
	 * @param int      $user_id      Administrator ID.
	 * @param string   $paid_date    ISO payout date.
	 * @param callable $order_loader Receives an order ID.
	 * @return array{updated:int,skipped:int,conflicts:int,unmatched:int,already_paid:int,duplicates:int}|null
	 */
	public function apply_import_preview( $token, $user_id, $paid_date, $order_loader ) {
		$preview = $this->get_import_preview( $token, $user_id );
		if ( null === $preview ) {
			return null;
		}

		$result = $this->importer->apply_preview( $preview, $order_loader, $paid_date );
		$this->delete_import_preview( $user_id );
		$result['conflicts']    = isset( $preview['conflicts'] ) && is_array( $preview['conflicts'] ) ? count( $preview['conflicts'] ) : 0;
		$result['unmatched']    = isset( $preview['unmatched'] ) ? (int) $preview['unmatched'] : 0;
		$result['already_paid'] = isset( $preview['already_paid'] ) ? (int) $preview['already_paid'] : 0;
		$result['duplicates']   = isset( $preview['duplicates'] ) ? (int) $preview['duplicates'] : 0;

		return $result;
	}

	/**
	 * @param int $user_id Administrator ID.
	 * @return string
	 */
	private function preview_transient_key( $user_id ) {
		return 'bgcs3_cod_import_preview_' . absint( $user_id );
	}

	/**
	 * Preview requested by the current report page URL.
	 *
	 * @return array{token:string,preview:array<string,mixed>}|null
	 */
	public function requested_import_preview() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only lookup of an owner-bound transient.
		$token = isset( $_GET['import_preview'] ) ? sanitize_text_field( wp_unslash( $_GET['import_preview'] ) ) : '';
		if ( '' === $token ) {
			return null;
		}
		$preview = $this->get_import_preview( $token, get_current_user_id() );
		return null === $preview ? null : array( 'token' => $token, 'preview' => $preview );
	}

	/* ------------------------------------------------------------------ */
	/* Shared request parsing                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Текущи филтри на отчета от query string-а (по подразбиране: последните 30 дни).
	 *
	 * Споделя се между рендера на страницата и експорта, за да е единен
	 * източникът на разчитане на филтрите.
	 *
	 * @return array{from:string,to:string,courier:string,status:string}
	 */
	public function filters() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only view filters.
		$from    = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '';
		$to      = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : '';
		$courier = isset( $_GET['courier'] ) ? sanitize_key( wp_unslash( $_GET['courier'] ) ) : '';
		$status  = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		// phpcs:enable

		$valid_date = static function ( $d ) {
			return (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d );
		};

		return array(
			'from'    => $valid_date( $from ) ? $from : gmdate( 'Y-m-d', strtotime( '-30 days' ) ),
			'to'      => $valid_date( $to ) ? $to : gmdate( 'Y-m-d' ),
			'courier' => $courier,
			'status'  => in_array( $status, array( 'pending', 'paid' ), true ) ? $status : '',
		);
	}

	/**
	 * Активните куриерски модули [ id => name ] за филтъра и CSV колоната.
	 *
	 * @return array<string,string>
	 */
	public function couriers() {
		$out       = array();
		$container = function_exists( 'bgcs3' ) ? bgcs3()->container() : null;
		if ( $container && isset( $container['modules'] ) ) {
			foreach ( $container['modules']->all() as $module ) {
				if ( $module instanceof \BgCommerce3\Modules\Shipping\Courier_Interface ) {
					$out[ $module->id() ] = $module->name();
				}
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* Actions                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Отбелязва COD сума като изплатена / чакаща.
	 */
	public function handle_mark() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission for this action.', 'bg-commerce-suite' ) );
		}
		check_admin_referer( 'bgcs3_cod_mark' );

		$order_id = isset( $_GET['order'] ) ? absint( $_GET['order'] ) : 0;
		$state    = isset( $_GET['state'] ) && 'paid' === $_GET['state'] ? 'paid' : 'pending';

		$order = wc_get_order( $order_id );
		// Rule 81 — this link only ever appears in the COD report table, which
		// already only lists COD orders, but a hand-crafted order id in the URL
		// (still capability+nonce gated) must not be able to mark payout status
		// on a non-COD order.
		if ( $order instanceof \WC_Order && $this->repository->is_cod_order( $order ) ) {
			if ( 'paid' === $state ) {
				\BgCommerce3\Shipping\Cod_Payout::mark_manually( $order, current_time( 'Y-m-d' ) );
			} else {
				\BgCommerce3\Shipping\Cod_Payout::reset_to_pending( $order );
			}
		}

		// Back to the report with the same filters.
		$back = add_query_arg(
			array(
				'page'    => 'bgcs3-settings',
				'tab'     => Cod_Reports::ID,
				'from'    => isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '',
				'to'      => isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : '',
				'courier' => isset( $_GET['courier'] ) ? sanitize_key( wp_unslash( $_GET['courier'] ) ) : '',
				'status'  => isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '',
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $back );
		exit;
	}

	/**
	 * Стриймва филтрирания отчет като CSV (UTF-8 BOM за Excel).
	 */
	public function handle_export() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission for this action.', 'bg-commerce-suite' ) );
		}
		check_admin_referer( 'bgcs3_cod_export' );

		$f    = $this->filters();
		$rows = $this->repository->rows( $f );

		if ( 'pending' === $f['status'] || 'paid' === $f['status'] ) {
			$want_paid = ( 'paid' === $f['status'] );
			$rows      = array_values(
				array_filter(
					$rows,
					static function ( $row ) use ( $want_paid ) {
						return $row['paid'] === $want_paid;
					}
				)
			);
		}

		$couriers = $this->couriers();
		$filename = 'bgcs-cod-report_' . $f['from'] . '_' . $f['to'] . '.csv';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- UTF-8 BOM so Excel opens Cyrillic correctly.

		fputcsv(
			$out,
			array(
				__( 'Order', 'bg-commerce-suite' ),
				__( 'Date', 'bg-commerce-suite' ),
				__( 'Customer', 'bg-commerce-suite' ),
				__( 'Courier', 'bg-commerce-suite' ),
				__( 'Shipment label', 'bg-commerce-suite' ),
				__( 'COD amount', 'bg-commerce-suite' ),
				__( 'Currency', 'bg-commerce-suite' ),
				__( 'Status', 'bg-commerce-suite' ),
				__( 'Payout date', 'bg-commerce-suite' ),
				__( 'COD mismatch', 'bg-commerce-suite' ),
			)
		);

		foreach ( $rows as $row ) {
			/** @var \WC_Order $order */
			$order = $row['order'];
			fputcsv(
				$out,
				array(
					$order->get_order_number(),
					$order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d' ) : '',
					self::escape_csv_formula( trim( $order->get_formatted_billing_full_name() ) ),
					isset( $couriers[ $row['courier'] ] ) ? $couriers[ $row['courier'] ] : $row['courier'],
					self::escape_csv_formula( $row['waybill'] ),
					number_format( $row['amount'], 2, '.', '' ),
					$row['currency'],
					$row['paid'] ? __( 'Paid', 'bg-commerce-suite' ) : __( 'Awaiting payout', 'bg-commerce-suite' ),
					$row['paid_date'],
					// Rule 78 — не скривай anomaly в експорта, не само в UI.
					! empty( $row['mismatch'] ) ? __( 'Yes — the order is COD but the shipment is not', 'bg-commerce-suite' ) : '',
				)
			);
		}

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	/**
	 * Неутрализира CSV/formula injection в клиентски-контролирани клетки (BUG-015).
	 *
	 * Excel/LibreOffice/Sheets могат да изпълнят клетка, чиято стойност започва с
	 * `=`, `+`, `-` или `@`, като формула. Клиентското име (`get_formatted_
	 * billing_full_name()`) стига до тук директно от checkout, без validation
	 * срещу тези символи. Префиксваме с апостроф — същата техника като
	 * `bgcs-naredba-h18`'s `Xlsx_Writer::escape_formula()` — така стойността
	 * остава четима за човек, но спредшийт приложението вече не я третира като
	 * формула. Легитимни стойности (напр. отрицателна сума като текст) не се
	 * повреждат, само получават видим water-mark апостроф отпред.
	 *
	 * @param mixed $value Клетъчна стойност.
	 * @return mixed
	 */
	private static function escape_csv_formula( $value ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return $value;
		}

		return in_array( substr( $value, 0, 1 ), array( '=', '+', '-', '@' ), true ) ? "'" . $value : $value;
	}

	/**
	 * Validate an uploaded courier CSV and redirect to a non-mutating preview.
	 */
	public function handle_import() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission for this action.', 'bg-commerce-suite' ) );
		}
		check_admin_referer( 'bgcs3_cod_import' );

		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated as an HTTP upload before use.
		$tmp_name = isset( $_FILES['import_file']['tmp_name'] ) ? wp_unslash( $_FILES['import_file']['tmp_name'] ) : '';
		$filename = isset( $_FILES['import_file']['name'] ) ? sanitize_file_name( wp_unslash( $_FILES['import_file']['name'] ) ) : '';
		$filesize = isset( $_FILES['import_file']['size'] ) ? absint( $_FILES['import_file']['size'] ) : 0;
		// phpcs:enable
		$extension = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );

		if ( '' === $tmp_name || ! is_uploaded_file( $tmp_name ) || ! in_array( $extension, array( 'csv', 'txt' ), true ) ) {
			$this->redirect_import_error( 'invalid_upload' );
		}

		try {
			$preview = $this->importer->preview_file( $tmp_name, $filesize );
		} catch ( \RuntimeException $error ) {
			$this->redirect_import_error( $error->getMessage() );
		}

		$token = $this->store_import_preview( $preview, get_current_user_id() );
		$this->redirect_report( array( 'import_preview' => $token ) );
	}

	/**
	 * Couriers that can report their own payouts.
	 *
	 * Duck-typed, so this module knows nothing about which couriers exist — a
	 * courier add-on either offers the capability or it does not.
	 *
	 * @return array<string,string> Module id => display name.
	 */
	public function payout_couriers() {
		$out = array();

		if ( ! function_exists( 'bgcs3' ) ) {
			return $out;
		}

		$container = bgcs3()->container();
		$registry  = isset( $container['modules'] ) ? $container['modules'] : null;
		if ( ! $registry ) {
			return $out;
		}

		foreach ( $registry->all() as $module ) {
			if ( method_exists( $module, 'is_enabled' )
				&& $module->is_enabled()
				&& method_exists( $module, 'supports_cod_payouts' )
				&& $module->supports_cod_payouts()
				&& method_exists( $module, 'cod_payouts' )
			) {
				$out[ $module->id() ] = $module->name();
			}
		}

		return $out;
	}

	/**
	 * Pull payouts straight from a courier instead of importing a file.
	 *
	 * Ends in the same owner-bound preview an upload produces, so confirming it
	 * runs the same revalidation of every order.
	 */
	public function handle_fetch() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission for this action.', 'bg-commerce-suite' ) );
		}
		check_admin_referer( 'bgcs3_cod_fetch' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked above.
		$courier_id = isset( $_POST['courier'] ) ? sanitize_key( wp_unslash( $_POST['courier'] ) ) : '';
		$from       = isset( $_POST['from_date'] ) ? sanitize_text_field( wp_unslash( $_POST['from_date'] ) ) : '';
		$to         = isset( $_POST['to_date'] ) ? sanitize_text_field( wp_unslash( $_POST['to_date'] ) ) : '';
		// phpcs:enable

		$couriers = $this->payout_couriers();
		if ( '' === $courier_id || ! isset( $couriers[ $courier_id ] ) ) {
			$this->redirect_import_error( 'unknown_courier' );
		}

		$container = bgcs3()->container();
		$module    = $container['modules']->get( $courier_id );
		$rows      = $module->cod_payouts( $from, $to );

		if ( is_wp_error( $rows ) ) {
			// The courier's own words — a rejected date range or an auth problem
			// is far more useful to the merchant than a generic failure.
			$this->redirect_report(
				array(
					'cod_import_error' => 'courier_error',
					'import_message' => rawurlencode( $rows->get_error_message() ),
				)
			);
		}

		if ( empty( $rows ) ) {
			$this->redirect_import_error( 'no_payouts' );
		}

		try {
			$preview = $this->importer->preview_courier_payouts( (array) $rows );
		} catch ( \RuntimeException $error ) {
			$this->redirect_import_error( $error->getMessage() );
		}

		// The payout date is when the courier actually paid, not today —
		// otherwise every historical import is dated to the day it was run.
		$preview['paid_date'] = $this->latest_paid_date( (array) $rows );

		$token = $this->store_import_preview( $preview, get_current_user_id() );
		$this->redirect_report( array( 'import_preview' => $token ) );
	}

	/**
	 * @param array<int,array<string,mixed>> $rows Rows.
	 * @return string `Y-m-d`, or '' when no row carries one.
	 */
	private function latest_paid_date( array $rows ) {
		$latest = '';

		foreach ( $rows as $row ) {
			$date = isset( $row['paid_date'] ) ? (string) $row['paid_date'] : '';
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) && ( '' === $latest || $date > $latest ) ) {
				$latest = $date;
			}
		}

		return $latest;
	}

	/**
	 * Apply a confirmed, owner-bound preview after revalidating every order.
	 */
	public function handle_import_apply() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission for this action.', 'bg-commerce-suite' ) );
		}
		check_admin_referer( 'bgcs3_cod_import_apply' );

		$confirmed = isset( $_POST['confirm_import'] ) && 'yes' === sanitize_key( wp_unslash( $_POST['confirm_import'] ) );
		$token     = isset( $_POST['preview_token'] ) ? sanitize_text_field( wp_unslash( $_POST['preview_token'] ) ) : '';
		if ( ! $confirmed || '' === $token ) {
			$this->redirect_import_error( 'confirmation_required' );
		}

		// When the courier told us WHEN it paid, record that date. Today is only
		// a fallback for a file that carries no payout date — dating a payout to
		// the day someone happened to press the button makes the report useless
		// for reconciling against a bank statement.
		$stored    = $this->get_import_preview( $token, get_current_user_id() );
		$paid_date = ( is_array( $stored ) && ! empty( $stored['paid_date'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $stored['paid_date'] ) )
			? (string) $stored['paid_date']
			: current_time( 'Y-m-d' );

		$result = $this->apply_import_preview(
			$token,
			get_current_user_id(),
			$paid_date,
			static function ( $order_id ) {
				return wc_get_order( $order_id );
			}
		);

		if ( null === $result ) {
			$this->redirect_import_error( 'preview_expired' );
		}

		$this->redirect_report(
			array(
				'imported_cnt'     => $result['updated'],
				'skipped_cnt'      => $result['skipped'],
				'conflict_cnt'     => $result['conflicts'],
				'unmatched_cnt'    => $result['unmatched'],
				'already_paid_cnt' => $result['already_paid'],
				'duplicate_cnt'    => $result['duplicates'],
			)
		);
	}

	/**
	 * Redirect to the report page with an import error code.
	 *
	 * @param string $code Error code.
	 */
	private function redirect_import_error( $code ) {
		$this->redirect_report( array( 'cod_import_error' => sanitize_key( $code ) ) );
	}

	/**
	 * Redirect to the COD report tab.
	 *
	 * @param array<string,mixed> $args Additional query args.
	 */
	private function redirect_report( array $args ) {
		$args = array_merge( array( 'page' => 'bgcs3-settings', 'tab' => Cod_Reports::ID ), $args );
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}
