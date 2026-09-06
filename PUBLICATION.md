# Public repository publication

The private repository is the working archive. The public repository must start from a reviewed
source snapshot and must not import the private Git history.

## Source boundary

Version 4.6.0 enables the external product catalog by default at the owner's request.
It is not a WordPress.org submission candidate until the external-request consent
policy is reviewed again. The WordPress.org readiness test intentionally blocks
this default; passing packaging or Plugin Check alone does not clear that gate.
Repository-dependent privacy/terms links are hidden from the catalog admin UI;
the notices themselves remain bundled and accurately describe the current behavior.

Run:

```powershell
php tools/build-public-source.php
php tests/test-publication-readiness.php
```

The exporter writes `dist/public-source/BG-Commerce-Suite/` from the explicit allowlist in
`tools/release-manifest.php`. Files outside that allowlist cannot enter the snapshot.

The public snapshot includes runtime source, compiled assets, public documentation and all
applicable licenses. Tests and maintenance tools stay in the internal repository. It excludes:

- private Git metadata and commit trailers;
- `audit/`, internal handoffs, test reports and live acceptance evidence;
- ignored `docs/`, including courier manuals and provider schemas that are not ours to publish;
- deployment helpers, staging domains, local archives, temporary files and release ZIPs;
- credentials, environment files and editor metadata.

## Create the clean public repository

After the exporter and tests pass, inspect the generated directory. Initialize the public
repository inside that directory and create one new initial commit. Do not copy `.git/`, import
branches, push private tags or force-push the private repository into the public remote.

Before publishing, verify:

1. `git log --oneline` contains only the new public history.
2. The repository is created under the intended owner and starts as private for a final review.
3. The current plugin version agrees in `bg-commerce-suite.php`, `readme.txt`, `README.md` and
   `CHANGELOG.md`.
4. `LICENSE`, `legal/THIRD-PARTY-NOTICES.md` and `assets/img/PROVENANCE.md` are present.
5. `php tools/build-zip.php` creates one `bg-commerce-suite/` archive root and the generated ZIP
   passes `php tests/test-publication-readiness.php`.
6. A clean WordPress/WooCommerce installation can install and activate the reviewed ZIP.

## Ongoing contribution boundary

Public commits should contain product source and public engineering documentation only. Real
orders, customer data, provider credentials, private infrastructure, internal audit evidence and
licensed provider manuals stay in the private repository.
