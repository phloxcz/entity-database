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

final class EntityRefDefinition
{
    public function __construct(
        public readonly string $column,
        public readonly string $ref,
        public readonly string $type,
        public readonly string $name,
        public readonly ?string $comment = null,
    )
    {
    }
}
