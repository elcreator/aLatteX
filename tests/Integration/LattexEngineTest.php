<?php

declare(strict_types=1);

use Elcreator\aLatteX\LattexEngine;

test('renders Latte variables while preserving realistic EVO template tags', function (): void {
    if (!class_exists(\Latte\Engine::class)) {
        skip('Latte dependency is not installed; run composer install first.');
    }

    useFakeEvo(
        documentObject: [
            'id' => 42,
            'pagetitle' => 'About & News',
            'longtitle' => '',
            'content' => 'Body copy from the document record.',
        ],
        config: [
            'manager_language' => 'en',
            'site_name' => 'Example & Co',
        ],
        chunks: [
            'hero' => '<section class="hero">{{hero_inner}}<h1>[*pagetitle*]</h1></section>',
        ],
    );

    $template = <<<'HTML'
<!doctype html>
<html lang="{evoSetting('manager_language')}">
<head>
    <title>{$pagetitle} - {evoSetting('site_name')}</title>
    {{head_chunk}}
</head>
<body>
{if $longtitle}
    <h1>{$longtitle}</h1>
{else}
    <h1>{$pagetitle}</h1>
{/if}
    {evoChunk('hero')}
    {{nav_chunk?&level=`2`}}
    <main>[*content*]</main>
    [[Breadcrumbs?&id=`[*id*]`&tpl=`@CODE:<li>[+title+]</li>`]]
    {evoSnippet('DocLister', ['parents' => $id, 'tpl' => '@CODE:<article>[+pagetitle+]</article>'])}
    {evoUncachedSnippet('RandomBanner', ['docid' => '[*id*]'])}
    [+breadcrumbs+]
</body>
</html>
HTML;

    $rendered = (new LattexEngine())->render($template, evo()->documentObject);

    assertStringContains('<html lang="en">', $rendered);
    assertStringContains('<title>About &amp; News - Example &amp; Co</title>', $rendered);
    assertStringContains('<h1>About &amp; News</h1>', $rendered);

    assertStringContains('{{head_chunk}}', $rendered);
    assertStringContains('{{nav_chunk?&level=`2`}}', $rendered);
    assertStringContains('<main>[*content*]</main>', $rendered);
    assertStringContains(
        '[[Breadcrumbs?&id=`[*id*]`&tpl=`@CODE:<li>[+title+]</li>`]]',
        $rendered,
    );
    assertStringContains('[+breadcrumbs+]', $rendered);

    assertStringContains(
        '<section class="hero">{{hero_inner}}<h1>[*pagetitle*]</h1></section>',
        $rendered,
    );
    assertStringContains(
        '[[DocLister?&parents=`42`&tpl=`@CODE:<article>[+pagetitle+]</article>`]]',
        $rendered,
    );
    assertStringContains('[!RandomBanner?&docid=`[*id*]`!]', $rendered);

    assertStringNotContains('&amp;parents=', $rendered);
    assertStringNotContains('&lt;article&gt;', $rendered);
    assertStringNotContains('__ALATTEX_', $rendered);
});
