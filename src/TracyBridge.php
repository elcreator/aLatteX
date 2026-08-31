<?php

declare(strict_types=1);

namespace Elcreator\aLatteX;

use Latte\Extension;
use Latte\Runtime\Template;
use Tracy\Debugger;

/**
 * Puts the templates aLatteX renders on Evolution CMS's Tracy bar.
 *
 * Nothing here is a new dependency. Tracy is part of the CMS - core/composer.json
 * requires tracy/tracy 2.*, EvolutionCMS\Providers\TracyServiceProvider turns it
 * on from core/config/tracy.php and hangs the core's own panels on it - and this
 * is the wiring between that and the engine.
 *
 * ---
 *
 * **On not building this out of `@internal` classes.**
 *
 * Latte ships two classes under Latte\Bridges\Tracy that would each save work
 * here, and both are marked `@internal`: LattePanel, whose only non-deprecated
 * constructor is itself marked `@deprecated`, and BlueScreenPanel. A Latte patch
 * release is free to rename or drop either one, and a plugin that calls them
 * unguarded turns that into a fatal on every page of every site running it. The
 * public alternative, TracyExtension, is not usable as-is: it keeps its panel in
 * a private readonly property, and that panel dumps every top-level parameter -
 * one of ours is $evo, the whole CMS core.
 *
 * So the split is:
 *
 *   - **The bar panel is ours.** TracyPanel implements Tracy\IBarPanel, which
 *     carries no `@internal` and is two methods wide. Nothing about the panel
 *     depends on Latte's.
 *   - **The BlueScreen integration is still Latte's**, because reimplementing it
 *     would mean reimplementing the compiled-PHP-to-.latte source mapping, and
 *     it is a bonus rather than the feature. It is called only after checking
 *     that the class and the method are both there, and inside a try/catch, so
 *     losing it costs the nicer error screen and nothing else.
 *
 * Everything setup does is wrapped: a Throwable while building the panel leaves
 * the site with no panel, never with no page.
 *
 * ---
 *
 * Configuration, in core/custom/config/alattex.php (optional - the defaults are
 * what a site gets without the file):
 *
 *     return [
 *         'tracy' => [
 *             'enabled' => true,           // false: never register the panel
 *             'dump_parameters' => false,  // true: dump template parameters, $evo included
 *         ],
 *     ];
 */
final class TracyBridge extends Extension
{
    private function __construct(private readonly TracyPanel $panel)
    {
    }

    /**
     * The extension to register, or null when this site has no Tracy bar to
     * register it on - or when building the panel went wrong, which is not a
     * reason to take a page down over a debugging aid.
     */
    public static function extension(): ?self
    {
        if (!self::isActive()) {
            return null;
        }

        try {
            return self::create((bool) self::config('dump_parameters', false));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Is Tracy present, switched on, and not opted out of?
     *
     * Debugger::isEnabled() is the switch, and it is answered by the time this
     * runs: TracyServiceProvider is registered from EvolutionCMS\ExceptionHandler,
     * which Core::initialize() resolves at the top of the request, while the
     * engine this gates is a lazily-made singleton that nothing touches before
     * OnLoadWebDocument. It stays true in Tracy's production mode, where the bar
     * is collected but never rendered; the panel costs a few objects there and
     * the BlueScreen still gains the .latte source mapping, which is the half of
     * this that matters when there is no bar to look at.
     */
    public static function isActive(): bool
    {
        return self::isSupported()
            && Debugger::isEnabled()
            && (bool) self::config('enabled', true);
    }

    /**
     * Everything the panel cannot be built without: Tracy's debugger, the bar it
     * hangs on, and the interface the panel implements. Tracy can be removed
     * from a core, and a plugin has no business assuming otherwise.
     */
    public static function isSupported(): bool
    {
        return class_exists(Debugger::class)
            && interface_exists(\Tracy\IBarPanel::class)
            && method_exists(Debugger::class, 'getBar');
    }

    /**
     * Builds the panel and hangs it on the bar, whatever the switch says.
     *
     * Split out from extension() so the suite can exercise the panel without
     * calling Debugger::enable(), which would take over the process's error and
     * shutdown handlers for the rest of the run.
     */
    public static function create(bool $dumpParameters = false): self
    {
        self::initialiseBlueScreen();

        $panel = new TracyPanel();
        $panel->dumpParameters = $dumpParameters;

        Debugger::getBar()->addPanel($panel);

        return new self($panel);
    }

    /**
     * Teach Tracy's BlueScreen to report a Latte\CompileException with its
     * template name, and to map a line of compiled PHP back to the .latte line
     * it came from.
     *
     * This is the one thing still taken from an `@internal` Latte class, so it
     * is asked for rather than assumed - and a failure here is swallowed, since
     * the alternative is a debugging aid taking down the site it is meant to
     * help debug.
     */
    private static function initialiseBlueScreen(): void
    {
        $panel = \Latte\Bridges\Tracy\BlueScreenPanel::class;

        if (!class_exists($panel) || !method_exists($panel, 'initialize')) {
            return;
        }

        try {
            $panel::initialize();
        } catch (\Throwable) {
            // No source mapping in the error screen. The bar is unaffected.
        }
    }

    /** The panel this extension feeds. */
    public function panel(): TracyPanel
    {
        return $this->panel;
    }

    public function beforeRender(Template $template): void
    {
        $this->panel->addTemplate($template);
    }

    public function afterRender(Template $template): void
    {
        $this->panel->templateRendered($template);
    }

    /**
     * A key from the site's alattex.tracy config, if the site has a config at
     * all - the plugin is usable, and testable, without a container.
     */
    private static function config(string $key, mixed $default): mixed
    {
        if (!function_exists('config')) {
            return $default;
        }

        return config('alattex.tracy.' . $key, $default);
    }
}
