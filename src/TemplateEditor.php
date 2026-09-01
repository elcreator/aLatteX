<?php

namespace Elcreator\aLatteX;

/**
 * Latte syntax highlighting and completion in the manager's template editor.
 *
 * The template page already has an editor: the CodeMirror plugin from the base
 * install set builds one over the `post` textarea and gives it an overlay mode -
 * htmlmixed with a layer that colours the eight EVO tag forms, named
 * `Evo-htmlmixed` on a current core and `MODx-htmlmixed` on one from before the
 * rename. Overlays stack, so Latte is added by layering a second overlay on top
 * of whichever is there; no core file is touched and nothing is rebuilt. Where the CMS's
 * own editor is not there - the plugin disabled, its assets removed - this
 * stands down rather than building a replacement, because the template field is
 * the CMS's to furnish and it normally does.
 *
 * One mode, for both places a template can live. That was not always so: a
 * template held in views/<alias>.latte used to get Latte colouring without the
 * EVO layer, because the core skips its parser for a view-rendered document and
 * an EVO tag in such a file reached the page as text. aLatteX now runs those
 * passes itself - see alattexFinishViewRender() in plugins/aLattexPlugin.php -
 * so a file means exactly what the same code means in the database, and the
 * editor says so by colouring both syntaxes either way.
 *
 * The completion vocabulary is asked of the engine (LattexEngine::vocabulary()),
 * not written out here, so it is the truth for the Latte the site has and
 * includes whatever an extension contributes - the evo* functions among them.
 * It is offered through CodeMirror's show-hint addon, the same dropdown the
 * CMS's own element-name-helper.js uses for {{chunk}} and [[snippet]] names,
 * and the two do not overlap: that one answers `{{`, `[[`, `[!` and `@`, this
 * one answers a single `{`, a `|` and an `n:`.
 *
 * ---
 *
 * One thing here is a repair rather than a feature. manager/views/page/
 * template.blade.php ends its DOMContentLoaded handler with
 *
 *     var modes = {'php': 'application/x-httpd-php', 'css': 'text/css'};
 *     ...
 *     window.myCodeMirrors['post'].setOption('mode', modes[ext] || 'htmlmixed');
 *
 * - names without the overlay prefix. The CodeMirror plugin's script is inline
 * in the body and has already built the editor by then, so on every template
 * page of a site that has any template-file engine registered, the EVO tag
 * highlighting is switched off a moment after the page loads. aLatteX registers
 * such an engine, so it cannot leave this alone: the guard installed on
 * setOption() below puts the right mode back whenever something asks for the
 * bare one. The proper fix belongs in the CMS, and is prepared as
 * patches/evo-template-highlighting.patch - which also renames the overlay mode
 * from MODx-* to Evo-*, the reason this file watches for both names.
 */
class TemplateEditor
{
    /** Where the CodeMirror plugin keeps the library, relative to the site root. */
    private const ASSET_BASE = 'assets/plugins/codemirror/';

    /** The textarea the template form puts the code in, and the key the plugin registers it under. */
    private const FIELD = 'post';

    /**
     * Tags Latte reports but aLatteX cannot run, so they are not offered.
     *
     * `{sandbox}` needs a security Policy aLatteX deliberately does not supply.
     * `{php}` is in the list for a different reason: Latte 3 keeps the name only
     * to tell you to write `{do}` instead. File and chunk references are now
     * resolved by SourceLoader, so layout/extends/import/embed are offered.
     *
     * Filters are not filtered the same way: `|webalize` and `|localDate` need
     * a package and an extension that a site can perfectly well install, which
     * makes them a runtime question rather than a structural one.
     */
    private const UNSUPPORTED_TAGS = ['sandbox', 'php'];

