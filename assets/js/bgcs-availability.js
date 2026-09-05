( function () {
	'use strict';

	var availabilityTimer = null;
	var priceStateTimer = null;
	var lastCartSignature = '';
	var hostAttempts = 0;
	var observer = null;

	function shippingHost() {
		return document.querySelector( '.wc-block-components-shipping-rates-control' ) ||
			document.querySelector( '.wc-block-checkout__shipping-method' ) ||
			document.querySelector( '[data-block-name="woocommerce/checkout-shipping-methods-block"]' );
	}

	function cartStore() {
		if ( ! window.wp || ! window.wp.data ) {
			return null;
		}
		return window.wp.data.select( 'wc/store/cart' ) || null;
	}

	function shippingPackages( store ) {
		var cartData = {};
		var packages = [];

		if ( ! store ) {
			return packages;
		}

		try {
			cartData = store.getCartData ? store.getCartData() : {};
			packages = cartData && ( cartData.shippingRates || cartData.shipping_rates ) ?
				( cartData.shippingRates || cartData.shipping_rates ) : [];

			if ( ( ! Array.isArray( packages ) || ! packages.length ) && store.getShippingRates ) {
				packages = store.getShippingRates() || [];
			}
		} catch ( error ) {
			return [];
		}

		return Array.isArray( packages ) ? packages : [];
	}

	function packageRates( pkg ) {
		if ( ! pkg ) {
			return [];
		}
		if ( Array.isArray( pkg.shipping_rates ) ) {
			return pkg.shipping_rates;
		}
		if ( Array.isArray( pkg.shippingRates ) ) {
			return pkg.shippingRates;
		}
		if ( Array.isArray( pkg.rates ) ) {
			return pkg.rates;
		}
		return [];
	}

	function rateId( rate ) {
		if ( ! rate ) {
			return '';
		}
		return String( rate.rate_id || rate.rateId || rate.id || '' );
	}

	function metaMap( rate ) {
		var out = {};
		var meta = rate && rate.meta_data ? rate.meta_data : [];

		if ( Array.isArray( meta ) ) {
			meta.forEach( function ( row ) {
				if ( row && typeof row.key !== 'undefined' ) {
					out[ String( row.key ) ] = row.value;
				}
			} );
		} else if ( meta && typeof meta === 'object' ) {
			Object.keys( meta ).forEach( function ( key ) {
				out[ key ] = meta[ key ];
			} );
		}

		return out;
	}

	function boolValue( value ) {
		if ( value === true || value === 1 || value === '1' ) {
			return true;
		}
		if ( value === false || value === 0 || value === '0' || value === '' || value === null || typeof value === 'undefined' ) {
			return false;
		}
		return String( value ).toLowerCase() === 'true' || String( value ).toLowerCase() === 'yes';
	}

	function ratePriceState( rate ) {
		var meta = metaMap( rate );
		var state = String( meta.price_state || meta.bgcs3_price_state || meta._bgcs3_price_state || '' ).toLowerCase();
		var hasValidated = typeof meta.validated !== 'undefined' || typeof meta._bgcs3_validated !== 'undefined';
		var validated = boolValue( typeof meta.validated !== 'undefined' ? meta.validated : meta._bgcs3_validated );
		var isFree = boolValue( typeof meta.free_shipping !== 'undefined' ? meta.free_shipping : meta._bgcs3_free_shipping );

		if ( [ 'pending', 'calculated', 'free', 'unavailable' ].indexOf( state ) !== -1 ) {
			return state;
		}
		if ( hasValidated && ! validated ) {
			return 'pending';
		}
		if ( isFree ) {
			return 'free';
		}
		if ( validated ) {
			return 'calculated';
		}
		return '';
	}

	function currentPriceState() {
		var state = {
			pendingIds: {},
			selectedPending: false
		};

		shippingPackages( cartStore() ).forEach( function ( pkg ) {
			packageRates( pkg ).forEach( function ( rate ) {
				var id = rateId( rate );
				if ( id.indexOf( 'bgcs3_' ) !== 0 || ratePriceState( rate ) !== 'pending' ) {
					return;
				}
				state.pendingIds[ id ] = true;
				if ( rate.selected === true || rate.selected === 1 || rate.selected === '1' ) {
					state.selectedPending = true;
				}
			} );
		} );

		return state;
	}

	function pendingText() {
		return window.bgcsCheckout && window.bgcsCheckout.i18n && window.bgcsCheckout.i18n.awaitingCalculation ?
			window.bgcsCheckout.i18n.awaitingCalculation : 'Awaiting calculation';
	}

	function isCartSurface() {
		return window.bgcsCheckout && window.bgcsCheckout.blocksSurface === 'cart';
	}

	function firstTextNode( element ) {
		var i;
		if ( ! element ) {
			return null;
		}
		for ( i = 0; i < element.childNodes.length; i += 1 ) {
			if ( element.childNodes[ i ].nodeType === 3 && String( element.childNodes[ i ].nodeValue || '' ).trim() !== '' ) {
				return element.childNodes[ i ];
			}
		}
		return null;
	}

	function patchPriceElement( element, kind, rateIdValue ) {
		var node;
		var text = pendingText();

		if ( ! element ) {
			return;
		}

		node = firstTextNode( element );
		if ( ! node && element.childNodes.length === 0 ) {
			node = document.createTextNode( '' );
			element.appendChild( node );
		}
		if ( ! node ) {
			return;
		}

		if ( ! element.__bgcsPendingPricePatch ) {
			element.__bgcsPendingPricePatch = {
				node: node,
				original: node.nodeValue,
				hadWooFreeClass: element.classList.contains( 'wc-block-components-shipping-rates-control__package__description--free' )
			};
		}
		// Do not keep WooCommerce's semantic/styling "free" class on a pending
		// quote; besides the wording, themes often use it for uppercase/green badges.
		element.classList.remove( 'wc-block-components-shipping-rates-control__package__description--free' );
		element.setAttribute( 'data-bgcs-pending-price-patched', '1' );
		element.setAttribute( 'data-bgcs-pending-price-kind', kind );
		if ( rateIdValue ) {
			element.setAttribute( 'data-bgcs-pending-rate-id', rateIdValue );
		}
		element.classList.add( 'bgcs-shipping-price-state--pending' );

		if ( node.nodeValue !== text ) {
			node.nodeValue = text;
		}
	}

	function restorePriceElement( element ) {
		var patch = element && element.__bgcsPendingPricePatch ? element.__bgcsPendingPricePatch : null;
		if ( patch && patch.node && patch.node.parentNode === element ) {
			patch.node.nodeValue = patch.original;
		}
		if ( patch && patch.hadWooFreeClass ) {
			element.classList.add( 'wc-block-components-shipping-rates-control__package__description--free' );
		}
		if ( element ) {
			delete element.__bgcsPendingPricePatch;
			element.removeAttribute( 'data-bgcs-pending-price-patched' );
			element.removeAttribute( 'data-bgcs-pending-price-kind' );
			element.removeAttribute( 'data-bgcs-pending-rate-id' );
			element.classList.remove( 'bgcs-shipping-price-state--pending' );
		}
	}

	function restoreResolvedPriceElements( state ) {
		document.querySelectorAll( '[data-bgcs-pending-price-patched="1"]' ).forEach( function ( element ) {
			var kind = element.getAttribute( 'data-bgcs-pending-price-kind' ) || '';
			var id = element.getAttribute( 'data-bgcs-pending-rate-id' ) || '';
			var stillPending = kind === 'total' ? state.selectedPending : !! state.pendingIds[ id ];
			if ( ! stillPending ) {
				restorePriceElement( element );
			}
		} );
	}

	function patchRateOptions( state ) {
		document.querySelectorAll( 'input[type="radio"]' ).forEach( function ( input ) {
			var id = String( input.value || '' );
			var row;
			var price;
			if ( ! state.pendingIds[ id ] ) {
				return;
			}

			row = input.closest ? ( input.closest( '.wc-block-components-radio-control__option' ) || input.closest( 'label' ) ) : input.parentNode;
			if ( ! row || ! row.querySelector ) {
				return;
			}

			price = row.querySelector( '[data-bgcs-pending-price-patched="1"]' ) ||
				row.querySelector( '.wc-block-components-shipping-rates-control__package__description--free' ) ||
				row.querySelector( '.wc-block-components-formatted-money-amount' ) ||
				row.querySelector( '.wc-block-formatted-money-amount' );
			patchPriceElement( price, 'rate', id );
		} );
	}

	function patchShippingTotal( state ) {
		var selector;
		if ( ! state.selectedPending ) {
			return;
		}

		selector = [
			'.wc-block-components-totals-shipping .wc-block-components-totals-item__value',
			'[data-block-name="woocommerce/cart-order-summary-shipping-block"] .wc-block-components-totals-item__value',
			'[data-block-name="woocommerce/checkout-order-summary-shipping-block"] .wc-block-components-totals-item__value'
		].join( ',' );

		document.querySelectorAll( selector ).forEach( function ( value ) {
			var price = value.querySelector( '[data-bgcs-pending-price-patched="1"]' ) ||
				value.querySelector( '.wc-block-components-formatted-money-amount' ) ||
				value.querySelector( '.wc-block-formatted-money-amount' ) ||
				value.querySelector( 'strong' ) || value;
			patchPriceElement( price, 'total', '' );
		} );
	}

	function applyPriceStates() {
		var state = currentPriceState();
		restoreResolvedPriceElements( state );
		patchRateOptions( state );
		patchShippingTotal( state );
	}

	function schedulePriceStateRefresh() {
		window.clearTimeout( priceStateTimer );
		priceStateTimer = window.setTimeout( applyPriceStates, 30 );
	}

	function removeCards() {
		var existing = document.getElementById( 'bgcs-blocks-availability' );
		if ( existing ) {
			existing.remove();
		}
	}

	function render( rows ) {
		removeCards();
		var host = shippingHost();
		if ( ! Array.isArray( rows ) || ! rows.length ) {
			return;
		}
		if ( ! host ) {
			if ( hostAttempts < 10 ) {
				hostAttempts += 1;
				window.setTimeout( refresh, 300 );
			}
			return;
		}
		hostAttempts = 0;

		var root = document.createElement( 'div' );
		root.id = 'bgcs-blocks-availability';
		root.setAttribute( 'data-bgcs-availability-root', '' );

		rows.forEach( function ( row ) {
			if ( ! row || [ 'pending', 'unavailable', 'temporary_error', 'error' ].indexOf( row.status ) === -1 || ! row.customer_message ) {
				return;
			}
			var card = document.createElement( 'div' );
			card.className = 'bgcs-availability-card bgcs-availability-card--' + row.status;
			card.setAttribute( 'role', [ 'temporary_error', 'error' ].indexOf( row.status ) !== -1 ? 'alert' : 'status' );
			card.setAttribute( 'aria-disabled', 'true' );
			card.setAttribute( 'data-bgcs-availability-card', '' );
			card.dataset.courier = row.courier || '';
			card.dataset.status = row.status;
			card.dataset.code = row.code || 'shipping_unavailable';

			var title = document.createElement( 'div' );
			title.className = 'bgcs-availability-card__title';
			title.textContent = row.courier_name || row.courier || '';
			var message = document.createElement( 'div' );
			message.className = 'bgcs-availability-card__message';
			message.textContent = row.customer_message;
			card.appendChild( title );
			card.appendChild( message );
			root.appendChild( card );
		} );

		if ( root.children.length ) {
			host.insertAdjacentElement( 'afterend', root );
		}
	}

	function refresh() {
		if ( isCartSurface() ) {
			removeCards();
			return;
		}
		if ( ! window.bgcsCheckout || ! window.bgcsCheckout.restUrl || ! window.fetch ) {
			return;
		}
		window.fetch( window.bgcsCheckout.restUrl + 'availability', {
			credentials: 'same-origin',
			cache: 'no-store',
			headers: window.bgcsCheckout.nonce ? { 'X-WP-Nonce': window.bgcsCheckout.nonce } : {}
		} ).then( function ( response ) {
			return response.ok ? response.json() : Promise.reject();
		} ).then( function ( payload ) {
			render( payload && payload.availability ? payload.availability : [] );
			schedulePriceStateRefresh();
		} ).catch( removeCards );
	}

	function scheduleRefresh() {
		window.clearTimeout( availabilityTimer );
		availabilityTimer = window.setTimeout( refresh, 250 );
	}

	function cartSignature() {
		var store = cartStore();
		if ( ! store ) {
			return '';
		}
		try {
			var cartData = store.getCartData ? store.getCartData() : {};
			return JSON.stringify( {
				items: cartData && cartData.items ? cartData.items : null,
				cartRates: cartData && ( cartData.shippingRates || cartData.shipping_rates ) ? ( cartData.shippingRates || cartData.shipping_rates ) : null,
				rates: store.getShippingRates ? store.getShippingRates() : null
			} );
		} catch ( error ) {
			return '';
		}
	}

	function startObserver() {
		if ( observer || ! window.MutationObserver || ! document.body ) {
			return;
		}
		observer = new window.MutationObserver( schedulePriceStateRefresh );
		observer.observe( document.body, { childList: true, subtree: true, characterData: true } );
	}

	if ( window.wp && window.wp.data && window.wp.data.subscribe ) {
		window.wp.data.subscribe( function () {
			var next = cartSignature();
			if ( next && next !== lastCartSignature ) {
				lastCartSignature = next;
				if ( ! isCartSurface() ) {
					scheduleRefresh();
				}
				schedulePriceStateRefresh();
			}
		} );
	}

	function boot() {
		startObserver();
		if ( ! isCartSurface() ) {
			scheduleRefresh();
		}
		schedulePriceStateRefresh();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}() );
