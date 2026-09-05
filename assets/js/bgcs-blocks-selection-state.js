/**
 * Serialize BGCS Store API selection updates before the Blocks selector loads.
 */
( function ( root ) {
	'use strict';

	var state = root.BgcsCheckoutState;
	var checkout = root.wc && root.wc.blocksCheckout;
	if ( ! state || ! checkout || 'function' !== typeof checkout.extensionCartUpdate ) {
		return;
	}

	var original = checkout.extensionCartUpdate;
	if ( original.bgcsSelectionQueue ) {
		return;
	}

	var queue = state.createSelectionUpdateQueue( function ( entry ) {
		var options = Object.assign( {}, entry.options, {
			data: Object.assign( {}, entry.selection, { revision: entry.revision } ),
		} );
		return original.call( checkout, options );
	} );

	function extensionCartUpdate( options ) {
		if ( ! options || 'bg-commerce-suite' !== options.namespace ) {
			return original.apply( checkout, arguments );
		}

		return queue.submit( {
			options: options,
			selection: options.data || {},
		} );
	}

	extensionCartUpdate.bgcsSelectionQueue = true;
	checkout.extensionCartUpdate = extensionCartUpdate;

	function selectedRateContext() {
		var dataStore = root.wp && root.wp.data;
		var cartStore = dataStore && dataStore.select ? dataStore.select( 'wc/store/cart' ) : null;
		var cart = cartStore && cartStore.getCartData ? cartStore.getCartData() : null;
		var packages = cart && Array.isArray( cart.shippingRates ) ? cart.shippingRates : [];
		return state.selectedBgcsRateContext( packages );
	}

	function persistReset( selection ) {
		try {
			if ( root.bgcsCheckout && false === root.bgcsCheckout.rememberSelection ) {
				root.localStorage.removeItem( 'bgcs3_selection' );
			} else {
				root.localStorage.setItem( 'bgcs3_selection', JSON.stringify( selection ) );
			}
		} catch ( error ) {}
	}

	var courierTransitions = state.createCourierTransitionObserver( function ( selection ) {
		persistReset( selection );
		checkout.extensionCartUpdate( {
			namespace: 'bg-commerce-suite',
			data: selection,
		} ).catch( function () {} );
	} );

	function observeSelectedCourier() {
		var context = selectedRateContext();
		if ( context ) {
			courierTransitions.observe( context.courier, context.deliveryTypes );
		}
	}

	if ( root.wp && root.wp.data && 'function' === typeof root.wp.data.subscribe ) {
		root.wp.data.subscribe( observeSelectedCourier );
		observeSelectedCourier();
	}
} )( window );
