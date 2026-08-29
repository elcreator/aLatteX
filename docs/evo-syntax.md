# Evolution CMS syntax in an aLatteX template

Nothing about the classic tags changes. aLatteX replaces each one with an
opaque token before Latte runs and restores it afterwards, so Evolution CMS
receives byte-for-byte what you wrote and parses it in `parseDocumentSource()`
exactly as it would under DocumentParser.

What aLatteX adds is a second way to write the same six things, as Latte
function calls - useful precisely when the tag cannot be written as a literal.

The working page for all of this is `demo/templates/evo-syntax.latte`, installed
as **EVO syntax**.

---

## The tags

| Tag | Meaning | Resolved by |
| --- | --- | --- |
| `{{name}}` | HTML chunk | `mergeChunkContent()` |
| `[[name]]` | cacheable snippet | `evalSnippets()` |
| `[!name!]` | non-cacheable snippet | `evalSnippets()` |
| `[*name*]` | template variable or document field | `mergeDocumentContent()` |
| `[(name)]` | system setting | `mergeSettingsContent()` |
| `[+name+]` | placeholder | `mergePlaceholderContent()` |
| `[~123~]` | URL of document 123 | `UrlProcessor::rewriteUrls()`, after parsing |
| `[^t^]` | benchmark values — also `[^q^] [^qt^] [^p^] [^s^] [^m^]` | `Core::outputContent()`, after parsing |

Parameters use the standard form, and a parameter value may itself be a tag:

```latte
[[DocLister? &parents=`[*id*]` &tpl=`@CODE:<li>[+pagetitle+]</li>`]]
```

`Core::getTagsForEscape()` names eight delimiter pairs; aLatteX tokenises six.
`[~…~]` and `[^…^]` are deliberately left alone, and do not need protecting:
neither contains a brace, so Latte has no opinion about them, and both are
substituted *after* `parseDocumentSource()` has finished rather than during it.
Tokenising them would only add ways to mistake a regular expression for a tag.

### Parser passes

`parseDocumentSource()` runs the six merges in a loop - twice at minimum, and
again (up to ten times) for as long as the output keeps changing. That is why
nesting works without anyone arranging it: a snippet that returns `[[other]]`
has that tag expanded by the next pass.

### Output filters and `@` syntax

`[*tv:modifier*]` output filters and the `<@IF:…>` conditional syntax are
governed by the `enable_filter` and `enable_at_syntax` system settings. Unlike
DLTemplate, selecting aLatteX does not force either of them, so whatever you
have configured keeps working.

---

## The Latte functions

Registered by `Elcreator\aLatteX\EvoExtension`, usable in any `{…}` expression.

### `evoChunk(string $name): Html`

Fetches the chunk and returns its markup **now**, during Latte's pass, as raw
HTML.

```latte
{evoChunk('aLatteXDemoHeader')}
```

It does not compile the chunk. A chunk containing `{$title}` yields those six
characters - see [interop.md](interop.md#latte-inside-a-chunk).

### `evoSnippet(string $name, array $params = []): Html`

Does **not** run the snippet. It writes the tag:

```latte
{evoSnippet('DocLister', ['parents' => $id, 'tpl' => '@CODE:<li>[+pagetitle+]</li>'])}
```

becomes, in the output Latte hands back:

```
[[DocLister?&parents=`7`&tpl=`@CODE:<li>[+pagetitle+]</li>`]]
```

which Evolution CMS then runs on its own pass. Keeping the tag rather than the
result is deliberate: it preserves snippet caching, and it means the snippet
sees the CMS's normal environment.

The reason to prefer it over a literal `[[…]]` is that the arguments are Latte
expressions, so a call can be built from a variable, a loop or a condition:

```latte
{foreach $tags as $tag}
    {evoSnippet('aLatteXDemoList', ['items' => $tag, 'class' => 'tag-' . $tag])}
{/foreach}

{if $published}
    {evoSnippet('aLatteXDemoClock', ['format' => 'Y-m-d'])}
{else}
    {evoUncachedSnippet('aLatteXDemoClock')}
{/if}
```

Both functions return `Latte\Runtime\Html`, so the backticks and ampersands of
the tag are not escaped on the way out.

### `evoUncachedSnippet(string $name, array $params = []): Html`

The same, for `[!…!]`.

### `evoTv(string $name): string`

Reads `evo()->documentObject[$name]`, flattening a TV's
`[name, value, display, display_params, type]` array down to its value the way
the EVO parser does. Equivalent to the bare variable `{$name}`, and useful when
the name is itself dynamic:

```latte
{evoTv('alxSubtitle')}
{evoTv($fieldName)}
```

### `evoSetting(string $name): string`

`evo()->getConfig($name)`. Escaped like any other string, so a site name
containing `&` comes out as `&amp;` - which is what you want in markup.

### `evoPlaceholder(string $name): string`

Reads `evo()->placeholders[$name]`. **Most placeholders do not exist yet when
Latte runs** - snippets set them later - so this is only useful for
placeholders a plugin published on an earlier event. For the ordinary case,
write `[+name+]` and let the CMS fill it in.

---

## Which form to use

| Situation | Write |
| --- | --- |
| A fixed chunk, snippet, TV or setting | the classic tag - shorter, and obvious to the next person |
| The name or the parameters come from a Latte expression | `evoSnippet()`, `evoChunk()`, `evoTv()` |
| You need the value *as a value* - to count, split, compare or filter it | the bare variable, `{$alxTags}` |
| You need the snippet's output inside a Latte expression | you cannot have it; see [interop.md](interop.md) |
