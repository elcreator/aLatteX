<?php
/**
 * aLatteXDemoClock - the smallest possible snippet, used to show the
 * difference between the two call syntaxes on one page:
 *
 *   [[aLatteXDemoClock]]   cacheable     - frozen into the page cache
 *   [!aLatteXDemoClock!]   non-cacheable - re-evaluated on every request
 *
 * Reload a demo page twice with caching on and only the second line moves.
 *
 * @var \EvolutionCMS\Core $modx
 * @var string|null $format
 */

return date((string) ($format ?? 'H:i:s'), time());
