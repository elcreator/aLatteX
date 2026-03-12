<?php

use EvolutionCMS\aLatteX\LattexEngine;

// ---------------------------------------------------------------------------
// Front-end: process templates through Latte when aLatteX is selected
// ---------------------------------------------------------------------------

/**
 * OnLoadWebDocument fires after the template content has been loaded from DB
 * into $modx->documentContent, but before EVO's own parseDocumentSource() runs.
 *
 * We intercept here, process through Latte, and put the result back. EVO then
 * parses any remaining {{chunk}}, [[snippet]], [*tv*] tags normally.
 */
Event::listen('evolution.OnLoadWebDocument', function (): void {
    $modx = evo();

    if ($modx->getConfig('chunk_processor') !== 'aLatteX') {
        return;
    }

    $content = $modx->documentContent;

    if (empty($content)) {
        return;
    }

    try {
        /** @var LattexEngine $engine */
        $engine = app(LattexEngine::class);

        $modx->documentContent = $engine->render(
            $content,
            $modx->documentObject ?? []
        );
    } catch (\Throwable $e) {
        $modx->logEvent(
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
    $modx = evo();

    // Only act on the system-settings page (action 17 = "Editing settings")
    $action = (string) ($_GET['a'] ?? $_POST['a'] ?? '');
    if ($action !== '17') {
        return '';
    }

    $currentValue = htmlspecialchars(
        (string) $modx->getConfig('chunk_processor'),
        ENT_QUOTES
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

        // Find the last radio's wrapper element to clone structure
        var lastRadio = radios[radios.length - 1];
        var wrapper   = lastRadio.closest('label') || lastRadio.parentElement;
        if (!wrapper) {
            return;
        }

        var newWrapper = wrapper.cloneNode(true);
        var newInput   = newWrapper.querySelector('input[type="radio"]');
        if (!newInput) {
            return;
        }

        // Configure the new radio
        newInput.value   = 'aLatteX';
        newInput.id      = 'chunk_processor_alattex';
        newInput.checked = ('{$currentValue}' === 'aLatteX');

        // Update visible label text — try common patterns
        var textNode = newWrapper.querySelector('span.radio-label, span, div.label-text');
        if (textNode) {
            textNode.textContent = 'aLatteX';
        } else {
            // Fallback: walk child nodes looking for a text node
            for (var i = 0; i < newWrapper.childNodes.length; i++) {
                var node = newWrapper.childNodes[i];
                if (node.nodeType === Node.TEXT_NODE && node.textContent.trim()) {
                    node.textContent = ' aLatteX';
                    break;
                }
            }
        }

        // Update <label for="..."> if present
        var labelFor = newWrapper.querySelector('label[for]') || (newWrapper.tagName === 'LABEL' ? newWrapper : null);
        if (labelFor) {
            labelFor.setAttribute('for', 'chunk_processor_alattex');
        }

        // Insert right after the last existing radio wrapper
        wrapper.parentNode.insertBefore(newWrapper, wrapper.nextSibling);

        // Re-attach the CMS change handler (defined in system_settings.blade.php)
        newInput.addEventListener('change', function () {
            if (typeof setChangesChunkProcessor === 'function') {
                setChangesChunkProcessor(this);
            }
        });
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
