<?php

declare(strict_types=1);

namespace Elcreator\aLatteX;

/**
 * Normalises evo()->documentObject for use as template variables.
 *
 * Document *fields* land in that array as scalars, but a template *variable*
 * lands as a five-element array - see Core::getDocumentObject(), which builds
 *
 *     $tmplvars[$name] = [$name, $value, $display, $display_params, $type];
 *
 * and merges it in. The EVO parser knows this and reads `$value[1]` when it
 * expands [*name*]; a template language handed the raw array would print
 * "Array" instead, or fail outright the moment a filter touched it.
 *
 * So the array is flattened before Latte sees it: every TV becomes its value,
 * every field is left alone, and {$alxSubtitle} means the same thing as
 * [*alxSubtitle*]. The display type and parameters are dropped, which is the
 * same trade the CMS makes - a template that needs them can reach the raw
 * array through $evo->documentObject.
 */
final class DocumentObject
{
    /**
     * @param  array<string, mixed> $documentObject
     * @return array<string, mixed>
     */
    public static function flatten(array $documentObject): array
    {
        $flat = [];

        foreach ($documentObject as $key => $value) {
            $flat[$key] = self::value($key, $value);
        }

        return $flat;
    }

    /**
     * The value of one entry, whichever shape it arrived in.
     *
     * The guard is `$value[0] === $key` rather than a plain is_array(), so
     * that anything else the core parks in documentObject under an array -
     * __MODxSJScripts__ and friends - is passed through untouched.
     */
    public static function value(string|int $key, mixed $value): mixed
    {
        if (is_array($value) && ($value[0] ?? null) === $key) {
            return $value[1] ?? '';
        }

        return $value;
    }
}
