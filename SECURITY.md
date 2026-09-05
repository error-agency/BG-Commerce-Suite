# Security policy

## Supported versions

Security fixes are provided for the latest released BG Commerce Suite version. Merchants should
update to the latest release before reporting a problem.

## Reporting a vulnerability

Do not open a public issue containing credentials, customer information, order data or a working
exploit. Use the repository owner's private security-reporting channel. Include the affected
version, impact, reproduction steps and the smallest safe proof of concept.

Do not test against courier production accounts, create real shipments or modify real orders
without explicit authorization. Redact API credentials, names, addresses, telephone numbers,
emails, order identifiers and tracking numbers from logs and screenshots.

## Scope

BG Commerce Suite processes merchant-supplied courier credentials and customer delivery data in
WordPress/WooCommerce. Reports involving authorization, REST/AJAX permissions, webhook signature
validation, checkout selection integrity, shipment creation, stored labels or diagnostic logging
are in scope. Availability or policy issues in external courier services are outside the plugin's
control but may still be reported when BGCS handles them unsafely.
