<?php

namespace Elcreator\aLatteX;

use Illuminate\Contracts\View\Engine;

/**
 * Renders a .latte file for Laravel's view factory.
 *
 * Registering this makes the CMS's template-file feature work for Latte the way
 * it works for Blade: a template whose alias resolves to /views/<alias>.latte is
 * rendered from that file, and the parser is skipped for it - see
 * EvolutionCMS\TemplateProcessor::getBladeDocumentContent().
 */
class LatteViewEngine implements Engine
{
    public function __construct(private LattexEngine $engine)
    {
    }

    /**
     * @param  string               $path
     * @param  array<string, mixed> $data
     * @return string
     */
    public function get($path, array $data = [])
    {
        return $this->engine->renderView((string) $path, $data);
    }
}
