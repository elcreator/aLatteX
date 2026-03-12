# aLatteX

**A Latte eXtended template parser for Evolution CMS.**

Adds [Latte 3.x](https://latte.nette.org) as a third template-parser option alongside the built-in *DocumentParser* and *DLTemplate*. Templates are created and edited directly in the CMS admin panel, saved to the database, compiled to PHP by Latte, and cached — no filesystem template files required.

All existing Evolution CMS template syntax is fully supported alongside Latte syntax in the same template.

---

## Requirements

- PHP 8.3+
- Evolution CMS 3.5.2+
- `latte/latte` ^3.1 (pulled in automatically via Composer)

---

## Installation

```bash
php artisan package:installrequire evolution-cms/a-latte-x "*"
```

Then open **System Settings → Site** and select **aLatteX** in the *Chunk processor* radio group.

---

## How it works

### Rendering pipeline

```
DB template
    │
    ▼
EvoSyntaxBridge::protect()   — EVO tags replaced with __ALATTEX_N__ tokens
    │
    ▼
Latte::renderToString()      — Latte processes {$vars}, {if}, {foreach}, etc.
    │
    ▼
EvoSyntaxBridge::restore()   — tokens replaced back with original EVO tags
    │
    ▼
EVO parseDocumentSource()    — EVO resolves {{chunks}}, [[snippets]], [*tvs*], etc.
```

The bridge ensures Latte never sees Evolution CMS tags, so neither parser interferes with the other.

### Caching

Latte compiles each template to a PHP file stored in `storage/framework/cache/latte/`. The cache key is derived from the template content, so the compiled cache automatically invalidates whenever a template is saved in the admin panel.

Evolution CMS page-level caching (`enable_cache`) works as normal on top of this.

---

## Writing templates

Templates are written in the admin panel (*Elements → Templates*) using standard Latte syntax. EVO tags can appear anywhere alongside Latte tags.

### Available variables

| Variable | Description |
|---|---|
| `$modx` | Evolution CMS core object |
| `$documentObject` | Full document array (all fields + TVs) |
| `$pagetitle`, `$alias`, `$id`, … | All document fields spread as top-level variables |
| `$content` | Raw document content (also available as `[*content*]`) |

### Example template

```latte
<!DOCTYPE html>
<html lang="{evoSetting('manager_language')}">
<head>
    <meta charset="utf-8">
    <title>{$pagetitle} — {evoSetting('site_name')}</title>
    {{head_chunk}}
</head>
<body>

{* Latte conditional *}
{if $longtitle}
    <h1>{$longtitle}</h1>
{else}
    <h1>{$pagetitle}</h1>
{/if}

{* Classic EVO chunk — processed after Latte *}
{{nav_chunk}}

{* EVO template variable *}
<main>[*content*]</main>

{* EVO cacheable snippet *}
[[Breadcrumbs?&id=`[*id*]`]]

{* EVO non-cacheable snippet *}
[!RandomBanner!]

{* EVO placeholder set by a snippet *}
[+some_placeholder+]

{{footer_chunk}}

</body>
</html>
```

### Native Latte helper functions

These are registered by the plugin as first-class Latte functions and can be used anywhere in `{...}` expressions:

| Function | Description                                                       |
|---|-------------------------------------------------------------------|
| `{evoChunk('name')}` | Render an HTML chunk immediately                                  |
| `{evoSnippet('name', ['p' => 'v'])}` | Output a cacheable `[[snippet]]` EVO tag for later processing     |
| `{evoUncachedSnippet('name', ['p' => 'v'])}` | Output a non-cacheable `[!snippet!]` EVO tag for later processing |
| `{evoTv('name')}` | Current document TV / field value                                 |
| `{evoSetting('name')}` | System setting value                                              |
| `{evoPlaceholder('name')}` | Placeholder previously set via `evo()->setPlaceholder()`          |

> **Tip:** `evoSnippet` and `evoUncachedSnippet` return the raw EVO tag string, not the snippet output. This ensures snippet caching behaviour is preserved — the tag is resolved in EVO's own parsing pass after Latte finishes.

### Supported EVO syntax (pass-through)

The following tags are transparently passed through Latte and resolved by Evolution CMS after Latte rendering:

| Syntax | Meaning |
|---|---|
| `{{chunkName}}` | HTML chunk |
| `[[snippetName]]` | Cacheable PHP snippet |
| `[!snippetName!]` | Non-cacheable PHP snippet |
| `[*templateVariable*]` | Template variable / document field |
| `[(configSetting)]` | System setting |
| `[+placeholder+]` | Placeholder |

Parameters follow standard EVO syntax:

```
[[snippetName?&param1=`value1`&param2=`value2`]]
```

---

## Admin panel

When the plugin is installed, opening **System Settings** shows an **aLatteX** radio button added to the *Chunk processor* group next to *DocumentParser* and *DLTemplate*.

Selecting **aLatteX** and saving enables Latte template processing site-wide.

---

## File structure

```
aLatteX/
├── composer.json
├── plugins/
│   └── aLattexPlugin.php          Event listeners (OnLoadWebDocument, OnManagerMainFrameHeaderHTMLBlock)
└── src/
    ├── aLattexServiceProvider.php  Laravel service provider
    ├── LattexEngine.php            Latte engine wrapper + render pipeline
    ├── EvoSyntaxBridge.php         EVO tag protect/restore around Latte rendering
    └── EvoExtension.php            Latte extension: evoChunk, evoSnippet, evoTv, …
```

---

## License

GPL-2.0-or-later
