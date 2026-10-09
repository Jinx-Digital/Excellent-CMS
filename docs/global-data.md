---
title: Shared data for several websites
summary: Countries, currencies or team members kept once in the area "Global" and used by every project.
parent: knowledge-base
order: 60
---
# Shared data for several websites

An agency runs the websites of three brands in one CMS, one [project](projects.md) each. Some data is the same
everywhere: countries, currencies, the locations of the company, the team. It is kept once in the area **Global**,
and every project uses it as if it were its own.

## 1. Global entities

Switch to *Global* in the project menu (admins only) and create the entities as usual, e.g. **Currencies**
(`currencies`: `code`, `symbol`, `rate`) and **Locations** (`locations`: `name`, `address`, `phone`, `photo`).

From then on, every project:

- lists them in its navigation under *Global*, where its users edit the records (with their permissions per entity);
- can point to them from its own fields: a reference `currency` in `products` of the project *shop*;
- delivers them through its own content API:

```
GET /api/v1/brand-a/content/locations
GET /api/v1/shop/content/products?include=currency
```

A website needs only the API of its own project. It does not know there is a Global area.

## 2. What stays global

- **The schema** is changed only in the area Global. In a project, *Schema* lists the global entities with a link to
  switch there.
- **One name space:** a project cannot have its own `currencies` next to the global one, and the area Global cannot
  take a name that a project already uses.
- **Files** of global records belong to the area Global (`/media/global/…`) and are shared too.
- **Deleting:** a global entity, or one of its records, cannot be deleted while a project still points to it. *Used
  in* on the record lists only what the current project can open.

## 3. Languages

Each project has its own languages. A project in `de` and `fr` may ask a global entity for `?lang=fr`. Missing
translations fall back to the default language of the global entity.

## 4. Events and previews

- Events are per project. An event in *shop* on the global entity `currencies` reacts to changes made in any project,
  e.g. to recalculate prices when a rate changes (`"condition": {"_changed": ["rate"]}`).
- The preview of a global record opened in a project goes to the website of that project. Opened in the area Global,
  its token works on every project's website, but shows drafts only of the global entities.

## Global or copied?

| | Global entity | Copy into the project (*Schema › Copy to project*) |
| --- | --- | --- |
| Records | kept once, a change shows everywhere | separate per project, change independently |
| Schema | the same for everyone | each project changes its own |
| Good for | reference data: countries, currencies, locations | a start that then grows apart: a template for `pages` |
