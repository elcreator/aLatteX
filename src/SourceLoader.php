<?php

declare(strict_types=1);

namespace Elcreator\aLatteX;

use Latte\Loader;
use Latte\TemplateNotFoundException;

/**
 * Loads aLatteX templates from three deliberately separate namespaces:
 *
 *   - roots registered with add(), such as a database template;
 *   - flat `<alias>.latte` files in Evolution's configured /views/ roots;
 *   - `chunk:<name>` references, which opt a CMS chunk into Latte rendering.
 *
 * Latte's own StringLoader, constructed with null, answers getContent($name)
 * with $name itself - so a template's *name* is its entire source code. That is
 * invisible until something prints the name: Tracy's Latte panel puts it in a
 * table cell, and a CompileException carries it as sourceName. A whole template
 * in a bar-panel row is unreadable, so the name is separated from the source
 * here.
 *
 * getUniqueId() combines the readable name with protected source. The name
 * keeps Tracy's source mapping distinct for two files with identical contents;
 * the source makes an edit a new compiled class immediately, including during
 * a second render through the request's singleton engine.
 */
final class SourceLoader implements Loader
{
    private const CHUNK_PREFIX = 'chunk:';

    /** @var array<string, string> name => raw template source */
    private array $sources = [];

    /** @var list<string> canonical absolute view roots */
    private array $viewPaths = [];

    /**
     * @param list<string> $viewPaths
     */
    public function __construct(
        private readonly EvoSyntaxBridge $bridge,
        array $viewPaths = [],
    ) {
        foreach ($viewPaths as $path) {
            $real = realpath($path);
            if ($real !== false && is_dir($real)) {
                $this->viewPaths[] = rtrim($real, "/\\");
            }
        }
    }

    /**
     * Registers a source and returns the name to render it under.
     */
    public function add(string $name, string $source): string
    {
        $this->sources[$name] = $source;

        return $name;
    }

    public function getContent(string $name): string
    {
        if (array_key_exists($name, $this->sources)) {
            $source = $this->sources[$name];
        } elseif (str_starts_with($name, self::CHUNK_PREFIX)) {
            $source = $this->chunkContent(substr($name, strlen(self::CHUNK_PREFIX)));
        } else {
            $path = $this->confinedFile($name);
            if ($path === null) {
                throw new TemplateNotFoundException("Missing template '$name'.");
            }

            $source = @file_get_contents($path);
            if ($source === false) {
                throw new TemplateNotFoundException("Unable to read template '$name'.");
            }
        }

        return $this->bridge->protect($source);
    }

    /**
     * File names are intentionally flat and match the aliases Evolution's
     * template manager can scaffold. Chunk references use an explicit
     * namespace, so an existing {{chunk}} never silently changes meaning.
     */
    public function getReferredName(string $name, string $referringName): string
    {
        if (str_starts_with($name, self::CHUNK_PREFIX)) {
            $chunk = substr($name, strlen(self::CHUNK_PREFIX));
            $this->assertChunkName($chunk, $referringName);

            // Fail at the reference with a useful source name rather than
            // later while Latte is building the child template.
            $this->chunkContent($chunk);

            return self::CHUNK_PREFIX . $chunk;
        }

        if (!preg_match('/^[A-Za-z0-9_-]+\.latte$/D', $name)) {
            throw new TemplateNotFoundException(
                "Invalid template reference '$name' in '$referringName'. "
                . 'Use a flat <alias>.latte filename or chunk:<name>.'
            );
        }

        foreach ($this->viewPaths as $root) {
            $path = realpath($root . DIRECTORY_SEPARATOR . $name);
            if ($path !== false && is_file($path) && $this->isWithin($path, $root)) {
                return $path;
            }
        }

        throw new TemplateNotFoundException(
            "Missing template '$name' referred from '$referringName'."
        );
    }

    public function getUniqueId(string $name): string
    {
        // Calling getContent() here is load-bearing: Latte can reuse a compiled
        // class without asking for its source again. Protecting it while the
        // unique id is made registers this render's EVO tokens even on that
        // fast path. The name keeps two identical files distinct for Tracy's
        // source mapping; the content makes edits a new compiled class.
        return $name . "\0" . $this->getContent($name);
    }

    private function chunkContent(string $name): string
    {
        $this->assertChunkName($name, 'chunk reference');

        if (!function_exists('evo')) {
            throw new TemplateNotFoundException("Cannot load chunk '$name' without Evolution CMS.");
        }

        $source = evo()->getChunk($name);
        if (!is_string($source)) {
            throw new TemplateNotFoundException("Missing or disabled chunk '$name'.");
        }

        return $source;
    }

    private function assertChunkName(string $name, string $referringName): void
    {
        if ($name === ''
            || trim($name) !== $name
            || str_starts_with($name, '@')
            || preg_match('/[\x00-\x1F\x7F]/', $name)
        ) {
            throw new TemplateNotFoundException(
                "Invalid chunk reference '" . self::CHUNK_PREFIX . "$name' in '$referringName'."
            );
        }
    }

    private function confinedFile(string $name): ?string
    {
        $real = realpath($name);
        if ($real === false || !is_file($real)) {
            return null;
        }

        foreach ($this->viewPaths as $root) {
            if ($this->isWithin($real, $root)) {
                return $real;
            }
        }

        return null;
    }

    private function isWithin(string $path, string $root): bool
    {
        $path = str_replace('\\', '/', $path);
        $root = rtrim(str_replace('\\', '/', $root), '/');

        if (DIRECTORY_SEPARATOR === '\\') {
            $path = strtolower($path);
            $root = strtolower($root);
        }

        return str_starts_with($path, $root . '/');
    }
}
