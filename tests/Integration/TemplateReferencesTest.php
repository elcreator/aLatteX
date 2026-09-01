<?php

declare(strict_types=1);

use Elcreator\aLatteX\LattexEngine;
use Elcreator\aLatteX\SourceLoader;
use Elcreator\aLatteX\EvoSyntaxBridge;
use Latte\TemplateNotFoundException;

function referenceViewDirectory(): string
{
    $dir = ALATTEX_TEST_STORAGE . '/reference-views';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    return $dir;
}

function writeReferenceView(string $name, string $source): string
{
    $path = referenceViewDirectory() . '/' . $name;
    file_put_contents($path, $source);

    return $path;
}

function assertTemplateReferenceRejected(SourceLoader $loader, string $name): void
{
    try {
        $loader->getReferredName($name, 'Evolution template #1');
    } catch (TemplateNotFoundException) {
        assertSame(true, true);
        return;
    }

    throw new RuntimeException("Template reference '$name' should have been rejected.");
}

test('a database template can extend a flat file layout', function (): void {
    if (!class_exists(\Latte\Engine::class)) {
        skip('Latte dependency is not installed; run composer install first.');
    }

    useFakeEvo(documentObject: ['pagetitle' => 'Inherited & safe']);

    writeReferenceView('base.latte', <<<'LATTE'
<!doctype html>
<title>{$pagetitle}</title>
{{layoutHead}}
<main>{include content}</main>
LATTE);

    $child = <<<'LATTE'
{layout 'base.latte'}
{block content}<h1>{$pagetitle}</h1>[*content*]{/block}
LATTE;

    $rendered = (new LattexEngine([referenceViewDirectory()]))
        ->render($child, evo()->documentObject);

    assertStringContains('<title>Inherited &amp; safe</title>', $rendered);
    assertStringContains('<h1>Inherited &amp; safe</h1>[*content*]', $rendered);
    assertStringContains('{{layoutHead}}', $rendered);
    assertStringNotContains('__ALATTEX_', $rendered);
});

test('a chunk can be rendered explicitly as a Latte partial with parameters', function (): void {
    if (!class_exists(\Latte\Engine::class)) {
        skip('Latte dependency is not installed; run composer install first.');
    }

    useFakeEvo(
        documentObject: ['pagetitle' => 'Chunk page'],
        chunks: [
            'ProductCard' => '<article><h2>{$title}</h2><b n:if="$featured">featured</b> {{badge}}</article>',
        ],
    );

    $template = <<<'LATTE'
{include 'chunk:ProductCard', title: $pagetitle, featured: true}
LATTE;

    $rendered = (new LattexEngine([referenceViewDirectory()]))
        ->render($template, evo()->documentObject);

    assertStringContains('<h2>Chunk page</h2>', $rendered);
    assertStringContains('<b>featured</b>', $rendered);
    assertStringContains('{{badge}}', $rendered);
    assertStringNotContains('__ALATTEX_', $rendered);
});

test('file include import and embed use the same flat resolver', function (): void {
    if (!class_exists(\Latte\Engine::class)) {
        skip('Latte dependency is not installed; run composer install first.');
    }

    useFakeEvo();
    writeReferenceView('piece.latte', '<p>{$label}</p>');
    writeReferenceView('definitions.latte', '{define note, string $text}<i>{$text}</i>{/define}');
    writeReferenceView('frame.latte', '<section>{block content}default{/block}</section>');

    $source = <<<'LATTE'
{include 'piece.latte', label: 'included'}
{import 'definitions.latte'}
{include note, text: 'imported'}
{embed 'frame.latte'}{block content}embedded{/block}{/embed}
LATTE;

    $rendered = (new LattexEngine([referenceViewDirectory()]))->render($source);

    assertStringContains('<p>included</p>', $rendered);
    assertStringContains('<i>imported</i>', $rendered);
    assertStringContains('<section>embedded</section>', $rendered);
});

test('ordinary EVO chunk syntax does not opt a chunk into Latte rendering', function (): void {
    if (!class_exists(\Latte\Engine::class)) {
        skip('Latte dependency is not installed; run composer install first.');
    }

    useFakeEvo(
        documentObject: ['pagetitle' => 'Literal'],
        chunks: ['ProductCard' => '<h2>{$pagetitle}</h2>'],
    );

    $rendered = (new LattexEngine([referenceViewDirectory()]))
        ->render('{{ProductCard}}', evo()->documentObject);

    assertSame('{{ProductCard}}', $rendered);
});

