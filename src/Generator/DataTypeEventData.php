<?php
/**
 * This file is part of phloxcz/entity-database.
 *
 * (c) Phlox <https://github.com/phloxcz>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace Phlox\EntityDatabase\Generator;

use Nette;

/**
 * @property-read string $databaseIdent
 * @property-read string $table
 * @property-read string $field
 * @property string $dataType
 * @property-read array $vendor 
 */
class DataTypeEventData
{
    use Nette\SmartObject;
    
    public function __construct(
            private string $databaseIdent,
            private string $table,
            private string $field,
            private string $dataType,
            private array $vendor
        )
    {
    }    
     
    public function getDatabaseIdent(): string
    {
        return $this->databaseIdent;
    }

    public function getTable(): string
    {
        return $this->table;
    }

    public function getField(): string
    {
        return $this->field;
    }
    
    public function getDataType(): string
    {
        return $this->dataType;
    }

    public function getVendor(): array
    {
        return $this->vendor;
    }

    
    public function setDataType(string $dataType): self
    {
        $this->dataType = $dataType;
        return $this;
    }


    
}