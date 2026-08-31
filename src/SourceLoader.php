<?php

declare(strict_types=1);

namespace Elcreator\aLatteX;

use Latte\Loader;
use Latte\TemplateNotFoundException;

/**
 * Holds the template sources of the current request under names of our choosing.
 *
 * Latte's own StringLoader, constructed with null, answers getContent($name)
 * with $name itself - so a template's *name* is its entire source code. That is
 * invisible until something prints the name: Tracy's Latte panel puts it in a
 * table cell, and a CompileException carries it as sourceName. A whole template
 * in a bar-panel row is unreadable, so the name is separated from the source
 * here.
 *
 * getUniqueId() still returns the source, which is what keeps the compiled-cache
 * key correct: two revisions of one template share a name but not a cache entry,
 * and an edit is picked up on the next request without the name having to carry
 * a hash. This is also what Latte's StringLoader does in its array mode - the
 * difference is only that this one can be added to after construction, so the
 * engine stays a singleton across renders.
 */
final class SourceLoader implements Loader
{
    /** @var array<string, string> name => template source */
    private array $sources = [];

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
        if (!isset($this->sources[$name])) {
            throw new TemplateNotFoundException("Missing template '$name'.");
        }

        return $this->sources[$name];
    }

    /**
     * aLatteX renders one template at a time and there is no directory to
     * resolve a second name against, so {extends}, {import}, {embed} and
     * {include 'other'} have nothing to reach - exactly as with the StringLoader
     * this replaces, which throws here too. Only the message is better.
     */
    public function getReferredName(string $name, string $referringName): string
    {
        throw new TemplateNotFoundException(
            "Missing template '$name'. aLatteX renders a single template, so '$referringName' "
            . 'cannot include, extend or import another one; see docs/latte-syntax.md.'
        );
    }

    public function getUniqueId(string $name): string
    {
        return $this->getContent($name);
    }
}
