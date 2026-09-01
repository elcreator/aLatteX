<?php

declare(strict_types=1);

use Elcreator\aLatteX\Demo\DemoContent;
use Elcreator\aLatteX\LattexEngine;

/**
 * The demo set doubles as this package's fixture set.
 *
 * demo/ exists so a human can install it into a site and click through it, but
 * it is also the broadest sample of aLatteX-shaped template code in the
 * repository - every Latte tag the plugin has to survive, every EVO tag it has
 * to protect, and the awkward combinations of the two. Rendering it here means
 * a regression in the bridge or the extension breaks the suite rather than
 * being discovered on somebody's site.
 *
 * Nothing below needs a CMS: DemoContent is deliberately free of models and of
 * the container, and the stub core from tests/bootstrap.php answers the four
 * things these templates ask of it.
 */

/** Render one demo document exactly as an installed site would. */
function renderDemoDocument(string $alias): string
{
    $documents = [];
    $aliasToId = [];
    foreach (DemoContent::documents() as $i => $document) {
        $documents[$document['alias']] = $document;
        $aliasToId[$document['alias']] = $i + 1;
    }

    $documentObject = DemoContent::documentObject($alias);

    useFakeEvo(
        documentObject: $documentObject,
        config: [
            'site_name' => 'Demo & Co',
            'site_url' => 'https://example.test/',
            'manager_language' => 'en',
            'chunk_processor' => 'aLatteX',
        ],
        chunks: DemoContent::chunkMap(),
        documents: $aliasToId,
        // The one snippet a demo template calls for *data* rather than markup.
        // On a site it is a query; here it is the shape that query returns, so
        // the template's loop over it is what gets exercised.
        snippets: [
            'aLatteXDemoRows' => [
                ['id' => 3, 'title' => 'Latte basics', 'body' => 'Every Latte construct aLatteX passes through.', 'url' => '/index.php?id=3'],
                ['id' => 4, 'title' => 'EVO syntax', 'body' => 'The six tag forms, untouched.', 'url' => '/index.php?id=4'],
            ],
        ],
    );

    return (new LattexEngine())->render(
        DemoContent::templateMap()[$documents[$alias]['template']],
        $documentObject,
    );
}

function requireLatte(): void
{
    if (!class_exists(\Latte\Engine::class)) {
        skip('Latte dependency is not installed; run composer install first.');
    }
}

test('the demo manifest describes a complete, self-consistent set', function (): void {
    assertSame(6, count(DemoContent::chunks()));
    assertSame(5, count(DemoContent::snippets()));
    assertSame(3, count(DemoContent::tvs()));
    assertSame(6, count(DemoContent::templates()));
    assertSame(6, count(DemoContent::documents()));

    $templateNames = array_keys(DemoContent::templateMap());

    // Every document names a template that exists, and carries a body.
    foreach (DemoContent::documents() as $document) {
        assertSame(
            true,
            in_array($document['template'], $templateNames, true),
            $document['alias'] . ' names an unknown template: ' . $document['template'],
        );
        assertSame(
            true,
            trim($document['body']) !== '',
            $document['alias'] . ' has an empty body',
        );
    }

    // A parent is named by alias and must already be installed when its child
    // is, which is what lets the seeder resolve parents in a single pass.
    $seen = [];
    foreach (DemoContent::documents() as $document) {
        if ($document['parent'] !== null) {
            assertSame(
                true,
                in_array($document['parent'], $seen, true),
                $document['alias'] . ' is listed before its parent ' . $document['parent'],
            );
        }
        $seen[] = $document['alias'];
    }
});

test('every demo snippet is valid PHP', function (): void {
    if (!function_exists('exec')) {
        skip('exec() is disabled, so php -l cannot be run.');
    }

    foreach (DemoContent::snippets() as $snippet) {
        $file = (string) tempnam(sys_get_temp_dir(), 'alx');
        file_put_contents($file, $snippet['body']);

        $output = [];
        $status = 0;
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1', $output, $status);
        @unlink($file);

        assertSame(0, $status, $snippet['name'] . ': ' . implode("\n", $output));
    }
});

test('every demo template compiles and renders', function (): void {
    requireLatte();

    foreach (DemoContent::documents() as $document) {
        $rendered = renderDemoDocument($document['alias']);

        assertSame(
            true,
            trim($rendered) !== '',
            $document['alias'] . ' rendered to nothing',
        );

        // A leaked token means protect() found a tag that restore() could not
        // put back - the one failure mode that silently corrupts a page.
        assertStringNotContains('__ALATTEX_', $rendered);
    }
});

