<?php

namespace Elcreator\aLatteX;

use Latte\Extension;
use Latte\Runtime\Html;

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
    public function chunk(string $name): Html
    {
        return new Html((string) evo()->getChunk($name));
    }

    /**
     * Return a cacheable snippet EVO tag so it is processed after Latte.
     * Parameters are passed as `key => value` pairs.
     *
     * @param array<string, string> $params
     */
    public function snippet(string $name, array $params = []): Html
    {
        return new Html('[[' . $this->assertName($name) . $this->buildParamString($params) . ']]');
    }

    /**
     * Return a non-cacheable snippet EVO tag so it is processed after Latte.
     *
     * @param array<string, string> $params
     */
    public function uncachedSnippet(string $name, array $params = []): Html
    {
        return new Html('[!' . $this->assertName($name) . $this->buildParamString($params) . '!]');
    }

    /**
     * Return a raw template variable / document field value.
     *
     * A TV sits in documentObject as [name, value, display, display_params,
     * type], so the value has to be picked out of it - see DocumentObject.
     */
    public function tv(string $name): string
    {
        return (string) DocumentObject::value($name, evo()->documentObject[$name] ?? '');
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

    /**
     * Refuse a name that is not an element name.
     *
     * These helpers emit EVO syntax as raw Html, so a name assembled from a
     * request value could otherwise carry the rest of a tag with it.
     */
    private function assertName(string $name): string
    {
        if (!EvoSyntaxBridge::isElementName($name)) {
            throw new \InvalidArgumentException(
                'aLatteX: "' . $name . '" is not a valid Evolution CMS element name.'
            );
        }

        return $name;
    }

    /**
     * Build `?&key=`value`` pairs, with the tag delimiters removed.
     *
     * EVO delimits a parameter value with backticks and has no escape for
     * one, so a value containing a backtick ends the value early and the rest
     * of it is parsed as tag syntax: `['id' => '`]] [[Other']` would close the
     * call and open another. Since the result is returned as Html and reaches
     * the CMS parser unescaped, the delimiters are stripped rather than
     * trusted - both the backtick and the sequences that open or close a tag.
     *
     * Parameter *names* go through the element-name test for the same reason.
     *
     * @param array<string, string> $params
     */
    private function buildParamString(array $params): string
    {
        $out = '';

        foreach ($params as $key => $value) {
            $out .= '&' . $this->assertName((string) $key) . '=`' . self::sanitiseValue((string) $value) . '`';
        }

        return $out ? '?' . $out : '';
    }

    /** Remove everything that could end the value or start another tag. */
    private static function sanitiseValue(string $value): string
    {
        return str_replace(
            ['`', '[[', ']]', '[!', '!]', '{{', '}}'],
            '',
            $value,
        );
    }
}
