<?php

declare(strict_types=1);

/**
 * The aLatteX demo set, as data.
 *
 * Only names, relationships and metadata live here; every body is a file in
 * one of the sibling directories, so a chunk is editable as HTML and a
 * template as Latte, with syntax highlighting and `php -l` where it applies.
 *
 * Nothing in this file touches a database or the CMS. Elcreator\aLatteX\Demo\
 * DemoContent turns it into arrays with the bodies loaded, which is what both
 * the installer (`composer demo:install`) and the test suite consume - so the
 * pages a developer clicks through and the fixtures CI renders are the same
 * bytes.
 *
 * Element names are referenced by other entries, so they are the join keys:
 * a document names its template, a TV names the templates it is attached to.
 */

return [
    /*
     * Every element is filed under this category, which is created if it is
     * missing and dropped again on removal once it is empty. It is also what
     * makes the demo easy to find in the manager's element trees.
     */
    'category' => 'aLatteX demo',

    /*
     * Chunks. Plain markup as far as Latte is concerned - see the header of
     * demo/chunks/card.html for the one that is deliberately not.
     */
    'chunks' => [
        [
            'name' => 'aLatteXDemoHeader',
            'description' => 'Page header. EVO tags only - what a chunk normally is.',
            'file' => 'chunks/header.html',
        ],
        [
            'name' => 'aLatteXDemoNav',
            'description' => 'A chunk that calls a snippet, to show chunk/snippet nesting.',
            'file' => 'chunks/nav.html',
        ],
        [
            'name' => 'aLatteXDemoBadge',
            'description' => 'One line, included from another chunk and from PHP.',
            'file' => 'chunks/badge.html',
        ],
        [
            'name' => 'aLatteXDemoFooter',
            'description' => 'Cacheable vs non-cacheable snippet calls, plus a nested chunk.',
            'file' => 'chunks/footer.html',
        ],
        [
            'name' => 'aLatteXDemoCard',
            'description' => 'Latte source in a chunk. Literal unless rendered by aLatteXDemoLatte.',
            'file' => 'chunks/card.html',
        ],
        [
            'name' => 'aLatteXDemoPartial',
            'description' => 'A chunk explicitly included as a Latte partial, with native arguments.',
            'file' => 'chunks/partial.html',
        ],
    ],

    /*
     * Snippets. Stored with their leading <?php, which Evolution CMS strips
     * before eval() - keeping it means the files lint and open in an editor as
     * PHP.
     */
    'snippets' => [
        [
            'name' => 'aLatteXDemoList',
            'description' => 'Parameters, placeholders and getChunk() - an ordinary snippet.',
            'file' => 'snippets/list.php',
            'properties' => '',
        ],
        [
            'name' => 'aLatteXDemoClock',
            'description' => 'Prints the time. Shows [[cacheable]] against [!non-cacheable!].',
            'file' => 'snippets/clock.php',
            'properties' => '',
        ],
        [
            'name' => 'aLatteXDemoLatte',
            'description' => 'Renders a chunk or an inline string through aLatteX itself.',
            'file' => 'snippets/latte.php',
            'properties' => '',
        ],
        [
            'name' => 'aLatteXDemoNested',
            'description' => 'Returns EVO tags and calls runSnippet(): both ways of nesting.',
            'file' => 'snippets/nested.php',
            'properties' => '',
        ],
        [
            'name' => 'aLatteXDemoRows',
            'description' => 'Returns an array of documents, for a template to loop over during the Latte pass.',
            'file' => 'snippets/rows.php',
            'properties' => '',
        ],
    ],

    /*
     * Template variables. Three types, because the interesting part is what
     * Latte can do with the value once it has it - a multi-value TV is a ||
     * separated string until something splits it.
     */
    'tvs' => [
        [
            'name' => 'alxSubtitle',
            'caption' => 'Demo subtitle',
            'description' => 'Plain text TV, printed three different ways on the TV demo page.',
            'type' => 'text',
            'elements' => '',
            'default_text' => 'A Latte eXtended demo page',
            'display' => '',
            'display_params' => '',
            'rank' => 0,
            'templates' => '*',
        ],
        [
            'name' => 'alxTags',
            'caption' => 'Demo tags',
            'description' => 'Multi-value TV. Stored as latte||evo||demo; split in Latte with |explode.',
            'type' => 'checkbox',
            'elements' => 'latte==latte||evo==evo||demo==demo||raw==raw',
            'default_text' => 'latte||evo||demo',
            'display' => '',
            'display_params' => '',
            'rank' => 1,
            'templates' => '*',
        ],
        [
            'name' => 'alxImage',
            'caption' => 'Demo image',
            'description' => 'Image TV, left empty so the templates can show a guarded read.',
            'type' => 'image',
            'elements' => '',
            'default_text' => '',
            'display' => '',
            'display_params' => '',
            'rank' => 2,
            'templates' => '*',
        ],
    ],

    /*
     * Templates. templatealias is deliberately left empty: an alias that
     * resolves to a file under views/ makes the CMS render that file instead,
     * and the whole point of the demo is templates held in the database.
     */
    'templates' => [
        [
            'name' => 'aLatteX Demo: Home',
            'description' => 'Index page. One layout defined with {define}, filled per section.',
            'file' => 'templates/home.latte',
        ],
        [
            'name' => 'aLatteX Demo: Latte basics',
            'description' => 'Latte 3 in full, plus a CMS chunk rendered explicitly as a partial.',
            'file' => 'templates/basics.latte',
        ],
        [
            'name' => 'aLatteX Demo: EVO syntax',
            'description' => 'All six EVO tag forms, plus the evo* Latte functions.',
            'file' => 'templates/evo-syntax.latte',
        ],
        [
            'name' => 'aLatteX Demo: Chunks and snippets',
            'description' => 'Latte inside chunks and snippets, and why it is literal by default.',
            'file' => 'templates/chunks-and-snippets.latte',
        ],
        [
            'name' => 'aLatteX Demo: Raw output',
            'description' => 'syntax off, brace literals, escaping contexts, and the brace traps.',
            'file' => 'templates/raw-output.latte',
        ],
        [
            'name' => 'aLatteX Demo: Fields and TVs',
            'description' => 'Document fields, template variables and $evo as Latte values.',
            'file' => 'templates/tvs-and-fields.latte',
        ],
    ],

    /*
     * Documents. The first is a folder; the rest are its children, so the
     * whole demo is one subtree in the resource tree and one line in a menu.
     * 'parent' names another document's alias, or null for a site root.
     */
    'documents' => [
        [
            'alias' => 'alattex-demo',
            'pagetitle' => 'aLatteX demo',
            'longtitle' => 'A Latte eXtended template parser, demonstrated',
            'menutitle' => 'aLatteX demo',
            'template' => 'aLatteX Demo: Home',
            'parent' => null,
            'isfolder' => true,
            'file' => 'documents/home.html',
            'tvs' => [
                'alxSubtitle' => 'Start here',
                'alxTags' => 'latte||evo||demo',
            ],
        ],
        [
            'alias' => 'alattex-basics',
            'pagetitle' => 'Latte basics',
            'longtitle' => 'Every Latte construct aLatteX passes through',
            'menutitle' => 'Basics',
            'template' => 'aLatteX Demo: Latte basics',
            'parent' => 'alattex-demo',
            'isfolder' => false,
            'file' => 'documents/basics.html',
            'tvs' => [
                'alxSubtitle' => 'Stock Latte 3, from the database',
                'alxTags' => 'latte',
            ],
        ],
        [
            'alias' => 'alattex-evo',
            'pagetitle' => 'EVO syntax',
            'longtitle' => 'Evolution CMS tags, and the bridge around them',
            'menutitle' => 'EVO syntax',
            'template' => 'aLatteX Demo: EVO syntax',
            'parent' => 'alattex-demo',
            'isfolder' => false,
            'file' => 'documents/evo-syntax.html',
            'tvs' => [
                'alxSubtitle' => 'Six tag forms, twice over',
                'alxTags' => 'evo||demo',
            ],
        ],
        [
            'alias' => 'alattex-chunks',
            'pagetitle' => 'Chunks and snippets',
            'longtitle' => 'Latte inside chunks and snippets',
            'menutitle' => 'Chunks',
            'template' => 'aLatteX Demo: Chunks and snippets',
            'parent' => 'alattex-demo',
            'isfolder' => false,
            'file' => 'documents/chunks-and-snippets.html',
            'tvs' => [
                'alxSubtitle' => 'One pass, and how to buy a second',
                'alxTags' => 'latte||evo',
            ],
        ],
        [
            'alias' => 'alattex-raw',
            'pagetitle' => 'Raw output',
            'longtitle' => 'Braces that must survive both parsers',
            'menutitle' => 'Raw output',
            'template' => 'aLatteX Demo: Raw output',
            'parent' => 'alattex-demo',
            'isfolder' => false,
            'file' => 'documents/raw-output.html',
            'tvs' => [
                'alxSubtitle' => 'syntax off, and what it does not cover',
                'alxTags' => 'raw||latte',
            ],
        ],
        [
            'alias' => 'alattex-tvs',
            'pagetitle' => 'Fields and TVs',
            'longtitle' => 'Document fields and template variables as Latte values',
            'menutitle' => 'Fields and TVs',
            'template' => 'aLatteX Demo: Fields and TVs',
            'parent' => 'alattex-demo',
            'isfolder' => false,
            'file' => 'documents/tvs-and-fields.html',
            'tvs' => [
                'alxSubtitle' => 'Values, not just placeholders',
                'alxTags' => 'evo||demo||raw',
            ],
        ],
    ],
];
