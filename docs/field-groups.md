---
title: Field groups and blocks
summary: Reusable sets of fields, blocks for the page builder and their templates.
parent: concepts
order: 40
---
# Field groups and blocks

A field group is a reusable set of fields of a project, e.g. **SEO** with keywords and description (*Administration ›
Field groups*). **Blocks** (*Administration › Blocks*) work the same, but are the items of block lists (page builder)
and can only be chosen there; field groups only for group fields - both can contain the other. Both have an optional
**category** (e.g. "Media", "Layout") that groups them in the lists and in the block picker (`"category"` on
`POST|PUT /api/v1/admin/groups`, `?kind=group|block` filters the list). A blocks field takes chosen blocks and/or all
blocks of categories (`"block_categories"` - also blocks added to them later).

**Templates of blocks:** a block can have a template (Twig) - the HTML websites get without a template of their own.
The content API adds `"_html"` to every item of a blocks field with `?render=html`; the PHP SDK uses it where the
website has no template. Templates run in Twig's sandbox (only `if`, `for`, `set`, `apply`, `with` and harmless filters
and functions, output escaped); `block` holds the values as the API delivers them, `api_url` the content API of the
project, `|markdown` turns Markdown into HTML, `render_blocks(block.content)` renders nested blocks. *Try* in the admin
app renders it with example values (`POST /api/v1/admin/groups/{id}/render`); unsaved blocks of live editing:
`POST /api/v1/{project}/content/{entity}/render` (preview token). Plugins bring templates for their blocks. An entity uses a field group with a field of
type **group**, and the API returns an object:

```json
"seo": { "keywords": ["cms", "headless"], "description": "…" }
```

- Groups can contain groups, but never themselves (not even through other groups).
- The group field in the entity can be **repeatable** (a list of objects, with minimum, maximum and drag & drop) and
  **translatable** (the whole object per language). Fields inside a group cannot be unique or translatable on their
  own, and sequence numbers, UUIDs and slugs do not exist in groups.
- Values are JSON, so changing a group (adding a field, say) applies to every entity at once, without changing tables.
  Fields inside are checked like entity fields, and problems name their path (`SEO › Description: …`).
- Files and records used inside groups count as used: they are kept by the cleanup and cannot be deleted.
- **Existing fields become a group** in the schema (*Turn into a field group*): a new group made from them, or an
  existing group with fields of the same names and types. All values, translations included, move into the group
  field. This is how two entities with the same SEO fields end up sharing one group.

## Blocks (page builder)

A group field can be a **blocks** field instead of using one group: the schema picks several field groups as *block
types* (hero, text, image, quote, call to action …), and editors line up blocks of these types in any order. They add
blocks from a menu (at the end or after a block), move them by drag & drop or the arrows, duplicate, collapse and delete
them. The API returns a list in which every block names its type and has a stable key:

```json
"content": [
  { "_type": "hero", "_key": "3f9a1c0b7d2e", "title": "Welcome", "image": { "id": "…", "url": "…" } },
  { "_type": "text", "_key": "a81c55e0f4b9", "body": "We build **pages** from blocks." }
]
```

- Every block is checked by its group, like any group value. Problems name the block (`Hero › Title: Please fill in.`).
  Unknown types are refused. Blocks without values stay (e.g. a divider).
- Files and references inside blocks count as used. The texts of blocks are part of the search (`?s=`) and
  the search index. Blocks are translatable as a whole list per language.
- Block types can be added to or removed from the field at any time. Blocks of a removed type are left out of the
  API. A group used as block type cannot be deleted. A group field cannot become a blocks field (or the other way round).
- **Nested blocks:** a field group may have a blocks field itself, e.g. a *column* with its own blocks inside a block
  *columns*. A block type never contains itself, not even deeper down (422). Keys are unique in the whole value, and
  the editor, the content API, the search and the media usage reach every level. In the PHP SDK, templates render the
  inner blocks with `$block->blocks('columns.0.content')`.
- API: `{"type": "group", "blocks": ["hero", "text"]}` (names or ids of the groups) when creating the field.
  Writing records takes the same list of items with `_type` (and `_key`, made if missing).
- The website renders one template per block type. The PHP SDK does this with `$record->blocks('content')`,
  `BlockRenderer` (PHP templates or callables) or the Twig extension `{{ excellent_blocks(page.content) }}`.
