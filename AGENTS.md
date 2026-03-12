# AGENTS.md — aLatteX

Guidelines for AI agents working on this codebase.

---

## Project overview

`aLatteX` is an Evolution CMS plugin (type `evolutioncms-plugin`) that adds Latte 3.x as a template parser. It hooks into Evolution CMS's event system and Laravel service-provider lifecycle.

Key constraint: **no core Evolution CMS files are modified**. All integration is done through events, service providers, and published assets.

---

## Repository layout

```
composer.json              Package manifest (type: evolutioncms-plugin)
plugins/
  aLattexPlugin.php        Two Event::listen calls — the only runtime entry points
src/
  aLattexServiceProvider.php  Extends EvolutionCMS\ServiceProvider
  LattexEngine.php            Wraps Latte\Engine; owns the render pipeline
  EvoSyntaxBridge.php         Regex-based protect/restore for EVO tag syntax
  EvoExtension.php            Latte\Extension subclass; adds evo* functions
vendor/                    Managed by Composer — never edit
tmp/                       Reference Evolution CMS packages — never modify
```

---

## Architecture

### Rendering pipeline

```
OnLoadWebDocument event
  → LattexEngine::render($content, $documentObject)
      → EvoSyntaxBridge::protect()      tokenise EVO tags
      → Latte\Engine::renderToString()  process Latte syntax
      → EvoSyntaxBridge::restore()      restore EVO tags
  → evo()->documentContent = result
  (EVO's parseDocumentSource() runs next, handling the restored EVO tags)
```

### Admin panel injection

`OnManagerMainFrameHeaderHTMLBlock` fires on every manager page render. The handler checks `$_GET['a'] === '17'` (system settings page) and returns a `<script>` that clones the "DLTemplate" radio button, relabels it "aLatteX", and appends it to the `chunk_processor` group. No Blade views are overridden.

### Activation

The plugin is active when `chunk_processor` system setting equals `'aLatteX'`. The event handler checks this on every request; there is no separate enabled/disabled flag.

---

## Evolution CMS conventions

- **Service providers** extend `EvolutionCMS\ServiceProvider` (not Laravel's base class).
- `register()` — bind singletons, call `loadPluginsFrom()`, `loadSnippetsFrom()`, `loadChunksFrom()`.
- `boot()` — load migrations, views, translations, call `publishes()`.
- **Plugin files** in `plugins/` are plain PHP files loaded by `loadPluginsFrom()`. They use `Event::listen('evolution.<EventName>', callable)`.
- `invokeEvent()` collects all non-null listener return values into an array. Return `''` (empty string) to opt out — do **not** return `null`.
- **Action IDs**: system settings page = `17`, save settings = `30`.
- **`$_GET['a']`** is the canonical way to read the current manager action.

### EVO template syntax reference

| Tag | Type |
|---|---|
| `{{name}}` | HTML chunk |
| `[[name]]` | Cacheable snippet |
| `[!name!]` | Non-cacheable snippet |
| `[*name*]` | Template variable / document field |
| `[(name)]` | System setting |
| `[+name+]` | Placeholder |

Snippet parameters: `[[name?&key=\`value\`&key2=\`value2\`]]`

---

## Latte 3.x API notes

- `Engine::setLoader(new StringLoader())` — `StringLoader(null)` uses the content string itself as the unique ID; Latte hashes it when naming cache files.
- `Engine::setTempDirectory(string)` — compiled PHP cache location.
- `Engine::addExtension(Extension)` — register tags, filters, functions.
- `Extension::getFunctions(): array` — `['functionName' => callable]`.
- `Engine::renderToString(string $name, array|object $params)` — returns rendered HTML.

---

## What to change and where

| Task | File(s) to edit |
|---|---|
| Add a new EVO-style helper function in Latte | `src/EvoExtension.php` → `getFunctions()` |
| Support an additional EVO tag syntax pattern | `src/EvoSyntaxBridge.php` → `PATTERNS` constant |
| Change the Latte cache location | `src/LattexEngine.php` → `resolveCacheDir()` |
| Add/remove Latte variables available in templates | `src/LattexEngine.php` → `render()`, `$params` array |
| Change which admin page the radio is injected on | `plugins/aLattexPlugin.php` → action check |
| Add Latte filters or tags | `src/EvoExtension.php` → `getFilters()` / `getTags()` |
| Register routes, migrations, or views | `src/aLattexServiceProvider.php` → `boot()` |

---

## What not to do

- Do not edit anything under `vendor/` or `tmp/`.
- Do not call `evo()->parseDocumentSource()` from within the plugin — it runs automatically after `OnLoadWebDocument` completes.
- Do not add a second `protect()`/`restore()` cycle; `EvoSyntaxBridge` is stateful per render call and must not be reused across requests (it is instantiated inside `LattexEngine` which is a singleton — the token map is reset at the start of each `protect()` call, which is correct).
- Do not override core Blade views to inject the admin panel option; use the JS injection approach already in place.
- Do not return `null` from event listeners — return `''` to produce no output.

---

## Testing checklist

When making changes, verify manually:

1. **Basic render** — create a template with `{$pagetitle}`, save, view a page using it.
2. **EVO tag pass-through** — use `{{chunk}}`, `[[snippet]]`, `[*tv*]` in the same template; all should resolve correctly.
3. **Latte helpers** — use `{evoChunk('name')}`, `{evoSetting('site_name')}` in a template.
4. **Latte cache** — edit a template, verify the rendered output updates (cached file invalidated).
5. **Admin panel** — open System Settings, confirm "aLatteX" radio appears after "DLTemplate".
6. **Other parsers unaffected** — switch `chunk_processor` to `''` (DocumentParser); confirm Latte plugin no longer processes templates.
7. **Error handling** — introduce a Latte syntax error; confirm a CMS event log entry appears and the site does not fatal.
