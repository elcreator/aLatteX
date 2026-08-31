<?php

declare(strict_types=1);

/**
 * Test bootstrap.
 *
 * aLatteX has no vendor directory of its own in CI — it is installed into an
 * Evolution CMS core and borrows that core's autoloader, which is also where
 * Pest and Latte come from. So the suite points at the core it is developed
 * against and maps this package's namespace on top. A developer who has run
 * `composer install` here gets the plugin's own vendor tree instead, so
 * `composer test` works without a core.
 *
 * The CMS is never booted: these are unit tests against the template bridge,
 * and the thin FakeEvolutionCore below is all of the CMS they are entitled to.
 */

$core = getenv('EVO_CORE_PATH_TEST') ?: '';
$core = $core !== '' ? rtrim(str_replace('\\', '/', $core), '/') : '';

if ($core !== '' && is_file($core . '/vendor/autoload.php')) {
    require $core . '/vendor/autoload.php';
} elseif (is_file(dirname(__DIR__) . '/vendor/autoload.php')) {
    require dirname(__DIR__) . '/vendor/autoload.php';
} else {
    fwrite(STDERR, "aLatteX tests need an autoloader to borrow.\n"
        . "Either set EVO_CORE_PATH_TEST to an Evolution CMS core built with dev\n"
        . "dependencies, or run `composer install` in this repository.\n");
    exit(1);
}

// This package's own namespace, prepended so the working copy under test wins
// over the installed copy inside a core the autoloader was borrowed from.
spl_autoload_register(static function (string $class): void {
    $prefix = 'Elcreator\\aLatteX\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $path = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

    if (is_file($path)) {
        require $path;
    }
}, true, true);

/**
 * Latte stand-ins.
 *
 * The two unit suites exercise the bridge and the extension, neither of which
 * needs a real Latte. Only the integration test does, and it skips itself when
 * Latte is absent. So a bare checkout can still run most of the suite.
 */
if (!class_exists(\Latte\Extension::class)) {
    eval(<<<'PHP'
namespace Latte;

abstract class Extension
{
    public function getFunctions(): array
    {
        return [];
    }
}
PHP);
}

if (!class_exists(\Latte\Runtime\Html::class)) {
    eval(<<<'PHP'
namespace Latte\Runtime;

class Html
{
    public function __construct(private string|\Stringable|null $value)
    {
    }

    public function __toString(): string
    {
        return (string) $this->value;
    }
}
PHP);
}

/**
 * Assertion and control helpers.
 *
 * Defined here rather than in a `Pest.php`: this package's tests run against an
 * Evolution CMS core's Pest binary, so Pest's own root is that core and it
 * would never discover a `Pest.php` living here. Loading the helpers from the
 * bootstrap makes the suite independent of where Pest thinks it is.
 *
 * PHPUnit no longer publishes its assertions as global functions, so the three
 * names this suite is written against are bound to the static facade here.
 */
if (!function_exists('assertSame')) {
    function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        \PHPUnit\Framework\Assert::assertSame($expected, $actual, $message);
    }
}

if (!function_exists('skip')) {
    function skip(string $reason): void
    {
        \PHPUnit\Framework\Assert::markTestSkipped($reason);
    }
}

if (!function_exists('assertStringContains')) {
    function assertStringContains(string $needle, string $haystack, string $message = ''): void
    {
        \PHPUnit\Framework\Assert::assertStringContainsString($needle, $haystack, $message);
    }
}

if (!function_exists('assertStringNotContains')) {
    function assertStringNotContains(string $needle, string $haystack, string $message = ''): void
    {
        \PHPUnit\Framework\Assert::assertStringNotContainsString($needle, $haystack, $message);
    }
}

/**
 * Stands in for `EvolutionCMS\Core` so the package's `evo()` calls resolve.
 *
 * When the autoloader is borrowed from a core, that core's
 * `functions/preload.php` has already defined the real `evo()`, which reflects
 * `EVO_CLASS` and then caches the result in `global $evo`. So the double is
 * installed by writing that global rather than by shadowing the function, and
 * `useFakeEvo()` replaces it per test the way it always did.
 */
