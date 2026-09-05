<?php
/**
 * A module double whose `settings_fields()` reads an option while it is being
 * composed — the shape Econt has, where the selected profile is read before the
 * API-fed dropdowns are built. Used to prove `Module_Settings` cannot recurse
 * into a field set that is still under construction.
 *
 * @package BgCommerce3
 */

use BgCommerce3\Container\Container;
use BgCommerce3\Module\Module_Interface;
use BgCommerce3\Support\Module_Settings;

class Reentrant_Module implements Module_Interface {

	/** @var int How many times settings_fields() ran. */
	public $builds = 0;

	/** @var mixed What the nested read returned. */
	public $seen = null;

	public function id() {
		return 'reentrant';
	}
	public function name() {
		return 'Re-entrant module';
	}
	public function category() {
		return 'shipping';
	}
	public function requires_api() {
		return false;
	}
	public function is_enabled() {
		return true;
	}
	public function settings_tab() {
		return array();
	}
	public function register( Container $container ) {
	}

	public function settings_fields() {
		$this->builds++;

		// The re-entrant read: this key is missing from storage, so resolving it
		// wants the very field set being composed right now.
		$this->seen = Module_Settings::get( 'reentrant', 'built', 'inner-fallback' );

		return array( 'built' => array( 'type' => 'text', 'default' => 'declared-value' ) );
	}
}

if ( ! function_exists( 'bgcs3' ) ) {
	/** Minimal stand-in for the plugin accessor `Module_Settings` resolves through. */
	function bgcs3() {
		return new Bgcs_Test_Plugin();
	}
}

class Bgcs_Test_Plugin {
	public function container() {
		return new Bgcs_Test_Container();
	}
}

class Bgcs_Test_Container implements ArrayAccess {

	#[\ReturnTypeWillChange]
	public function offsetGet( $id ) {
		return ( 'modules' === $id ) ? new Bgcs_Test_Registry() : null;
	}
	public function offsetExists( $id ): bool {
		return 'modules' === $id;
	}
	public function offsetSet( $id, $value ): void {
	}
	public function offsetUnset( $id ): void {
	}
}

class Bgcs_Test_Registry {
	public function get( $id ) {
		return ( 'reentrant' === $id && isset( $GLOBALS['bgcs_reentrant_module'] ) )
			? $GLOBALS['bgcs_reentrant_module']
			: null;
	}
}
