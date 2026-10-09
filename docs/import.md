---
title: Import
summary: From a spreadsheet to a typed table - and updating it later.
parent: concepts
order: 130
---
# Import

Upload → analyze → preview → import. The target is either a new entity (admins only) or an existing one. When
importing into an existing entity, you can choose a key field: matching records are then updated instead of created.
Invalid rows are reported with their line number and skipped, and all other rows are imported.

All values go through one converter, so the import, the admin app and the API accept the same formats. Examples:
`12,50` and `1.234,56` for decimals, `29.10.2025` for dates, `ja`/`nein` for yes/no.

**References:** a column can point to records of another entity, for example *Country* to `countries`:

- The analysis offers the entities whose records the values name: for each entity, how many sample values were
  found (*Countries (Name): 48 of 50*). With 90 % or more, the reference is preset. One click links the column.
- Values are found by a field of the target (`match`, default `id`), in every language of the field and regardless
  of upper and lower case: *deutschland* finds the country whose German name is *Deutschland*.
- **Add missing ones** (`create_missing: true`): values that are not found are added to the target, so every row
  is imported. This needs a `match` field (not the id), permission to create records there, and no other required
  fields in the target.
- **New entity from the values** (`new_reference: {"slug": "genres", "name": "Genres"}`, administrators): the
  column becomes an entity of its own with the field *Name* and one record per value (*Crime* and *crime* are one).
  Importing books creates the genres right away. With *several values per cell* (`repeatable: true`),
  `Crime | Fantasy` gives two genres and a list reference.
- The preview shows what will be created in addition, and nothing is written yet. If the import fails, a new entity
  is removed again.

**Yes/no columns:** the analysis lists the different values of the column, and the import lets you choose which of
them mean *yes* and which *no* (several each, e.g. `1`, `true` and `x` for yes) and whether an empty cell means *no*.
Other values are reported as errors. Without a choice, the usual words count (`ja`/`nein`, `yes`/`no`, `1`/`0`,
`true`/`false` …). In the plan: `true_values`, `false_values` and `empty_false` per column.
