=== BG Commerce Suite ===
Contributors: bgcommerce
Tags: woocommerce, shipping, bulgaria, couriers, speedy, econt, boxnow, pigeon
Requires at least: 6.3
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 4.3.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A modular WooCommerce integration for Speedy, Econt, BOX NOW, Pigeon Express and COD reports.

== Description ==

BG Commerce Suite combines the supported Bulgarian courier integrations and COD reporting tools in one WooCommerce plugin.

Included modules:

* Speedy
* Econt
* BOX NOW
* Pigeon Express
* COD Reports

The plugin includes Classic and Blocks checkout integration, courier location selection, shipment label creation, tracking, courier status normalization, configurable WooCommerce order-status automation, COD payout reconciliation, and WooCommerce-native transactional email for created shipment labels.

The source/default language is English. A Bulgarian translation is bundled. All plugin UI strings use the `bg-commerce-suite` text domain so translations can be maintained with standard gettext-compatible tools.

== Important ==

The plugin directory must remain exactly:

`wp-content/plugins/bg-commerce-suite/`

Dependent BGCS add-ons use this stable Core path for dependency detection.

Do not run legacy standalone BGCS courier plugins at the same time. If a known legacy BGCS plugin is active, Core blocks its normal runtime boot and shows an administrator warning. Legacy files and settings are not deleted automatically.

On a first migration from supported earlier BGCS installations, BG Commerce Suite may conservatively copy useful settings into the `bgcs3_*` namespace. The original options remain untouched and courier modules remain disabled until explicitly enabled by the merchant.

== Administration ==

BG Commerce Suite uses one consistent admin shell:

* Dashboard
* General settings
* Shipping
  * BOX NOW
  * Econt
  * Pigeon Express
  * Speedy
* Accounting
  * COD Reports

The Dashboard combines the overview, optional extensions catalog and controls for all built-in modules on one page.

Courier settings remain available while a module is disabled, but disabled modules do not boot their provider runtime hooks.

== Add-ons and Module API ==

The Dashboard extensions area contains:

* Optional BGCS add-ons — independent WordPress plugins that extend Core through Module API 1.1.
* Built-in modules — integrations already included in this plugin and controlled by their enable switches.

Independent add-ons must not edit BG Commerce Suite Core files. Their runtime behavior belongs in the module `register()` method so disabled or incompatible modules stay inert.

The optional-product catalog is sourced from public presentation metadata at error.bg and refreshes hourly or when an authorized administrator selects Refresh catalog. A failed or invalid response never replaces the last valid catalog, and remote metadata cannot install, activate or execute plugin code.

== Shipment workflow ==

The WooCommerce order screen uses one compact shipment workspace for all couriers. Before a label exists, the workspace exposes the delivery summary and label creation flow. After label creation, the Label tab opens first and keeps preview, print, download and delete actions together.

Tracking events are normalized into common BGCS states while preserving the original provider state. Courier-specific WooCommerce status policies can be configured independently. Unknown provider statuses are recorded for diagnostics and never change a WooCommerce order automatically until they are mapped.

== Shipment email ==

The shipment-label email is a native WooCommerce `WC_Email` and appears under WooCommerce > Settings > Emails. WooCommerce controls enable/disable, subject, heading, additional content, email type, sender identity, mail transport, styling and supported email-editor features.

The email supports the normal WooCommerce template override mechanism and follows WooCommerce order/email hooks. BGCS-specific placeholders include courier, waybill/tracking number and tracking URL.

== Translation ==

English is the source/default language. The package includes:

* `languages/bg-commerce-suite.pot`
* `languages/bg-commerce-suite-bg_BG.po`
* `languages/bg-commerce-suite-bg_BG.mo`

The plugin uses one text domain: `bg-commerce-suite`.

For a custom language, use a standard gettext-compatible translation workflow for that locale. Do not edit the bundled source strings to translate the plugin.

== Installation ==

1. Create a staging backup before replacing an existing BGCS build.
2. Deactivate legacy standalone BGCS courier plugins.
3. Upload the ZIP. It must extract as `wp-content/plugins/bg-commerce-suite/`.
4. Activate BG Commerce Suite.
5. Open WooCommerce > BG Commerce Suite.
6. Configure and enable only the courier modules you intend to test or use.
7. Use provider test/staging credentials for provider-side tests whenever available.

== External Services ==

When the corresponding module is enabled, BG Commerce Suite can communicate with the external APIs of Speedy, Econt, BOX NOW and Pigeon Express according to the merchant's configuration.

Checkout map/location functionality may use OpenStreetMap tiles where the selected flow requires them. BOX NOW locker selection can use the official BOX NOW map/widget according to the module configuration.

Bundled open-source component licenses and map attribution requirements are documented in `THIRD-PARTY-NOTICES.md`. Courier names and marks identify supported services; no endorsement is implied.

The Dashboard extensions area uses the public product metadata endpoint at https://error.bg/wp-json/error-catalog/v1/feed. BGCS checks it hourly through Action Scheduler and on an authorized manual refresh. The request uses the generic BG-Commerce-Suite-Catalog/1 User-Agent; it does not send the store URL, plugin inventory, customer/order data, credentials or cookies. The remote server necessarily receives the connecting server IP address. The last valid product names, versions, prices, links and promotion dates remain cached locally during an outage and are used only to present the catalog.

Do not use production courier credentials for destructive provider-side tests when the goal is staging QA.

== Changelog ==

= 4.3.2 =
* Added complete third-party software notices and asset provenance documentation to the distributed plugin.
* Corrected the Checkout Blocks OpenStreetMap endpoint and visible attribution while preserving the existing map behavior.
* Added a reproducible public-source export that excludes internal audits, handoffs, deployment helpers and live-environment references.

= 4.3.1 =
* Restored the Speedy full-locker behavior and shipment-note controls to the task-oriented Shipping methods screen, including its scoped save path.

= 4.3.0 =

