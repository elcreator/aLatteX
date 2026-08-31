# Where the two parsers meet

Everything on this page follows from one fact:

```
DB template
  -> aLatteX replaces every EVO tag with an opaque token
  -> Latte compiles and renders what is left
  -> aLatteX restores the EVO tags
  -> Evolution CMS runs parseDocumentSource() over the result
```

**Latte runs first, Evolution CMS second, and neither gets a second turn.**

The demo pages **Chunks and snippets** and **Raw output** are this document,
rendered.

---

## What Latte does not see

Latte sees the template, and only the template. A document's **content** field is
substituted into it by `parseDocumentSource()` - which runs *after* Latte has
finished - so Latte syntax typed into a resource is printed exactly as written:

| In the content field | On the page |
| --- | --- |
| `[*pagetitle*]` | `Latte basics` - an EVO tag, resolved by the parser that owns it |
| `{if true}yes{/if}` | `{if true}yes{/if}` - Latte never ran on this string |

The same goes for chunk and snippet output, for the same reason - see
[Latte inside a chunk](#latte-inside-a-chunk) for what to do instead.

This is why the manager's Resource editor highlights EVO tags and not Latte
ones, while the template editor highlights both. The colouring follows the
pipeline: what is coloured as code is what runs as code.

### Chunks called from the content field

**They work, exactly as they always did.** `parseDocumentSource()` owns the
content field, and expanding `{{chunks}}` is its job. Writing this in a
resource:

```
{{aLatteXDemoBadge}}
```

puts the chunk on the page, and the `[(chunk_processor)]` inside that chunk is
resolved too - a tag produced by a chunk is picked up by a later pass, the same
as in a template:

```html
<span class="alx-badge">chunk_processor = aLatteX</span>
```

What does *not* happen is Latte. A chunk called from the content field is
inserted as markup, so Latte syntax in the chunk reaches the page verbatim -
the same rule as a chunk called from a template, and with the same three ways
out below. Nothing about being called from the content changes it: Latte
finished before either the content or the chunk existed on the page.

### `[*content*]` and `{$content}` are not the same thing

The document's content is reachable two ways, and only one of them keeps EVO
tags working:

| In the template | `{{chunk}}` in the content | `[[snippet]]` in the content |
| --- | --- | --- |
| `[*content*]` | expanded | expanded |
| `{$content}` | **broken**, silently | expanded |

`{$content}` prints a *value*, and Latte's HTML escaper defends against
client-side template injection by breaking a double brace apart - `{{name}}`
comes out as `{<!-- -->{name}}`, which the CMS then does not recognise. The
square-bracket forms are not a mustache and are left alone, so a snippet call in
the same string still runs.

So use `[*content*]` for the page body. Reach for `{$content}` only to derive
something from it - `{$content|stripHtml|truncate:160}` for a meta description,
say - where breaking a chunk tag is the safe outcome rather than a surprise.

---

## Latte inside a chunk

A chunk is expanded by `mergeChunkContent()`, long after Latte has finished.
Latte is never invoked on it. So a chunk containing `{$title|upper}` puts those
fourteen characters on the page - and this is true whether you include it with
`{{chunkName}}` or with `{evoChunk('chunkName')}`, because `evoChunk()` returns
the chunk's markup rather than compiling it.

Three ways out, best first.

### 1. Keep the logic in the template

Usually the right answer. Latte does the loop, the chunk stays the lump of
markup a chunk is good at:

```latte
{foreach $rows as $row}
    <article>
        <h4>{$row['title']}</h4>
        {evoSnippet('renderCard', ['id' => $row['id']])}
    </article>
{/foreach}
```

### 2. Render the chunk through aLatteX explicitly

A snippet can start a second Latte pass on purpose. `demo/snippets/latte.php`
is the whole implementation:

```php
$source = (string) $modx->getChunk($chunk);
$engine = app(\Elcreator\aLatteX\LattexEngine::class);

return $engine->render($source, (array) $modx->documentObject);
```

Called from a template like this:

```latte
[[aLatteXDemoLatte? &chunk=`myCard` &vars=`{"title":"Rendered"}`]]
```

Its output is spliced back into the page by the CMS, so any EVO tags the chunk
produced are still resolved by a later pass.

Reusing the container's engine is safe here: the template pass has finished
before `parseDocumentSource()` starts expanding snippets, so nothing is
mid-render.

### 3. Move the markup into the template

If a chunk only exists to be Latte, it is a `{define}` block in the template.

The same rule runs the other way: **a snippet's return value is not Latte
either.** A snippet that returns `{$pagetitle}` prints those characters. It
*is* re-parsed for EVO tags, though, which is what makes nesting work.

---

## Data from a snippet

Everything above is about markup arriving too late for Latte. The way round it
is not to send markup at all.

`Core::evalSnippet()` ends with

```php
if (is_array($return) || is_object($return)) {
    return $return;
}

return $echo . $return;
```

so a snippet that returns an array is handed back as an array. Call it through
`$evo` and it runs *inside* the Latte pass, early enough for the template to
loop over what comes back:

```latte
{var $rows = $evo->runSnippet('aLatteXDemoRows', ['parent' => $parent, 'limit' => 4])}

{foreach $rows as $row}
    <li><a href="{$row['url']}">{$row['title']}</a></li>
{/foreach}
```

`demo/snippets/rows.php` is the snippet: a query against `site_content`, no
markup in it at all. This is the shape most real templates want, because the
rows a page lists live in the database rather than in a `{var}`.

**The two deferred spellings do not work here, and it is worth being clear
about why.** `[[aLatteXDemoRows]]` and `{evoSnippet('aLatteXDemoRows')}` both
hand a *tag* to the page for `parseDocumentSource()` to expand afterwards - by
which time Latte has finished and there is no loop left to feed. They are for
markup you want spliced into the output; `$evo->runSnippet()` is for values you
want to compute with.

Three things follow from running during the Latte pass:

- **Escaping is yours to get right, and Latte does it.** Data arrives raw, and
  `{$row['title']}` escapes it on the way out. A snippet that returns HTML it
  wants kept has to say so with `|noescape`, which is the correct place for
  that decision to be visible.
- **The snippet is not re-parsed for EVO tags.** A tag in a returned *string*
  is expanded by a later pass as usual; a tag inside a returned *array* is
  printed by Latte as text, because Latte prints it and the parser never sees a
  tag - it sees escaped output.
- **`runSnippet()`'s own cache parameters still apply.** `$evo->runSnippet($name,
  $params, $cacheTime)` caches through Evolution CMS's cache the same way it
  does anywhere else, which is the cheap way to keep a query off every request.

---

## Raw output

Two parsers want the braces, and each is told separately to keep off.

### Stopping Latte

```latte
{l} and {r}                    print a single { and }
{l}$pagetitle|upper{r}         show a Latte tag as text

