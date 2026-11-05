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

namespace Phlox\EntityDatabase\Database\Search;

use Nette\Database\Connection;

/**
 * Builds the condition of a search: every word of a term must appear in the same
 * column, and the columns are alternatives.
 */
final class SearchCondition
{
    /**
     * Separators folded to spaces before splitting a term into words — so e.g.
     * "BDP-International" also matches a DB value stored as "BDP International"
     * (the hyphen would otherwise force an exact, unlikely-to-match substring).
     * The wildcards of LIKE are among them, so a client can't use them.
     */
    private const SpecialChars = [
        ':',';','-','.',',','_','[',']',
        '(',')','{','}','/','*','+','=',
        '|','\\','\'','"','<','>','?','!',
        '@','&','~','`','–','—','%',
        '„','“','‘','’','”','‚'
    ];

    /**
     * @param  list<string>  $columns
     * @return array{string, list<mixed>}|null  SQL and its parameters; null when the term holds no word
     */
    public static function build(Connection $connection, array $columns, string $term, bool $ignoreDiacritics = false): ?array
    {
        $words = self::tokenize($term);
        if (!$words) {
            return null;
        }

        $adapter    = self::resolveAdapter($connection);
        $driver     = $connection->getDriver();
        $conditions = [];
        $params     = [];

        foreach ($columns as $column) {
            $columnDb       = $driver->delimite($column);
            $wordConditions = [];
            foreach ($words as $word) {
                [$sql, $conditionParams] = $adapter->buildCondition($columnDb, $word, $ignoreDiacritics);
                $wordConditions[] = $sql;
                array_push($params, ...$conditionParams);
            }
            $conditions[] = '(' . implode(' AND ', $wordConditions) . ')';
        }

        return ['(' . implode(' OR ', $conditions) . ')', $params];
    }

    /** @return list<string> */
    private static function tokenize(string $term): array
    {
        if (preg_match('#^[' . preg_quote(implode('', self::SpecialChars), '#') . ']+$#u', $term)) {
            return [$term];
        }

        $normalized = str_replace(self::SpecialChars, ' ', $term);

        return array_values(array_filter(explode(' ', $normalized), static fn(string $w) => $w !== ''));
    }

    private static function resolveAdapter(Connection $connection): SearchAdapter
    {
        $type  = explode(':', $connection->getDsn())[0];
        $class = __NAMESPACE__ . '\\' . ucfirst($type) . 'SearchAdapter';

        return class_exists($class) ? new $class() : new GenericSearchAdapter();
    }
}