* Publishes courier delivery wording through WooCommerce's native delivery_time field so Checkout Blocks can render it without custom JavaScript while the shipping method name stays clean.
* Shows the same delivery wording through the standard per-rate Classic Checkout hook, including BGCS Flow's standalone checkout.
* Distinguishes Speedy's delivery deadline from Econt's expected delivery date and renders nothing when a courier supplies no estimate.
* Removes the previous rate-label mutation and the compensating order-title cleanup, so stored shipping method titles remain clean by construction.

= 4.2.0 =

* Added a Speedy "Behavior when the locker is full" setting. By default a configurable merchant note is sent as the waybill's shipmentNote for locker deliveries only, and a selected office/locker now sends autoSelectNearestOffice = false.
* Shipping rates, orders and e-mails now show a delivery estimate, but only when the courier API actually returns one. Speedy uses deliveryDeadline, Econt uses expectedDeliveryDate; BOX NOW and Pigeon show nothing rather than inventing a date.
* A courier timestamp that lands on local midnight is reported as a date, so an Econt estimate no longer promises the customer a delivery at 00:00.
* Kept the estimate out of the order's stored shipping method title, so it is not frozen at checkout time and no longer appears twice in order e-mails.

= 4.1.3 =

* Vertically centered the remote module switch and action button in the catalog card footer.

= 4.1.2 =

* Matched remote catalog products to their installed plugin and registered module without hardcoded product identities, including a safe directory-slug fallback when the feed omits plugin_file.
* Added active/disabled controls and explicit installed/latest version status for matched add-on modules.
* Hid catalog diagnostics from normal production screens; they remain available only with WP_DEBUG and bgcs_debug=1.

= 4.1.1 =

* Removed the bundled BGCS Flow product record so optional product cards are sourced only from the validated error.bg catalog feed.
* Improved catalog refresh status spacing and changed product metadata to clear label/value rows on desktop and mobile.

= 4.1.0 =

* Added a validated, cached product metadata feed for new products, versions and scheduled promotions in the Add-ons catalog.
* Added hourly background refresh, ETag support and an authorized manual Refresh catalog action with persistent last-known-good fallback.
* Added catalog diagnostics, deterministic campaign selection, normal/promotion prices and explicit installed/version states.
* Kept remote catalog data presentation-only: it cannot replace local module identity, install or activate plugins, or execute code.
* Combined Dashboard and Extensions into one page and removed the duplicate installed-modules list.

= 4.0.0 =

* Removed two implicit legacy payment gateway compatibility ids. Standard WooCommerce COD and the existing BOX NOW integration remain unchanged; stores can extend the documented BGCS filter for their own gateway.
* Replaced a token-based source check with a positive BG Commerce Suite identity contract for namespaces, translations, approved external hosts and courier payment registration boundaries.
* Kept courier names, shipping identifiers and official courier endpoints unchanged.

= 3.6.6 =

* External shipping selections now survive the early WooCommerce checkout-review hook when shipping package snapshots have not been populated yet; fresh WooCommerce rates still validate the provisional choice before totals are returned.

= 3.6.5 =

* Flow now emits WooCommerce's checkout refresh directly after an external shipping-method selection, even when a previous courier refresh left the BGCS update scheduler busy.

= 3.6.4 =

* Flow checkout now persists an explicitly selected external WooCommerce shipping method before BGCS courier synchronization, preventing the previous courier rate from overwriting the selection and totals.

= 3.6.3 =

* External WooCommerce shipping methods now trigger a checkout totals refresh in custom renderers such as Flow when the renderer does not start WooCommerce's native refresh itself. Classic Checkout keeps its native single refresh without an extra duplicate request.

= 3.6.2 =

* The REST quote endpoint now uses the same WooCommerce/Core pricing pipeline as Classic, Blocks and Flow instead of issuing a separate provider quote. Static/free/API pricing, COD context and taxes therefore stay consistent across consumers.
* REST quote responses now aggregate every shipping package, fail closed when any package has no settled courier rate, and never present a pending zero as free delivery.
* Public and administrator location endpoints now enforce bounded query, provider ID, postcode, country and pickup-point type inputs before dispatching provider searches.

= 3.6.1 =

* Office and locker rows now retain the courier's exact city ID. Local checkout search prefers that ID over display-name matching, preventing same-name cities from sharing pickup points while preserving normalized-name fallback for legacy rows.
* City-scoped provider results now reject explicit nearby-city IDs. Pigeon and BOX NOW normalize Bulgarian Cyrillic/Latin city search instead of depending on provider-side transliteration.
* Location responses and persistent pools now collapse exact duplicate location IDs deterministically without merging distinct same-name cities.
* Changing a courier account, credentials or environment now invalidates both transient reference data and persistent office/locker pools for Speedy, Econt, BOX NOW and Pigeon Express.

= 3.6.0 =

* Courier pickup requests now use a canonical lifecycle with atomic duplicate protection, PII-free request identity, provider waybill plus stable BGCS shipment reference, normalized status and last-update time.
* Econt create/status and Pigeon create/status/cancel operations persist the same order association. Pigeon cancellation releases active shipment markers only after provider success, while Econt does not advertise an undocumented cancellation operation.
* Pickup requests now require at least one prepared shipment. The courier settings and order workspace show request ID, status, attached shipment, pickup date and last update; Econt pickup IDs returned together with label creation enter the same lifecycle.

= 3.5.0 =
* COD payout reconciliation now stores a canonical receipt with expected and paid amount, currency, fee when supplied, net payout, difference, payout date, source, report reference, shipment identity, status and an idempotency fingerprint.
* Background API sync, confirmed courier/CSV previews and manual paid/pending actions now share the same receipt lifecycle. Repeated reports do not duplicate notes, while conflicting duplicate or replacement payout identities require review.
* The order shipment workspace now shows Expected, Paid, Difference, Fee, Net payout, Paid date, Source and Report reference. BOX NOW continues to hide payout controls because it exposes no supported payout-report capability.

