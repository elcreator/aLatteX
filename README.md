# aLatteX

**A Latte eXtended template parser for Evolution CMS.**

Adds [Latte 3.x](https://latte.nette.org) as a third template-parser option alongside the built-in *DocumentParser* and *DLTemplate*. Templates are compiled to PHP by Latte and cached, and they can live wherever suits the project: written in the admin panel and stored in the database, or kept in `views/<alias>.latte` as files under version control. The manager's *Template code* switch chooses per template, and aLatteX registers itself as one of the engines it can create a file for.

All existing Evolution CMS template syntax is fully supported alongside Latte syntax in the same template.

---

## Requirements

- PHP 8.3+
- Evolution CMS 3.5.2+
- `latte/latte` ^3.1 (pulled in automatically via Composer)

---

## Installation

```bash
php artisan package:installrequire elcreator/alattex "*"
```

Then open **System Settings → Site** and select **aLatteX** in the *Chunk processor* radio group.

---

## Documentation

| Page | Contents |
|---|---|
| [docs/latte-syntax.md](docs/latte-syntax.md) | Latte 3 as it behaves inside Evolution CMS, and the few features that are unavailable here |
| [docs/evo-syntax.md](docs/evo-syntax.md) | The six EVO tag forms and the `evo*` Latte functions |
| [docs/interop.md](docs/interop.md) | Parse order and everything that follows from it — chunks, snippets, raw output, caching |
| [docs/demo.md](docs/demo.md) | The demo set, and how the test suite reuses it |

---

## Demo content

A working example of every construct above — six pages, six templates, five
chunks, five snippets and three template variables — can be installed into the
site:

```bash
composer demo:install     # or: cd core && php artisan alattex:demo:install
composer demo:remove      # or: cd core && php artisan alattex:demo:remove
```

Everything lands in an **aLatteX demo** category and under `/alattex-demo`, so
it is one folder in each manager tree and one subtree in the resource tree.
Both commands are idempotent, address elements by name, and prompt before
running; pass `--force` to skip the prompt.

The same files are this package's test fixtures — see [docs/demo.md](docs/demo.md).

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

### Compatibility with regular EVO template code

aLatteX works as a pre-processing layer before Evolution CMS's normal parser.
Classic EVO tags written directly in the template are protected while Latte is
rendering and restored before Evolution CMS calls `parseDocumentSource()`.

That means existing template code like this continues to be processed by
Evolution CMS after Latte finishes:

```html
{{chunk}}
[[snippet]]
[!snippet!]
[*pagetitle*]
[*content*]
[(site_name)]
[+placeholder+]
[[snippet?&id=`[*id*]`]]
```

Processing order:

```text
DB template
    -> aLatteX protects EVO tags
    -> Latte renders Latte syntax
    -> aLatteX restores EVO tags
    -> Evolution CMS parseDocumentSource() resolves EVO tags
```

Important caveats:

- Latte runs before Evolution CMS parses chunks, snippets, TVs, settings, and
  placeholders.
- Latte syntax inside ordinary `{{chunks}}` or snippet output is not processed
  later, because those values are generated after Latte has already finished.
  A chunk explicitly loaded as `chunk:<name>` is a Latte partial instead.
- Regular EVO tags written directly in the template are passed through for the
  default Evolution CMS parser to handle.

### Caching

Latte compiles each template to a PHP file stored in `storage/framework/cache/latte/`. The cache key is derived from the template content itself, so the compiled cache invalidates as soon as the template changes - whether it was saved in the admin panel or edited as a file.

Evolution CMS page-level caching (`enable_cache`) works as normal on top of this.

### Debugging

Evolution CMS bundles [Tracy](https://tracy.nette.org), and aLatteX puts itself
on its bar. Set `'active'` in `core/config/tracy.php` and a page rendered
through Latte gains an **aLatteX** panel listing the templates the request
rendered with their timings, working `{dump}` and `{dump $var}` tags, and a
BlueScreen that reports a Latte compile error against the template it came from.
Nothing to install, and nothing registered when Tracy is off - see
[docs/latte-syntax.md](docs/latte-syntax.md#debugging-with-tracy).

---

## Writing templates

Templates are written in the admin panel (*Elements → Templates*), or in a file, using standard Latte syntax. EVO tags can appear anywhere alongside Latte tags.

### In the database, or in a file

The template form's **Template code** switch has three settings:

| Setting | Where the code lives |
|---|---|
| *In the database* | the `site_templates` row, edited in the manager |
| *In a file* | `views/<templatealias>.latte`, edited wherever you edit code |
| *Automatic* | the file if one matches the alias, the database otherwise (the default) |

Choosing *In a file* with **Latte (.latte)** scaffolds the file and hands rendering to this plugin's view engine. Both routes protect EVO-looking syntax while Latte runs. A database template subsequently reaches Evolution's parser; a file template is already the final view, so restored EVO tags in it stay literal.

The switch and the engine dropdown need Evolution CMS 3.5.9 or newer. On older cores a template whose alias matches `views/<alias>.latte` is still rendered from that file — the CMS has always preferred a matching view — there is simply no UI for creating one.

### Layouts and chunk partials

Template references are flat and match the files Evolution manages:

```latte
{layout 'base.latte'}
{include 'navigation.latte'}
{include 'chunk:ProductCard', product: $product}
```

`base.latte` and `navigation.latte` resolve under `views/`; directory and
traversal names are rejected. A CMS-managed layout can be a non-selectable
file template with alias `base`. The explicit `chunk:` prefix compiles that
chunk as Latte and passes native Latte arguments. Without it, `{{ProductCard}}`
keeps the existing EVO behavior and Latte source in the chunk stays literal.

### Available variables

| Variable                         | Description |
|----------------------------------|---|
| `$evo`                           | Evolution CMS core object |
| `$documentObject`                | Full document array (all fields + TVs) |
| `$pagetitle`, `$alias`, `$id`, … | All document fields spread as top-level variables |
| `$content`                       | Raw document content (also available as `[*content*]`) |

The booted application is available too: templates may call `$evo` methods,
`app()`, application services, or Eloquent models. Prefer a snippet/service
that returns arrays or DTOs for production queries; see
[Eloquent and Laravel services](docs/interop.md#eloquent-and-laravel-services).

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

### Editing templates

With aLatteX active, the manager's template editor understands Latte as well as
EVO tags. It is the CMS's own CodeMirror, with a second overlay layered on the
one the core installs - tags, `{$variables}`, filters, `n:attributes` and
`{* comments *}` are coloured alongside `{{chunks}}` and `[[snippets]]`, and a
template kept in `views/<alias>.latte` is coloured as pure Latte, because EVO
tags in a view file stay literal.

A tag is read in two registers. The structure Latte owns - the braces, the tag
name, filters, function calls - is in the plugin's own colour; the expression
inside it is tokenised as the PHP-ish expression it is, and returned under
CodeMirror's own token names, so strings, numbers, arrays, operators, `true`,
and `$variables` follow the manager's theme in light and in dark exactly as
they do in the snippet editor. Tags spanning several lines - a `{var $rows = [
… ]}` written over four of them, a multi-line `{* comment *}` - keep their
highlighting all the way through.

The Resource editor is deliberately different: it highlights EVO tags only.
Latte renders the template and `[*content*]` is substituted afterwards, so a
`{$var}` typed into a page body is printed verbatim - see
[docs/interop.md](docs/interop.md#what-latte-does-not-see).

Completion is offered through the same dropdown the manager already uses for
chunk and snippet names. Typing `{` offers Latte's tags and functions - the
`evo*` helpers among them - `{$` offers the document fields aLatteX spreads as
variables, `|` offers filters and `n:` offers the attribute forms. The list is
asked of the engine at render time, so it is whatever the installed Latte and
its extensions really provide.

---

## File structure

```
aLatteX/
├── composer.json
├── bin/
│   └── alattex-demo.php            composer demo:install / demo:remove → artisan
├── demo/                           the demo set: manifest plus one file per element
│   ├── manifest.php
│   ├── chunks/  snippets/  templates/  documents/
├── docs/                           syntax reference and interop notes
├── patches/                        fixes that belong in the CMS, until they land there
├── plugins/
│   └── aLattexPlugin.php           Event listeners (OnLoadWebDocument, OnManagerMainFrameHeaderHTMLBlock)
└── src/
    ├── aLattexServiceProvider.php  Laravel service provider
    ├── LattexEngine.php            Latte engine wrapper + render pipeline
    ├── LatteViewEngine.php         renders views/<alias>.latte for the view factory
    ├── SourceLoader.php            names the template Latte is rendering
    ├── TracyBridge.php             wires the panel to the CMS's Tracy bar
    ├── TracyPanel.php              the aLatteX tab itself (Tracy\IBarPanel)
    ├── TemplateEditor.php          Latte highlighting + completion in the template editor
    ├── EvoSyntaxBridge.php         EVO tag protect/restore around Latte rendering
    ├── EvoExtension.php            Latte extension: evoChunk, evoSnippet, evoTv, …
    ├── Console/                    DemoInstallCommand, DemoRemoveCommand
    └── Demo/
        ├── DemoContent.php         loads demo/ as data — no CMS, no database
        └── DemoSeeder.php          writes it into a site, and takes it out again
```

---

## License

GPL-3.0-or-later
