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
 * this plugin. What that panel offers over this one is a nesting tree. aLatteX
 * deliberately keeps a flat list instead: it reports every root, layout, file
 * and chunk partial with its total time without depending on Latte's internal
 * panel.
 *
 * So the panel is implemented against Tracy\IBarPanel instead, which carries no
 * `@internal` and is two methods wide. Everything else it touches - Dumper,
 * Helpers::editorLink - is optional and guarded, so a Tracy that moves them
 * costs a link or a dump rather than the request.
 *
 * See TracyBridge for what is still borrowed from Latte, and how.
 */
final class TracyPanel implements IBarPanel
{
    /**
     * Dump the first template's parameters into the panel.
     *
     * Off by default, and not a stylistic choice: aLatteX passes $evo, which is
     * the CMS core and through it the container, the database connection and the
     * config. Tracy walks it to Debugger::$maxDepth, which Evolution CMS raises
     * to 20. On a demo page the dump took the response from 113 KB to 280 KB.
     */
    public bool $dumpParameters = false;

    /** @var array<string, array{count: int, time: float}> keyed by template name */
    private array $rows = [];

    /** @var array<int, int|float> spl_object_id => start time from hrtime() */
    private array $started = [];

    /** @var array<string, mixed>|null the first template's parameters */
    private ?array $parameters = null;

    public function addTemplate(Template $template): void
    {
        $name = $template->getName();

        if (!isset($this->rows[$name])) {
            $this->rows[$name] = ['count' => 0, 'time' => 0.0];
        }

        $this->rows[$name]['count']++;
        $this->started[spl_object_id($template)] = hrtime(true);

        if ($this->parameters === null) {
            $this->parameters = $template->getParameters();
        }
    }

    public function templateRendered(Template $template): void
    {
        $id = spl_object_id($template);

        if (!isset($this->started[$id])) {
            return;
        }

        $this->rows[$template->getName()]['time'] += (hrtime(true) - $this->started[$id]) / 1e9;
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
        if (!$this->rows) {
            return null;
        }

        return '<span title="Templates rendered by aLatteX">'
            . '<span class="tracy-label">aLatteX</span>'
            . '</span>';
    }

    public function getPanel(): ?string
    {
        if (!$this->rows) {
            return null;
        }

        $out = '<h1>aLatteX</h1><div class="tracy-inner"><table>'
            . '<tr><th>Template</th><th></th><th>time</th></tr>';

        foreach ($this->rows as $name => $row) {
            $out .= '<tr><td>' . self::name($name) . '</td>'
                . '<td>' . ($row['count'] > 1 ? self::escape((string) $row['count']) . '&times;' : '') . '</td>'
                . '<td style="text-align:right">'
                . self::escape(number_format($row['time'] * 1000, 1)) . ' ms</td></tr>';
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
     * A template kept in a file is offered as an editor link; one kept in the
     * database has no file to open, so it is printed as it is. Helpers is
     * Tracy's and not marked internal, but it is a convenience either way -
     * losing it costs the link, not the row.
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

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
