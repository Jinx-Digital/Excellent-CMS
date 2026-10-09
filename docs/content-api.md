---
title: Content API
summary: The REST API websites read (and write) the content with.
order: 50
---
# Content API

```
GET  /api/v1/{project}/content                  readable entities with their fields
GET  /api/v1/{project}/content/{entity}         ?page, limit, s, sort=-field, filter[field][op]=…, fields=a,b, include=reference, lang, tree
GET  /api/v1/{project}/content/{entity}/{id}    ?fields, include, lang
POST /api/v1/{project}/oauth/token              grant_type=client_credentials (RFC 6749 §4.4)
```
