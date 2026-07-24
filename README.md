# Feed Forge

**Google Merchant Center integration for PrestaShop 8 & 9.**

Feed Forge syncs your PrestaShop catalog to Google Merchant Center through the
**Google Merchant API**, monitors product approval status, and surfaces
analytics — all from a clean admin interface inside your back office. No third‑party
SaaS, no monthly fees: the module talks to Google directly using your own
OAuth credentials.

> Feed Forge is a free, open‑source module (MIT). If it saves you time, you can
> support development via the in‑module "Buy me a coffee" link.

---

## Features

- **Direct Google Merchant API integration** — products are uploaded to a
  Merchant API *DataSource* that Feed Forge provisions automatically per feed.
  No gRPC extension required (pure REST transport, works on shared hosting).
- **OAuth 2.0 connection** — connect your Google account with your own Client ID
  and Client Secret. Tokens are encrypted at rest (AES‑256‑CBC).
- **Multiple feeds** — configure per‑feed target country / `feedLabel`, language,
  and an optional `offer_id_prefix` to match product IDs already present in your
  Merchant Center account (e.g. `PL123`).
- **Delta sync** — only changed products are re‑sent, detected via SHA‑1 hashing,
  so recurring syncs stay fast.
- **Live product status** — see which offers are approved, disapproved, or
  pending, with per‑product Google issues.
- **Sync rules** — a rule engine to transform or filter which products get sent
  (include/exclude, field overrides, conditions).
- **Category & attribute mapping** — map PrestaShop categories to the Google
  product taxonomy (with one‑click taxonomy import and autocomplete search),
  and map attributes to Google fields (GTIN, MPN, etc.).
- **Promotions sync** — push Google Promotions from PrestaShop cart rules.
- **Shipping** — configure shipping settings sent alongside products.
- **Queue & cron** — background queue processing with a standalone cron endpoint,
  deadline‑aware batching, and a lock to prevent overlapping runs.
- **Analytics dashboard** — sync history and reports (charts via Chart.js).
- **Multilingual** — English base with full Polish translation; built on
  PrestaShop's standard translation system.

## Requirements

| Requirement | Version |
|-------------|---------|
| PrestaShop  | 8.0 – 9.x |
| PHP         | ≥ 8.1 |
| PHP ext     | `bcmath` |
| Composer    | for the `google/auth` dependency |

A Google Cloud project with the **Merchant API** enabled and an OAuth 2.0 Client
(Web application) is required — you provide the Client ID and Client Secret in the
module configuration.

## Installation

1. Copy the module into your shop:

   ```bash
   git clone https://github.com/GajewskiMarcin/FeedForge.git
   # place the inner `feedforge/` folder into your shop's modules/ directory
   ```

   The module lives in the [`feedforge/`](feedforge/) subfolder of this
   repository. Make sure the installed folder is named exactly `feedforge`.

2. Install PHP dependencies (the `vendor/` directory is not committed):

   ```bash
   cd modules/feedforge
   composer install --no-dev
   ```

3. Install the module from the PrestaShop back office
   (**Modules → Module Manager**), or via CLI:

   ```bash
   php bin/console prestashop:module install feedforge
   ```

4. Open **Feed Forge** in the back office (under the *Secret Sauce* menu group)
   and complete the setup below.

## Configuration

1. **Connect Google** — paste your OAuth **Client ID** and **Client Secret**, then
   authorize. Feed Forge stores encrypted access/refresh tokens.
2. **Select Merchant Center account** — enter your Merchant ID.
3. **Create a feed** — set the target country (`feedLabel`), language, and an
   optional offer‑ID prefix; Feed Forge provisions the matching Merchant API
   DataSource automatically on save.
4. **Map categories & attributes** — align your catalog with the Google product
   taxonomy and required fields.
5. **Sync** — run a sync manually, or schedule the cron endpoint for hands‑off
   operation.

### Cron

Feed Forge ships a standalone cron entry point. Set `FEEDFORGE_CRON_TOKEN` in the
module settings, then schedule one of:

```bash
# HTTP
curl "https://your-shop.com/modules/feedforge/cron.php?token=YOUR_CRON_TOKEN"

# CLI
php /path/to/shop/modules/feedforge/cron.php --token=YOUR_CRON_TOKEN
```

The endpoint respects a lock file (no overlapping runs) and an execution
deadline, so it is safe to call frequently.

## Architecture

- **Symfony controllers + Twig** templates for the admin UI (no React/Vite).
- **Service layer** — `GoogleApiClient`, `DataSourceService`, `ProductService`,
  `StatusService`, `ReportService`, `SyncEngine`, `DeltaDetector`,
  `QueueProcessor`, `DataMapper`, `RuleEngine`, `TaxonomyService`,
  `ShippingService`, `PromotionService`, `TokenEncryption`.
- **DBAL repositories** over 12 tables (prefix `ps_feedforge_`).
- **Event subscribers** keep the sync queue in step with product and cart‑rule
  changes.
- **OAuth tokens** are encrypted with a key derived from PrestaShop's
  `_COOKIE_KEY_` — no separate secret to manage.

## Development

```bash
cd feedforge
composer install
vendor/bin/phpunit          # unit tests (PHPUnit 10)
```

The module targets PrestaShop 8.0–9.x on PHP 8.1+. See [`feedforge/`](feedforge/)
for the full source.

## License

[MIT](LICENSE) © Feed Forge
