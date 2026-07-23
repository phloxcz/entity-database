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

namespace Phlox\EntityDatabase\Generator\Renderer;

use Nette;
use Nette\PhpGenerator\ClassType;
use Nette\PhpGenerator\Property;
use Phlox\EntityDatabase\Entity\Attributes;
use Phlox\EntityDatabase\Generator\ClassEventData;
use Phlox\EntityDatabase\Generator\Schema\EntityDefinition;
use Phlox\EntityDatabase\Generator\Schema\EntityPropDefinition;
use Phlox\EntityDatabase\Generator\Schema\EntityRefDefinition;
use Phlox\EntityDatabase\Generator\Schema\EntityRelatedDefinition;

/**
 * Renders an EntityDefinition into a PHP class file with real PHP 8.4 hooked
 * properties (get/set), backed by Entity::$_ctx (ActiveRow + dirty tracking).
 */
class PhpEntityRenderer
{
    private const DateTimeTypes = [
        'datetime', 'datetimeimmutable', 'datetimeinterface', 'nette\utils\datetime',
    ];

    public function render(EntityDefinition $entity, string $dir): string
    {
        if (!is_dir($dir)) {
            throw new Nette\DirectoryNotFoundException("Directory '{$dir}' not found!");
        }

        $dir  = rtrim($dir, '/') . '/';
        $file = $dir . $entity->name . '.php';

        $phpFile   = new Nette\PhpGenerator\PhpFile();
        $namespace = $phpFile->addNamespace($entity->namespace);

        $class = $namespace->addClass($entity->name);

        if ($entity->comment) {
            $class->addComment($entity->comment);
        }

        $class->setExtends($entity->extends);
        $class->addAttribute(Attributes\Table::class, ['name' => $entity->table]);

        foreach ($entity->properties as $p) {
            $this->addScalarProperty($class, $p);
        }

        foreach ($entity->references as $r) {
            $this->addRefProperty($class, $r);
        }

        foreach ($entity->collections as $c) {
            $this->addCollectionProperty($class, $c);
        }

        // Per-table onClass hooks — called after the class is fully assembled
        foreach ($entity->onClass as $callback) {
            $callback(new ClassEventData($class, $namespace, $phpFile, $entity));
        }

        Nette\Utils\FileSystem::write($file, (string) $phpFile);

        return $file;
    }


    // -------------------------------------------------------------------------
    // Scalar (column / virtual) properties
    // -------------------------------------------------------------------------

    private function addScalarProperty(ClassType $class, EntityPropDefinition $p): void
    {
        $prop = $class->addProperty($p->name)
            ->setType($p->type)
            ->setNullable($p->nullable);

        if ($p->nullable) {
            $prop->setValue(null);
        }

        if ($p->comment) {
            $prop->addComment($p->comment);
        }

        $attrs = [
            'col'        => $p->column,
            'key'        => $p->key,
            'nullable'   => $p->nullable,
            'searchable' => $p->searchable,
            'virtual'    => $p->virtual,
            'type'       => $p->type,
            'access'     => $p->access,
        ];
        if ($p->nativeType !== null) $attrs['nativeType'] = $p->nativeType;
        if ($p->format !== null)     $attrs['format']     = $p->format;
        if ($p->json !== null)       $attrs['json']       = $p->json;

        $prop->addAttribute(Attributes\Property::class, $attrs);

        $wantsGet = str_contains($p->access, 'r');
        $wantsSet = str_contains($p->access, 'w');

        // A property with no `set` hook still accepts a plain external write by
        // default in PHP 8.4 — omitting the hook alone does NOT make it read-only.
        // public/private(set) closes that: external assignment then throws.
        // (There's no equivalent trick for the opposite direction — PHP rejects a
        // get visibility weaker than set — so write-only uses writeOnlyGetExpr()
        // below instead, which throws from an explicit get hook.)
        if ($wantsGet && !$wantsSet) {
            $prop->setVisibility('public', 'private');
        }

        if ($p->virtual) {
            if ($wantsGet) {
                $prop->addHook('get', "\$this->{$p->name}");
            } elseif ($wantsSet) {
                $prop->addHook('get', $this->writeOnlyGetExpr($p->name));
            }
            if ($wantsSet) {
                $this->addSetHook($prop, $p->name, $p->type, "\$this->{$p->name} = \$value;");
            }
            return;
        }

        if ($wantsGet) {
            $prop->addHook('get', $this->buildColumnGetExpr($p));
        } elseif ($wantsSet) {
            $prop->addHook('get', $this->writeOnlyGetExpr($p->name));
        }
        if ($wantsSet) {
            $this->addColumnSetHook($prop, $p);
        }
    }

