<?php

namespace Elcreator\aLatteX;

/**
 * Syntax highlighting for the Resource content field in the manager.
 *
 * Evolution CMS highlights the code of templates, chunks, snippets and TVs with
 * CodeMirror, through the CodeMirror plugin that ships in the base install set.
 * A *document* gets nothing, and not by design: the plugin decides with
 *
 *     $rte = $prte ?: (isset($content['id']) ? ($xrte ? $srte : 'none') : $srte);
 *     ...
 *     if (('none' == $rte) && $mode && !defined('INIT_CODEMIRROR')) { ...emit... }
 *
 * where `$srte` is `use_editor ? which_editor : 'none'`. A stock install has
 * `use_editor = 1` and `which_editor = 'TinyMCE4'` (core/factory/settings.php),
 * but no rich-text editor is part of the base install set - so `$rte` is the
 * name of an editor that is not there: not the 'none' CodeMirror waits for, and
 * not anything that will answer for the field either. Both step back and the
 * textarea is left bare. The elements are unaffected because their branches
 * force `$rte = 'none'`.
 *
 * So this fills the gap from the outside, without touching the core or the
 * CodeMirror plugin: same library, same shipped assets, same `myCodeMirrors`
 * registry the manager's dark-mode switch iterates, and a mode that highlights
 * the tags this field runs. If the CodeMirror plugin does get to the field - a
 * site that sets `which_editor` to `none` explicitly - this stands down and
 * leaves its editor alone.
 *
 * What it highlights is EVO tags, not Latte ones, and that is a statement about
 * the pipeline rather than an omission. aLatteX renders the *template* at
 * OnLoadWebDocument; `[*content*]` is substituted afterwards, by EVO's own
 * parser pass. So a document's content field reaches the page as data: its EVO
 * tags are resolved, and a `{$var}` in it is printed verbatim. Colouring Latte
 * here would promise a feature the pipeline does not have.
 */
class ManagerEditor
{
    /** Where the CodeMirror plugin keeps the library, relative to the site root. */
    private const ASSET_BASE = 'assets/plugins/codemirror/';

    /** The textarea the document form puts the content in. */
    private const FIELD = 'ta';

    /**
     * The <script> for the document form, or '' when it should not be there.
     *
     * Returns a string in both cases: invokeEvent() collects every non-null
     * return value, so opting out is an empty string and never null.
     */
    public static function documentEditorScript(): string
    {
        return self::scriptFor(
            function_exists('evo') ? evo() : null,
            defined('EVO_BASE_PATH') ? EVO_BASE_PATH : null,
            defined('EVO_SITE_URL') ? EVO_SITE_URL : '/'
        );
    }

    /**
     * The same decision, with the CMS handed in rather than reached for.
     *
     * `evo()` and the two path constants are the whole of this class's contact
     * with the site, so taking them as arguments is what lets the conditions
     * below be tested without a booted CMS.
     */
    public static function scriptFor(?object $evo, ?string $basePath, string $siteUrl): string
    {
        if ($evo === null || $basePath === null) {
            return '';
        }

        // Tied to the plugin being the active chunk processor, like everything
        // else it does: a site that switched aLatteX off should get its manager
        // back exactly as the CMS ships it.
        if ($evo->getConfig('chunk_processor') !== 'aLatteX') {
            return '';
        }

        // The library is the CMS's own copy. If the CodeMirror plugin was
        // removed - assets and all - there is nothing to load, and asking for
        // it anyway would only add four 404s to the page.
        if (!is_dir($basePath . self::ASSET_BASE . 'cm')) {
            return '';
        }

        $config = [
            'base' => $siteUrl . self::ASSET_BASE,
            'field' => self::FIELD,
            // Both themes are named so the manager's light/dark switch can swap
            // between them the way it does for every other CodeMirror instance
            // (evo.js reads options.defaulttheme / options.darktheme).
            'theme' => self::isDarkMode($evo) ? 'one-dark' : 'default',
            'defaulttheme' => 'default',
            'darktheme' => 'one-dark',
        ];

        $script = str_replace(
            '__ALATTEX_EDITOR_CONFIG__',
            (string) json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            self::js()
        );

        return "<script>\n" . $script . "\n</script>\n";
    }

    /**
     * The same test the CodeMirror plugin makes: the cookie the theme switch
     * writes wins over the configured mode, and modes 3 and 4 are the dark ones.
     */
    private static function isDarkMode(object $evo): bool
    {
        $dark = [3, 4];

        if (!empty($_COOKIE['EVO_themeMode'])) {
            return in_array((int) $_COOKIE['EVO_themeMode'], $dark, true);
        }

        return in_array((int) $evo->getConfig('manager_theme_mode'), $dark, true);
    }

