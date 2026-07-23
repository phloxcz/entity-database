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
 * Postgres-specific quirks — override convertType() further here for
 * custom domains etc. when the need arises.
 */
class PgsqlGeneratorAdapter extends GenericGeneratorAdapter
{
    public function convertType(string $nativeType, array $vendor): string
    {
        // Postgres array types are internally named with a leading underscore
        // (e.g. int4[] → typname '_int4', text[] → '_text'). Element type info
        // is lost, but 'array' is a far more useful default than the raw name.
        if (str_starts_with($nativeType, '_')) {
            return 'array';
        }

        return parent::convertType($nativeType, $vendor);
    }
}
