# Entity Database — dokumentace

## Obsah

1. [Co to je a proč to vzniklo](#1-co-to-je-a-proč-to-vzniklo)
2. [Instalace](#2-instalace)
3. [Registrace do Nette](#3-registrace-do-nette)
4. [Konfigurační soubor entit](#4-konfigurační-soubor-entit)
5. [Generování entit](#5-generování-entit)
6. [Vygenerovaná entita](#6-vygenerovaná-entita)
7. [Použití v aplikaci](#7-použití-v-aplikaci)
8. [Atributy entit](#8-atributy-entit)
9. [Relace](#9-relace)
10. [Pojmenovávací strategie](#10-pojmenovávací-strategie)
11. [onClass hook — úprava vygenerovaných tříd](#11-onclass-hook--úprava-vygenerovaných-tříd)
12. [Vlastní generátorový adaptér](#12-vlastní-generátorový-adaptér)
13. [Inline konfigurace](#13-inline-konfigurace)
14. [Referenční tabulka — konfigurace balíčku](#14-referenční-tabulka--konfigurace-balíčku)
15. [Architektura](#15-architektura)
16. [Výjimky](#16-výjimky)

---

## 1. Co to je a proč to vzniklo

Nette Database pracuje s objektem `ActiveRow` — každý řádek z databáze je instance téže generické třídy. To funguje skvěle pro rychlý vývoj, ale ve větší aplikaci narážíte na limity:

- IDE neví, jaké sloupce tabulka má → žádný autocomplete
- Typy nejsou zaručené — `$user->active` může být `1`, `"1"`, nebo `true` podle ovladače
- Doménová logika (`$user->getFullName()`) nemá kde bydlet
- Přejmenování sloupce v DB = ruční hledání v celém projektu

**Entity Database** přidává nad Nette Database tenkou entitní vrstvu. Každá DB tabulka má svou PHP třídu (`User`, `Article`, `Order`…) s typovanými properties. Tyto třídy se **generují automaticky** z databázového schématu, takže nemusíte psát boilerplate ručně.

Klíčový princip: entity **nenahrazují** Nette Database — **obalují** ho. Lazy loading, kešování dotazů, procházení relací — vše z Nette Database funguje beze změny. Jen místo `ActiveRow` dostanete vaši třídu.

---

## 2. Instalace

```bash
composer require phloxcz/entity-database
```

Požadavky:
- PHP 8.4+
- Nette Database 3.2.2+
- Nette DI 3.1+

---

## 3. Registrace do Nette

V konfiguračním souboru Nette (obvykle `config/common.neon`) zaregistrujte extension a popište mapování:

```neon
extensions:
    entityDatabase: Phlox\EntityDatabase\DI\Extension

entityDatabase:
    connections:
        default:                              # název DB spojení (musí odpovídat nette/database konfiguraci)
            entities:
                -   namespace: App\Entity     # namespace vygenerovaných tříd
                    output: %appDir%/Entity   # adresář, kam se zapíšou třídy
                    config:
                        - %appDir%/config/entities.neon
```

Pokud máte více DB spojení:

```neon
entityDatabase:
    connections:
        default:
            entities:
                -   namespace: App\Entity
                    output: %appDir%/Entity
                    config:
                        - %appDir%/config/entities.neon
        reporting:
            entities:
                -   namespace: App\ReportEntity
                    output: %appDir%/ReportEntity
                    config:
                        - %appDir%/config/report-entities.neon
```

---

## 4. Konfigurační soubor entit

Soubor `entities.neon` popisuje, které tabulky mají mít entitu a jak se mají jmenovat vlastnosti. Generátor si většinu věcí odvodí sám ze schématu DB — v konfiguráku stačí uvést jen to, co chcete **přepsat nebo doplnit**.

### Minimální příklad

```neon
users:
    class: User
```

Generátor přečte sloupce tabulky `users` a vygeneruje třídu `User` s property pro každý sloupec. Názvy properties převede z `snake_case` na `camelCase` (např. `created_at` → `$createdAt`).

### Plný příklad

```neon
users:
    class: User
    comment: "Uživatel aplikace"
    extends: App\BaseEntity           # volitelně: vlastní rodičovská třída

    # Sloupce, které mají být vynechány
    skip:
        - internal_note
        - legacy_column

    # Sloupce označené jako prohledávatelné
    searchable:
        - email
        - username

    # Přejmenování property (klíč = název sloupce v DB)
    rename:
        created_at: createdAt
        is_active: active
        password_hash: passwordHash

    # Přepsání odvozených hodnot pro konkrétní sloupce
    override:
        price:
            type: float
        created_at:
            type: '\Nette\Utils\DateTime'
            format: 'Y-m-d H:i:s'
        profile_json:
            json: object              # 'array' (výchozí) nebo 'object'

    # Virtuální properties — nemají DB sloupec
    virtual:
        fullName:
            type: string
            access: r
            comment: "Celé jméno (sestaveno z firstName + lastName)"
        orderCount:
            type: int
            access: r

    # HasMany kolekce (1:N)
    hasMany:
        orders:
            through: user_id          # cizí klíč v tabulce orders
        activeOrders:
            table: orders             # název tabulky (výchozí = klíč = 'activeOrders')
            through: user_id
            condition: "status != 'cancelled'"

    # Per-table onClass hook (viz sekci 11)
    onClass: [App\Generator\EntityHook, onUser]
```

### Popis konfiguračních klíčů

#### Na úrovni tabulky

| Klíč | Typ | Popis |
|---|---|---|
| `class` | string | **Povinné.** Název generované PHP třídy. |
| `comment` | string | PHPDoc komentář třídy. |
| `extends` | string | Rodičovská třída. Výchozí: `Phlox\EntityDatabase\Entity\Entity`. |
| `skip` | string[] | Názvy sloupců, které se přeskočí. |
| `searchable` | string[] | Sloupce označené jako prohledávatelné (`$meta->searchables`). |
| `rename` | `{col: propName}` | Přepsání automaticky odvozeného názvu property pro daný sloupec. |
| `override` | `{col: {…}}` | Přepsání odvozených hodnot (viz níže). |
| `virtual` | `{propName: {…}}` | Virtuální properties bez DB sloupce (viz níže). |
| `hasMany` | `{propName: {…}}` | HasMany kolekce — relace 1:N (viz sekci [Relace](#9-relace)). |
| `references` | `{col: {name, comment}}` | Přepsání názvů auto-detekovaných BelongsTo referencí. |
| `onClass` | callable / callable[] | Per-table hook(y) pro úpravu vygenerované třídy (viz sekci [11](#11-onclass-hook--úprava-vygenerovaných-tříd)). |

#### Klíče v `override` (per sloupec)

| Klíč | Typ | Popis |
|---|---|---|
| `type` | string | PHP typ property (`int`, `string`, `bool`, `\DateTime`, …). |
| `format` | string | Formát pro parsování DateTime. |
| `access` | `rw` / `r` / `w` | `rw` = getter + setter, `r` = jen getter, `w` = jen setter. |
| `comment` | string | PHPDoc komentář property. |
| `json` | `array` / `object` | Pro JSON sloupce — jak mapovat (`array` je výchozí). |

#### Klíče v `virtual` (per property)

| Klíč | Typ | Výchozí | Popis |
|---|---|---|---|
| `type` | string | `mixed` | PHP typ. |
| `access` | `r` / `rw` | `r` | Getter/setter generování. |
| `nullable` | bool | `true` | Může být null. |
| `comment` | string | — | PHPDoc komentář. |

#### Klíče v `hasMany` (per kolekce)

| Klíč | Typ | Výchozí | Popis |
|---|---|---|---|
| `through` | string | **Povinné.** | Název cizího klíče v odkazované tabulce. |
| `table` | string | klíč (název property) | Název DB tabulky (pokud se liší od názvu property). |
| `condition` | string | — | Extra SQL podmínka kolekce. |
| `comment` | string | — | PHPDoc komentář. |

---

## 5. Generování entit

Generátor čte databázové schéma přes `Nette\Database\Structure` a zapisuje PHP třídy pomocí `Nette\PhpGenerator`. Před každým generováním smaže staré soubory v output adresáři (kromě souborů v `cleanSkip`).

### Spuštění

```bash
vendor/bin/entity-database generate
```

Skript automaticky hledá bootstrap aplikace (`www/index.php` nebo `index.php`) v nadřazených adresářích.

### Vlastní bootstrap

Pokud standardní autodedekce selže (nestandardní layout projektu, monorepo, Docker…):

```bash
vendor/bin/entity-database generate --bootstrap app/bootstrap.php
# nebo zkrácená forma
vendor/bin/entity-database generate -b app/bootstrap.php
```

### Výstup

```
Generating entities…
  ✔ /var/www/app/Entity/User.php
  ✔ /var/www/app/Entity/Article.php
  ✔ /var/www/app/Entity/Order.php

Done. 3 file(s) written.
```

### Ochrana souborů před smazáním

Pokud máte v output adresáři ručně psané soubory (základní třídy, traity…), chraňte je přes `cleanSkip`:

```neon
entityDatabase:
    connections:
        default:
            entities:
                -   namespace: App\Entity
                    output: %appDir%/Entity
                    cleanSkip:
                        - BaseEntity.php
                    config:
                        - %appDir%/config/entities.neon
```

---

## 6. Vygenerovaná entita

Generátor vytvoří soubor přibližně v tomto tvaru:

```php
<?php

namespace App\Entity;

use Phlox\EntityDatabase\Entity\Attributes\Property;
use Phlox\EntityDatabase\Entity\Attributes\Table;
use Phlox\EntityDatabase\Entity\Entity;

/**
 * Uživatel aplikace
 */
#[Table(name: 'users')]
class User extends Entity
{
    #[Property(col: 'id', key: true, nullable: false, nativeType: 'int', type: 'int')]
    public int $id {
        get => $this->_ctx->modified['id'] ?? false
            ? $this->id
            : $this->_ctx->activeRow?->id ?? null;
        set(?int $value) {
            $this->id = $value;
            $this->_ctx->modified['id'] = true;
        }
    }

    /** E-mail pro přihlášení */
    #[Property(col: 'email', key: false, nullable: false, searchable: true, virtual: false, nativeType: 'varchar', type: 'string')]
    public string $email {
        get => $this->_ctx->modified['email'] ?? false
            ? $this->email
            : $this->_ctx->activeRow?->email ?? null;
        set(?string $value) {
            $this->email = $value;
            $this->_ctx->modified['email'] = true;
        }
    }

    #[Property(col: 'password_hash', key: false, nullable: true, nativeType: 'varchar', type: 'string')]
    public ?string $passwordHash {
        set(?string $value) {
            $this->passwordHash = $value;
            $this->_ctx->modified['passwordHash'] = true;
        }
    }

    #[Property(col: 'created_at', key: false, nullable: true, nativeType: 'timestamp', type: '\Nette\Utils\DateTime')]
    public ?\Nette\Utils\DateTime $createdAt = null {
        get => $this->_ctx->modified['createdAt'] ?? false
            ? $this->createdAt
            : $this->_ctx->activeRow?->created_at ?? null;
        set(string|\DateTimeInterface|null $value) {
            $this->createdAt = match(true) {
                $value === null => null,
                $value instanceof \DateTimeInterface => \Nette\Utils\DateTime::from($value),
                default => \Phlox\EntityDatabase\Entity\EntityHelpers::parseDateTime($value, 'Y-m-d H:i:s'),
            };
            $this->_ctx->modified['createdAt'] = true;
        }
    }

    #[Property(col: 'is_active', key: false, nullable: true, nativeType: 'tinyint', type: 'bool')]
    public ?bool $active = null {
        get => $this->_ctx->modified['active'] ?? false
            ? $this->active
            : $this->_ctx->activeRow?->is_active ?? null;
        set(?bool $value) {
            $this->active = $value;
            $this->_ctx->modified['active'] = true;
        }
    }

    #[Property(type: '\App\Entity\Role', ref: 'roles', through: 'role_id')]
    public ?\App\Entity\Role $role = null {
        get => ($row = $this->_ctx->activeRow?->ref('roles', 'role_id')) === null
            ? null
            : $this->_ctx->selection?->entityFactory->create($row, $this->_ctx->selection);
    }

    #[Property(related: 'orders', through: 'user_id')]
    public \Phlox\EntityDatabase\Database\Table\GroupedSelection $orders {
        get => $this->_ctx->selection->createGroupedSelection(
            $this->_ctx->activeRow->related('orders', 'user_id')
        );
    }
}
```

Vlastnosti jsou **skutečné typované PHP 8.4 property s hooks** (`get`/`set`), ne jen doc-komentáře — IDE (PhpStorm, VS Code s Intelephense) je díky tomu nabízí v autocomplete přirozeně, bez nutnosti `@property` anotací. `#[Property]` atribut na každé vlastnosti nese metadata pro `MetaStorage` (searchable, nativeType, relace…), skutečný název a přístupová práva (r/w) generátor odvozuje za běhu z reflexe hooků.

---

## 7. Použití v aplikaci

### Injektujte `Phlox\EntityDatabase\Database\Explorer`

Entity Database registruje vlastní `Explorer`, který obaluje nativní Nette `Explorer`:

```php
use Phlox\EntityDatabase\Database\Explorer;

class UserRepository
{
    public function __construct(private Explorer $db) {}
}
```

### Čtení dat

```php
// Jeden záznam podle primárního klíče → User|null
$user = $this->db->table('users')->get(1);

// Fetch jednoho záznamu s podmínkou → User|null
$user = $this->db->table('users')
    ->where('email', 'jan@example.com')
    ->fetch();

// Všechny záznamy → User[]
$users = $this->db->table('users')
    ->where('active', 1)
    ->order('created_at DESC')
    ->fetchAll();

// Pairs → [id => email]
$pairs = $this->db->table('users')
    ->fetchPairs('id', 'email');
```

### Přístup k vlastnostem

```php
$user = $this->db->table('users')->get(1);

echo $user->email;
echo $user->createdAt->format('d.m.Y');
echo $user->active ? 'aktivní' : 'neaktivní';
```

### Vkládání a aktualizace

```php
// Insert → vrátí instanci User
$user = $this->db->table('users')->insert([
    'email'         => 'jan@example.com',
    'password_hash' => password_hash('secret', PASSWORD_DEFAULT),
    'is_active'     => true,
]);

// Hromadná aktualizace přes Selection (syrové sloupce, bez ohledu na entitu)
$this->db->table('users')
    ->where('id', 1)
    ->update(['is_active' => false]);

// Aktualizace jednoho záznamu přes typované settery + saveModified()
$user->email = 'novy@example.com';   // jen nastaví dirty flag, nic nezapisuje
$user->saveModified();               // teprve tady se zapíše UPDATE, scopovaný podle PK

// Smazání jednoho záznamu
$user->delete();
```

`saveModified()` zapíše jen properties změněné od posledního fetchnutí/uložení (přes `_ctx->modified`), ne celý řádek — a po úspěchu dirty stav vyčistí. Bez podkladového `ActiveRow` (např. ručně vytvořená entita) obě metody vyhodí `EntityException::INVALID_CALL` — insert nového řádku jde vždy přes `Selection::insert()`.

### Repository pattern — CRUD + heslo

Typický vzor v aplikaci: repository třída obalující `Explorer` pro jednu entitu. Kompletní, otestovaný příklad je v [`examples/sqlite/UserRepository.php`](../examples/sqlite/UserRepository.php) (ověření v [`examples/sqlite/repository-test.php`](../examples/sqlite/repository-test.php)):

```php
final class UserRepository
{
    public function __construct(private readonly Explorer $db) {}

    public function create(string $email, string $plainPassword): User
    {
        return $this->db->table('users')->insert([
            'email'         => $email,
            'password_hash' => password_hash($plainPassword, PASSWORD_DEFAULT),
            'is_active'     => true,
        ]);
    }

    public function setPassword(User $user, string $newPlainPassword): bool
    {
        $user->passwordHash = password_hash($newPlainPassword, PASSWORD_DEFAULT);
        return $user->saveModified();
    }

    public function verifyPassword(User $user, string $plainPassword): bool
    {
        $hash = $user->toArrayDb()['password_hash'] ?? null;
        return is_string($hash) && password_verify($plainPassword, $hash);
    }

    public function delete(User $user): int
    {
        return $user->delete();
    }
}
```

Klíčový detail: `password_hash` má v `entities.neon` `override: { password_hash: { access: w } }`. `$user->passwordHash` nejde přečíst přes typovanou property — pokus o to vyhodí `EntityException::WRITE_ONLY_PROPERTY` — takže haš se nemůže náhodou objevit v `toArray()`, logu, JSON exportu, `foreach ($user as ...)` apod. Jediné cesty, jak se k uloženému hashi dostat, jsou `Entity::toArrayDb()` (typicky pro `password_verify()` při loginu) a `toModifiedArray()`/`toModifiedArrayDb()` (pro manuální/hromadné uložení) — obě záměrně obcházejí property hooky a čtou raw hodnotu.

**`access: w` ale není bezpečnostní hranice** — kdokoliv smí zavolat `toArrayDb()`/`toModifiedArrayDb()` a haš dostane, stejně jako dřív. Chrání jen proti *náhodnému* úniku přes implicitní/generické cesty čtení entity (obyčejný property read, `toArray()`, iterace, serializace) — proti chybě typu „vrátím celou entitu z controlleru". Cesty, které haš skutečně vrací, mají v názvu `Db`/`Modified`, takže je z volajícího kódu vidět, že jde o záměrný, nízkoúrovňový přístup, ne o omyl.

### Jak je `access: r`/`access: w` skutečně vynucené

PHP 8.4 property hooks toto samo o sobě nehlídají tak, jak by se čekalo — pouhé vynechání `get`/`set` hooku **nestačí**:

- Property jen s `get` hookem (žádný `set`) **defaultně pořád přijme obyčejný zápis zvenku** (jde rovnou do backing storage, mimo náš hook). Generátor proto u `access: r` sloupcových a virtuálních properties navíc nastaví asymetrickou viditelnost (`public private(set)`), která zápis zvenku skutečně zablokuje.
- U BelongsTo/HasMany properties (`role`, `orders`, …) tohle řešit nemusí — jejich `get` hook nikdy nesahá na vlastní backing storage (pokaždé se přepočítává z `activeRow`), takže je PHP samo bere jako plně virtuální a zápis zvenku odmítne bez nutnosti cokoliv nastavovat (asymetrická viditelnost by tam navíc ani nešla použít — PHP ji na "storage-less" property nedovolí).
- Naopak property jen se `set` hookem (žádný `get`) **defaultně pořád dovolí obyčejné čtení zvenku** — přesně proto `access: w` property v runtime vždy sama vyhodí výjimku ve svém `get` hooku, viz `passwordHash` výše. Asymetrická viditelnost tady nejde použít v opačném směru (PHP odmítne "get slabší než set").

Metadata (`PropertyMeta::$access`) se odvozují přímo z `#[Property]` atributu (co vygeneroval `PhpEntityRenderer`), ne zpětně z přítomnosti hooků — jakmile `access: w` property potřebuje vlastní (vyhazující) `get` hook kvůli vynucení, přítomnost hooku už sama o sobě nic nevypovídá o tom, jestli je property čitelná.

---

## 8. Atributy entit

Entity jsou popsány PHP 8 atributy. `MetaStorage` je čte přes Reflection a výsledek cachuje (invalidace při změně souboru entity).

### `#[Table]`

```php
#[Table(name: 'users')]
class User extends Entity
```

| Parametr | Typ | Popis |
|---|---|---|
| `name` | string | Název DB tabulky. |

### `#[Property]`

Popisuje jednu property entity.

| Parametr | Typ | Výchozí | Popis |
|---|---|---|---|
| `col` | string | — | Název sloupce v DB. Null pro virtuální properties. |
| `type` | string | `string` | PHP typ. |
| `nativeType` | string | — | Nativní DB typ (`varchar`, `int`, `timestamp`, …). |
| `key` | bool | `false` | Primární klíč. |
| `nullable` | bool | `true` | Hodnota může být null. |
| `searchable` | bool | `false` | Zahrne do `EntityMeta::$searchables`. |
| `format` | string | — | DateTime formát pro parsování. |
| `virtual` | bool | `false` | Virtuální property (bez DB sloupce). |
| `json` | string | — | `'array'` nebo `'object'` pro JSON sloupce. |
| `ref` | string | — | Tabulka pro BelongsTo relaci. |
| `related` | string | — | Tabulka pro HasMany relaci. |
| `through` | string | — | Cizí klíč relace. |
| `condition` | string | — | Extra SQL podmínka pro HasMany. |

### Přístup k metadatům za běhu

```php
use Phlox\EntityDatabase\Metadata\MetaStorage;

$meta = MetaStorage::get(User::class);

$meta->table;           // 'users'
$meta->byProp['email']; // PropertyMeta pro $email
$meta->byCol['email'];  // PropertyMeta přes název sloupce
$meta->searchables;     // ['email' => 'email', ...] — jen searchable
```

### Vyhledávání přes `searchable` sloupce

`searchable: true` samo o sobě jen označí sloupec v metadatech — reálné vyhledávání nad nimi provede `Selection::search()`:

```php
$users = $this->db->table('users')->search('jan')->fetchAll();
// najde 'jan@example.com', 'Jan Novák', 'JAN@EXAMPLE.COM', ... — case-insensitive
// OR přes všechny sloupce s searchable: true na dané entitě
```

`search()` hledá `%term%` (obsahuje, case-insensitive) přes všechny `searchable` sloupce entity najednou (OR). Když entita nemá žádný `searchable` sloupec, vyhodí `SearchException`.

Volitelně lze zapnout i ignorování diakritiky:

```php
$users = $this->db->table('users')->search('jan', ignoreDiacritics: true)->fetchAll();
// najde i 'Ján', 'Jaň', ...
```

Diakritika-insensitivní hledání je DB-specifické a vyžaduje podporu na straně databáze:

| DB | Jak to funguje | Požadavek |
|---|---|---|
| MySQL 8+ | `COLLATE UTF8MB4_0900_AI_CI` | MySQL 8.0+ (neexistuje v 5.7) |
| PostgreSQL | `unaccent()` | rozšíření `unaccent` musí být zapnuté: `CREATE EXTENSION IF NOT EXISTS unaccent;` |
| SQL Server | `COLLATE LATIN1_GENERAL_CI_AI` | funguje out-of-the-box |
| SQLite | — | **nepodporováno**, `search(..., ignoreDiacritics: true)` vyhodí `SearchException` |

> **Pozor na výkon:** `LOWER()`/`COLLATE`/`unaccent()` na sloupci obvykle znemožní použití běžného indexu (změní se na sekvenční scan), pokud nemáte funkční/expression index přímo na tomto výrazu. Pro velké tabulky zvažte spíš plnohodnotné fulltextové vyhledávání (Postgres `tsvector`, MySQL `FULLTEXT`, …) — to `search()` neřeší, je to jen pohodlné `LIKE` napříč sloupci.

---

## 9. Relace

### BelongsTo (N:1) — odkaz na jiný záznam

Detekuje se automaticky z cizích klíčů v DB. Název property odvodí naming strategie (např. `role_id` → `$role`).

Přepsání názvu přes `references`:
```neon
users:
    class: User
    references:
        role_id: { name: role, comment: "Role uživatele" }
```

Použití:
```php
$user = $this->db->table('users')->get(1);
echo $user->role->name; // $role je instance Role
```

### HasMany (1:N) — kolekce záznamů

Definujete přes `hasMany`:

```neon
users:
    class: User
    hasMany:
        orders: { through: user_id }
        activeOrders:
            table: orders
            through: user_id
            condition: "status != 'cancelled'"
```

Klíč v `hasMany` je **název property**. `table` se odvodí z klíče, pokud není uvedeno.

Použití:
```php
$user = $this->db->table('users')->get(1);

foreach ($user->orders as $order) {
    echo $order->totalPrice;
}
```

`$user->orders` vrací `GroupedSelection` — lazy-loaded, DB dotaz proběhne až při první iteraci.

---

## 10. Pojmenovávací strategie

Strategie převádí název sloupce na název PHP property.

### Výchozí: CamelCase

```
user_id        → userId        (cizí klíče → refToProperty: user)
created_at     → createdAt
INVOICE_NUMBER → invoiceNumber
category_id    → category      (odřízne 'Id')
```

### PascalCase

```
created_at     → CreatedAt
user_id        → UserId
```

### Nastavení v konfiguraci

```neon
entityDatabase:
    connections:
        default:
            namingStrategy: Phlox\EntityDatabase\Generator\Naming\PascalCaseNamingStrategy
```

### Vlastní strategie

```php
use Phlox\EntityDatabase\Generator\Naming\NamingStrategy;

class MyNamingStrategy implements NamingStrategy
{
    public function columnToProperty(string $name): string
    {
        return $name; // vaše logika
    }

    public function refToProperty(string $name): string
    {
        return $name; // vaše logika pro cizí klíče
    }
}
```

```neon
entityDatabase:
    connections:
        default:
            namingStrategy: App\Generator\MyNamingStrategy
```

---

## 11. onClass hook — úprava vygenerovaných tříd

`onClass` umožňuje přidat do vygenerované entity libovolný kód (metody, traity, konstanty, komentáře…) **per tabulka**, přímo v `entities.neon`. Hook se volá při každém `generate`, takže customizace jsou reprodukovatelné a verzovatelné.

Hook dostane instanci `ClassEventData` s přístupem ke všemu, co `Nette\PhpGenerator` nabízí.

### Jeden hook na tabulku

```neon
users:
    class: User
    onClass: [App\Generator\EntityHook, onUser]
```

### Více hooků na tabulku

```neon
users:
    class: User
    onClass:
        - [App\Generator\EntityHook, addTimestamps]
        - [App\Generator\UserHook, addAuthMethods]
```

### Implementace

```php
namespace App\Generator;

use Phlox\EntityDatabase\Generator\ClassEventData;

class EntityHook
{
    public function onUser(ClassEventData $data): void
    {
        // Přidat use trait
        $data->class->addTrait(\App\Entity\Traits\SoftDeleteTrait::class);

        // Přidat metodu
        $data->class->addMethod('getFullName')
            ->setReturnType('string')
            ->setBody('return trim($this->firstName . " " . $this->lastName);');

        // Přidat konstantu
        $data->class->addConstant('ROLE_ADMIN', 'admin')->setPublic();
    }

    public function addTimestamps(ClassEventData $data): void
    {
        // Přidat metodu na všechny entity (volat z více hooků)
        $data->class->addMethod('touch')
            ->setReturnType('void')
            ->setBody('$this->updatedAt = new \DateTime();');
    }
}
```

### Dostupné objekty v `ClassEventData`

| Property | Typ | Popis |
|---|---|---|
| `$class` | `Nette\PhpGenerator\ClassType` | Vygenerovaná třída — **zde přidávejte metody, traity, …** |
| `$namespace` | `Nette\PhpGenerator\PhpNamespace` | Namespace souboru — přidávejte `use` importy. |
| `$phpFile` | `Nette\PhpGenerator\PhpFile` | Celý PHP soubor. |
| `$entity` | `EntityDefinition` | Definice entity (tabulka, properties, relace, …). |

### Registrace hookovací service v DI

Hookovaná třída musí být zaregistrovaná v Nette DI kontejneru (aby ji generátor mohl získat přes `getByType()`):

```neon
services:
    - App\Generator\EntityHook
    - App\Generator\UserHook
```

---

## 12. Vlastní generátorový adaptér

Generátor převádí nativní DB typy na PHP typy přes adaptér. Balíček obsahuje adaptéry pro MySQL, PostgreSQL a generický fallback (ten se použije i pro jakoukoliv jinou DB, kterou Nette Database podporuje — SQLite, MS SQL, Oracle). Adaptér se vybírá automaticky podle DSN.

> Firebird adaptér zde záměrně není — `nette/database` samo o sobě nemá Firebird driver (`Nette\Database\Drivers\FirebirdDriver` neexistuje), takže připojení na Firebird přes tuto knihovnu dnes není možné bez ohledu na generátorový adaptér.

```php
use Phlox\EntityDatabase\Generator\Adapter\GeneratorAdapter;

class MyAdapter implements GeneratorAdapter
{
    public function convertType(string $nativeType, array $vendor): string
    {
        return match (strtolower($nativeType)) {
            'money' => 'float',
            'uuid'  => 'string',
            'jsonb' => 'array',
            default => 'mixed',
        };
    }

    public function getDatabaseIdent(): string
    {
        return 'mydb'; // musí odpovídat prefixu DSN
    }
}
```

---

## 13. Inline konfigurace

M�sto externích souborů lze definovat tabulky přímo v `neon` konfiguraci aplikace:

```neon
entityDatabase:
    connections:
        default:
            entities:
                -   namespace: App\Entity
                    output: %appDir%/Entity
                    config:
                        - %appDir%/config/entities.neon   # soubor
                        -                                  # inline blok
                            settings:
                                class: Setting
```

JSON soubory jsou také podporovány:

```neon
config:
    - %appDir%/config/entities.json
```

```json
{
    "users": { "class": "User" },
    "orders": { "class": "Order" }
}
```

Při duplicitním klíči tabulky vyhrává poslední definice (merge zleva doprava).

---

## 14. Referenční tabulka — konfigurace balíčku

```neon
entityDatabase:
    connections:
        <název_spojení>:
            namingStrategy: <třída>       # volitelné, výchozí CamelCaseNamingStrategy
            entities:
                -   namespace: <string>   # povinné
                    output: <string>      # povinné
                    cleanSkip:            # volitelné
                        - <filename>
                    config:               # povinné
                        - <cesta.neon>
                        - <cesta.json>
                        -                 # inline
                            <tabulka>:
                                class: <string>
                                comment: <string>
                                extends: <string>
                                skip: [<col>, …]
                                searchable: [<col>, …]
                                rename:
                                    <col>: <propName>
                                override:
                                    <col>:
                                        type: <string>
                                        format: <string>
                                        access: rw|r|w
                                        comment: <string>
                                        json: array|object
                                virtual:
                                    <propName>:
                                        type: <string>
                                        access: r|rw
                                        nullable: <bool>
                                        comment: <string>
                                hasMany:
                                    <propName>:
                                        through: <col>          # povinné
                                        table: <string>         # výchozí = propName
                                        condition: <string>
                                        comment: <string>
                                references:
                                    <col>:
                                        name: <propName>
                                        comment: <string>
                                onClass: [<Třída>, <metoda>]
                                # nebo více hooků:
                                onClass:
                                    - [<Třída>, <metoda>]
```

---

## 15. Architektura

```
src/
├── Console/
│   ├── EntityDatabaseApplication.php   CLI Symfony Console aplikace
│   └── Command/Generate.php            Příkaz 'generate' (+ --bootstrap option)
│
├── Database/
│   ├── Explorer.php                    Wrapper nad Nette Explorer
│   └── Table/
│       ├── Selection.php               Wrapper nad Nette Selection → vrací Entity
│       └── GroupedSelection.php        Totéž pro relační (grouped) selection
│
├── DI/
│   └── Extension.php                   Nette DI CompilerExtension
│
├── Entity/
│   ├── Entity.php                      Abstraktní základní třída entit
│   ├── EntityContext.php               Vnitřní stav entity (activeRow, modified, meta)
│   ├── EntityFactory.php               Mapuje tabulka → třída, vytváří instance
│   ├── EntityHelpers.php               Parsování DateTime (EntityHelpers::parseDateTime)
│   └── Attributes/
│       ├── Table.php                   #[Table(name: '...')]
│       └── Property.php                #[Property(col: '...', type: '...', ...)]
│
├── Exception/
│   ├── EntityException.php
│   ├── MappingException.php
│   ├── GeneratorException.php
│   └── MetadataException.php
│
├── Generator/
│   ├── EntityGenerator.php             Orchestruje generování
│   ├── ClassEventData.php              DTO pro onClass hook
│   ├── DataTypeEventData.php           DTO pro onDataType (adaptéry)
│   ├── Adapter/
│   │   ├── GeneratorAdapter.php        Interface
│   │   ├── GenericGeneratorAdapter.php Generický fallback
│   │   ├── MysqlGeneratorAdapter.php
│   │   └── PgsqlGeneratorAdapter.php
│   ├── Naming/
│   │   ├── NamingStrategy.php          Interface
│   │   ├── CamelCaseNamingStrategy.php (výchozí)
│   │   └── PascalCaseNamingStrategy.php
│   ├── Renderer/
│   │   └── PhpEntityRenderer.php       Zapisuje PHP soubor, volá onClass hooky
│   └── Schema/
│       ├── EntityDefinition.php        Kompletní popis entity (vč. onClass callables)
│       ├── EntityDefinitionBuilder.php
│       ├── EntityPropDefinition.php
│       ├── EntityRefDefinition.php
│       └── EntityRelatedDefinition.php
│
└── Metadata/
    ├── MetaStorage.php                 Reflection cache (invalidace při změně souboru)
    ├── EntityMeta.php                  Metadata entity
    └── PropertyMeta.php                Metadata property
```

### Tok generování

```
vendor/bin/entity-database generate
    └─► EntityGenerator::generate()
            ├─► resolveTableConfigs()     — slije NEON/JSON/inline zdroje
            ├─► defineClass()
            │       ├─► addClassProperties()       — sloupce z DB
            │       ├─► addClassVirtualProperties() — virtual: blok
            │       ├─► addClassRefProperties()     — FK → BelongsTo
            │       ├─► addClassHasManyProperties() — hasMany: blok
            │       └─► resolveCallbacks()          — [Třída, metoda] → callable[] (onClass i onDataType)
            └─► PhpEntityRenderer::render()
                    ├─► sestaví ClassType se skutečnými hookovanými properties (get/set)
                    ├─► volá onClass hooky           ← zde se přidávají metody, traity…
                    └─► zapíše PHP soubor
```

### Tok čtení dat

```
$db->table('users')->fetch()
    └─► Selection::fetch()
            └─► NSelection::fetch() → ActiveRow
                    └─► EntityFactory::create(ActiveRow, Selection)
                            └─► new User(ActiveRow, Selection)
```

---

## 16. Výjimky

| Třída | Kód | Kdy nastane |
|---|---|---|
| `MappingException` | `MappingNotFound` (1) | Tabulka nemá nakonfigurovanou entitu |
| `MappingException` | `ClassNotFound` (2) | Entita je nakonfigurována, ale třída neexistuje |
| `EntityException` | `INVALID_FORMAT` (1) | Nelze parsovat DateTime nebo neplatný typ |
| `EntityException` | `INVALID_CALL` (2) | Neplatné volání metody na entitě (mj. `saveModified()`/`delete()`/`__call` bez podkladového `ActiveRow`) |
| `EntityException` | `WRITE_ONLY_PROPERTY` (3) | Čtení property s `access: w` (např. `password_hash`) — použij `Entity::toArrayDb()` |
| `EntityException` | `UNDECLARED_PROPERTY` (4) | Zápis/`unset()` na property, kterou entita nemá (typo, nebo patří do `virtual:`) |
| `SearchException` | `NoSearchableProperties` (1) | `Selection::search()` na entitě bez `searchable: true` sloupce |
| `SearchException` | `DiacriticsNotSupported` (2) | `search(..., ignoreDiacritics: true)` na driveru bez podpory (SQLite) |
| `\Nette\IOException` | — | Konfigurační soubor entit nenalezen |
| `\Nette\InvalidArgumentException` | — | Chybí povinný klíč v konfiguraci (`class`, `through`, …) |