{syntax off}
<script>
    const config = {"theme": {"lineHeight": 1.5}};
</script>
{/syntax}

<p n:syntax="off">{$notAVariable} stays as written</p>
```

`{syntax off}` is the tool for JavaScript, JSON and minified CSS. Latte is
normally relaxed about braces followed by a space or a quote, but an object
literal or a Tailwind config will abort compilation without it.

### Stopping Evolution CMS

You cannot. There is no verbatim construct in the CMS parser, and `{syntax off}`
does not help: aLatteX tokenised the EVO tags *before* Latte ever saw the
template, so a tag inside a `{syntax off}` block is restored afterwards and
expanded like any other. This really does print the time:

```latte
{syntax off}
<pre>[[aLatteXDemoClock]]</pre>
{/syntax}
```

To show a tag rather than run it, write its brackets as HTML entities - the one
form that survives both passes and reaches the browser as text:

```html
<pre>&#91;&#91;snippetName&#93;&#93;
&#123;&#123;chunkName&#125;&#125;
&#91;*pagetitle*&#93;</pre>
```

---

## Brackets that only look like tags

`EvoSyntaxBridge` decides what is an EVO tag before Latte runs, and a delimiter
alone is not enough to decide it. `[[1, 2], [3, 4]]` is a nested array literal,
`$row[($i + 1)]` is a subscript, `$a[!empty($b)]` is a negation — all three are
spelled like tags.

So the bridge checks the **name** as well as the brackets. Something is only
tokenised when what follows the opening delimiter could name an element:

- it starts with a letter, `_`, `#` (QuickEdit's `[*#field*]`), `@`, or an
  interpolated `[+placeholder+]` — never a digit, a quote, a bracket or a sigil;
