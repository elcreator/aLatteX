<?php

namespace Elcreator\aLatteX;

/**
 * Protects Evolution CMS template syntax from being interpreted by Latte,
 * then restores it after Latte rendering so EVO's own parser can handle it.
 *
 * Supported EVO syntax:
 *   {{htmlChunk}}              - HTML chunks
 *   [[cacheableSnippet]]       - Cacheable PHP snippets
 *   [!nonCacheableSnippet!]    - Non-cacheable PHP snippets
 *   [*templateVariable*]       - Template variables / document fields
 *   [(configSetting)]          - System settings
 *   [+placeholder+]            - Placeholders
 *
 * ---------------------------------------------------------------------------
 * Why the patterns check the name
 *
 * A delimiter alone is not enough to tell an EVO tag from a PHP expression
 * that happens to be spelled the same way. `[[1, 2], [3, 4]]` is a nested
 * array literal; `$row[($i + 1)]` is a subscript; `$a[!empty($b)]` is a
 * negation. Matching on the brackets alone swallowed all three, and Latte
 * then never saw an expression that was, as far as its author was concerned,
 * ordinary PHP.
 *
 * The fix belongs here rather than in the CMS. By the time Evolution CMS
 * parses the page, Latte has already run and rewritten every expression it
 * owns - the core is never in a position to tell the two apart, and its
 * scanner is shared by every parser and every site. This class is the only
 * place that knows both that the string is a template on its way to Latte and
 * what an EVO element name may look like.
 *
 * So a tag is only tokenised when what follows the opening delimiter could
 * actually name an element:
 *
 *   - it starts with a letter, `_`, `#` (QuickEdit's `[*#field*]`), `@`, or an
 *     interpolated `[+placeholder+]`; never a digit, quote, bracket or sigil;
 *   - it continues with name characters, `.`/`-`/`/` and `@` for the
 *     `[*field@context*]` form, or further `[+placeholder+]` segments, as in
 *     `[*tv_name_[+param+]*]`;
 *   - it then ends, or hands over to `?`, `&` or a newline for parameters, or
 *     to `:` for output filters - which is the boundary set
 *     Core::_getSplitPosition() looks for.
 *
 * A name therefore can never contain `]`, and parameters can never begin with
 * one, which is what makes `[[foo], [bar]]` a nested array rather than a
 * snippet call. The snippet forms additionally accept the superglobal tags
 * Core::_getSGVar() handles - `[[$_GET(id)]]`, `[[$_SERVER['HTTP_HOST']]]` -
 * spelled out in full, so that `[[$a], [$b]]` stays an array of variables.
 *
 * `null`, `true` and `false` are excluded outright: `[[null]]` is valid JSON
 * and valid PHP, and is not a snippet anyone has ever written.
 *
 * Anything that fails the test is left alone, and - because the test is part
 * of the pattern rather than a veto after the fact - the scanner simply
 * carries on, so a genuine tag sitting inside a rejected region is still
 * found.
 *
 * One case is irreducible: `[[foo]]` is a snippet call and a nested array of
 * one bare constant, spelled identically. It is read as a snippet. Quote the
 * identifier, or bind the array to a variable.
 */
class EvoSyntaxBridge
{
    /** @var array<string, string> Token map: token => original EVO tag */
    private array $tokens = [];

    /**
     * Start one top-level Latte render.
     *
     * A render may load several sources (a root template, layouts and
     * partials), so protect() must accumulate their tokens. The map is reset
     * here instead of in protect() to keep every source restorable until the
     * complete rendered page is available.
     */
    public function beginRender(): void
    {
        $this->tokens = [];
    }

    /** First character of an element name. */
    private const NAME_START = '(?:[A-Za-z_#@]|\[\+[^\]\[]*\+\])';

    /** The rest of it, including `[*tv_name_[+param+]*]` interpolation. */
    private const NAME_REST = '(?:[A-Za-z0-9_.\-#@\/]|\[\+[^\]\[]*\+\])*';

    /** Literals that are never an element name, whatever the brackets say. */
    private const RESERVED = '(?i:null|true|false)';

