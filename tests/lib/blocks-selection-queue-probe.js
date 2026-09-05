'use strict';

const assert = require( 'assert' );
const state = require( '../../assets/js/bgcs-checkout-state.js' );

async function run() {
	const mixedPackages = [
		{
			shipping_rates: [
				{ rate_id: 'flat_rate:8', selected: true, meta_data: [] },
			],
		},
		{
			shipping_rates: [
				{
					rate_id: 'bgcs3_econt:14',
					selected: true,
					meta_data: [
						{ key: '_bgcs3_courier', value: 'econt' },
						{ key: '_bgcs3_delivery_types', value: 'office,address' },
					],
				},
			],
		},
	];
	assert.deepStrictEqual( state.selectedBgcsRateContext( mixedPackages ), {
		courier: 'econt',
		deliveryTypes: [ 'office', 'address' ],
	}, 'an external first package cannot hide the selected BGCS package' );
	assert.deepStrictEqual( state.selectedBgcsRateContext( [ mixedPackages[ 0 ] ] ), {
		courier: '',
		deliveryTypes: [],
	}, 'an external-only selection still leaves the BGCS transition context' );
	assert.strictEqual( state.selectedBgcsRateContext( [] ), null, 'packages without a selected rate have no transition context' );

	const resets = [];
	const transitions = state.createCourierTransitionObserver( ( selection ) => {
		resets.push( selection );
	} );

	assert.strictEqual( transitions.observe( 'speedy', [ 'office', 'address' ] ), false, 'initial courier may restore its saved selection' );
	assert.strictEqual( transitions.observe( 'speedy', [ 'office', 'address' ] ), false, 'same courier is not a transition' );
	assert.strictEqual( transitions.observe( 'econt', [ 'office', 'address' ] ), true, 'interactive courier switch emits a reset' );
	assert.deepStrictEqual( resets[ 0 ], {
		courier: 'econt',
		delivery_type: 'office',
		country: 'BG',
		city: null,
		office: null,
		address: null,
		extras: [],
	}, 'the reset cannot carry provider-specific destination data' );
	assert.strictEqual( transitions.observe( '', [] ), false, 'leaving BGCS records the external selection without emitting a BGCS update' );
	assert.strictEqual( transitions.observe( 'boxnow', [ 'locker' ] ), true, 'returning from an external method is a fresh courier transition' );
	assert.strictEqual( resets[ 1 ].delivery_type, 'locker', 'the reset uses the new courier\'s first supported type' );

	const revisions = state.createRevisionClock( 100 );
	assert.strictEqual( revisions.next(), 101 );
	assert.strictEqual( revisions.next(), 102 );

	const calls = [];
	const pending = [];
	const queue = state.createSelectionUpdateQueue( ( payload ) => {
		calls.push( payload.revision );
		return new Promise( ( resolve ) => pending.push( resolve ) );
	}, state.createRevisionClock( 200 ) );

	const first = queue.submit( { courier: 'speedy' } );
	const second = queue.submit( { courier: 'econt' } );
	const third = queue.submit( { courier: 'boxnow' } );

	assert.deepStrictEqual( calls, [ 201 ], 'only the first request starts immediately' );
	pending.shift()( 'speedy-done' );
	await new Promise( ( resolve ) => setImmediate( resolve ) );
	assert.deepStrictEqual( calls, [ 201, 202 ], 'the second request starts after the first settles' );
	pending.shift()( 'econt-done' );
	await new Promise( ( resolve ) => setImmediate( resolve ) );
	assert.deepStrictEqual( calls, [ 201, 202, 203 ], 'the third request preserves user-action order' );
	pending.shift()( 'boxnow-done' );

	assert.strictEqual( await first, 'speedy-done' );
	assert.strictEqual( await second, 'econt-done' );
	assert.strictEqual( await third, 'boxnow-done' );

	console.log( '[PASS] Courier transitions reset destination state and Blocks updates remain serialized' );
}

run().catch( ( error ) => {
	console.error( '[FAIL]', error && error.stack ? error.stack : error );
	process.exit( 1 );
} );
