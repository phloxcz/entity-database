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

namespace Phlox\EntityDatabase\Generator\Naming;

final class CamelCaseNamingStrategy implements NamingStrategy
{
    public function columnToProperty(string $name): string
    {
        // SOME_NAME_FROM_DB => some_name_from_db
        if(preg_match('/^[A-Z0-9_\-]+$/', $name)) {
            $name = strtolower($name);
        }
        // some_name_from_db => someNameFromDb
        return lcfirst(str_replace('_', '', ucwords($name, '_')));
    }

    public function refToProperty(string $name): string
    {
        $n = $this->columnToProperty($name);
        if(str_ends_with(strtolower($n), 'id')) {
            return substr($n, 0, -2);
        }
        return $n.'Ref';
    }
}