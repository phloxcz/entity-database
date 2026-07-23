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
 * Portable fallback (used for SQLite and any driver without a dedicated
 * adapter): case-insensitive via LOWER(), no diacritics support.
 */
class GenericSearchAdapter implements SearchAdapter
{
    public function buildCondition(string $column, string $term, bool $ignoreDiacritics): array
    {
        if ($ignoreDiacritics) {
            throw new SearchException(
                'Diacritics-insensitive search is not supported for this database driver.',
                SearchException::DiacriticsNotSupported,
            );
        }

        return ["LOWER({$column}) LIKE LOWER(?)", ['%' . $term . '%']];
    }
}
