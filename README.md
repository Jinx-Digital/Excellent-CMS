# Excellent CMS

A headless CMS whose data structures come from your spreadsheets. Upload a CSV or Excel file, review the detected
fields, and you get a typed database table, an admin UI to edit the records, and a REST API to read them.

- **Backend:** PHP 8.4+ with [Yii3](https://www.yiiframework.com/) (`src/`, `config/`), MySQL 8
- **Admin app:** [Nuxt](https://nuxt.com/) SPA with [Nuxt UI](https://ui.nuxt.com/) (`frontend/`)
- **Languages:** the admin app and all messages of the API are in English and German (`Accept-Language`), see [Configuration](docs/configuration.md#translations)
- **Website:** [excellent.jinx-digital.com](https://excellent.jinx-digital.com/) · live demo: admin app and API
  [admin.demo.excellent.jinx-digital.com](https://admin.demo.excellent.jinx-digital.com/), a website built with it
  (page builder, PHP SDK) [demo.excellent.jinx-digital.com](https://demo.excellent.jinx-digital.com/)

## Documentation

The documentation is in [`docs/`](docs/) - the same files are the content of the project "docs" (`./yii docs:sync`,
also part of the demo data) and of the documentation on the website.

- **Getting started:** [Getting started](docs/getting-started.md) (features, requirements, demo data, tests) ·
  [Configuration](docs/configuration.md) · [Deployment](docs/deployment.md)
- **Concepts:** [Overview](docs/concepts.md) - [Projects](docs/projects.md), [Languages](docs/languages.md),
  [Entities and fields](docs/entities-and-fields.md), [Field groups and blocks](docs/field-groups.md),
  [Project variables](docs/variables.md), [Drafts and preview](docs/drafts-and-preview.md),
  [Revisions](docs/revisions.md), [Order and trees](docs/order-and-trees.md), [Trash](docs/trash.md),
  [Search](docs/search.md), [Media](docs/media.md), [Events](docs/events.md), [Import](docs/import.md),
  [Users and permissions](docs/users-and-permissions.md), [Plugins](docs/plugins.md)
- **For websites:** [Content API](docs/content-api.md) · [PHP SDK](docs/php-sdk.md)

## Quick start

```bash
cp .env.example .env     # database, JWT_SECRET …
composer install
make db-reset            # tables and demo data
make dev                 # API on http://localhost:8090, admin app on http://localhost:3090
make generate            # optional: the built admin app in public/ (not in git) for Apache/nginx
```

Sign in with `admin@example.com` / `admin123`. A package for servers: `make release` (see [Deployment](docs/deployment.md)).

## License

Excellent CMS is licensed under the [PolyForm Small Business License 1.0.0](LICENSE.md):

- **Free** for companies with fewer than 100 people (employees and independent contractors) and less than
  1,000,000 USD (2019 value, adjusted for inflation) of revenue in the prior tax year. This covers using, changing
  and passing it on, for example for your own or your clients' projects.
- **Larger companies** need a commercial license: please contact [jinx digital](https://jinx-digital.com).

The license does not turn into an open-source license later. The [PHP SDK](https://github.com/Jinx-Digital/Excellent-CMS-PHP-SDK)
is MIT-licensed.

