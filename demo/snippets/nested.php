<?php
/**
 * aLatteXDemoNested - a snippet whose output is itself made of EVO tags.
 *
 * Evolution CMS parses in passes, so tags a snippet returns are expanded by a
 * later pass. Two ways of nesting are shown side by side:
 *
 *   1. returning [[aLatteXDemoList]] and letting the parser resolve it;
 *   2. calling $modx->runSnippet() directly, which resolves immediately.
 *
 * Both are reached from a template that only ever writes [[aLatteXDemoNested]].
 *
 * @var \EvolutionCMS\Core $modx
 * @var string|null $depth
 */

$depth = (int) ($depth ?? 1);

$deferred = '[[aLatteXDemoList? &items=`deferred one||deferred two` &class=`alx-deferred`]]';

$immediate = $modx->runSnippet('aLatteXDemoList', [
    'items' => 'immediate one||immediate two',
    'class' => 'alx-immediate',
    'note'  => 'Placeholder rewritten by aLatteXDemoNested at depth ' . $depth . '.',
]);

return '<div class="alx-nested" data-depth="' . $depth . '">'
    . '<h4>Resolved by a later parse pass</h4>' . $deferred
    . '<h4>Resolved immediately via runSnippet()</h4>' . $immediate
    . '<p>A chunk fetched from PHP: ' . $modx->getChunk('aLatteXDemoBadge') . '</p>'
    . '</div>';