    /**
     * Blocks reads on a write-only property. Omitting the get hook entirely
     * is not enough — PHP then falls back to a plain read of the backing
     * storage, silently exposing whatever was last written (e.g. a password
     * hash). Entity::toArrayDb() remains the deliberate, visible way to read
     * a write-only column's raw value — it goes straight to ActiveRow and
     * never touches this hook.
     */
    private function writeOnlyGetExpr(string $name): string
    {
        $message = 'Property $' . $name . ' is write-only.';

        return 'throw new \Phlox\EntityDatabase\Exception\EntityException('
            . $this->dump($message)
            . ', \Phlox\EntityDatabase\Exception\EntityException::WRITE_ONLY_PROPERTY)';
    }

    private function buildColumnGetExpr(EntityPropDefinition $p): string
    {
        if ($p->json !== null) {
            $decodeArgs = $p->json === 'array' ? ', true' : '';
            return "\$this->_ctx->modified[{$this->dump($p->name)}] ?? false\n"
                . "    ? \$this->{$p->name}\n"
                . "    : ((\$raw = \$this->_ctx->activeRow?->{$p->column}) !== null ? json_decode(\$raw{$decodeArgs}) : null)";
        }

        // MySQL SET column: comma-separated string ("read,write" / "") → list.
        if ($p->nativeType === 'set') {
            return "\$this->_ctx->modified[{$this->dump($p->name)}] ?? false\n"
                . "    ? \$this->{$p->name}\n"
                . "    : ((\$raw = \$this->_ctx->activeRow?->{$p->column}) !== null ? \\Phlox\\EntityDatabase\\Entity\\EntityHelpers::parseMysqlSet(\$raw) : null)";
        }

        // Postgres array column: native typname is '_'-prefixed (e.g. _int4, _text).
        if ($p->nativeType !== null && str_starts_with($p->nativeType, '_')) {
            return "\$this->_ctx->modified[{$this->dump($p->name)}] ?? false\n"
                . "    ? \$this->{$p->name}\n"
                . "    : ((\$raw = \$this->_ctx->activeRow?->{$p->column}) !== null ? \\Phlox\\EntityDatabase\\Entity\\EntityHelpers::parsePgArray(\$raw) : null)";
        }

        // BIT/boolean column: some drivers (e.g. pdo_sqlsrv) hand back raw '1'/'0'
        // strings instead of casting it like they do MONEY or DATETIME2 columns.
        if ($p->nativeType === 'bit' && strtolower(ltrim($p->type, '\\')) === 'bool') {
            return "\$this->_ctx->modified[{$this->dump($p->name)}] ?? false\n"
                . "    ? \$this->{$p->name}\n"
                . "    : ((\$raw = \$this->_ctx->activeRow?->{$p->column}) !== null ? \\Phlox\\EntityDatabase\\Entity\\EntityHelpers::parseBit(\$raw) : null)";
        }

        return "\$this->_ctx->modified[{$this->dump($p->name)}] ?? false\n"
            . "    ? \$this->{$p->name}\n"
            . "    : \$this->_ctx->activeRow?->{$p->column} ?? null";
    }

