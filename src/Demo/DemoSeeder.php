<?php

declare(strict_types=1);

namespace Elcreator\aLatteX\Demo;

use EvolutionCMS\Models\Category;
use EvolutionCMS\Models\SiteContent;
use EvolutionCMS\Models\SiteHtmlsnippet;
use EvolutionCMS\Models\SiteSnippet;
use EvolutionCMS\Models\SiteTemplate;
use EvolutionCMS\Models\SiteTmplvar;
use EvolutionCMS\Models\SiteTmplvarContentvalue;
use EvolutionCMS\Models\SiteTmplvarTemplate;
use Illuminate\Support\Facades\DB;

/**
 * Writes the DemoContent set into an Evolution CMS site, and takes it out again.
 *
 * Both directions are idempotent and both are addressed by name: install()
 * updates an element that is already there rather than adding a second one,
 * and remove() deletes only the names the manifest lists. Nothing is matched
 * by prefix or wildcard, so an element a user renamed is left alone rather
 * than swept up with the demo.
 *
 * Order matters in remove(): the join rows first, then the things they join.
 */
final class DemoSeeder
{
    /** @var list<string> Human-readable log of what the last run did. */
    private array $log = [];

    /** @return list<string> */
    public function log(): array
    {
        return $this->log;
    }

    // ------------------------------------------------------------- install --

    /**
     * Create or update every element, then the documents that use them.
     *
     * @return list<string>
     */
    public function install(): array
    {
        $this->log = [];

        $categoryId = $this->categoryId();

        $views = $this->installViews();
        $chunks = $this->installChunks($categoryId);
        $snippets = $this->installSnippets($categoryId);
        $templates = $this->installTemplates($categoryId);
        $tvs = $this->installTvs($categoryId, $templates);
        $documents = $this->installDocuments($templates, $tvs);

        $this->note(sprintf(
            'Installed %d chunks, %d snippets, %d templates, %d TVs, %d documents, %d view files.',
            count($chunks),
            count($snippets),
            count($templates),
            count($tvs),
            count($documents),
            count($views),
        ));

        $this->clearCache();

        return $this->log;
    }

    /**
     * Writes the layout files into the site's views/ directory.
     *
     * The one part of the set that touches the filesystem, because a template
     * reference resolves to a file and a layout that is not one cannot be
     * extended. Everything else here is a database record.
     *
     * A file that is already there and does not match what the demo ships is
     * left alone and reported: it is somebody's own layout that happens to
     * share a name, and overwriting it would be the installer destroying work
     * it did not create. That is the same rule the rest of the seeder follows
     * by addressing elements by name.
     *
     * @return list<string> the files written
     */
    private function installViews(): array
    {
        $directory = $this->viewsDirectory();

        if ($directory === null) {
            $this->note('no views directory configured; layout files skipped');

            return [];
        }

        $written = [];

        foreach (DemoContent::views() as $view) {
            $target = $directory . DIRECTORY_SEPARATOR . $view['target'];

            if (is_file($target) && file_get_contents($target) !== $view['body']) {
                $this->note('kept     ' . $view['target'] . ' (already there, and changed)');

                continue;
            }

            if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
                $this->note('could not create ' . $directory);

                return $written;
            }

            if (file_put_contents($target, $view['body']) === false) {
                $this->note('could not write ' . $target);

                continue;
            }

            $written[] = $view['target'];
            $this->note('view     ' . $view['target']);
        }

