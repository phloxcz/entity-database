<?php
/**
 * This file is part of phloxcz/entity-database.
 *
 * (c) Phlox <https://github.com/phloxcz>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace Phlox\EntityDatabase\Generator\Adapter;

interface GeneratorAdapter
{
    /**
     * Convert DB type from Nette Database Reflection to PHP type
     */
    public function convertType(string $nativeType, array $vendor): string;

    /**
     * Returns database ident (mysql, pgsql ...)
     */
    public function getDatabaseIdent(): string;
}