<?php

use Elcreator\aLatteX\LattexEngine;
use Elcreator\aLatteX\ManagerEditor;
use Elcreator\aLatteX\TemplateEditor;

// ---------------------------------------------------------------------------
// Front-end: process templates through Latte when aLatteX is selected
// ---------------------------------------------------------------------------

/**
 * OnLoadWebDocument fires after the template content has been loaded from DB
 * into evo()->documentContent, but before EVO's own parseDocumentSource() runs.
 *
 * We intercept here, process through Latte, and put the result back. EVO then
 * parses any remaining {{chunk}}, [[snippet]], [*tv*] tags normally.
 */
Event::listen('evolution.OnLoadWebDocument', function (): void {
    $evo = evo();

    if ($evo->getConfig('chunk_processor') !== 'aLatteX') {
        return;
    }

    // A document whose template resolves to a file under /views/ has already
    // been rendered by that file's engine - Latte's own .latte files included.
    // documentContent is finished HTML by now, not template code, so Latte must
    // not run again: a second pass over somebody else's output is a no-op at
    // best, and at worst it aborts on the first brace of an inline stylesheet
    // and logs an error on every request.
    //
    // What that file *does* still need is the rest of the pipeline, which the
    // core skips for it: parseDocumentSource(), the [!…!] pass,
    // cleanUpMODXTags() and rewriteUrls() - so every EVO tag in it would reach
    // the page as text. For aLatteX that is the wrong trade: where a template
    // lives is a version-control decision, not a syntax one, and the same
    // template must mean the same thing in the database and in a file.
    //
    // finishViewRender() asks for those passes here. It is scoped to .latte
    // files: Blade's identical behaviour is Blade's to change, not this
    // plugin's.
    $renderedFromView = property_exists($evo, 'documentTemplateView')
        ? (string) $evo->documentTemplateView
        : '';
    if ($renderedFromView !== '') {
        alattexFinishViewRender($evo);

        return;
    }

    $content = $evo->documentContent;

    if (empty($content)) {
        return;
    }

    try {
        /** @var LattexEngine $engine */
        $engine = app(LattexEngine::class);

        $evo->documentContent = $engine->render(
            $content,
            $evo->documentObject ?? []
        );
    } catch (\Throwable $e) {
        // Which template failed is the first thing anyone reading this log
        // wants, and Latte knows it: aLatteX renders under a name - see
        // SourceLoader - and a compile or sandbox error carries that name.
        // Latte folds it into the message itself only when it is a real file
        // path, which the name of a template held in the database is not.
        $source = property_exists($e, 'sourceName')
            && is_string($e->sourceName)
            && !@is_file($e->sourceName)
                ? $e->sourceName . ': '
                : '';

        $evo->logEvent(
            0,
            3,
            'aLatteX template error: ' . $source . $e->getMessage()
                . '<br><pre>' . htmlspecialchars($e->getTraceAsString()) . '</pre>',
            'aLatteX'
        );
    }
});

// ---------------------------------------------------------------------------
// Admin panel: inject aLatteX option into the chunk_processor radio group
// ---------------------------------------------------------------------------

/**
 * OnSiteSettingsRender fires while the system-settings page is being built and
 * its return value is printed inside the Site tab, below the fields - after the
 * chunk_processor radios and before the page's own <script>. Both halves of
 * that matter:
 *
 *   - the radios already exist, so the option can be added synchronously
 *     rather than waiting for DOMContentLoaded, and
 *   - the CMS's setChangesChunkProcessor() has not run yet.
 *
 * That second point is the whole reason this is not in the manager header.
 * From the header the script can only run at DOMContentLoaded, which is after
 * the CMS's own inline call - and with aLatteX stored, none of the two options
 * the CMS renders is checked, so its
 *
 *     item = item || document.querySelector('[name="chunk_processor"]:checked')
 *
 * yields null and the next line throws "Cannot read properties of null
 * (reading 'checked')" on every visit to the settings page. Injecting the
 * checked option before that call runs means there is something to find.
 */