    private function addColumnSetHook(Property $prop, EntityPropDefinition $p): void
    {
        if ($this->isDateTimeType($p->type)) {
            $format = $this->dump($p->format ?? 'Y-m-d H:i:s');
            $body = "\$this->{$p->name} = match(true) {\n"
                . "    \$value === null => null,\n"
                . "    \$value instanceof \\DateTimeInterface => \\Nette\\Utils\\DateTime::from(\$value),\n"
                . "    default => \\Phlox\\EntityDatabase\\Entity\\EntityHelpers::parseDateTime(\$value, {$format}),\n"
                . "};\n"
                . "\$this->_ctx->modified[{$this->dump($p->name)}] = true;";

            $hook = $prop->addHook('set')->setBody($body);
            $hook->addParameter('value')->setType('string|\DateTimeInterface|null');
            return;
        }

        $body = "\$this->{$p->name} = \$value;\n\$this->_ctx->modified[{$this->dump($p->name)}] = true;";
        $this->addSetHook($prop, $p->name, $p->type, $body, $p->nullable);
    }

    private function addSetHook(Property $prop, string $name, string $type, string $body, bool $nullable = true): void
    {
        $hook = $prop->addHook('set')->setBody($body);
        $hook->addParameter('value')->setType($type)->setNullable($nullable);
    }

    private function isDateTimeType(string $type): bool
    {
        return in_array(strtolower(ltrim($type, '\\')), self::DateTimeTypes, true);
    }


    // -------------------------------------------------------------------------
    // BelongsTo (ref) properties — always read-only
    // -------------------------------------------------------------------------

    private function addRefProperty(ClassType $class, EntityRefDefinition $r): void
    {
        // No setValue(null) here: the get hook below never references $this->{$r->name}
        // (it's always computed fresh from activeRow), so PHP treats this as a fully
        // virtual property with no backing storage — a default value is not allowed.
        $prop = $class->addProperty($r->name)
            ->setType($r->type)
            ->setNullable(true);
        // No setVisibility() here either: the get hook never references $this->{$r->name},
        // so PHP already treats this as a fully virtual (storage-less) property and
        // rejects external writes on its own ("Property ... is read-only") — asymmetric
        // visibility isn't just unnecessary here, PHP outright refuses to declare it on
        // a property with no backing storage at all.

        if ($r->comment) {
            $prop->addComment($r->comment);
        }

        $prop->addAttribute(Attributes\Property::class, [
            'type'    => $r->type,
            'ref'     => $r->ref,
            'through' => $r->column,
            'access'  => 'r',
        ]);

        $prop->addHook('get',
            "(\$row = \$this->_ctx->activeRow?->ref({$this->dump($r->ref)}, {$this->dump($r->column)})) === null\n"
            . "    ? null\n"
            . "    : \$this->_ctx->selection?->entityFactory->create(\$row, \$this->_ctx->selection)"
        );
    }


    // -------------------------------------------------------------------------
    // HasMany (collection) properties — always read-only
    // -------------------------------------------------------------------------

    private function addCollectionProperty(ClassType $class, EntityRelatedDefinition $c): void
    {
        $prop = $class->addProperty($c->name)
            ->setType('\Phlox\EntityDatabase\Database\Table\GroupedSelection');
        // Same as addRefProperty(): no backing-storage reference in the get hook below,
        // so PHP already rejects external writes to this property on its own.

        if ($c->comment) {
            $prop->addComment($c->comment);
        }

        $attrs = [
            'related' => $c->related,
            'through' => $c->column,
            'access'  => 'r',
        ];
        if ($c->condition !== null) {
            $attrs['condition'] = $c->condition;
        }
        $prop->addAttribute(Attributes\Property::class, $attrs);

        $expr = "\$this->_ctx->selection->createGroupedSelection(\n"
            . "    \$this->_ctx->activeRow->related({$this->dump($c->related)}, {$this->dump($c->column)})\n"
            . ')';
        if ($c->condition !== null) {
            $expr .= "\n    ->where({$this->dump($c->condition)})";
        }

        $prop->addHook('get', $expr);
    }


    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function dump(mixed $value): string
    {
        return var_export($value, true);
    }
}
