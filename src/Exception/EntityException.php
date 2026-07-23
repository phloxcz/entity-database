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
 * Entity Exception
 */
class EntityException extends \Exception
{
    const INVALID_FORMAT = 1;
    
    const INVALID_CALL = 2;
}
