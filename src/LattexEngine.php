<?php

namespace Elcreator\aLatteX;

use Latte\Engine;
use Latte\Loaders\StringLoader;

/**
 * Wraps the Latte 3.x engine for use as an Evolution CMS template processor.
 *
 * Rendering pipeline:
 *  1. EvoSyntaxBridge::protect()  – replace EVO tags with safe tokens
 *  2. Latte::renderToString()     – process Latte syntax
 *  3. EvoSyntaxBridge::restore()  – put EVO tags back
 *  4. EVO's parseDocumentSource() – EVO handles its own tags normally
 *
 * Template variables available in Latte:
 *  $x            – the Evolution CMS core object
 *  $documentObject  – associative array of the current document (all fields + TVs)
 *  All document fields are also spread as top-level variables so authors can write
 *  {$pagetitle}, {$alias}, {$longtitle}, {$content}, etc. directly.
 *
 * Native Latte helper functions (from EvoExtension):
 *  {evoChunk('name')}
 *  {evoSnippet('name', ['param' => 'value'])}
 *  {evoUncachedSnippet('name', ['param' => 'value'])}
 *  {evoTv('name')}
 *  {evoSetting('name')}
 *  {evoPlaceholder('name')}
 */
class LattexEngine
{
    private Engine $latte;
    private EvoSyntaxBridge $bridge;

    public function __construct()
    {
        $this->bridge = new EvoSyntaxBridge();
        $this->latte  = new Engine();

        $cacheDir = $this->resolveCacheDir();
        $this->latte->setTempDirectory($cacheDir);

        $this->latte->addExtension(new EvoExtension());

        // Use StringLoader with null so the template content itself is the unique cache key.
        // Latte hashes the unique ID internally when naming compiled cache files.
        $this->latte->setLoader(new StringLoader());
    }

    /**
     * Render a DB template string through Latte and return the result.
     * EVO syntax in the template is preserved and returned verbatim so that
     * Evolution CMS's own parseDocumentSource() can handle it afterwards.
     *
     * @param  string               $templateContent  Raw template code from site_templates
     * @param  array<string, mixed> $documentObject   Current document fields + TVs
     * @return string
     */
    public function render(string $templateContent, array $documentObject = []): string
    {
        // 1. Protect EVO tags
        $protected = $this->bridge->protect($templateContent);

        // 2. Build Latte params: spread document fields as top-level variables
        //    plus keep $evo and $documentObject for structured access.
        $params = array_merge(
            $documentObject,
            [
                'evo'            => evo(),
                'documentObject' => $documentObject,
            ]
        );

        // 3. Render through Latte (StringLoader uses the content string as the unique ID)
        $rendered = $this->latte->renderToString($protected, $params);

        // 4. Restore EVO tags
        return $this->bridge->restore($rendered);
    }

    // -------------------------------------------------------------------------

    private function resolveCacheDir(): string
    {
        // Prefer Laravel's storage_path() if available
        if (function_exists('storage_path')) {
            $dir = storage_path('framework/cache/latte');
        } else {
            $dir = rtrim(sys_get_temp_dir(), '/\\') . '/latte_cache';
        }

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return $dir;
    }
}
