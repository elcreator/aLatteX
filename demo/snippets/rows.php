<?php
/**
 * aLatteXDemoRows - a snippet that returns DATA, not markup.
 *
 * Every other snippet in this set returns a string, because that is what a
 * snippet called as [[name]] has to do: the parser splices its return value
 * into the page. This one is called the other way round - from inside the Latte
 * pass, by the template, before there is a page to splice anything into:
 *
 *     {var $rows = $evo->runSnippet('aLatteXDemoRows', ['parent' => $parent])}
 *     {foreach $rows as $row} ... {/foreach}
 *
 * That works because Core::evalSnippet() hands an array or an object straight
 * back to the caller and only stringifies everything else. So a snippet can be
 * a query, Latte can be the loop, and the data a real site keeps in the
 * database reaches the template as the array it is.
 *
 * Note what is NOT used here: [[aLatteXDemoRows]] and evoSnippet(). Both defer
 * to the Evolution CMS parser, which runs after Latte has finished - by then
 * the template cannot loop over anything. See docs/interop.md.
 *
 * Parameters:
 *   &parent `3`   document whose published children to return; the current
 *                 document's parent by default
 *   &limit  `10`  how many rows at most
 *
 * @var \EvolutionCMS\Core $modx
 * @var string|int|null $parent
 * @var string|int|null $limit
 * @return array<int, array{id: int, title: string, body: string, url: string}>
 */

$parent = isset($parent) && $parent !== ''
    ? (int) $parent
    : (int) ($modx->documentObject['parent'] ?? 0);

$limit = max(1, min(50, (int) ($limit ?? 10)));

if (!class_exists(\EvolutionCMS\Models\SiteContent::class)) {
    return [];
}

$documents = \EvolutionCMS\Models\SiteContent::query()
    ->select('id', 'pagetitle', 'longtitle', 'introtext', 'description', 'menuindex')
    ->where('parent', $parent)
    ->where('published', 1)
    ->where('deleted', 0)
    ->orderBy('menuindex')
    ->limit($limit)
    ->get();

$rows = [];

foreach ($documents as $document) {
    $title = (string) $document->longtitle !== ''
        ? (string) $document->longtitle
        : (string) $document->pagetitle;

    $summary = (string) $document->introtext !== ''
        ? (string) $document->introtext
        : (string) $document->description;

    $rows[] = [
        'id' => (int) $document->id,
        'title' => $title,
        // Left raw on purpose. Latte escapes it when the template prints it,
        // which is the whole point of handing over data rather than markup.
        // Often empty in this demo set - the template is written to cope.
        'body' => $summary,
        'url' => $modx->makeUrl((int) $document->id),
    ];
}

return $rows;
