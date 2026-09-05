/**
 * BGCS-AUDIT-002 / TASK-F1 — does `checkout.remember_selection` actually stop
 * the checkout selector writing to the customer's browser?
 *
 * The finding was that the merchant-facing switch changed nothing:
 * `createSelectionStore()` was handed `window.localStorage` unconditionally, so
 * a shop owner who turned "remember last address" off still had every customer
 * selection written to localStorage. Asserting the setting reaches the payload
 * is not enough — this loads the REAL checkout script and watches the storage.
 *
 * Run: node tests/lib/checkout-storage-probe.js
 */

'use strict';

const fs = require( 'fs' );
const path = require( 'path' );
const vm = require( 'vm' );

const assetsDir = path.join( __dirname, '..', '..', 'assets', 'js' );

let failures = 0;
function check( condition, message ) {
	console.log( ( condition ? '  [PASS] ' : '  [FAIL] ' ) + message );
	if ( ! condition ) {
		failures++;
	}
}

/** Records every write and removal so the test can assert on them. */
function createRecordingStorage( seed ) {
	const data = Object.assign( {}, seed || {} );
	const writes = [];
	const removals = [];

	return {
		writes,
		removals,
		get length() {
			return Object.keys( data ).length;
		},
		key( index ) {
			return Object.keys( data )[ index ] || null;
		},
		getItem( name ) {
			return Object.prototype.hasOwnProperty.call( data, name ) ? data[ name ] : null;
		},
		setItem( name, value ) {
			writes.push( name );
			data[ name ] = String( value );
		},
		removeItem( name ) {
			removals.push( name );
			delete data[ name ];
		},
		snapshot() {
			return Object.keys( data );
		},
	};
}

/** Minimal chainable jQuery stand-in: every call returns something callable. */
function createJqueryStub() {
	const chain = new Proxy( function () {}, {
		get() {
			return () => chain;
		},
		apply() {
			return chain;
		},
	} );

	const $ = function () {
		return chain;
	};
	$.fn = {};
	$.each = function () {};
	$.extend = Object.assign;
	$.ajax = function () {
		return chain;
	};
	return $;
}

/**
 * Boots the real checkout scripts with the given config and storage.
 *
 * @param {Object} config  window.bgcsCheckout payload.
 * @param {Object} storage Recording storage double.
 * @return {Object} The sandbox, after both scripts have run.
 */
function boot( config, storage ) {
	const sandbox = {
		console,
		setTimeout,
		clearTimeout,
		JSON,
		Date,
		Math,
	};

	sandbox.window = sandbox;
	sandbox.globalThis = sandbox;
	sandbox.self = sandbox;
	sandbox.localStorage = storage;
	sandbox.bgcsCheckout = config;
	sandbox.jQuery = createJqueryStub();
	sandbox.document = { body: {}, createElement: () => ( {} ), addEventListener: () => {} };

	vm.createContext( sandbox );

	for ( const file of [ 'bgcs-checkout-state.js', 'bgcs-checkout.js' ] ) {
		vm.runInContext( fs.readFileSync( path.join( assetsDir, file ), 'utf8' ), sandbox, { filename: file } );
	}

	return sandbox;
}

const selection = { courier: 'econt', delivery_type: 'office', office: { id: '7042' } };

console.log( '--- The store itself honours "no storage" ---' );
{
	const storage = createRecordingStorage();
	const state = require( path.join( assetsDir, 'bgcs-checkout-state.js' ) );

	const enabled = state.createSelectionStore( storage, 'bgcs3_selection_' );
	enabled.save( selection );
	check( 1 === storage.writes.length, 'With storage, a selection is written' );
	check( null !== enabled.load( 'econt' ), 'and read back' );

	const disabled = state.createSelectionStore( null, 'bgcs3_selection_' );
	const before = storage.writes.length;
	check( false === disabled.save( selection ), 'With no storage, save() reports it did not store' );
	check( null === disabled.load( 'econt' ), 'load() returns nothing' );
	disabled.clear( 'econt' );
	check( before === storage.writes.length, 'and nothing reached the browser' );
}

console.log( '--- The real checkout script, remember_selection ON ---' );
{
	const storage = createRecordingStorage();
	const sandbox = boot( { rememberSelection: true, couriers: {} }, storage );

	// `setSelection()` is what runs when the customer picks an office; it is the
	// call that reaches selectionStore.save() inside the module.
	sandbox.window.bgcsCourier.api.setSelection( selection, { silent: true } );
	check( storage.writes.includes( 'bgcs3_selection_econt' ), 'Choosing an office is remembered, as the merchant asked' );
}

console.log( '--- The real checkout script, remember_selection OFF ---' );
{
	const storage = createRecordingStorage( {
		// Written while the setting was still on.
		bgcs3_selection_econt: JSON.stringify( selection ),
		unrelated_key: 'kept',
	} );

	const sandbox = boot( { rememberSelection: false, couriers: {} }, storage );

	// This is the defect itself: the store the module built must be inert, not
	// merely ignored. Before the fix, this call wrote to the customer's browser.
	sandbox.window.bgcsCourier.api.setSelection( selection, { silent: true } );
	check( 0 === storage.writes.length, 'Choosing an office writes nothing to the browser' );

	check(
		storage.removals.includes( 'bgcs3_selection_econt' ),
		'A selection stored while the setting was on is cleared out'
	);
	check(
		storage.snapshot().includes( 'unrelated_key' ),
		'and nothing that is not ours is touched'
	);
}

console.log( '--- A missing flag keeps the historical behaviour ---' );
{
	// An older cached asset, or a filtered payload, must not silently disable
	// persistence: only an explicit `false` turns it off.
	const storage = createRecordingStorage( { bgcs3_selection_econt: JSON.stringify( selection ) } );
	boot( { couriers: {} }, storage );
	check( 0 === storage.removals.length, 'An absent rememberSelection flag leaves stored selections alone' );
}

console.log( '' );
if ( failures > 0 ) {
	console.log( 'FAILED: ' + failures + ' check(s)' );
	process.exit( 1 );
}
console.log( 'OK — all checkout storage checks passed' );
