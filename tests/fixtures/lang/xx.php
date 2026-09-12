<?php

/**
 * A deliberately INCOMPLETE locale, for the tests.
 *
 * `xx` is a private-use-shaped tag that no real site will have, so this cannot
 * be mistaken for a shipped translation — and it lives under tests/ rather than
 * core/lang/ so the completeness test, which walks the product's catalogues,
 * does not see it and fail.
 *
 * `only.in_base` is missing ON PURPOSE. That is the whole point of the file.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'both.translated' => 'Traducido en ambos',
    'with.placeholder' => 'Hola :name',
];
