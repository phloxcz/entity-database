<?php
/**
 * This file is part of phloxcz/entity-database.
 *
 * (c) Phlox <https://github.com/phloxcz>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace Phlox\EntityDatabase\Entity;

use Nette\Caching\Cache;
use Nette\Caching\Storage;
use Nette\Database\Table\ActiveRow;
use Phlox\EntityDatabase\Database\Table\Selection;
use Phlox\EntityDatabase\Entity\Attributes\Table;
use Phlox\EntityDatabase\Exception\MappingException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;

final class EntityFactory
{
    private ?array $entityMap = null;

    private Cache $cache;

    public function __construct(private object $config, Storage $storage)
    {
        $this->cache = new Cache($storage, strtr(self::class, "\\", '.'));
    }

    private function buildMap(): void
    {
        $this->entityMap = $this->cache->load('map', function (&$dependencies) {

            $files = [];
            $entityMap = [];

            foreach($this->config->entities as $entitySet) {

                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($entitySet->output)
                );

                foreach($iterator as $file) {
                    /** @var SplFileInfo $file */

                    if($file->isFile() && $file->getExtension() === 'php') {

                        $filePath = $file->getPathname();
                        $classes = $this->readFileClasses($filePath);

                        foreach($classes as $class) {
                            if(is_subclass_of($class, Entity::class)) {
                                $ref = new ReflectionClass($class);
                                $attrs = $ref->getAttributes(Table::class);
                                if($attrs) {
                                    $tableAttr = $attrs[0]->newInstance();
                                    $entityMap[$tableAttr->getName()] = $class;

                                    $files[$filePath] = $filePath;
                                }
                            }
                        }
                    }
                }
            }
            
            $dependencies[Cache::Files] = array_values($files);

            return $entityMap;
        });


    }

    private function readFileClasses(string $file): array
    {
        $code = file_get_contents($file);
        $tokens = token_get_all($code);

        $namespace = '';
        $classes = [];

        for ($i = 0; $i < count($tokens); $i++) {
            if ($tokens[$i][0] === T_NAMESPACE) {
                $namespace = '';
                for ($j = $i + 1; isset($tokens[$j]) && $tokens[$j] !== ';'; $j++) {
                    if (is_array($tokens[$j])) {
                        $namespace .= $tokens[$j][1];
                    }
                }
            }

            if ($tokens[$i][0] === T_CLASS && isset($tokens[$i + 2][1])) {
                $className = $tokens[$i + 2][1];
                $classes[] = "\\".trim($namespace . '\\' . $className, ' \\');
            }
        }

        return $classes;
    }

    public function getEntityName(string $tableName): string
    {
        if($this->entityMap === null || !isset($this->entityMap[$tableName])) {
            $this->buildMap();
        }
        if(!isset($this->entityMap[$tableName])) {
            throw new MappingException("No 'table->entity' mapping for table '{$tableName}' found in configuration", MappingException::MappingNotFound);
        }
        if(!class_exists($this->entityMap[$tableName])) {
            throw new MappingException("Class '{$this->entityMap[$tableName]}' not found for table '{$tableName}'", MappingException::ClassNotFound);
        }
        return $this->entityMap[$tableName];
    }

    public function create(ActiveRow $activeRow, Selection $selection): Entity
    {
        $tableName = $activeRow->getTable()->getName();
        $entityClassName = $this->getEntityName($tableName);
        return new $entityClassName($activeRow, $selection);
    }
}