= 3.4.0 =
* Tracking snapshots now preserve raw and normalized status, acquisition source, last synchronization time and deduplicated event history. Background cron, direct polling, manual refresh and verified webhook paths are distinguishable.
* Status resolution follows provider event time, so older history cannot move a shipment backwards. Repeated polling and protected WooCommerce status decisions are idempotent.
* Speedy operation codes 144, 152 and 175 are recognized as non-terminal in-transit events. Both tracking mappings and post-label automation include custom WooCommerce statuses.

= 3.3.0 =
* Shipment cancellation now uses the same per-order database lock as creation and persists preparation, provider request, confirmed, failed, ambiguous and completed states. Active shipment metadata is removed only after confirmed courier cancellation.
* Confirmed cancellations append a PII-free shipment history entry and advance the stable shipment edition before an explicit replacement can be created. Failed or ambiguous cancellation preserves the active label/tracking and blocks replacement.
* The order workspace now identifies the active shipment, shows cancellation uncertainty/failure, disables unsafe repeated actions and lists cancelled shipment history. Econt also validates the documented per-shipment delete result.

= 3.2.0 =

* Shipment creation now persists a crash-safe lifecycle state from local preparation through remote request, provider acceptance and canonical order storage. A timeout, conflict or malformed response blocks blind retry until the courier state is reconciled.
* Every built-in courier records provider identity immediately after acceptance. The canonical label snapshot now includes shipment number, all parcel/tracking IDs, label reference, environment, payload fingerprint and a truthful local/remote/missing PDF state.
* Speedy prints all returned parcels, Econt caches temporary label URLs in the protected BGCS PDF store, and BOX NOW/Pigeon preserve a successfully created shipment even when local PDF retrieval fails. Provider read-back is recorded as verified, partial or unavailable without cancelling automatically.

= 3.1.0 =

* Added a shared, non-destructive shipment preflight contract for Speedy, Econt, BOX NOW and Pigeon Express. Missing selection, destination, package data, recipient details or API credentials now blocks shipment creation before the provider create call.
* Each create attempt stores a structured `_bgcs3_preflight` snapshot covering environment, selection, package, payment, payer, services, warnings and blocking errors. Customer PII, credentials and raw courier payloads are not persisted; the exact payload is represented by its shape and SHA-256 fingerprint.
* Courier-specific validation remains owned by each module and joins the same preflight result. The public Module API remains backward compatible for independently developed add-ons.

= 3.0.64 =

* BGCS shipping rates now record the payment context used for pricing. Changing the checkout gateway refreshes the quote, and a server-side order guard invalidates a stale prepaid shipping cache before a COD order can be created.

= 3.0.63 =

* Speedy API pricing now separates the PMT component already included by the courier from any additional amount required by the configured percentage/minimum floor. Only the missing difference is added, preventing a duplicate PMT charge.
* Recipient-paid Speedy transport remains outside COD while sender-paid PMT recovery remains inside it. Checkout and shipment preflight now rebuild the same PMT base, including products after discounts, taxes, fees and base shipping.
* COD amounts are normalized once in the shared resolver, and surcharge snapshots record the applied source, provider VAT percentage when known, and WooCommerce shipping tax treatment.
* Test-site candidate superseded by 3.0.64 before public release; no build artifact.

= 3.0.62 =

* Package quote snapshots now persist the explicit shipping method ID, runtime instance ID, net shipping total, shipping tax and fixed-precision tax-inclusive shipping total. Retry integrity checks reject changes to either the shipping amount or its tax.
* Pricing surcharges are retained in both the immutable package snapshot and the legacy single-package order audit metadata.

= 3.0.61 =
* Order creation now retains a package-aware quote snapshot with the exact BGCS rate instance, selection revision, package total and pricing audit data. A stale destination, rate instance or shipping total cannot be persisted as the current courier quote.
* Repeated Store API draft updates atomically replace public `bgcs3_*` order fields and the shipping destination, removing office, address and previous-courier data that no longer belongs to the canonical selection.
* Courier-specific shipping-price fields now sum only that courier's BGCS shipping lines, so unrelated shipping packages cannot inflate the persisted courier price.

= 3.0.60 =
* Checkout Blocks now finds the selected BGCS rate across every shipping package. An external method in an earlier package can no longer hide the BGCS courier from transition reset and revision synchronization.
* Mixed-package regression coverage now exercises Classic, Store API and pre-payment rejection of pending BGCS rates while preserving unrelated external shipping methods.

= 3.0.59 =
* Switching courier in Classic or Blocks Checkout now immediately clears the previous provider's city, office, address and extras, then publishes a revisioned incomplete selection for the newly chosen courier before recalculation.
* Remembered selections are restored only for the courier present on initial checkout load. Interactive courier changes cannot silently restore an earlier destination; the customer must confirm the new courier's city and office again.

= 3.0.58 =
* Classic Checkout now aligns WooCommerce's top-level posted shipping method with the canonical BGCS courier after synchronization, preventing a late stale AJAX value from restoring the previous courier.
* Office search now requires an exact normalized city when courier data provides one. Regional labels such as “Бяла, Русенско” and “БЯЛА (РУСЕ)” no longer appear as offices in Русе.

= 3.0.57 =
* BGCS courier selections now synchronize the exact active WooCommerce rate instance per shipping package, persist `chosen_shipping_methods`, invalidate relevant package caches and recalculate to one settled state.
* Monotonic selection revisions and a serialized Blocks update queue prevent older checkout requests from restoring a previous courier. External shipping selections in unrelated packages remain untouched.
* Checkout and pre-payment validation reject cross-courier, pending or unvalidated BGCS rates. BOX NOW keeps its configured tariff price and now reports canonical static/configured-tariff pricing metadata instead of API pricing.

