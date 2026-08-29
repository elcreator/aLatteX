# Latte syntax in an Evolution CMS template

aLatteX runs an unmodified [Latte 3](https://latte.nette.org) engine, so the
upstream documentation applies in full. This page is the subset that matters in
practice, written against a CMS template rather than a filesystem one, plus the
short list of things that genuinely do not work here.

Everything below is rendered by `demo/templates/basics.latte`, which the demo
installs as the page **Latte basics**.

---

## What is in scope

aLatteX hands Latte the current document three ways over:

```latte
{$pagetitle}                {* every field and attached TV, as a bare variable *}
{$documentObject['alias']}  {* the same thing, as an array *}
{$evo->getConfig('site_name')}  {* the Evolution CMS core object *}
```

A template variable attached to the template is just another key, so
`{$alxSubtitle}` works with no ceremony. Keys that are absent - a TV that is
not attached to this template - are simply missing, so read them defensively:

```latte
{$alxNotAttached ?? 'not attached'}
```

> **TVs are flattened for you.** The core stores each one in `documentObject`
> as `[name, value, display, display_params, type]`, which is what the EVO
> parser reads when it expands `[*name*]`. aLatteX reduces each to its value
> before Latte sees it, so `{$alxSubtitle}`, `{evoTv('alxSubtitle')}` and
> `[*alxSubtitle*]` all mean the same thing and a filter can be applied to any
> of them. A template that needs the display type or parameters can still
> reach the raw array through `$evo->documentObject`.

---

## Printing

```latte
{$pagetitle}                       escaped for the current context
{$content|noescape}                raw, opt in explicitly
{= strrev('etpircs')}              print an expression
{$price|number:2, ',', ' '}        filters, chained left to right
{$missing ?? 'fallback'}           null coalescing
{$published ? 'live' : 'draft'}    ternary
```

Escaping is **context aware**. The same variable is escaped differently in
markup, in an attribute, in a URL and inside `<script>`:

```latte
<p title="{$pagetitle}">{$pagetitle}</p>
<a href="/go?to={$alias|escapeUrl}">…</a>
<script>window.page = {$pagetitle};</script>   {* json-encoded for you *}
```

> **A brace followed by a quote or a space is not a tag.** Latte ignores
> `{'7'|padLeft:3}` on purpose, so that CSS and JS survive. Write `{= '7'|padLeft:3}`
> when the expression starts with a literal.

---

## Variables

```latte
{var $tags = ['latte', 'evo', 'demo']}
{default $heading = 'Latte basics'}      {* only if not already set *}
{varType string $pagetitle}              {* a hint for static analysis, no output *}
{do $tags[] = 'appended'}                {* run an expression, print nothing *}
```

`{var}` takes plain expressions. To use a filter inside one, parenthesise it:

```latte
{var $rows = ($tags|batch:2)}
```

---

## Branching

```latte
{if $published && $tags}…{elseif $published}…{else}…{/if}
{ifset $alxSubtitle}…{/ifset}

{switch count($tags)}
    {case 0}…
    {case 1}…
    {default}…
{/switch}

{try}
    {intdiv(1, 0)}
{else}
    the exception was swallowed and this printed instead
{/try}
```

---

## Loops

```latte
{foreach $tags as $i => $tag}
    {continueIf $tag === 'skip'}
    {breakIf $iterator->counter > 10}
    {$iterator->counter}. {$tag}
    {first}(first){/first}
    {last}(last){/last}
    {sep}, {/sep}
{else}
    nothing to show
{/foreach}

{for $i = 1; $i <= 3; $i++}{$i}{/for}
{while $n > 0}{$n--}{/while}
```

`{sep}` belongs inside `{foreach}`; in a `{for}` or `{while}` it is deprecated,
so write the separator with an `{if}`.

`{iterateWhile}` groups consecutive items - the condition goes on the *closing*
tag:

```latte
{foreach $rows as $row}
    <li>{iterateWhile}{$row}{/iterateWhile $iterator->nextValue === $row}</li>
{/foreach}
```

---

## n:attributes

Any tag that wraps content has an `n:` form that lives on the element instead:

```latte
<p n:if="$tags">only when there are tags</p>
<p n:ifset="$maybe">only when it is set</p>
<li n:foreach="$tags as $tag">{$tag}</li>
<ul n:inner-foreach="$tags as $tag"><li>{$tag}</li></ul>
<p n:class="alx-flag, $published ? alx-flag--live">merged classes</p>
<p n:attr="data-count: count($tags), title: $pagetitle">computed attributes</p>
<p n:ifcontent>{$maybe}</p>                 {* drop the element if it is empty *}
<span n:tag="$published ? 'strong' : 'em'">the element name is an expression</span>
<div n:block="aside">…</div>
<p n:syntax="off">{$notAVariable} stays literal</p>
```

---

## Blocks

Blocks are local to the template. There is no cross-template inheritance:
`{extends}`, `{layout}` and `{include 'other.latte'}` have nothing to resolve
against, because aLatteX renders through Latte's `StringLoader` - it hands the
engine the template's source, not a path. That is true of a file template as
well as a database one: `views/<alias>.latte` is read and rendered as a string,
so putting a template in a file buys version control, not `{extends}`.

`{define}` and `{include name}` within one template cover what a CMS template
usually wants from inheritance, and a shared fragment is what chunks are for.

```latte
{define layout, string $title, array $pages}
    …
    {include content, pages: $pages}
{/define}

{define note, string $text, string $level = 'info'}
    <p class="note note--{$level}">{$text}</p>
{/define}

{block #intro}printed where it stands{/block}

{include note, text: 'with arguments'}
{include layout, title: $pagetitle, pages: $pages}
```

**A `{define}` does not see the enclosing template's local variables.** Pass
what it needs as arguments, as above; document fields are global and always
visible.

`{capture}` buffers instead of printing, and `{spaceless}` collapses whitespace
between tags:

```latte
{capture $html}<strong>buffered</strong>{/capture}
<p>{strlen(trim((string) $html))} bytes</p>

{spaceless}<ul><li>a</li><li>b</li></ul>{/spaceless}
```

An anonymous block can be filtered as a whole:

```latte
{block|stripHtml|trim|upper}   filtered per block   {/block}
```

---

## Filters

The core set is available unchanged. The ones that come up most in a CMS
template:

| Group | Filters |
| --- | --- |
| Text | `upper` `lower` `firstUpper` `capitalize` `truncate` `trim` `padLeft` `padRight` `repeat` `replace` `replaceRE` `indent` `substr` |
| Markup | `noescape` `escapeUrl` `stripHtml` `striptags` `nl2br` `breakLines` `spaceless` |
| Numbers and dates | `number` `round` `floor` `ceil` `clamp` `bytes` `date` |
| Collections | `implode` `explode` `split` `first` `last` `length` `slice` `sort` `reverse` `batch` `group` `random` |
| Other | `query` `checkUrl` `dataStream` |

Functions: `clamp()` `divisibleBy()` `even()` `odd()` `first()` `last()`
`slice()` `hasBlock()` `group()`, plus any PHP function Latte allows in an
expression (`count()`, `implode()`, `in_array()`, …).

---

## Not available here

| Feature | Why |
| --- | --- |
| `{layout}`, `{extends}`, `{import}`, `{embed}`, `{sandbox}`, `{include 'file.latte'}` | aLatteX renders a database string through a `StringLoader`, so there is no file to resolve a name against. Blocks defined in the same template work; inheritance across templates does not. |
| `{syntax double}` | Its delimiter is `{{…}}`, which is how Evolution CMS spells a chunk. aLatteX tokenises those before Latte sees them. |
| `{cache}` | Needs `nette/caching`. Use the CMS's own page cache instead - see [interop.md](interop.md#caching). |
| `{dump}`, Tracy integration | Needs `tracy/tracy`. |
| `|webalize`, `|localDate`, `{translate}` | Need `nette/utils`, `ext-intl` and a translator respectively; none is a dependency of this plugin. |
| `{php}` | Removed in Latte 3. Use `{do}`. |
| `{snippet}`, `{control}`, `{link}`, `{plink}` | Nette Application tags. Unrelated to Evolution CMS snippets - use `[[…]]` or `evoSnippet()`. |

`{contentType}` works, but sends a header, so leave it alone unless you mean
to change the response type.

For the parts of a template that must not be parsed at all, see
[interop.md](interop.md#raw-output).