    /**
     * Nowdoc, deliberately: the script is full of `{$` - the very sequence a
     * heredoc would try to interpolate. The one value that varies is spliced in
     * as JSON by the caller.
     */
    private static function js(): string
    {
        return <<<'JS'
(function () {
    'use strict';

    var CONFIG = __ALATTEX_EDITOR_CONFIG__;
    var MODE = 'alattex';

    // The editor opens at the height of its content, between these two bounds.
    // Measuring the textarea instead is no good: it carries no rows attribute,
    // so the browser gives it its two-row default whatever is inside it. An
    // empty document gets the floor, a long one gets the cap and scrolls.
    var MIN_LINES = 5;
    var MAX_LINES = 20;

    // EVO's tag forms, and the token names the shipped CodeMirror stylesheets
    // already colour - reusing them is what makes this look like the editor on
    // the template page rather than something bolted on.
    //
    // Only EVO tags, deliberately. Latte has already run and finished by the
    // time this field is substituted into the page, so a {$var} typed here is
    // printed verbatim, and colouring it like code would advertise something
    // that does not work. The tags below are the ones this field really runs.
    var EVO_TAGS = [
        ['{{', '}}', 'modxChunk'],
        ['[[', ']]', 'modxSnippet'],
        ['[!', '!]', 'modxSnippetNoCache'],
        ['[*', '*]', 'modxTv'],
        ['[+', '+]', 'modxPlaceholder'],
        ['[(', ')]', 'modxVariable'],
        ['[~', '~]', 'modxUrl'],
        ['[^', '^]', 'modxConfig']
    ];

    function ready(fn) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fn);
        } else {
            fn();
        }
    }

    function field() {
        return document.getElementsByName(CONFIG.field)[0];
    }

    function url(path) {
        return CONFIG.base + path;
    }

    function addCss(href, done) {
        var link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = href;
        link.onload = done;
        link.onerror = done;
        document.head.appendChild(link);
    }

    // Sequentially, because the modes register themselves against the library
    // and htmlmixed needs xml, javascript and css to be there first.
    function addScripts(list, done) {
        var next = list.shift();

        if (!next) {
            done();
            return;
        }

        var script = document.createElement('script');
        script.src = next;
        script.onload = function () {
            addScripts(list, done);
        };
        script.onerror = function () {
            done();
        };
        document.head.appendChild(script);
    }

    // Both halves have to be in before the editor is built, and the stylesheets
    // are the half that is easy to forget. CodeMirror measures the element it is
    // given: without codemirror.css applied there is no overflow:hidden on the
    // wrapper and no overflow:scroll on the scroller, so it concludes nothing
    // overflows and never shows a scrollbar. The CodeMirror plugin prints its
    // <link> tags into the markup and so cannot race them; these are injected,
    // and can.
    function load(done) {
        if (window.CodeMirror) {
            done();
            return;
        }

        var pending = 1;
        var ready = function () {
            pending -= 1;
            if (pending === 0) {
                done();
            }
        };

        [
            url('cm/lib/codemirror.css'),
            url('cm/addon.css'),
            url('cm/theme/' + CONFIG.defaulttheme + '.css'),
            url('cm/theme/' + CONFIG.darktheme + '.css')
        ].forEach(function (href) {
            pending += 1;
            addCss(href, ready);
        });

        addScripts([
            url('cm/lib/codemirror-compressed.js'),
            url('cm/mode/xml-compressed.js'),
            url('cm/mode/javascript-compressed.js'),
            url('cm/mode/css-compressed.js'),
            url('cm/mode/htmlmixed-compressed.js'),
            // overlayMode is an addon, not part of the library - without this
            // the mode below cannot be built at all.
            url('cm/addon-compressed.js')
        ], ready);
    }

    // An overlay on htmlmixed: the HTML underneath is tokenised as usual and
    // this only claims the template tags on top of it. CodeMirror runs an
    // overlay one line at a time, so a tag broken across lines is highlighted
    // as far as that line goes - which is how the manager's own MODx mode
    // behaves too.
    //
    // Returns the mode to open the editor with: the overlay when it can be
    // built, plain htmlmixed when it cannot. overlayMode comes from the addon
    // bundle, so a site whose assets are incomplete gets an editor with HTML
    // highlighting rather than an exception halfway through fromTextArea.
    function defineMode() {
        if (window.CodeMirror.modes[MODE]) {
            return MODE;
        }

        if (typeof window.CodeMirror.overlayMode !== 'function') {
            return 'htmlmixed';
        }

        window.CodeMirror.defineMode(MODE, function (config) {
            var overlay = {
                token: function (stream) {
                    var i, tag;

                    for (i = 0; i < EVO_TAGS.length; i += 1) {
                        tag = EVO_TAGS[i];
                        if (stream.match(tag[0])) {
                            consumeTo(stream, tag[1]);
                            return tag[2];
                        }
                    }

                    // Nothing here: run on to the next character that could
                    // open a tag, and let the base mode keep the rest.
                    while (stream.next() != null) {
                        if (opensTag(stream)) {
                            break;
                        }
                    }

                    return null;
                }
            };

            return window.CodeMirror.overlayMode(
                window.CodeMirror.getMode(config, 'htmlmixed'),
                overlay
            );
        });

        return MODE;
    }

