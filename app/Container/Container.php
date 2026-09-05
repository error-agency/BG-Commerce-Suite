<?php
/**
 * Lightweight dependency-injection container (Pimple-style).
 *
 * Closures are lazily resolved once and cached. Kept dependency-free so the
 * skeleton runs without Composer; can be swapped for pimple/pimple later.
 *
 * @package BgCommerce3
 */

namespace BgCommerce3\Container;

defined( 'ABSPATH' ) || exit;

class Container implements \ArrayAccess {

	/** @var array<string,mixed> Service factories (closures) or raw values. */
	private $factories = array();

	/** @var array<string,mixed> Resolved singletons. */
	private $resolved = array();

	/**
	 * Register a service factory or value.
	 *
	 * @param string $id    Service id.
	 * @param mixed  $value Closure(Container):mixed or a raw value.
	 */
	public function offsetSet( $id, $value ): void {
		$this->factories[ $id ] = $value;
		unset( $this->resolved[ $id ] );
	}

	/**
	 * Resolve a service (singleton).
	 *
	 * @param string $id Service id.
	 * @return mixed
	 */
	#[\ReturnTypeWillChange]
	public function offsetGet( $id ) {
		if ( ! isset( $this->factories[ $id ] ) ) {
			throw new \InvalidArgumentException( sprintf( 'Unknown service "%s".', esc_html( (string) $id ) ) );
		}

		if ( ! array_key_exists( $id, $this->resolved ) ) {
			$factory              = $this->factories[ $id ];
			$this->resolved[ $id ] = ( $factory instanceof \Closure ) ? $factory( $this ) : $factory;
		}

		return $this->resolved[ $id ];
	}

	/**
	 * @param string $id Service id.
	 */
	public function offsetExists( $id ): bool {
		return isset( $this->factories[ $id ] );
	}

	/**
	 * @param string $id Service id.
	 */
	public function offsetUnset( $id ): void {
		unset( $this->factories[ $id ], $this->resolved[ $id ] );
	}
}
