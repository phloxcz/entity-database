<?php
/**
 * This file is part of phloxcz/entity-database.
 *
 * (c) Phlox <https://github.com/phloxcz>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */
namespace Phlox\EntityDatabase\Entity\Attributes;

use Attribute;

use Nette;

/**
 * Property attribute
 * 
 * @property-read string $col
 * @property-read bool $key
 * @property-read bool $nullable
 * @property-read bool $searchable
 * @property-read string $format
 * @property-read string $nativeType
 * @property-read string $type
 * @property-read string $ref
 * @property-read string $related
 * @property-read string $through
 * @property-read string $condition
 * @property-read bool $virtual
 * @property-read string $json
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Property
{
    use Nette\SmartObject;
    
    private ?string $col;
    
    private bool $key;
    
    private bool $nullable;
    
    private bool $searchable;
    
    private ?string $format;
    
    private ?string $nativeType;
       
    
    private string $type;
            
    
    private ?string $ref;
    
    private ?string $related;
    
    private ?string $through;
    
    private ?string $condition;
        
    private bool $virtual;
    
    private ?string $json;


    public function __construct(
        ?string $col = null,
        bool $key = false,
        bool $nullable = true,
        bool $searchable = false,
        ?string $format = null,
        ?string $nativeType = null,
        string $type = 'string',
        ?string $ref = null,
        ?string $related = null,
        ?string $through = null,
        ?string $condition = null,
        bool $virtual = false,
        ?string $json = null
    )
    {
        $this->col = $col;
        $this->key = $key;
        $this->nullable = $nullable;
        $this->searchable = $searchable;
        $this->format = $format;
        $this->nativeType = $nativeType;
        
        $this->type = $type;
        
        $this->ref = $ref;
        $this->related = $related;
        $this->through = $through;
        $this->condition = $condition;

        $this->virtual = $virtual;

        $this->json = $json;
    }

    public function getCol(): ?string
    {
        // is it ref / related object?
        if(isset($this->through)) {
            return (isset($this->ref) ? "ref@{$this->ref}" : "related@{$this->related}").":{$this->through}";
        }
        
        // is it direct property?
        return $this->col;
    }
 
    public function isKey(): bool
    {
        return $this->key;
    }
 
    public function isNullable(): bool
    {
        return $this->nullable;
    }
 
    public function isSearchable(): bool
    {
        return $this->searchable;
    }

    public function getFormat(): ?string
    {
        return $this->format;
    }

    public function getNativeType(): ?string
    {
        return $this->nativeType;
    }

    
    
    public function getType(): string
    {
        return $this->type;
    }
    
    
    
    
    public function getRef(): ?string
    {
        return $this->ref;
    }
    
    public function getRelated(): ?string
    {
        return $this->related;
    }
    
    public function getThrough(): ?string
    {
        return $this->through;
    }

    public function getCondition(): ?string
    {
        return $this->condition;
    }
    
    
    public function isVirtual(): bool
    {
        return $this->virtual;
    }

    public function getJson(): ?string
    {
        return $this->json;
    }
    
    
    public function getProps(): array
    {
        return [
            'col' => $this->getCol(),
            'key' => $this->isKey(),
            'nullable' => $this->isNullable(),
            'searchable' => $this->isSearchable(),
            'format' => $this->getFormat(),
            'nativeType' => $this->getNativeType(),
            'type' => $this->getType(),
            'ref' => $this->getRef(),
            'related' => $this->getRelated(),
            'through' => $this->getThrough(),
            'condition' => $this->getCondition(),
            'virtual' => $this->isVirtual(),
            'json' => $this->getJson()
        ];
    }
}