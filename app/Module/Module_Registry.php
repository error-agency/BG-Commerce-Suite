<?php
/**
 * Holds all registered modules (built-in + DLC), validates API compatibility,
 * and boots the enabled ones.
 *
 * @package BgCommerce3
 */

namespace BgCommerce3\Module;

use BgCommerce3\Container\Container;

defined( 'ABSPATH' ) || exit;

class Module_Registry {

	/** @var array<string,Module_Interface> */
	private $modules = array();

	/** @var string Current Core Module API version. */
	private $api_version;

	/** @var array<string,string> id => reason, for modules skipped as incompatible. */
	private $incompatible = array();

	/** @var array<string,array<int,array<string,string>>> Duplicate registration diagnostics. */
	private $conflicts = array();

	/**
	 * @param string $api_version Current Core Module API version.
	 */
	public function __construct( $api_version ) {
		$this->api_version = $api_version;
	}

	/**
	 * Register a module. Duplicate IDs are rejected so plugin load order cannot
	 * silently replace an already registered module.
	 *
	 * @param Module_Interface $module Module instance.
	 * @return self
	 */
	public function add( Module_Interface $module ) {
		$id = $module->id();

		if ( isset( $this->modules[ $id ] ) ) {
			$registered_class = get_class( $this->modules[ $id ] );
			$rejected_class   = get_class( $module );

			$this->conflicts[ $id ][] = array(
				'registered' => $registered_class,
				'rejected'   => $rejected_class,
			);

			add_action(
				'admin_notices',
				static function () use ( $id, $registered_class, $rejected_class ) {
					if ( ! current_user_can( 'activate_plugins' ) ) {
						return;
					}

					echo '<div class="notice notice-error"><p>' .
						esc_html(
							sprintf(
								/* translators: 1: module ID, 2: active module class, 3: rejected module class. */
								__( 'BG Commerce Suite detected a conflict for module ID “%1$s”. %2$s was kept and %3$s was not loaded.', 'bg-commerce-suite' ),
								$id,
								$registered_class,
								$rejected_class
							)
						) .
						'</p></div>';
				}
			);

			return $this;
		}

		$this->modules[ $id ] = $module;
		return $this;
	}

	/**
	 * @param string $id Module id.
	 * @return Module_Interface|null
	 */
	public function get( $id ) {
		return isset( $this->modules[ $id ] ) ? $this->modules[ $id ] : null;
	}

	/**
	 * All registered modules.
	 *
	 * @return array<string,Module_Interface>
	 */
	public function all() {
		return $this->modules;
	}

	/**
	 * Registered modules grouped by category.
	 *
	 * @return array<string,Module_Interface[]>
	 */
	public function by_category() {
		$grouped = array();
		foreach ( $this->modules as $module ) {
			$grouped[ $module->category() ][] = $module;
		}
		return $grouped;
	}

	/**
	 * Modules skipped because they need a newer Core. id => reason.
	 *
	 * @return array<string,string>
	 */
	public function incompatible() {
		return $this->incompatible;
	}

	/**
	 * Duplicate module registrations rejected by the registry.
	 *
	 * @return array<string,array<int,array<string,string>>>
	 */
	public function conflicts() {
		return $this->conflicts;
	}

	/**
	 * Boot every enabled, compatible module.
	 *
	 * @param Container $container Core DI container.
	 */
	public function boot( Container $container ) {
		foreach ( $this->modules as $id => $module ) {
			if ( ! $this->is_compatible( $module ) ) {
				/* translators: 1: module name, 2: required API version */
				$this->incompatible[ $id ] = sprintf(
					__( '“%1$s” requires BG Commerce Suite Module API %2$s or newer.', 'bg-commerce-suite' ),
					$module->name(),
					$module->requires_api()
				);
				continue;
			}

			if ( ! $module->is_enabled() ) {
				continue;
			}

			$module->register( $container );
		}
	}

	/**
	 * @param Module_Interface $module Module instance.
	 * @return bool
	 */
	private function is_compatible( Module_Interface $module ) {
		return version_compare( $this->api_version, $module->requires_api(), '>=' );
	}
}
