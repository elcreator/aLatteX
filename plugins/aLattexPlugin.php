<?php

use Elcreator\aLatteX\LattexEngine;

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

    // A document whose template alias resolves to a file under /views/ has
    // already been rendered by that file's engine - Latte's own .latte files
    // included - and the CMS skips its parser for it. documentContent is
    // finished HTML at this point, not template code, and running it through
    // Latte again is a second pass over somebody else's output: a no-op at
    // best, and at worst it aborts on the first brace of an inline stylesheet
    // and logs an error on every request.
    $renderedFromView = property_exists($evo, 'documentTemplateView')
        ? (string) $evo->documentTemplateView
        : '';
    if ($renderedFromView !== '') {
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
        $evo->logEvent(
            0,
            3,
            'aLatteX template error: ' . $e->getMessage()
                . '<br><pre>' . htmlspecialchars($e->getTraceAsString()) . '</pre>',
            'aLatteX'
        );
    }
});

// ---------------------------------------------------------------------------
// Admin panel: inject aLatteX option into the chunk_processor radio group
// ---------------------------------------------------------------------------

/**
 * OnManagerMainFrameHeaderHTMLBlock fires on every manager page render.
 * We return a small <script> only when on the system-settings page (action 17).
 * The script adds an "aLatteX" radio button after "DLTemplate".
 */
Event::listen('evolution.OnManagerMainFrameHeaderHTMLBlock', function (): string {
    $evo = evo();

    // Only act on the system-settings page (action 17 = "Editing settings")
    $action = (string) ($_GET['a'] ?? $_POST['a'] ?? '');
    if ($action !== '17') {
        return '';
    }

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

        // Re-attach the CMS change handler (defined in system_settings.blade.php)
        newInput.addEventListener('change', function () {
            if (typeof setChangesChunkProcessor === 'function') {
                setChangesChunkProcessor(this);
            }
        });

        // The CMS runs setChangesChunkProcessor() once while parsing the page,
        // before this option exists. With aLatteX stored, none of the options it
        // can see is checked, so that first call decided the state of
        // enable_filter / enable_at_syntax from nothing. Now that the checked
        // option is in the DOM, let it decide again.
        if (typeof setChangesChunkProcessor === 'function') {
            try {
                setChangesChunkProcessor(isCurrent ? newInput : undefined);
            } catch (e) {
                // An older manager throws here when no option is checked at all.
            }
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', injectALatteXOption);
    } else {
        injectALatteXOption();
    }
}());
</script>
HTML;
});
