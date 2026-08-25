<?php

declare(strict_types=1);

namespace Elcreator\aLatteX\Sync;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Converts a Google Stitch HTML export into an aLatteX-ready Latte template.
 *
 * Two transforms, both fully deterministic (no model in the loop, so re-running
 * on a fresh Stitch export is idempotent):
 *
 *   1. Repeat regions  <!-- repeat:NAME start --> … <!-- repeat:NAME end -->
 *      become            {foreach $NAME as $item} … {/foreach}
 *      and every element carrying data-field="X" inside the region is rewired
 *      to a Latte expression according to $fieldMap.
 *
 *   2. Brace-heavy blocks (<script>, <style>) are wrapped in {syntax off} …
 *      {/syntax} so Latte's lexer never tries to parse CSS/JS braces or the
 *      Tailwind config object. This runs BEFORE aLatteX hands the template to
 *      Latte, which is the pass that would otherwise choke on `{"lineHeight":…}`.
 */
final class StitchLatteConverter
{
    /**
     * @var array<string, array{mode: 'text'|'src'|'href', prop: string, filter?: string}>
     *   data-field value => how to bind it.
     *     mode 'text' replaces the element's inner content,
     *     mode 'src'  sets the src attribute (images),
     *     mode 'href' sets the href attribute (links).
     *   'prop' is the property accessed on the loop item.
     *   'filter' is an optional Latte filter chain, e.g. "|number:2".
     */
    private array $fieldMap;

    /** @var list<string> Non-fatal issues found during conversion. */
    private array $warnings = [];

    /**
     * @param array<string, array{mode: 'text'|'src'|'href', prop: string, filter?: string}>|null $fieldMap
     */
    public function __construct(?array $fieldMap = null)
    {
        $this->fieldMap = $fieldMap ?? self::defaultFieldMap();
    }

    /**
     * Default mapping for the e-commerce product card. Override via the
     * constructor for other screens.
     *
     * @return array<string, array{mode: 'text'|'src'|'href', prop: string, filter?: string}>
     */
    public static function defaultFieldMap(): array
    {
        return [
            'image'       => ['mode' => 'src',  'prop' => 'image_url'],
            'name'        => ['mode' => 'text', 'prop' => 'name'],
            'price'       => ['mode' => 'text', 'prop' => 'price', 'filter' => "|number:2,',',' '"],
            'description' => ['mode' => 'text', 'prop' => 'description'],
            'url'         => ['mode' => 'href', 'prop' => 'url'],
        ];
    }

    public function convert(string $html): string
    {
        $this->warnings = [];
        $html = $this->convertRepeatRegions($html);
        $html = $this->wrapBraceHeavyBlocks($html);
        return $html;
    }

    /** @return list<string> */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    private function convertRepeatRegions(string $html): string
    {
        $pattern = '/<!--\s*repeat:([A-Za-z_][A-Za-z0-9_]*)\s+start\s*-->(.*?)<!--\s*repeat:\1\s+end\s*-->/s';

        return preg_replace_callback($pattern, function (array $m): string {
            $collection = $m[1];               // e.g. "products"
            $itemVar    = $this->singularize($collection); // e.g. "product"
            $card       = $this->rewriteFields($m[2], $itemVar);

            return "{foreach \$$collection as \$$itemVar}"
                . $card
                . "{/foreach}";
        }, $html) ?? $html;
    }

    private function rewriteFields(string $fragment, string $itemVar): string
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);

        // Force UTF-8 and avoid the implied <html>/<body> wrappers and a DTD.
        $doc->loadHTML(
            '<?xml encoding="UTF-8"><div data-frag-root="1">' . $fragment . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();

        $xpath = new DOMXPath($doc);

        // DOMDocument's serializer entity-escapes "->" and URL-encodes the
        // contents of src/href. So we never write the Latte expression into the
        // DOM directly — we write an inert ASCII sentinel (which serializes
        // untouched), then swap sentinels for real expressions afterwards.
        // Same protect/restore idea as EvoSyntaxBridge.
        $tokens = [];   // token => Latte expression
        $i      = 0;

        /** @var DOMElement $el */
        foreach ($xpath->query('//*[@data-field]') as $el) {
            $field = $el->getAttribute('data-field');
            $spec  = $this->fieldMap[$field] ?? null;

            if ($spec === null) {
                $this->warnings[] = "Unmapped data-field=\"$field\" left untouched.";
                $el->removeAttribute('data-field');
                continue;
            }

            $expr  = '{$' . $itemVar . '->' . $spec['prop'] . ($spec['filter'] ?? '') . '}';
            $token = '__ALX_TOKEN_' . $i++ . '__';
            $tokens[$token] = $expr;

            switch ($spec['mode']) {
                case 'src':
                    $el->setAttribute('src', $token);
                    break;

                case 'href':
                    if (strtolower($el->tagName) === 'a') {
                        $el->setAttribute('href', $token);
                    } else {
                        // e.g. data-field="url" on a <button> — no href slot.
                        $el->setAttribute('data-href', $token);
                        $this->warnings[] = sprintf(
                            'data-field="%s" sits on <%s>, not <a>; emitted data-href instead. '
                            . 'Consider moving the url field onto an anchor in Stitch.',
                            $field,
                            strtolower($el->tagName)
                        );
                    }
                    break;

                case 'text':
                default:
                    // Replace inner content wholesale with the sentinel.
                    while ($el->firstChild) {
                        $el->removeChild($el->firstChild);
                    }
                    $el->appendChild($doc->createTextNode($token));
                    break;
            }

            $el->removeAttribute('data-field');
            $el->removeAttribute('data-alt'); // Stitch image-prompt leftovers
        }

        $serialized = $this->innerHtml($xpath);

        // Restore: sentinels are pure [A-Z0-9_], so strtr is exact and safe.
        return strtr($serialized, $tokens);
    }

    private function innerHtml(DOMXPath $xpath): string
    {
        $rootList = $xpath->query('//div[@data-frag-root="1"]');
        $root     = $rootList instanceof \DOMNodeList ? $rootList->item(0) : null;

        if (!$root instanceof DOMElement) {
            return '';
        }

        $html = '';
        foreach ($root->childNodes as $child) {
            $html .= $root->ownerDocument->saveHTML($child);
        }

        // DOMDocument escapes nothing problematic in our text/attr Latte exprs,
        // but it does entity-encode a bare "&" it inserts — none here. Decode the
        // few entities DOM may introduce around plain braces just in case.
        return $html;
    }

    private function wrapBraceHeavyBlocks(string $html): string
    {
        $pattern = '#<(script|style)\b[^>]*>.*?</\1\s*>#is';

        return preg_replace_callback($pattern, static function (array $m): string {
            return '{syntax off}' . $m[0] . '{/syntax}';
        }, $html) ?? $html;
    }

    private function singularize(string $collection): string
    {
        // Deliberately naive — good enough for products/categories/items.
        // Override by passing an explicit item var if you have irregular names.
        if (str_ends_with($collection, 'ies')) {
            return substr($collection, 0, -3) . 'y';
        }
        if (str_ends_with($collection, 's') && !str_ends_with($collection, 'ss')) {
            return substr($collection, 0, -1);
        }
        return $collection;
    }
}
