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
 */
class EvoSyntaxBridge
{
    /** @var array<string, string> Token map: token => original EVO tag */
    private array $tokens = [];

    /**
     * Patterns ordered from most-specific to least-specific to avoid partial matches.
     * Non-greedy (lazy) quantifiers prevent consuming too much content.
     */
    private const PATTERNS = [
        '/\{\{.*?\}\}/s',    // {{chunk}} or {{chunk?&param=`value`}}
        '/\[\[.*?\]\]/s',    // [[snippet]] or [[snippet?&param=`value`]]
        '/\[!.*?!\]/s',      // [!nonCacheable!]
        '/\[\*.*?\*\]/s',    // [*templateVar*]
        '/\[\(.*?\)\]/s',    // [(setting)]
        '/\[\+.*?\+\]/s',    // [+placeholder+]
    ];

    /**
     * Replace all EVO syntax tags with safe placeholder tokens.
     * Call before passing the template to Latte.
     */
    public function protect(string $template): string
    {
        $this->tokens = [];

        foreach (self::PATTERNS as $pattern) {
            $template = preg_replace_callback($pattern, function (array $matches): string {
                $idx   = count($this->tokens);
                $token = "__ALATTEX_{$idx}__";
                $this->tokens[$token] = $matches[0];
                return $token;
            }, $template);
        }

        return $template;
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
}
