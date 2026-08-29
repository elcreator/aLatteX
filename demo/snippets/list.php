<?php
/**
 * aLatteXDemoList - the ordinary kind of Evolution CMS snippet.
 *
 * Demonstrates:
 *   - snippet parameters, the [[name? &key=`value`]] form;
 *   - reading a chunk from PHP with $modx->getChunk();
 *   - setting a placeholder that a chunk elsewhere on the page picks up
 *     as [+alx_note+].
 *
 * Parameters:
 *   &items  `a||b||c`   items to render, separated by ||
 *   &class  `alx-list`  class for the <ul>
 *   &note   `text`      value published as the [+alx_note+] placeholder
 *
 * @var \EvolutionCMS\Core $modx
 * @var string|null $items
 * @var string|null $class
 * @var string|null $note
 */

$items = array_values(array_filter(array_map(
    'trim',
    explode('||', (string) ($items ?? ''))
), static fn(string $item): bool => $item !== ''));

$class = (string) ($class ?? 'alx-list');

$modx->setPlaceholder(
    'alx_note',
    (string) ($note ?? 'Placeholder set by aLatteXDemoList, printed by aLatteXDemoHeader.')
);

if ($items === []) {
    return '<p class="alx-empty">aLatteXDemoList was called without &amp;items.</p>';
}

$out = '<ul class="' . htmlspecialchars($class, ENT_QUOTES) . '">';
foreach ($items as $i => $item) {
    $out .= '<li class="alx-item alx-item-' . ($i + 1) . '">'
        . htmlspecialchars($item, ENT_QUOTES)
        . '</li>';
}
$out .= '</ul>';

return $out;