    /**
     * The document fields aLatteX spreads as top-level Latte variables.
     *
     * The columns of site_content, which is what Core::getDocumentObject() hands
     * over and DocumentObject::flatten() passes through. Template variables are
     * per-site and are not listed; they are completed by nothing and typed by
     * hand, the same as in a chunk.
     */
    private const DOCUMENT_FIELDS = [
        'id', 'type', 'contentType', 'pagetitle', 'longtitle', 'description', 'alias',
        'link_attributes', 'published', 'pub_date', 'unpub_date', 'parent', 'isfolder',
        'introtext', 'content', 'richtext', 'template', 'menuindex', 'searchable',
        'cacheable', 'createdby', 'createdon', 'editedby', 'editedon', 'deleted',
        'deletedon', 'deletedby', 'publishedon', 'publishedby', 'menutitle',
        'hide_from_tree', 'privateweb', 'privatemgr', 'content_dispo', 'hidemenu',
        'alias_visible',
    ];

    /**
     * The <script> for the template form, or '' when it should not be there.
     *
     * Returns a string in both cases: invokeEvent() collects every non-null
     * return value, so opting out is an empty string and never null.
     */
    public static function templateEditorScript(): string
    {
        $vocabulary = ['tags' => [], 'filters' => [], 'functions' => []];

        if (class_exists(\Latte\Engine::class)) {
            // The container's engine, so the vocabulary is the one the site
            // renders with - extensions and all - and building it costs nothing
            // beyond what the request already paid for.
            $engine = function_exists('app') ? app(LattexEngine::class) : new LattexEngine();
            $vocabulary = $engine->vocabulary();
        }

        return self::scriptFor(
            function_exists('evo') ? evo() : null,
            defined('EVO_BASE_PATH') ? EVO_BASE_PATH : null,
            defined('EVO_SITE_URL') ? EVO_SITE_URL : '/',
            $vocabulary
        );
    }

    /**
     * The same decision, with the CMS and the vocabulary handed in rather than
     * reached for - which is what lets the conditions below be tested without a
     * booted CMS.
     *
     * @param array{tags?: list<string>, filters?: list<string>, functions?: list<string>} $vocabulary
     */
    public static function scriptFor(
        ?object $evo,
        ?string $basePath,
        string $siteUrl,
        array $vocabulary = []
    ): string {
        if ($evo === null || $basePath === null) {
            return '';
        }

        // Tied to the plugin being the active chunk processor, like everything
        // else it does: a site that switched aLatteX off gets its manager back
        // exactly as the CMS ships it.
        if ($evo->getConfig('chunk_processor') !== 'aLatteX') {
            return '';
        }

        // The library is the CMS's own copy, loaded by the CodeMirror plugin.
        // With it gone there is no editor to decorate and nothing to do.
        if (!is_dir($basePath . self::ASSET_BASE . 'cm')) {
            return '';
        }

        $config = [
            'field' => self::FIELD,
            'tags' => self::supportedTags($vocabulary['tags'] ?? []),
            'filters' => array_values($vocabulary['filters'] ?? []),
            'functions' => array_values($vocabulary['functions'] ?? []),
            'variables' => array_merge(['evo', 'documentObject'], self::DOCUMENT_FIELDS),
            // The extension aLatteX declares for a template kept in a file. The
            // editor shows Latte without the EVO overlay while that pair of
            // selectors points at one.
            'fileExtension' => 'latte',
        ];

        $script = str_replace(
            '__ALATTEX_TEMPLATE_EDITOR_CONFIG__',
            (string) json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            self::js()
        );

        return "<script>\n" . $script . "\n</script>\n";
    }

