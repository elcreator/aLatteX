<?php

declare(strict_types=1);

namespace Elcreator\aLatteX\Demo;

use RuntimeException;

/**
 * The demo set, loaded from demo/manifest.php with every body read in.
 *
 * Deliberately free of the CMS: no models, no container, no database, not even
 * a call to evo(). That is what lets the same fixtures serve two audiences -
 * DemoSeeder installs them into a site for a human to click through, and the
 * test suite renders them with nothing but Latte and a stub core.
 *
 * @phpstan-type Element array{name: string, description: string, body: string}
 */
final class DemoContent
{
    /** @var array<string, mixed>|null */
    private static ?array $cache = null;

    /**
     * The whole set, keyed as in the manifest, with 'body' filled in wherever
     * the manifest named a 'file'.
     *
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $dir = self::directory();
        $manifest = require $dir . '/manifest.php';

        if (!is_array($manifest)) {
            throw new RuntimeException('demo/manifest.php must return an array.');
        }

        foreach (['chunks', 'snippets', 'templates', 'documents'] as $section) {
            foreach ($manifest[$section] as $i => $entry) {
                $manifest[$section][$i]['body'] = self::read($dir, $entry['file']);
            }
        }

        return self::$cache = $manifest;
    }

    /** @return list<array<string, mixed>> */
    public static function chunks(): array
    {
        return self::all()['chunks'];
    }

    /** @return list<array<string, mixed>> */
    public static function snippets(): array
    {
        return self::all()['snippets'];
    }

    /** @return list<array<string, mixed>> */
    public static function tvs(): array
    {
        return self::all()['tvs'];
    }

    /** @return list<array<string, mixed>> */
    public static function templates(): array
    {
        return self::all()['templates'];
    }

    /** @return list<array<string, mixed>> */
    public static function documents(): array
    {
        return self::all()['documents'];
    }

    public static function category(): string
    {
        return (string) self::all()['category'];
    }

    /**
     * Chunk bodies as name => body.
     *
     * The shape evo()->getChunk() answers in, so a test can hand this straight
     * to a stub core and have {{aLatteXDemoHeader}} resolve.
     *
     * @return array<string, string>
     */
    public static function chunkMap(): array
    {
        $map = [];
        foreach (self::chunks() as $chunk) {
            $map[$chunk['name']] = $chunk['body'];
        }

        return $map;
    }

    /**
     * Template bodies as name => Latte source.
     *
     * @return array<string, string>
     */
    public static function templateMap(): array
    {
        $map = [];
        foreach (self::templates() as $template) {
            $map[$template['name']] = $template['body'];
        }

        return $map;
    }

    /**
     * A document as Latte would see it: every field plus its TV values, which
     * is what Evolution CMS assembles into evo()->documentObject.
     *
     * Documents in the manifest carry only the fields that differ per page, so
     * the rest are filled in here with the values the seeder writes - a test
     * rendering this array is rendering what the installed site would.
     *
     * @return array<string, mixed>
     */
    public static function documentObject(string $alias): array
    {
        foreach (self::documents() as $i => $document) {
            if ($document['alias'] !== $alias) {
                continue;
            }

            // TVs are not scalars in documentObject. Core::getDocumentObject()
            // merges each one in as [name, value, display, display_params,
            // type], and a fixture that used a plain string here would be
            // testing a shape the CMS never produces - which is exactly how
            // {$alxSubtitle|upper} once reached a live page as "Array".
            $tvs = [];
            foreach (self::tvs() as $tv) {
                $tvs[$tv['name']] = [
                    $tv['name'],
                    $document['tvs'][$tv['name']] ?? $tv['default_text'],
                    $tv['display'],
                    $tv['display_params'],
                    $tv['type'],
                ];
            }

            return array_merge([
                'id' => $i + 1,
                'type' => 'document',
                'contentType' => 'text/html',
                'pagetitle' => $document['pagetitle'],
                'longtitle' => $document['longtitle'],
                'menutitle' => $document['menutitle'],
                'description' => '',
                'alias' => $document['alias'],
                'introtext' => '',
                'content' => $document['body'],
                'published' => 1,
                'isfolder' => $document['isfolder'] ? 1 : 0,
                'template' => 0,
                'parent' => 0,
                'searchable' => 1,
                'cacheable' => 1,
                'hidemenu' => 0,
            ], $tvs);
        }

        throw new RuntimeException('No demo document with alias "' . $alias . '".');
    }

    /** Absolute path of the demo/ directory. */
    public static function directory(): string
    {
        return dirname(__DIR__, 2) . '/demo';
    }

    private static function read(string $dir, string $relative): string
    {
        $path = $dir . '/' . $relative;
        $body = @file_get_contents($path);

        if ($body === false) {
            throw new RuntimeException('Missing demo file: ' . $path);
        }

        // Editors add a trailing newline; the CMS stores what it is given.
        return rtrim(str_replace("\r\n", "\n", $body), "\n");
    }
}