    function consumeTo(stream, closer) {
        if (stream.skipTo(closer)) {
            stream.match(closer);
        } else {
            stream.skipToEnd();
        }
    }

    function opensTag(stream) {
        var i;

        for (i = 0; i < EVO_TAGS.length; i += 1) {
            if (stream.match(EVO_TAGS[i][0], false)) {
                return true;
            }
        }

        return false;
    }

    // Height of the text as laid out, not the number of newlines in it: with
    // lineWrapping on, one long paragraph occupies several lines on screen, and
    // heightAtLine measures what is actually drawn. defaultTextHeight() is the
    // editor's own line height, so the bounds follow the theme's font size
    // rather than a guessed pixel value.
    function fitToContent(editor) {
        var perLine = editor.defaultTextHeight() || 18;
        var content = editor.heightAtLine(editor.lastLine(), 'local') + perLine;
        var bounded = Math.min(Math.max(content, MIN_LINES * perLine), MAX_LINES * perLine);

        // The allowance is the scroller's own padding, which is outside the
        // text height and would otherwise cost the last line half its room.
        editor.setSize(null, Math.round(bounded) + 12);
    }

    function start(textarea) {
        var tabs = document.querySelectorAll('.tab-row .tab');
        var editor;
        var i;

        editor = window.CodeMirror.fromTextArea(textarea, {
            mode: defineMode(),
            theme: CONFIG.theme,
            defaulttheme: CONFIG.defaulttheme,
            darktheme: CONFIG.darktheme,
            lineNumbers: true,
            lineWrapping: true,
            matchBrackets: true,
            indentUnit: 4,
            tabSize: 4
        });

        fitToContent(editor);

        // The registry the manager's theme switch walks. Registering under the
        // field name is what lets it find the textarea again.
        window.myCodeMirrors = window.myCodeMirrors || {};
        window.myCodeMirrors[CONFIG.field] = editor;

        // fromTextArea writes back on form submit, but the document form is
        // also saved from script, so the textarea is kept current instead.
        editor.on('change', function () {
            editor.save();
            window.documentDirty = true;
        });

        // A tab that was hidden when the editor was created measures as zero
        // and draws blank until it is told to look again.
        for (i = 0; i < tabs.length; i += 1) {
            tabs[i].addEventListener('click', function () {
                editor.refresh();
            }, false);
        }

        // The manager theme makes .CodeMirror resize: vertical, so the wrapper
        // can be dragged taller. CodeMirror draws the lines its last
        // measurement said would fit, so without this the new space stays
        // blank. Instances the CodeMirror plugin creates are refreshed by that
        // plugin; this one is ours to look after.
        if (window.ResizeObserver) {
            new window.ResizeObserver(function () {
                editor.refresh();
            }).observe(editor.getWrapperElement());
        }

        editor.refresh();

        // Fonts and the manager's own stylesheets can still land after this,
        // and every one of them changes what a line measures.
        window.addEventListener('load', function () {
            editor.refresh();
        });
    }

    function init() {
        var textarea = field();

        if (!textarea) {
            return;
        }

        // Someone was here first: the CodeMirror plugin on a site that sets
        // which_editor to none, or a rich text editor that took the field over.
        if (window.myCodeMirrors && window.myCodeMirrors[CONFIG.field]) {
            return;
        }

        // An editor is about to claim the field. The picker is only rendered
        // when the CMS has editors registered for this document, so its
        // presence - not the which_editor setting, which names TinyMCE on every
        // stock install whether or not it was ever installed - is what says a
        // rich text editor is really in play.
        var picker = document.getElementById('which_editor');
        if (picker && picker.value && picker.value !== 'none') {
            return;
        }

        if (textarea.offsetParent === null) {
            return;
        }

        load(function () {
            if (window.CodeMirror && !(window.myCodeMirrors || {})[CONFIG.field]) {
                start(textarea);
            }
        });
    }

    // Deferred on purpose. This script is printed by an event whose output
    // comes before the CodeMirror plugin's, so at parse time there is no way to
    // tell whether that plugin is about to claim the same field. By
    // DOMContentLoaded its inline script has run and the question is settled.
    ready(init);
}());
JS;
    }
}