Event::listen('evolution.OnSiteSettingsRender', function (): string {
    $evo = evo();

    // json_encode, not htmlspecialchars: this lands in a JavaScript string
    // literal, not in markup.
    $currentValue = json_encode(
        (string) $evo->getConfig('chunk_processor'),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );

    return <<<HTML
<script>
(function () {
    'use strict';

    function injectALatteXOption() {
        // Bail if option already present (idempotent)
        if (document.querySelector('input[name="chunk_processor"][value="aLatteX"]')) {
            return;
        }

        var radios = document.querySelectorAll('input[name="chunk_processor"]');
        if (!radios.length) {
            return; // Not on system settings page or DOM not yet ready
        }

        // manager::form.radio wraps every option in its own <div class="radio">
        // holding a <label>. Cloning the label alone would drop aLatteX into
        // the previous option's row, sharing its line; the row is the wrapper.
        var lastRadio = radios[radios.length - 1];
        var wrapper   = lastRadio.closest('.radio') || lastRadio.closest('label') || lastRadio.parentElement;
        if (!wrapper || !wrapper.parentNode) {
            return;
        }

        var newWrapper = wrapper.cloneNode(true);
        var newInput   = newWrapper.querySelector('input[type="radio"]');
        if (!newInput) {
            return;
        }

        // Configure the new radio. The clone carries whatever checked attribute
        // the cloned option was rendered with, so clear it as well as the
        // property - otherwise two options claim to be checked in the markup.
        var isCurrent = ({$currentValue} === 'aLatteX');

        newInput.value = 'aLatteX';
        newInput.id    = 'chunk_processor_alattex';
        if (isCurrent) {
            newInput.setAttribute('checked', 'checked');
        } else {
            newInput.removeAttribute('checked');
        }
        newInput.checked = isCurrent;

        // The visible text is a bare text node next to the input, inside the
        // label - not a span, and not a child of the row.
        var newLabel = newWrapper.querySelector('label') || newWrapper;
        var textNode = newLabel.querySelector('span.radio-label, span, div.label-text');
        if (textNode) {
            textNode.textContent = 'aLatteX';
        } else {
            var replaced = false;
            for (var i = 0; i < newLabel.childNodes.length; i++) {
                var node = newLabel.childNodes[i];
                if (node.nodeType === Node.TEXT_NODE && node.textContent.trim()) {
                    node.textContent = ' aLatteX';
                    replaced = true;
                    break;
                }
            }
            if (!replaced) {
                newLabel.appendChild(document.createTextNode(' aLatteX'));
            }
        }

        // Insert as its own row, right after the last existing one.
        wrapper.parentNode.insertBefore(newWrapper, wrapper.nextSibling);

        // Normally this script runs before system_settings.blade.php's, which
        // then binds its change handler to every chunk_processor radio, this
        // one included, and calls setChangesChunkProcessor() itself. Only if
        // that has already happened - a reordered or cached page - is there
        // anything left to do here.
        if (typeof setChangesChunkProcessor === 'function') {
            newInput.addEventListener('change', function () {
                setChangesChunkProcessor(this);
            });
            try {
                setChangesChunkProcessor(isCurrent ? newInput : undefined);
            } catch (e) {
                // An older manager throws here when no option is checked at all.
            }
        }
    }

    // The radios are above this script in the document, so there is no reason
    // to wait - and every reason not to: the CMS reads the checked option
    // before DOMContentLoaded. The listener is the fallback for a manager
    // theme that prints the tab events somewhere else.
    injectALatteXOption();
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', injectALatteXOption);
    }
}());
</script>
HTML;
});

// ---------------------------------------------------------------------------
// Admin panel: highlight the Resource content field
// ---------------------------------------------------------------------------

/**
 * OnDocFormRender fires while the document form is being written, and its
 * return value is printed inside the form, below the content textarea.
 *
 * Evolution CMS leaves that one field unhighlighted while templates, chunks and
 * snippets all get CodeMirror - see ManagerEditor for why. With aLatteX as the
 * chunk processor the field holds template source, so it gets the same editor,
 * with a mode that knows Latte tags as well as EVO ones.
 */
Event::listen('evolution.OnDocFormRender', function (): string {
    return ManagerEditor::documentEditorScript();
});

// ---------------------------------------------------------------------------
// Admin panel: teach the template editor Latte
// ---------------------------------------------------------------------------

/**
 * OnTempFormRender fires while the template form is being built, and its return
 * value is printed at the end of the form - alongside the CodeMirror plugin's
 * own output, which is printed by the same event.
 *
 * The template is where Latte is actually live, and the editor there knows only
 * EVO tags. TemplateEditor layers Latte on top of the mode the CMS's plugin
 * already built, and hangs the tags, filters and functions the engine reports -
 * evoChunk() and friends among them - on the same completion dropdown the
 * manager already uses for chunk and snippet names.
 */
