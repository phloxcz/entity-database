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

namespace Phlox\EntityDatabase\PHPStan;

use Phlox\EntityDatabase\Database\Explorer;
use Phlox\EntityDatabase\Database\Table\Selection;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\DynamicMethodReturnTypeExtension;
use PHPStan\Type\Generic\GenericObjectType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

/**
 * PHPStan: $explorer->table('user') is a Selection<\App\Entity\User> — so
 * fetch(), get(), insert(), iterating… give the entity's class, not the
 * base Entity. The table → class map comes from the generated entities'
 * #[Table(name: '…')] in the directories of the `entityDatabase.entityDirs`
 * parameter (glob patterns allowed); see extension.neon and the README.
 */
final class TableReturnTypeExtension implements DynamicMethodReturnTypeExtension
{
    /** @var array<string, class-string>|null table => entity class */
    private ?array $map = null;

    /** @param list<string> $entityDirs */
    public function __construct(
        private readonly array $entityDirs,
    ) {
    }

    public function getClass(): string
    {
        return Explorer::class;
    }

    public function isMethodSupported(MethodReflection $methodReflection): bool
    {
        return $methodReflection->getName() === 'table';
    }

    public function getTypeFromMethodCall(MethodReflection $methodReflection, MethodCall $methodCall, Scope $scope): ?Type
    {
        $arg = $methodCall->getArgs()[0] ?? null;
        $tables = $arg !== null ? $scope->getType($arg->value)->getConstantStrings() : [];
        if ($tables === []) {
            return null; // not a literal table name: Selection<Entity>
        }
        $entities = [];
        foreach ($tables as $table) {
            $class = $this->map()[$table->getValue()] ?? null;
            if ($class === null) {
                return null;
            }
            $entities[] = new ObjectType($class);
        }
        return new GenericObjectType(Selection::class, [TypeCombinator::union(...$entities)]);
    }

    /** @return array<string, class-string> */
    private function map(): array
    {
        if ($this->map !== null) {
            return $this->map;
        }
        $this->map = [];
        foreach ($this->entityDirs as $pattern) {
            foreach (glob($pattern, GLOB_ONLYDIR) ?: [] as $dir) {
                foreach (glob($dir . '/*.php') ?: [] as $file) {
                    $source = (string) file_get_contents($file);
                    if (preg_match('~^namespace\s+([\w\\\\]+)\s*;~m', $source, $namespace)
                        && preg_match('~#\[Table\(\s*name:\s*[\'"]([^\'"]+)[\'"]~', $source, $table)
                        && preg_match('~^(?:final\s+|abstract\s+)?class\s+(\w+)~m', $source, $class)
                    ) {
                        /** @var class-string $fqcn */
                        $fqcn = $namespace[1] . '\\' . $class[1];
                        $this->map[$table[1]] = $fqcn;
                    }
                }
            }
        }
        return $this->map;
    }
}
