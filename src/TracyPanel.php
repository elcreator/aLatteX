<?php

declare(strict_types=1);

namespace Elcreator\aLatteX;

use Latte\Runtime\Template;
use Tracy\Dumper;
use Tracy\Helpers;
use Tracy\IBarPanel;

/**
 * The aLatteX tab on Evolution CMS's Tracy bar.
 *
 * Latte ships a panel of its own - Latte\Bridges\Tracy\LattePanel - and this
 * started as a use of it. It is marked `@internal`, and the only constructor
 * that does not emit a deprecation is marked `@deprecated` besides, so building
 * on it means a Latte patch release can take the bar down on every site running
 * this plugin. The panel is implemented against Tracy\IBarPanel instead, which
 * carries no `@internal` and is two methods wide.
 *
 * It reports what one request rendered as a tree: the root template, whatever it
 * extends, and every file or chunk partial included along the way, each with the
 * relation that pulled it in and the time it took. That mattered less when
 * aLatteX could only ever render one string; now that SourceLoader resolves
 * `<alias>.latte` files and `chunk:` references, a page is routinely three
 * templates deep and the shape is the first thing worth seeing.
 *
 * Everything outside Tracy\IBarPanel is optional and guarded - Dumper,
 * Helpers::editorLink, and Latte's own getReferringTemplate()/getReferenceType()
 * - so a version that moves any of them costs a link, a dump or the nesting
 * rather than the request.
 *
 * See TracyBridge for what is still borrowed from Latte, and how.
 */
final class TracyPanel implements IBarPanel
{
    /**
     * Dump the root template's parameters into the panel.
     *
     * Off by default, and not a stylistic choice: aLatteX passes $evo, which is
     * the CMS core and through it the container, the database connection and the
     * config. Tracy walks it to Debugger::$maxDepth, which Evolution CMS raises
     * to 20. On a demo page the dump took the response from 113 KB to 280 KB.
     */
    public bool $dumpParameters = false;

    /**
     * One entry per rendered Template instance, in the order they began.
     *
     * @var array<int, array{name: string, parent: int|null, type: string|null, time: float}>
     */
    private array $entries = [];

    /** @var array<int, int|float> spl_object_id => start time from hrtime() */
    private array $started = [];

    /** @var array<string, mixed>|null the root template's parameters */
    private ?array $parameters = null;

    public function addTemplate(Template $template): void
    {
        $id = spl_object_id($template);

        $this->entries[$id] = [
            'name' => $template->getName(),
            'parent' => $this->parentOf($template),
            'type' => $this->relationOf($template),
            'time' => 0.0,
        ];

        $this->started[$id] = hrtime(true);

        if ($this->parameters === null) {
            $this->parameters = $template->getParameters();
        }
    }

    public function templateRendered(Template $template): void
    {
        $id = spl_object_id($template);

        if (!isset($this->started[$id], $this->entries[$id])) {
            return;
        }

        $this->entries[$id]['time'] += (hrtime(true) - $this->started[$id]) / 1e9;
        unset($this->started[$id]);
    }

    /**
     * Null when nothing was rendered, which is what keeps the tab off a request
     * that never reached a template - a manager page, or a document served from
     * the CMS's page cache. Tracy asks for the panel only when the tab is there,
     * so this is also what guarantees getPanel() has rows to show.
     */
    public function getTab(): ?string
    {
        if (!$this->entries) {
            return null;
        }

        $count = count($this->entries);

        return '<span title="Templates rendered by aLatteX">'
            . '<span class="tracy-label">aLatteX'
            . ($count > 1 ? ' (' . $count . ')' : '')
            . '</span></span>';
    }

    public function getPanel(): ?string
    {
        if (!$this->entries) {
            return null;
        }

        $out = self::style()
            . '<h1>aLatteX</h1><div class="tracy-inner alx-panel"><table>'
            . '<tr><th>Template</th><th></th><th class="alx-time">time</th></tr>';

        foreach ($this->rows() as $row) {
            $out .= $this->row($row);
        }

        $out .= '</table></div>';

        if ($this->dumpParameters && $this->parameters) {
            $out .= '<h2>Parameters</h2><div class="tracy-inner"><table>';

            foreach ($this->parameters as $key => $value) {
                $out .= '<tr><th>' . self::escape((string) $key) . '</th>'
                    . '<td>' . self::dump($value) . '</td></tr>';
            }

            $out .= '</table></div>';
        }

        return $out;
    }

