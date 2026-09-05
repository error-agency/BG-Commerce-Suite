<?php
/**
 * TeamHub-compatible UI for courier payout report import and confirmation.
 *
 * @package BgCommerce3\CodReports
 */

namespace BgCommerce3\Modules\Accounting\CodReports;

defined( 'ABSPATH' ) || exit;

class Import_Renderer {

	/**
	 * Render the initial non-mutating CSV upload form.
	 *
	 * @return string
	 */
	public function render_upload_form() {
		$html  = '<section class="bgcs-card bgcs-card--standalone" aria-labelledby="bgcs-cod-import-title">';
		$html .= '<div class="bgcs-card__head"><div class="bgcs-card__titles">';
		$html .= '<h2 class="bgcs-card__title" id="bgcs-cod-import-title">' . esc_html__( 'Reconcile from courier report', 'bg-commerce-suite' ) . '</h2>';
		$html .= '<p class="bgcs-card__desc">' . esc_html__( 'Upload a courier CSV report. You will see a preview first; orders will not be changed without explicit confirmation.', 'bg-commerce-suite' ) . '</p>';
		$html .= '</div></div><div class="bgcs-card__body">';
		$html .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" enctype="multipart/form-data">';
		$html .= '<input type="hidden" name="action" value="bgcs3_cod_import" />';
		$html .= wp_nonce_field( 'bgcs3_cod_import', '_wpnonce', true, false );
		$html .= '<div class="bgcs-field">';
		$html .= '<label class="bgcs-field__label" for="bgcs-cod-import-file">' . esc_html__( 'Courier CSV file', 'bg-commerce-suite' ) . '</label>';
		$html .= '<input id="bgcs-cod-import-file" type="file" name="import_file" accept=".csv,text/csv,text/plain" required />';
		$html .= '<p class="bgcs-field__desc">' . esc_html__( 'Up to 10 MB and 50,000 rows. Supported delimiters: comma, semicolon, vertical bar and tab.', 'bg-commerce-suite' ) . '</p>';
		$html .= '</div><button type="submit" class="bgcs-btn bgcs-btn--primary">' . esc_html__( 'Preview report', 'bg-commerce-suite' ) . '</button>';
		$html .= '</form></div></section>';

		return $html;
	}

	/**
	 * Offer to pull payouts straight from a courier that can report them.
	 *
	 * Renders nothing at all when no installed courier offers the capability —
	 * an empty dropdown promising a feature the shop cannot use is worse than
	 * no card.
	 *
	 * @param array<string,string> $couriers Module id => display name.
	 * @return string
	 */
	public function render_fetch_form( array $couriers ) {
		if ( empty( $couriers ) ) {
			return '';
		}

		// A month back is both the most useful default and the longest window
		// couriers accept.
		$to   = current_time( 'Y-m-d' );
		$from = gmdate( 'Y-m-d', strtotime( '-1 month', strtotime( $to ) ) );

		$html  = '<section class="bgcs-card bgcs-card--standalone" aria-labelledby="bgcs-cod-fetch-title">';
		$html .= '<div class="bgcs-card__head"><div class="bgcs-card__titles">';
		$html .= '<h2 class="bgcs-card__title" id="bgcs-cod-fetch-title">' . esc_html__( 'Fetch report from courier', 'bg-commerce-suite' ) . '</h2>';
		$html .= '<p class="bgcs-card__desc">' . esc_html__( 'Fetches paid COD amounts directly without uploading a file. The period is based on the PAYOUT date, not the delivery date, and cannot exceed one month. You will still see a preview before any changes are made.', 'bg-commerce-suite' ) . '</p>';
		$html .= '</div></div><div class="bgcs-card__body">';
		$html .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		$html .= '<input type="hidden" name="action" value="bgcs3_cod_fetch" />';
		$html .= wp_nonce_field( 'bgcs3_cod_fetch', '_wpnonce', true, false );

		$html .= '<div class="bgcs-field">';
		$html .= '<label class="bgcs-field__label" for="bgcs-cod-fetch-courier">' . esc_html__( 'Courier', 'bg-commerce-suite' ) . '</label>';
		$html .= '<select id="bgcs-cod-fetch-courier" name="courier">';
		foreach ( $couriers as $id => $name ) {
			$html .= '<option value="' . esc_attr( $id ) . '">' . esc_html( $name ) . '</option>';
		}
		$html .= '</select></div>';

		$html .= '<div class="bgcs-field">';
		$html .= '<label class="bgcs-field__label" for="bgcs-cod-fetch-from">' . esc_html__( 'From date', 'bg-commerce-suite' ) . '</label>';
		$html .= '<input id="bgcs-cod-fetch-from" type="date" name="from_date" value="' . esc_attr( $from ) . '" required />';
		$html .= '</div>';

		$html .= '<div class="bgcs-field">';
		$html .= '<label class="bgcs-field__label" for="bgcs-cod-fetch-to">' . esc_html__( 'To date', 'bg-commerce-suite' ) . '</label>';
		$html .= '<input id="bgcs-cod-fetch-to" type="date" name="to_date" value="' . esc_attr( $to ) . '" required />';
		$html .= '</div>';

		$html .= '<button type="submit" class="bgcs-btn bgcs-btn--primary">' . esc_html__( 'Fetch and preview', 'bg-commerce-suite' ) . '</button>';
		$html .= '</form></div></section>';

		return $html;
	}