= 3.0.56 =
* “Update sender details” is now one action in every courier integration: it saves the current Account and Sender fields first, then validates and refreshes the sender from the courier. Unsaved selections are no longer ignored by a separate empty form.
* BOX NOW skips its shipping-price repeater validation for this sender action, so a hidden field on another settings tab cannot block the save and refresh.

= 3.0.55 =
* Speedy sender setup now separates the contracted sender object from parcel handover. The contract object is always required for an accurate quote; office handover adds the selected drop-off office without removing the contract client from the API request.
* Client objects returned by “Check connection” are cached immediately and shown as a searchable list with object name, address, contact and email. A missing cache no longer falls back to an unverified manual Client ID field.
* The sender-office selector contains only regular Speedy offices that explicitly allow parcel drop-off, and its labels include the full office address.
* “Update sender details” now bypasses stale contract and office caches and validates the complete configured sender origin.

= 3.0.54 =
* The Speedy contract panel no longer reports an entitlement it never received as one your contract refuses. A term Speedy did not answer now reads “not checked” instead of “not in your contract”, and the panel says which contract it read and when.
* The cash-on-delivery premium is labelled for the processing type actually in use. On a cash contract it is no longer called a postal money transfer fee — the same component carries both meanings, and the old wording showed a fee for a service the panel declared absent.
* The panel reports the contract number, the countries international cash on delivery covers, and any special delivery requirements the contract carries.
* Switching on the administrative fee against a contract that does not carry it is called out. Speedy ignores the flag and the price does not change, which until now looked like a broken setting.

= 3.0.53 =
* The courier name now survives later shipping-label filters. 3.0.52 fixed the Checkout block but not the cart: the name was read back from the shipping rate itself, where earlier callbacks had already changed the wording. The name is now taken from a value the shipping method publishes for itself, which no filter can reach or rewrite.
* Checkout renderers can read that untouched courier name from the Store API as `method_title`, alongside the existing `price_state`.
* Shipped namespaces, selectors and shipping labels follow the BG Commerce Suite identity contract. A release test enforces the contract.

= 3.0.52 =
* The courier name is no longer rewritten in the shipping row. A line that read "Доставка със Спиди: Безплатна доставка" now reads "Доставка със Спиди", with the delivery state shown where the price is — once, instead of twice.
* A delivery that is free because the order passed your free-shipping threshold still reads "Free" in the price, and a delivery that has no price yet because no office, locker or address has been chosen still reads "Awaiting calculation" there.
* A theme that writes its own "free" wording over a not-yet-calculated delivery still has that claim removed, so a price that is not known is never shown as free.

= 3.0.51 =
* The courier line no longer prints raw `<span …>` tag text next to the courier name in Cart and Checkout Blocks. The delivery state — "Awaiting calculation" or "Free shipping" — is now sent as plain text, which every checkout surface can display.
* A theme that replaces a zero shipping cost with its own "free" wording no longer leaves two conflicting free labels in the same courier card. The line is rebuilt from the courier title, so exactly one state is shown whichever side the theme writes on.
* Checkout renderers can read the state wording from a public contract instead of parsing it out of the label.

= 3.0.50 =
* Speedy: a shop that had asked for the money-transfer fee to be charged on free shipping no longer has that answer applied to the handling fee as well. The 3.0.49 upgrade copied the old cash-on-delivery-only setting into the one that now governs both fees, so a customer who had earned free shipping could still be billed for delivery. The copied value is removed on upgrade; a choice made after installing 3.0.49 is kept.
* Speedy: the contract terms are read from the endpoint the API actually serves, so the settings screen shows your real entitlements instead of nothing.
* Speedy: the fee rates learned from a priced order are read under the names Speedy really returns, so the money-transfer rate from your contract is shown and used. A component your contract does not charge is no longer recorded as a fee of zero, which had silenced a handling fee set by hand.

= 3.0.49 =
* Two concurrent "Create shipment" clicks can no longer both reach the courier: the per-order lock is now an atomic conditional insert instead of `add_option()`, which WordPress implements as read-then-write and which never refused a competing caller.
* The lock is also held while the waybill is saved to the order, closing the window in which a second request saw no label yet and created a second real shipment.
* Econt deliveries to an office are no longer blocked after a location sync: the shipment-type guard was comparing the office's list of SERVICES against the shipment TYPE being sent, two different vocabularies that only overlap by accident.
* One key, one default: a setting's default now comes from the field that declares it, so the admin screen, the courier payload and the order record can no longer disagree. This corrects the Speedy courier-service payer, the PMT fee payer and the default weight used for the Econt courier request.
* Both "Create shipment" paths — the order panel and the orders list — now build the same order record through one shared builder, and every courier states its own payment semantics instead of Core guessing them.
* Speedy: the declared-value insurance premium and the packaging fee are always billed to the sender. They previously followed the courier-service payer, so choosing "Recipient" silently charged the customer more at the door than the order said.
* Speedy: the payment block is read back after creation, so a payer the contract silently replaced is reported instead of passing as success.
* Speedy: a handling and preparation fee can be recovered from the customer with custom pricing, and the PMT and handling fees are learned from your Speedy contract when Speedy prices the order. The settings screen now shows the terms and rates your contract actually carries.
* BOX NOW: cash on delivery above the contract limit, or on an account without the entitlement, is now refused at checkout with a clear reason instead of accepted and then rejected when the waybill is created.
* BOX NOW: a repeated webhook message is recognised however long ago it arrived, and with or without the optional fields it carries; events older than the freshness window are ignored rather than replayed onto the order.
* A courier event no longer moves an order out of Refunded, Cancelled or Failed. The event is still recorded, with a note explaining which status change was declined.
* A courier's internal 5xx failure is reported as a temporary outage instead of quoting its infrastructure error to the merchant. Validation messages from 4xx responses are unchanged.
* Checkout: remembering the customer's last selected address is now a working setting in Checkout, applies to every courier, and clears what was already stored when switched off. It replaces three Econt fields that changed nothing.

