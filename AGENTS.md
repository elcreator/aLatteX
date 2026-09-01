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
bin/
  alattex-demo.php         composer demo:install / demo:remove → finds artisan, shells out
demo/
  manifest.php             The demo set as data: names, relationships, metadata
  chunks/ snippets/ templates/ documents/   One file per element body
  views/                    The layouts. The only part of the set that is a file on the site, because
                            {extends} resolves a name to a file - a layout in the database cannot be
                            extended. DemoSeeder writes them into config('view.paths')[0] on install,
                            skips one already there whose contents differ, and on remove deletes only
                            the files still identical to what it wrote.
docs/                      User- and agent-facing syntax reference
patches/                   Fixes that belong in the CMS, kept until they are upstream
plugins/
  aLattexPlugin.php        Three Event::listen calls — the only runtime entry points
src/
  aLattexServiceProvider.php  Extends EvolutionCMS\ServiceProvider
  LattexEngine.php            Wraps Latte\Engine; owns the render pipeline
  LatteViewEngine.php         Illuminate view Engine for views/<alias>.latte
  SourceLoader.php            Latte\Loader: roots, flat views and chunk: partials
  TracyBridge.php             Wires the panel on; the only place Latte's Tracy bridge is touched
  TracyPanel.php              The aLatteX tab itself, against Tracy\IBarPanel
  EvoSyntaxBridge.php         Regex-based protect/restore for EVO tag syntax
  TokenSecret.php             The plugin's own key, behind the token HMAC
  DocumentObject.php          Flattens documentObject: a TV is [name, value, ...]
  ManagerEditor.php           CodeMirror on the Resource content field, EVO-tag mode
  TemplateEditor.php          Latte overlay + completion on the template editor
  EvoExtension.php            Latte\Extension subclass; adds evo* functions
  Console/                    DemoInstallCommand, DemoRemoveCommand (console only)
  Demo/
    DemoContent.php           Loads demo/ into arrays. No models, no container, no evo()
    DemoSeeder.php            install()/remove() against the CMS database
