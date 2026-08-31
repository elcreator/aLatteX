<?php

declare(strict_types=1);

use Elcreator\aLatteX\TemplateEditor;

/**
 * A CMS stand-in for the two questions TemplateEditor asks of one.
 */
function templateEditorEvo(string $chunkProcessor = 'aLatteX'): object
{
    return new class ($chunkProcessor) {
        public function __construct(private string $chunkProcessor)
        {
        }

        public function getConfig(string $name): mixed
        {
            return $name === 'chunk_processor' ? $this->chunkProcessor : null;
        }
    };
}

/**
 * A base path whose CodeMirror assets are where the plugin keeps them.
 */
function templateEditorSite(bool $withAssets = true): string
{
    $root = ALATTEX_TEST_STORAGE . '/site-' . ($withAssets ? 'with' : 'without') . '-cm/';

    $dir = $withAssets ? $root . 'assets/plugins/codemirror/cm' : $root;

    // is_dir first, not @mkdir: PHPUnit's error handler reports a suppressed
    // warning all the same, and every test after the first would carry one.
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    return $root;
}

function templateEditorScript(array $vocabulary = []): string
{
    return TemplateEditor::scriptFor(
        templateEditorEvo(),
        templateEditorSite(),
        '/',
        $vocabulary + ['tags' => ['if', 'foreach'], 'filters' => ['upper'], 'functions' => ['evoChunk']]
    );
}

test('the template editor is decorated only while aLatteX is the chunk processor', function (): void {
    assertSame('', TemplateEditor::scriptFor(templateEditorEvo(''), templateEditorSite(), '/'));
    assertSame('', TemplateEditor::scriptFor(templateEditorEvo('DLTemplate'), templateEditorSite(), '/'));
    assertStringContains('<script>', templateEditorScript());
});

test('nothing is emitted when the CMS has no CodeMirror to decorate', function (): void {
    assertSame('', TemplateEditor::scriptFor(templateEditorEvo(), templateEditorSite(false), '/'));
    assertSame('', TemplateEditor::scriptFor(templateEditorEvo(), null, '/'));
    assertSame('', TemplateEditor::scriptFor(null, templateEditorSite(), '/'));
});

test('the emitted script carries no unresolved placeholder', function (): void {
    assertStringNotContains('__ALATTEX', templateEditorScript());
});

test('both names of the CMS overlay mode are recognised', function (): void {
    $script = templateEditorScript();

    // The CMS is renaming the mode; a plugin that insisted on either name would
    // break on half the cores it is installed into.
    assertStringContains("'Evo-htmlmixed'", $script);
    assertStringContains("'MODx-htmlmixed'", $script);
});

test('the vocabulary reaches the script, minus what aLatteX cannot run', function (): void {
    $script = templateEditorScript([
        'tags' => ['if', 'foreach', 'extends', 'layout', 'import', 'embed', 'sandbox', 'php', 'include'],
        'filters' => ['upper', 'truncate'],
        'functions' => ['evoChunk', 'evoSnippet', 'hasBlock'],
    ]);

    foreach (['if', 'foreach', 'include', 'upper', 'truncate', 'evoChunk', 'evoSnippet', 'hasBlock'] as $offered) {
        assertStringContains('"' . $offered . '"', $script);
    }

    // Each of these needs a second template to resolve a name against, which
    // the single-template loader cannot give it - see docs/latte-syntax.md.
    foreach (['extends', 'layout', 'import', 'embed', 'sandbox', 'php'] as $withheld) {
        assertStringNotContains('"' . $withheld . '"', $script);
    }
});

test('the document fields aLatteX spreads are completable', function (): void {
    $script = templateEditorScript();

    foreach (['pagetitle', 'longtitle', 'alias', 'content', 'template', 'evo', 'documentObject'] as $variable) {
        assertStringContains('"' . $variable . '"', $script);
    }
});

test('the engine is the source of the vocabulary', function (): void {
    if (!class_exists(\Latte\Engine::class)) {
        skip('Latte dependency is not installed; run composer install first.');
    }

    useFakeEvo();

    $vocabulary = (new \Elcreator\aLatteX\LattexEngine())->vocabulary();

    // Latte's own, so an upgrade that adds a tag adds it here too.
    assertStringContains('foreach', implode(' ', $vocabulary['tags']));
    assertStringContains('truncate', implode(' ', $vocabulary['filters']));

    // And this plugin's, which is the half a Latte-only list would miss.
    foreach (['evoChunk', 'evoSnippet', 'evoUncachedSnippet', 'evoTv', 'evoSetting', 'evoPlaceholder'] as $fn) {
        assertStringContains($fn, implode(' ', $vocabulary['functions']));
    }
});

test('every Latte token the mode emits is given a colour in both themes', function (): void {
    $script = templateEditorScript();

    $tokens = ['latteKeyword', 'latteTag', 'latteVar', 'latteAttr', 'latteComment',
        'latteFilter', 'latteFunction'];

    foreach ($tokens as $token) {
        assertStringContains(".cm-$token{", $script);
        assertStringContains(".cm-s-one-dark .cm-$token{", $script);
    }
});

test('a tag body is tokenised, not painted in one colour', function (): void {
    $script = templateEditorScript();

    // The expression inside a tag is handed to its own tokeniser, so
    // {var $rows = [['title' => 'First']]} reads as a variable, an operator,
    // brackets and strings rather than as one magenta run.
    assertStringContains('function expression(stream, state)', $script);

    // Those parts are returned under CodeMirror's own token names, which is
    // what makes them follow the manager's theme in light and in dark without
    // this plugin having an opinion about their colour.
    foreach (['string', 'number', 'operator', 'keyword', 'atom', 'bracket', 'property'] as $standard) {
        assertStringContains("'" . $standard . "'", $script);
    }

    // A string that runs past the end of a line keeps its colour, the same way
    // an unclosed tag body does.
    assertStringContains('function string(stream, state)', $script);
});

test('the overlay carries state, so a tag may span lines', function (): void {
    $script = templateEditorScript();

    // A {var $x = [ ... ]} written over four lines is one tag, and an overlay
    // without state colours only the line it started on. CodeMirror carries an
    // overlay's state when it declares one, so these two are what make the rest
    // of the block keep its colour.
    assertStringContains('startState: function', $script);
    assertStringContains('copyState: function', $script);

    // And the mechanism itself: what is still open is remembered by its closing
    // delimiter, and picked up again on the next line.
    assertStringContains('state.open = { closer: closer, style: style }', $script);
});
