---
title: Getting started
summary: What Excellent CMS does, what it needs and how to run it with the demo data.
order: 10
---
# Getting started

## Live demo

**[admin.demo.excellent.jinx-digital.com](https://admin.demo.excellent.jinx-digital.com/)** is the admin app and the API of the
demo, with the [demo data](#demo-data): the projects “Library” (English and German) and “Documentation” (English).
**[demo.excellent.jinx-digital.com](https://demo.excellent.jinx-digital.com/)** is a website made from it with the PHP SDK: the
landing pages of the page builder (with preview and live editing from the admin app).

| Role | E-mail | Password |
| --- | --- | --- |
| Administrator | `admin@example.com` | `admin123` |
| Editor (library: can edit and import books, and read everything else; role *Redakteur*: may take over records) | `redaktion@example.com` | `redaktion123` |
| Author (role *Autor*: writes books and blog posts and edits or deletes only her own, adds authors, reads the rest - no imports) | `autorin@example.com` | `autorin123` |

The content API of the demo is public for countries and authors, e.g.
[/api/v1/bibliothek/content/countries](https://admin.demo.excellent.jinx-digital.com/api/v1/bibliothek/content/countries). Everybody shares the same
demo, so things may have been changed by others.

## Features

- **Import from CSV, XLSX, XLS and ODS.** Delimiter, encoding (UTF-8, Windows-1252, BOM), sheets and Excel date cells
  are detected. For every column the importer suggests a type, whether it is required or unique, and references to
  existing entities.
- **Projects and languages.** Each project has its own entities, table prefix, API clients, media and content API.
  Fields can be translatable, and the API returns one language or all of them.
- **Real tables, no generated code.** Every entity gets its own table `_<prefix><slug>` with typed columns, unique indexes and
  foreign keys. Fields can be added, renamed or retyped later, and existing values are converted.
- **Admin app** for schema, records, imports, users, API clients and settings. Every user chooses the columns and the
  number of entries per page of each list for themselves (saved on the server, so it follows them to every browser).
- **Content API** with search, filters, sorting, field selection and embedded references.
- **PHP SDK:** the [Excellent CMS PHP SDK](https://github.com/Jinx-Digital/Excellent-CMS-PHP-SDK) is a client for the content
  API, see [PHP SDK](php-sdk.md).
- **Drafts, working copies and schedules:** records can be saved as drafts, published records can be changed in a
  working copy while the published version stays live (in every entity), and both can be published or unpublished at
  a set time, see [Drafts](drafts-and-preview.md#drafts).
- **Page builder and preview:** blocks fields let editors build pages from blocks of several field groups (hero, text,
  image …), and the website shows drafts and working copies in a preview next to the form, see
  [Blocks](field-groups.md#blocks-page-builder) and [Preview](drafts-and-preview.md#preview).
- **Plugins:** extensions as ZIP, installed in the admin app with their migrations and switched on and off there,
  without Composer or a build, see [Plugins](plugins.md).
- **Storages:** uploads go to a local folder, S3 and S3-compatible services (R2, Hetzner, IONOS, DigitalOcean, Wasabi …),
  FTP, SFTP or WebDAV. Storages are defined in the admin app or the `.env`, and each project picks one, see
  [Media storage](media.md).
- **Revisions:** switched on per entity, every change keeps a version of the record. Versions can be compared field by
  field and restored, see [Revisions](revisions.md).
- **Events:** react to changes of records, media and variables (create, update, delete, publish ...) with an optional
  condition in the filter language, and run steps like webhooks, e-mails or creating, updating and deleting records -
  directly or through a queue, with visible progress, see [Events](events.md).
- **Image transformations:** images in any size, cropped or with whitespace, as webp, jpg, png or avif, made once and
  cached by the CMS, see [Image transformations](media.md#image-transformations).
- **Global entities** in the area "Global" (countries, currencies ...) are shared by all projects.
- **Record locks:** a record being edited is locked for everyone else; administrators and roles with *take over* can
  take it over.
- **Access control:** roles with inheritance (yiisoft/rbac) for users and API clients, permissions per entity, fields
  limited to roles, public or OAuth 2.0 access for API consumers, and an optional
  rate limit.

## Requirements

- PHP 8.4.1 – 8.5 and Composer
- MySQL 8
- Node.js 22 or newer for the admin app (see `frontend/.nvmrc`)

## Installation

```bash
cp .env.example .env                 # set the DB_* values and JWT_SECRET (at least 32 characters)
composer install
mysql -u root -p -e "CREATE DATABASE excellent_cms CHARACTER SET utf8mb4"
make migrate                         # create the system tables
./yii user:create-admin you@example.com "Your Name"   # asks for a password
make serve                           # API on http://localhost:8090/api/v1
```

Start the admin app in a second terminal:

```bash
cd frontend
nvm use && npm install
npm run dev                          # http://localhost:3090
```

Log in with the admin account and import your first file under **Import**.

`make help` lists all available commands.

### Demo data

To try things out, load the demo data instead of creating an admin. `make db-reset` **drops and recreates** the
database from `.env`, runs the migrations and creates two projects and the global entities. The library is imported from the sample files in
`resources/samples` through the regular importer.

```bash
make db-reset
```

**Area “Global”** (tables `global_*`) - shared by all projects, readable in the content API of each one (e.g.
`/api/v1/bibliothek/content/countries`), their schema is edited in the area “Global”:

| Entity | Access | Content |
| --- | --- | --- |
| Regions | public | The 29 UN M49 regions as a tree: World › continents › subregions (e.g. Europe › Western Europe), in English and German |
| Countries | public | All 249 ISO 3166 countries: English and German name, ISO-2/ISO-3/numeric code, region (reference), currency, EU membership |
| Languages | public | the 7 original languages of the books, with ISO code and native name |

**Project “Library”** (`/api/v1/bibliothek/content`, tables `lib_*`):

| Entity | Access | Content |
| --- | --- | --- |
| Authors | public | 30 classic authors with dates of birth and death, country of birth (reference to the global countries) and Wikipedia link |
| Genres | public | 7 genres (novel, drama, poetry …) in English and German |
| Books | OAuth | 42 of the authors' best-known works with original title and year of first publication, references to author, genre and (global) language, and a sequence number |

**Project “Documentation”** (`/api/v1/docs/content`, tables `docs_*`): the documentation of Excellent CMS itself,
in English (the files of `docs/`, loaded with `./yii docs:sync`).

| Entity | Content |
| --- | --- |
| Pages | 14 documentation pages as a tree, with translatable title, slug, summary and Markdown text, and the field group SEO |
| Blog | 3 release notes with date, repeatable tags and the field group SEO |

Links in the texts use the project variables `{{url}}` (the website), `{{api_url}}` and `{{admin_url}}` (the demo).

Logins: `admin@example.com` / `admin123` (administrator, both projects) and `redaktion@example.com` / `redaktion123`
(editor in the library: can edit and import books, and read everything else; role *Redakteur*), and
`autorin@example.com` / `autorin123` (role *Autor* in both projects: writes books and blog posts and edits or deletes
only her own, adds authors, reads the rest - no imports). The command also prints the credentials of an API client for the books.

Country and region data come from the Unicode CLDR via PHP `intl` and from the public-domain ISO 3166 list of the tz database.
Author and book data are public facts about public-domain works. The prices and stock flags of the books are made up.

## Tests

```bash
make test    # recreates the database excellent_cms_test with the test data, then runs Codeception
```