= 3.0.48 =
* Pending BGCS courier rates no longer appear as free or 0.00 before an office, locker or delivery address has produced a real price; Classic Cart/Checkout and WooCommerce Cart/Checkout Blocks display "Awaiting calculation" instead.
* The pending/free distinction is enforced by the shared shipping layer for Speedy, Econt, BOX NOW and Pigeon Express, including the Store API/Blocks path and themes that rewrite every zero-cost rate as "Free".
* Genuine free shipping remains a separate semantic state and is shown as free only when Core actually resolves the configured free-shipping rule; positive payment-service surcharges are never hidden.

= 3.0.47 =
* Unavailable courier services now remain visible as non-selectable information cards in Classic Checkout and Checkout Blocks instead of disappearing or becoming zero-cost rates.
* Speedy calculation responses now select the requested service, preserve per-service API errors and distinguish missing, malformed, non-positive and wrong-currency prices.
* BOX NOW physical validation now names the affected product or variation and reports its normalized dimensions or weight against the inclusive 36 × 45 × 60 cm and 20 kg limits.
* A public, diagnostics-safe availability contract is available to BGCS Flow and other checkout renderers through the `bgcs3_shipping_availability` filter and the session-scoped REST endpoint.

= 3.0.46 =
* Module and integration enable/disable switches now persist immediately through authenticated AJAX; a separate Save changes click is no longer required for module state.
* The same AJAX switch contract is used by module headers, built-in integrations and installed compatible add-ons, with rollback on failure and no-JavaScript admin-post fallback.
* Shipping-cache invalidation is now shared by both normal settings saves and immediate module-state changes.

= 3.0.45 =
* Clean Checkout now hides native WooCommerce address fields only while every selected shipping package uses a BGCS-owned rate.
* Switching to Flat Rate, Local Pickup or any other shipping method restores native region/state and other managed WooCommerce fields together with their required state.
* Mixed-package checkout no longer lets a BGCS rate suppress fields needed by an external shipping package.

= 3.0.44 =
* Shipping rates now expose semantic `pending`, `calculated` and `free` price state metadata.
* Pending selection text is no longer appended to the shipping method title.
* Free-shipping state no longer mutates the raw courier method title.

= 3.0.43 =
* Speedy PMT: when the merchant enables the contract PMT fee, always add exactly the greater of the configured percentage amount and configured minimum; the courier return-amount payer can no longer suppress this customer surcharge.
* Speedy PMT: use merchandise + non-PMT fees + base delivery as the deterministic PMT base for both API and custom pricing, excluding the PMT fee itself.
* Custom pricing: allow bounded fixed-price rules to use the explicitly configured default package weight when products have no weight, instead of silently falling back to courier API pricing.
* Custom pricing: Custom prices mode is now authoritative; if no configured rule matches the selected delivery type, weight and order value, checkout reports the missing rule instead of silently calling the courier API.
* Checkout: provisional BGCS rates now show the existing "select office/locker/address" prompt instead of being presented as free delivery before a destination is completed.

= 3.0.42 =
* Speedy PMT: always calculate the sender-paid customer surcharge as at least the greater of the configured percentage and minimum amount, including when the Speedy API returns a lower sender amount.
* Speedy PMT: preserve a higher sender amount reported by Speedy and never recover a PMT component assigned to the recipient or a third party.

= 3.0.41 =
* Checkout map: fix WooCommerce Blocks styling so the registered BGCS stylesheet is actually enqueued through the IntegrationInterface initialization flow.
* Checkout map: give the shared Leaflet office/locker container an explicit responsive height in Blocks and Classic Checkout, so the map is visible when the Office map setting is enabled.
* Checkout map: preserve direct marker selection for offices and automated parcel terminals while keeping the list fallback and the merchant ON/OFF setting authoritative.

= 3.0.40 =
* Speedy: restore the valid Recipient courier-service payer flow for live API pricing + COD; WooCommerce still shows the delivery price, while BGCS removes the recipient-paid courier component from the COD amount so the customer is charged exactly once.
* Speedy: persist the effective checkout payer and re-calculate recipient-paid transport immediately before shipment creation; block create if the live Speedy price/currency no longer matches the WooCommerce shipping snapshot.
* Tracking: add Econt bulk status synchronization through one getShipmentStatuses request per chunk and preserve provider operational/accounting facts on the WooCommerce order.
* COD payouts: add safe automatic background reconciliation for Econt, Speedy and Pigeon where the courier exposes a payout API; waybill, amount, currency and payout date must all match before COD is marked paid.
* COD payouts: add per-courier enable/interval settings, mismatch diagnostics in the order panel, and keep payout synchronization independent from WooCommerce refunds/payment-status changes.
* Accounting: use the shipment COD snapshot for payout reconciliation so recipient-paid courier transport and per-order COD overrides do not create false amount mismatches.

= 3.0.39 =
* Checkout: enforce validated BGCS shipping rates in Classic Checkout and Store API/Blocks so failed courier quotations cannot become payable zero-cost deliveries.
* Checkout: persist incomplete courier selections as drafts, invalidate incompatible office/address state on courier/type changes, and reject stale office/locker selections against the current synced city/directory.
* Blocks: use the official Store API extension cart-update callback when available, keep the nonce-protected REST fallback for older compatible WooCommerce versions, and surface courier warnings beside the selected rate.
* Core: add shared package-dimension and financial-invariant helpers; explicit package rows remain authoritative and product dimensions are used only when a single physical unit makes the inference lossless.
* Econt/Speedy/Pigeon: use the shared package resolver for single-unit shipment dimensions and block incomplete explicit package rows before destructive shipment creation.
* Speedy/Pigeon: make Sender the safe courier-service payer default and block configurations that would charge delivery both in WooCommerce and again directly by the courier.
* BOX NOW: extend physical eligibility checks with per-item weight, verify explicit parcel compartments/values, and keep parcel commercial values consistent with the order invoice value.

