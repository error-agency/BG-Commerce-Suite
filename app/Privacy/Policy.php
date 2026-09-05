<?php
/**
 * Suggested privacy-policy text for merchants using BG Commerce Suite.
 *
 * @package BgCommerce3
 */

namespace BgCommerce3\Privacy;

defined( 'ABSPATH' ) || exit;

final class Policy {

	/** Register the WordPress privacy-policy helper content. */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'add_policy_content' ) );
	}

	/** Add factual, editable text to Settings > Privacy. */
	public static function add_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content = '<p>' . __( 'When a courier module is enabled and used, BG Commerce Suite sends the shipment and contact information needed to calculate delivery, create a shipment, request pickup or retrieve tracking and payout information to the courier selected by the merchant. Depending on the operation, this can include sender and recipient names, telephone numbers, email addresses, delivery addresses or pickup-point identifiers, parcel details, order references and cash-on-delivery amounts.', 'bg-commerce-suite' ) . '</p>';
		$content .= '<p>' . __( 'The optional Error Web Agency product catalog is disabled by default. If a site administrator enables it, the site requests public product metadata from error.bg hourly and when an administrator requests a refresh. The request does not include the store URL, plugin inventory, customer or order data, credentials or cookies. The remote server necessarily receives the connecting server IP address and the generic BG-Commerce-Suite-Catalog/1 User-Agent.', 'bg-commerce-suite' ) . '</p>';
		$content .= '<p>' . __( 'When office or locker maps are displayed, the visitor browser may request map tiles or a courier-provided location widget. Those services receive normal connection data such as the visitor IP address and browser headers. Review the enabled courier services and adapt this suggested text to the store’s actual configuration and legal basis.', 'bg-commerce-suite' ) . '</p>';

		wp_add_privacy_policy_content(
			__( 'BG Commerce Suite', 'bg-commerce-suite' ),
			wp_kses_post( wpautop( $content, false ) )
		);
	}
}
