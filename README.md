# BG Commerce Suite

Modular WooCommerce integration for the Bulgarian courier market — **Speedy**, **Econt**,
**BOX NOW** and **Pigeon Express** — covering checkout location selection, shipment labels,
tracking, order-status automation and cash-on-delivery payout reconciliation, in a single
plugin.

|  |  |
| --- | --- |
| **Current version** | [4.4.0](https://github.com/error-agency/BG-Commerce-Suite/releases/latest) |
| **Requires WordPress** | 6.3 or later (tested up to 7.0) |
| **Requires PHP** | 7.4 or later |
| **Requires WooCommerce** | 8.2 or later |
| **License** | [GPL-2.0-or-later](LICENSE) |
| **Text domain** | `bg-commerce-suite` |
| **Module API** | 1.1 |

---

## Modules

| Module | Category | What it covers |
| --- | --- | --- |
| **Speedy** | Shipping | Live pricing, office/APT selection, labels, tracking, PMT contract fees |
| **Econt** | Shipping | Live pricing, office/Econtomat selection, labels, bulk status sync, courier requests |
| **BOX NOW** | Shipping | Locker selection via the official map/widget, parcel eligibility, webhooks |
| **Pigeon Express** | Shipping | Pricing, labels, tracking, courier pickup requests |
| **COD Reports** | Accounting | Payout reconciliation against courier payout APIs |

Each module has its own enable switch. A disabled module keeps its settings but does not boot
its provider runtime hooks.

## Installation

> **The plugin directory must be exactly `wp-content/plugins/bg-commerce-suite/`.**
> Dependent BGCS add-ons detect Core through this stable path.

1. Take a backup before replacing an existing BGCS build.
2. Deactivate any legacy standalone BGCS courier plugins. If one is still active, Core blocks
   its own runtime boot and shows an administrator warning; legacy files and settings are
   never deleted automatically.
3. Download the ZIP for the version you want from
   [Releases](https://github.com/error-agency/BG-Commerce-Suite/releases), then upload it under
   **Plugins → Add New → Upload Plugin**.
4. Activate **BG Commerce Suite** and open **WooCommerce → BG Commerce Suite**.
5. Configure and enable only the courier modules you intend to use.
6. Use provider test/staging credentials for provider-side testing wherever they exist.

## Administration

One consistent admin shell: Dashboard · General settings · Shipping (BOX NOW, Econt,
Pigeon Express, Speedy) · Accounting (COD Reports). The Dashboard combines the overview,
optional extensions catalog and controls for all built-in modules on one page.

The WooCommerce order screen uses a single compact shipment workspace for every courier.
Before a label exists it shows the delivery summary and the label creation flow; afterwards the
Label tab opens first and keeps preview, print, download and delete together.

Tracking events are normalised into shared BGCS states while the original provider state is
preserved. Per-courier WooCommerce status policies are configured independently, and an
unmapped provider status is recorded for diagnostics but never changes an order on its own.

The shipment-label email is a native `WC_Email` under **WooCommerce → Settings → Emails**, so
WooCommerce owns enable/disable, subject, heading, sender identity, transport and styling. It
supports the normal template override mechanism; BGCS placeholders add courier, waybill and
tracking URL.

## Add-ons and the Module API

Optional BGCS add-ons are independent WordPress plugins that extend Core through **Module API
1.1** and the `bgcs3_api_ready` runtime hook. Add-ons must not edit Core files, and their
runtime behaviour belongs in the module `register()` method so that disabled or incompatible
modules stay inert.

The merchant-facing product catalog is optional and disabled by default. After a site
administrator explicitly enables it, BGCS refreshes validated public product metadata from
`error.bg` hourly through Action Scheduler, with an authorized manual refresh and a persistent
last-known-good fallback. Product cards are sourced from that feed rather than bundled product
records. Remote metadata cannot install or activate plugins, or execute code.

Checkout renderers such as BGCS Flow read why a courier service is not selectable through the
public shipping-availability contract: the `bgcs3_shipping_availability` filter, and the
session-scoped `GET /wp-json/bg-commerce-suite/v3/availability` endpoint that applies the same
filter. Both return customer-safe rows only — provider response payloads and technical
diagnostics never leave Core through them.

## Translation

English is the source language. The package ships `languages/bg-commerce-suite.pot` plus a
bundled Bulgarian translation (`bg-commerce-suite-bg_BG.po` / `.mo`). Everything uses the single
`bg-commerce-suite` text domain, so any standard gettext-compatible workflow can add a locale.
Do not edit the bundled source strings to translate the plugin.

## External services

With the corresponding module enabled, the plugin talks to the external APIs of Speedy, Econt,
BOX NOW and Pigeon Express according to merchant configuration. Checkout map/location
functionality may use OpenStreetMap tiles, and BOX NOW locker selection can use the official
BOX NOW map/widget. Do not point destructive provider-side tests at production courier
credentials.

If a site administrator opts in, the Dashboard extensions area checks
`https://error.bg/wp-json/error-catalog/v1/feed` hourly and when an authorized administrator
requests a refresh. The request uses the generic
`BG-Commerce-Suite-Catalog/1` User-Agent and does not send the store URL, plugin inventory,
customer/order data, credentials or cookies; the remote server necessarily sees the connecting
server IP. The last valid product metadata remains cached locally during an outage and is
deleted when the catalog is disabled. See the [privacy notice](legal/PRIVACY.md) and
[catalog service terms](legal/CATALOG-SERVICE-TERMS.md).

## Repository layout

| Path | Contents |
| --- | --- |
| `bg-commerce-suite.php` | Plugin bootstrap, constants, guards |
| `app/` | Runtime: container, module lifecycle, admin, checkout, REST, shipping, accounting |
| `assets/` | Admin and front-end CSS/JS, build output, vendored libraries |
| `languages/` | Source `.pot` and the bundled `bg_BG` translation |
| `templates/` | Overridable WooCommerce email templates |
| `tests/` | Standalone contract test scripts |
| `tools/` | Translation, release packaging and clean public-source export scripts |
| `readme.txt` | Canonical WordPress plugin readme and changelog |
| `uninstall.php` | Uninstall cleanup |
| `legal/THIRD-PARTY-NOTICES.md` | Required notices for bundled open-source components |
| `legal/NOTICE.md` | Copyright and redistribution notices |
| `legal/PRIVACY.md` | External-service and data-processing disclosure |
| `legal/TRADEMARKS.md` | Accurate attribution and non-endorsement rules |
| `PUBLICATION.md` | Clean-history public repository procedure and export boundary |

The clean public-source export deliberately omits `dist/`, `docs/`, `audit/`, internal handoffs,
test reports and deployment helpers. `docs/` can contain provider API manuals that are useful
inside the private workspace but are not ours to redistribute. Release ZIPs are generated under
`dist/` and published separately rather than committed as source.

## Public repository history

The public repository starts from a reviewed source snapshot and intentionally does not import
the private development history. Internal handoffs, audits, provider manuals, deployment helpers
and environment-specific evidence remain in the private repository. See
[PUBLICATION.md](PUBLICATION.md) for the exact export and verification procedure.

## Releasing

1. Bump the version in three places together:
   * `Version:` in the `bg-commerce-suite.php` plugin header
   * the `BGCS3_VERSION` constant in the same file
   * `Stable tag:` in `readme.txt`, plus a new `= <version> =` block under `== Changelog ==`
2. Mirror that changelog block into [CHANGELOG.md](CHANGELOG.md).
3. Build `dist/bg-commerce-suite-<version>.zip`. The archive must extract to a single
   `bg-commerce-suite/` directory.
4. Run `php tools/build-public-source.php` and `php tests/test-publication-readiness.php` from the private development repository.
5. Commit, then publish the tag and the artifact together:

```bash
gh release create v<version> dist/bg-commerce-suite-<version>.zip --title "BG Commerce Suite <version>" --notes-file release-notes.md
```

## License

BG Commerce Suite is free software, released under the GNU General Public License version 2 or
(at your option) any later version. See [LICENSE](LICENSE) for the full text.

Copyright © 2026 [Error Web Agency](https://error.bg)

Bundled open-source components retain their own licenses and notices. See
[THIRD-PARTY-NOTICES.md](legal/THIRD-PARTY-NOTICES.md). Courier names and marks identify the services
with which the plugin integrates; no endorsement is implied.
