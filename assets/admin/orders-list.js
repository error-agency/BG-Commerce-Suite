/**
 * AJAX действия в списъка с поръчки (бързо генериране на товарителница).
 *
 * @package BgCommerce3
 */
( function ( $ ) {
	'use strict';

	$( document ).on( 'click', '.bgcs-wb-create-btn', function ( e ) {
		e.preventDefault();

		const $btn = $( this );
		const $cell = $btn.closest( '.bgcs-wb-cell' );
		const orderId = $btn.data( 'order-id' ) || $cell.data( 'order-id' );
		const $msg = $cell.find( '.bgcs-wb-cell__msg' );

		if ( ! orderId || $btn.prop( 'disabled' ) ) {
			return;
		}

		if ( typeof window.bgcsOrdersList === 'undefined' ) {
			return;
		}

		const originalText = $btn.text();
		$btn.prop( 'disabled', true ).text( window.bgcsOrdersList.i18n.generating );
		$msg.empty();

		$.post( window.bgcsOrdersList.ajaxUrl, {
			action: 'bgcs3_quick_create_label',
			nonce: window.bgcsOrdersList.nonce,
			order_id: orderId,
		} )
			.done( function ( res ) {
				if ( res && res.success && res.data && res.data.html ) {
					const $newContent = $( res.data.html );
					$cell.replaceWith( $newContent );
				} else {
					const errText = ( res && res.data && res.data.message ) || window.bgcsOrdersList.i18n.error;
					$msg.text( errText );
					$btn.prop( 'disabled', false ).text( originalText );
				}
			} )
			.fail( function () {
				$msg.text( window.bgcsOrdersList.i18n.error );
				$btn.prop( 'disabled', false ).text( originalText );
			} );
	} );
} )( jQuery );