    /**
     * @param  list<string> $tags
     * @return list<string>
     */
    private static function supportedTags(array $tags): array
    {
        return array_values(array_diff($tags, self::UNSUPPORTED_TAGS));
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

    var CONFIG = __ALATTEX_TEMPLATE_EDITOR_CONFIG__;

    // Latte on top of the CMS's EVO overlay. One mode: see the class docblock.
    var MODE_DB = 'alattex-template';

    // What the CMS's own CodeMirror plugin opens the template editor with, and
    // what manager/views/page/template.blade.php resets it to. Both are watched
    // for: the first is the mode we replace, the second is the mode we put back.
    //
    // Two names, because the CMS is renaming this one. It has been
    // 'MODx-htmlmixed' since the plugin was ported from MODX Evolution, and the
    // Evo-prefixed name is what it is becoming; a plugin has no business
    // requiring the core to be on either side of that, so it takes whichever is
    // defined and guards both.
    var CORE_MODES = ['Evo-htmlmixed', 'MODx-htmlmixed'];
    var PLAIN_MODE = 'htmlmixed';

    // n:attributes worth completing. Latte reports the handful that exist only
    // in that form as tags of their own; the rest are block tags used as an
    // attribute, and these are the ones that make sense on a CMS template.
    var N_BLOCK_TAGS = ['if', 'ifset', 'ifcontent', 'foreach', 'for', 'while',
        'first', 'last', 'sep', 'block'];
    var N_PREFIXED = ['inner-if', 'inner-foreach', 'tag-if'];

    // Tags with a closing form. Latte reports its tags as one flat list, and a
    // dropdown that answers `{/` with {/breakIf} is worse than no dropdown, so
    // the paired ones are named. Anything here that this Latte does not have is
    // dropped when the list is built.
    var PAIRED_TAGS = ['block', 'capture', 'define', 'first', 'for', 'foreach', 'if',
        'ifchanged', 'ifset', 'iterateWhile', 'last', 'sep', 'spaceless', 'switch',
        'sync', 'try', 'while'];

    function ready(fn) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fn);
        } else {
            fn();
        }
    }

    function editor() {
        return (window.myCodeMirrors || {})[CONFIG.field] || null;
    }

    // ---------------------------------------------------------------- mode ---

    // The Latte overlay. It runs on top of a base mode and claims only what Latte
    // would claim - which is the point of the awkward cases below rather than a
    // simplification of them:
    //
    //   {{  is an Evolution CMS chunk. It is left to the layer underneath, so
    //       returning null over it is what lets the EVO colour show through.
    //   {   followed by whitespace is not a tag at all; Latte prints it. Every
    //       other character opens one - including in tight CSS, where {color:red}
    //       really is a Latte tag and really does fail to compile. Colouring it
    //       says so before the page does.
    //   n:  is an attribute only where an attribute can start, so it is taken
    //       only after whitespace or at the start of a line.
    function overlay(vocabulary) {
        return {
            // Two things have to survive the end of a line. A tag body, because
            // {var $rows = [ ... ]} is routinely written over four of them; and
            // a comment, for the same reason. CodeMirror carries an overlay's
            // state when the overlay declares one, so this is all it takes.
            startState: function () {
                return { open: null, expr: false, str: null };
            },

            copyState: function (state) {
                return { open: state.open, expr: state.expr, str: state.str };
            },

            token: function (stream, state) {
                var name;

                // Inside a {* comment *} that began on an earlier line.
                if (state.open) {
                    return resume(stream, state);
                }

                // Inside a tag body: one token at a time, so the expression
                // reads as an expression rather than as one magenta smear.
                if (state.expr) {
                    return expression(stream, state);
                }

                if (stream.match('{{', false)) {
                    stream.next();
                    stream.next();
                    return null;
                }

                if (stream.match('{*')) {
                    return consumeTo(stream, state, '*}', 'latteComment');
                }

                if (atAttribute(stream) && stream.match(/^n:[a-zA-Z][-a-zA-Z0-9:]*/)) {
                    return 'latteAttr';
                }

                // A printed expression: {$var}, {=$a + $b}. Only the brace is
                // taken here; what follows is an expression like any other.
                if (stream.match(/^\{[$=]/, false)) {
                    stream.next();
                    state.expr = true;
                    return 'latteTag';
                }

                // {evoChunk('hero')} is a printed function call, not a tag
                // named evoChunk. Told apart by the bracket that follows the
                // name, and by the name not being a tag of its own - {if(...)}
                // is legal Latte and is still the if tag.
                name = stream.match(/^\{([a-zA-Z_][a-zA-Z0-9_]*)\(/, false);
                if (name && !vocabulary.tags[name[1]]) {
                    stream.next();
                    state.expr = true;
                    return 'latteTag';
                }

                // A tag: the name is coloured by whether this Latte has it, and
                // the rest of the body goes to expression() below.
                if (stream.match(/^\{\/?[^\s}]/, false)) {
                    stream.next();
                    stream.eat('/');
                    state.expr = true;
                    name = stream.match(/^[a-zA-Z][a-zA-Z0-9]*/);

                    if (!name) {
                        return 'latteTag';
                    }

                    return vocabulary.tags[name[0]] ? 'latteKeyword' : 'latteTag';
                }

                while (stream.next() != null) {
                    if (opensLatte(stream)) {
                        break;
                    }
                }

                return null;
            }
        };
    }

    // Words that are PHP-ish syntax rather than a value, and the three literals
    // every theme already colours apart from them.
    var EXPR_KEYWORDS = {
        'and': 1, 'or': 1, 'xor': 1, 'not': 1, 'as': 1, 'in': 1, 'to': 1, 'by': 1,
        'instanceof': 1, 'clone': 1, 'new': 1, 'fn': 1, 'use': 1, 'else': 1,
        'isset': 1, 'empty': 1, 'default': 1
    };
    var EXPR_ATOMS = { 'true': 1, 'false': 1, 'null': 1 };

    // One token of a tag body.
    //
    // The names returned are CodeMirror's own - string, number, keyword, atom,
    // operator, bracket, variable - because both stylesheets the CMS ships
    // already colour them, in light and in dark. So an array written in a Latte
    // tag looks like the same array written in the snippet editor, and only the
    // Latte structure around it stays in this plugin's own colour.
    function expression(stream, state) {
        var word;
        var called;
        var at;

        // A quoted string that ran past the end of a line.
        if (state.str) {
            return string(stream, state);
        }

        if (stream.eatSpace()) {
            return null;
        }

        if (stream.eat('}')) {
            state.expr = false;
            return 'latteTag';
        }

        if (stream.peek() === '"' || stream.peek() === "'") {
            state.str = stream.next();
            return string(stream, state);
        }

        if (stream.match(/^\$[a-zA-Z_][a-zA-Z0-9_]*/)) {
            return 'latteVar';
        }

        if (stream.match(/^\d[\d_]*(\.[\d_]+)?([eE][-+]?\d+)?/)) {
            return 'number';
        }

        // Before the filter rule, so that || is or and not a filter named '|'.
        if (stream.match(/^(=>|\?->|->|\?\?|===|!==|==|!=|<=>|<=|>=|&&|\|\||\+\+|--|[-+*\/%.!<>?:=&])/)) {
            return 'operator';
        }

        if (stream.match(/^\|[a-zA-Z][a-zA-Z0-9]*/)) {
            return 'latteFilter';
        }

        at = stream.pos;

        if (stream.match(/^[a-zA-Z_][a-zA-Z0-9_]*/)) {
            word = stream.current().toLowerCase();
            called = stream.peek() === '(';

            // What follows an arrow belongs to the object, not to the template:
            // $iterator->counter is a property, $obj->find() a method.
            if (/(->|\?->)$/.test(stream.string.slice(0, at))) {
                return called ? 'latteFunction' : 'property';
            }

            if (EXPR_ATOMS[word]) {
                return 'atom';
            }

            if (EXPR_KEYWORDS[word]) {
                return 'keyword';
            }

            // A name followed by ( is being called - evoChunk(), count(), a
            // Latte function. Worth telling apart from a bare word.
            return called ? 'latteFunction' : 'variable';
        }

        if (stream.match(/^[\[\](),;]/)) {
            return 'bracket';
        }

        stream.next();
        return null;
    }

    // Runs to the closing quote, honouring backslash escapes. An unterminated
    // string keeps its colour to the end of the line and picks it up on the
    // next, the same as an unterminated tag body.
    function string(stream, state) {
        var ch;

        while ((ch = stream.next()) != null) {
            if (ch === '\\') {
                stream.next();
                continue;
            }

            if (ch === state.str) {
                state.str = null;
                break;
            }
        }

        return 'string';
    }

    // Run to the closing delimiter, or to the end of the line - and in that case
    // record what is still open so the next line picks the colour back up.
    function consumeTo(stream, state, closer, style) {
        if (stream.skipTo(closer)) {
            stream.match(closer);
        } else {
            stream.skipToEnd();
            state.open = { closer: closer, style: style };
        }

        return style;
    }

    function resume(stream, state) {
        var open = state.open;

        if (stream.skipTo(open.closer)) {
            stream.match(open.closer);
            state.open = null;
        } else {
            stream.skipToEnd();
        }

        return open.style;
    }
    function atAttribute(stream) {
        return stream.sol() || /\s/.test(stream.string.charAt(stream.pos - 1));
    }

    function opensLatte(stream) {
        if (stream.match('{{', false)) {
            return false;
        }

        if (stream.match(/^\{[*$=]/, false) || stream.match(/^\{\/?[^\s}]/, false)) {
            return true;
        }

        return atAttribute(stream) && stream.match(/^n:[a-zA-Z]/, false);
    }

    // Whichever of the EVO overlay's two names this core defines, or null when
    // the CodeMirror plugin never ran on this page.
    function coreMode() {
        var i;

        for (i = 0; i < CORE_MODES.length; i += 1) {
            if (window.CodeMirror.modes[CORE_MODES[i]]) {
                return CORE_MODES[i];
            }
        }

        return null;
    }

    // A mode name that means "the editor was reset to the CMS's own default" -
    // either overlay name, or the bare one template.blade.php falls back to.
    function isBaseMode(value) {
        return value === PLAIN_MODE || CORE_MODES.indexOf(value) !== -1;
    }

    // Returns the mode name to use, or null when the overlay cannot be built -
    // overlayMode lives in the addon bundle, and a site with an incomplete asset
    // tree should keep the editor it has rather than get an exception.
    function defineModes(vocabulary) {
        var CM = window.CodeMirror;

        if (!CM || typeof CM.overlayMode !== 'function') {
            return false;
        }

        if (CM.modes[MODE_DB]) {
            return true;
        }

        // The EVO layer only exists if the CodeMirror plugin defined it, which
        // it does for this page. Falling back to htmlmixed keeps the Latte
        // colouring on a page where it did not.
        var base = coreMode() || PLAIN_MODE;

        CM.defineMode(MODE_DB, function (config) {
            return CM.overlayMode(CM.getMode(config, base), overlay(vocabulary));
        });

        return true;
    }

    // The mode the editor should be in, from the pair of selectors the template
    // form uses to say where the code lives. Both places get the same mode: a
    // .latte file and a database record run the same two parsers. What the
    // selectors still decide is whether this is *our* code at all - a template
    // pinned to .php or .css is somebody else's, and returning null leaves the
    // mode the core chose for it.
    function wantedMode() {
        var source = document.getElementById('templatesource');
        var extension = document.getElementById('templatefileextension');

        if (!source || source.value !== 'file') {
            return MODE_DB;
        }

        return extension && extension.value === CONFIG.fileExtension ? MODE_DB : null;
    }

    // Puts the mode on, and keeps it on.
    //
    // template.blade.php resets the mode from its own DOMContentLoaded handler
    // and again on every change of the selectors, to a name with no overlay in
    // it. Rather than race that with timers, setOption is wrapped once: an
    // attempt to set the bare mode is answered with the one that belongs there,
    // and anything else - php, css, a real choice by a real caller - is passed
    // through untouched.
    function install(cm) {
        var wrapped = cm.setOption;

        if (cm.state.alattexModeGuard) {
            return;
        }

        cm.state.alattexModeGuard = true;

        cm.setOption = function (option, value) {
            if (option === 'mode' && isBaseMode(value)) {
                value = wantedMode() || value;
            }

            return wrapped.call(this, option, value);
        };

        apply(cm);
    }

    function apply(cm) {
        var mode = wantedMode();

        if (!mode) {
            return;
        }

        try {
            cm.setOption('mode', mode);
        } catch (e) {
            // An editor that will not take a mode is not worth breaking the
            // form over - the same judgement the core makes here.
        }
    }

    // --------------------------------------------------------------- hints ---

    // The completion contexts, in the order they are tried. Each returns the
    // typed prefix and the list to offer for it, or null.
    //
    // A single `{` is a Latte tag or a printed expression, so it offers both
    // tags and functions: {if …} and {evoChunk('name')} are typed the same way
    // up to the first letter. `{{` is not ours - that is a chunk, and
    // element-name-helper.js is already answering it.
    function context(line, vocabulary) {
        var match;

        match = /(^|[^{])\{(\/?)([a-zA-Z][a-zA-Z0-9]*|)$/.exec(line);
        if (match) {
            return {
                term: match[3],
                list: match[2] === '/'
                    ? items(vocabulary.closable, '', '}')
                    : items(vocabulary.tagList, '', '').concat(items(vocabulary.functions, '', '('))
            };
        }

        match = /\{\$([a-zA-Z_][a-zA-Z0-9_]*|)$/.exec(line);
        if (match) {
            return { term: match[1], list: items(vocabulary.variables, '', '') };
        }

        match = /\|([a-zA-Z][a-zA-Z0-9]*|)$/.exec(line);
        if (match) {
            return { term: match[1], list: items(vocabulary.filters, '', '') };
        }

        match = /(?:^|\s)n:([a-zA-Z][-a-zA-Z0-9:]*|)$/.exec(line);
        if (match) {
            return { term: match[1], list: items(vocabulary.attributes, '', '=""') };
        }

        return null;
    }

    function items(names, prefix, suffix) {
        var out = [];
        var i;

        for (i = 0; i < names.length; i += 1) {
            out.push({
                text: prefix + names[i] + suffix,
                displayText: prefix + names[i]
            });
        }

        return out;
    }

    function hint(cm, vocabulary) {
        var cursor = cm.getCursor();
        var found = context(cm.getLine(cursor.line).slice(0, cursor.ch), vocabulary);
        var list = [];
        var lower;
        var i;

        if (!found) {
            return null;
        }

        lower = found.term.toLowerCase();

        for (i = 0; i < found.list.length; i += 1) {
            if (!lower || found.list[i].displayText.toLowerCase().indexOf(lower) === 0) {
                list.push(found.list[i]);
            }
        }

        if (!list.length) {
            return null;
        }

        return {
            list: list,
            from: { line: cursor.line, ch: cursor.ch - found.term.length },
            to: cursor
        };
    }

    // Opened on typing, the way element-name-helper.js opens its own - and
    // yielding to it when it got there first, so the two dropdowns never fight
    // over one keystroke.
    function installHints(cm, vocabulary) {
        var CM = window.CodeMirror;

        if (!CM || !CM.showHint || cm.state.alattexHints) {
            return;
        }

        cm.state.alattexHints = true;

        cm.on('change', function (instance, change) {
            if (!change || change.origin === 'setValue' || instance.state.completionActive) {
                return;
            }

            if (!/[a-zA-Z0-9_{|:$]/.test(change.text.join(''))) {
                return;
            }

            instance.showHint({
                hint: function (target) {
                    return hint(target, vocabulary);
                },
                completeSingle: false
            });
        });
    }

    // --------------------------------------------------------------- style ---

    // The EVO tokens are coloured by the shipped stylesheets, and so are the
    // ordinary expression ones an expression is made of - string, number,
    // keyword, atom, operator, bracket, variable. Only what is specific to
    // Latte is defined here, and deliberately not in the colour of an EVO tag:
    // in a database template both syntaxes are on screen at once, and which
    // parser will read which is the one thing the author needs to see.
    //
    // The result is that a Latte tag reads in two registers - magenta for the
    // structure Latte owns, the editor's usual palette for the PHP-ish
    // expression inside it.
    function ensureStyles() {
        var style;

        if (document.getElementById('alattex-template-editor-style')) {
            return;
        }

        style = document.createElement('style');
        style.id = 'alattex-template-editor-style';
        style.appendChild(document.createTextNode(
            '.cm-latteKeyword{color:#8250df;font-weight:bold;}' +
            '.cm-latteTag{color:#8250df;}' +
            '.cm-latteVar{color:#0550ae;}' +
            '.cm-latteAttr{color:#8250df;font-style:italic;}' +
            '.cm-latteComment{color:#6e7781;font-style:italic;}' +
            '.cm-latteFilter{color:#0550ae;font-style:italic;}' +
            '.cm-latteFunction{color:#953800;}' +
            '.cm-s-one-dark .cm-latteKeyword{color:#c678dd;font-weight:bold;}' +
            '.cm-s-one-dark .cm-latteTag{color:#c678dd;}' +
            '.cm-s-one-dark .cm-latteVar{color:#61afef;}' +
            '.cm-s-one-dark .cm-latteAttr{color:#c678dd;font-style:italic;}' +
            '.cm-s-one-dark .cm-latteComment{color:#7f848e;font-style:italic;}' +
            '.cm-s-one-dark .cm-latteFilter{color:#56b6c2;font-style:italic;}' +
            '.cm-s-one-dark .cm-latteFunction{color:#e5c07b;}'
        ));
        document.head.appendChild(style);
    }

    // ---------------------------------------------------------------- init ---

    function vocabulary() {
        var lookup = {};
        var attributes = [];
        var closable = [];
        var i;

        for (i = 0; i < CONFIG.tags.length; i += 1) {
            lookup[CONFIG.tags[i]] = true;

            // The n:* entries Latte reports are attributes already, not tags to
            // be prefixed a second time.
            if (CONFIG.tags[i].indexOf('n:') === 0) {
                attributes.push(CONFIG.tags[i].slice(2));
            }
        }

        for (i = 0; i < PAIRED_TAGS.length; i += 1) {
            if (lookup[PAIRED_TAGS[i]]) {
                closable.push(PAIRED_TAGS[i]);
            }
        }

        return {
            tags: lookup,
            tagList: CONFIG.tags,
            closable: closable,
            filters: CONFIG.filters,
            functions: CONFIG.functions,
            variables: CONFIG.variables,
            attributes: attributes.concat(N_BLOCK_TAGS, N_PREFIXED).sort()
        };
    }

    function init() {
        var cm = editor();
        var words;

        // No editor on the field: the CodeMirror plugin is disabled or gone.
        // Furnishing the template field is the CMS's job and it normally does
        // it, so there is nothing to take over here.
        if (!cm) {
            return;
        }

        words = vocabulary();

        if (defineModes(words)) {
            ensureStyles();
            install(cm);
        }

        installHints(cm, words);
    }

    // Deferred, like ManagerEditor's: this script and the CodeMirror plugin's
    // are both printed by OnTempFormRender and the order between them is the
    // order of the listeners. By DOMContentLoaded the plugin's inline script has
    // run either way, so the editor is there to be found.
    //
    // The second pass is for template.blade.php, whose own DOMContentLoaded
    // handler may be registered before this one and would then reset the mode
    // after it was set. The guard on setOption covers that, and this covers the
    // guard not being installed yet.
    ready(function () {
        init();
        window.setTimeout(init, 0);
    });
}());
JS;
    }
}
