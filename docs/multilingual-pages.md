---
title: Multilingual pages
summary: A page tree in several languages - slug paths per language, routing on the website and the preview.
parent: knowledge-base
order: 30
---
# Multilingual pages

A website with pages and subpages in German and English: `/de/ueber-uns/team` and `/en/about-us/team` are the same
record. This needs no plugin. It combines [languages](languages.md), [trees](order-and-trees.md), slugs and the
[preview](drafts-and-preview.md#preview).

## 1. The project

*Administration › Projects*: languages `de`, `en` (the first is the default language). A project variable `url`
(*Administration › Variables*), translatable, with `https://example.com/de` and `https://example.com/en`, gives
links that follow the language: `{{url}}/kontakt` in a German text, `{{url}}/contact` in its English version.

## 2. The entity

**Pages** (`pages`), public, with drafts:

| Field | Type | |
| --- | --- | --- |
| `title` | string, required, **translatable** | display field |
| `slug` | slug from `title`, **translatable** | |
| `parent` | reference to `pages` | parent field (*Schema › Settings › Tree*) |
| `position` | order | drag & drop among siblings |
| `content` | blocks, **translatable** where the text differs | |
| `seo_title`, `seo_description` | string / text, translatable | or the plugin *SEO* |

- The slug is made per language from the title in that language: *Über uns* → `ueber-uns`, *About us* →
  `about-us`. Editors can overwrite each.
- In a tree, slugs are unique per level, so `ueber-uns/team` and `produkte/team` can both exist.
- Empty translations fall back to the default language, so a page can go live before it is translated.

## 3. Routing on the website

The content API returns the slug of a tree as the whole path, in the requested language, and finds a page by it:

```
GET /api/v1/site/content/pages?lang=en&filter[slug]=about-us/team
```

A front controller therefore needs one request per page:

```php
[$lang, $path] = explode('/', trim($_SERVER['REQUEST_URI'], '/'), 2) + [1 => 'home'];
$page = $cms->entity('pages')->lang($lang)->where('slug', $path)->first() ?? throw new RuntimeException('Not found', 404);
```

- **Navigation:** `GET …/pages?tree=1&lang=en&fields=title,slug` returns the whole tree in one request, in the order
  of `position`.
- **Language switcher:** `?lang=all` returns every language of the translatable fields
  (`"slug": {"de": "ueber-uns/team", "en": "about-us/team"}`), so the link to the other language is known without a
  second request.
- **Breadcrumbs:** the path is in the slug. Split it and look up the parents, or include them with `include=parent`.

## 4. Preview

*Schema › Settings › Preview address*, with `{{lang}}` so the preview opens in the language being edited:

```
https://example.com/api/preview?lang={{lang}}&path={{record.slug}}&token={{token}}
```

The website calls `$cms->preview($_GET['token'])` and then loads the page like any other. With the token it gets
drafts and working copies. With `LiveEdit` of the PHP SDK, editors click a block in the preview to edit it.

## Things to watch

- Display field, search, sorting and required fields work on the default language. A search in English needs
  `lang=en`.
- Removing a language deletes its texts (the admin app asks first). Choosing another default language swaps the
  texts.
- A page with subpages cannot be deleted until the subpages are moved or deleted.