= 3.0.38 =
* Checkout: never expose a complete BGCS courier selection as a zero-cost rate when the live courier quotation failed; classic checkout also revalidates the selected rate before order creation.
* Checkout: normalize incompatible delivery selections so stale office/locker/address data cannot survive a delivery-type or invalid-country change.
* Econt: carry provider quotation warnings through the BGCS price result and surface them with the selected shipping rate for merchant/customer visibility.
* BOX NOW: reject products that exceed the documented locker envelope, enforce the 20 kg per-parcel limit, and derive a safe minimum compartment size from known WooCommerce product dimensions.
* Core: add a shared physical package-dimension resolver that preserves explicit package rows as authoritative and uses product dimensions only as safe eligibility/lower-bound data.

= 3.0.37 =
* Econt: prevent double-charging delivery by assigning courier-service charges to the sender whenever BGCS includes shipping in the WooCommerce order.
* Econt: remove the unsafe recipient courier-payer setting from the merchant and per-order UI; WooCommerce remains the source of truth for the customer-facing delivery amount.
* Econt: block shipment creation if provider validation still reports an additional receiverDueAmount, so a customer cannot be charged once in WooCommerce and again by Econt.
* Econt: retain Econt totalPrice/senderDueAmount/receiverDueAmount from validation in label metadata for financial diagnostics.

= 3.0.36 =
* Econt: validate explicit multi-package rows and preserve the provider's floating-point PackElement dimensions/weights instead of silently rounding them.
* Econt: stop inventing placeholder sender/recipient phone numbers; SMS now requires a real recipient phone for shipment creation.
* Econt: validate synced office shipment-type capabilities and block Review + Test for Econt Drive before label creation.
* Econt: send standalone courier-request times as the documented JSON unix timestamps and keep PACK 12 shipment service separate from requestCourier motorbike-stand semantics.
* Econt: normalize the documented short delivery statuses, including cancellation and return states, into the shared BGCS tracking/status model.
* Econt: align PaymentReport parsing with the official report contract and remove the undocumented one-month date-range restriction.
* Econt: use only explicit APS/MPS flags for locker classification and retain office Drive/shipment-type capabilities from synchronized nomenclatures.

= 3.0.35 =
* Econt: add configurable shipment types and serialize complete per-package dimensions/weights to the official ShippingLabel.packs contract.
* Econt: add official PACK 5/6/8/9/10/12 and refrigerated-pack service counts globally and per order.
* Econt: add standalone ShipmentService.requestCourier / getRequestCourierStatus workflow with prepared-shipment attachment, status refresh and duplicate-request protection.
* Econt: validate sender-profile-bound pickup addresses and instruction templates before shipment creation.
* Econt: expose all 3.0.34 sender/additional-service fields correctly in the shared Courier workspace and remove stale Econt workspace field references.
* Econt: preserve courierRequestID returned by createLabel when pickup is requested together with shipment creation.

= 3.0.34 =
* Econt: validate and resolve COD/declared-value currency so an empty currency is never sent.
* Econt: expose official delivery receipt, digital receipt, goods receipt, two-way shipment, delivery-to-floor and email-on-delivery services globally and per order.
* Econt: identify Postal Money Transfer (PPP) and express payout capabilities directly from account COD payout agreements.
* Econt: load account instruction templates into settings dropdowns after data sync.
* Econt: include WooCommerce order number in ShippingLabel and stop silently truncating shipmentDescription.
* Econt: support sender handover either at an Econt office or by courier pickup from an address returned by the selected Econt client profile.
* Econt: block delivery-to-floor for office/Econtomat destinations before shipment creation and reject stale COD/PPP payout agreements.

= 3.0.33 =
* Removed the developer guide, code samples and "Create an add-on" entry point from the customer-facing Add-ons admin screen.
* Kept the Add-ons catalog merchant-facing while retaining Module API 1.1 and `bgcs3_api_ready` as the public runtime contract for independent add-on plugins.
* Developer implementation instructions are maintained separately from the distributable Core plugin.

= 3.0.32 =
* Added a separate promotional Add-ons catalog while preserving the built-in module controls.
* Added BGCS Flow as the first catalog product entry, with installed/active detection and Module API metadata.
* Added an in-plugin Add-on Developer Guide with a starter plugin, module example, lifecycle rules and release checklist.
* Added the public `bgcs3_api_ready` action so independent add-ons can register through `Addon\Bootstrap` without relying on plugin load order.
* Added the `bgcs3_addon_catalog` metadata filter for built-in and independently developed catalog entries.

= 3.0.31 =
* Removed the unreachable post-create shipment mutation layer left behind by the manual-only policy: Speedy full/property updates, Speedy/BOX NOW quick corrections, the BOX NOW delivery-request PUT path, the unregistered order-panel quick-update handlers and unused Econt update endpoint constants are no longer present in runtime code.
* Kept the Speedy/Econt legacy update entry points fail-closed for stale cached admin assets; they still cannot call a courier update API.
* Corrected the Econt sender contact mapping to the documented `ShippingLabel.senderAgent` object. The previous `senderClient.contactName` property is not part of Econt's ClientProfile contract.

= 3.0.30 =
* Pigeon Express now declares its own description limit of 200 characters, so the order screen shows the right number for whichever courier is selected. The same Core field maps to a 200-character limit at Pigeon and a 100-character one at Speedy.

= 3.0.29 =
* Speedy's description limit is now known rather than assumed: the courier's own validation refuses more than 100 characters. The order screen states that limit on the field, so an over-long description is prevented while typing instead of surfacing as a refusal after pressing Create.
* A description the merchant typed is still sent whole and never trimmed — if it is too long the shipment is refused with the courier's own message, not silently shortened.
* The description generated from product names when the field is left empty is shortened to fit instead. Three ordinary product names already reach about 85 characters, so refusing to create a shipment over text nobody wrote would have been wrong.
* Couriers now declare their own documented field limits, so Core no longer assumes one applies everywhere.

