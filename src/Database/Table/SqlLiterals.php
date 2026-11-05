<?php
/**
 * This file is part of phloxcz/entity-database.
 *
 * (c) Phlox <https://github.com/phloxcz>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Phlox\EntityDatabase\Database\Table;

/**
 * Nette takes a lowercase word for an identifier even inside a string, so it turns
 * `status <> 'cancelled'` into `status <> '[cancelled]'`, which is true for every row.
 * Strings moved to parameters are safe from that.
 */
final class SqlLiterals
{
    /**
     * @return array{string, list<string>} The condition with `?` for its strings, and the strings
     */
    public static function extract(string $condition): array
    {
        $params = [];

        $sql = preg_replace_callback(
            "/'((?:[^']|'')*)'/",
            static function (array $matches) use (&$params): string {
                $params[] = str_replace("''", "'", $matches[1]);
                return '?';
            },
            $condition,
        );

        return [$sql ?? $condition, $params];
    }
}