	/**
	 * Render a stored import preview and explicit confirmation form.
	 *
	 * @param array<string,mixed> $preview Preview data.
	 * @param string              $token   Opaque per-user preview token.
	 * @return string
	 */
	public function render_preview( array $preview, $token ) {
		$matches      = isset( $preview['matches'] ) && is_array( $preview['matches'] ) ? $preview['matches'] : array();
		$conflicts    = isset( $preview['conflicts'] ) && is_array( $preview['conflicts'] ) ? $preview['conflicts'] : array();
		$unmatched    = isset( $preview['unmatched'] ) ? (int) $preview['unmatched'] : 0;
		$already_paid = isset( $preview['already_paid'] ) ? (int) $preview['already_paid'] : 0;
		$duplicates   = isset( $preview['duplicates'] ) ? (int) $preview['duplicates'] : 0;

		$html  = '<section class="bgcs-card bgcs-card--standalone" aria-labelledby="bgcs-cod-preview-title">';
		$html .= '<div class="bgcs-card__head"><div class="bgcs-card__titles">';
		$html .= '<h2 class="bgcs-card__title" id="bgcs-cod-preview-title">' . esc_html__( 'Courier report preview', 'bg-commerce-suite' ) . '</h2>';
		$html .= '<p class="bgcs-card__desc">' . esc_html__( 'Review the results. Conflicting and unmatched rows will not be applied.', 'bg-commerce-suite' ) . '</p>';
		$html .= '</div></div><div class="bgcs-card__body">';
		$html .= '<div class="bgcs-alert bgcs-alert--info" role="status">';
		/* translators: %d: number of orders ready to reconcile. */
		$html .= esc_html( sprintf( _n( '%d order ready to reconcile', '%d orders ready to reconcile', count( $matches ), 'bg-commerce-suite' ), count( $matches ) ) );
		/* translators: %d: number of conflicting payout rows. */
		$html .= ' · ' . esc_html( sprintf( _n( '%d conflict', '%d conflicts', count( $conflicts ), 'bg-commerce-suite' ), count( $conflicts ) ) );
		/* translators: %d: number of unmatched payout rows. */
		$html .= ' · ' . esc_html( sprintf( _n( '%d unmatched row', '%d unmatched rows', $unmatched, 'bg-commerce-suite' ), $unmatched ) );
		/* translators: %d: number of orders already marked paid. */
		$html .= ' · ' . esc_html( sprintf( _n( '%d already paid order', '%d already paid orders', $already_paid, 'bg-commerce-suite' ), $already_paid ) );
		/* translators: %d: number of duplicate payout rows. */
		$html .= ' · ' . esc_html( sprintf( _n( '%d duplicate row', '%d duplicate rows', $duplicates, 'bg-commerce-suite' ), $duplicates ) );
		$html .= '</div>';

		if ( ! empty( $matches ) ) {
			$html .= '<h3>' . esc_html__( 'Ready to reconcile', 'bg-commerce-suite' ) . '</h3>';
			$html .= $this->render_records_table( $matches, false );
		}

		if ( ! empty( $conflicts ) ) {
			$html .= '<h3>' . esc_html__( 'Conflicts — will not be applied', 'bg-commerce-suite' ) . '</h3>';
			$html .= $this->render_records_table( $conflicts, true );
		}

		if ( ! empty( $matches ) ) {
			$html .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			$html .= '<input type="hidden" name="action" value="bgcs3_cod_import_apply" />';
			$html .= '<input type="hidden" name="preview_token" value="' . esc_attr( $token ) . '" />';
			$html .= wp_nonce_field( 'bgcs3_cod_import_apply', '_wpnonce', true, false );
			$html .= '<p><label><input type="checkbox" name="confirm_import" value="yes" required /> ';
			$html .= esc_html__( 'I confirm marking the shown orders as paid.', 'bg-commerce-suite' ) . '</label></p>';
			$html .= '<button type="submit" class="bgcs-btn bgcs-btn--primary">' . esc_html__( 'Reconcile confirmed orders', 'bg-commerce-suite' ) . '</button>';
			$html .= '</form>';
		}

		$html .= '</div></section>';
		return $html;
	}

