# The demo set

A working site's worth of aLatteX: six pages, six templates, six chunks, five
snippets and three template variables, installed into a real Evolution CMS
database so you can open them in a browser and read them in the manager.

The same files are this package's test fixtures, so what the documentation
claims, what a developer clicks through, and what CI renders are the same bytes.

---

## Installing

From the plugin directory inside an Evolution CMS tree:

```bash
composer demo:install
composer demo:remove
```

Or straight from the CMS, which is the same thing without the wrapper:

```bash
cd core
php artisan alattex:demo:install
php artisan alattex:demo:remove
```

Both commands confirm before doing anything; pass `--force` to skip the prompt
in a script. `composer demo:install -- --force` passes the flag through.

The composer scripts look for `artisan` by walking up from the package
directory, which covers both layouts a plugin ends up in
(`core/vendor/<vendor>/<package>` and `core/custom/packages/<slug>`). Set
`EVO_CORE_PATH=/path/to/site/core` to skip the search.

Set **System Settings -> Site -> Chunk processor** to **aLatteX** before
looking at the pages, or they will be rendered by whichever parser is selected
and the Latte tags will show up as text. `demo:install` warns when it notices.

### Without a site of your own

`ci/` builds one in Docker - an Evolution CMS on sqlite with this plugin
installed - and serves it on <http://localhost:8080> (manager `admin` /
`Passw0rd123`):

```sh
EVO_SRC=/path/to/evolution docker compose -f ci/compose.yaml up serve
docker compose -f ci/compose.yaml exec serve php /build/core/artisan alattex:demo:install --force
```

Friendly URLs are off in that build, for the reason explained in
[ci/README.md](../ci/README.md), so the demo index links to `index.php?id=N`.
The templates ask the CMS for those URLs with `makeUrl()` rather than
assembling them, so they are correct either way.

### What it writes

Everything goes into a category called **aLatteX demo**, so it is one folder in
each element tree in the manager, and one subtree - `/alattex-demo` and its five
children - in the resource tree.

Both directions are idempotent and addressed **by name**: installing twice
updates rather than duplicates, and removing deletes only the names
`demo/manifest.php` lists. An element you renamed is left alone. The category
itself is only dropped if nothing else was filed under it.

Removal is permanent for the documents - they are force-deleted rather than
sent to the recycle bin, so a later reinstall does not collide with them.

---

## The pages

| Page | Template | What it proves |
| --- | --- | --- |
| `/alattex-demo` | `home.latte` | A realistic layout: one `{define}` filled per section, an index built in Latte, the same index built by a snippet through a chunk. |
| `/alattex-basics` | `basics.latte` | Latte 3 in full, including a CMS chunk loaded explicitly as a Latte partial with arguments. |
| `/alattex-evo` | `evo-syntax.latte` | All six EVO tag forms verbatim, then the same six through the `evo*` Latte functions, then a snippet call assembled inside a `{foreach}`. |
| `/alattex-chunks` | `chunks-and-snippets.latte` | Why Latte in an ordinary chunk prints literally, and the explicit ways round it. |
| `/alattex-raw` | `raw-output.latte` | `{syntax off}`, `{l}`/`{r}`, escaping contexts, and the fact that none of it stops the CMS parser. Plus the two brace traps. |
| `/alattex-tvs` | `tvs-and-fields.latte` | Document fields, three TV types, `$documentObject` and `$evo` as Latte values. |

### What is in a page's own content

Every page carries two blocks in its content field, on top of its introduction:

* **Read this page's template** - names the template that produced the page,
  where to find it in the manager and in the package, and the concrete
  landmarks in it worth reading (`{define layout, ...}`, section `#printing`,
  and so on). The demo is a side-by-side exercise; this is the pointer that
  says which file to open beside it.
* **Live in this content field** - working examples typed straight into the
  content. They are EVO tags, and deliberately so: Latte renders the *template*
  and finishes before `[*content*]` is substituted, so a `{$var}` in a content
  field prints verbatim while `[*pagetitle*]`, `{{chunks}}` and `[[snippets]]`
  all run. The `basics` page shows both halves of that in one list, and the
  `chunks` page pulls the same chunk twice - once literal, once through
  `aLatteXDemoLatte` - without leaving the content field.

## The elements

| Chunk | Point |
| --- | --- |
| `aLatteXDemoHeader` | An ordinary chunk: EVO tags only. |
| `aLatteXDemoNav` | A chunk that calls a snippet. |
| `aLatteXDemoBadge` | Included from another chunk, and from PHP. |
| `aLatteXDemoFooter` | `[[cacheable]]` beside `[!non-cacheable!]`, plus a nested chunk. |
| `aLatteXDemoCard` | Latte *source* in a chunk - literal until something renders it. |
| `aLatteXDemoPartial` | Explicitly included through `chunk:` and rendered as Latte with native arguments. |

| Snippet | Point |
| --- | --- |
| `aLatteXDemoList` | Parameters, `setPlaceholder()`, `getChunk()`. |
| `aLatteXDemoClock` | The smallest possible snippet; makes the cacheable/non-cacheable difference visible on reload. |
| `aLatteXDemoLatte` | Renders a chunk or an inline string through aLatteX itself - the second pass. |
| `aLatteXDemoNested` | Returns EVO tags *and* calls `runSnippet()`: both ways of nesting. |
| `aLatteXDemoRows` | Returns an **array** of documents, for the template to loop over during the Latte pass. |

| TV | Type | Point |
| --- | --- | --- |
| `alxSubtitle` | text | The same value read three ways. |
| `alxTags` | checkbox | A `||`-joined multi-value, split in Latte with `|explode`. |
| `alxImage` | image | Left empty, so the templates show a guarded read. |

---

## The layout on disk

```
demo/
  manifest.php        names, relationships, metadata - no bodies, no database
  chunks/*.html
  snippets/*.php      stored with their <?php opener, which the CMS strips
  templates/*.latte
  documents/*.html    the content field of each page
```

`manifest.php` is the join: a document names its template, a TV names the
templates it attaches to. Parents are listed before their children, so the
installer resolves the tree in one pass.

---

## Reusing it

`Elcreator\aLatteX\Demo\DemoContent` loads the manifest with every body read
in. It touches no models, no container and not even `evo()`, which is what lets
the same fixtures serve both the installer and the test suite:

```php
DemoContent::chunkMap();               // name => body, as evo()->getChunk() answers
DemoContent::templateMap();            // name => Latte source
DemoContent::documentObject('alattex-tvs');  // fields + TV values, as the CMS assembles them
```

`tests/Integration/DemoContentTest.php` uses exactly those three to render every
demo page against the stub core in `tests/bootstrap.php`, and asserts what the
documentation claims: that the six EVO tag forms come out untouched, that
`evoSnippet()` produces an unescaped tag, that a Latte chunk stays literal, that
`{syntax off}` stops Latte but not the CMS, and that no `__ALATTEX_` token ever
leaks into a page.

`Elcreator\aLatteX\Demo\DemoSeeder` is the other consumer - the part that does
talk to the database. `install()` and `remove()` both return a log of what they
did, which is what the two artisan commands print.
