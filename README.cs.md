# phloxcz/entity-database

[English](README.md) | **Česky**

**Vrstva entit pro [Nette Database](https://doc.nette.org/cs/database).**

Generuje typované třídy entit pro PHP 8.4 ze schématu databáze. Entity obalují `ActiveRow` kompozicí, takže lazy loading, keš, skládání dotazů i procházení relací v Nette Database dál fungují beze změny. Dostanete skutečné typované vlastnosti (property hooks z PHP 8.4, ne jen doc-komentáře), napovídání v IDE, sledování změn a typovaný přístup k relacím a hledání, aniž byste přišli o cokoli, co Nette Database už umí dobře.

> ⚠️ Balíček se aktivně vyvíjí. API zatím není stabilní.

Otestováno od začátku do konce na **SQLite, MySQL, PostgreSQL a SQL Serveru**, viz [Příklady a testování](#příklady-a-testování).

---

## Požadavky

- PHP 8.4+
- `nette/database` 3.2.2+
- `nette/di` 3.1+
- `nette/php-generator` 4.1.7+ (potřeba pro generování property hooks z PHP 8.4)
- `nette/caching`, `nette/neon`, `nette/schema`, `nette/utils`
- `symfony/console` (pro příkaz `generate`)

---

## Instalace

```bash
composer require phloxcz/entity-database
```

---

## Rychlý start

### 1. Zaregistrujte rozšíření v konfiguraci Nette

```neon
extensions:
    entityDatabase: Phlox\EntityDatabase\DI\Extension

entityDatabase:
    connections:
        default:                              # musí odpovídat názvu připojení nette/database
            entities:
                -   namespace: App\Entity
                    output: %appDir%/Entity
                    config:
                        - %appDir%/config/entities.neon
```

### 2. Popište tabulky v `entities.neon`

Generátor čte schéma databáze a většinu si odvodí sám. Uvádíte jen to, co chcete *přepsat nebo přidat*.

```neon
roles:
    class: Role

users:
    class: User
    comment: "Application user"
    searchable:
        - email                       # používá Selection::search()
    rename:
        created_at: createdAt
    override:
        created_at:
            format: 'd.m.Y H:i'        # tento formát se přijme při nastavení z textu
        password_hash:
            access: w                  # vlastnost jen pro zápis
    references:
        role_id: { name: role }        # BelongsTo, zjistí se z cizího klíče; tady se jen přejmenuje
    hasMany:
        orders:
            through: user_id           # HasMany, postavené na related() z Nette
```

### 3. Vygenerujte třídy entit

```bash
vendor/bin/entity-database generate
```

Tím vznikne `App\Entity\User` (dědí z `Phlox\EntityDatabase\Entity\Entity`) se skutečnými typovanými vlastnostmi s property hooks z PHP 8.4, viz [Ukázka vygenerované entity](#ukázka-vygenerované-entity).

### 4. Použijte je v aplikaci

```php
use Phlox\EntityDatabase\Database\Explorer; // injektujte tento, ne Explorer z Nette

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
echo $user->role->name;                           // BelongsTo, líně
foreach ($user->orders as $order) { ... }          // HasMany, líně
```

---

## Ukázka vygenerované entity

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

    // jen pro zápis (access: w): get vždy vyhodí výjimku, takže hash lze získat zpět
    // jen vědomým voláním toArrayDb()/toModifiedArrayDb(),
    // nikdy omylem (běžné čtení, toArray(), iterace, ...)
    #[Property(col: 'password_hash', nullable: true, type: 'string', nativeType: 'varchar', access: 'w')]
    public ?string $passwordHash = null {
        set(?string $value) {
            $this->passwordHash = $value;
            $this->_ctx->modified['passwordHash'] = true;
        }
        get => throw new EntityException('Property $passwordHash is write-only.', EntityException::WRITE_ONLY_PROPERTY);
    }

    // BelongsTo: jen pro čtení, řeší se přes ActiveRow::ref()
    // bez výchozí hodnoty: vlastnost ref nemá úložiště (get vždy počítá znovu),
    // takže ji PHP bere jako plně virtuální a výchozí hodnota není povolená
    #[Property(type: '\App\Entity\Role', ref: 'roles', through: 'role_id')]
    public ?Role $role {
        get => ($row = $this->_ctx->activeRow?->ref('roles', 'role_id')) === null
            ? null
            : $this->_ctx->selection?->entityFactory->create($row, $this->_ctx->selection);
    }

    // HasMany: jen pro čtení, řeší se přes ActiveRow::related()
    #[Property(related: 'orders', through: 'user_id')]
    public GroupedSelection $orders {
        get => $this->_ctx->selection->createGroupedSelection(
            $this->_ctx->activeRow->related('orders', 'user_id')
        );
    }
}
```

Skutečné typované vlastnosti PHP, ne jen doc-komentáře `@property`, takže IDE (PhpStorm, VS Code + Intelephense) napovídají samy. `access` (`r`/`w`/`rw`) určuje, které hooky se vygenerují. `MetaStorage` ho čte přímo z atributu `#[Property]`, místo aby zkoumal, jaké hooky existují: vlastnost jen pro zápis stejně potřebuje `get` hook, který vyhazuje výjimku, aby čtení opravdu zablokoval, takže podle přítomnosti hooku už nejde poznat „čitelné" od „jen pro zápis". Generátor také přidá `use` pro každou třídu, na kterou hooky odkazují (`EntityHelpers`, `EntityException`, `GroupedSelection`, `Nette\Utils\DateTime` jako `NetteDateTime`), takže v těle třídy stačí krátké názvy.

`access: w` není bezpečnostní hranice. `toArrayDb()`/`toModifiedArray()`/`toModifiedArrayDb()` umí surovou hodnotu vždy přečíst, a to záměrně (tak repozitář získá hash hesla, aby ho uložil nebo ověřil). Blokuje *implicitní* cesty: běžné čtení `$user->passwordHash`, `$user->toArray()`, iteraci přes entitu, zalogování nebo serializaci celé entity, tedy způsoby, kterými tajná hodnota unikne omylem. Metody, které ji vracejí, mají v názvu `Db`/`Modified`, takže je z volání vidět, že jde o něco vědomého a nízkoúrovňového.

---

## Čtení a zápis

```php
// čtení
$db->table('users')->fetch();                 // ?User (další řádek)
$db->table('users')->fetchAll();               // User[]
$db->table('users')->get(1);                   // ?User, podle primárního klíče
$db->table('users')->fetchPairs('id', 'email');

// potřebujete surový řádek? každá metoda fetch má protějšek raw*
$db->table('users')->rawFetch();               // ?ActiveRow
$db->table('users')->rawFetchAll();            // ActiveRow[]
$db->table('users')->rawGet(1);                // ?ActiveRow

// zápis: klíče dat jsou názvy sloupců, ne vlastností
$user = $db->table('users')->insert([...]);    // User|int|array, viz Selection::insert()
$db->table('users')->where('id', 1)->update([...]);
$db->table('users')->where('id', 1)->delete();

// nebo na jedné načtené entitě: nastavit přes typované vlastnosti a zapsat
$user->email = 'new@example.com';   // jen označí změnu, nic se ještě nezapisuje
$user->saveModified();              // zapíše jen změněné sloupce podle primárního klíče a vynuluje změny
$user->delete();                    // smaže tento řádek

// obě vyhodí EntityException na entitě bez ActiveRow (vytvořené ručně);
// insert jde vždy přes Selection::insert(),
// který umí přijmout to, co taková entita drží, přeložené na sloupce:
$user = new User();
$user->email = 'new@example.com';
$created = $db->table('users')->insert($user->toModifiedArrayDb());

// transakce a čisté SQL: Explorer je obaluje explicitně (ne přes __call), takže
// callback níže dostane *tento* Explorer a mapování entit dál funguje
$db->transaction(function (Explorer $db) {
    $db->table('users')->insert([...]);
});
$db->query('SELECT COUNT(*) AS c FROM users')->fetch();

// cesta k objektům Nette, když potřebujete něco, co výše není obalené
$db->nExplorer;               // Nette\Database\Explorer
$selection->nSelection;       // Nette\Database\Table\Selection
```

## Hledání

```php
$db->table('users')->search('jan')->fetchAll();
```

Hledá `%výraz%` bez ohledu na velikost písmen, spojené přes OR napříč všemi sloupci označenými `searchable: true`. Pokud entita žádný nemá, vyhodí `SearchException`.

```php
$db->table('users')->search('jan', ignoreDiacritics: true)->fetchAll(); // najde i "Ján"
$db->table('users')->searchIn(['email'], 'jan')->fetchAll();            // jen v těchto sloupcích
```

Firebird jako driver potřebuje [`phloxcz/firebird-database`](https://github.com/phloxcz/firebird-database/blob/master/README.cs.md) a `ignoreDiacritics` nemá: tam to závisí na collation sloupce.

Hledání bez ohledu na diakritiku se zapíná zvlášť a závisí na databázi:

| Driver     | Mechanismus                      | Požadavek                                                       |
| ---------- | -------------------------------- | --------------------------------------------------------------- |
| MySQL      | `COLLATE UTF8MB4_0900_AI_CI`   | MySQL 8.0+                                                      |
| PostgreSQL | `unaccent()`                   | zapnuté rozšíření: `CREATE EXTENSION IF NOT EXISTS unaccent;` |
| SQL Server | `COLLATE LATIN1_GENERAL_CI_AI` | funguje rovnou                                                  |
| SQLite     | —                               | nepodporováno, vyhodí `SearchException`                      |

`LOWER()`/`COLLATE`/`unaccent()` na sloupci obvykle zabrání použití běžného indexu. U velké tabulky se vyplatí index nad výrazem.

---

## Mapování typů

Generátor převádí nativní typy sloupců na typy PHP pomocí `GeneratorAdapter`, který se vybere automaticky podle DSN připojení. Několik konvencí konkrétních databází se řeší za vás:

| Nativní typ                                        | Typ PHP                   | Poznámky                                                                                    |
| -------------------------------------------------- | ------------------------- | ------------------------------------------------------------------------------------------- |
| MySQL `TINYINT(1)`                               | `bool`                  | v MySQL obvyklý booleovský zápis; širší `TINYINT` zůstává `int`                         |
| MySQL `SET(...)`                                 | `array`                 | text oddělený čárkami se rozparsuje za vás                                                  |
| MySQL `JSON`, Postgres `json`/`jsonb`        | `array`\|`object`     | dekóduje se v `get` hooku vlastnosti; řídí se přes `override.<col>.json: array\|object`  |
| Postgres pole (`int4[]`, `text[]`, ...)        | `array`                 | rozparsuje se z literálu pole v Postgres (jednorozměrné)                                    |
| Postgres nativní `boolean`                       | `bool`                  | bez převodu                                                                                 |
| SQL Server `BIT`                                 | `bool`                  | některé drivery vrací surový text `'1'`/`'0'`, ten se za vás znormalizuje              |
| SQL Server `NVARCHAR`/`NCHAR`/`NTEXT`        | `string`                |                                                                                             |
| SQL Server `MONEY`, `SMALLMONEY`               | `float`                 |                                                                                             |
| SQL Server `DATETIME2`, `UNIQUEIDENTIFIER`     | `\DateTime`, `string` |                                                                                             |
| `DATE`/`DATETIME`/`TIMESTAMP` (každý driver) | `\DateTime`             | setter přijímá i `string`, parsuje se přes `format` (nebo běžné náhradní formáty)         |

Pro cokoli dalšího napište vlastní adaptér (`Phlox\EntityDatabase\Generator\Adapter\GeneratorAdapter`), nebo přepište konkrétní sloupec přes `override.<col>.type` v `entities.neon`.

---

## Přehled konfigurace

### Volby sady entit (v konfiguraci Nette)

| Klíč          | Typ           | Popis                                                            |
| ------------- | ------------- | ---------------------------------------------------------------- |
| `namespace` | string        | Jmenný prostor generovaných tříd                                 |
| `output`    | string        | Složka, kam se třídy zapisují                                    |
| `config`    | string\|array | Cesty k souborům NEON/JSON **nebo** definice tabulek přímo |
| `cleanSkip` | string[]      | Soubory, které se při přegenerování nemažou                      |

### Konfigurace tabulky (v `entities.neon`, pro každou tabulku)

| Klíč           | Typ                                                     | Popis                                                          |
| -------------- | ------------------------------------------------------- | -------------------------------------------------------------- |
| `class`      | string                                                  | **Povinné.** Název generované třídy                      |
| `comment`    | string                                                  | Doc-komentář třídy                                             |
| `extends`    | string                                                  | Rodičovská třída (výchozí `Phlox\EntityDatabase\Entity\Entity`) |
| `skip`       | string[]                                                | Sloupce, které se úplně vynechají                              |
| `searchable` | string[]                                                | Sloupce použitelné v `Selection::search()`                   |
| `rename`     | `{col: propName}`                                     | Přepíše název vlastnosti generovaný pro sloupec                |
| `override`   | `{col: {type?, format?, access?, comment?, json?}}`   | Doladění vygenerované vlastnosti konkrétního sloupce           |
| `virtual`    | `{propName: {type, access, nullable, comment}}`       | Vlastnosti bez sloupce                                         |
| `references` | `{col: {name, comment}}`                              | Přejmenuje automaticky zjištěnou vlastnost BelongsTo           |
| `hasMany`    | `{propName: {through, table?, condition?, comment?}}` | Kolekce HasMany                                                |
| `onClass`    | callable\|callable[]                                    | Hooky pro přidání metod, traitů a konstant do generované třídy |
| `onDataType` | callable\|callable[]                                    | Hooky pro přepsání převodu typu databáze na typ PHP u sloupce  |

---

## CLI

```
vendor/bin/entity-database generate    # regenerate all configured entity sets
```

---

## Konfigurace přímo v souboru

Místo souborů NEON, nebo vedle nich, můžete tabulky definovat přímo v konfiguraci Nette:

```neon
entityDatabase:
    connections:
        default:
            entities:
                -   namespace: App\Entity
                    output: %appDir%/Entity
                    config:
                        - %appDir%/config/entities.neon   # soubor
                        -                                  # přímo
                            roles:
                                class: Role
```

---

## Příklady a testování

Složka `examples/` obsahuje pro každou databázi úplnou spustitelnou aplikaci: vytvoření schématu, naplnění daty, generování entit a smoke test CRUD, který ověřuje výše popsané konvence mapování typů (nejen to, že to nespadlo):

```
examples/
├── sqlite/     # file-based, no setup needed — also has UserRepository.php,
│               # a worked repository example (CRUD + password hashing via
│               # a write-only property + toArrayDb(), see repository-test.php)
├── mysql/      # TINYINT(1), JSON, SET, ENUM, DECIMAL
├── postgres/   # boolean, jsonb, array types, numeric
└── mssql/      # BIT, NVARCHAR, MONEY, UNIQUEIDENTIFIER, DATETIME2
```

`docker-compose.yml` v kořeni repozitáře lokálně spustí MySQL, PostgreSQL a SQL Server:

```bash
docker compose up -d
composer install

php examples/sqlite/init-db.php   && php examples/sqlite/generate.php   && php examples/sqlite/demo.php
php examples/mysql/init-db.php    && php examples/mysql/generate.php    && php examples/mysql/crud-test.php
php examples/postgres/init-db.php && php examples/postgres/generate.php && php examples/postgres/crud-test.php
php examples/mssql/init-db.php    && php examples/mssql/generate.php    && php examples/mssql/crud-test.php
```

Příklad pro SQL Server navíc vyžaduje rozšíření PHP [`pdo_sqlsrv`](https://learn.microsoft.com/sql/connect/php/download-drivers-php-sql-server). `bootstrap.php` každého příkladu čte údaje o připojení z proměnných prostředí (popsaných na začátku souboru) s výchozími hodnotami podle `docker-compose.yml`, takže na vlastní databázi ho nasměrujete jen nastavením pár proměnných, bez změny kódu.

---

## PHPStan

`Selection` je generická: `Selection<T of Entity>`. Její plynulé metody (`where()`, `order()`, `limit()`, …) dosahují na Selection z Nette přes `__call()` a jsou deklarované jako `@method`, takže je PHPStan zná. Vlastnosti has-many u generovaných entit nesou `@var GroupedSelection<RelatedEntity>`.

Aby PHPStan znal třídu entity u `$explorer->table('user')` (jako `Selection<App\Entity\User>`, takže `fetch()`, `get()`, `insert()`, `fetchAll()` i iterace dají `User`), přidejte přibalené rozšíření a řekněte mu, kde jsou generované entity:

```neon
includes:
	- vendor/phloxcz/entity-database/extension.neon

parameters:
	entityDatabase:
		entityDirs:
			- %currentWorkingDirectory%/app/Entity
			- %currentWorkingDirectory%/extensions/*/src/Entity   # lze použít glob
	excludePaths:
		analyse:
			- app/Entity/*   # generované: sloupce jsou magické vlastnosti ActiveRow
	universalObjectCratesClasses:
		- Nette\Database\Table\ActiveRow   # surové řádky (rawFetch(), obyčejné selections z Nette)
```

Rozšíření čte mapu tabulek na třídy z `#[Table(name: '…')]` u entit. Název tabulky, který není doslovný text (`table($name)`), zůstane `Selection<Entity>`. Kde na tom záleží, výsledek anotujte.

## Další dokumentace

Podrobnější reference s množstvím příkladů je v [`docs/readme.md`](docs/readme.md): přehled architektury, strategie pojmenování, hooky `onClass`/`onDataType` a úplný přehled výjimek.

---

## Licence

[MIT](LICENSE)