- it continues with name characters, `.` `-` `/`, `@` for the `[*field@context*]`
  form, or further `[+placeholder+]` segments as in `[*tv_name_[+param+]*]`;
- it then ends, or hands over to `?`, `&` or a newline for parameters, or to `:`
  for output filters — the boundary set `Core::_getSplitPosition()` looks for.

So a name can never contain `]`, and parameters can never begin with one. The
two snippet forms additionally accept the superglobal tags `Core::_getSGVar()`
handles — `[[$_GET(id)]]`, `[[$_SERVER['HTTP_HOST']]]` — matched by their full
names, so that `[[$a], [$b]]` stays an array of variables.

`null`, `true` and `false` are excluded outright, since `[[null]]` is valid JSON
and valid PHP and is not a snippet anyone has written.

All of these therefore reach Latte intact:

```latte
{var $m = [[1, 2], [3, 4]]}
{var $m = [[foo], [bar]]}
{var $j = [[null], [true]]}
{var $v = [[$a], [$b]]}
{$row[($i + 1)]}
{$a[!empty($b)]}
{$a[+1]}
```

The check is part of the pattern rather than a veto applied after a match, so a
genuine tag sitting inside a rejected region is still found:

```latte
{var $m = [[1,2],[3,4]]} then [[Breadcrumbs]]   {* the snippet still resolves *}
```

**What is left.** Exactly one case is irreducible: `[[foo]]` is a snippet call
and a nested array holding one bare constant, spelled identically. It is read as
a snippet. Quote the identifier — `[['foo']]` — or bind the array to a variable.

**Regular expressions.** A character class can also spell a tag. `/[*a-z*]/`
and `/[[a-z]]/` are tokenised; `/[a-z*]/`, `/[*+]/` and `/[^a-z]/` are not.
Escape the bracket or star inside the class — the regex is unchanged and the
resemblance goes away:

```latte
{$s|replaceRE:'/[\[a-z\]]/', ''}
{$s|replaceRE:'/[\*a-z\*]/', ''}
```

### Why the fix lives here and not in the CMS

Because the core is never in a position to make the distinction. By the time
`parseDocumentSource()` runs, Latte has already consumed and rewritten every
expression it owns — what reaches the CMS is output, not template source.
Core's `getTagsFromContent()` is also shared by every parser and every site, so
tightening it would change behaviour well beyond this plugin without helping
it. The bridge is the only place that knows both that the string is a template
on its way to Latte and what an EVO element name may look like.

The traps are inert inside `{syntax off}` blocks too, because tokenisation
happens before Latte's lexer runs at all.

---

## Caching

Two independent caches, neither of which you have to manage by hand.

**Latte's compiled templates** live in `storage/framework/cache/latte/`. The
cache key is derived from the template's own content, so saving a template in
the manager invalidates it automatically. Nothing needs clearing.

**The CMS page cache** works exactly as before, and is what makes the
difference between `[[snippet]]` and `[!snippet!]` visible: the cacheable form
is frozen into the cached page, the non-cacheable one is re-evaluated on every
request. Note that this means the *Latte pass runs once per cache miss*, not
once per request.

