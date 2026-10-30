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

The payload is a JSON array of entries signed with HMAC-SHA256 over the **raw request body** using the webhook secret (`X-Signature` header). Each entry carries `url`, `extId` (optional: the shop's internal product ID, null for products synced before external IDs existed), `bestPriceAmount`, `bestPriceCurrency`, `timestamp` and `nonce`; the plugin rejects entries with a missing/stale timestamp (> `PRICEE_WEBHOOK_MAX_AGE`) or an already-used nonce (replay protection; a nonce shared by all entries of one batch is consumed only once per payload).

Any change to this format is a cross-repo contract: the sender lives in `../app/src/Service/WebhookService.php` and the PrestaShop integration validates the same signature — keep changes backward compatible or coordinate all three.
