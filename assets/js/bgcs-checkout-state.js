/**
 * BG Commerce Suite checkout request/update state.
 *
 * Small dependency-free helpers shared by the classic checkout selector. The
 * CommonJS export exists only for the local Node regression tests.
 */
( function ( root, factory ) {
	'use strict';

	var api = factory();

	if ( typeof module === 'object' && module.exports ) {
		module.exports = api;
	}

	if ( root ) {
		root.BgcsCheckoutState = api;
	}
} )( typeof window !== 'undefined' ? window : globalThis, function () {
	'use strict';

	/**
	 * Keep one active AbortController per request channel.
	 *
	 * @return {Object} Request gate.
	 */
	function createRequestGate() {
		var channels = {};
		var sequence = 0;

		return {
			begin: function ( channel, context ) {
				if ( channels[ channel ] ) {
					channels[ channel ].controller.abort();
				}

				var id = ++sequence;
				var controller = new AbortController();
				var entry = {
					id: id,
					controller: controller,
				};
				channels[ channel ] = entry;

				return {
					id: id,
					context: context || {},
					signal: controller.signal,
					isCurrent: function () {
						return channels[ channel ] === entry && ! controller.signal.aborted;
					},
				};
			},
			abort: function ( channel ) {
				if ( channels[ channel ] ) {
					channels[ channel ].controller.abort();
					delete channels[ channel ];
				}
			},
			abortAll: function () {
				Object.keys( channels ).forEach( function ( channel ) {
					channels[ channel ].controller.abort();
				} );
				channels = {};
			},
		};
	}

	/**
	 * Collapse repeated refresh requests while WooCommerce is already updating.
	 *
	 * @param {Function} trigger Native WooCommerce update trigger.
	 * @return {Object} Update scheduler.
	 */
	function createUpdateScheduler( trigger ) {
		var busy = false;
		var scheduled = false;
		var pending = false;

		function run() {
			scheduled = true;
			trigger();
		}

		return {
			request: function () {
				if ( busy || scheduled ) {
					pending = true;
					return;
				}
				run();
			},
			started: function () {
				scheduled = false;
				busy = true;
			},
			finished: function () {
				busy = false;
				scheduled = false;
				if ( pending ) {
					pending = false;
					run();
				}
			},
			reset: function () {
				busy = false;
				scheduled = false;
				pending = false;
			},
		};
	}

	/**
	 * Monotonic epoch-microsecond clock. Values remain below Number.MAX_SAFE_INTEGER
	 * and can be compared directly by the PHP session store.
	 *
	 * @param {number} [initial] Deterministic initial value for tests/restores.
	 * @return {Object} Revision clock.
	 */
	function createRevisionClock( initial ) {
		var useEpoch = 'undefined' === typeof initial;
		var current = Number.isFinite( Number( initial ) ) && ! useEpoch
			? Math.max( 0, Math.floor( Number( initial ) ) )
			: Date.now() * 1000;

		return {
			next: function ( observed ) {
				var floor = Number.isFinite( Number( observed ) ) ? Math.floor( Number( observed ) ) : 0;
				current = Math.max( current + 1, floor + 1, useEpoch ? Date.now() * 1000 : 0 );
				return current;
			},
		};
	}

	/**
	 * Serialize selection writes and stamp each one in user-action order.
	 *
	 * @param {Function} submitter Actual async request function.
	 * @param {Object}   [clock]   Revision clock.
	 * @return {Object} Update queue.
	 */
	function createSelectionUpdateQueue( submitter, clock ) {
		clock = clock || createRevisionClock();
		var tail = null;

		return {
			submit: function ( payload ) {
				payload = payload || {};
				payload.revision = clock.next( payload.revision );

				var task;
				if ( null === tail ) {
					try {
						task = Promise.resolve( submitter( payload ) );
					} catch ( error ) {
						task = Promise.reject( error );
					}
				} else {
					task = tail.then( function () {
						return submitter( payload );
					} );
				}

				tail = task.catch( function () {} );
				return task;
			},
		};
	}

	/**
	 * Build the only safe state immediately after an interactive courier switch.
	 * City, office and address identifiers belong to the previous provider.
	 *
	 * @param {string} courier     New courier id.
	 * @param {string} deliveryType First supported delivery type.
	 * @return {Object} Incomplete canonical selection.
	 */
	function createIncompleteSelection( courier, deliveryType ) {
		var allowed = [ 'office', 'locker', 'address' ];
		deliveryType = allowed.indexOf( deliveryType ) === -1 ? 'office' : deliveryType;

		return {
			courier: String( courier || '' ).replace( /[^a-z0-9_-]/gi, '' ),
			delivery_type: deliveryType,
			country: 'BG',
			city: null,
			office: null,
			address: null,
			extras: [],
		};
	}

	/**
	 * Distinguish initial restore from an interactive shipping-method change.
	 * Empty courier is meaningful after an external WooCommerce method is seen.
	 *
	 * @param {Function} [onTransition] Receives the incomplete selection.
	 * @return {Object} Transition observer.
	 */
	function createCourierTransitionObserver( onTransition ) {
		var initialized = false;
		var currentCourier = '';

		return {
			observe: function ( courier, deliveryTypes ) {
				courier = String( courier || '' ).replace( /[^a-z0-9_-]/gi, '' );
				deliveryTypes = Array.isArray( deliveryTypes ) ? deliveryTypes : [];

				if ( ! initialized ) {
					initialized = true;
					currentCourier = courier;
					return false;
				}

				if ( currentCourier === courier ) {
					return false;
				}

				currentCourier = courier;
				if ( ! courier ) {
					return false;
				}

				var deliveryType = deliveryTypes.filter( function ( type ) {
					return [ 'office', 'locker', 'address' ].indexOf( type ) !== -1;
				} )[ 0 ] || 'office';
				var selection = createIncompleteSelection( courier, deliveryType );
				if ( 'function' === typeof onTransition ) {
					onTransition( selection );
				}
				return true;
			},
		};
	}

	/**
	 * Find the selected BGCS rate across all Store API shipping packages.
	 * An unrelated package may appear first and must not hide the BGCS package.
	 *
	 * @param {Array} packages Store API shipping packages.
	 * @return {Object|null} Selected courier context, external-only context or null.
	 */
	function selectedBgcsRateContext( packages ) {
		packages = Array.isArray( packages ) ? packages : [];
		var hasSelectedRate = false;
		var selectedBgcsRate = null;

		packages.some( function ( packageRates ) {
			var rates = packageRates && Array.isArray( packageRates.shipping_rates ) ? packageRates.shipping_rates : [];
			return rates.some( function ( rate ) {
				if ( ! rate || ! rate.selected ) {
					return false;
				}

				hasSelectedRate = true;
				var rateId = String( rate.rate_id || '' );
				if ( 0 !== rateId.indexOf( 'bgcs3_' ) ) {
					return false;
				}

				selectedBgcsRate = rate;
				return true;
			} );
		} );

		if ( ! selectedBgcsRate ) {
			return hasSelectedRate ? { courier: '', deliveryTypes: [] } : null;
		}

		var meta = {};
		( selectedBgcsRate.meta_data || [] ).forEach( function ( row ) {
			if ( row && row.key ) {
				meta[ row.key ] = row.value;
			}
		} );
		var rateId = String( selectedBgcsRate.rate_id || '' );
		var courier = meta.courier || meta._bgcs3_courier || rateId.replace( 'bgcs3_', '' ).split( ':' )[ 0 ];
		var rawTypes = meta.delivery_types || meta._bgcs3_delivery_types || 'office';

		return {
			courier: courier,
			deliveryTypes: String( rawTypes ).split( ',' ).map( function ( type ) {
				return type.trim();
			} ).filter( Boolean ),
		};
	}

	/**
	 * Trailing debounce.
	 *
	 * @param {Function} callback Callback.
	 * @param {number}   delay    Delay in milliseconds.
	 * @return {Function} Debounced callback.
	 */
	function debounce( callback, delay ) {
		var timer = null;
		var timers = typeof window !== 'undefined' ? window : globalThis;

		function debounced() {
			var args = arguments;
			var context = this;
			timers.clearTimeout( timer );
			timer = timers.setTimeout( function () {
				callback.apply( context, args );
			}, delay );
		}

		debounced.cancel = function () {
			timers.clearTimeout( timer );
			timer = null;
		};

		return debounced;
	}

	/**
	 * Store one checkout selection per courier without coupling the state helper
	 * to window.localStorage.
	 *
	 * @param {Object} storage Web Storage-compatible object.
	 * @param {string} prefix  Storage-key prefix.
	 * @return {Object} Selection store.
	 */
	function createSelectionStore( storage, prefix ) {
		prefix = prefix || 'bgcs3_selection_';

		function key( courier ) {
			return prefix + String( courier || '' ).replace( /[^a-z0-9_-]/gi, '' );
		}

		return {
			save: function ( selection ) {
				if ( ! storage || ! selection || ! selection.courier ) {
					return false;
				}
				try {
					storage.setItem( key( selection.courier ), JSON.stringify( selection ) );
					return true;
				} catch ( error ) {
					return false;
				}
			},
			load: function ( courier ) {
				if ( ! storage || ! courier ) {
					return null;
				}
				try {
					var value = JSON.parse( storage.getItem( key( courier ) ) || 'null' );
					return value && value.courier === courier ? value : null;
				} catch ( error ) {
					try {
						storage.removeItem( key( courier ) );
					} catch ( removeError ) {}
					return null;
				}
			},
			clear: function ( courier ) {
				if ( ! storage || ! courier ) {
					return;
				}
				try {
					storage.removeItem( key( courier ) );
				} catch ( error ) {}
			},
		};
	}

	return {
		createRequestGate: createRequestGate,
		createUpdateScheduler: createUpdateScheduler,
		createRevisionClock: createRevisionClock,
		createSelectionUpdateQueue: createSelectionUpdateQueue,
		createIncompleteSelection: createIncompleteSelection,
		createCourierTransitionObserver: createCourierTransitionObserver,
		selectedBgcsRateContext: selectedBgcsRateContext,
		createSelectionStore: createSelectionStore,
		debounce: debounce,
	};
} );