`composer demo:install` and `composer demo:remove` both clear the page cache,
because every demo page is cacheable.

---

## Rendering from a `.latte` file

If a template's alias resolves to `views/<alias>.latte`, the CMS renders that
file through `LatteViewEngine` and **skips its own parser entirely** - the same
way it treats `.blade.php`. EVO tags left in such a file stay literal, because
nothing runs `parseDocumentSource()` on the result.

The demo deliberately leaves `templatealias` empty on every template, so all of
it goes through the database path documented above.

---

## Errors

A Latte compile or runtime error is caught by the plugin, written to the CMS
event log with its stack trace, and the document is left unrendered rather than
fataling. If a page suddenly shows raw template code, look in **Reports ->
System events** first.

---

## Escaping and safety

Latte's own protections are **unmodified and fully active**. The engine is
stock: no `Policy` changes, no injected `|noescape`, no altered escaper. In a
template rendered by aLatteX, escaping is context-aware exactly as it is in
Nette:

| Context | `"><script>alert(1)</script>` becomes |
| --- | --- |
| text | `&quot;&gt;&lt;script&gt;…` |
| attribute | escaped, cannot break the quote |
| unquoted attribute | Latte adds the quotes, then escapes |
| `<script>` | JSON-encoded, with `</script>` broken up |
| `href` | `javascript:` URLs are dropped by `checkUrl` |

EVO tags sitting in the same element or attribute do not disturb this: a token
is inert alphanumeric text, so Latte's HTML parser sees the same structure it
would otherwise.

### Where the guarantee stops

It is **not** equivalent to Latte in a Nette application, because Latte's
output is not the finished page here — Evolution CMS parses it afterwards.

**Restored tags are never escaped.** That is the entire point of the bridge, so
"everything Latte prints is escaped" is not true of the final page. What a
snippet or chunk emits is governed by EVO's rules, not Latte's, exactly as
under DocumentParser.

**Context tracking cannot see through a chunk.** If `{{chunk}}` emits a
`<script>`, Latte — which saw only a token — will have escaped a neighbouring
`{$var}` for text context. The failure direction is over-escaping, which is
inert, and no exploit has been demonstrated; but the guarantee is weaker than
Nette's.

**Tokens are unforgeable, and must stay that way.** `restore()` is a
`str_replace` over the rendered page, and a token contains no characters that
escaping would change. A predictable token would therefore let any value that
reaches the page name a tag from that template and have the CMS execute it —
this was demonstrated on a live site. Tokens are named after an HMAC of the
template under a key that belongs to this plugin alone (`TokenSecret`), kept in
`storage/alattex/token.key` rather than in `system_settings`, where any
template could print it as `[(setting)]`. The key is never emitted; only a
64-bit truncation of an HMAC under it appears in a compiled template.

**Snippet helpers sanitise what they interpolate.** `evoSnippet()` and
`evoUncachedSnippet()` return raw `Html`, so a parameter value that contained a
backtick could close the value and open a tag of its own. Values have the tag
delimiters (`` ` ``, `[[`, `]]`, `[!`, `!]`, `{{`, `}}`) removed, and names —
of the snippet and of each parameter — must be element names or the call is
refused.

**`evoChunk()` is deliberately trusted.** It returns chunk markup as `Html`,
unescaped, on the same footing as `|noescape`. Chunks are authored by managers;
do not build one from request data.

**There is no sandbox.** Latte's `Policy`/`{sandbox}` is not wired up, so a
template author has full expression power. This is not a regression against the
CMS — Evolution CMS's own parser `eval()`s snippet PHP — but it does mean
template editing is a privileged operation, as it already was.

### Rules of thumb

- Never pass request data to `evoSnippet()` as a *name*.
- Prefer `{$var}` over `[*var*]` when the value is untrusted: Latte escapes for
  the context, EVO's output filters do not.
- Treat chunk and template editing as privileged.
