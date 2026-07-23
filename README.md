# phloxcz/entity-database

**Entity layer for [Nette Database](https://doc.nette.org/en/database).**

Generates typed PHP 8.4 entity classes from your database schema. Entities wrap `ActiveRow` via composition — Nette Database's lazy loading, caching, query building and relation traversal all continue to work unchanged underneath. You get real typed properties (PHP 8.4 property hooks, not just doc-comments), IDE autocompletion, dirty-tracking, and typed access to relations and search — without giving up anything Nette Database already does well.

> ⚠️ This package is under active development. The API is not yet stable.

Tested end-to-end against **SQLite, MySQL, PostgreSQL, and SQL Server** — see [Examples & testing](#examples--testing).

---

## Requirements

- PHP 8.4+
- `nette/database` 3.2.2+
- `nette/di` 3.1+
- `nette/php-generator` 4.1.7+ (needed for PHP 8.4 property hook generation)
- `nette/caching`, `nette/neon`, `nette/schema`, `nette/utils`
- `symfony/console` (for the `generate` CLI command)

---

## Installation

```bash
composer require phloxcz/entity-database
```

---

## Quick Start

### 1. Register the extension in your Nette config

```neon
extensions:
    entityDatabase: Phlox\EntityDatabase\DI\Extension

entityDatabase:
    connections:
        default:                              # must match your nette/database connection name
            entities:
                -   namespace: App\Entity
                    output: %appDir%/Entity
                    config:
                        - %appDir%/config/entities.neon
```

### 2. Describe your tables in `entities.neon`

The generator reads your DB schema and infers most of this itself — you only declare what you want to *override or add*.

```neon
roles:
    class: Role

users:
    class: User
    comment: "Application user"
    searchable:
        - email                       # used by Selection::search()
    rename:
        created_at: createdAt
    override:
        created_at:
            format: 'd.m.Y H:i'        # accept this format when setting from a string
        password_hash:
            access: w                  # write-only property
    references:
        role_id: { name: role }        # BelongsTo, auto-detected from the FK — this just renames it
    hasMany:
        orders:
            through: user_id           # HasMany, backed by Nette's related()
```

### 3. Generate entity classes

```bash
vendor/bin/entity-database generate
```

This writes `App\Entity\User` (extending `Phlox\EntityDatabase\Entity\Entity`) with real typed properties using PHP 8.4 property hooks — see [Generated entity example](#generated-entity-example) below.

### 4. Use in your application

```php
use Phlox\EntityDatabase\Database\Explorer; // inject this, not Nette's own Explorer

class UserRepository
{
    public function __construct(private Explorer $db) {}

    public function findActive(): array
    {
        return $this->db->table('users')
            ->where('is_active', 1)
            ->fetchAll(); // User[]
    }
}

$user = $this->db->table('users')->get(1);       // ?User
echo $user->email;
echo $user->createdAt->format('d.m.Y');
echo $user->role->name;                           // BelongsTo, lazy
foreach ($user->orders as $order) { ... }          // HasMany, lazy
```

---

## Generated entity example

```php
use Phlox\EntityDatabase\Database\Table\GroupedSelection;
use Phlox\EntityDatabase\Entity\Attributes\Property;
use Phlox\EntityDatabase\Entity\Attributes\Table;
use Phlox\EntityDatabase\Entity\Entity;
use Phlox\EntityDatabase\Exception\EntityException;

#[Table(name: 'users')]
class User extends Entity
{
    #[Property(col: 'id', key: true, nullable: false, type: 'int', nativeType: 'int')]
    public int $id {
        set(int $value) {
            $this->id = $value;
            $this->_ctx->modified['id'] = true;
        }
        get => $this->_ctx->modified['id'] ?? false
            ? $this->id
            : $this->_ctx->activeRow?->id ?? null;
    }

    #[Property(col: 'email', nullable: false, searchable: true, type: 'string', nativeType: 'varchar')]
    public string $email {
        set(string $value) {
            $this->email = $value;
            $this->_ctx->modified['email'] = true;
        }
        get => $this->_ctx->modified['email'] ?? false
            ? $this->email
            : $this->_ctx->activeRow?->email ?? null;
    }

    // write-only (access: w) — get always throws, so the hash can only ever
    // come back out through an explicit toArrayDb()/toModifiedArrayDb() call,
    // never by accident (plain read, toArray(), iteration, ...)
    #[Property(col: 'password_hash', nullable: true, type: 'string', nativeType: 'varchar', access: 'w')]
    public ?string $passwordHash = null {
        set(?string $value) {
            $this->passwordHash = $value;
            $this->_ctx->modified['passwordHash'] = true;
        }
        get => throw new EntityException('Property $passwordHash is write-only.', EntityException::WRITE_ONLY_PROPERTY);
    }

    // BelongsTo — read-only, resolved via ActiveRow::ref()
    // no default value here — a ref property has no backing storage (get always
    // computes fresh), so PHP treats it as fully virtual; a default isn't allowed
    #[Property(type: '\App\Entity\Role', ref: 'roles', through: 'role_id')]
    public ?Role $role {
        get => ($row = $this->_ctx->activeRow?->ref('roles', 'role_id')) === null
            ? null
            : $this->_ctx->selection?->entityFactory->create($row, $this->_ctx->selection);
    }

    // HasMany — read-only, resolved via ActiveRow::related()
    #[Property(related: 'orders', through: 'user_id')]
    public GroupedSelection $orders {
        get => $this->_ctx->selection->createGroupedSelection(
            $this->_ctx->activeRow->related('orders', 'user_id')
        );
    }
}
```

Real typed PHP properties, not just `@property` doc-comments — IDEs (PhpStorm, VS Code + Intelephense) get autocomplete for free. `access` (`r`/`w`/`rw`) controls which hooks get generated; `MetaStorage` reads it straight from the `#[Property]` attribute rather than inspecting which hooks exist — a write-only property still needs a throwing `get` hook to actually block reads, so hook presence alone can no longer tell "readable" from "write-only". The generator also registers a `use` for every class a property's hooks end up referencing (`EntityHelpers`, `EntityException`, `GroupedSelection`, `Nette\Utils\DateTime` as `NetteDateTime`), so the class body itself only ever needs short names.

`access: w` isn't a security boundary — `toArrayDb()`/`toModifiedArray()`/`toModifiedArrayDb()` can always read the raw value back out, on purpose (that's how a repository gets a password hash to persist it or verify it against). What it blocks is the *implicit* paths: a plain `$user->passwordHash` read, `$user->toArray()`, iterating the entity, logging/serializing it whole — the ways a secret field leaks by accident. The methods that do return it have `Db`/`Modified` in the name, so a call site says out loud that it's doing something deliberate and low-level.

---

## Reading & writing

```php
// fetch
$db->table('users')->fetch();                 // ?User (next row)
$db->table('users')->fetchAll();               // User[]
$db->table('users')->get(1);                   // ?User, by primary key
$db->table('users')->fetchPairs('id', 'email');

// need the raw row instead? every fetch method has a raw* counterpart
$db->table('users')->rawFetch();               // ?ActiveRow
$db->table('users')->rawFetchAll();            // ActiveRow[]
$db->table('users')->rawGet(1);                // ?ActiveRow

// write — data is keyed by DB column names, not property names
$user = $db->table('users')->insert([...]);    // User|int|array, see Selection::insert()
$db->table('users')->where('id', 1)->update([...]);
$db->table('users')->where('id', 1)->delete();

// or, on a single fetched entity: set via typed properties, then flush
$user->email = 'new@example.com';   // just flags it dirty, nothing written yet
$user->saveModified();              // writes only the changed columns, scoped by PK, then clears dirty state
$user->delete();                    // deletes this row

// both throw EntityException on an entity with no underlying ActiveRow
// (a manually-constructed one) — insert always goes through Selection::insert()

// transactions / raw SQL — Explorer wraps these explicitly (not via __call), so
// the callback below receives *this* Explorer and entity mapping keeps working
$db->transaction(function (Explorer $db) {
    $db->table('users')->insert([...]);
});
$db->query('SELECT COUNT(*) AS c FROM users')->fetch();

// escape hatch to the underlying Nette objects when you need something not wrapped above
$db->nExplorer;               // Nette\Database\Explorer
$selection->nSelection;       // Nette\Database\Table\Selection
```

## Search

```php
$db->table('users')->search('jan')->fetchAll();
```

Matches `%term%`, case-insensitive, OR'd across every column marked `searchable: true` on the entity. Throws `SearchException` if the entity has none.

```php
$db->table('users')->search('jan', ignoreDiacritics: true)->fetchAll(); // also matches "Ján"
```

Diacritics-insensitive search is opt-in and DB-specific:

| Driver | Mechanism | Requirement |
|---|---|---|
| MySQL | `COLLATE UTF8MB4_0900_AI_CI` | MySQL 8.0+ |
| PostgreSQL | `unaccent()` | extension enabled: `CREATE EXTENSION IF NOT EXISTS unaccent;` |
| SQL Server | `COLLATE LATIN1_GENERAL_CI_AI` | works out of the box |
| SQLite | — | not supported — throws `SearchException` |

`LOWER()`/`COLLATE`/`unaccent()` on a column generally prevents use of a plain index — worth an expression index if the table is large.

---

## Type mapping

The generator maps native DB column types to PHP types via a `GeneratorAdapter`, auto-selected from your connection's DSN scheme. A few DB-specific conventions are handled for you:

| Native type | PHP type | Notes |
|---|---|---|
| MySQL `TINYINT(1)` | `bool` | the de-facto MySQL boolean convention; wider `TINYINT` stays `int` |
| MySQL `SET(...)` | `array` | comma-separated string parsed for you |
| MySQL `JSON`, Postgres `json`/`jsonb` | `array`\|`object` | decoded via the property's `get` hook; control with `override.<col>.json: array\|object` |
| Postgres array types (`int4[]`, `text[]`, ...) | `array` | parsed from the Postgres array literal (single-dimension) |
| Postgres native `boolean` | `bool` | no conversion needed |
| SQL Server `BIT` | `bool` | some drivers hand this back as a raw `'1'`/`'0'` string — normalized for you |
| SQL Server `NVARCHAR`/`NCHAR`/`NTEXT` | `string` | |
| SQL Server `MONEY`, `SMALLMONEY` | `float` | |
| SQL Server `DATETIME2`, `UNIQUEIDENTIFIER` | `\DateTime`, `string` | |
| `DATE`/`DATETIME`/`TIMESTAMP` (any driver) | `\DateTime` | setter also accepts a `string`, parsed via `format` (or common fallbacks) |

Write your own adapter (`Phlox\EntityDatabase\Generator\Adapter\GeneratorAdapter`) for anything not covered, or override per-column with `override.<col>.type` in `entities.neon`.

---

## Config reference

### Entity set options (in your Nette config)

| Key | Type | Description |
|---|---|---|
| `namespace` | string | PHP namespace for generated classes |
| `output` | string | Directory where classes are written |
| `config` | string\|array | Path(s) to NEON/JSON files **or** inline table definitions |
| `cleanSkip` | string[] | Filenames to preserve during regeneration |

### Table config (in `entities.neon`, per table)

| Key | Type | Description |
|---|---|---|
| `class` | string | **Required.** Generated class name |
| `comment` | string | Class doc-comment |
| `extends` | string | Parent class (default `Phlox\EntityDatabase\Entity\Entity`) |
| `skip` | string[] | Columns to omit entirely |
| `searchable` | string[] | Columns usable with `Selection::search()` |
| `rename` | `{col: propName}` | Override the generated property name for a column |
| `override` | `{col: {type?, format?, access?, comment?, json?}}` | Fine-tune a specific column's generated property |
| `virtual` | `{propName: {type, access, nullable, comment}}` | Properties with no backing column |
| `references` | `{col: {name, comment}}` | Rename an auto-detected BelongsTo property |
| `hasMany` | `{propName: {through, table?, condition?, comment?}}` | HasMany collections |
| `onClass` | callable\|callable[] | Hook(s) to add methods/traits/constants to the generated class |
| `onDataType` | callable\|callable[] | Hook(s) to override the DB-type → PHP-type mapping per column |

---

## CLI

```
vendor/bin/entity-database generate    # regenerate all configured entity sets
```

---

## Inline config

Instead of (or alongside) NEON files you can define tables directly in your Nette config:

```neon
entityDatabase:
    connections:
        default:
            entities:
                -   namespace: App\Entity
                    output: %appDir%/Entity
                    config:
                        - %appDir%/config/entities.neon   # file
                        -                                  # inline
                            roles:
                                class: Role
```

---

## Examples & testing

The `examples/` directory has a complete, runnable app per database engine — schema creation, seeding, entity generation, and a CRUD smoke test that asserts on the type-mapping conventions above (not just "it ran without crashing"):

```
examples/
├── sqlite/     # file-based, no setup needed — also has UserRepository.php,
│               # a worked repository example (CRUD + password hashing via
│               # a write-only property + toArrayDb(), see repository-test.php)
├── mysql/      # TINYINT(1), JSON, SET, ENUM, DECIMAL
├── postgres/   # boolean, jsonb, array types, numeric
└── mssql/      # BIT, NVARCHAR, MONEY, UNIQUEIDENTIFIER, DATETIME2
```

`docker-compose.yml` at the repo root starts MySQL, PostgreSQL and SQL Server locally:

```bash
docker compose up -d
composer install

php examples/sqlite/init-db.php   && php examples/sqlite/generate.php   && php examples/sqlite/demo.php
php examples/mysql/init-db.php    && php examples/mysql/generate.php    && php examples/mysql/crud-test.php
php examples/postgres/init-db.php && php examples/postgres/generate.php && php examples/postgres/crud-test.php
php examples/mssql/init-db.php    && php examples/mssql/generate.php    && php examples/mssql/crud-test.php
```

The SQL Server example additionally requires the [`pdo_sqlsrv`](https://learn.microsoft.com/sql/connect/php/download-drivers-php-sql-server) PHP extension. Each example's `bootstrap.php` reads connection details from environment variables (documented at the top of the file) with sane defaults matching `docker-compose.yml`, so pointing any of them at your own database is a matter of setting a few env vars — no code changes.

---

## More documentation

A more detailed, example-heavy reference (in Czech) lives in [`docs/readme.md`](docs/readme.md) — architecture overview, naming strategies, `onClass`/`onDataType` hooks, and the full exception reference.

---

## License

[MIT](LICENSE)
