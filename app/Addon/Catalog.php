<?php
/**
 * Product catalog for optional BG Commerce Suite add-ons.
 *
 * The catalog is presentation metadata only. It never downloads, installs,
 * activates or executes remote code. Add-ons remain normal independent
 * WordPress plugins and must register their runtime module through Module API.
 *
 * @package BgCommerce3
 */

namespace BgCommerce3\Addon;

defined( 'ABSPATH' ) || exit;

final class Catalog {

	/**
	 * Return the remote product catalog shown on the BGCS Dashboard.
	 *
	 * Each entry may contain:
	 * - name: public product name.
	 * - category: short merchant-facing category.
	 * - description: marketing description.
	 * - version: advertised/current version (optional).
	 * - price: display-only price text (optional).
	 * - url: external product/purchase URL (optional).
	 * - plugin_file: plugin basename used only for installed detection (optional).
	 * - requires_api: minimum BGCS Module API version (optional).
	 * - icon: icon name from Admin\Icons.
	 * - status: available|beta|coming_soon|retired.
	 * - status_label: custom display label (optional).
	 * - featured: whether the card should receive featured styling.
	 *
	 * Extensions can append or adjust cards with the
	 * `bgcs3_addon_catalog` filter. This filter is metadata only and grants no
	 * runtime privileges.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function items() {
		$items = Remote_Catalog::items();

		/**
		 * Filter the merchant-facing BGCS add-on catalog.
		 *
		 * Use this only for product metadata. Runtime registration belongs to
		 * `bgcs3_register_modules` / Addon\Bootstrap.
		 *
		 * @param array<string,array<string,mixed>> $items Catalog entries.
		 */
		$items = (array) apply_filters( 'bgcs3_addon_catalog', $items );

		return $items;
	}
}