        return $written;
    }

    /**
     * Deletes only the layout files still byte-identical to what was installed.
     *
     * An edited file is somebody's work now, whoever put it there, so it stays
     * and is reported.
     */
    private function removeViews(): void
    {
        $directory = $this->viewsDirectory();

        if ($directory === null) {
            return;
        }

        $removed = 0;

        foreach (DemoContent::views() as $view) {
            $target = $directory . DIRECTORY_SEPARATOR . $view['target'];

            if (!is_file($target)) {
                continue;
            }

            if (file_get_contents($target) !== $view['body']) {
                $this->note('kept     ' . $view['target'] . ' (edited since install)');

                continue;
            }

            if (@unlink($target)) {
                $removed++;
            }
        }

        $this->note('removed ' . $removed . ' view files');
    }

    /**
     * Where the CMS looks for view files. The first configured path, which on a
     * stock install is EVO_BASE_PATH . 'views/' - the same directory the
     * manager scaffolds a template file into.
     */
    private function viewsDirectory(): ?string
    {
        $paths = function_exists('config') ? (array) config('view.paths', []) : [];

        foreach ($paths as $path) {
            if (is_string($path) && $path !== '') {
                return rtrim($path, "/\\");
            }
        }

        return defined('EVO_BASE_PATH') ? EVO_BASE_PATH . 'views' : null;
    }

    /** @return array<string, int> name => id */
    private function installChunks(int $categoryId): array
    {
        $ids = [];

        foreach (DemoContent::chunks() as $chunk) {
            $model = SiteHtmlsnippet::firstOrNew(['name' => $chunk['name']]);
            $model->fill([
                'description' => $chunk['description'],
                'editor_type' => 0,
                'editor_name' => 'none',
                'category' => $categoryId,
                'cache_type' => false,
                'snippet' => $chunk['body'],
                'locked' => 0,
                'disabled' => 0,
            ])->save();

            $ids[$chunk['name']] = (int) $model->getKey();
            $this->note('chunk    ' . $chunk['name']);
        }

        return $ids;
    }

    /** @return array<string, int> name => id */
    private function installSnippets(int $categoryId): array
    {
        $ids = [];

        foreach (DemoContent::snippets() as $snippet) {
            $model = SiteSnippet::firstOrNew(['name' => $snippet['name']]);
            $model->fill([
                'description' => $snippet['description'],
                'editor_type' => 0,
                'category' => $categoryId,
                'cache_type' => false,
                'snippet' => $snippet['body'],
                'locked' => 0,
                'properties' => (string) ($snippet['properties'] ?? ''),
                'moduleguid' => '',
                'disabled' => 0,
            ])->save();

            $ids[$snippet['name']] = (int) $model->getKey();
            $this->note('snippet  ' . $snippet['name']);
        }

        return $ids;
    }

    /** @return array<string, int> name => id */
    private function installTemplates(int $categoryId): array
    {
        $ids = [];

        foreach (DemoContent::templates() as $template) {
            $model = SiteTemplate::firstOrNew(['templatename' => $template['name']]);
            $model->fill([
                'description' => $template['description'],
                'editor_type' => 0,
                'category' => $categoryId,
                'icon' => '',
                'template_type' => 0,
                // Empty on purpose: a templatealias that resolves to a file
                // under views/ makes the CMS render that file and skip the
                // parser, and these templates are meant to come from the DB.
                'templatealias' => '',
                'content' => $template['body'],
                'locked' => 0,
                'selectable' => 1,
            ])->save();

            $ids[$template['name']] = (int) $model->getKey();
            $this->note('template ' . $template['name']);
        }

        return $ids;
    }

    /**
     * @param  array<string, int> $templates name => id
     * @return array<string, int> name => id
     */
    private function installTvs(int $categoryId, array $templates): array
    {
        $ids = [];

        foreach (DemoContent::tvs() as $tv) {
            $model = SiteTmplvar::firstOrNew(['name' => $tv['name']]);
            $model->fill([
                'type' => $tv['type'],
                'caption' => $tv['caption'],
                'description' => $tv['description'],
                'editor_type' => 0,
                'category' => $categoryId,
                'locked' => 0,
                'elements' => $tv['elements'],
                'rank' => $tv['rank'],
                'display' => $tv['display'],
                'display_params' => $tv['display_params'],
                'default_text' => $tv['default_text'],
            ])->save();

            $id = (int) $model->getKey();
            $ids[$tv['name']] = $id;

            $attachTo = $tv['templates'] === '*'
                ? array_values($templates)
                : array_values(array_intersect_key($templates, array_flip((array) $tv['templates'])));

            foreach ($attachTo as $rank => $templateId) {
                // The query builder rather than the model: site_tmplvar_templates
                // has a composite primary key and no id column, so an Eloquent
                // save() on an existing row would look for one and fail.
                DB::table('site_tmplvar_templates')->updateOrInsert(
                    ['tmplvarid' => $id, 'templateid' => $templateId],
                    ['rank' => $rank],
                );
            }

            $this->note('tv       ' . $tv['name'] . ' (on ' . count($attachTo) . ' templates)');
        }

        return $ids;
    }

    /**
     * @param  array<string, int> $templates name => id
     * @param  array<string, int> $tvs       name => id
     * @return array<string, int> alias => id
     */
    private function installDocuments(array $templates, array $tvs): array
    {
        $ids = [];
        $now = time();

        foreach (DemoContent::documents() as $document) {
            $parentId = 0;
            if ($document['parent'] !== null) {
                // The manifest lists parents before their children, so this is
                // always already installed.
                $parentId = $ids[$document['parent']] ?? 0;
            }

            $model = SiteContent::withTrashed()
                ->where('alias', $document['alias'])
                ->first();

            $attributes = [
                'type' => 'document',
                'contentType' => 'text/html',
                'pagetitle' => $document['pagetitle'],
                'longtitle' => $document['longtitle'],
                'menutitle' => $document['menutitle'],
                'description' => '',
                'alias' => $document['alias'],
                'link_attributes' => '',
                'published' => 1,
                'pub_date' => 0,
                'unpub_date' => 0,
                'parent' => $parentId,
                'isfolder' => $document['isfolder'] ? 1 : 0,
                'introtext' => '',
                'content' => $document['body'],
                'richtext' => 1,
                'template' => $templates[$document['template']] ?? 0,
                'searchable' => 1,
                'cacheable' => 1,
                'createdby' => 1,
                'editedby' => 1,
                // 'deleted' is fillable, so reinstalling over a soft-deleted
                // page brings it back rather than leaving it in the bin.
                'deleted' => 0,
                'deletedby' => 0,
                'publishedon' => $now,
                'publishedby' => 1,
                'hide_from_tree' => 0,
                'privateweb' => 0,
                'privatemgr' => 0,
                'content_dispo' => 0,
                'hidemenu' => 0,
                'alias_visible' => 1,
            ];

            if ($model === null) {
                $model = SiteContent::create($attributes);
            } else {
                $model->fill($attributes)->save();
            }

            $id = (int) $model->getKey();
            $ids[$document['alias']] = $id;

            foreach ($document['tvs'] as $tvName => $value) {
                if (!isset($tvs[$tvName])) {
                    continue;
                }

                SiteTmplvarContentvalue::query()->updateOrCreate(
                    ['tmplvarid' => $tvs[$tvName], 'contentid' => $id],
                    ['value' => $value],
                );
            }

            $this->note('document ' . $document['alias'] . ' (#' . $id . ')');
        }

        return $ids;
    }

    // -------------------------------------------------------------- remove --

    /**
     * Delete everything install() created, by name.
     *
     * Documents are force-deleted rather than sent to the recycle bin: the
     * demo is scaffolding, and leaving six soft-deleted pages behind would
     * make a second install() collide with them.
     *
     * @return list<string>
     */
    public function remove(): array
    {
        $this->log = [];

        $tvIds = SiteTmplvar::query()
            ->whereIn('name', array_column(DemoContent::tvs(), 'name'))
            ->pluck('id')
            ->all();

        $documentIds = SiteContent::withTrashed()
            ->whereIn('alias', array_column(DemoContent::documents(), 'alias'))
            ->pluck('id')
            ->all();

        // Join rows first, so nothing is left pointing at a deleted row.
        if ($tvIds !== []) {
            SiteTmplvarTemplate::query()->whereIn('tmplvarid', $tvIds)->delete();
            SiteTmplvarContentvalue::query()->whereIn('tmplvarid', $tvIds)->delete();
        }

        if ($documentIds !== []) {
            SiteTmplvarContentvalue::query()->whereIn('contentid', $documentIds)->delete();

            // The closure table is maintained by the model on create but not
            // on a force delete, so its rows are removed explicitly.
            DB::table('site_content_closure')
                ->whereIn('descendant', $documentIds)
                ->orWhereIn('ancestor', $documentIds)
                ->delete();

            // The query builder, so this is one hard DELETE: SiteContent's
            // deleting event flips the `deleted` flag and saves instead, which
            // is the recycle-bin behaviour the demo does not want.
            DB::table('site_content')->whereIn('id', $documentIds)->delete();
            $this->note('removed ' . count($documentIds) . ' documents');
        }

        $counts = [
            'TVs' => SiteTmplvar::query()
                ->whereIn('name', array_column(DemoContent::tvs(), 'name'))->delete(),
            'templates' => SiteTemplate::query()
                ->whereIn('templatename', array_column(DemoContent::templates(), 'name'))->delete(),
            'snippets' => SiteSnippet::query()
                ->whereIn('name', array_column(DemoContent::snippets(), 'name'))->delete(),
            'chunks' => SiteHtmlsnippet::query()
                ->whereIn('name', array_column(DemoContent::chunks(), 'name'))->delete(),
        ];

        foreach ($counts as $label => $count) {
            $this->note('removed ' . $count . ' ' . $label);
        }

        $this->removeViews();
        $this->removeCategoryIfEmpty();
        $this->clearCache();

        return $this->log;
    }

    // -------------------------------------------------------------- helpers --

    private function categoryId(): int
    {
        $category = Category::query()->firstOrCreate(
            ['category' => DemoContent::category()],
            ['rank' => 0],
        );

        return (int) $category->getKey();
    }

    /**
     * Drop the demo category, but only once nothing is filed under it - a site
     * may have put its own elements there in the meantime.
     */
    private function removeCategoryIfEmpty(): void
    {
        $category = Category::query()
            ->where('category', DemoContent::category())
            ->first();

        if ($category === null) {
            return;
        }

        $id = (int) $category->getKey();

        $inUse = SiteHtmlsnippet::query()->where('category', $id)->exists()
            || SiteSnippet::query()->where('category', $id)->exists()
            || SiteTemplate::query()->where('category', $id)->exists()
            || SiteTmplvar::query()->where('category', $id)->exists();

        if ($inUse) {
            $this->note('kept the "' . DemoContent::category() . '" category: it still has elements in it');
            return;
        }

        $category->delete();
        $this->note('removed the "' . DemoContent::category() . '" category');
    }

    /**
     * Ask the CMS to drop its page cache. Every demo page is cacheable, so
     * without this the site would keep serving the pre-install version.
     */
    private function clearCache(): void
    {
        if (!function_exists('evo')) {
            return;
        }

        try {
            evo()->clearCache('full');
            $this->note('cleared the site cache');
        } catch (\Throwable $e) {
            $this->note('could not clear the site cache: ' . $e->getMessage());
        }
    }

    private function note(string $line): void
    {
        $this->log[] = $line;
    }
}