= 3.0.28 =
* Speedy shipment descriptions are no longer cut at 100 characters. That limit was invented: Speedy's machine-readable schema declares no maximum length for the shipment description, and its documentation states a length only for the shipment note. A 150-character description was reaching the courier as exactly 100, with nothing shown to the merchant.
* This also affected orders where nobody typed a description: the text generated from the product names goes through the same path and crosses 100 characters on any sufficiently large order.
* The post-creation readback now reports when the courier itself shortens the description, so removing our own limit cannot replace one silent truncation with another. Formatting differences are still ignored — only a genuine shortening is reported.

= 3.0.27 =
* Once a shipment label exists, the order screen no longer shows any editable shipment settings, for every courier. Editable fields next to a live shipment implied an edit BGCS deliberately never performs. Change a generated shipment in the courier's own system, or remove it here and create a new one — the delivery and shipment values saved for the order are kept and reused.
* The shipment editor is now pre-creation only, so the misleading "Edit shipment" section and its Save order settings button are gone.
* Bulgarian translation catalogue completed: all 1257 translatable strings are translated, including every string added in 3.0.26.
* Added tools/i18n.php, a self-contained POT/PO/MO toolchain, so translations no longer depend on wp-cli or gettext binaries being installed.

= 3.0.26 =
* Shipment editing policy is now manual-only: BGCS stores order-specific settings but never edits, cancels or recreates a courier shipment by itself. Apply new values by cancelling the current shipment and creating a new one.
* Speedy and Econt no longer advertise in-place shipment updates. Their update entry points fail closed and point at the manual flow, so a stale cached admin script cannot reach a real provider edit.
* Speedy shipment creation is now checked against the provider contract before anything is sent: the destination-aware service allowance matrix from /services/destination, then POST /validation/shipment. A service or additional service that the account and destination do not permit is refused before create instead of failing silently.
* Speedy reads every created shipment back from /shipment/info and warns when a requested optional service was not applied. The shipment is never cancelled automatically; the administrator decides.
* Econt validates the payAfterAccept/payAfterTest response instead of treating an ignored request as success, and Partial delivery now requires its companion structures before it can be sent.
* Pigeon dynamic account services honour their declared input_type (checkbox, text, select) rather than being forced to boolean, and sender_name/sender_phone/sender_email are treated as reverse-shipment semantics instead of being attached to normal outgoing shipments.
* New opt-in diagnostics: when enabled in General settings, each shipment creation records what was requested, what the courier allowed for the destination, what was validated, what was sent and what came back. Visible read-only under Details in the order. Credentials are never recorded and customer data is stored only as a length preview.

= 3.0.25 =
* Unsupported full-update couriers now show “Save order settings” instead of a misleading Update shipment label action. Saved overrides remain order-local and survive explicit shipment cancellation so they can be used when creating the replacement label.
* Existing-shipment Details help text now distinguishes real in-place updates from order-only saved values for BOX NOW/Pigeon.
* Econt shipment updates now fail closed when checkPossibleShipmentEditions cannot confirm that the specific shipment is still editable; no provider update is sent in that state.
* Speedy full-update verification now also confirms that an old pickup office/locker was actually cleared when switching the recipient to an address.
* Speedy late quick correction is limited to COD and is reported successful only after ShipmentInfo readback matches the requested amount. Recipient phone remains editable through the documented full shipment update while Speedy permits it; BGCS does not claim an unverified post-pickup phone property update.

= 3.0.24 =
* Shipment editing now uses only courier-supported in-place update APIs. BGCS never silently creates a replacement shipment when an edit is unsupported or refused.
* Speedy: full shipment updates use the same payload as creation and are read back from ShipmentInfo before BGCS reports success, including Review / Review and test (OBPD), COD, declared value/fragile, payer, Saturday/deferred delivery, parcels and return services.
* Speedy: explicit false/zero values are sent for mutable flags so an update can reliably disable a previously enabled option.
* Econt: added per-order overrides for shipment defaults, pre-checks allowed shipment editions, uses LabelService.updateLabel, and blocks Review/Test for Econtomat destinations where Econt documents the service as ignored.
* Econt: courier warnings returned during label creation are retained on the order instead of being silently discarded.
* BOX NOW: only Allow return is editable in place, matching the Partner API. Other changes now clearly require cancel/remove + create new; no automatic replacement is created.
* Pigeon Express: sender name/phone/email and pickup overrides are included in shipment creation; post-create changes clearly require cancel + create new, matching the official WooCommerce integration guidance.
* Per-order shipment overrides are stored only on the WooCommerce order and never overwrite the courier module defaults.
* Order UI: removed the Actions tab; tracking refresh/open-at-courier actions are in Tracking and Resend shipment email is in Shipment label.
* Fixed a blank package-editor row being saved as an additional parcel.

= 3.0.23 =
* Fixed shipment-editor persistence so every visible delivery field is sent on both create and update: city, postcode, office/locker, street, number, block, entrance, floor, apartment and note.
* Added editable delivery fields to existing shipments; “Update shipment label” now persists both destination and waybill overrides before calling the courier.
* Speedy review/test (OBPD) now always sends the required return service and payer, falling back safely to the current Speedy service when no dedicated return service is selected.
* Added documented Econt LabelService.updateLabel support and corrected Econt payload field names/locations for dimensions, COD invoice-before-payment, previous shipment references, envelope numbers, priority windows and courier pickup requests.
* Removed Econt/Pigeon controls that had no valid provider payload counterpart and resolved Pigeon COD/declared-value overrides through the actual shipment services payload.
* BOX NOW/Pigeon full updates use safe recreate-then-cancel semantics; BOX NOW now reserves the next stable shipment reference before recreation and rolls it back if creation fails.
* Improved payer/review-test inheritance so leaving a per-order field untouched uses the courier-specific settings instead of silently applying Speedy defaults to other couriers.
* Updated Bulgarian translations and translation templates for the new shipment-editor and Econt fields.

