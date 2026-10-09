---
title: Project variables
summary: Placeholders like {{url}} in texts and links.
parent: concepts
order: 50
---
# Project variables

Values like the URL of the website are defined once per project (*Administration › Variables*) and used in text fields
as placeholders: a link field with `{{url}}/imprint` is delivered by the content API as
`https://example.com/imprint`. Records keep the placeholder, so changing the variable changes every record at once.

- Placeholders work in short and long text, Markdown, URL and e-mail fields. Values are checked as they will be
  delivered, so `{{url}}/imprint` is a valid link.
- They are written `{{url}}` - or `{{project.url}}`, as in the steps of events; spaces inside are fine. Single braces
  (`{url}`) are plain text.
- Variables can be translatable, and the API then fills in the value of the requested language.
- Unknown `{{names}}` stay as they are. The admin app always shows the placeholders.
- `GET /api/v1/{project}/variables?lang=en` returns the variables themselves.
