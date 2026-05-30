<?php

declare(strict_types=1);

use Elcreator\aLatteX\EvoSyntaxBridge;

test('protects and restores realistic EVO tags around Latte syntax', function (): void {
    $template = <<<'HTML'
<!doctype html>
<html lang="{evoSetting('manager_language')}">
<head>
    <title>{$pagetitle} - [(site_name)]</title>
    {{head_chunk}}
</head>
<body>
    {{nav_chunk?&level=`2`}}
    <main>[*content*]</main>
    [[Breadcrumbs?&id=`[*id*]`&tpl=`@CODE:<li>[+title+]</li>`]]
    [!RandomBanner!]
    [+breadcrumbs+]
</body>
</html>
HTML;

    $bridge = new EvoSyntaxBridge();
    $protected = $bridge->protect($template);

    foreach ([
        '{{head_chunk}}',
        '{{nav_chunk?&level=`2`}}',
        '[*content*]',
        '[(site_name)]',
        '[[Breadcrumbs?&id=`[*id*]`&tpl=`@CODE:<li>[+title+]</li>`]]',
        '[!RandomBanner!]',
        '[+breadcrumbs+]',
    ] as $tag) {
        assertStringNotContains($tag, $protected, "EVO tag was not protected: {$tag}");
    }

    assertStringContains('{$pagetitle}', $protected, 'Latte variables should remain visible to Latte.');
    assertStringContains('{evoSetting(\'manager_language\')}', $protected, 'Latte helpers should remain visible to Latte.');
    assertSame($template, $bridge->restore($protected));
});

test('resets token map between render calls', function (): void {
    $bridge = new EvoSyntaxBridge();

    $first = $bridge->protect('{{header}} [[Menu]]');
    assertSame('{{header}} [[Menu]]', $bridge->restore($first));

    $second = $bridge->protect('<main>[*content*]</main>');
    assertSame('<main>[*content*]</main>', $bridge->restore($second));
    assertSame('__ALATTEX_0__', $bridge->protect('[+notice+]'));
});
