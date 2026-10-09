---
title: Search
summary: The search index, weights and stopwords.
parent: concepts
order: 100
---
# Search

Every project has a **search index**. The text search `?s=` of the admin app and the content API uses it, and so does
the **global search** of the admin app (⌘K / Ctrl+K, or *Search …* in the sidebar).

- **What is indexed:** the searchable text fields (see *Search and filters* above) in every language. Fields that are
  not searchable stay out, and fields limited to roles are only searched for users who may read them.
- **Normalization:** text without HTML/Markdown, in lower case, accents merged (*Köln* = *koln*, *ß* = *ss*), split
  into words of at least 2 characters. Word stems are not used yet.
- **Stop words** per project (*Administration › Search*, separated by spaces) are not indexed and are ignored in searches.
- **Matching:** every word has to occur, the last one also as the start of a word (*sommerf* finds *Sommerfest*).
  Without a sort order of their own, the best matches come first.
- **Weights:** every searchable field has a weight from 1 to 10 (field dialog, `"search_weight"`, default 1, label
  fields of existing entities 3). The score is how often a term occurs (at most 3) times the weight of its field.
  Weights count when searching, so changing one takes effect at once without a rebuild.
- **Up to date:** saving, importing and deleting records updates the index right away. After changes the index cannot
  follow on its own (stop words, languages, *searchable*, field types), an entity's index is *stale*. Its text search
  then goes to the columns as before, until the index is rebuilt.
- **Rebuild and clear** in *Administration › Search*, for all entities or one, or on the command line with
  `./yii search:rebuild [--project=…] [--entity=…]`.
- **Global search:** the best 5 records per entity the user may read, the entities with the most matches first.
  *Show … only* switches to `in:pages summer`, which searches one entity and returns up to 50 records.

```
GET    /api/v1/search?s=summer            global search (admin app), "in:<entity> …" for one entity
GET    /api/v1/admin/search-index         stop words, status per entity
PUT    /api/v1/admin/search-index/stopwords   {"stopwords": "der die das"} (or a list)
POST   /api/v1/admin/search-index/rebuild     {"entity": "pages"} (empty: all)
POST   /api/v1/admin/search-index/clear       {"entity": "pages"} (empty: all)
```
