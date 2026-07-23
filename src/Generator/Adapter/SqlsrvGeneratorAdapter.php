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
 * SQL Server-specific type mapping. Class name matches the 'sqlsrv:' DSN
 * prefix (EntityGenerator resolves <ucfirst(dsn-scheme)>GeneratorAdapter) —
 * the modern, actively maintained pdo_sqlsrv driver. The legacy 'mssql:'
 * DSN scheme (old pdo_mssql, long removed from PHP) is not handled here.
 */
class SqlsrvGeneratorAdapter extends GenericGeneratorAdapter
{
    protected static $typeMap = [
        // Unicode string types — GenericGeneratorAdapter only knows the non-'n' variants
        'nvarchar' => 'string',
        'nchar'    => 'string',
        'ntext'    => 'string',

        // Higher-precision date/time types (recommended over legacy datetime/smalldatetime)
        'datetime2'     => '\DateTime',
        'smalldatetime' => '\DateTime',
        'datetimeoffset' => '\DateTime',

        // SQL Server-specific scalar types
        'uniqueidentifier' => 'string',
        'money'            => 'float',
        'smallmoney'       => 'float',
        'xml'              => 'string',
    ];

    public function convertType(string $nativeType, array $vendor): string
    {
        $t = strtolower($nativeType);
        return self::$typeMap[$t] ?? parent::convertType($nativeType, $vendor);
    }
}
