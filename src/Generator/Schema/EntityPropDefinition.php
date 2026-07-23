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

namespace Phlox\EntityDatabase\Generator\Schema;

final class EntityPropDefinition
{
    public function __construct(
        public readonly ?string $column,
        public readonly bool $nullable,
        public readonly bool $key,
        public readonly string $type,
        public readonly ?string $nativeType,
        public readonly string $name,
        public readonly string $access = 'rw',
        public readonly bool $searchable = false,
        public readonly ?string $format = null,
        public readonly ?string $comment = null,
        public readonly ?string $json = null,
        public readonly bool $virtual = false
    )
    {
    }
}
