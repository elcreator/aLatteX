<?php

namespace Elcreator\aLatteX;

use Latte\Engine;

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
    private SourceLoader $loader;

    public function __construct()
    {
        $this->bridge = new EvoSyntaxBridge();
        $this->latte  = new Engine();
        $this->loader = new SourceLoader();

        $cacheDir = $this->resolveCacheDir();
        $this->latte->setTempDirectory($cacheDir);

        $this->latte->addExtension(new EvoExtension());

        // Templates are rendered under a readable name, while the loader still
        // reports the source as the unique cache key - so an edited template is
        // recompiled, and Tracy has something short to print. See SourceLoader.
        $this->latte->setLoader($this->loader);

        // On a site with Tracy switched on, list what was rendered and how long
        // it took on its bar. Null on every other site.
        if ($tracy = TracyBridge::extension()) {
            $this->latte->addExtension($tracy);
        }
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
        //    Template variables arrive from the core as [name, value, display,
        //    display_params, type]; DocumentObject::flatten() reduces each to
        //    its value, so {$alxSubtitle} means what [*alxSubtitle*] means.
        $fields = DocumentObject::flatten($documentObject);

        $params = array_merge(
            $fields,
            [
                'evo'            => evo(),
                'documentObject' => $fields,
            ]
        );

        // 3. Render through Latte, under a name that says which template this is
        $rendered = $this->latte->renderToString(
            $this->loader->add($this->templateName($fields), $protected),
            $params
        );

        // 4. Restore EVO tags
        return $this->bridge->restore($rendered);
    }

    /**
     * Render a .latte file for Laravel's view factory.
     *
     * Unlike render(), the result is final: a document rendered from a view
     * file never reaches parseDocumentSource(), so EVO tags left in the output
     * stay literal - exactly as they do in a .blade.php file today. They are
     * still protected during the Latte pass so that a stray brace in EVO syntax
     * cannot abort compilation.
     *
     * @param  string               $path    Absolute path to the .latte file
     * @param  array<string, mixed> $data    View data shared by the CMS
     * @return string
     */
    public function renderView(string $path, array $data = []): string
    {
        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException('aLatteX cannot read the template file: ' . $path);
        }

        $documentObject = [];
        if (isset($data['documentObject']) && is_array($data['documentObject'])) {
            $documentObject = $data['documentObject'];
        }

        // Document fields stay available as bare variables, the same way they
        // are in a template held in the database - TVs flattened to their
        // values just the same.
        $fields = DocumentObject::flatten($documentObject);

        $params = array_merge(
            $fields,
            $data,
            [
                'evo' => evo(),
                'documentObject' => $fields,
            ]
        );

        $protected = $this->bridge->protect($contents);

        // The file's own path is the name here, so Tracy's panel and its
        // BlueScreen can offer to open the template in an editor.
        $rendered = $this->latte->renderToString(
            $this->loader->add($path, $protected),
            $params
        );

        return $this->bridge->restore($rendered);
    }

    /**
     * Every tag, filter and function this engine understands.
     *
     * Asked of the engine rather than written out, so it is the truth for the
     * Latte the site actually has and gains whatever an extension adds - the
     * six evo* functions of EvoExtension included. The manager's template
     * editor completes from it; see ManagerEditor.
     *
     * @return array{tags: list<string>, filters: list<string>, functions: list<string>}
     */
    public function vocabulary(): array
    {
        $tags = $filters = $functions = [];

        foreach ($this->latte->getExtensions() as $extension) {
            $tags[]      = array_keys($extension->getTags());
            $filters[]   = array_keys($extension->getFilters());
            $functions[] = array_keys($extension->getFunctions());
        }

        $flatten = static function (array $lists): array {
            $names = array_values(array_unique(array_merge(...$lists ?: [[]])));
            sort($names, SORT_NATURAL | SORT_FLAG_CASE);

            return $names;
        };

        return [
            'tags'      => $flatten($tags),
            'filters'   => $flatten($filters),
            'functions' => $flatten($functions),
        ];
    }

    // -------------------------------------------------------------------------

    /**
     * What to call a template held in the database.
     *
     * There is no file and no name in what the core hands over - only the
     * document's fields, one of which is the id of the template it was rendered
     * from. That id is what a developer needs to find the record, so it is the
     * name; where it is missing (a chunk rendered straight through the engine, a
     * fixture) the generic name still beats a source dump.
     *
     * @param  array<string, mixed> $fields  a flattened documentObject
     */
    private function templateName(array $fields): string
    {
        $id = $fields['template'] ?? null;

        return is_numeric($id)
            ? 'Evolution template #' . (int) $id
            : 'Evolution template';
    }

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
