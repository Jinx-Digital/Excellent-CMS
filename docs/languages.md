---
title: Languages
summary: Translatable fields and how the API delivers languages.
parent: concepts
order: 20
---
# Languages

A project has a list of languages, and the first one is the default language. Text fields (short and long text,
Markdown, URL, slug) can be **translatable**. The default language stays in the field's column, and every other
language gets its own column `<field>__<language>`. Display field, search, sorting, required fields and slug sources
work on the default language.

- In the admin app, the record form has a tab per language. Empty translations fall back to the default language.
- A translatable **slug** is unique per language and made from its source field in the same language: the English
  slug from the English title, the German slug from the German title.
- Adding a language adds its columns, and removing one deletes its texts (the app asks first). Choosing another
  default language swaps the texts, so the new default moves into the fields' columns.
- Imports fill the default language.