    /**
     * The entries as display rows, depth-first from each root.
     *
     * Instances sharing a parent *and* a name become one row with a count: a
     * partial included in a loop is one thing included many times, and twenty
     * identical rows would say less than one row saying twenty.
     *
     * @return list<array{name: string, type: string|null, depth: int, count: int, time: float}>
     */
    private function rows(): array
    {
        $children = [];

        foreach ($this->entries as $id => $entry) {
            $children[$entry['parent'] ?? 0][$entry['name']][] = $id;
        }

        $rows = [];
        $listed = [];
        $this->collect($children, 0, 0, $rows, $listed);

        // A template whose parent was never recorded would otherwise vanish
        // from the panel. It should not happen; if it ever does, showing it at
        // the root beats losing it.
        foreach ($this->entries as $id => $entry) {
            if (!isset($listed[$id])) {
                $rows[] = [
                    'name' => $entry['name'],
                    'type' => $entry['type'],
                    'depth' => 0,
                    'count' => 1,
                    'time' => $entry['time'],
                ];
            }
        }

        return $rows;
    }

    /**
     * @param array<int, array<string, list<int>>> $children
     * @param list<array{name: string, type: string|null, depth: int, count: int, time: float}> $rows
     * @param array<int, true> $listed
     */
    private function collect(array $children, int $parent, int $depth, array &$rows, array &$listed): void
    {
        foreach ($children[$parent] ?? [] as $name => $ids) {
            $time = 0.0;

            foreach ($ids as $id) {
                $time += $this->entries[$id]['time'];
                $listed[$id] = true;
            }

            $rows[] = [
                'name' => $name,
                'type' => $this->entries[$ids[0]]['type'],
                'depth' => $depth,
                'count' => count($ids),
                'time' => $time,
            ];

            foreach ($ids as $id) {
                $this->collect($children, $id, $depth + 1, $rows, $listed);
            }
        }
    }

    /** @param array{name: string, type: string|null, depth: int, count: int, time: float} $row */
    private function row(array $row): string
    {
        $indent = $row['depth'] > 0
            ? '<span class="alx-indent" style="width:' . ($row['depth'] * 12) . 'px"></span>&#9492; '
            : '';

        $relation = $row['type'] !== null && $row['type'] !== ''
            ? '<span class="alx-rel">' . self::escape($row['type']) . '</span> '
            : '';

        return '<tr><td>' . $indent . $relation . self::name($row['name']) . '</td>'
            . '<td>' . ($row['count'] > 1 ? self::escape((string) $row['count']) . '&times;' : '') . '</td>'
            . '<td class="alx-time">' . self::escape(number_format($row['time'] * 1000, 1)) . ' ms</td></tr>';
    }

    /**
     * Which template pulled this one in, and how. Both are public methods on a
     * public Latte class, but the panel is a debugging aid: it asks rather than
     * assumes, and degrades to a flat list if either ever goes away.
     */
    private function parentOf(Template $template): ?int
    {
        if (!method_exists($template, 'getReferringTemplate')) {
            return null;
        }

        $parent = $template->getReferringTemplate();

        return $parent === null ? null : spl_object_id($parent);
    }

    private function relationOf(Template $template): ?string
    {
        return method_exists($template, 'getReferenceType')
            ? $template->getReferenceType()
            : null;
    }

    /**
     * A template kept in a file is offered as an editor link; one kept in the
     * database, or a chunk, has no file to open and is printed as it is.
     */
    private static function name(string $name): string
    {
        if (@is_file($name) && method_exists(Helpers::class, 'editorLink')) {
            return Helpers::editorLink($name);
        }

        return '<span>' . self::escape($name) . '</span>';
    }

    private static function dump(mixed $value): string
    {
        if (!class_exists(Dumper::class) || !method_exists(Dumper::class, 'toHtml')) {
            return self::escape(get_debug_type($value));
        }

        // LIVE defers the expansion to a snapshot Tracy renders once, which is
        // what makes dumping a large graph survivable at all. Its absence is not
        // worth giving up the dump over.
        $options = defined(Dumper::class . '::LIVE') ? [Dumper::LIVE => true] : [];

        return Dumper::toHtml($value, $options);
    }

    private static function style(): string
    {
        return '<style class="tracy-debug">'
            . '#tracy-debug .alx-panel td{white-space:nowrap}'
            . '#tracy-debug .alx-time{text-align:right;font-variant-numeric:tabular-nums}'
            . '#tracy-debug .alx-indent{display:inline-block}'
            . '#tracy-debug .alx-rel{border-radius:2px;padding:1px 4px;font-size:80%;font-weight:bold;'
            . 'color:#fff;background:#8250df}'
            . '</style>';
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
