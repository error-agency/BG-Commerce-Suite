# Error Web Agency catalog service terms

Last updated: 5 September 2026

## Service

Error Web Agency provides the public metadata endpoint used by the optional BG
Commerce Suite product catalog:

`https://error.bg/wp-json/error-catalog/v1/feed`

The service supplies presentation metadata such as product names, descriptions,
versions, availability, prices, links and time-limited promotions. It does not
deliver executable plugin code and cannot install, activate or update software
on a WordPress site.

## Activation and requests

The catalog is disabled by default. A site administrator must explicitly enable
it in BG Commerce Suite. While enabled, the site may request the feed hourly and
when an authorized administrator selects **Refresh catalog**.

The request contains a generic `BG-Commerce-Suite-Catalog/1` User-Agent and may
contain a standard `If-None-Match` cache validator. BG Commerce Suite does not
add the store URL, plugin inventory, customer or order data, credentials, email
addresses or cookies. Normal Internet operation exposes the connecting server IP
address to the service infrastructure.

## Availability and acceptable use

The catalog is provided for product discovery and may change or be unavailable.
It is not an update, licensing, activation or telemetry service. Automated use
must remain within the frequency and response-size limits implemented by BG
Commerce Suite. Attempts to bypass security controls, overload the endpoint or
use it to distribute executable code are not permitted.

Disabling the catalog stops future scheduled requests and removes locally cached
catalog offers. The courier integrations and other plugin functionality continue
to operate independently.

## Privacy and changes

Data handling is described in the [BG Commerce Suite privacy notice](PRIVACY.md).
Error Web Agency may update these terms and the catalog schema. Incompatible
schema responses are rejected by the plugin and cannot replace its last valid
cache.

Questions about the service can be directed to Error Web Agency through
<https://error.bg>.
