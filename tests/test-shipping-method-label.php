<?php
/**
 * Offline regression checks for the courier method label presentation boundary.
 *
 * The `woocommerce_cart_shipping_method_full_label` filter has no single
 * consumer: classic templates print the result as HTML, while Store API driven
 * surfaces transport it as text and display any tag source literally. Markup
 * placed here reached customers as a visible `<span …>` in the Cart block, so
 * the contract is that this filter yields plain text.
 *
 * @package BgCommerce3
 */

define( 'ABSPATH', __DIR__ );
define( 'BGCS3_PATH', dirname( __DIR__ ) . DIRECTORY_SEPARATOR );

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $value ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $text ) {
		return strip_tags( (string) $text );
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = null ) {
		unset( $domain );
		$bg = array(
			'Awaiting calculation' => 'Очаква изчисляване',
			'Free shipping'        => 'Безплатна доставка',
		);
		return isset( $bg[ $text ] ) ? $bg[ $text ] : $text;
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = null ) {
		return __( $text, $domain );
	}
}

if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( $text ) {
		return (string) $text;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {
		unset( $hook, $callback, $priority, $args );
		return true;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
		return add_filter( $hook, $callback, $priority, $args );
	}
}

require_once BGCS3_PATH . 'app/Shipping/Hooks.php';

final class BGCS_Label_Test_Rate {
	private $id;
	private $label;
	private $cost;
	private $meta;

	public function __construct( $id, $label, $cost, array $meta ) {
		$this->id    = $id;
		$this->label = $label;
		$this->cost  = $cost;
		$this->meta  = $meta;
	}

	public function get_id() {
		return $this->id;
	}

	public function get_label() {
		return $this->label;
	}

	public function get_cost() {
		return $this->cost;
	}

	public function get_meta_data() {
		return $this->meta;
	}

	// Deliberately no get_data(): WC_Shipping_Rate has no such method. A fixture
	// that offered one let the production code read a label it can never reach
	// on a real rate, and hid the defect this file now pins.
}

$failures = 0;

function bgcs_label_check( $name, $expected, $actual ) {
	global $failures;
	if ( $expected === $actual ) {
		printf( "  [PASS] %s\n", $name );
		return;
	}
	++$failures;
	printf( "  [FAIL] %s\n    expected: %s\n    actual:   %s\n", $name, $expected, $actual );
}

$filter = array( '\BgCommerce3\Shipping\Hooks', 'pending_shipping_method_label' );

$free_rate = new BGCS_Label_Test_Rate(
	'bgcs3_speedy:14',
	'Доставка със Спиди',
	0.0,
	array(
		'_bgcs3_price_state'   => 'free',
		'_bgcs3_validated'     => true,
		'_bgcs3_free_shipping' => true,
		'_bgcs3_method_title'  => 'Доставка със Спиди',
	)
);

$pending_rate = new BGCS_Label_Test_Rate(
	'bgcs3_speedy:14',
	'Доставка със Спиди',
	0.0,
	array(
		'_bgcs3_price_state'  => 'pending',
		'_bgcs3_validated'    => false,
		'_bgcs3_method_title' => 'Доставка със Спиди',
	)
);

// Free transport that still charges a payment-service surcharge is a real
// positive cost and must keep whatever WooCommerce formatted.
$surcharged_rate = new BGCS_Label_Test_Rate(
	'bgcs3_speedy:14',
	'Доставка със Спиди',
	3.5,
	array(
		'_bgcs3_price_state'   => 'free',
		'_bgcs3_validated'     => true,
		'_bgcs3_free_shipping' => true,
		'_bgcs3_method_title'  => 'Доставка със Спиди',
	)
);

$foreign_rate = new BGCS_Label_Test_Rate( 'flat_rate:3', 'Flat rate', 0.0, array() );

echo "Courier method label contract\n";

// The courier name is identity, not a status line. The semantic state belongs
// in the price slot, which both surfaces already own: the classic cart total
// filter and the Cart/Checkout block JS. Announcing it in the name as well
// produced "Доставка със Спиди: Безплатна доставка" and said the same thing
// twice in one row.
bgcs_label_check(
	'A free rate keeps the courier name untouched',
	'Доставка със Спиди',
	call_user_func( $filter, 'Доставка със Спиди', $free_rate )
);

bgcs_label_check(
	'A pending rate keeps the courier name untouched',
	'Доставка със Спиди',
	call_user_func( $filter, 'Доставка със Спиди', $pending_rate )
);