test('EVO tags reach Evolution CMS untouched', function (): void {
    requireLatte();

    $rendered = renderDemoDocument('alattex-evo');

    // All six forms, verbatim, including a call whose parameter is itself a tag.
    assertStringContains('{{aLatteXDemoHeader}}', $rendered);
    assertStringContains('[[aLatteXDemoClock]]', $rendered);
    assertStringContains('[!aLatteXDemoClock!]', $rendered);
    assertStringContains('[*pagetitle*]', $rendered);
    assertStringContains('[(site_name)]', $rendered);
    assertStringContains('[+alx_note+]', $rendered);
    assertStringContains(
        '[[aLatteXDemoList? &items=`[*pagetitle*]||[(site_name)]` &class=`alx-mixed`]]',
        $rendered,
    );

    // evoSnippet() builds a tag out of Latte expressions rather than running
    // the snippet, so its backticks and ampersands must survive unescaped.
    assertStringContains('&items=`latte||evo||demo`', $rendered);
    assertStringNotContains('&amp;items=', $rendered);
    assertStringContains('[!aLatteXDemoClock?&format=`H:i:s`!]', $rendered);
});

test('a chunk of Latte source stays literal until something renders it', function (): void {
    requireLatte();

    $rendered = renderDemoDocument('alattex-chunks');

    // evoChunk() runs during the Latte pass but returns finished Html, so the
    // chunk's own tags are printed rather than compiled.
    assertStringContains('{$title|upper}', $rendered);

    // The pass that does compile it is a snippet call, left for the CMS.
    assertStringContains('[[aLatteXDemoLatte?', $rendered);
});

test('a chunk can be an explicitly rendered Latte partial', function (): void {
    requireLatte();

    $rendered = renderDemoDocument('alattex-basics');

    assertStringContains('<h4>Chunk-backed partial</h4>', $rendered);
    foreach (['Latte,', 'Evo,', 'Demo'] as $item) {
        assertStringContains($item, $rendered);
    }
    assertStringNotContains('{$title}', $rendered);
});

test('a snippet can hand a template data to loop over', function (): void {
    requireLatte();

    $rendered = renderDemoDocument('alattex-chunks');

    // The rows came back from $evo->runSnippet() as an array, during the Latte
    // pass, and the template looped over them - which is the whole difference
    // from [[aLatteXDemoRows]], resolved long afterwards by the CMS parser.
    assertStringContains('<a href="/index.php?id=3">Latte basics</a>', $rendered);
    assertStringContains('<a href="/index.php?id=4">EVO syntax</a>', $rendered);

    // The snippet returned data, so Latte is the one that escaped it.
    assertStringContains('Every Latte construct aLatteX passes through.', $rendered);

    // And the deferred spellings stay documentation rather than becoming live
    // tags: the paragraph explaining them is written with entities.
    assertStringNotContains('[[aLatteXDemoRows]]', $rendered);
});

test('syntax off stops Latte but not the Evolution CMS parser', function (): void {
    requireLatte();

    $rendered = renderDemoDocument('alattex-raw');

    // Inside {syntax off} the Latte tag survives as text ...
    assertStringContains('Latte tag, printed literally: {$pagetitle}', $rendered);
    // ... and the EVO tag survives as a tag, because it was tokenised before
    // Latte ever saw the block.
    assertStringContains('Snippet, still expanded by Evolution CMS: [[aLatteXDemoClock]]', $rendered);

    // The JavaScript object literal that would otherwise abort compilation.
    assertStringContains('{"theme": {"lineHeight": 1.5, "scale": [1, 2, 3]}}', $rendered);

    // A tag shown as documentation is written with entities, so nothing is
    // left for the CMS to expand.
    assertStringContains('&#91;&#91;aLatteXDemoClock&#93;&#93;', $rendered);

    // Expressions the bridge used to swallow: a nested array literal, a
    // reserved word, and a parenthesised subscript spelling [( … )].
    assertStringContains('Nested array literal: 3', $rendered);
    assertStringContains('2 inner arrays', $rendered);
    assertStringContains('JSON, never a snippet: null', $rendered);
    assertStringContains('Parenthesised subscript, which spells the setting form: 4', $rendered);
});

test('template variables arrive as ordinary Latte values', function (): void {
    requireLatte();

    $rendered = renderDemoDocument('alattex-tvs');

    // alxTags is stored as evo||demo||raw and split by the template.
    assertStringContains('<code>evo||demo||raw</code>', $rendered);
    assertStringContains('3 tags:', $rendered);

    // The per-document TV value, not the manifest default.
    assertStringContains('Values, not just placeholders', $rendered);

    // The image TV is empty on every demo document, so the guarded branch runs.
    assertStringContains('No image chosen for this document.', $rendered);

    // A TV that is not attached to the template is simply absent.
    assertStringContains('not attached to this template', $rendered);
});
