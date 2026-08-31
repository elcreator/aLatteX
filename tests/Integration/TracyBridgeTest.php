<?php

declare(strict_types=1);

use Elcreator\aLatteX\LattexEngine;
use Elcreator\aLatteX\TracyBridge;
use Elcreator\aLatteX\TracyPanel;

/**
 * The bar panel, exercised without switching Tracy on.
 *
 * Debugger::enable() would take the process's error, exception and shutdown
 * handlers for the rest of the run, and print a bar over whatever the runner was
 * writing. It is not needed: everything below the switch works on a Debugger
 * that was never enabled - getBar() builds its bar on demand and simply never
 * renders it - so the suite calls TracyBridge::create() directly and asserts on
 * the panel's own HTML.
 */
function requireLatteTracyBridge(): void
{
    if (!class_exists(\Latte\Engine::class)) {
        skip('Latte dependency is not installed; run composer install first.');
    }

    if (!TracyBridge::isSupported()) {
        skip('Tracy is not installed in this core; the bar panel cannot be built.');
    }
}

/**
 * Attaches a bridge to an engine the way its constructor would on a site with
 * Tracy switched on.
 */
function engineWithBridge(TracyBridge $bridge): LattexEngine
{
    $engine = new LattexEngine();

    (function () use ($bridge): void {
        $this->latte->addExtension($bridge);
    })->call($engine);

    return $engine;
}

test('the panel stays off a site whose Tracy is not enabled', function (): void {
    requireLatteTracyBridge();

    // Nothing in the suite calls Debugger::enable(), so this is the state of a
    // stock site: tracy.active is false and the bridge must not register.
    assertSame(false, \Tracy\Debugger::isEnabled());
    assertSame(null, TracyBridge::extension());
});

test('rendered templates reach the bar panel under a readable name', function (): void {
    requireLatteTracyBridge();

    useFakeEvo(
        documentObject: [
            'id' => 7,
            'template' => 12,
            'pagetitle' => 'Panel probe',
        ],
    );

    $bridge = TracyBridge::create();

    engineWithBridge($bridge)->render('<h1>{$pagetitle}</h1>', evo()->documentObject);

    $tab = (string) $bridge->panel()->getTab();
    $panel = (string) $bridge->panel()->getPanel();

    assertStringContains('aLatteX', $tab);
    assertStringContains('Evolution template #12', $panel);

    // The point of the whole naming exercise: the source is not the name, so it
    // is not what the panel prints.
    assertStringNotContains('{$pagetitle}', $panel);
    assertStringNotContains('__ALATTEX_', $panel);

    // Parameters are off by default, so $evo - the entire CMS core - is not
    // walked by the dumper.
    assertStringNotContains('<h2>Parameters</h2>', $panel);
});

test('parameters are dumped only when the site asks for it', function (): void {
    requireLatteTracyBridge();

    useFakeEvo(documentObject: ['id' => 7, 'template' => 12, 'pagetitle' => 'Dumped']);

    $bridge = TracyBridge::create(dumpParameters: true);

    engineWithBridge($bridge)->render('<h1>{$pagetitle}</h1>', evo()->documentObject);

    assertStringContains('<h2>Parameters</h2>', (string) $bridge->panel()->getPanel());
});

test('a request that rendered nothing gets no tab, and so no panel', function (): void {
    requireLatteTracyBridge();

    // Tracy asks a panel for its content only when its tab is non-empty, so the
    // null tab is what keeps aLatteX off a manager page or a page-cache hit -
    // and what guarantees getPanel() always has rows when it is called.
    $panel = TracyBridge::create()->panel();

    assertSame(null, $panel->getTab());
    assertSame(null, $panel->getPanel());
});

test('the panel is built on a published interface, not on Latte internals', function (): void {
    requireLatteTracyBridge();

    // Latte's own LattePanel is marked @internal and the constructor that does
    // not warn is marked @deprecated, so a patch release is free to move either.
    // This plugin's panel implements Tracy\IBarPanel, which is neither.
    assertSame(
        true,
        (new ReflectionClass(TracyPanel::class))->implementsInterface(\Tracy\IBarPanel::class),
    );

    assertSame(TracyPanel::class, get_class(TracyBridge::create()->panel()));
});

test('a core without the pieces is left alone rather than broken', function (): void {
    // isSupported() gates every other entry point, and asks about exactly the
    // three things the panel cannot be built without - so a core with Tracy
    // removed gets no panel instead of a fatal.
    assertSame(
        class_exists(\Tracy\Debugger::class)
            && interface_exists(\Tracy\IBarPanel::class)
            && method_exists(\Tracy\Debugger::class, 'getBar'),
        TracyBridge::isSupported(),
    );

    // Latte's BlueScreen bridge is the one @internal class still used. It is
    // reached only through class_exists()/method_exists() and a try/catch, so
    // the suite can assert the shape rather than the outcome.
    $source = file_get_contents(dirname(__DIR__, 2) . '/src/TracyBridge.php');

    assertStringContains('class_exists($panel)', $source);
    assertStringContains("method_exists(\$panel, 'initialize')", $source);
    assertStringContains('} catch (\Throwable) {', $source);
});
