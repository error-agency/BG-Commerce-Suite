# BG Commerce Suite privacy notice

Last updated: 6 September 2026

This document describes the data connections made by BG Commerce Suite and is
intended to help a WordPress site owner prepare an accurate site privacy policy.
The merchant remains responsible for choosing an appropriate legal basis and for
adapting the disclosure to the modules actually enabled on the store.

BG Commerce Suite contains no general analytics or telemetry system.

## Error Web Agency product catalog

The optional product catalog is enabled by default when no preference has been
saved. Previously saved opt-outs are preserved, and an administrator can disable
it at any time. While enabled, the WordPress server requests public product metadata from
`https://error.bg/wp-json/error-catalog/v1/feed` hourly and during an authorized
manual refresh.

The request uses a generic `BG-Commerce-Suite-Catalog/1` User-Agent and may send
an `If-None-Match` cache validator. It does not add the store URL, installed
plugin inventory, customer or order data, credentials, email addresses or
cookies. The service infrastructure necessarily receives normal connection data,
including the connecting server IP address and request time. This data may be
processed in standard operational and security logs only as needed to operate
and protect the service.

Validated product metadata is cached in the WordPress database without autoload.
Disabling the catalog removes that cache and its recurring schedule. See the
[catalog service terms](CATALOG-SERVICE-TERMS.md).

## Courier services

When a merchant configures and enables a courier module, BG Commerce Suite
connects to that courier to provide the selected operation. Depending on the
operation, information can include sender and recipient names, telephone numbers,
email addresses, delivery addresses or pickup-point identifiers, parcel contents
and dimensions, order references, declared value and cash-on-delivery amounts.
Credentials entered by the merchant are sent only to the configured courier API.

Provider information:

- Speedy: <https://www.speedy.bg/bg/system-integration> · [privacy](https://www.speedy.bg/public/en/gdpr?re=ib) · [terms](https://www.speedy.bg/public/index.php/bg/terms-and-conditions-cookies)
- Econt: <https://www.econt.com/> · [privacy](https://www.econt.com/econt-express/privacy-policy) · [terms](https://www.econt.com/econt-express/terms-of-use)
- BOX NOW: <https://boxnow.bg/> · [privacy](https://www2.boxnow.bg/personal-data-processing-notice) · [terms](https://t.boxnow.bg/en/terms-of-use-for-shipping-services)
- Pigeon Express: <https://pigeonexpress.com/> · [privacy](https://pigeonexpress.com/privacy) · [terms](https://pigeonexpress.com/terms)

## Maps and location widgets

If the merchant enables map-based pickup-point selection, the visitor browser
may request OpenStreetMap tiles. The tile service receives normal connection
data such as the visitor IP address and browser headers. OpenStreetMap policies:
[privacy](https://osmfoundation.org/wiki/Privacy_Policy),
[copyright](https://www.openstreetmap.org/copyright) and
[tile usage](https://operations.osmfoundation.org/policies/tiles/).

BOX NOW locker selection can load the courier's official map/widget from
`map.boxnow.bg`, which likewise receives the visitor's normal connection data.

## Local storage and removal

The plugin stores its configuration in `bgcs3_*` WordPress options. Shipment,
tracking and payout facts connected with WooCommerce orders may remain in order
metadata so the merchant retains business records. Uninstall removes BGCS-owned
options, transients and scheduled work but intentionally does not erase order and
shipment history.

Questions about BG Commerce Suite can be directed to Error Web Agency through
<https://error.bg>.
