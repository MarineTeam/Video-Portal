<?php

/**
 * A base catalogue for the tests, NOT for the product.
 *
 * This exists because the real catalogues are complete — the completeness test
 * enforces it — so no real key ever exercises the base-locale FALLBACK, which
 * is the most important behaviour in Translator and was unconstrained until a
 * mutation made it return an empty string and nothing failed.
 *
 * A partially translated locale is the NORMAL state of a site adding a
 * language, so the path has to be tested even though the shipped catalogues
 * will never take it.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'both.translated' => 'Translated in both',
    'only.in_base'    => 'Only in the base',
    'with.placeholder' => 'Hello :name',
];