vendor/                    Managed by Composer — never edit
tmp/                       Reference Evolution CMS packages — never modify
```

---

## Architecture

### Rendering pipeline

```
OnLoadWebDocument event
  → LattexEngine::render($content, $documentObject)
      → EvoSyntaxBridge::beginRender()  reset the per-render token map
      → SourceLoader::getContent()      load/protect roots, layouts and partials
      → Latte\Engine::renderToString()  process Latte syntax
      → EvoSyntaxBridge::restore()      restore EVO tags
  → evo()->documentContent = result
  (EVO's parseDocumentSource() runs next, handling the restored EVO tags)
```

### Admin panel injection

Two handlers, both returning a `<script>` and neither overriding a Blade view.

`OnSiteSettingsRender` fires while the system-settings page is built; its output is printed inside the Site tab (`system_settings/general.blade.php`), below the fields. The handler clones the "DLTemplate" radio, relabels it "aLatteX" and appends it to the `chunk_processor` group. The event matters: it renders *after* the radios and *before* the page's own script, so the option exists by the time the CMS calls `setChangesChunkProcessor()`. From the manager header — where this used to live — the injection could only run at `DOMContentLoaded`, by which point that call had already thrown on a `null` `:checked` lookup, since none of the two options the CMS renders is the stored `aLatteX`.

`OnDocFormRender` fires while the document form is built, and its output lands inside the form below the content textarea. The handler hands over to `src/ManagerEditor.php`, which puts CodeMirror on the field with an EVO-tag overlay on `htmlmixed`. Evolution leaves that one field bare: the CodeMirror plugin only initialises when `$rte === 'none'`, and a stock install has `use_editor = 1` with `which_editor = 'TinyMCE4'` (`core/factory/settings.php`) while no rich-text editor is in the base install set — so `$rte` names an editor that is not there, CodeMirror stays out, and nothing else steps in. It stands down when `myCodeMirrors['ta']` already exists, when the page renders a `which_editor` picker set to a real editor, and when the CMS ships no CodeMirror assets to load.

**It highlights EVO tags only, and that is deliberate.** Latte renders the *template* at `OnLoadWebDocument`; `[*content*]` is substituted afterwards by EVO's parser pass, so a document's content field arrives as data. Verified on a real site: content `LATTE[{$pagetitle}] EVO[[*pagetitle*]]` renders as `LATTE[{$pagetitle}] EVO[aLatteX probe]`. Colouring Latte in that field would advertise a feature the pipeline does not have. The template editor is where Latte is live, and it is still highlighted by the core's EVO-only MODx mode — a Latte-aware mode there would be a real improvement and is not done.

`OnTempFormRender` fires while the template form is built, and its output lands
at the end of the form - alongside the CodeMirror plugin's, which the same event
prints. `src/TemplateEditor.php` does not build an editor there: the CMS already
does, and CodeMirror overlays stack, so Latte is added as a second overlay on
top of the core's EVO one. Two modes, because a database template is read by
Latte *and* by the EVO parser while a `views/<alias>.latte` file is read only by
Latte - so the file case drops the EVO layer, for the same reason the content
field never gains a Latte one.

The overlay tokenises a tag body rather than swallowing it: `overlay()` hands
off to `expression()` once inside `{...}`, and carries `expr`/`str`/`open` in
its state so a tag, a string or a comment can span lines. Expression parts are
returned under CodeMirror's own token names (`string`, `number`, `operator`,
`keyword`, `atom`, `bracket`, `property`), which both shipped stylesheets
already colour - so only `latte*` classes need CSS of their own, and a theme
change costs nothing here.

Completion goes through `CodeMirror.showHint`, the addon
`manager/media/script/element-name-helper.js` already uses for `{{chunk}}` and
`[[snippet]]` names. The two never answer the same keystroke: that one owns
`{{`, `[[`, `[!` and `@`, this one owns a single `{`, `{$`, `|` and `n:`. The
vocabulary is `LattexEngine::vocabulary()` - asked of the live engine, so it
carries whatever an extension contributes and cannot drift from it.

**Two things about the core's editor are worth knowing before touching this.**
The overlay mode is named `MODx-htmlmixed` on cores from before the rename and
`Evo-htmlmixed` after it, so both are looked for. And
`manager/views/page/template.blade.php` resets the mode to a name with no
overlay in it - from its own `DOMContentLoaded` handler, after the CodeMirror
plugin's inline script has built the editor - which switches the EVO
highlighting off on every template page of a site that has any template-file
engine registered. aLatteX registers one, so `TemplateEditor` wraps `setOption`
on its own instance and puts the mode back. The fix for the core itself is
`patches/evo-template-highlighting.patch`.

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
- **`documentObject` is not flat.** Document fields are scalars, but
  `Core::getDocumentObject()` merges each TV in as
  `[name, value, display, display_params, type]`. The EVO parser reads
  `$value[1]`; so must anything else. `src/DocumentObject.php` does it for the
  template variables and for `evoTv()`. A fixture that puts a plain string
  under a TV name is testing a shape the CMS never produces — which is exactly
  how `{$alxSubtitle|upper}` once reached a live page as `Array`.

### Verifying against a real CMS

The unit suite runs without a site, which is what let the `documentObject`
shape bug through. `ci/compose-evo.sh` builds a real one on sqlite, and it is
worth doing for anything that touches the render pipeline or the demo:

```sh
ci/compose-evo.sh /tmp/evo --evo-src ../../evolution --dev --install
ci/run-tests.sh /tmp/evo
cd /tmp/evo && php core/artisan alattex:demo:install --force
```

Then set `chunk_processor` to `aLatteX`, delete
`core/storage/bootstrap/siteCache.idx.php`, serve the tree and read the pages.
Latte errors do not surface in the output — the plugin logs them and leaves the
document unrendered — so check `evo_event_log` when a page comes back as raw
template source.

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

- `Engine::setLoader(new StringLoader())` — `StringLoader(null)` uses the content string itself as the unique ID. aLatteX uses `SourceLoader`, which loads registered roots, flat `views/<alias>.latte` references and explicit `chunk:<name>` partials. Its unique ID is the readable name plus protected source: identical files retain distinct source mapping, and an edit creates a new compiled class immediately.
- **Latte's Tracy bridge classes are `@internal`.** `Latte\Bridges\Tracy\LattePanel` is marked `@internal` and its only non-warning constructor `@deprecated`; `BlueScreenPanel` is `@internal` too. A patch release may move either, so the bar panel is *not* built on them: `src/TracyPanel.php` implements `Tracy\IBarPanel`, which carries no `@internal` and is two methods wide. The panel deliberately reports roots, layouts and partials as a flat timed list instead of depending on Latte's internal nesting panel.
- `BlueScreenPanel::initialize()` is the one `@internal` call that remains, because reimplementing the compiled-PHP-to-`.latte` source mapping is not worth it. It is reached through `class_exists()` + `method_exists()` inside a `try/catch`, and `TracyBridge::extension()` swallows any Throwable from setup — a debugging aid must not be able to take down the site it is meant to help debug.
- `Tracy\Debugger::isEnabled()` is the switch. `EvolutionCMS\ExceptionHandler` registers `TracyServiceProvider` (which calls `Debugger::enable()` when `tracy.active` resolves truthy) and `Core::initialize()` resolves that handler at the top of the request — before any event fires, so the lazily-made engine always sees a settled answer. It stays true in Tracy's production mode, where the bar is collected and never printed.
- `Engine::setTempDirectory(string)` — compiled PHP cache location.
- `Engine::addExtension(Extension)` — register tags, filters, functions.
- `Extension::getFunctions(): array` — `['functionName' => callable]`.
- `Engine::renderToString(string $name, array|object $params)` — returns rendered HTML.

---

## What to change and where

| Task | File(s) to edit |
|---|---|
| Add a new EVO-style helper function in Latte | `src/EvoExtension.php` → `getFunctions()` |
| Support an additional EVO tag syntax pattern | `src/EvoSyntaxBridge.php` → `DELIMITERS` constant (and `NAME_START`/`NAME_REST` if the name grammar changes) |
| Change the Latte cache location | `src/LattexEngine.php` → `resolveCacheDir()` |
| Change what the Tracy panel shows, or its config keys | `src/TracyBridge.php` |
| Change how a template is named in the panel and in errors | `src/LattexEngine.php` → `templateName()` / `renderView()` |
| Change flat file or `chunk:` partial resolution | `src/SourceLoader.php` |
| Add/remove Latte variables available in templates | `src/LattexEngine.php` → `render()`, `$params` array |
| Change where the chunk_processor radio is injected | `plugins/aLattexPlugin.php` → the `OnSiteSettingsRender` listener |
| Change the Resource content editor (mode, theme, options) | `src/ManagerEditor.php` |
| Change Latte highlighting or completion in the template editor | `src/TemplateEditor.php` |
| Add Latte filters or tags | `src/EvoExtension.php` → `getFilters()` / `getTags()` |
| Register routes, migrations, or views | `src/aLattexServiceProvider.php` → `boot()` |
| Add or change a demo page/element | `demo/manifest.php` plus the file it names |
| Change how the demo is written to the DB | `src/Demo/DemoSeeder.php` |
| Document a syntax rule for users and agents | `docs/` — and add it to the demo, so it is tested |

---

## What not to do

- Do not edit anything under `vendor/` or `tmp/`.
- Do not call `evo()->parseDocumentSource()` on a template held in the database — the core runs it automatically once `OnLoadWebDocument` completes, and a second call parses the page twice. The exception is the view-file path, where the core's `if (!$template)` gate means it never runs at all: `alattexFinishViewRender()` does it there, and only there, so a template moved into `views/<alias>.latte` keeps its meaning.
- Do not add a second `protect()`/`restore()` cycle. `EvoSyntaxBridge` accumulates
  tokens from every source in one top-level render: `beginRender()` resets the
  map once, while each loader `getContent()` protects and adds to it. Resetting
  inside `protect()` loses the root or layout tokens.
- Do not override core Blade views to inject the admin panel option; use the JS injection approach already in place.
- Do not return `null` from event listeners — return `''` to produce no output.
- Do not make the bridge's token prefix predictable, and do not derive it from
  anything random per request. It must be unguessable (a guessable token lets a
  rendered value become live EVO syntax — demonstrated executing on a live site)
  **and** stable per template (Latte's cache id is the protected string, so a
  changing prefix recompiles every template on every request). The HMAC in
  `EvoSyntaxBridge::protect()` is what satisfies both.
- Do not put the token key in `system_settings`: any template could print it as
  `[(setting)]`. It lives in `storage/alattex/token.key`.
- Do not reuse the application key for it. Only this plugin's key should be
  exposed, however indirectly, by a truncated HMAC in a compiled template.
- Do not interpolate untrusted values into EVO tag syntax without going through
  `EvoExtension::sanitiseValue()` / `EvoSyntaxBridge::isElementName()`.

---

## The demo set is also the fixture set

`demo/` serves two audiences at once, and both must keep working:

- **Humans.** `composer demo:install` writes it into a site; `composer
  demo:remove` takes it out. Both are idempotent and match elements by name —
  never by prefix or wildcard, so an element a user renamed is never swept up.
- **CI.** `tests/Integration/DemoContentTest.php` renders every demo template
  through `LattexEngine` against the stub core in `tests/bootstrap.php`.

That is why `DemoContent` is free of models, of the container and of `evo()`:
the moment it needs a CMS, the tests need one too. Keep the database in
`DemoSeeder` and nowhere else.

A syntax claim in `docs/` should have a line in a demo template that
demonstrates it and an assertion in `DemoContentTest` that pins it. When adding
Latte constructs to a demo template, render them before committing — several
plausible-looking forms do not compile (`{sep}` outside `{foreach}`, a filter
before `as` in `n:foreach`, a filter in `{var}` without parentheses, `{'x'|f}`
where the brace is followed by a quote).

## Testing checklist

When making changes, verify manually:

1. **Basic render** — create a template with `{$pagetitle}`, save, view a page using it.
2. **EVO tag pass-through** — use `{{chunk}}`, `[[snippet]]`, `[*tv*]` in the same template; all should resolve correctly.
3. **Latte helpers** — use `{evoChunk('name')}`, `{evoSetting('site_name')}` in a template.
4. **Latte cache** — edit a template, verify the rendered output updates (cached file invalidated).
5. **Admin panel** — open System Settings, confirm "aLatteX" radio appears after "DLTemplate".
6. **Other parsers unaffected** — switch `chunk_processor` to `''` (DocumentParser); confirm Latte plugin no longer processes templates.
7. **Error handling** — introduce a Latte syntax error; confirm a CMS event log entry appears and the site does not fatal.
