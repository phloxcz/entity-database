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

class GenericGeneratorAdapter implements GeneratorAdapter
{
    
    protected static $typeMap = [
        // int
        'int' => 'int',
        'int unsigned' => 'int',
        'integer' => 'int',
        'int4' => 'int',
        'int8' => 'int',
        'int16' => 'int',
        'int32' => 'int',
        'int64' => 'int',
        'tinyint' => 'int',
        'tinyint unsigned' => 'int',
        'smallint' => 'int',
        'smallint unsigned' => 'int',
        'mediumint' => 'int',
        'mediumint unsigned' => 'int',
        'bigint' => 'int',
        'bigint unsigned' => 'int',
        'serial' => 'int',
        'bigserial' => 'int',
        'long' => 'int',
        'short' => 'int',
        
        // float
        'float' => 'float',
        'numeric' => 'float',
        'decimal' => 'float',
        'real' => 'float',
        'double' => 'float',
        'double precision' => 'float',
        
        // bool
        'bool' => 'bool',
        'boolean' => 'bool',
        'bit' => 'bool',
        
        // string
        'char' => 'string',
        'character' => 'string',
        'varchar' => 'string',
        'character varyinging' => 'string',
        'tinytext' => 'string',
        'text' => 'string',
        'mediumtext' => 'string',
        'longtext' => 'string',
        'binary' => 'string',
        'varbinary' => 'string',
        'enum' => 'string',
        'varying' => 'string',
        'tinyblob' => 'string',
        'blob' => 'string',
        'mediumblob' => 'string',
        'longblob' => 'string',
        'xml' => 'string',
        
        // stream resource
        'bytea' => 'object',
        
        // DateTime
        'datetime' => '\DateTime',
        'date' => '\DateTime',
        'timestamp' => '\DateTime',
        'time' => '\DateTime',
        
        // JSON JSONb
        'json' => 'object',
        'jsonb' => 'object',

        // Multi-valued column types (MySQL SET; Postgres arrays are handled
        // by PgsqlGeneratorAdapter, which detects the '_'-prefixed native name)
        'set' => 'array',
    ];

    public function convertType(string $nativeType, array $vendor): string
    {
        $t = strtolower($nativeType ?? 'string');
        // Unknown native type (a vendor-specific one we haven't mapped, e.g. a
        // future MySQL/Postgres addition) falls back to 'string' rather than
        // the raw native type name — the latter is never a valid PHP type and
        // would break the generated class.
        return self::$typeMap[$t] ?? 'string';
    }

    public function getDatabaseIdent(): string
    {
        $nameParts = explode('\\', get_called_class());
        return str_replace('generatoradapter', '', strtolower(end($nameParts)));
    }
}