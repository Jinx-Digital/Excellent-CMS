---
title: Deployment
summary: Release package, web server, cron jobs, updates and backups.
parent: getting-started
order: 30
---
# Deployment

One package serves every installation - e.g. **cms.jinx-digital.com** (the content of the website
excellent.jinx-digital.com with its docs and plugin downloads, and of jinx-digital) and
**admin.demo.excellent.jinx-digital.com** (the demo with the demo data; its website demo.excellent.jinx-digital.com is the
page builder demo of the PHP SDK). Each has its own database, `.env`, `storage/` and `plugins/`.

For the demo, `DEMO_PREVIEW_URL` (`.env`) points the preview of the landing pages to the demo website, e.g.
`https://demo.excellent.jinx-digital.com/page-builder.php?id={{id}}&token={{token}}`, before loading the demo data; the
website (the folder `demo/` of the PHP SDK) gets a `demo/config.local.php` returning `url` and `admin_origin`
`https://admin.demo.excellent.jinx-digital.com` (live editing; the example is in `demo/config.php`). Without it the demos read from
`http://localhost:8090`.

## Release package

On a machine with Node 22+ and Composer:

```bash
make release        # builds the admin app, then runtime/release/excellent-cms-<version>.tar.gz
```

The package holds the API, the built admin app (`public/`), migrations, sample data, docs and the Composer libraries
for production - no tests, sources of the admin app, `.env` or data. The server needs PHP 8.4 (intl, pdo_mysql, gd,
zip, mbstring) and MySQL 8, no Node or Composer.

## First installation

```bash
tar -xzf excellent-cms-<version>.tar.gz && cd excellent-cms-<version>
cp .env.example .env                 # DB_*, JWT_SECRET, APP_ENCRYPTION_KEY, APP_URL, MAILER_DSN, APP_DEBUG=false …
php yii migrate:up --no-interaction
php yii user:create-admin            # the first administrator
php yii docs:sync                    # optional: the documentation as the project "docs" (see below)
```

Real environment variables win over the `.env` (Docker, server configuration). `runtime/`, `storage/` and `plugins/`
must be writable.

## Updates

Unpack the new version next to the old one, take over `.env`, `storage/`, `plugins/` (and `runtime/logs` if you want
the old logs), point the web server to it and run:

```bash
make update          # migrations, missing blocks of plugins, search index (php yii … without make)
```

After updating a **plugin** (Administration › Plugins › Update) the admin app asks whether its blocks should be
updated too: missing blocks are created; "Reset templates" gives its blocks the plugin's templates again (changes made
in the admin app are lost). The same on the command line: `./yii plugins:sync [--plugin=…] [--templates]`.

## Documentation as content

`docs/*.md` is the documentation of Excellent CMS. `./yii docs:sync` loads it into a project (default `docs`, created
if missing; `--project=excellent` for another one) as the tree of the entity `pages` - title, summary and text in
English, titles and summaries in German too. Pages are matched by their slug (the file name): run it after every update
to bring the docs up to date; `--prune` deletes pages of the entity that are no longer in `docs/`.

## Web server

`public/` is the document root. It serves the admin app as static files and the API under `/api/v1`
(`public/api/index.php`). Apache uses the included `public/.htaccess`. For nginx:

```nginx
location /api/   { try_files $uri /api/index.php$is_args$args; }
location /media/ { rewrite ^ /api/index.php last; }   # uploaded files (local and private storages), served by PHP
location /       { try_files $uri /200.html; }
```

The admin app can be **installed as an app (PWA)** from the browser (Chrome, Edge, Safari "Add to Home Screen"). A
service worker caches the app shell and the built files, while API responses and media are always loaded fresh. It is
active in the built app only, not in `make dev`. Over HTTP, browsers only allow it on `localhost`.

Events in queue mode run in the worker. On a server without long-running processes, start it every minute by cron;
it handles what is waiting and exits:

```bash
* * * * * cd /path/to/cms && ./yii queue:run events
```

After updating to the version with the search index, build it once (until then, searches go to the columns as
before):

```bash
./yii search:rebuild
```

Scheduled publishing and unpublishing (see [Drafts](drafts-and-preview.md#drafts)) needs the same, every minute:

```bash
* * * * * cd /path/to/cms && ./yii schedule:run
```

The queue is [yiisoft/queue](https://github.com/yiisoft/queue) with its database adapter (table `queue`, locks
through MySQL). Both have no stable release yet: the version is pinned in `composer.lock` - update them on purpose
and run the tests. `src/Infrastructure/Queue` and `config/common/di/queue.php` are the only places that know them.

Run the cleanup command periodically, e.g. hourly. It removes expired OAuth tokens, abandoned imports, media files
that no record uses any more (after 24 hours), variants of deleted images, expired record locks and old rate-limit
counters:

```bash
./yii cleanup
```

## Cron jobs

Events in **direct** mode need nothing (they run after the answer); queue mode needs the worker below.

```bash
* * * * * cd /path/to/cms && php yii queue:run events     # events in queue mode
* * * * * cd /path/to/cms && php yii schedule:run         # scheduled publishing
0 * * * * cd /path/to/cms && php yii cleanup              # tokens, imports, unused files, locks
```

**Without cron jobs** (shared hosting): set `CRON_KEY` in `.env` and let an outside service (e.g.
[cron-job.org](https://cron-job.org), free) call `https://<cms>/api/v1/cron?key=<CRON_KEY>` every minute. It runs the
scheduled publishing and, at most hourly, the cleanup. Use events in direct mode then (the queue needs the worker).

## Moving a project

`./yii project:import <folder> [--project=<slug>] [--as=<admin e-mail>]` creates a project from an export folder - e.g.
of another installation, read through its API: `project.json`, `variables.json`, `groups.json`, `entities.json`,
`records/<entity>.json` (records as `GET /api/v1/entities/{entity}/records/{id}` delivers them, with `_i18n`) and
`media.json` with the files. Everything gets new ids; media fields, references (also in groups and translations) point
to the new ones. The project must not have entities yet.

## Backups

Back up the database (e.g. `mysqldump --single-transaction`) and the folder `storage/` (uploaded files of the local
storage) together, plus `.env` (without `APP_ENCRYPTION_KEY` the stored secrets cannot be read). Files on other
storages (S3, FTP …) are backed up there.

## Security

- **Login of the admin app:** an httpOnly cookie (`cms_session`, `SameSite=Strict`, `Secure` over https) - scripts in
  the page cannot read it, and it only counts for requests of the admin app (header `X-Requested-With`). Scripts sign
  in with `POST /api/v1/auth/login` and send the token of the answer as `Authorization: Bearer`.
- **CORS:** the public API (`/<project>/content`, `/media`, `/plugins`, `/variables`, OAuth tokens) answers every
  website; the admin routes only the origins of `ADMIN_ORIGINS` (default: none - the admin app runs on the same
  address).
- **Content-Security-Policy** of the admin app: `make generate` writes it into `public/.htaccess` (only the app's own
  scripts run, the inline ones by their hashes). For nginx: `php bin/csp.php --print` and
  `add_header Content-Security-Policy "…" always;` for the HTML pages.
- **Logs:** `runtime/logs/app.log` is rotated at `LOG_MAX_SIZE` MB, the newest `LOG_MAX_FILES` are kept (compressed).
- Set `APP_DEBUG=false`, switch the **rate limit** on (Administration › Rate limit) - it also protects forms of
  plugins against floods.
