=== BG Commerce Suite ===
Tags: woocommerce, shipping, bulgaria, delivery, couriers
Requires at least: 6.3
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 4.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Courier shipping, pickup-point selection, shipment labels, tracking and COD reconciliation for Bulgarian WooCommerce stores.

== Description ==

BG Commerce Suite combines supported Bulgarian courier integrations and cash-on-delivery reporting in one WooCommerce plugin.

Included modules:

* Speedy
* Econt
* BOX NOW
* Pigeon Express
* COD Reports

The plugin supports Classic and Block Checkout, office and locker selection, courier rate calculation, shipment label creation, tracking, configurable WooCommerce order-status automation and COD payout reconciliation.

Each module has its own enable switch. Disabled modules retain their settings but do not run their courier hooks. Courier accounts and API credentials are supplied by the merchant directly under the applicable courier agreement.

The complete BG Commerce Suite functionality included in this package is free software under GPLv2 or later. Optional Error Web Agency products shown in the administrator-only catalog are separate plugins or services and are not required for BG Commerce Suite to operate.

= Optional product catalog =

The Error Web Agency product catalog is disabled by default. A site administrator can explicitly opt in under WooCommerce > BG Commerce Suite > General settings.

After opt-in, the plugin requests validated presentation metadata from error.bg hourly and when an administrator selects Refresh catalog. It does not send the store URL, plugin inventory, customer or order data, credentials, email addresses or cookies. Normal Internet operation exposes the connecting server IP address. Disabling the catalog stops its schedule and removes its cached offers.

Remote catalog metadata cannot install, activate, update or execute plugin code. It can only provide names, descriptions, versions, availability, prices, links and promotion dates for administrator-facing product cards.

= Privacy support =

BG Commerce Suite adds editable suggested text to WordPress Settings > Privacy for the external services used by enabled modules. Store owners should review that text and publish a privacy policy matching their actual courier and map configuration.

= Translation =

English is the source language and a Bulgarian translation is bundled. The plugin uses the `bg-commerce-suite` text domain.

== Installation ==

1. Install and activate WooCommerce.
2. Upload `bg-commerce-suite.zip` under Plugins > Add New > Upload Plugin, or install it from the WordPress.org Plugin Directory after approval.
3. Activate BG Commerce Suite.
4. Open WooCommerce > BG Commerce Suite.
5. Configure and enable only the courier modules you use.
6. Use courier test or demo credentials for integration testing where available.

The plugin directory must remain `wp-content/plugins/bg-commerce-suite/` because optional BGCS add-ons use this stable path for dependency detection.

Do not activate earlier standalone BGCS courier plugins at the same time. BG Commerce Suite detects known legacy plugins and blocks its own runtime to prevent duplicate checkout and shipment hooks. It does not delete legacy files or settings.

== Frequently Asked Questions ==

= Does BG Commerce Suite send telemetry? =

No. The plugin contains no general analytics or telemetry system.

= Is the optional Error Web Agency catalog required? =

No. It is disabled by default and the courier integrations operate without it. Only a site administrator can opt in.

= Can catalog metadata install another plugin? =

No. Remote metadata is validated and display-only. It is never passed to an installer, updater, activation function, include statement or script/style loader.

= What information is sent to couriers? =

Only information needed for the operation selected by the merchant, such as rate calculation, shipment creation, pickup request, tracking or payout reconciliation. Depending on that operation it can include sender and recipient contact information, delivery destination, parcel details, order references, declared value and COD amounts.

= Does uninstall delete order shipment history? =

No. Uninstall removes BGCS-owned options, transients and scheduled actions. Order, shipment and payout facts remain available in WooCommerce records.

== External Services ==

BG Commerce Suite connects to an external service only when the corresponding feature has been configured or explicitly enabled.

= Error Web Agency catalog =

Purpose: Optional administrator-facing product and extension discovery.

When used: Only after a site administrator opts in; then hourly and on manual refresh.

Data: Generic `BG-Commerce-Suite-Catalog/1` User-Agent, optional `If-None-Match` cache validator and normal connection data including the server IP address. No store URL, plugin inventory, customer/order data, credentials, email addresses or cookies are added.

Service: https://error.bg/wp-json/error-catalog/v1/feed

Privacy: https://github.com/error-agency/BG-Commerce-Suite/blob/main/legal/PRIVACY.md

Terms: https://github.com/error-agency/BG-Commerce-Suite/blob/main/legal/CATALOG-SERVICE-TERMS.md

= Speedy =

Purpose: Locations, rates, shipment labels, pickup requests and tracking when the Speedy module is enabled.

Service: https://www.speedy.bg/bg/system-integration

Privacy: https://www.speedy.bg/public/en/gdpr?re=ib

Terms: https://www.speedy.bg/public/index.php/bg/terms-and-conditions-cookies

= Econt =

Purpose: Locations, rates, shipment labels, pickup requests, tracking and payout reports when the Econt module is enabled.

Service: https://www.econt.com/

Privacy: https://www.econt.com/econt-express/privacy-policy

Terms: https://www.econt.com/econt-express/terms-of-use

= BOX NOW =

Purpose: Locker locations, rates, shipment labels, tracking and the official locker map/widget when the BOX NOW module is enabled.

Service: https://boxnow.bg/

Privacy: https://www2.boxnow.bg/personal-data-processing-notice

Terms: https://t.boxnow.bg/en/terms-of-use-for-shipping-services

= Pigeon Express =

Purpose: Locations, rates, shipment labels, pickup requests and tracking when the Pigeon Express module is enabled.

Service: https://pigeonexpress.com/

Privacy: https://pigeonexpress.com/privacy

Terms: https://pigeonexpress.com/terms

= OpenStreetMap =

Purpose: Map tiles for optional office and locker selection. When a map is displayed, the visitor browser requests tiles and the tile service receives normal connection data such as the visitor IP address and browser headers.

Service and copyright: https://www.openstreetmap.org/copyright

Privacy: https://osmfoundation.org/wiki/Privacy_Policy

Tile policy: https://operations.osmfoundation.org/policies/tiles/

Bundled open-source component licenses are in `legal/THIRD-PARTY-NOTICES.md`.

== Changelog ==

= 4.4.0 =

* Made the optional Error Web Agency product catalog an explicit administrator opt-in that is disabled by default.
* Prevented scheduled, manual and direct catalog requests without consent; opting out removes the recurring action and cached offers.
* Added WordPress privacy-policy helper text and complete external-service disclosures.
* Added Error Web Agency copyright, redistribution, privacy, catalog-service and trademark notices.
* Reduced the directory readme to five tags and a compact current changelog.

= 4.3.2 =

* Added third-party software notices and asset provenance documentation.
* Corrected the Checkout Blocks OpenStreetMap endpoint and visible attribution.
* Added reproducible release and public-source manifests.

The complete release history is available at https://github.com/error-agency/BG-Commerce-Suite/blob/main/CHANGELOG.md

== Upgrade Notice ==

= 4.4.0 =

The optional Error Web Agency catalog is now disabled until a site administrator explicitly enables it. Courier modules and existing shipment workflows are unaffected.

== Copyright ==

Copyright (C) 2026 Error Web Agency. BG Commerce Suite is licensed under GPLv2 or later. Distributions must preserve applicable copyright and license notices. See `legal/NOTICE.md`, `LICENSE`, `legal/THIRD-PARTY-NOTICES.md` and `legal/TRADEMARKS.md`.
