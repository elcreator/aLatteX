<?php

declare(strict_types=1);

/**
 * `composer demo:install` / `composer demo:remove`.
 *
 * A composer script runs in the package directory, and a plugin's package
 * directory is somewhere inside an Evolution CMS tree - core/vendor/<vendor>/
 * <package> when composer installed it, core/custom/packages/<slug> when the
 * CI pipeline or the CMS's own installer put it there. Both are three levels
 * below core/, but rather than rely on that this walks up looking for an
 * artisan binary, so a fourth layout would work too.
 *
 * Set EVO_CORE_PATH to skip the search:
 *
 *   EVO_CORE_PATH=/srv/site/core composer demo:install
 *
 * Everything after the sub-command is passed through to artisan, so
 * `composer demo:install -- --force` works.
 */

$command = $argv[1] ?? '';

if (!in_array($command, ['install', 'remove'], true)) {
    fwrite(STDERR, "usage: php bin/alattex-demo.php install|remove [artisan options]\n");
    exit(2);
}

$artisan = locateArtisan();

if ($artisan === null) {
    fwrite(STDERR, <<<TEXT
    aLatteX demo: no Evolution CMS installation found around this package.

    The demo installs pages, templates, chunks, snippets and TVs into a site's
    database, so it needs a site. Install this plugin into an Evolution CMS
    tree and run it from there:

        php artisan alattex:demo:$command

    or point the script at the core directly:

        EVO_CORE_PATH=/path/to/site/core composer demo:$command

    To exercise the same fixtures without a CMS, run the test suite instead -
    tests/Integration/DemoContentTest.php renders every demo template.

    TEXT);
    exit(1);
}

$arguments = array_slice($argv, 2);

$parts = array_merge(
    [PHP_BINARY, $artisan, 'alattex:demo:' . $command],
    $arguments,
);

$line = implode(' ', array_map(static fn(string $p): string => escapeshellarg($p), $parts));

fwrite(STDOUT, '> ' . $line . PHP_EOL);

passthru($line, $status);

exit($status);

/**
 * The first artisan binary at or above this package, searching the package
 * directory's ancestors and the core/ directory beside each of them.
 */
function locateArtisan(): ?string
{
    $fromEnv = getenv('EVO_CORE_PATH');
    if (is_string($fromEnv) && $fromEnv !== '') {
        $candidate = rtrim(str_replace('\\', '/', $fromEnv), '/') . '/artisan';

        return is_file($candidate) ? $candidate : null;
    }

    $dir = str_replace('\\', '/', dirname(__DIR__));

    // Six is comfortably more than the deepest known layout
    // (<evo>/core/custom/packages/<slug>) needs.
    for ($i = 0; $i < 6; $i++) {
        foreach ([$dir . '/artisan', $dir . '/core/artisan'] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        $parent = dirname($dir);
        if ($parent === $dir) {
            break;
        }

        $dir = $parent;
    }

    return null;
}
