<?php

declare(strict_types=1);

use Elcreator\aLatteX\EvoExtension;
use Latte\Runtime\Html;

test('chunk helper returns intentional HTML without converting it to plain text', function (): void {
    useFakeEvo(
        chunks: [
            'hero' => '<section class="hero">{{hero_inner}}<h1>[*pagetitle*]</h1></section>',
        ],
    );

    $html = (new EvoExtension())->chunk('hero');

    assertSame(Html::class, $html::class);
    assertSame(
        '<section class="hero">{{hero_inner}}<h1>[*pagetitle*]</h1></section>',
        (string) $html,
    );
});

test('snippet helpers preserve EVO parameter delimiters for the later parser pass', function (): void {
    $extension = new EvoExtension();

    $cached = $extension->snippet('DocLister', [
        'parents' => '42',
        'tpl' => '@CODE:<li>[+pagetitle+]</li>',
        'tvList' => 'image,summary',
    ]);

    $uncached = $extension->uncachedSnippet('RandomBanner', [
        'category' => 'homepage',
        'docid' => '[*id*]',
    ]);

    assertSame(Html::class, $cached::class);
    assertSame(
        '[[DocLister?&parents=`42`&tpl=`@CODE:<li>[+pagetitle+]</li>`&tvList=`image,summary`]]',
        (string) $cached,
    );

    assertSame(Html::class, $uncached::class);
    assertSame(
        '[!RandomBanner?&category=`homepage`&docid=`[*id*]`!]',
        (string) $uncached,
    );
});

test('data helpers return plain strings so Latte can escape normal values', function (): void {
    useFakeEvo(
        documentObject: ['pagetitle' => '<About & News>'],
        config: ['site_name' => 'Example & Co'],
        placeholders: ['notice' => '<strong>Saved</strong>'],
    );

    $extension = new EvoExtension();

    assertSame('<About & News>', $extension->tv('pagetitle'));
    assertSame('Example & Co', $extension->setting('site_name'));
    assertSame('<strong>Saved</strong>', $extension->placeholder('notice'));
});