test('nested source tokens survive repeated renders through one engine', function (): void {
    if (!class_exists(\Latte\Engine::class)) {
        skip('Latte dependency is not installed; run composer install first.');
    }

    useFakeEvo(
        documentObject: ['pagetitle' => 'Repeated'],
        chunks: ['Panel' => '<aside>[[fromChunk]] {$pagetitle}</aside>'],
    );
    writeReferenceView('shell.latte', "{{fromFile}}\n{include content}");

    $engine = new LattexEngine([referenceViewDirectory()]);
    $source = "{layout 'shell.latte'}\n{block content}{include 'chunk:Panel'}{/block}";

    $first = $engine->render($source, evo()->documentObject);
    $second = $engine->render($source, evo()->documentObject);

    assertSame($first, $second);
    assertStringContains('{{fromFile}}', $second);
    assertStringContains('[[fromChunk]]', $second);
    assertStringNotContains('__ALATTEX_', $second);
});

test('editing a referenced file invalidates it on the same engine', function (): void {
    if (!class_exists(\Latte\Engine::class)) {
        skip('Latte dependency is not installed; run composer install first.');
    }

    useFakeEvo();
    writeReferenceView('changing.latte', 'first {include content}');

    $engine = new LattexEngine([referenceViewDirectory()]);
    $source = "{layout 'changing.latte'}{block content}body{/block}";
    assertStringContains('first body', $engine->render($source));

    writeReferenceView('changing.latte', 'second {include content}');
    assertStringContains('second body', $engine->render($source));
});

test('editing a chunk invalidates it on the same engine', function (): void {
    if (!class_exists(\Latte\Engine::class)) {
        skip('Latte dependency is not installed; run composer install first.');
    }

    useFakeEvo(chunks: ['ChangingChunk' => '<p>first</p>']);
    $engine = new LattexEngine([referenceViewDirectory()]);
    $source = "{include 'chunk:ChangingChunk'}";
    assertStringContains('<p>first</p>', $engine->render($source));

    useFakeEvo(chunks: ['ChangingChunk' => '<p>second</p>']);
    assertStringContains('<p>second</p>', $engine->render($source));
});

test('a file entry point can extend another flat file', function (): void {
    if (!class_exists(\Latte\Engine::class)) {
        skip('Latte dependency is not installed; run composer install first.');
    }

    useFakeEvo(documentObject: ['pagetitle' => 'File child']);
    writeReferenceView('filebase.latte', '<main>{include content}</main>');
    $child = writeReferenceView(
        'filechild.latte',
        "{layout 'filebase.latte'}{block content}{\$pagetitle}{/block}",
    );

    $rendered = (new LattexEngine([referenceViewDirectory()]))->renderView(
        $child,
        ['documentObject' => evo()->documentObject],
    );

    assertStringContains('<main>File child</main>', $rendered);
});

test('Laravel and Eloquent calls are valid Latte expressions', function (): void {
    if (!class_exists(\Latte\Engine::class)) {
        skip('Latte dependency is required.');
    }

    useFakeEvo();

    // The branch stays false because the test has no booted database. What it
    // pins is that namespaced Eloquent and Laravel helper calls compile as
    // ordinary Latte/PHP expressions inside the booted application.
    $source = <<<'LATTE'
{if false}
    {var $rows = EvolutionCMS\Models\SiteContent::query()->limit(1)->get()}
    {var $service = app('some.service')}
{/if}
data calls compile
LATTE;

    $rendered = (new LattexEngine([referenceViewDirectory()]))->render($source);
    assertStringContains('data calls compile', $rendered);
});

test('file references are flat and chunk bindings cannot escape their namespace', function (): void {
    $bridge = new EvoSyntaxBridge();
    $loader = new SourceLoader($bridge, [referenceViewDirectory()]);

    foreach ([
        '../base.latte',
        'layouts/base.latte',
        '/base.latte',
        'base.blade.php',
        'php://filter/resource=base.latte',
        'chunk:',
        'chunk:@FILE:secret',
        "chunk:bad\nname",
    ] as $name) {
        assertTemplateReferenceRejected($loader, $name);
    }
});

test('a missing file or chunk is reported as a missing template', function (): void {
    useFakeEvo();

    $bridge = new EvoSyntaxBridge();
    $loader = new SourceLoader($bridge, [referenceViewDirectory()]);

    assertTemplateReferenceRejected($loader, 'missing.latte');
    assertTemplateReferenceRejected($loader, 'chunk:MissingChunk');
});
