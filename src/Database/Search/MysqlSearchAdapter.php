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
 * MySQL 8+: diacritics-insensitive search coerces the accent-and-case-
 * insensitive collation directly in the query — independent of whatever
 * collation the column actually uses. Requires MySQL 8+ (utf8mb4_0900_ai_ci
 * does not exist on 5.7); on older servers this fails with a clear
 * "Unknown collation" error from the database itself.
 *
 * The collation name is written in UPPERCASE deliberately: Nette's
 * Selection::where() auto-delimits (backtick-quotes) any lowercase/mixed-case
 * bareword in a raw condition string, assuming it's an unquoted column name —
 * which would corrupt a collation name. Fully-uppercase tokens are left alone
 * (treated like a keyword), and MySQL collation names are case-insensitive.
 */
class MysqlSearchAdapter implements SearchAdapter
{
    public function buildCondition(string $column, string $term, bool $ignoreDiacritics): array
    {
        if ($ignoreDiacritics) {
            return ["{$column} COLLATE UTF8MB4_0900_AI_CI LIKE ?", ['%' . $term . '%']];
        }

        return ["LOWER({$column}) LIKE LOWER(?)", ['%' . $term . '%']];
    }
}
