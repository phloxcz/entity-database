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

namespace Phlox\EntityDatabase\Metadata;

use Nette\Caching\Cache;
use Nette\Caching\Storage;
use Phlox\EntityDatabase\Entity\Attributes\Property;
use Phlox\EntityDatabase\Entity\Attributes\Table;
use Phlox\EntityDatabase\Exception\MetadataException;
use ReflectionProperty;

/**
 * Builds and caches EntityMeta for entity classes.
 *
 * Uses Nette Cache with file-based invalidation — when an entity PHP file
 * changes, its metadata is automatically rebuilt on the next request.
 *
 * Falls back to an in-process runtime array when no Cache storage has been
 * configured (e.g. during CLI generation before the DI container boots).
 */
final class MetaStorage
{
    private static ?Cache $cache   = null;
    private static bool $initialized = false;

    /** @var array<string, EntityMeta> */
    private static array $runtimeCache = [];

    public static function initialize(object $config, Storage $storage): void
    {
        if (!self::$initialized) {
            self::$cache       = new Cache($storage, strtr(self::class, '\\', '.'));
            self::$initialized = true;
        }
    }

    public static function get(string $class): EntityMeta
    {
        if (!self::$cache) {
            return self::$runtimeCache[$class] ??= self::describeEntity($class);
        }

        return self::$cache->load($class, function (&$dependencies) use ($class): EntityMeta {
            $meta = self::describeEntity($class);
            $dependencies[Cache::Files] = [$meta->file];
            return $meta;
        });
    }

    private static function describeEntity(string $class): EntityMeta
    {
        $rc         = new \ReflectionClass($class);
        $tableAttrs = $rc->getAttributes(Table::class);

        if (empty($tableAttrs)) {
            throw new MetadataException(
                "Entity class '{$class}' is missing the #[Table] attribute."
            );
        }

        $properties = [];

        foreach ($rc->getProperties() as $prop) {
            /** @var ReflectionProperty $prop */
            foreach ($prop->getAttributes(Property::class) as $attr) {
                $propData = $attr->newInstance()->getProps();

                $propData['name']    = $prop->getName();
                $propData['comment'] = self::cleanDocComment($prop->getDocComment());
                // 'access' comes from the #[Property] attribute (set by PhpEntityRenderer to
                // match the hooks it generated) rather than being re-derived from hook presence
                // here: a write-only property still needs a `get` hook — one that throws — to
                // actually block reads (PHP has no other way to enforce that direction), so
                // "does a get hook exist" is no longer a reliable signal for "is this readable".
                // PropertyMeta itself derives readable/writable from it.

                $properties[] = new PropertyMeta($propData);
            }
        }

        return new EntityMeta([
            'class'      => $class,
            'table'      => $tableAttrs[0]->newInstance()->getName(),
            'file'       => $rc->getFileName(),
            'properties' => $properties,
        ]);
    }

    private static function cleanDocComment(string|false|null $docComment): ?string
    {
        if (!$docComment) {
            return null;
        }
        $docComment = preg_replace('#^/\*\*|\*/$#', '', $docComment);
        $docComment = preg_replace('#^\s*\*\s?#m', '', $docComment);
        return trim($docComment);
    }
}
