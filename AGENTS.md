# AGENTS.md

Guidance for AI coding agents working in this repository.

## Project

Pricee.io WooCommerce plugin (`pricee/`): syncs WooCommerce products to the Pricee.io platform and updates prices via webhooks. PHP 8.2+ (see `composer.json`), WordPress + WooCommerce.

- `pricee/pricee.php` — main plugin class: settings page, AJAX category sync, REST webhook handler, logging
- `pricee/includes/class-pricee-api-service.php` — Pricee.io API client (bearer login, websites, products)
- `pricee/assets/admin.{css,js}` — admin assets (loaded only on the Pricee settings page)

## Development

All tooling runs through the Docker dev environment (see `Makefile`):

- `make dev` — shell into the dev container
- `make lint` — php-cs-fixer (fix), `make lint_ci` — dry-run check
- `make analyze` — phpstan
- `make package` — regenerate `pricee.zip` for release (`*.zip` is gitignored)

Run lint and analysis before committing.

## Commits

- Do NOT add `Co-Authored-By` trailers or any AI attribution to commit messages.

## Versioning

The plugin follows [Semantic Versioning](https://semver.org/). The version is defined in three places and all must be updated together:

1. `Version:` in the plugin header
2. The `PRICEE_VERSION` constant
3. A new `## [x.y.z] - YYYY-MM-DD` entry at the top of `CHANGELOG.md`

The `Requires PHP:` header must also stay in sync with `composer.json`, and the README's PHP prerequisite with both.

Changelog rules (this is a WordPress plugin, so the changelog is read by merchants, not developers):

- Only user-facing changes: what the plugin user sees or experiences in the WordPress admin or the storefront. Skip CI, tooling, Dependabot, static analysis and internal refactors.
- One short line per change, no implementation details (no function names, no file paths, no "fix X by doing Y" narration).

## Webhook contract

The payload is a JSON array of entries signed with HMAC-SHA256 over the **raw request body** using the webhook secret (`X-Signature` header). Each entry carries `url`, `extId` (optional: the shop's internal product ID, null for products synced before external IDs existed), `priceAmount` (the price to set, as visitors see it), `priceCurrency`, `currentPriceAmount` (the price Pricee last read on the page), `timestamp` and `nonce`; the plugin rejects entries with a missing/stale timestamp (> `PRICEE_WEBHOOK_MAX_AGE`) or an already-used nonce (replay protection; a nonce shared by all entries of one batch is consumed only once per payload).

Prices are applied by `pricee_apply_webhook_entry()`. Writing a price is the critical action: when in doubt, skip the entry and log why. Products are found by `extId` (never falling back to the URL for an unknown one), else by URL. Only the regular price of a published simple product in the shop currency is written, converted from the displayed price with the ratio of its current stored and displayed prices (tax). Products on sale or with a scheduled sale, and prices changed since Pricee read them (`currentPriceAmount`), are skipped. The answer gives `outcomes` (entries per outcome) and `results` (the outcome of each entry, in payload order). Every answer, refused calls included, carries `plugin` (`{"platform": "woocommerce", "version": PRICEE_VERSION}`, through `pricee_webhook_response()`), which the app stores in its webhook history; an accepted call's also gives `platform_version` (`WC_VERSION`) and `runtime` (`php`) with `runtime_version`, kept from refused calls as anyone can read their answer.

Any change to this format is a cross-repo contract: the sender lives in `../app/src/Service/WebhookService.php` and the PrestaShop integration validates the same signature — keep changes backward compatible or coordinate all three.

## End-to-end tests

`make e2e` (`e2e/run.sh`) starts its own throwaway database and WordPress containers (never the dev ones), installs WooCommerce, and tests the upgrade from the last release (`E2E_FROM_REF`, a tag), real signed HTTP webhook calls, the settings page and a sync from the admin in a browser (Playwright, `e2e/sync.mjs`) against a fake Pricee API (`e2e/fake-api.php`, reached through the `PRICEE_API_BASE_URL` constant), then the deletion of the plugin. `.github/workflows/e2e.yml` runs it on the lowest supported PHP and a recent one. When releasing, point `E2E_FROM_REF` in `e2e/run.sh` to the new release tag. The fake API mirrors the app's API for the calls the plugin makes: keep it in line with `../app` and with the PrestaShop and Sylius ones.