// Genuinely free transport is a true zero. Whatever WooCommerce or the theme
// worded for it is correct and is none of this filter's business.
bgcs_label_check(
	'A true free claim in the incoming label survives',
	'Доставка със Спиди: Безплатна доставка',
	call_user_func( $filter, 'Доставка със Спиди: Безплатна доставка', $free_rate )
);

// The one thing only this filter can do: a provisional zero is not free, so a
// theme's "free" wording for it is false and must not reach the customer.
bgcs_label_check(
	'A false free claim on a pending rate is dropped',
	'Доставка със Спиди',
	call_user_func( $filter, 'Доставка със Спиди: Безплатна доставка', $pending_rate )
);

bgcs_label_check(
	'Markup around a false free claim is dropped with it',
	'Доставка със Спиди',
	call_user_func(
		$filter,
		'Доставка със Спиди: <span class="woocommerce-Price-amount">Безплатна доставка</span>',
		$pending_rate
	)
);

$emitted = call_user_func( $filter, 'Доставка със Спиди: <span>Безплатно</span>', $pending_rate );
bgcs_label_check(
	'No markup is emitted at all',
	$emitted,
	wp_strip_all_tags( $emitted )
);

bgcs_label_check(
	'No state wording is emitted at all',
	true,
	false === strpos( $emitted, __( 'Free shipping' ) ) && false === strpos( $emitted, __( 'Awaiting calculation' ) )
);

bgcs_label_check(
	'Free transport with a positive surcharge keeps the formatted label',
	'Доставка със Спиди: <span class="woocommerce-Price-amount">3,50 лв.</span>',
	call_user_func(
		$filter,
		'Доставка със Спиди: <span class="woocommerce-Price-amount">3,50 лв.</span>',
		$surcharged_rate
	)
);

bgcs_label_check(
	'Non-BGCS rates are untouched',
	'Flat rate: <span class="woocommerce-Price-amount">0,00 лв.</span>',
	call_user_func(
		$filter,
		'Flat rate: <span class="woocommerce-Price-amount">0,00 лв.</span>',
		$foreign_rate
	)
);

// The defect this file exists to pin. `get_label()` runs
// `woocommerce_shipping_rate_label`, so reading it from inside a label filter
// returns whatever every other participant has already appended — on a live
// site, another Bulgarian shipping plugin writing its own "free" wording at
// priority 100. The code used to reach for a `get_data()` that WC_Shipping_Rate
// does not have, fall through to `get_label()`, and rebuild the "clean" name
// out of the polluted one. The untouched name comes from meta only.
$poisoned_rate = new BGCS_Label_Test_Rate(
	'bgcs3_speedy:14',
	'Доставка със Спиди: Не е калкулирано',   // what get_label() now returns
	0.0,
	array(
		'_bgcs3_price_state'  => 'pending',
		'_bgcs3_validated'    => false,
		'_bgcs3_method_title' => 'Доставка със Спиди',
	)
);

bgcs_label_check(
	'A third-party append is not read back through get_label()',
	'Доставка със Спиди',
	call_user_func( $filter, 'Доставка със Спиди: Не е калкулирано', $poisoned_rate )
);

// A rate calculated before the title meta existed and replayed from a session
// cache. Nothing better is reachable, so the caller's own input has to stand —
// silently inventing a name would be worse than leaving it alone.
$legacy_rate = new BGCS_Label_Test_Rate(
	'bgcs3_speedy:14',
	'Доставка със Спиди',
	0.0,
	array(
		'_bgcs3_price_state' => 'pending',
		'_bgcs3_validated'   => false,
	)
);

bgcs_label_check(
	'Without the title meta the incoming label is left as it is',
	'Доставка със Спиди',
	call_user_func( $filter, 'Доставка със Спиди', $legacy_rate )
);

bgcs_label_check(
	'The public state wording is exposed for other renderers',
	'Безплатна доставка',
	\BgCommerce3\Shipping\Hooks::rate_price_state_text( $free_rate )
);

bgcs_label_check(
	'A surcharged free rate announces no state',
	'',
	\BgCommerce3\Shipping\Hooks::rate_price_state_text( $surcharged_rate )
);

if ( $failures > 0 ) {
	printf( "\n%d check(s) failed.\n", $failures );
	exit( 1 );
}

echo "\nCourier method label checks passed.\n";
