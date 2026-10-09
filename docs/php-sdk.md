---
title: PHP SDK
summary: Records, blocks, media and live editing in PHP.
parent: content-api
order: 10
---
# PHP SDK

There is a PHP client for this API: **[Excellent CMS PHP SDK](https://github.com/Jinx-Digital/Excellent-CMS-PHP-SDK)**. It works with any PSR-18 HTTP
client and offers:

- an immutable query builder with filters and sorting on fields of referenced records;
- lazy paging, trees and included references;
- OAuth client credentials with token caching;
- writing records and uploading media;
- typed exceptions for every error.

```bash
composer require lugat/excellent-cms-php-sdk guzzlehttp/guzzle
```

```php
$cms = new ExcellentCms\Sdk\Client('https://cms.example.com', 'bibliothek', new ExcellentCms\Sdk\Auth\ClientCredentials($id, $secret));
$books = $cms->entity('books')->where('author.name', 'Jane Austen')->orderBy('author')->with('author')->get();
```

Its `demo/demo.php` reads the API of the [live demo](https://admin.demo.excellent.jinx-digital.com/) on the command line or in
the browser; `demo/page-builder.php` is the website of the demo ([demo.excellent.jinx-digital.com](https://demo.excellent.jinx-digital.com/)).

**Writing:** API clients can also create, update and delete records, if their client has the permission for the
entity (*Administration › API clients*). Writing always needs a token, public entities included:

```
POST   /api/v1/{project}/content/{entity}          {"title": "…"}   -> 201 with the record
PUT    /api/v1/{project}/content/{entity}/{id}     only the sent fields change (PATCH works too)
DELETE /api/v1/{project}/content/{entity}/{id}     into the trash if the entity has one
```

The same checks as in the admin app apply, and errors come back as `422` with the field names. With `?lang=de`, the
values of translatable fields are written as German. `_i18n` (`{"title": {"de": "…"}}`) works as well. Media fields take the id of an
uploaded file.

**Media:** API clients with the media permissions (*Upload media* and *Delete media* on the client) manage the files
of their project:

```
POST   /api/v1/{project}/media          multipart: file, optional entity + field, keep=1   -> 201 with id and url
GET    /api/v1/{project}/media/{id}     the file with kept and usage_count
DELETE /api/v1/{project}/media/{id}     unused files only, 409 media_in_use otherwise
```

With `entity` and `field`, the allowed types of that media field are checked on upload. Use the returned `id` in
media fields when creating or updating records. Files that no record uses are removed by `./yii cleanup` after a day,
unless they were uploaded with `keep=1`, which keeps them in the media library.

**Languages:** `?lang=en` returns translatable fields in English, and empty translations fall back to the default
language. Search, filters and sorting then use English too. `?lang=all` returns an object with every language, e.g.
`{"de": "Über uns", "en": "About us"}`. Without `lang`, the default language is returned.

**Trees:** `GET /api/v1/{project}/content/{entity}?tree=1` returns all matching records nested, each with a `children` array
(at most 10,000 records). `filter[parent][null]=true` returns the top level, and `filter[parent]=<id>` returns the
children of a record. In trees, slug fields are unique per level, so `europe/team` and `asia/team` can both exist. The
content API returns them as the whole path (`world/europe/western-europe`, per language with `lang`), and
`filter[slug]=world/europe` finds a record by its path. The admin app shows the slug of the record itself.

**Filter operators:** `eq`, `ne`, `gt`, `gte`, `lt`, `lte`, `like`, `in`, `null`. Values are parsed like in the import,
e.g. `filter[price][gte]=12,50` or `filter[date][lt]=29.10.2025`.

**References in filters and sorting:** `filter[country]=<id>` matches the id of the referenced record. Fields of the
referenced record work too, with the same operators and up to three levels deep. For example, `filter[country][name]=Germany`,
`filter[country][region][name][like]=europe` or `filter[authors][name]=Jane Doe`. For a repeatable reference, one of the
referenced records must match. `sort=country` sorts by the display field of the referenced record, and
`sort=-country[alpha2code]` or `sort=city[country][name]` sorts by another field. Fields of entities the client (or user)
may not read cannot be used. A plain `sort=country` then falls back to the id.

**Access** is set per entity:

- `public`: readable without authentication.
- `oauth`: needs the bearer token of an API client that has been granted this entity. Scopes are entity slugs.

Changes to a client's permissions take effect immediately, even for tokens that were already issued.
`include` embeds referenced records only if the caller is allowed to read their entity.

**Rate limit (optional):** can be switched on in the admin app. Requests are counted per API client, or per IP address
when there is no token. A client can have its own limit, where `0` means unlimited. Responses carry `X-RateLimit-*`
headers, and exceeding the limit returns `429` with `Retry-After`. Counters live in the database, so Redis is not
needed.
