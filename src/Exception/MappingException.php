<?php
/**
 * This file is part of phloxcz/entity-database.
 *
 * (c) Phlox <https://github.com/phloxcz>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace Phlox\EntityDatabase\Exception;

/**
 * Mapping Exception
 */
class MappingException extends \Exception
{
    const MappingNotFound = 1;

    const ClassNotFound = 2;
}