	/**
	 * Render import completion details or a recoverable Bulgarian error.
	 *
	 * @param string              $error_code Machine error code, or empty.
	 * @param array<string,mixed> $result     Import result counts.
	 * @return string
	 */
	public function render_feedback( $error_code, array $result ) {
		if ( '' !== $error_code ) {
			$messages = array(
				'invalid_upload'        => __( 'Upload a valid courier CSV or TXT report.', 'bg-commerce-suite' ),
				'invalid_file_size'     => __( 'The file is empty or exceeds the 10 MB maximum size.', 'bg-commerce-suite' ),
				'unreadable_file'       => __( 'The uploaded file could not be read.', 'bg-commerce-suite' ),
				'empty_file'            => __( 'The uploaded file is empty.', 'bg-commerce-suite' ),
				'invalid_csv'           => __( 'The file does not contain a valid CSV report.', 'bg-commerce-suite' ),
				'too_many_rows'         => __( 'The report contains more than 50,000 rows. Split it into smaller files.', 'bg-commerce-suite' ),
				'confirmation_required' => __( 'You must explicitly confirm the reconciliation.', 'bg-commerce-suite' ),
				'preview_expired'       => __( 'The preview has expired or has already been applied. Upload the report again.', 'bg-commerce-suite' ),
				'unknown_courier'       => __( 'The selected courier cannot provide a payout report.', 'bg-commerce-suite' ),
				'no_payouts'            => __( 'The courier reports no payouts for the selected period. The period is based on the payout date, not the delivery date.', 'bg-commerce-suite' ),
			);
			$message = isset( $messages[ $error_code ] ) ? $messages[ $error_code ] : __( 'The report could not be processed. Check the file and try again.', 'bg-commerce-suite' );

			// The courier's own wording — a refused date range or an expired key
			// is far more useful to the merchant than a generic failure.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only, escaped below.
			if ( 'courier_error' === $error_code && ! empty( $_GET['import_message'] ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Display-only query value is unslashed, decoded, sanitized, and escaped.
				$message = sanitize_text_field( rawurldecode( wp_unslash( $_GET['import_message'] ) ) );
			}

			return '<div class="bgcs-alert bgcs-alert--danger" role="alert">' . esc_html( $message ) . '</div>';
		}

		if ( empty( $result ) ) {
			return '';
		}

		$updated      = isset( $result['updated'] ) ? (int) $result['updated'] : 0;
		$skipped      = isset( $result['skipped'] ) ? (int) $result['skipped'] : 0;
		$conflicts    = isset( $result['conflicts'] ) ? (int) $result['conflicts'] : 0;
		$unmatched    = isset( $result['unmatched'] ) ? (int) $result['unmatched'] : 0;
		$already_paid = isset( $result['already_paid'] ) ? (int) $result['already_paid'] : 0;
		$duplicates   = isset( $result['duplicates'] ) ? (int) $result['duplicates'] : 0;
		$message      = sprintf(
			/* translators: 1: updated, 2: skipped, 3: conflicts, 4: unmatched, 5: already paid, 6: duplicates. */
			__( 'Reconciled: %1$d · Skipped: %2$d · Conflicts: %3$d · Unmatched: %4$d · Already paid: %5$d · Duplicates: %6$d', 'bg-commerce-suite' ),
			$updated,
			$skipped,
			$conflicts,
			$unmatched,
			$already_paid,
			$duplicates
		);

		return '<div class="bgcs-alert bgcs-alert--success" role="status">' . esc_html( $message ) . '</div>';
	}

	/**
	 * Render validated or conflicting preview records.
	 *
	 * @param array<int,array<string,mixed>> $records   Preview records.
	 * @param bool                           $conflicts Include conflict reason column.
	 * @return string
	 */
	private function render_records_table( array $records, $conflicts ) {
		$html  = '<div class="bgcs-table-wrap"><table class="bgcs-table"><thead><tr>';
		$html .= '<th>' . esc_html__( 'Order', 'bg-commerce-suite' ) . '</th>';
		$html .= '<th>' . esc_html__( 'Shipment label', 'bg-commerce-suite' ) . '</th>';
		$html .= '<th>' . esc_html__( 'Store amount', 'bg-commerce-suite' ) . '</th>';
		$html .= '<th>' . esc_html__( 'Report amount', 'bg-commerce-suite' ) . '</th>';
		$html .= '<th>' . esc_html__( 'Courier', 'bg-commerce-suite' ) . '</th>';
		if ( $conflicts ) {
			$html .= '<th>' . esc_html__( 'Reason', 'bg-commerce-suite' ) . '</th>';
		}
		$html .= '</tr></thead><tbody>';

		foreach ( $records as $record ) {
			$expected_amount = number_format( (float) $record['expected_amount'], 2, '.', ' ' ) . ' ' . (string) $record['expected_currency'];
			$reported_amount = null === $record['reported_amount']
				? __( 'Not recognized', 'bg-commerce-suite' )
				: number_format( (float) $record['reported_amount'], 2, '.', ' ' ) . ' ' . ( '' !== (string) $record['reported_currency'] ? (string) $record['reported_currency'] : (string) $record['expected_currency'] );

			$html .= '<tr><td>#' . esc_html( (int) $record['order_id'] ) . '</td>';
			$html .= '<td>' . esc_html( $record['waybill'] ) . '</td>';
			$html .= '<td>' . esc_html( $expected_amount ) . '</td>';
			$html .= '<td>' . esc_html( $reported_amount ) . '</td>';
			$html .= '<td>' . esc_html( $record['expected_courier'] ) . '</td>';
			if ( $conflicts ) {
				$labels = array();
				foreach ( (array) $record['conflict_reasons'] as $reason ) {
					$labels[] = $this->conflict_label( $reason );
				}
				$html .= '<td>' . esc_html( implode( '; ', $labels ) ) . '</td>';
			}
			$html .= '</tr>';
		}

		$html .= '</tbody></table></div>';
		return $html;
	}

	/**
	 * Bulgarian explanation for a machine conflict code.
	 *
	 * @param string $reason Conflict code.
	 * @return string
	 */
	private function conflict_label( $reason ) {
		$labels = array(
			'invalid_amount'    => __( 'Missing or invalid amount', 'bg-commerce-suite' ),
			'invalid_currency'  => __( 'Missing or invalid currency', 'bg-commerce-suite' ),
			'invalid_courier'   => __( 'Missing or invalid courier', 'bg-commerce-suite' ),
			'amount_mismatch'   => __( 'Amount mismatch', 'bg-commerce-suite' ),
			'currency_mismatch' => __( 'Currency mismatch', 'bg-commerce-suite' ),
			'courier_mismatch'  => __( 'Courier mismatch', 'bg-commerce-suite' ),
			'shipment_reference_mismatch' => __( 'Shipment reference mismatch', 'bg-commerce-suite' ),
			'invalid_paid_date' => __( 'Missing or invalid payout date', 'bg-commerce-suite' ),
			'invalid_payout_status' => __( 'The report row is not marked as paid', 'bg-commerce-suite' ),
			'duplicate_conflict' => __( 'Conflicting duplicate payout rows', 'bg-commerce-suite' ),
			'invalid_fee'       => __( 'Invalid payout fee', 'bg-commerce-suite' ),
			'invalid_net'       => __( 'Invalid net payout', 'bg-commerce-suite' ),
			'net_mismatch'      => __( 'Paid amount minus fee does not equal net payout', 'bg-commerce-suite' ),
		);
		return isset( $labels[ $reason ] ) ? $labels[ $reason ] : __( 'Unknown conflict', 'bg-commerce-suite' );
	}
}
