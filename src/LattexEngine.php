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

    /** @param list<string>|null $viewPaths */
    public function __construct(?array $viewPaths = null)
    {
        $this->bridge = new EvoSyntaxBridge();
        $this->latte  = new Engine();
        $this->loader = new SourceLoader($this->bridge, $viewPaths ?? $this->resolveViewPaths());

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
        // One render can load a root, layout and several partials. Their EVO
        // token maps live together until the complete output is restored.
        $this->bridge->beginRender();

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
            $this->loader->add($this->templateName($fields), $templateContent),
            $params
        );

        // 4. Restore EVO tags
        return $this->bridge->restore($rendered);
    }

    /**
     * Render a .latte file for Laravel's view factory.
     *
     * EVO tags are protected during the Latte pass, the same as in render(),
     * and restored afterwards. The core would then leave them as text - it
     * skips parseDocumentSource() for a view-rendered document - so the plugin
     * runs those passes itself once the view is back; see
     * alattexFinishViewRender() in plugins/aLattexPlugin.php. The effect is
     * that a template kept in views/<alias>.latte behaves exactly like the same
     * code kept in the database, which is the point: where a template lives is
     * a version-control decision, not a syntax one.
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

        $this->bridge->beginRender();

        // The file's own path is the name here, so Tracy's panel and its
        // BlueScreen can offer to open the template in an editor.
        $rendered = $this->latte->renderToString(
            $this->loader->add($path, $contents),
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

    /** @return list<string> */
    private function resolveViewPaths(): array
    {
        if (function_exists('config')) {
            try {
                $paths = array_values(array_filter(
                    (array) config('view.paths', []),
                    static fn(mixed $path): bool => is_string($path) && $path !== '',
                ));
                if ($paths !== []) {
                    return $paths;
                }
            } catch (\Throwable) {
                // A standalone test or console context can have Laravel's
                // helper without a booted config repository.
            }
        }

        return defined('EVO_BASE_PATH') ? [EVO_BASE_PATH . 'views/'] : [];
    }
}
