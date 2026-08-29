# aLatteX documentation

Latte 3 as a template parser for Evolution CMS. These pages describe what you
can write in a template, what happens to it, and where the two syntaxes meet.

Every construct documented here is also *installed* by the demo, as a working
page you can open in a browser and a template you can read in the manager:

```bash
composer demo:install     # or: php artisan alattex:demo:install
composer demo:remove      # or: php artisan alattex:demo:remove
```

## The pages

| Page | What it covers |
| --- | --- |
| [latte-syntax.md](latte-syntax.md) | Latte 3 as it behaves inside Evolution CMS: tags, filters, functions, `n:` attributes, and the handful of features that are unavailable here. |
| [evo-syntax.md](evo-syntax.md) | The six Evolution CMS tag forms, the `evo*` Latte functions this plugin adds, and when to use which. |
| [interop.md](interop.md) | The order the two parsers run in, and every consequence of it - chunks, snippets, raw output, caching, and the escaping/safety model. |
| [demo.md](demo.md) | The demo set: what it installs, what each page proves, and how the test suite reuses it. |

## The one rule

```
DB template
  -> aLatteX replaces every EVO tag with an opaque token
  -> Latte compiles and renders what is left
  -> aLatteX restores the EVO tags
  -> Evolution CMS runs parseDocumentSource() over the result
```

**Latte runs first, Evolution CMS second, and neither one gets a second turn.**

Almost every surprise in this plugin follows from that sentence: a Latte
variable cannot hold a snippet's output, a chunk full of `{$vars}` prints them
as text, and `{syntax off}` stops Latte without stopping the CMS.

## Quick reference

Available in every template:

| Variable | What it is |
| --- | --- |
| `$pagetitle`, `$alias`, `$content`, … | every document field and attached TV, spread as bare variables |
| `$documentObject` | the same thing as an array |
| `$evo` | the Evolution CMS core object |

Functions this plugin registers:

| Function | Returns |
| --- | --- |
| `evoChunk('name')` | the chunk's markup, now |
| `evoSnippet('name', ['k' => 'v'])` | the tag ``[[name?&k=`v`]]`` as a string, for the CMS to run later |
| `evoUncachedSnippet('name', […])` | the tag ``[!name?&k=`v`!]`` as a string |
| `evoTv('name')` | a document field or TV value |
| `evoSetting('name')` | a system setting |
| `evoPlaceholder('name')` | a placeholder, if one was set before Latte ran |

Evolution CMS tags, passed through untouched:

| Tag | Meaning |
| --- | --- |
| `{{name}}` | HTML chunk |
| `[[name]]` | cacheable snippet |
| `[!name!]` | non-cacheable snippet |
| `[*name*]` | template variable or document field |
| `[(name)]` | system setting |
| `[+name+]` | placeholder |
| `[~123~]` | link to document 123 |
| `[^t^]` | benchmark values |
