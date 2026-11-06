<?php

declare(strict_types=1);

/**
 * A FrankenPHP worker with a new application per request (Evolution 3.6) includes
 * the plugin once per request, to register its listeners on that application, in
 * a process that keeps the functions of the last one. A child process, because the
 * failure is a fatal "Cannot redeclare" that would take the suite down with it.
 */
test('the plugin can be included again in the same process', function (): void {
    $plugin = dirname(__DIR__, 2) . '/plugins/aLattexPlugin.php';
    $script = tempnam(sys_get_temp_dir(), 'alattex-reload-');
    file_put_contents($script, '<?php
class Event { public static int $listeners = 0; public static function listen(mixed ...$arguments): void { self::$listeners++; } }
include ' . var_export($plugin, true) . ';
$once = Event::$listeners;
include ' . var_export($plugin, true) . ';
echo Event::$listeners === 2 * $once && $once > 0 ? "registered twice" : "listeners: " . Event::$listeners;
echo function_exists("alattexEvoTagsEnabled") && function_exists("alattexFinishViewRender") ? ", helpers defined" : ", helpers missing";
');

    try {
        $output = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>&1');
    } finally {
        unlink($script);
    }

    assertStringContains('registered twice, helpers defined', $output);
});

/**
 * The system settings page runs what the plugin prints, and a stored value is not
 * trusted to stay inside a script element.
 */
test('the settings script carries no stored value', function (string $stored, string $expected): void {
    $plugin = dirname(__DIR__, 2) . '/plugins/aLattexPlugin.php';
    $script = tempnam(sys_get_temp_dir(), 'alattex-settings-');
    file_put_contents($script, '<?php
class Event { public static array $listeners = []; public static function listen(string $name, mixed $listener = null): void { self::$listeners[$name] = $listener; } }
function evo(): object { return new class { public function getConfig(string $name): string { return ' . var_export($stored, true) . '; } }; }
include ' . var_export($plugin, true) . ';
echo (Event::$listeners["evolution.OnSiteSettingsRender"])();
');

    try {
        $output = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>&1');
    } finally {
        unlink($script);
    }

    assertStringContains('var isCurrent = ' . $expected . ';', $output);
    expect(substr_count($output, '</script>'))->toBe(1);
})->with([
    'the plugin is the processor' => ['aLatteX', 'true'],
    'another processor' => ['DocumentParser', 'false'],
    'a value that closes the script' => ['</script><script>alert(1)//', 'false'],
]);
