<?php
/**
 * The Speedy module's id constant on its own.
 *
 * `Speedy\Client` reads its settings through `Module_Settings::get( Speedy::ID,
 * … )`, so a test that wants only the client would otherwise have to load the
 * whole module and everything it registers. This gives the client the one
 * symbol it actually needs.
 *
 * @package BgCommerce3
 */

namespace BgCommerce3\Modules\Shipping\Speedy;

if ( ! class_exists( __NAMESPACE__ . '\\Speedy', false ) ) {
	class Speedy {
		const ID = 'speedy';
	}
}
