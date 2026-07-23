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
 * Builds a case-insensitive (optionally diacritics-insensitive) "contains"
 * condition for one already-delimited column, for use in Selection::search().
 */
interface SearchAdapter
{
    /**
     * @param  string $column          Already delimited (quoted) column identifier.
     * @param  string $term            Raw search term (not yet wrapped in %...%).
     * @param  bool   $ignoreDiacritics
     * @return array{0: string, 1: array<int, mixed>}  [SQL condition, bound params]
     *
     * @throws \Phlox\EntityDatabase\Exception\SearchException
     *         When $ignoreDiacritics is true but not supported by this adapter.
     */
    public function buildCondition(string $column, string $term, bool $ignoreDiacritics): array;
}