Event::listen('evolution.OnTempFormRender', function (): string {
    return TemplateEditor::templateEditorScript();
});

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Is the EVO pass wanted over what a .latte view file produced?
 *
 * On by default: the point of the pass is that the same template means the same
 * thing whether it is kept in the database or in a file, and a site that has
 * moved a template into views/ has not asked for its tags to stop working.
 *
 * A site whose .latte files are pure Latte - no EVO tags anywhere, output that
 * happens to contain `[[`, `{{` or `[*` as literal text - sets
 * `alattex.evo_tags` to false and gets the core's own behaviour back: the file's
 * output is final and nothing runs a second dialect over it.
 */
function alattexEvoTagsEnabled(): bool
{
    if (!function_exists('config')) {
        return true;
    }

    return (bool) config('alattex.evo_tags', true);
}

/**
 * Ask for the parser passes the core skips for a view-rendered document.
 *
 * The core takes a different branch for a document whose template resolves to a
 * file, and that branch omits four things the database branch does:
 *
 *   1. parseDocumentSource();
 *   2. the [!non-cacheable!] pass, and
 *   3. cleanUpMODXTags() + rewriteUrls() - all three skipped because the file
 *      branch post-parses with $postParse off;
 *   4. the page cache, because that branch also forces cacheable = 0 and never
 *      registers postProcess(). That one cannot be restored from here.
 *
 * 1 to 3 are what make EVO tags work. Evolution CMS 3.5.8 turned that decision
 * into `Core::$runDocumentParser`, set from `!$template` and still open while
 * OnLoadWebDocument runs, precisely so a view engine whose syntax survives the
 * pass can ask for it instead of re-implementing it: setting the property back
 * to true hands the whole tail to the core, in the core's own order. That is
 * the path taken here whenever the core offers the property.
 *
 * Against an older core there is no property to set, so the same three passes
 * are run by hand, with the core's own methods. Either way, moving a template
 * from the database into views/<alias>.latte changes where it is stored and
 * nothing else.
 *
 * Only .latte views are touched. A .blade.php template reaches the same core
 * branch and behaves the same way; that is Blade's contract with the CMS and
 * this plugin has no business rewriting it.
 */
function alattexFinishViewRender(object $evo): void
{
    if (!alattexEvoTagsEnabled()) {
        return;
    }

    $viewPath = '';

    try {
        $viewPath = (string) app('TemplateProcessor')->getDocumentViewPath();
    } catch (\Throwable) {
        // No TemplateProcessor, no way to tell whose view this is: leave it be.
        return;
    }

    if (!str_ends_with(strtolower($viewPath), '.latte')) {
        return;
    }

    // Evolution CMS 3.5.8 and up. The core has not read the property yet - this
    // event fires while the decision is open - so nothing else is needed: the
    // parser pass, the [!…!] rewrite, cleanUpMODXTags() and rewriteUrls() all
    // follow it.
    if (property_exists($evo, 'runDocumentParser')) {
        $evo->runDocumentParser = true;

        return;
    }

    $content = (string) $evo->documentContent;

    if ($content === '') {
        return;
    }

    try {
        $content = $evo->parseDocumentSource($content);

        // outputContent() does this when it post-parses: a non-cacheable tag is
        // a cacheable one that was held back, so it is rewritten and parsed on
        // a second pass. A view-rendered document is never page-cached, so the
        // distinction is moot for it - but [!name!] still has to resolve.
        if (str_contains($content, '[!')) {
            $content = str_replace(['[!', '!]'], ['[[', ']]'], $content);
            $evo->minParserPasses = 2;
            $content = $evo->parseDocumentSource($content);
        }

        $content = $evo->cleanUpMODXTags($content);
        $content = $evo->rewriteUrls($content);

        $evo->documentContent = $content;
    } catch (\Throwable $e) {
        // The page already has its markup; losing the EVO pass leaves tags on
        // screen, which is bad, but taking the page down is worse.
        $evo->logEvent(
            0,
            3,
            'aLatteX could not finish the parser passes for ' . htmlspecialchars($viewPath)
                . ': ' . $e->getMessage(),
            'aLatteX'
        );
    }
}
