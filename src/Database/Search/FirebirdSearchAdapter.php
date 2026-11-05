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

use Phlox\EntityDatabase\Exception\SearchException;

/**
 * Firebird: CONTAINING ignores case and takes no wildcards. The column is cast to
 * a text BLOB, because Firebird otherwise sizes the parameter by the column and
 * refuses a term longer than it.
 *
 * Ignoring accents depends on the collation of a column, which can't be stated
 * here for any character set, so it's left to the database.
 */
class FirebirdSearchAdapter implements SearchAdapter
{
    public function buildCondition(string $column, string $term, bool $ignoreDiacritics): array
    {
        if ($ignoreDiacritics) {
            throw new SearchException(
                'Diacritics-insensitive search is not supported for Firebird. Use a collation ignoring accents on the column, such as UNICODE_CI_AI.',
                SearchException::DiacriticsNotSupported,
            );
        }

        // In brackets, because Nette doesn't know CONTAINING and would add "=" before a bare "?"
        return ["CAST({$column} AS BLOB SUB_TYPE TEXT) CONTAINING (?)", [$term]];
    }
}