final class FakeEvolutionCore
{
    /** @param array<string, mixed> $documentObject */
    public function __construct(
        public array $documentObject = [],
        private array $config = [],
        private array $chunks = [],
        public array $placeholders = [],
        private array $documents = [],
        private array $snippets = [],
    ) {
    }

    /** The seam the core's `evo()` reflects on. */
    public function getInstance(): self
    {
        return $this;
    }

    /**
     * The container lookups the engine reaches for through Laravel's helpers.
     *
     * `storage_path()` is the only one it uses, to place Latte's compiled
     * template cache; it is answered with a throwaway directory so a test run
     * cannot write into the site.
     */
    public function make(string $abstract, array $parameters = []): mixed
    {
        return $abstract === 'path.storage' ? ALATTEX_TEST_STORAGE : null;
    }

    public function getConfig(string $name): mixed
    {
        return $this->config[$name] ?? null;
    }

    public function getChunk(string $name): string
    {
        return $this->chunks[$name] ?? '';
    }

    /**
     * A snippet run from inside the Latte pass, the way a template asks for
     * data rather than for markup - see demo/snippets/rows.php.
     *
     * The double is a map rather than an evaluator on purpose: what these tests
     * cover is the template's use of the value, and the CMS is what runs the
     * PHP. A callable entry is called so a fixture can react to its parameters.
     *
     * @param array<string, mixed> $params
     */
    public function runSnippet(string $name, array $params = []): mixed
    {
        $snippet = $this->snippets[$name] ?? '';

        return is_callable($snippet) ? $snippet($params) : $snippet;
    }

    /**
     * The two URL helpers a template needs to link to another document without
     * assuming how the site spells its URLs. Answered from $documents, keyed by
     * alias, so a template asking for a link gets one.
     */
    public function getIdFromAlias(string $alias): int
    {
        return $this->documents[$alias] ?? 0;
    }

    public function makeUrl(int $id, string $alias = '', string $args = '', string $scheme = ''): string
    {
        return '/index.php?id=' . $id . ($args !== '' ? '&' . ltrim($args, '&') : '');
    }
}

/**
 * A throwaway storage root, for the compiled-template cache the engine writes.
 * Torn down at shutdown so a failed run leaves nothing behind.
 */
$storage = str_replace(chr(92), '/', sys_get_temp_dir()) . '/alattex-tests-' . getmypid();
@mkdir($storage . '/framework/cache/latte', 0775, true);

define('ALATTEX_TEST_STORAGE', $storage);

register_shutdown_function(static function () use ($storage): void {
    $remove = static function (string $path) use (&$remove): void {
        if (!is_dir($path)) {
            @unlink($path);
            return;
        }

        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            $remove($path . '/' . $entry);
        }

        @rmdir($path);
    };

    $remove($storage);
});

foreach ([
    'EVO_CLASS' => FakeEvolutionCore::class,
    // The three the core's `evo()` guards its CSRF check behind.
    'IN_MANAGER_MODE' => false,
    'IN_INSTALL_MODE' => false,
    'EVO_API_MODE' => false,
] as $constant => $value) {
    if (!defined($constant)) {
        define($constant, $value);
    }
}

// Only reached when there is no core to borrow one from.
if (!function_exists('evo')) {
    function evo(): FakeEvolutionCore
    {
        return $GLOBALS['evo'];
    }
}

/**
 * @param array<string, mixed> $documentObject
 * @param array<string, mixed> $config
 * @param array<string, string> $chunks
 * @param array<string, string> $placeholders
 * @param array<string, int> $documents alias => id
 */
function useFakeEvo(
    array $documentObject = [],
    array $config = [],
    array $chunks = [],
    array $placeholders = [],
    array $documents = [],
    array $snippets = [],
): FakeEvolutionCore {
    return $GLOBALS['evo'] = new FakeEvolutionCore(
        $documentObject,
        $config,
        $chunks,
        $placeholders,
        $documents,
        $snippets,
    );
}
