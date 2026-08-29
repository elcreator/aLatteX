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

/**
 * Snippet helpers return raw Html and their output reaches the CMS parser
 * unescaped, so an untrusted parameter value must not be able to end the value
 * and start a tag of its own. EVO delimits values with backticks and has no
 * escape for one, so the delimiters are removed.
 */
test('snippet parameters cannot break out of the tag they are written into', function (): void {
    useFakeEvo();

    $extension = new Elcreator\aLatteX\EvoExtension();

    // The injected call is gone; the trailing `]] is this tag's own terminator.
    assertSame(
        '[[X?&a=` Evil`]]',
        (string) $extension->snippet('X', ['a' => '`]] [[Evil']),
    );

    $out = (string) $extension->snippet('X', ['a' => '`&b=`injected']);
    assertSame('[[X?&a=`&b=injected`]]', $out);

    $out = (string) $extension->uncachedSnippet('X', ['a' => '!] [!Evil']);
    assertStringNotContains('[!Evil', $out);

    // Values that are merely awkward, not dangerous, are left intact - the
    // @CODE templates snippets are normally given depend on it.
    assertSame(
        '[[DocLister?&tpl=`@CODE:<li>[+pagetitle+]</li>`]]',
        (string) $extension->snippet('DocLister', ['tpl' => '@CODE:<li>[+pagetitle+]</li>']),
    );
});

test('snippet and parameter names are rejected when they are not names', function (): void {
    useFakeEvo();

    $extension = new Elcreator\aLatteX\EvoExtension();

    foreach (['X]] [[Evil', '1abc', 'a b', ''] as $name) {
        $threw = false;
        try {
            $extension->snippet($name);
        } catch (\InvalidArgumentException) {
            $threw = true;
        }
        assertSame(true, $threw, "should have been refused: {$name}");
    }

    $threw = false;
    try {
        $extension->snippet('X', ['a]] [[Evil' => '1']);
    } catch (\InvalidArgumentException) {
        $threw = true;
    }
    assertSame(true, $threw, 'a parameter name should be refused too');
});
