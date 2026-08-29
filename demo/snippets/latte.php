<?php
/**
 * aLatteXDemoLatte - runs a chunk (or an inline string) through aLatteX itself.
 *
 * This is the answer to "why are the Latte tags in my chunk printed
 * literally?". aLatteX processes the *template* and nothing else: by the time
 * Evolution CMS expands {{chunks}} and [[snippets]], Latte's pass is long
 * over. A chunk that is Latte source therefore needs a second, explicit pass -
 * which is all this snippet is.
 *
 * Parameters:
 *   &chunk `name`   chunk to render as Latte (takes precedence over &code)
 *   &code  `{$id}`  inline Latte source to render
 *   &vars  `{"a":1}` JSON object merged into the template variables
 *
 * The output still contains any EVO tags the chunk produced; Evolution CMS
 * picks those up on its next parse pass, exactly as with any snippet output.
 *
 * @var \EvolutionCMS\Core $modx
 * @var string|null $chunk
 * @var string|null $code
 * @var string|null $vars
 */

$source = isset($chunk) && $chunk !== ''
    ? (string) $modx->getChunk((string) $chunk)
    : (string) ($code ?? '');

if (trim($source) === '') {
    return '<p class="alx-empty">aLatteXDemoLatte needs &amp;chunk or &amp;code.</p>';
}

$extra = [];
if (isset($vars) && trim((string) $vars) !== '') {
    $decoded = json_decode((string) $vars, true);
    if (is_array($decoded)) {
        $extra = $decoded;
    }
}

// Safe to reuse the container's engine: the template pass finished before
// parseDocumentSource() started expanding snippets, so nothing is mid-render.
$engine = app(\Elcreator\aLatteX\LattexEngine::class);

try {
    return $engine->render($source, array_merge((array) $modx->documentObject, $extra));
} catch (\Throwable $e) {
    return '<pre class="alx-error">aLatteXDemoLatte: '
        . htmlspecialchars($e->getMessage(), ENT_QUOTES)
        . '</pre>';
}
