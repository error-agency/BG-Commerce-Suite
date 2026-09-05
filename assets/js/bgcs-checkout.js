/**
 * BG Commerce Suite — classic checkout selector.
 *
 * Plain jQuery + Leaflet (vendored). No build step.
 * Real form fields live in the stable checkout
 * form; a hidden marker after each shipping rate (in order_review, which WC
 * rebuilds) tells us the chosen courier + allowed delivery types.
 */
( function ( $ ) {
	'use strict';

	var stateApi = window.BgcsCheckoutState;

	if ( ! $ || ! stateApi ) {
		return;
	}

	var cfg = function () {
		return window.bgcsCheckout || {};
	};
	var t = function ( k, d ) {
		var i = cfg().i18n || {};
		return i[ k ] || d;
	};

	var cityCache = {};
	var officeCache = {};
	var streetCache = {};
	var responseCache = {};
	var cityOfficePool = []; // Offices/lockers of the currently chosen city.
	var selectedOffice = null; // Currently picked office/locker (office/APS types).
	var pendingCityOfficesKey = null; // In-flight /office-search for the chosen city.
	var pendingCityOfficesOpen = false; // Дали резултатът от нея да отвори списъка.
	var addonOwnsPicker = false; // A courier add-on renders the picker itself.
	var officeSearchTimer = null;
	var streetSearchTimer = null;
	var map = null;
	var markerLayer = null;
	var markerById = {};
	var requestGate = stateApi.createRequestGate();
	var revisionClock = stateApi.createRevisionClock();
	var courierTransitions = stateApi.createCourierTransitionObserver();

	/**
	 * Browser persistence of the customer's selection, gated by the merchant's
	 * `checkout.remember_selection` setting (BGCS-AUDIT-002).
	 *
	 * Passing no storage yields a store whose save/load/clear are all no-ops, so
	 * "off" means nothing is written rather than written-and-ignored. The flag is
	 * read once here: it is a page-load configuration, not per-request state.
	 */
	var selectionStorage = false === cfg().rememberSelection ? null : window.localStorage;
	var selectionStore = stateApi.createSelectionStore(
		selectionStorage,
		'bgcs3_selection_'
	);

	// Switching the setting off must also take away what was stored while it was
	// on — otherwise a merchant who turns it off for a shared computer still
	// leaves the previous customer's address in that browser.
	if ( null === selectionStorage ) {
		forgetStoredSelections();
	}

	/**
	 * Remove every `bgcs3_selection_*` entry this plugin has written.
	 */
	function forgetStoredSelections() {
		var storage = window.localStorage;
		if ( ! storage ) {
			return;
		}
		try {
			var stale = [];
			for ( var i = 0; i < storage.length; i++ ) {
				var name = storage.key( i );
				if ( name && 0 === name.indexOf( 'bgcs3_selection_' ) ) {
					stale.push( name );
				}
			}
			for ( var j = 0; j < stale.length; j++ ) {
				storage.removeItem( stale[ j ] );
			}
		} catch ( error ) {
			// Private mode, disabled storage, quota errors — nothing to clean up.
		}
	}
	var loadingChannels = {};
	var checkoutUpdateEpoch = 0;
	var checkoutUpdateStartedAt = 0;
	var updateScheduler = stateApi.createUpdateScheduler( function () {
		$host().addClass( 'is-updating' );
		$( document.body ).trigger( 'update_checkout' );
	} );
	var addressRefresh = stateApi.debounce( function () {
		syncSelection();
	}, 450 );

	var cleanCheckoutManagedFields = [
		'billing_company',
		'billing_address_1',
		'billing_address_2',
		'billing_city',
		'billing_state',
		'billing_postcode',
		'billing_country',
		'shipping_city',
	];
	function isBgcsRateId( rateId ) {
		return 'string' === typeof rateId && 0 === rateId.indexOf( 'bgcs3_' );
	}

	function selectedShippingRateIds() {
		var ids = [];
		document.querySelectorAll( 'input[name^="shipping_method"]' ).forEach( function ( input ) {
			if ( input.checked || 'hidden' === input.type ) {
				var value = String( input.value || '' );
				if ( value && -1 === ids.indexOf( value ) ) {
					ids.push( value );
				}
			}
		} );
		return ids;
	}

	function cleanCheckoutOwnsNativeFields() {
		var ids = selectedShippingRateIds();
		return ids.length > 0 && ids.every( isBgcsRateId );
	}

	/*
	 * Core owns field visibility. Clean checkout may suppress native address
	 * fields only while a BGCS rate is selected. External methods keep the full
	 * WooCommerce field contract, including state/region and required markers.
	 */
	function syncCleanCheckoutFields() {
		if ( true !== cfg().cleanCheckout ) {
			document.body.classList.remove( 'bgcs3-clean-fields-active' );
			return;
		}

		var active = cleanCheckoutOwnsNativeFields();
		document.body.classList.toggle( 'bgcs3-clean-fields-active', active );

		cleanCheckoutManagedFields.forEach( function ( key ) {
			var $row = $( '#' + key + '_field' );
			var $input = $( '[name="' + key + '"]' ).first();
			if ( ! $row.length || ! $input.length ) {
				return;
			}

			var original = $input.attr( 'data-bgcs-runtime-original-required' );
			if ( '0' !== original && '1' !== original ) {
				original = $input.attr( 'data-bgcs-original-required' );
			}
			if ( '0' !== original && '1' !== original ) {
				original =
					$input.prop( 'required' ) || $row.hasClass( 'validate-required' )
						? '1'
						: '0';
			}
			$input.attr( 'data-bgcs-runtime-original-required', original );

			if ( active ) {
				$row.addClass( 'bgcs-clean-field-suspended' ).removeClass( 'validate-required' );
				$input.prop( 'required', false ).attr( 'aria-required', 'false' );
			} else {
				$row.removeClass( 'bgcs-clean-field-suspended' );
				if ( '1' === original ) {
					$row.addClass( 'validate-required' );
					$input.prop( 'required', true ).attr( 'aria-required', 'true' );
				} else {
					$input.prop( 'required', false ).removeAttr( 'aria-required' );
				}
			}
		} );

	}

	/* Read the checkout postcode so the office search can prefilter by it. */
	function checkoutPostcode() {
		var el =
			document.querySelector( 'input[name="shipping_postcode"]' ) ||
			document.querySelector( 'input[name="billing_postcode"]' );
		return el && el.value ? el.value.trim() : '';
	}

	/*
	 * Clean checkout has one visible city source: the BGCS picker. Keep the
	 * hidden native WooCommerce city/postcode inputs synchronised so WC shipping,
	 * taxes and checkout serialisation still receive their normal field names.
	 * No change event is emitted here: syncSelection already schedules the one
	 * WooCommerce update_checkout refresh we need.
	 */
	function syncNativeWooAddress( city ) {
		if ( true !== cfg().cleanCheckout || ! cleanCheckoutOwnsNativeFields() ) {
			return;
		}

		var name = city && ( city.name || city.text ) ? String( city.name || city.text ).trim() : '';
		var postcode = city && city.post_code ? String( city.post_code ).trim() : '';

		$( '[name="billing_city"], [name="shipping_city"]' ).val( name );
		$( '[name="billing_postcode"], [name="shipping_postcode"]' ).val( postcode );
	}

	function $host() {
		return $( '#bgcs-selector-host' );
	}

	function selectedType() {
		return $host().find( 'input[name="bgcs3_delivery_type"]:checked' ).val() || '';
	}

	function isOfficeLike( type ) {
		return type === 'office' || type === 'locker';
	}

	/* ---- Chosen shipping rate -> courier + allowed types ---- */
	function getChosenRateId() {
		var checked = document.querySelector( 'input[name^="shipping_method"]:checked' );
		if ( checked ) {
			return checked.value;
		}
		var hidden = document.querySelector( 'input[name^="shipping_method"][type="hidden"]' );
		return hidden ? hidden.value : null;
	}

	function getRateMeta( rateId ) {
		if ( ! rateId ) {
			return null;
		}
		var el = document.querySelector( '.bgcs-rate-meta[data-rate-id="' + rateId + '"]' );
		if ( ! el ) {
			return null;
		}
		return {
			courier: el.getAttribute( 'data-courier' ),
			types: ( el.getAttribute( 'data-delivery-types' ) || '' )
				.split( ',' )
				.map( function ( s ) {
					return s.trim();
				} )
				.filter( Boolean ),
		};
	}

	function cacheKey( parts ) {
		return parts.map( function ( part ) {
			return String( part || '' ).toLowerCase().trim();
		} ).join( '|' );
	}

	function setLoading( channel, loading ) {
		if ( loading ) {
			loadingChannels[ channel ] = true;
		} else {
			delete loadingChannels[ channel ];
		}
		$host().toggleClass( 'is-loading', Object.keys( loadingChannels ).length > 0 );
	}

	function setStatus( kind, message ) {
		var $status = $host().find( '.bgcs-selector-status' );
		$status
			.removeClass( 'is-error is-loading' )
			.toggleClass( 'is-error', 'error' === kind )
			.toggleClass( 'is-loading', 'loading' === kind )
			.text( message || '' )
			.prop( 'hidden', ! message );
	}

	function fetchJson( url, request ) {
		return window.fetch( url, {
			method: 'GET',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': cfg().nonce },
			signal: request.signal,
		} ).then( function ( response ) {
			return response.json().catch( function () {
				throw new Error( t( 'requestError', 'We could not load the data. Try again.' ) );
			} ).then( function ( payload ) {
				if ( ! response.ok ) {
					throw new Error(
						payload && payload.message
							? payload.message
							: t( 'requestError', 'We could not load the data. Try again.' )
					);
				}
				return payload;
			} );
		} );
	}

	function escapeHtml( value ) {
		return $( '<div>' ).text( value || '' ).html();
	}

	function triggerUpdate() {
		updateScheduler.request();
	}

	/*
	 * Flow and some custom checkout renderers emit only a jQuery shipping-method
	 * change and do not run WooCommerce's classic refresh handler. Defer one tick
	 * and provide a fallback only when no other handler started an update around
	 * the same event dispatch. This keeps external methods in sync without issuing
	 * a duplicate request in Classic Checkout.
	 */
	function ensureExternalShippingRefresh( input ) {
		if ( ! input || isBgcsRateId( String( input.value || '' ) ) ) {
			return;
		}

		var observedEpoch = checkoutUpdateEpoch;
		window.setTimeout( function () {
			// Flow only re-renders its method cards on radio changes. Emit the native
			// checkout event directly after the checked state is final; the BGCS
			// scheduler may still be busy from an earlier courier refresh and must not
			// swallow this external-method transition.
			if ( document.body.classList.contains( 'bgcs-flow-surface' ) ) {
				$( document.body ).trigger( 'update_checkout' );
				return;
			}

			var nativeRefreshStarted = observedEpoch !== checkoutUpdateEpoch
				|| Date.now() - checkoutUpdateStartedAt < 100;
			if ( ! nativeRefreshStarted ) {
				updateScheduler.request();
			}
		}, 0 );
	}

	/* ---- Map (Leaflet) ---- */
	function mapEnabled() {
		return cfg().showMap !== false && !! window.L;
	}

	function $deliveryMap() {
		var $map = $( '#bgcs3_delivery_map' );
		return $map.length ? $map : $host().find( '.bgcs-map' ).first();
	}

	function leafletIcon() {
		var base = cfg().leafletImages || '';
		return window.L.icon( {
			iconUrl: base + 'marker-icon.png',
			iconRetinaUrl: base + 'marker-icon-2x.png',
			shadowUrl: base + 'marker-shadow.png',
			iconSize: [ 25, 41 ],
			iconAnchor: [ 12, 41 ],
			popupAnchor: [ 1, -34 ],
			shadowSize: [ 41, 41 ],
		} );
	}

	function ensureMap() {
		var el = $deliveryMap().get( 0 );
		if ( ! el || map ) {
			return;
		}
		map = window.L.map( el ).setView( [ 42.7, 25.3 ], 7 );
		window.L.tileLayer( 'https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
			attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
			referrerPolicy: 'strict-origin-when-cross-origin',
			maxZoom: 19,
		} ).addTo( map );
		markerLayer = window.L.layerGroup().addTo( map );
	}

	function hideMap() {
		$deliveryMap().hide();
	}

	/* ---- Стъпков поток: първо град, чак после офис/автомат или адрес ---- */

	function prefersReducedMotion() {
		return !! (
			window.matchMedia &&
			window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches
		);
	}

	/**
	 * Show or hide a follow-up step (office picker / address fields). The step
	 * only opens once a city is committed, so the customer fills the form in
	 * order: куриер → тип доставка → град → офис/автомат или адрес.
	 *
	 * @param {jQuery}  $el     Row wrapper.
	 * @param {boolean} show    Target state.
	 * @param {boolean} animate Slide instead of a hard toggle (user actions only).
	 */
	function toggleStep( $el, show, animate ) {
		if ( ! $el || ! $el.length ) {
			return;
		}

		show = !! show;

		// Тече ли анимация, състоянието още не е окончателно — не подранявай с
		// изход, иначе едно закъсняло slideUp скрива току-що отворената стъпка.
		if ( ! $el.is( ':animated' ) && show === ( 'none' !== $el.css( 'display' ) ) ) {
			return;
		}

		if ( ! animate || prefersReducedMotion() ) {
			$el.stop( true, true ).toggle( show );
			return;
		}

		if ( show ) {
			$el.stop( true, true ).slideDown( 200 );
		} else {
			$el.stop( true, true ).slideUp( 150 );
		}
	}

	function $officeStep() {
		return $host().find( '.bgcs-office-search-row' );
	}

	function $addressStep() {
		return $host().find( '.bgcs-address-rows' );
	}

	/**
	 * The follow-up step needs a committed city. A previously saved selection
	 * (from before the stepped flow) may hold an office without a city — keep it
	 * visible so the customer still sees what is chosen.
	 *
	 * @return {boolean} Whether the step after the city field may be shown.
	 */
	function stepUnlocked() {
		if ( currentCity() ) {
			return true;
		}
		if ( isOfficeLike( selectedType() ) ) {
			return !! ( selectedOffice || $( '#bgcs3_office' ).val() );
		}
		if ( 'address' === selectedType() ) {
			return !! $( '#bgcs3_address_street' ).val();
		}
		return false;
	}

	/**
	 * Wipe the office list and the map markers. Used when the courier changes
	 * (another courier's offices/markers must never linger) and when no city
	 * is selected yet.
	 */
	function resetPicker() {
		requestGate.abort( 'cities' );
		requestGate.abort( 'offices' );
		requestGate.abort( 'streets' );
		setLoading( 'cities', false );
		setLoading( 'offices', false );
		setLoading( 'streets', false );
		setStatus( '', '' );
		addressRefresh.cancel();
		$( '#bgcs3_office' ).val( '' );
		$( '#bgcs3_office_search' ).val( '' );
		$( '#bgcs3_city' ).val( '' );
		$( '#bgcs3_city_search' ).val( '' );
		$( '#bgcs3_address_street, #bgcs3_address_num, #bgcs3_address_note' ).val( '' );
		$host().find( '.bgcs-office-suggestions, .bgcs-city-suggestions, .bgcs-street-suggestions' ).empty().attr( 'hidden', true );
		$host().find( '.bgcs-office-empty' ).attr( 'hidden', true );
		cityCache = {};
		officeCache = {};
		streetCache = {};
		cityOfficePool = [];
		selectedOffice = null;
		pendingCityOfficesKey = null;
		pendingCityOfficesOpen = false;
		addonOwnsPicker = false;

		if ( markerLayer ) {
			markerLayer.clearLayers();
		}
		markerById = {};
		hideMap();
	}

	function renderMap( offices ) {
		if ( ! mapEnabled() ) {
			hideMap();
			return;
		}
		var $map = $deliveryMap();
		$map.show();

		try {
			ensureMap();
			if ( ! map ) {
				return;
			}
			window.setTimeout( function () {
				map.invalidateSize();
			}, 60 );

			markerLayer.clearLayers();
			markerById = {};
			var points = [];
			var icon = leafletIcon();

			( offices || [] ).forEach( function ( o ) {
				if ( ! o.lat || ! o.lng ) {
					return;
				}
				var marker = window.L.marker( [ o.lat, o.lng ], { icon: icon } );
				marker.bindPopup(
					'<strong>' + escapeHtml( o.name || o.text || '' ) + '</strong>' +
					( o.address ? '<br>' + escapeHtml( o.address ) : '' )
				);
				marker.on( 'mouseover', function () {
					this.openPopup();
				} );
				marker.on( 'click', function () {
					pickOfficeFromMap( o );
				} );
				marker.addTo( markerLayer );
				markerById[ String( o.id ) ] = marker;
				points.push( [ o.lat, o.lng ] );
			} );

			if ( points.length ) {
				map.fitBounds( points, { maxZoom: 14, padding: [ 25, 25 ] } );
			}
		} catch ( e ) {}
	}

	function pickOfficeFromMap( office ) {
		selectOffice( office );
		if ( markerById[ String( office.id ) ] ) {
			markerById[ String( office.id ) ].openPopup();
		}
	}

	/* ---- City autocomplete ---- */
	var citySearchTimer = null;

	/* Plain, robust city autocomplete (no select2 dependency). */
	function searchCities( query ) {
		var courier = $host().attr( 'data-courier' );
		query = ( query || '' ).trim();
		if ( ! courier ) {
			return;
		}
		if ( query.length < 2 ) {
			requestGate.abort( 'cities' );
			setLoading( 'cities', false );
			renderCitySuggestions( [] );
			return;
		}

		var key = cacheKey( [ 'cities', courier, query ] );
		if ( Object.prototype.hasOwnProperty.call( responseCache, key ) ) {
			renderCitySuggestions( responseCache[ key ] );
			return;
		}

		var request = requestGate.begin( 'cities', {
			courier: courier,
			query: query,
		} );
		setLoading( 'cities', true );
		setStatus( 'loading', t( 'loading', 'Loading…' ) );

		fetchJson(
			cfg().restUrl + courier + '/cities?query=' + encodeURIComponent( query ),
			request
		)
			.then( function ( list ) {
				if (
					! request.isCurrent() ||
					request.context.courier !== $host().attr( 'data-courier' ) ||
					request.context.query !== $( '#bgcs3_city_search' ).val().trim()
				) {
					return;
				}
				list = Array.isArray( list ) ? list : [];
				responseCache[ key ] = list;
				renderCitySuggestions( list );
				setStatus( '', '' );
			} )
			.catch( function ( error ) {
				if ( 'AbortError' !== error.name && request.isCurrent() ) {
					setStatus( 'error', error.message || t( 'requestError', 'We could not load the data. Try again.' ) );
				}
			} )
			.finally( function () {
				if ( request.isCurrent() ) {
					setLoading( 'cities', false );
				}
			} );
	}

	function renderCitySuggestions( list ) {
		var $ul = $host().find( '.bgcs-city-suggestions' );
		$ul.empty();
		if ( ! list.length ) {
			$ul.attr( 'hidden', true );
			$( '#bgcs3_city_search' ).attr( 'aria-expanded', 'false' );
			return;
		}
		list.forEach( function ( c ) {
			cityCache[ String( c.id ) ] = c;
			$( '<li/>', {
				'class': 'bgcs-city-option bgcs-office-option',
				role: 'option',
				'data-id': String( c.id ),
				text: c.text || c.name,
			} ).appendTo( $ul );
		} );
		$ul.attr( 'hidden', false );
		$( '#bgcs3_city_search' ).attr( 'aria-expanded', 'true' );
	}

	/* Commit a city choice; then scope the office list + map to it. */
	function selectCity( city ) {
		if ( ! city || ! city.id ) {
			return;
		}
		cityCache[ String( city.id ) ] = city;
		$( '#bgcs3_city' ).val( String( city.id ) );
		$( '#bgcs3_city_search' ).val( city.name || city.text || '' );
		$host().find( '.bgcs-city-suggestions' ).empty().attr( 'hidden', true );

		// Changing city invalidates any previously picked office.
		selectedOffice = null;
		$( '#bgcs3_office' ).val( '' );
		$( '#bgcs3_office_search' ).val( '' );

		if ( isOfficeLike( selectedType() ) ) {
			// Списъкът се отваря от фокуса в полето по-долу, не оттук.
			loadCityOffices( false );
		} else if ( 'address' === selectedType() ) {
			requestGate.abort( 'streets' );
			streetCache = {};
			$( '#bgcs3_address_street, #bgcs3_address_num, #bgcs3_address_note' ).val( '' );
			renderStreetSuggestions( [] );
		}

		revealAfterCity();
		syncSelection();
	}

	/* Градът е потвърден — отвори следващата стъпка и премести курсора в нея. */
	function revealAfterCity() {
		if ( addonOwnsPicker ) {
			return;
		}

		var type = selectedType();
		var $next = null;
		var focusId = '';

		if ( isOfficeLike( type ) ) {
			$next = $officeStep();
			focusId = '#bgcs3_office_search';
		} else if ( 'address' === type ) {
			$next = $addressStep();
			focusId = '#bgcs3_address_street';
		}

		if ( ! $next || ! $next.length ) {
			return;
		}

		toggleStep( $next, true, true );
		$( focusId ).trigger( 'focus' );
	}

	/**
	 * The city field no longer holds a committed city (the customer started
	 * editing it). Close the step below it and drop what was chosen there — a
	 * different city always means a different office / street.
	 */
	function clearAfterCity() {
		requestGate.abort( 'offices' );
		requestGate.abort( 'streets' );
		setLoading( 'offices', false );
		setLoading( 'streets', false );
		pendingCityOfficesKey = null;
		pendingCityOfficesOpen = false;
		addressRefresh.cancel();

		selectedOffice = null;
		cityOfficePool = [];
		streetCache = {};
		$( '#bgcs3_office' ).val( '' );
		$( '#bgcs3_office_search' ).val( '' );
		$( '#bgcs3_address_street, #bgcs3_address_num, #bgcs3_address_note' ).val( '' );
		$( '#bgcs3_office_suggestions' ).empty().attr( 'hidden', true );
		$host().find( '.bgcs-street-suggestions' ).empty().attr( 'hidden', true );
		$host().find( '.bgcs-office-empty' ).attr( 'hidden', true );
		hideMap();

		if ( ! addonOwnsPicker ) {
			toggleStep( $officeStep(), false, true );
			toggleStep( $addressStep(), false, true );
		}

		syncSelection( false );
	}

	function currentCity() {
		return cityCache[ String( $( '#bgcs3_city' ).val() ) ] || null;
	}

	/* ---- Address street autocomplete ---- */
	function searchStreets( query ) {
		var courier = $host().attr( 'data-courier' );
		var city = currentCity();
		query = ( query || '' ).trim();

		if ( ! courier || 'address' !== selectedType() || ! city || query.length < 2 ) {
			requestGate.abort( 'streets' );
			setLoading( 'streets', false );
			renderStreetSuggestions( [] );
			return;
		}

		var key = cacheKey( [ 'streets', courier, city.id, query ] );
		if ( Object.prototype.hasOwnProperty.call( responseCache, key ) ) {
			renderStreetSuggestions( responseCache[ key ] );
			return;
		}

		var request = requestGate.begin( 'streets', {
			courier: courier,
			cityId: String( city.id ),
			query: query,
		} );
		setLoading( 'streets', true );
		setStatus( 'loading', t( 'loading', 'Loading…' ) );

		fetchJson(
			cfg().restUrl + courier + '/streets?city_id=' + encodeURIComponent( city.id ) +
				'&query=' + encodeURIComponent( query ),
			request
		)
			.then( function ( list ) {
				var activeCity = currentCity();
				if (
					! request.isCurrent() ||
					request.context.courier !== $host().attr( 'data-courier' ) ||
					'address' !== selectedType() ||
					! activeCity ||
					request.context.cityId !== String( activeCity.id ) ||
					request.context.query !== $( '#bgcs3_address_street' ).val().trim()
				) {
					return;
				}
				list = Array.isArray( list ) ? list : [];
				responseCache[ key ] = list;
				renderStreetSuggestions( list );
				setStatus( '', '' );
			} )
			.catch( function ( error ) {
				if ( 'AbortError' !== error.name && request.isCurrent() ) {
					setStatus( 'error', error.message || t( 'requestError', 'We could not load the data. Try again.' ) );
				}
			} )
			.finally( function () {
				if ( request.isCurrent() ) {
					setLoading( 'streets', false );
				}
			} );
	}

	function renderStreetSuggestions( list ) {
		var $list = $host().find( '.bgcs-street-suggestions' );
		$list.empty();

		if ( ! list.length ) {
			$list.attr( 'hidden', true );
			$( '#bgcs3_address_street' ).attr( 'aria-expanded', 'false' );
			return;
		}

		list.forEach( function ( street, index ) {
			var key = String( street.id || street.text || street.name || index );
			streetCache[ key ] = street;
			$( '<li/>', {
				'class': 'bgcs-street-option bgcs-office-option',
				role: 'option',
				'data-key': key,
				text: street.text || street.name || '',
			} ).appendTo( $list );
		} );
		$list.attr( 'hidden', false );
		$( '#bgcs3_address_street' ).attr( 'aria-expanded', 'true' );
	}

	function selectStreet( street ) {
		if ( ! street ) {
			return;
		}
		$( '#bgcs3_address_street' ).val( street.text || street.name || '' );
		$host().find( '.bgcs-street-suggestions' ).empty().attr( 'hidden', true );
		$( '#bgcs3_address_street' ).attr( 'aria-expanded', 'false' );
		addressRefresh.cancel();
		syncSelection();
	}

	/* ---- Courier extension API ----
	 * Add-on plugins register handlers for their courier id:
	 *
	 *   window.bgcsCourier.register( 'boxnow', {
	 *       renderPicker: function ( ctx ) { ...; return true; }, // owns the UI
	 *       restore:      function ( saved ) { ...; return true; },
	 *       onSync:       function () {}
	 *   } );
	 *
	 * Returning true from renderPicker/restore tells core the add-on took over.
	 */
	function courierHook( courier, name, arg ) {
		var reg = window.bgcsCourier;
		var h = reg && reg.handlers ? reg.handlers[ courier ] : null;
		if ( h && typeof h[ name ] === 'function' ) {
			return h[ name ]( arg );
		}
		return undefined;
	}

	/**
	 * Let every add-on that is NOT the active courier tear down its own UI
	 * (e.g. hide a map widget) when the customer switches shipping method.
	 *
	 * @param {string} active Active courier id.
	 */
	function hideInactiveCouriers( active ) {
		var handlers = ( window.bgcsCourier && window.bgcsCourier.handlers ) || {};
		Object.keys( handlers ).forEach( function ( id ) {
			if ( id !== active && typeof handlers[ id ].hide === 'function' ) {
				handlers[ id ].hide();
			}
		} );
	}

	/* ---- Show the right picker for the selected type ----
	 * Stepped: only the city field is shown until a city is committed; the
	 * office / locker picker (or the address fields) opens after it.
	 *
	 * @param {boolean} [animate] Slide the follow-up step (user-driven changes).
	 */
	function loadOfficesOrAddress( animate ) {
		var courier = $host().attr( 'data-courier' );
		var $cityRow = $host().find( '.bgcs-city-row' );
		var $officeSearch = $officeStep();
		var $addressRows = $addressStep();

		hideInactiveCouriers( courier );

		// A courier add-on may fully own the picker UI. Returning true means it
		// rendered everything itself — тогава стъпковата логика не се меси.
		addonOwnsPicker =
			true === courierHook( courier, 'renderPicker', { host: $host(), type: selectedType() } );
		if ( addonOwnsPicker ) {
			return;
		}

		var type = selectedType();
		var unlocked = stepUnlocked();

		if ( isOfficeLike( type ) ) {
			$cityRow.show();
			$addressRows.hide();
			$host().find( '.bgcs-office-label' ).text(
				type === 'locker' ? t( 'chooseLocker', 'Select a locker' ) : t( 'chooseOffice', 'Select an office' )
			);
			toggleStep( $officeSearch, unlocked, animate );

			if ( currentCity() ) {
				// A city is chosen — scope the map + list to it.
				loadCityOffices();
			} else {
				// Без град няма какво да се покаже — картата чака избора.
				hideMap();
			}
		} else if ( type === 'address' ) {
			$cityRow.show();
			$officeSearch.hide();
			toggleStep( $addressRows, unlocked, animate );
			hideMap();
		} else {
			$cityRow.hide();
			$officeSearch.hide();
			$addressRows.hide();
			hideMap();
		}
	}

	/* ---- City-scoped offices (map + list show ONLY the chosen city) ----
	 * Reads the persistent DB store via /office-search, filtered by exact provider
	 * city ID when available and normalized city name for legacy rows.
	 */
	function loadCityOffices( openList ) {
		var courier = $host().attr( 'data-courier' );
		var type = selectedType();
		var city = currentCity();
		if ( ! courier || ! isOfficeLike( type ) || ! city ) {
			return;
		}

		var key = cacheKey( [ 'offices', courier, type, city.id, city.name, city.post_code ] );
		if ( Object.prototype.hasOwnProperty.call( responseCache, key ) ) {
			acceptCityOffices( responseCache[ key ], openList );
			return;
		}

		// Изборът на град и фокусът в полето за офис идват един след друг — не
		// пускай втора заявка за същия град, само запомни, че вече се иска и
		// отворен списък.
		if ( pendingCityOfficesKey === key ) {
			pendingCityOfficesOpen = pendingCityOfficesOpen || true === openList;
			return;
		}
		pendingCityOfficesKey = key;
		pendingCityOfficesOpen = true === openList;

		var request = requestGate.begin( 'offices', {
			courier: courier,
			type: type,
			cityId: String( city.id ),
		} );
		setLoading( 'offices', true );
		setStatus( 'loading', t( 'loading', 'Loading…' ) );

		fetchJson(
			cfg().restUrl + courier + '/office-search?type=' + encodeURIComponent( type ) +
				'&city=' + encodeURIComponent( city.name || '' ) +
				'&city_id=' + encodeURIComponent( city.id || '' ) +
				'&postcode=' + encodeURIComponent( city.post_code || checkoutPostcode() ),
			request
		)
			.then( function ( list ) {
				var activeCity = currentCity();
				if (
					! request.isCurrent() ||
					request.context.courier !== $host().attr( 'data-courier' ) ||
					request.context.type !== selectedType() ||
					! activeCity ||
					request.context.cityId !== String( activeCity.id )
				) {
					return;
				}
				list = Array.isArray( list ) ? list : [];
				responseCache[ key ] = list;
				acceptCityOffices( list, true === openList || pendingCityOfficesOpen );
				setStatus( '', '' );
			} )
			.catch( function ( error ) {
				if ( 'AbortError' !== error.name && request.isCurrent() ) {
					setStatus( 'error', error.message || t( 'requestError', 'We could not load the data. Try again.' ) );
				}
			} )
			.finally( function () {
				if ( pendingCityOfficesKey === key ) {
					pendingCityOfficesKey = null;
					pendingCityOfficesOpen = false;
				}
				if ( request.isCurrent() ) {
					setLoading( 'offices', false );
				}
			} );
	}

	function acceptCityOffices( list, openList ) {
		cityOfficePool = Array.isArray( list ) ? list : [];
		cityOfficePool.forEach( function ( office ) {
			officeCache[ String( office.id ) ] = office;
		} );

		// A restored office/locker must still exist in the provider's current
		// location pool. If it does, replace the lightweight localStorage copy
		// with the fresh provider object and keep the confirmation visible. If
		// it no longer exists, clear the stale choice instead of silently sending
		// an obsolete location id with the order.
		if ( selectedOffice && selectedOffice.id ) {
			var currentId = String( selectedOffice.id );
			var currentMatches = cityOfficePool.filter( function ( office ) {
				return String( office.id ) === currentId;
			} );
			var currentOffice = currentMatches.length ? currentMatches[ 0 ] : null;

			if ( currentOffice ) {
				selectedOffice = currentOffice;
				$( '#bgcs3_office' ).val( currentId );
				$( '#bgcs3_office_search' ).val( currentOffice.text || currentOffice.name || '' );
				syncSelection( false );
			} else {
				selectedOffice = null;
				$( '#bgcs3_office' ).val( '' );
				$( '#bgcs3_office_search' ).val( '' );
				$host().find( '.bgcs-selected' ).hide().empty();
				$( '#bgcs3_selection' ).val( '' );
				selectionStore.clear( $host().attr( 'data-courier' ) );
			}
		}

		renderMap( cityOfficePool );
		if ( true === openList && ! selectedOffice ) {
			renderOfficeSuggestions( cityOfficePool );
		}
	}

	/* Client-side filter of the loaded city's offices. */
	function filterCityOffices( query ) {
		var q = ( query || '' ).toLowerCase().trim();
		if ( ! q ) {
			renderOfficeSuggestions( cityOfficePool );
			return;
		}
		var found = cityOfficePool.filter( function ( o ) {
			var hay = ( ( o.text || o.name || '' ) + ' ' + ( o.post_code || '' ) ).toLowerCase();
			return hay.indexOf( q ) !== -1;
		} );
		renderOfficeSuggestions( found );
	}

	/* ---- Office/locker search, always scoped to the chosen city ----
	 * Градът е задължителна първа стъпка, затова тук няма търсене по целия пул:
	 * или градските офиси още се зареждат, или ги филтрираме клиентски.
	 */
	function searchOffices( query, openList ) {
		var courier = $host().attr( 'data-courier' );
		var type = selectedType();

		if ( ! courier || ! isOfficeLike( type ) || ! currentCity() ) {
			return;
		}

		if ( ! cityOfficePool.length ) {
			loadCityOffices( !! openList );
			return;
		}

		if ( openList ) {
			filterCityOffices( query );
		}
	}

	function renderOfficeSuggestions( list ) {
		var $ul = $host().find( '#bgcs3_office_suggestions' );
		var $empty = $host().find( '.bgcs-office-empty' );
		$ul.empty();

		if ( ! list.length ) {
			$ul.attr( 'hidden', true );
			$empty.attr( 'hidden', false );
			$( '#bgcs3_office_search' ).attr( 'aria-expanded', 'false' );
			return;
		}

		$empty.attr( 'hidden', true );
		list.forEach( function ( o ) {
			officeCache[ String( o.id ) ] = o;
			var $li = $( '<li/>', {
				'class': 'bgcs-office-option',
				role: 'option',
				'data-id': String( o.id ),
				text: o.text || o.name,
			} );
			$ul.append( $li );
		} );
		$ul.attr( 'hidden', false );
		$( '#bgcs3_office_search' ).attr( 'aria-expanded', 'true' );
	}

	/* Commit an office/locker choice from the list or the map. */
	function selectOffice( office ) {
		if ( ! office || ! office.id ) {
			return;
		}
		officeCache[ String( office.id ) ] = office;
		selectedOffice = office;

		$( '#bgcs3_office' ).val( String( office.id ) );
		$( '#bgcs3_office_search' ).val( office.text || office.name || '' );
		$host().find( '#bgcs3_office_suggestions' ).empty().attr( 'hidden', true );
		$host().find( '.bgcs-office-empty' ).attr( 'hidden', true );

		syncSelection();
	}

	/* ---- Build + persist selection ---- */
	function buildSelection() {
		var courier = $host().attr( 'data-courier' );
		var type = selectedType();
		var office = officeCache[ String( $( '#bgcs3_office' ).val() ) ] || selectedOffice || null;
		var city = currentCity();

		return {
			courier: courier,
			delivery_type: type,
			country: 'BG',
			revision: revisionClock.next(),
			city: city ? { id: city.id, name: city.name, post_code: city.post_code } : null,
			office: isOfficeLike( type ) && office ? { id: office.id, text: office.text || office.name } : null,
			address: type === 'address' ? {
				street: $( '#bgcs3_address_street' ).val(),
				num: $( '#bgcs3_address_num' ).val(),
				note: $( '#bgcs3_address_note' ).val(),
			} : null,
		};
	}

	function isComplete( s ) {
		if ( ! s.courier || ! s.delivery_type ) {
			return false;
		}
		if ( isOfficeLike( s.delivery_type ) ) {
			return !! ( s.office && s.office.id );
		}
		if ( s.delivery_type === 'address' ) {
			return !! ( s.city && s.city.id && s.address && s.address.street );
		}
		return false;
	}

	function showSummary( s ) {
		var $sum = $host().find( '.bgcs-selected' );
		var value = '';

		if ( s.office && s.office.text ) {
			value = s.office.text;
		} else if ( s.delivery_type === 'address' && s.city ) {
			value = ( s.city.name || '' ) + ', ' + ( s.address.street || '' ) + ' ' + ( s.address.num || '' );
		}

		if ( value ) {
			$sum
				.empty()
				.append( document.createTextNode( t( 'selected', 'Selected:' ) + ' ' ) )
				.append( $( '<strong/>' ).text( value ) )
				.show();
		} else {
			$sum.hide();
		}
	}

	function syncSelection( forceRefresh ) {
		var s = buildSelection();
		syncNativeWooAddress( s.city );
		$( '#bgcs3_selection' ).val( JSON.stringify( s ) );
		showSummary( s );

		selectionStore.save( s );

		if ( false === forceRefresh ) {
			return;
		}
		if ( ! isComplete( s ) && true !== forceRefresh ) {
			return;
		}
		updateScheduler.request();
	}

	/**
	 * Apply a selection produced by a courier add-on (e.g. a map widget).
	 *
	 * @param {Object} s              Normalized selection.
	 * @param {Object} [opts]         Options.
	 * @param {boolean} [opts.silent] Skip the WooCommerce checkout refresh.
	 */
	function setSelection( s, opts ) {
		opts = opts || {};
		s.revision = revisionClock.next( s && s.revision );
		syncNativeWooAddress( s && s.city ? s.city : null );
		$( '#bgcs3_selection' ).val( JSON.stringify( s ) );
		showSummary( s );

		selectionStore.save( s );

		if ( opts.silent ) {
			return;
		}
		updateScheduler.request();
	}

	/* ---- Restore previous choice ---- */
	function restore( courier ) {
		var saved = selectionStore.load( courier );
		if ( ! saved ) {
			return;
		}

		// A courier add-on may own the restore flow (e.g. widget-only pickers that
		// have no city/office dropdowns to repopulate).
		if ( true === courierHook( courier, 'restore', saved ) ) {
			return;
		}

		if ( saved.delivery_type ) {
			$( '#bgcs3_dt_' + saved.delivery_type ).prop( 'checked', true );
		}

		// Restore the chosen city (drives the map filter for office/locker too).
		if ( saved.city && saved.city.id ) {
			cityCache[ String( saved.city.id ) ] = saved.city;
			$( '#bgcs3_city' ).val( String( saved.city.id ) );
			$( '#bgcs3_city_search' ).val( saved.city.name || '' );
			syncNativeWooAddress( saved.city );
		}

		// Office / locker: re-show the previously picked office directly.
		if ( isOfficeLike( saved.delivery_type ) && saved.office && saved.office.id ) {
			var restored = {
				id: saved.office.id,
				text: saved.office.text,
				name: saved.office.text,
				post_code: saved.city ? saved.city.post_code : '',
				city: saved.city ? saved.city.name : '',
			};
			officeCache[ String( restored.id ) ] = restored;
			selectedOffice = restored;
			$( '#bgcs3_office' ).val( String( restored.id ) );
			$( '#bgcs3_office_search' ).val( restored.text || '' );
		}

		// Address: restore the street fields.
		if ( saved.delivery_type === 'address' && saved.address ) {
			$( '#bgcs3_address_street' ).val( saved.address.street || '' );
			$( '#bgcs3_address_num' ).val( saved.address.num || '' );
			$( '#bgcs3_address_note' ).val( saved.address.note || '' );
		}

		// Rehydrate the complete checkout contract, not only the visible inputs.
		// Before 3.0.20 the city/office fields were restored after a reload but
		// the hidden selection and the green "Selected" confirmation stayed
		// empty until the customer interacted with the picker again. Keeping the
		// hidden JSON and summary in sync also makes a restored choice safe to
		// submit without forcing a redundant second selection.
		setSelection( saved, { silent: true } );
	}

	/* ---- Allowed types + active state ---- */
	function applyTypes( types ) {
		var $h = $host();
		types = ( types || [] ).filter( function ( ty ) {
			return ty === 'office' || ty === 'locker' || ty === 'address';
		} );

		$h.find( '.bgcs-type' ).hide();
		types.forEach( function ( ty ) {
			$h.find( '.bgcs-type[data-type="' + ty + '"]' ).css( 'display', '' );
		} );

		// A single delivery type needs no switcher — hide the whole row.
		$h.find( '.bgcs-types' ).toggle( types.length > 1 );

		var current = selectedType();
		if ( ! current || types.indexOf( current ) === -1 ) {
			$h.find( 'input[name="bgcs3_delivery_type"]' ).prop( 'checked', false );
			if ( types.length ) {
				$( '#bgcs3_dt_' + types[ 0 ] ).prop( 'checked', true );
			}
		}
		updateTypeActive();
	}

	function updateTypeActive() {
		$host().find( '.bgcs-type' ).removeClass( 'is-active' );
		$host().find( 'input[name="bgcs3_delivery_type"]:checked' ).closest( '.bgcs-type' ).addClass( 'is-active' );
	}

	/* ---- Pair chosen shipping method with the selector ---- */
	function sync() {
		syncCleanCheckoutFields();
		var $h = $host();
		if ( ! $h.length ) {
			return;
		}
		var chosenRateId = getChosenRateId();
		var meta = getRateMeta( chosenRateId );
		if ( ! meta || ! meta.courier ) {
			if ( chosenRateId ) {
				courierTransitions.observe( '', [] );
			}
			$h.hide();
			return;
		}
		$h.show();
		var isCourierTransition = courierTransitions.observe( meta.courier, meta.types );

		if ( $h.attr( 'data-courier' ) !== meta.courier ) {
			$h.attr( 'data-courier', meta.courier );
			// Full reset first: the previous courier's offices, markers and
			// summary must not survive the switch.
			resetPicker();
			$h.find( '.bgcs-selected' ).hide().empty();
			applyTypes( meta.types );
			if ( isCourierTransition ) {
				var resetSelection = stateApi.createIncompleteSelection( meta.courier, selectedType() );
				setSelection( resetSelection, { silent: true } );
				updateScheduler.request();
			} else {
				restore( meta.courier );
			}
			loadOfficesOrAddress();
		} else {
			applyTypes( meta.types );
			courierHook( meta.courier, 'onSync' );
		}
	}

	/* ---- Events ---- */
	$( document ).on( 'change', 'input[name="bgcs3_delivery_type"]', function () {
		updateTypeActive();
		resetPicker();
		loadOfficesOrAddress( true );
		syncSelection( true );
	} );

	// City search box (debounced) + picking a city suggestion. Choosing a city
	// scopes the map + office list to it and opens the next step.
	$( document ).on( 'input', '#bgcs3_city_search', function () {
		var q = this.value;
		var hadCity = !! $( '#bgcs3_city' ).val();
		$( '#bgcs3_city' ).val( '' );

		// Редакция на потвърден град => следващата стъпка се затваря.
		if ( hadCity ) {
			clearAfterCity();
		}

		window.clearTimeout( citySearchTimer );
		citySearchTimer = window.setTimeout( function () {
			searchCities( q );
		}, 250 );
	} );
	$( document ).on( 'mousedown', '.bgcs-city-suggestions .bgcs-city-option', function ( e ) {
		e.preventDefault();
		var city = cityCache[ String( $( this ).attr( 'data-id' ) ) ];
		if ( city ) {
			selectCity( city );
		}
	} );
	$( document ).on( 'blur', '#bgcs3_city_search', function () {
		var $cityUl = $host().find( '.bgcs-city-suggestions' );
		window.setTimeout( function () {
			$cityUl.attr( 'hidden', true );
		}, 200 );
	} );

	// Office / locker search box (debounced) + picking a suggestion.
	$( document ).on( 'input', '#bgcs3_office_search', function () {
		var q = this.value;
		selectedOffice = null;
		$( '#bgcs3_office' ).val( '' );
		window.clearTimeout( officeSearchTimer );
		officeSearchTimer = window.setTimeout( function () {
			searchOffices( q, true );
		}, 250 );
	} );
	$( document ).on( 'focus', '#bgcs3_office_search', function () {
		searchOffices( this.value, true );
	} );
	$( document ).on( 'mousedown', '#bgcs3_office_suggestions .bgcs-office-option', function ( e ) {
		e.preventDefault();
		var office = officeCache[ String( $( this ).attr( 'data-id' ) ) ];
		if ( office ) {
			selectOffice( office );
		}
	} );
	$( document ).on( 'blur', '#bgcs3_office_search', function () {
		var $ul = $( '#bgcs3_office_suggestions' );
		window.setTimeout( function () {
			$ul.attr( 'hidden', true );
		}, 200 );
	} );

	$( document ).on( 'input', '#bgcs3_address_street', function () {
		var query = this.value;
		window.clearTimeout( streetSearchTimer );
		streetSearchTimer = window.setTimeout( function () {
			searchStreets( query );
		}, 250 );
	} );
	$( document ).on( 'mousedown', '.bgcs-street-suggestions .bgcs-street-option', function ( event ) {
		event.preventDefault();
		var street = streetCache[ String( $( this ).attr( 'data-key' ) ) ];
		if ( street ) {
			selectStreet( street );
		}
	} );
	$( document ).on( 'blur', '#bgcs3_address_street', function () {
		var $streets = $host().find( '.bgcs-street-suggestions' );
		window.setTimeout( function () {
			$streets.attr( 'hidden', true );
		}, 200 );
	} );

	// Rule: the street field has its OWN debounced `input` handler above
	// (searchStreets, 250ms) that opens a clickable suggestion list — it must
	// never also schedule a real WooCommerce `update_checkout` while that list
	// is open. `isComplete()` treats any non-empty street text as a complete
	// address (free-text entry is a supported fallback when a street isn't in
	// the location provider), so previously EVERY keystroke re-armed
	// `addressRefresh()` (450ms) into a real `update_checkout` — WooCommerce's
	// own full-form blockUI overlay then covered the still-open suggestion
	// list and swallowed the click, so the customer could never actually
	// select a street. Office/locker never hit this: their `input` handler
	// only searches, and the real refresh fires exclusively from a discrete
	// list-click commit (`selectOffice()`) — street now follows the same
	// pattern via `selectStreet()`, which already calls `syncSelection()`
	// directly. `num`/`note` are plain fields with no suggestion list to
	// cover, so their existing debounced-refresh-while-typing stays.
	$( document ).on( 'input', '#bgcs3_address_street', function () {
		syncSelection( false );
	} );
	$( document ).on( 'input', '#bgcs3_address_num, #bgcs3_address_note', function () {
		syncSelection( false );
		addressRefresh();
	} );
	$( document ).on( 'change', '#bgcs3_address_street, #bgcs3_address_num, #bgcs3_address_note', function () {
		addressRefresh.cancel();
		syncSelection();
	} );

	$( document.body ).on( 'update_checkout', function () {
		checkoutUpdateEpoch++;
		checkoutUpdateStartedAt = Date.now();
		updateScheduler.started();
		$host().addClass( 'is-updating' );
	} );
	$( document.body ).on( 'updated_checkout', function () {
		$host().removeClass( 'is-updating' );
		updateScheduler.finished();
		sync();
	} );
	$( document.body ).on( 'checkout_error', function () {
		$host().removeClass( 'is-updating' );
		updateScheduler.finished();
	} );
	$( document.body ).on( 'bgcs3_map_moved', function () {
		if ( map ) {
			window.setTimeout( function () {
				map.invalidateSize();
			}, 0 );
		}
	} );
	$( document.body ).on( 'change', 'input[name^="shipping_method"]', function () {
		sync();
		ensureExternalShippingRefresh( this );
	} );
	$( document.body ).on( 'change', 'input[name="payment_method"]', function () {
		if ( selectedShippingRateIds().some( isBgcsRateId ) ) {
			updateScheduler.request();
		}
	} );
	$( function () {
		sync();
	} );

	/* ---- Public API for courier add-ons ---- */
	window.bgcsCourier = window.bgcsCourier || {};
	window.bgcsCourier.handlers = window.bgcsCourier.handlers || {};

	/**
	 * Register handlers for a courier id.
	 *
	 * @param {string} id       Courier id.
	 * @param {Object} handlers renderPicker(ctx) / restore(saved) / onSync().
	 */
	window.bgcsCourier.register = function ( id, handlers ) {
		window.bgcsCourier.handlers[ id ] = handlers || {};
	};

	window.bgcsCourier.api = {
		host: $host,
		cfg: cfg,
		t: t,
		setSelection: setSelection,
		showSummary: showSummary,
		restore: restore,
		triggerUpdate: triggerUpdate,
		hideMap: hideMap,
	};
} )( window.jQuery );
