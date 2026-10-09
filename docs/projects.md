---
title: Projects
summary: Separate content areas with their own tables, languages and API.
parent: concepts
order: 10
---
# Projects

Everything belongs to a project: entities (their tables are named `_<table prefix><entity>`, e.g. `_shop_products` - the `_` keeps content apart from the system tables), API
clients, media and languages. Each project has its own content API under `/api/v1/<project>/content`, and an OAuth
token only works in the project of its client. Entity names only have to be unique within a project.

- Administrators see all projects. Other users are assigned to projects and have their permissions per entity there.
- The admin app works in one project at a time: there is a switcher in the sidebar, and requests carry the header
  `X-Project`. Projects are managed under *Administration › Projects*.
- The table prefix can only be changed while a project has no entities. Only empty projects can be deleted, and their
  API clients and media go with them.
- A new installation starts with one project, `main` (table prefix `main_`). Rename it, change its prefix while it has
  no entities, or add more projects. On an existing installation, the migration puts everything that was there
  before into this project, so the existing `c_*` tables stay as they are.

**The area "Global"** is a project of its own (tables `global_*`) for data that every project needs, such as countries
or currencies. Admins switch to it in the project menu and create entities there as usual. Every project then:

- lists them in its navigation (section *Global*), where its users edit their records;
- can reference them from its own fields;
- serves them through its content API, e.g. `/api/v1/shop/content/currencies`.

The schema of a global entity can only be changed in the area "Global". Global entities and project entities share one
name space, so a project cannot have its own `currencies` next to a global one. A global entity, or one of its records,
cannot be deleted while any project still references it. Files of global records belong to the area "Global" and are
shared too. With `?lang=`, a project may also ask for one of its own languages, and missing translations fall back to the
default language.

**Copying an entity into another project** (*Schema › Copy to project*, admins): fields, settings and the field
groups it uses are copied, optionally with all records. Records keep their ids, so copying related entities one after
the other (regions, then countries, then authors) keeps their references. References point to the entity with the same
slug in the target project, which has to exist first. References to records the target project does not have are
emptied and reported. Files of media fields are copied into the target project.
