# BGCS Catalog Integration

## Scope

BG Commerce Suite is a read-only consumer of the public schema v1 metadata feed:

```text
GET https://error.bg/wp-json/error-catalog/v1/feed
```

The integration presents products, local installation/version state and active
promotions in the Dashboard extensions area. It is not an update, download,
activation, licensing or telemetry system.

The service is optional and disabled by default. Only a site administrator with
`manage_options` can opt in under **WooCommerce → BG Commerce Suite → General
settings**. Without that explicit setting, no catalog HTTP request is made.

## Data flow

1. After opt-in, Action Scheduler requests the feed hourly. An administrator with
   `manage_options` can also request a nonce-protected refresh.
2. The WordPress HTTP API enforces HTTPS, an approved `error.bg` host, a
   five-second timeout, no redirects and a 256 KiB response limit.
3. A `200` body is decoded, validated and normalized as schema v1.
4. Product/campaign cross-references and URLs are validated before persistence.
5. Only the complete validated payload replaces the non-autoloaded
   `bgcs3_catalog_feed_state` option.
6. A stored ETag is sent as `If-None-Match`; `304` retains the stored payload.
7. Catalog rendering reads only the local option and never performs HTTP work.

The request uses `BG-Commerce-Suite-Catalog/1` as a generic User-Agent. It sends
no store URL, plugin inventory, license, customer, order, email, WooCommerce or
credential data.

The server necessarily receives the connecting server IP address and the generic
User-Agent. The admin setting links to the public privacy notice and catalog
service terms.

## Cache and failures

The persistent option is the last-known-good catalog. HTTP errors, timeouts,
unexpected statuses, empty/oversized bodies, malformed JSON, unknown schemas,
invalid records and invalid cross-references update only the safe error/status
metadata; they never replace or remove the good payload.

While enabled, an expired or old payload remains available during an outage. Diagnostics mark
it as `stale` or `expired`, and the scheduled/manual refresh path keeps trying to
replace it. Uninstall removes the option through the existing `bgcs3_` cleanup;
deactivation and uninstall unschedule `bgcs3_sync_product_catalog`. Opting out
also removes the recurring action, interval marker and cached promotional payload.

## Security boundary

Remote values are plain presentation metadata. The validator restricts types,
lengths, enumerations, timestamps, SemVer values, plugin basenames and HTTPS
URLs. Product and campaign URLs are limited to `error.bg` and approved
subdomains. HTML is stripped at input and every value is escaped again for its
output context.

There is no bundled product record: names, descriptions, versions, prices,
statuses, links and plugin basenames are sourced from the validated feed. No
catalog value is passed to `include`, `require`, plugin installers, download
APIs, activation APIs, update APIs or script/style enqueue functions.

## Campaign selection

A campaign is active only when its UTC interval satisfies:

```text
starts_at <= now < ends_at
```

Campaigns are sorted by priority descending and id ascending. The first active
campaign for a product wins, which gives deterministic overlap and tie behavior.
The normal price remains visible beside the selected campaign price.

## Installed detection

`Installed_Product` loads one snapshot from WordPress `get_plugins()` for the
catalog render. It uses the validated plugin basename when supplied; otherwise
the product id may resolve one unambiguous installed plugin directory with the
same slug. A registered module is associated only when its local class file is
owned by that directory. Versions are compared with `version_compare()` and
reported as not installed, up to date, update available, local version newer or
version unknown. Detection is read-only and never starts an update.

## Diagnostics

Catalog diagnostics are hidden during normal administration. They are exposed
only to an administrator in an explicit `WP_DEBUG` request with
`bgcs_debug=1`, and contain the endpoint, schema, revision, ETag, refresh
timestamps, feed timestamps, product/campaign counts, cache status and the last
safe error code. Network failures are shown inside BGCS admin rather than as
persistent global notices.

## Extension points

- `bgcs3_catalog_feed_url` changes the feed endpoint. The final URL must still
  pass the HTTPS/host allowlist.
- `bgcs3_catalog_allowed_hosts` adds exact approved hosts. The default includes
  `error.bg`, `www.error.bg` and `*.error.bg` through the parent-host rule.
- `bgcs3_addon_catalog` adjusts final presentation metadata after local identity
  protection has been applied.

These filters do not grant runtime registration or code execution. Add-on runtime
registration remains owned by the BGCS Module API.
