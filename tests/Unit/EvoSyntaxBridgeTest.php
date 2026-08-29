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

/**
 * The name test, from both sides.
 *
 * A delimiter alone does not make a tag: `[[1, 2], [3, 4]]` is a nested array
 * literal and `$row[($i + 1)]` is a subscript. Both used to be swallowed here
 * and never reached Latte at all.
 */
test('only tokenises brackets that could name an element', function (): void {
    $isTag = static function (string $source): bool {
        $bridge = new EvoSyntaxBridge();

        // Tokens carry a per-template HMAC, so the whole string having been
        // replaced by exactly one of them is the test, not its literal text.
        return (bool) preg_match('/^__ALATTEX_[0-9a-f]{16}_0__$/D', $bridge->protect($source));
    };

    foreach ([
        '{{head_chunk}}',
        '{{nav_chunk?&level=`2`}}',
        '[[Breadcrumbs]]',
        '[[DocLister?&parents=`1`]]',
        '[[DocLister?parents=`1`]]',      // ? without a leading &
        '[[DocLister &parents=`1`]]',     // space + & boundary
        "[[DocLister\n&parents=`1`]]",    // newline boundary
        '[[ DocLister ? &x=`1` ]]',
        '[[namespace#snippet]]',
        '[[snippet:filter=`x`]]',
        '[[[+snippetname+]]]',
        '[[$_GET(id)]]',                  // superglobal tags, Core::_getSGVar()
        "[[\$_SERVER['HTTP_HOST']]]",
        '[[$_SERVER]]',
        '[!$_GET(id)!]',
        '[!RandomBanner!]',
        '[*content*]',
        '[*#pagetitle*]',                  // QuickEdit form
        '[*pagetitle@1*]',                 // context form
        '[*tv_name_[+param+]*]',           // interpolated name
        '[*custom:ne:then=`a`:else=`b`*]', // output filters
        '[(site_name)]',
        '[+placeholder+]',
    ] as $tag) {
        assertSame(true, $isTag($tag), "should be protected: {$tag}");
    }

    foreach ([
        '[[1, 2], [3, 4]]',   // nested array literal
        '[[1,2],[3,4]]',
        "[['a'], ['b']]",     // ... of strings
        '[[$a], [$b]]',       // ... of variables
        '[[$_x], [$_y]]',     // ... of variables that only look superglobal
        '[[foo], [bar]]',     // ... of bare constants: a name cannot end in ]
        '[[foo, bar]]',       // ... nor can parameters begin with one
        '[[null], [1]]',
        '[[null]]',           // valid JSON, never a snippet
        '[[true]]',
        '[[NULL]]',
        '[[]]',
        '[(1)]',              // parenthesised subscript, not a setting
        '[!$b]',
        '[+1]',
    ] as $expression) {
        assertSame(false, $isTag($expression), "should be left for Latte: {$expression}");
    }
});

test('a real tag inside a rejected region is still found', function (): void {
    $bridge = new EvoSyntaxBridge();

    // The name test is part of the pattern rather than a veto applied after a
    // match, so the scanner carries on through the array literal instead of
    // having consumed it.
    $protected = $bridge->protect('{var $m = [[1,2],[3,4]]} then [[Real]]');

    assertStringContains('{var $m = [[1,2],[3,4]]} then __ALATTEX_', $protected);
    assertSame('{var $m = [[1,2],[3,4]]} then [[Real]]', $bridge->restore($protected));
});

/**
 * Tokens must be unforgeable.
 *
 * restore() is a str_replace over the *rendered* page, and a token is plain
 * alphanumeric text - Latte escapes it to itself. A predictable token would
 * therefore let any value that reaches the page (a field, a TV, a query
 * parameter echoed into it) name a tag from the template and have the CMS
 * execute it. This was demonstrated on a live site before the HMAC was added.
 */
test('tokens cannot be guessed from the template alone', function (): void {
    $bridge = new EvoSyntaxBridge();
    $protected = $bridge->protect('<p>[[Snippet]]</p>');

    assertStringNotContains('__ALATTEX_0__', $protected);
    assertSame(1, preg_match('/__ALATTEX_[0-9a-f]{16}_0__/', $protected));

    // Someone spelling the old, guessable token gets it back unchanged.
    assertSame(
        '<p>__ALATTEX_0__</p>',
        $bridge->restore('<p>__ALATTEX_0__</p>'),
    );
});

/**
 * ... and stable, or Latte's cache id changes on every request and the whole
 * site recompiles. The prefix is an HMAC of the template, so it is fixed for a
 * given template and different for a different one.
 */
test('the token prefix is stable per template', function (): void {
    $template = '<p>[[Snippet]] {$x}</p>';

    $first = (new EvoSyntaxBridge())->protect($template);
    $second = (new EvoSyntaxBridge())->protect($template);
    $other = (new EvoSyntaxBridge())->protect('<p>[[Other]]</p>');

    assertSame($first, $second);
    assertSame(false, $first === $other);
});

test('element names are recognised the same way the patterns match them', function (): void {
    foreach (['DocLister', 'ns#snippet', 'a.b-c', 'pagetitle@1', '#field', '_x'] as $name) {
        assertSame(true, EvoSyntaxBridge::isElementName($name), $name);
    }

    foreach (['', '1abc', 'a]]', 'a b', "a\nb", 'a`b', '[[x', 'a]] [[b'] as $name) {
        assertSame(false, EvoSyntaxBridge::isElementName($name), $name);
    }
});

test('resets token map between render calls', function (): void {
    $bridge = new EvoSyntaxBridge();

    $first = $bridge->protect('{{header}} [[Menu]]');
    assertSame('{{header}} [[Menu]]', $bridge->restore($first));

    $second = $bridge->protect('<main>[*content*]</main>');
    assertSame('<main>[*content*]</main>', $bridge->restore($second));

    $third = $bridge->protect('[+notice+]');
    assertSame(1, preg_match('/^__ALATTEX_[0-9a-f]{16}_0__$/D', $third));
    assertSame('[+notice+]', $bridge->restore($third));
});
