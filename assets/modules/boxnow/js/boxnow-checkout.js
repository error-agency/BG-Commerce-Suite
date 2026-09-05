/**
 * BG Commerce Suite — BOX NOW checkout add-on.
 *
 * Plugs into the core selector through window.bgcsCourier (registered by
 * assets/js/bgcs-checkout.js, which is a declared dependency of this script).
 * Core knows nothing about BoxNow.
 */
( function ( $ ) {
	'use strict';

	if ( ! $ || ! window.bgcsCourier || ! window.bgcsCourier.api ) {
		return;
	}

	var api = window.bgcsCourier.api;
	var previousFocus = null;

	function cfg() {
		return window.bgcsBoxNow || {};
	}

	function t( key, fallback ) {
		var i18n = cfg().i18n || {};
		return i18n[ key ] || fallback;
	}

	/**
	 * Whether the official map is the picker.
	 *
	 * BOX NOW publishes ONE map and it is a production service — it only knows
	 * real lockers. On the stage API those ids do not exist, so a locker chosen
	 * from the map is rejected at label time (P402). Test mode therefore falls
	 * back to core's own city + locker list, which is fed by the stage account
	 * and offers exactly the few test lockers that can actually be used.
	 *
	 * @return {boolean} true on the live API.
	 */
	function usesMap() {
		return 'live' === cfg().env;
	}

	function $slot() {
		return $( '#boxnowmap' );
	}

	function $picker() {
		return $( '.bgcs-boxnow-picker' );
	}

	function $dialog() {
		return $( '#bgcs-boxnow-dialog' );
	}

	function renderSelection( selection ) {
		var $summary = $( '#bgcs-boxnow-selection' );
		var office = selection && selection.office ? selection.office : null;
		if ( ! office ) {
			$summary.prop( 'hidden', true ).empty();
			return;
		}
		$summary.text( office.text || office.id || '' ).prop( 'hidden', false );
	}

	/**
	 * The map's iframe URL. `countryCode`, `language` and `partnerId` are all
	 * required; 1.4.x sent only the partner id, so a Bulgarian shop asked an
	 * unconfigured map for lockers.
	 *
	 * @return {string} URL.
	 */
	function mapUrl() {
		var c = cfg();
		var params = [
			'countryCode=' + encodeURIComponent( c.countryCode || 'bg' ),
			'language=' + encodeURIComponent( c.language || 'bg' ),
			'partnerId=' + encodeURIComponent( c.partnerId || '' ),
			'gps=' + ( 'no' === c.gps ? 'no' : 'yes' ),
			// `autoclose=no` is ours to keep: the modal around the map belongs to
			// us, so the widget must not remove itself.
			'autoclose=no',
			// `autoselect` MUST stay `yes`. It does not mean "pre-select a locker"
			// — it means "emit the chosen locker". Read from their own widget:
			//
			//   fetchBoxPositions.js / reorderBoxes.js
			//     autoselect == 'no'  ->  showSelectLockerButtons = true
			//   markerListener.js
			//     0 == showSelectLockerButtons && 'iframe' == mapType
			//       && 0 == selectLockerAutoclose
			//         ? markerClicked(marker, true)   // posts to the parent
			//         : markerClicked(marker, false)  // stays silent
			//   markerClicked.js
			//     n && window.parent.postMessage({ boxnowLockerId, … }, '*')
			//
			// So `autoselect=no` silently severs the ONLY channel the widget has
			// back to us: the customer picks a locker and nothing happens. 1.5.1
			// through 1.6.0 shipped exactly that.
			'autoselect=yes',
		];

		return c.mapSrc + '?' + params.join( '&' );
	}

	function onLockerSelected( selected ) {
		if ( ! selected || ! selected.boxnowLockerId ) {
			return;
		}

		var line1 = selected.boxnowLockerAddressLine1 || '';
		var selection = {
			courier: 'boxnow',
			delivery_type: 'locker',
			country: 'BG',
			city: {
				id: '',
				name: line1.split( ',' )[ 0 ] || '',
				post_code: selected.boxnowLockerPostalCode || '',
			},
			office: {
				id: String( selected.boxnowLockerId ),
				text: 'BOX NOW ' + line1 + ' (' + selected.boxnowLockerId + ')',
			},
			address: null,
		};

		api.setSelection( selection );
		renderSelection( selection );
		closeModal();
	}

	function initWidget() {
		var partnerId = cfg().partnerId || '';
		var $map = $slot();

		if ( $map.children().length ) {
			return;
		}

		if ( '' === partnerId ) {
			// Without the merchant's own Partner ID the widget would list another
			// partner's lockers, whose ids are invalid for this account (P402).
			$map.text( t( 'noPartner', 'BOX NOW is not configured (Partner ID is missing).' ) );
			return;
		}

		$map.empty().append(
			$( '<iframe>', {
				src: mapUrl(),
				title: t( 'mapTitle', 'BOX NOW locker map' ),
				allow: 'geolocation',
			} )
		);
	}

	function openModal() {
		var $modal = $dialog();
		if ( ! $modal.length ) {
			return;
		}
		previousFocus = document.activeElement;
		$modal.prop( 'hidden', false );
		$( 'body' ).addClass( 'bgcs-boxnow-modal-open' );
		initWidget();
		$modal.find( '.bgcs-boxnow-modal__close' ).trigger( 'focus' );
	}

	function closeModal() {
		$dialog().prop( 'hidden', true );
		$( 'body' ).removeClass( 'bgcs-boxnow-modal-open' );
		if ( previousFocus && typeof previousFocus.focus === 'function' ) {
			previousFocus.focus();
		}
		previousFocus = null;
	}

	function trapFocus( event ) {
		var $modal = $dialog();
		if ( $modal.prop( 'hidden' ) ) {
			return;
		}
		if ( event.key === 'Escape' ) {
			event.preventDefault();
			closeModal();
			return;
		}
		if ( event.key !== 'Tab' ) {
			return;
		}
		var $focusable = $modal.find( 'button, iframe, [tabindex]:not([tabindex="-1"])' ).filter( ':visible' );
		if ( ! $focusable.length ) {
			return;
		}
		var first = $focusable.get( 0 );
		var last = $focusable.get( $focusable.length - 1 );
		if ( event.shiftKey && document.activeElement === first ) {
			event.preventDefault();
			last.focus();
		} else if ( ! event.shiftKey && document.activeElement === last ) {
			event.preventDefault();
			first.focus();
		}
	}

	window.bgcsCourier.register( 'boxnow', {
		/**
		 * On the live API the map owns the picker entirely. On the test API it is
		 * not shown at all and core's stepped city + locker UI takes over —
		 * returning false is what hands control back.
		 *
		 * @param {Object} ctx { host, type }
		 * @return {boolean} true when this add-on rendered the UI itself.
		 */
		renderPicker: function ( ctx ) {
			if ( ! usesMap() ) {
				// Leave the rows alone: core is about to render them itself.
				$picker().prop( 'hidden', true );
				closeModal();
				$slot().empty();
				return false;
			}

			// `.bgcs-city-row` belongs here too. Without it the city search stayed
			// visible next to the map button, because core returns early on `true`
			// and never gets to hide its own rows.
			ctx.host
				.find( '.bgcs-city-row, .bgcs-office-search-row, .bgcs-address-rows, .bgcs-map' )
				.hide();
			$picker().prop( 'hidden', false );
			return true;
		},

		/**
		 * Restores a locker the customer already chose, so returning to the
		 * checkout does not silently clear the selection.
		 *
		 * Only in map mode. In test mode there ARE city/office dropdowns to
		 * repopulate, which is core's job — claiming the restore would leave them
		 * empty while a selection was already set.
		 *
		 * @param {Object} saved Saved selection.
		 * @return {boolean} true when handled.
		 */
		restore: function ( saved ) {
			if ( ! usesMap() || ! saved || ! saved.office ) {
				return false;
			}
			api.setSelection( saved, { silent: true } );
			renderSelection( saved );
			return true;
		},

		/**
		 * Called by core when another courier becomes active — tear down our UI.
		 */
		hide: function () {
			$picker().prop( 'hidden', true );
			renderSelection( null );
			closeModal();
			$slot().empty();
		},

		onSync: function () {
			api.restore( 'boxnow' );
		},
	} );

	/**
	 * The map reports the chosen locker by posting it to the parent page. The
	 * origin is compared EXACTLY: 1.4.x used `/^https:\/\/.*\.boxnow\..*$/`,
	 * which also matches a host like `x.boxnow.attacker.com`, so any frame on
	 * such a domain could have set the customer's locker.
	 *
	 * @param {MessageEvent} event Message.
	 */
	function onMessage( event ) {
		if ( event.origin !== cfg().mapOrigin ) {
			return;
		}

		onLockerSelected( event.data );
	}

	$( function () {
		window.removeEventListener( 'message', onMessage );
		window.addEventListener( 'message', onMessage, false );
		$( document )
			.off( 'click.bgcsBoxNow', '#bgcs-boxnow-open' )
			.on( 'click.bgcsBoxNow', '#bgcs-boxnow-open', openModal )
			.off( 'click.bgcsBoxNowClose', '[data-bgcs-boxnow-close]' )
			.on( 'click.bgcsBoxNowClose', '[data-bgcs-boxnow-close]', closeModal )
			.off( 'keydown.bgcsBoxNow', '#bgcs-boxnow-dialog' )
			.on( 'keydown.bgcsBoxNow', '#bgcs-boxnow-dialog', trapFocus );
	} );
} )( window.jQuery );
