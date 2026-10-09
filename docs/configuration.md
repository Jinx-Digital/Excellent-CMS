---
title: Configuration
summary: Environment variables and the languages of the admin app and the API.
parent: getting-started
order: 20
---
# Configuration

## Configuration

All settings are environment variables. Locally they are read from `.env`. The loader (`vlucas/phpdotenv`) is a dev
dependency, so after `composer install --no-dev` you have to set them as real environment variables.

| Variable | Purpose |
| --- | --- |
| `APP_ENV`, `APP_DEBUG` | `dev` / `prod`. Set `APP_DEBUG=false` in production. |
| `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD` | MySQL connection |
| `JWT_SECRET` | Signs admin logins. At least 32 characters, e.g. `php -r 'echo bin2hex(random_bytes(32));'` |
| `APP_ENCRYPTION_KEY` | Encrypts secrets entered in the admin app (storage credentials). Base64 of 32 bytes, e.g. `php -r 'echo base64_encode(random_bytes(32));'`. Changing it makes stored secrets unreadable. |
| `JWT_TTL` | Lifetime of an admin login in seconds (default: 7 days) |
| `OAUTH_TOKEN_TTL` | Lifetime of OAuth access tokens in seconds (default: 1 hour) |
| `PREVIEW_TTL` | Lifetime of preview links in seconds (default: 1 hour), see [Preview](drafts-and-preview.md#preview) |
| `ADMIN_ORIGINS` | CORS of the admin routes: origins allowed to call them from a browser (comma separated). Empty (default): none - the admin app runs on the same address. The public API (`/<project>/content`, `/media`, `/plugins`, `/variables`, OAuth tokens) always answers every website (`*`); clients and tokens decide what they may do. |
| `RATE_LIMIT_ENABLED`, `RATE_LIMIT_REQUESTS`, `RATE_LIMIT_WINDOW` | Default rate limit, until an admin changes it in the app |
| `IMPORT_MAX_ROWS` | Largest file that can be imported (rows) |
| `MEDIA_*` | Storage of uploaded files, see [Media storage](media.md) |
| `APP_URL` | Address of the admin app for links in mails, e.g. `https://cms.example.com` (default: host of the request) |
| `MAILER_DSN` | Mail transport of [Symfony Mailer](https://symfony.com/doc/current/mailer.html#transport-setup), e.g. `smtp://user:pass@smtp.example.com:587`. Empty: mails are written to `runtime/logs/mail.log` instead. |
| `MAILER_FROM`, `MAILER_FROM_NAME` | Sender of the mails |
| `LOG_MAX_SIZE`, `LOG_MAX_FILES` | Rotation of `runtime/logs/app.log`: at this size in MB (default 10) it is moved to `app.log.1.gz`; the newest files are kept (default 5) |
| `CRON_KEY` | Web cron for servers without cron jobs: `GET /api/v1/cron?key=…` runs the scheduled publishing and the hourly cleanup (at least 16 characters; empty: off), see [Deployment](deployment.md#cron-jobs) |

## Translations

All texts are written in English in the code and translated per request:

- **API** (messages, validation errors, mails, import reports): [yiisoft/translator](https://github.com/yiisoft/translator)
  with ICU messages. German texts are in `messages/de/app.php`, keyed by the English text. The language comes from
  the `Accept-Language` header (`en` by default, answered as `Content-Language`). The content API works the same way;
  `?lang=` only selects the language of the content, not of the messages.
- **Admin app**: [@nuxtjs/i18n](https://i18n.nuxtjs.org/) with `frontend/i18n/locales/en.json` and `de.json`. Users
  switch the language at the bottom of the sidebar (or on the login page). The choice is kept in a cookie, and the
  app sends it to the API as `Accept-Language`.

To add a language, add `messages/<code>/app.php` and `frontend/i18n/locales/<code>.json`, register the code in
`App\Shared\I18n::LOCALES` and in `frontend/nuxt.config.ts`, and run `make generate`.
