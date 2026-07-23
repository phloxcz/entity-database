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

/**
 * MySQL has no native boolean type — override convertType() here for
 * further MySQL-specific quirks when the need arises.
 */
class MysqlGeneratorAdapter extends GenericGeneratorAdapter
{
    public function convertType(string $nativeType, array $vendor): string
    {
        // BOOLEAN/BOOL is silently rewritten to TINYINT(1) by MySQL itself —
        // SHOW FULL COLUMNS then reports it as 'tinyint(1)'. Treating exactly
        // that width as bool (and wider tinyints as int) is the de-facto
        // convention used by Doctrine, Laravel, etc.
        if (strtolower($nativeType) === 'tinyint' && preg_match('/^tinyint\(1\)$/i', (string) ($vendor['type'] ?? ''))) {
            return 'bool';
        }

        return parent::convertType($nativeType, $vendor);
    }
}
