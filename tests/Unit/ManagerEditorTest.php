<?php

declare(strict_types=1);

use Elcreator\aLatteX\ManagerEditor;

/**
 * A site root with the CodeMirror plugin's assets where the class looks for
 * them, and one without. The check is `is_dir`, so empty directories are all
 * these need to be.
 */
function alattexSiteRoot(bool $withCodeMirror): string
{
    $root = sys_get_temp_dir() . '/alattex-editor-' . ($withCodeMirror ? 'with' : 'without') . '-' . getmypid() . '/';
    $needed = $withCodeMirror ? $root . 'assets/plugins/codemirror/cm' : $root;

    // Several tests want the same root; making it once keeps mkdir from
    // warning about the tree the previous test already built.
    if (is_dir($needed)) {
        return $root;
    }

    mkdir($needed, 0775, true);

    register_shutdown_function(static function () use ($root, $withCodeMirror): void {
        if ($withCodeMirror) {
            @rmdir($root . 'assets/plugins/codemirror/cm');
            @rmdir($root . 'assets/plugins/codemirror');
            @rmdir($root . 'assets/plugins');
            @rmdir($root . 'assets');
        }
        @rmdir($root);
    });

    return $root;
}

test('the content editor is offered only while aLatteX is the chunk processor', function (): void {
    $root = alattexSiteRoot(true);

    $off = ManagerEditor::scriptFor(
        useFakeEvo(config: ['chunk_processor' => 'DLTemplate']),
        $root,
        'https://example.test/'
    );

    $on = ManagerEditor::scriptFor(
        useFakeEvo(config: ['chunk_processor' => 'aLatteX']),
        $root,
        'https://example.test/'
    );

    assertSame('', $off);
    assertStringContains('<script>', $on);
    assertStringContains('"field":"ta"', $on);
    assertStringContains('"base":"https://example.test/assets/plugins/codemirror/"', $on);
});

test('nothing is emitted when the CMS has no CodeMirror to load', function (): void {
    $script = ManagerEditor::scriptFor(
        useFakeEvo(config: ['chunk_processor' => 'aLatteX']),
        alattexSiteRoot(false),
        'https://example.test/'
    );

    // Four stylesheets and five scripts that would all 404.
    assertSame('', $script);
});

test('which_editor does not decide anything on its own', function (): void {
    // A stock install names TinyMCE here (core/factory/settings.php) without
    // any editor being installed, so this setting cannot be read as "an editor
    // owns the field". The page says whether one is really there, and the
    // script decides that in the browser, off the which_editor picker.
    $script = ManagerEditor::scriptFor(
        useFakeEvo(config: [
            'chunk_processor' => 'aLatteX',
            'use_editor' => 1,
            'which_editor' => 'TinyMCE4',
        ]),
        alattexSiteRoot(true),
        '/'
    );

    assertStringContains('<script>', $script);
    assertStringContains("getElementById('which_editor')", $script);
});

test('every bundle the mode needs is in the load list', function (): void {
    $script = ManagerEditor::scriptFor(
        useFakeEvo(config: ['chunk_processor' => 'aLatteX']),
        alattexSiteRoot(true),
        '/'
    );

    // addon-compressed.js is the one that is easy to forget and impossible to
    // do without: overlayMode is an addon, not part of the library, and
    // fromTextArea throws "overlayMode is not a function" halfway through
    // without it. htmlmixed is what the overlay sits on, and it in turn needs
    // xml, javascript and css.
    foreach ([
        'cm/lib/codemirror-compressed.js',
        'cm/mode/xml-compressed.js',
        'cm/mode/javascript-compressed.js',
        'cm/mode/css-compressed.js',
        'cm/mode/htmlmixed-compressed.js',
        'cm/addon-compressed.js',
    ] as $bundle) {
        assertStringContains($bundle, $script, "not loaded: {$bundle}");
    }
});

test('the editor is sized from its content, within bounds', function (): void {
    $script = ManagerEditor::scriptFor(
        useFakeEvo(config: ['chunk_processor' => 'aLatteX']),
        alattexSiteRoot(true),
        '/'
    );

    // Sized from the content, not from the textarea: that field has no rows
    // attribute, so measuring it gives the browser's two-row default whatever
    // is inside it. An empty document gets the floor, a long one the cap.
    assertStringContains('MIN_LINES = 5', $script);
    assertStringContains('MAX_LINES = 20', $script);
    assertStringContains('MIN_LINES * perLine', $script);
    assertStringContains('MAX_LINES * perLine', $script);
    assertStringContains('heightAtLine', $script);
});

test('the emitted script carries no unresolved placeholder', function (): void {
    $script = ManagerEditor::scriptFor(
        useFakeEvo(config: ['chunk_processor' => 'aLatteX']),
        alattexSiteRoot(true),
        'https://example.test/'
    );

    assertStringNotContains('__ALATTEX_EDITOR_CONFIG__', $script);
});

test('the editor follows the manager into dark mode, cookie first', function (): void {
    $root = alattexSiteRoot(true);
    $dark = useFakeEvo(config: ['chunk_processor' => 'aLatteX', 'manager_theme_mode' => 3]);
    $light = useFakeEvo(config: ['chunk_processor' => 'aLatteX', 'manager_theme_mode' => 1]);

    unset($_COOKIE['EVO_themeMode']);
    assertStringContains('"theme":"one-dark"', ManagerEditor::scriptFor($dark, $root, '/'));
    assertStringContains('"theme":"default"', ManagerEditor::scriptFor($light, $root, '/'));

    // The switch writes a cookie and does not touch the setting, so the cookie
    // is what a user who flipped the theme this session is looking at.
    $_COOKIE['EVO_themeMode'] = '1';
    assertStringContains('"theme":"default"', ManagerEditor::scriptFor($dark, $root, '/'));

    $_COOKIE['EVO_themeMode'] = '4';
    assertStringContains('"theme":"one-dark"', ManagerEditor::scriptFor($light, $root, '/'));

    unset($_COOKIE['EVO_themeMode']);
});
