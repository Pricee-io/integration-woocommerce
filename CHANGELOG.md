# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.7.0] - 2026-11-15

- Products are synced to the Pricee website your API key belongs to: create the API key from the Integrations page of your shop's website on Pricee
- The sync stops with a clear message when the API key belongs to another website, or is not tied to a website
- The plugin no longer creates websites on Pricee

## [1.6.0] - 2026-11-15

- Report to Pricee the outcome of each price update, so you can see in Pricee which prices were applied or skipped and why

## [1.5.0] - 2026-11-15

- Follow the updated format of Pricee's webhook calls, required for automatic price updates
- Never overwrite a price you changed yourself: a webhook update is skipped when the product's price changed since Pricee last read it
- Never modify a sale: products on sale or with a scheduled sale are no longer updated by the webhook
- Fix webhook prices being saved tax included in shops entering prices without tax, which added the tax a second time
- Skip webhook price updates in a currency other than the shop's and for unpublished products, and match updates to the product synced with its ID instead of its URL
- Sync large catalogs without timeouts: products are sent in batches with a progress indicator and the number of products in error, and the 500-product limit is removed

## [1.4.1] - 2026-10-30

- Fix webhook batches sharing a single nonce being rejected as replays
- Paginate the Pricee websites API so accounts with many websites are found during sync
- Sync each product only once when the selected categories overlap
- Add a nonce check to the sync AJAX endpoint
- Log to the server error log instead of web-accessible files in the uploads directory

## [1.4.0] - 2026-10-30

- Update product URLs on Pricee during sync when they changed and adopt products synced before external IDs existed, without creating duplicates

## [1.3.0] - 2026-10-27

- Reject webhook payloads without a fresh signed timestamp and an unused nonce (replay protection)
- Validate the received price before writing it and update the sale price instead of the regular price when the product is on sale
- Align the plugin header version with the `PRICEE_VERSION` constant
- Fix the plugin header description (WooCommerce, not PrestaShop)
- Align `Requires PHP` on 8.2 in the plugin header and README (matches `composer.json`)
- Fix admin asset paths to `assets/admin.css` and `assets/admin.js` (removes 404s in the admin)

## [1.0.2] - 2026-03-31

- Minor improvements and lint fixes

## [1.0.1] - 2026-03-10

- Check if the webhook is enabled in the webhook handler
- Log webhook price updates

## [1.0.0] - 2026-03-10

- Initial version: configuration page, category-based product synchronization to Pricee.io, webhook-driven price updates