= 3.0.22 =
* Restored shipping-rate detection, courier metadata lookup, city/office/address flow, Leaflet map rendering, picker helpers and saved-selection hydration that were accidentally removed during the 3.0.21 cleanup.
* Kept the intended frontend SelectWoo cleanup and added regression assertions for the Classic Checkout courier-selector runtime.

= 3.0.21 =
* Removed retired Classic Blocks build artifacts and stale frontend SelectWoo code/dependencies.
* Consolidated Speedy onto the same task-based courier settings renderer and save-scope contract used by Econt, BOX NOW and Pigeon Express.
* Removed superseded private order-panel, COD helper and BOX NOW duplicate-pricing code while keeping public/add-on contracts intact.
* Wired the idempotent legacy static-price migration into the versioned upgrade path for Speedy, Econt and Pigeon Express.
* Registered the Blocks RTL stylesheet through WordPress RTL style metadata.
* Simplified the unified order shipment editor by removing unreachable legacy accordion branches.

= 3.0.20 =
* Restore the complete saved courier selection after checkout reload, including the green Selected confirmation and hidden checkout payload.
* Revalidate restored office/locker IDs against the freshly loaded provider location pool and clear stale selections safely.

= 3.0.19 =
* The “Order status after shipment label creation” selector now loads every WooCommerce order status dynamically, including statuses registered by custom store code.
* Removed the previous hardcoded Processing/Completed/On hold limitation.
* Post-label automation validates the saved target against the statuses currently registered in WooCommerce before changing the order.

= 3.0.18 =
* English is now the source/default plugin language and a complete Bulgarian gettext translation is bundled.
* Added POT, Bulgarian PO/MO catalogs and one stable `bg-commerce-suite` text domain for standard WordPress localization workflows.
* Removed remaining Bulgarian UI source strings from PHP/JavaScript fallbacks; parser aliases required for courier/CSV compatibility remain intentionally unchanged.
* Added translation-ready checkout/block labels and BOX NOW widget labels through the shared localized frontend data.
* Integrated the BGCS shipment email with WooCommerce's native email preview hooks so courier, waybill and tracking placeholders receive representative preview values instead of appearing blank.
* Kept the shipment email on the standard WooCommerce `WC_Email` template/hooks, sender, transport and editor/settings stack.

= 3.0.17 =
* Unified the order shipment workspace before and after label creation.
* Courier shipment panel is open on first paint; no courier auto-open preference is required.
* Before label creation, Overview exposes editable delivery data plus one-click Create/Save actions.
* After label creation, the Label tab is first and active on page load with immediate PDF preview.
* Print/download/open/delete actions now live together inside the Label tab.
* Waybill create/update editors render inline inside shipment tabs instead of nested accordion panels.

= 3.0.16 =
* Added courier-normalized tracking policy per courier, provider-specific/unmapped status diagnostics, a native WooCommerce shipment email and a compact tabbed shipment panel.

= 3.0.15 =
* BOX NOW verified webhook events are persisted immediately instead of being only a trigger for polling.
* `data.time` is used to ignore stale out-of-order webhook events; duplicate message IDs are idempotently ignored.
* Valid pushed events enter the same canonical tracking timeline/state policy used by polling.
* Provider API refresh remains a secondary enrichment step and cannot erase a newer webhook event from the timeline.
* BOX NOW Diagnostics shows the 20 most recent verified webhook events and whether they were applied, stale or duplicate.
* Webhook transport preserves intentional HTTP 202 results for unknown/not-our orders.

= 3.0.14 =
* COD Reports received its own enable/disable switch.
* Added direct Speedy COD payout reports through the official payments endpoint.
* Added direct Econt COD payout reports through PaymentReportService.
* Kept Pigeon Express payout reporting; BOX NOW is not presented as a payout source without a confirmed payout endpoint.
* Courier API errors are surfaced correctly in COD Reports feedback.

= 3.0.13 =
* BOX NOW Diagnostics shows the exact public webhook URL with a copy action.
* BOX NOW Webhook Secret moved to Diagnostics.
* Webhook diagnostics show the HTTP method, HMAC-SHA256/datasignature contract and whether a secret is configured without exposing it.

= 3.0.12 =
* BOX NOW pricing now has one source of truth: weight ranges after an optional free-shipping threshold.
* Removed the duplicate generic own-price model from BOX NOW UI/runtime pricing.

= 3.0.11 =
* Plugin directory is permanently `bg-commerce-suite/` and the main plugin file is `bg-commerce-suite.php`.
* Legacy conflict detection no longer treats the current Core path as an earlier installation.

= 3.0.10 =
* Added conservative legacy Action Scheduler cleanup for known recurring BGCS 2.x runtime jobs.
* Unified remaining standalone Speedy translation-domain strings under `bg-commerce-suite`.

= 3.0.9 =
* Added the shared courier readiness assistant for Speedy, Econt, BOX NOW and Pigeon Express.

= 3.0.8 =
* Added context-aware admin settings: inactive branches are hidden, disabled, excluded from validation and preserved on save.
* BOX NOW pricing shows only the active pricing model.

= 3.0.7 =
* Clean Checkout displays one visible BGCS city field and synchronizes it into the native WooCommerce city/postcode data model before validation.

= 3.0.6 =
* Public branding is fixed to BG Commerce Suite.
* Added fail-closed tab-local saves, REST request budgets, secret/PII log redaction, conditional Leaflet loading and performance diagnostics.

= 3.0.5 =
* Reduced admin asset loading and added Save + Connection Check flows for courier account settings.

= 3.0.0 =
* Introduced the unified `bg-commerce-suite/` Core runtime with namespace `BgCommerce3\\`, five internal modules and conservative legacy migration/conflict protection.
