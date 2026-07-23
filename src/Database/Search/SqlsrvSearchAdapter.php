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
 * SQL Server: diacritics-insensitive search coerces a CI_AI (case-insensitive,
 * accent-insensitive) collation directly in the query, independent of the
 * column's own collation.
 *
 * The collation name is written in UPPERCASE deliberately: Nette's
 * Selection::where() auto-delimits (bracket-quotes) any lowercase/mixed-case
 * bareword in a raw condition string, assuming it's an unquoted column name —
 * which corrupts a collation name (SQL Server rejects a bracket-quoted
 * identifier there). Fully-uppercase tokens are left alone, and SQL Server
 * collation names are case-insensitive.
 */
class SqlsrvSearchAdapter implements SearchAdapter
{
    public function buildCondition(string $column, string $term, bool $ignoreDiacritics): array
    {
        if ($ignoreDiacritics) {
            return ["{$column} COLLATE LATIN1_GENERAL_CI_AI LIKE ?", ['%' . $term . '%']];
        }

        return ["LOWER({$column}) LIKE LOWER(?)", ['%' . $term . '%']];
    }
}
