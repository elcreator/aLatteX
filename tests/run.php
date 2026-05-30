<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/** @var array<int, array{name: string, callback: callable}> $tests */
$tests = [];
$skipped = [];

function test(string $name, callable $callback): void
{
    global $tests;
    $tests[] = ['name' => $name, 'callback' => $callback];
}

function skip(string $reason): void
{
    throw new RuntimeException('__SKIP__' . $reason);
}

function assertSame(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            ($message !== '' ? $message . "\n" : '')
            . "Expected:\n" . var_export($expected, true)
            . "\nActual:\n" . var_export($actual, true)
        );
    }
}

function assertStringContains(string $needle, string $haystack, string $message = ''): void
{
    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException(
            ($message !== '' ? $message . "\n" : '')
            . "Expected output to contain:\n{$needle}\nActual:\n{$haystack}"
        );
    }
}

function assertStringNotContains(string $needle, string $haystack, string $message = ''): void
{
    if (str_contains($haystack, $needle)) {
        throw new RuntimeException(
            ($message !== '' ? $message . "\n" : '')
            . "Expected output not to contain:\n{$needle}\nActual:\n{$haystack}"
        );
    }
}

foreach ([
    __DIR__ . '/Unit/EvoSyntaxBridgeTest.php',
    __DIR__ . '/Unit/EvoExtensionTest.php',
    __DIR__ . '/Integration/LattexEngineTest.php',
] as $file) {
    require $file;
}

$failures = [];

foreach ($tests as $case) {
    try {
        $case['callback']();
        fwrite(STDOUT, '.');
    } catch (RuntimeException $e) {
        if (str_starts_with($e->getMessage(), '__SKIP__')) {
            $skipped[] = [$case['name'], substr($e->getMessage(), 8)];
            fwrite(STDOUT, 'S');
            continue;
        }

        $failures[] = [$case['name'], $e];
        fwrite(STDOUT, 'F');
    } catch (Throwable $e) {
        $failures[] = [$case['name'], $e];
        fwrite(STDOUT, 'E');
    }
}

fwrite(STDOUT, PHP_EOL . PHP_EOL);

foreach ($skipped as [$name, $reason]) {
    fwrite(STDOUT, "Skipped: {$name} ({$reason})" . PHP_EOL);
}

foreach ($failures as [$name, $failure]) {
    fwrite(STDERR, "Failed: {$name}" . PHP_EOL);
    fwrite(STDERR, $failure->getMessage() . PHP_EOL . PHP_EOL);
}

$total = count($tests);
$failed = count($failures);
$skipCount = count($skipped);
$passed = $total - $failed - $skipCount;

fwrite(
    $failed > 0 ? STDERR : STDOUT,
    "Tests: {$total}, Passed: {$passed}, Skipped: {$skipCount}, Failed: {$failed}" . PHP_EOL
);

exit($failed > 0 ? 1 : 0);
