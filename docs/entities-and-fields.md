---
title: Entities and fields
summary: Entities are tables, fields are typed columns.
parent: concepts
order: 30
---
# Entities and fields

Entity and field definitions live in the tables `entity` and `entity_field`. `SchemaService` creates and changes the
matching table `_<project prefix><slug>` at runtime. Index and foreign key names are derived from the field ID, so renaming a field
or an entity never touches them.

Every record has an `id` (UUIDv7, base58), `created_at` and `updated_at`.

| Type | Column | Notes |
| --- | --- | --- |
| Short text | `VARCHAR(n)` | length 1–1000 |
| Long text | `TEXT` | cannot be unique |
| Integer | `BIGINT` | |
| Decimal | `DECIMAL` | 0–8 decimal places |
| Yes/No | `BOOLEAN` | |
| Date, Date and time, Time | `DATE`, `DATETIME`, `TIME` | date-times are stored in UTC |
| Email, URL | `VARCHAR` | validated |
| Reference | `VARCHAR(22)` + foreign key | points to a record of another entity, or of the same one |
| UUID | `CHAR(36)` | version 1, 4, 6 or 7. Generated for new records when left empty. One per entity. |
| Sequence number | `BIGINT UNSIGNED AUTO_INCREMENT` | numbered by the database and always unique. One per entity. |
| Media | `VARCHAR(22)` + foreign key | uploaded file, optionally limited to MIME types like `image/*` or `audio/mpeg`. See [Media storage](media.md). |
| Slug | `VARCHAR(n)` + unique index | URL name: entered text is cleaned up (`Über uns!` → `ueber-uns`), and taken slugs get a number (`home-2`). Optionally made from another text field when left empty. |
| Text with pattern | `VARCHAR(n)` | short text that has to match a regular expression set by the admin (e.g. `^[A-Z]{2}-\d{4}$`), with an optional message. A new pattern is refused while existing values do not match it. |
| Markdown | `MEDIUMTEXT` | formatted text, edited in a visual editor or as Markdown source. The API returns the Markdown. |
| Field type of a plugin | `MEDIUMTEXT` | e.g. *Rich text*, *Map position*, *User* – see [Plugins](plugins.md). The API names the type `<plugin>.<key>` (`geo.point`). |
| Selection (enum) | `VARCHAR(100)` | one of the values the admin set, each with a label (`open` → “Open”). The record stores the value, the API returns it, and the schema (`GET /content`) has the labels. Repeatable: several values. Values that records use cannot be removed. |
| Order | `BIGINT` | position of the record, one per entity. See [Order](order-and-trees.md#order). |
| Color | `VARCHAR(9)` | `#1E40AF`, `14f` → `#1e40af`; with transparency `#1e40af80`. Color picker in the admin app. |
| Phone number | `VARCHAR(16)` | stored in one form with country code: `0049 (30) 123 456-7` → `+49301234567`, ready for `tel:` links. Numbers without country code are refused. |
| Date range | `VARCHAR(21)` | `2026-10-01/2026-10-05` (sortable by the start), the API returns `{"from": "…", "to": "…"}`. Accepts `{from, to}`, `01.10.2026 - 05.10.2026` or `…/…`; the end may be empty, but not before the start. |
| JSON | `MEDIUMTEXT` | any JSON value, returned as it is (objects stay objects). Sent as JSON or as JSON text. Cannot be unique. |
| Code | `MEDIUMTEXT` | code as written, edited in a code editor (CodeMirror): `{"language": "php", "file": "page.php", "code": "…"}`. Languages: html, twig, css, javascript, php, json, markdown, sql, shell, text. Nothing is parsed or cleaned - the website decides how to output it. Plain text is accepted too (language `text`). Searchable (file name and code), not unique, not repeatable. |

**Search and filters:** every field is *searchable* (text search `?s=`) and *filterable* (`filter[field]`) by default.
Both can be switched off per field in the schema (`"searchable": false`, `"filterable": false`). A field that is not
filterable is not searched either. Filtering by it returns 422 in the content API and the admin app, nested
filters like `filter[author][code]` included. Event conditions are no API filters and may use every field. The schema
of the content API (`GET /content`) returns both values per field.

**Repeatable fields:** most types (not sequence numbers, UUIDs, slugs, yes/no, order, date ranges or JSON) can hold a list of values. The list
has an optional minimum and maximum, and if the field is *sortable* its order can be changed by drag & drop. The API
returns an array, and every value is checked like a single one. Repeatable media fields hold several files, and
repeatable references point to several records. Records that are referenced in a list cannot be deleted either. A
filter on a list means "contains" (`filter[tags]=<id>`), and imports separate several values in a cell with `|`
(`red | green`). Switching *repeatable* on turns existing values into one-item lists. Switching it off, or lowering the
maximum, is refused while records have more values.

When a field changes its type, every existing value is converted, e.g. text `29.10.2025` to a date, or text `DE` to
a reference found by ISO code. If any value does not fit, nothing changes and the error names the values.
Records and entities that are still referenced cannot be deleted.

Every record has `created_by` and `updated_by` (and `deleted_by` with a trash): the user of the admin app or the API
client that did it. The admin app shows the names and can filter by them (`filter[created_by]=user:<id>`). The content
API does not deliver these fields, so names of editors do not become public.

**Form designer** (schema of an entity, admins): the fields of the record form can be arranged in **tabs**, e.g. the
content with its blocks in one, SEO in another. There is always at least one tab; fields no tab names (new ones too)
are in the first, renamed fields stay in theirs. Only the arrangement changes – the fields stay columns of the
entity. Tabs with errors show their number, and after a failed save the first of them opens. API: `"tabs": [{"key",
"label", "fields": [names]}]` on `PUT /api/v1/admin/entities/{id}` (an empty list: one tab).
