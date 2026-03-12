<?php

namespace Elcreator\aLatteX;

use Latte\Extension;

/**
 * Latte extension that exposes Evolution CMS helpers as native Latte functions.
 *
 * These complement (not replace) the EVO syntax bridge: authors may use either
 * the classic EVO tag syntax (preserved through the bridge) or these Latte
 * function-call equivalents for inline use.
 *
 * Available in templates:
 *   {evoChunk('name')}                       - render HTML chunk
 *   {evoSnippet('name', ['p' => 'v'])}       - inline cached snippet call (returns EVO tag)
 *   {evoUncachedSnippet('name', ['p' => 'v'])} - non-cached snippet call
 *   {evoTv('name')}                          - current document TV / field value
 *   {evoSetting('name')}                     - system setting value
 *   {evoPlaceholder('name')}                 - placeholder value
 */
class EvoExtension extends Extension
{
    public function getFunctions(): array
    {
        return [
            'evoChunk'           => [$this, 'chunk'],
            'evoSnippet'         => [$this, 'snippet'],
            'evoUncachedSnippet' => [$this, 'uncachedSnippet'],
            'evoTv'              => [$this, 'tv'],
            'evoSetting'         => [$this, 'setting'],
            'evoPlaceholder'     => [$this, 'placeholder'],
        ];
    }

    // -------------------------------------------------------------------------

    /** Render an HTML chunk by name (may include EVO syntax itself). */
    public function chunk(string $name): string
    {
        return (string) evo()->getChunk($name);
    }

    /**
     * Return a cacheable snippet EVO tag so it is processed after Latte.
     * Parameters are passed as `key => value` pairs.
     *
     * @param array<string, string> $params
     */
    public function snippet(string $name, array $params = []): string
    {
        return '[[' . $name . $this->buildParamString($params) . ']]';
    }

    /**
     * Return a non-cacheable snippet EVO tag so it is processed after Latte.
     *
     * @param array<string, string> $params
     */
    public function uncachedSnippet(string $name, array $params = []): string
    {
        return '[!' . $name . $this->buildParamString($params) . '!]';
    }

    /** Return a raw template variable / document field value. */
    public function tv(string $name): string
    {
        return (string) (evo()->documentObject[$name] ?? '');
    }

    /** Return a system configuration setting value. */
    public function setting(string $name): string
    {
        return (string) evo()->getConfig($name);
    }

    /** Return a placeholder value previously set via evo()->setPlaceholder(). */
    public function placeholder(string $name): string
    {
        return (string) (evo()->placeholders[$name] ?? '');
    }

    // -------------------------------------------------------------------------

    /** @param array<string, string> $params */
    private function buildParamString(array $params): string
    {
        $out = '';
        foreach ($params as $key => $value) {
            $out .= '&' . $key . '=`' . $value . '`';
        }
        return $out ? '?' . $out : '';
    }
}
