<?php

declare(strict_types=1);

/**
 * The plugin's half of the view-file contract.
 *
 * A document rendered from views/<alias>.latte is finished output as far as the
 * core is concerned, so the EVO parser does not run over it. Evolution CMS
 * 3.5.8 turned that into `Core::$runDocumentParser`, set from `!$template` and
 * still open while OnLoadWebDocument fires, so a view engine whose syntax
 * survives the pass can ask for it rather than re-implement it.
 *
 * These assertions are made against the plugin's source. The file is a list of
 * `Event::listen()` calls against a booted CMS, which the suite deliberately
 * does not have - and what is worth pinning here is structural anyway: that the
 * property is asked for, that the by-hand passes stay behind it as the fallback
 * for an older core, and that the config switch is read before either.
 */

$pluginSource = static fn (): string => str_replace(
    chr(13),
    '',
    (string) file_get_contents(dirname(__DIR__, 2) . '/plugins/aLattexPlugin.php')
);

test('a .latte view asks the core for the parser pass', function () use ($pluginSource): void {
    assertStringContains('$evo->runDocumentParser = true;', $pluginSource());
});

test('the request is guarded, so an older core still gets the passes by hand', function () use ($pluginSource): void {
    // property_exists() is the whole compatibility story: a core without the
    // property falls through to parseDocumentSource() and the rest below.
    $source = $pluginSource();

    assertStringContains("if (property_exists(\$evo, 'runDocumentParser')) {", $source);
    assertStringContains('$content = $evo->parseDocumentSource($content);', $source);
    assertStringContains('$content = $evo->cleanUpMODXTags($content);', $source);
    assertStringContains('$content = $evo->rewriteUrls($content);', $source);
});

test('the pass is switchable off, and defaults to on', function () use ($pluginSource): void {
    $source = $pluginSource();

    assertStringContains("config('alattex.evo_tags', true)", $source);

    // The switch has to be read before anything else in the helper, or turning
    // it off would still leave one of the two paths running.
    $switch = strpos($source, 'if (!alattexEvoTagsEnabled()) {');
    $property = strpos($source, '$evo->runDocumentParser = true;');
    $byHand = strpos($source, '$content = $evo->parseDocumentSource($content);');

    assertSame(true, is_int($switch) && is_int($property) && $switch < $property);
    assertSame(true, is_int($byHand) && $switch < $byHand);
});

test('only a .latte view is touched', function () use ($pluginSource): void {
    // A .blade.php template reaches the same core branch; its behaviour there
    // is Blade's contract with the CMS, not this plugin's to rewrite.
    assertStringContains("str_ends_with(strtolower(\$viewPath), '.latte')", $pluginSource());
});
