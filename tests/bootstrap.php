<?php

declare(strict_types=1);

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
}

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

require_once dirname(__DIR__) . '/src/EvoSyntaxBridge.php';
require_once dirname(__DIR__) . '/src/EvoExtension.php';

if (class_exists(\Latte\Engine::class)) {
    require_once dirname(__DIR__) . '/src/LattexEngine.php';
}

final class FakeEvolutionCore
{
    /** @param array<string, mixed> $documentObject */
    public function __construct(
        public array $documentObject = [],
        private array $config = [],
        private array $chunks = [],
        public array $placeholders = [],
    ) {
    }

    public function getConfig(string $name): mixed
    {
        return $this->config[$name] ?? null;
    }

    public function getChunk(string $name): string
    {
        return $this->chunks[$name] ?? '';
    }
}

function evo(): FakeEvolutionCore
{
    return $GLOBALS['evo_test_core'];
}

/**
 * @param array<string, mixed> $documentObject
 * @param array<string, mixed> $config
 * @param array<string, string> $chunks
 * @param array<string, string> $placeholders
 */
function useFakeEvo(
    array $documentObject = [],
    array $config = [],
    array $chunks = [],
    array $placeholders = [],
): FakeEvolutionCore {
    return $GLOBALS['evo_test_core'] = new FakeEvolutionCore(
        $documentObject,
        $config,
        $chunks,
        $placeholders,
    );
}