    /**
     * The superglobal tags Core::_getSGVar() reads - `[[$_GET(id)]]` and
     * `[[$_SERVER['HTTP_HOST']]]`. Named in full rather than allowing a bare
     * `$`, so that a Latte array of variables is still an array.
     */
    private const SUPERGLOBAL =
        '\$_(?:GET|POST|SESSION|COOKIE|REQUEST|SERVER|FILES|ENV)(?:\[[^\[\]]*\]|\([^()]*\))*';

    /**
     * Opening and closing delimiters, as regex fragments, ordered from
     * most-specific to least-specific to avoid partial matches. The flag says
     * whether the form accepts a superglobal name, which only the two snippet
     * tags do.
     *
     * @var list<array{string, string, bool}>
     */
    private const DELIMITERS = [
        ['\{\{', '\}\}', false],  // {{chunk}} or {{chunk?&param=`value`}}
        ['\[\[', '\]\]', true],   // [[snippet]] or [[snippet?&param=`value`]]
        ['\[!', '!\]', true],     // [!nonCacheable!]
        ['\[\*', '\*\]', false],  // [*templateVar*]
        ['\[\(', '\)\]', false],  // [(setting)]
        ['\[\+', '\+\]', false],  // [+placeholder+]
    ];

    /**
     * Replace all EVO syntax tags with safe placeholder tokens.
     * Call before passing the template to Latte.
     */
    public function protect(string $template): string
    {
        // A token has to be unforgeable. restore() is a str_replace over the
        // *rendered* page, and a token is plain alphanumeric text, so it
        // survives escaping unchanged - a document field holding the literal
        // token would otherwise come back out of restore() as live EVO syntax
        // and be executed by the CMS. Naming the token after an HMAC of the
        // template closes that: an attacker cannot produce the string without
        // the key.
        //
        // Derived from the template rather than from randomness so that the
        // protected string, which is also Latte's cache id, is stable - a
        // random prefix would recompile every template on every request.
        $prefix = '__ALATTEX_'
            . substr(hash_hmac('sha256', $template, TokenSecret::get()), 0, 16)
            . '_';

        // The counter belongs to this source, not to the accumulated map. Its
        // protected form must not depend on which layout or partial happened
        // to be loaded before it, because that form participates in Latte's
        // compiled-template cache identity.
        $idx = 0;

        foreach (self::patterns() as $pattern) {
            $template = preg_replace_callback($pattern, function (array $matches) use ($prefix, &$idx): string {
                $token = "{$prefix}{$idx}__";
                $this->tokens[$token] = $matches[0];
                $idx++;
                return $token;
            }, $template);
        }

        return $template;
    }

    /**
     * Whether a string could name an Evolution CMS element.
     *
     * The same grammar the patterns below are built from, exposed so that
     * anything assembling an EVO tag from untrusted input - EvoExtension's
     * snippet helpers - can refuse a name that would break out of it.
     */
    public static function isElementName(string $name): bool
    {
        return (bool) preg_match(
            '/^' . self::NAME_START . self::NAME_REST . '$/D',
            $name,
        );
    }

    /**
     * Restore the original EVO syntax tags from their placeholder tokens.
     * Call after Latte has rendered the template.
     */
    public function restore(string $output): string
    {
        if (empty($this->tokens)) {
            return $output;
        }

        return str_replace(
            array_keys($this->tokens),
            array_values($this->tokens),
            $output
        );
    }

    /**
     * One pattern per delimiter pair.
     *
     * Body: an optional-whitespace-wrapped element name, then either the
     * closing delimiter or one of `?`, `&`, `:` handing over to parameters and
     * output filters, whose contents are matched lazily up to the first close.
     * `\s*` before that covers the `name\n&param` and `name &param` forms
     * Core::_getSplitPosition() accepts.
     *
     * @return list<string>
     */
    private static function patterns(): array
    {
        $patterns = [];

        foreach (self::DELIMITERS as [$open, $close, $superglobals]) {
            $name = self::NAME_START . self::NAME_REST;

            if ($superglobals) {
                $name = '(?:' . self::SUPERGLOBAL . '|' . $name . ')';
            }

            $patterns[] = '/'
                . $open
                . '(?!\s*' . self::RESERVED . '\s*' . $close . ')'
                . '\s*' . $name . '\s*'
                . '(?:[?:&].*?)?'
                . $close
                . '/s';
        }

        return $patterns;
    }
}
