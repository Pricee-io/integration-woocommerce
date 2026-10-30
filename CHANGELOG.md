# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
