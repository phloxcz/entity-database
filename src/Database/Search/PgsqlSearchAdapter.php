<?php
/**
 * This file is part of phloxcz/entity-database.
 *
 * (c) Phlox <https://github.com/phloxcz>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace Phlox\EntityDatabase\Database\Search;

/**
 * Postgres: case-insensitive via the native ILIKE operator. Diacritics-
 * insensitive search requires the `unaccent` extension to already be enabled
 * on the target database (`CREATE EXTENSION IF NOT EXISTS unaccent;`,
 * typically needs superuser once per database) — if it isn't, Postgres
 * raises a clear "function unaccent(text) does not exist" error.
 */
class PgsqlSearchAdapter implements SearchAdapter
{
    public function buildCondition(string $column, string $term, bool $ignoreDiacritics): array
    {
        if ($ignoreDiacritics) {
            return ["unaccent({$column}) ILIKE unaccent(?)", ['%' . $term . '%']];
        }

        return ["{$column} ILIKE ?", ['%' . $term . '%']];
    }
}
