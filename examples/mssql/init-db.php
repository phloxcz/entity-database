<?php
/**
 * Creates (or recreates) the demo SQL Server schema and seeds it —
 * deliberately uses column types that exercise the type-mapping edge cases:
 * BIT (boolean), NVARCHAR (unicode string), MONEY, UNIQUEIDENTIFIER,
 * DATETIME2, FKs.
 *
 * Connection is controlled by the same ENTITYDB_MSSQL_* env vars as bootstrap.php.
 */

declare(strict_types=1);

$host = getenv('ENTITYDB_MSSQL_HOST') ?: '127.0.0.1';
$port = getenv('ENTITYDB_MSSQL_PORT') ?: '1435';
$name = getenv('ENTITYDB_MSSQL_DB') ?: 'entity_database_example';
$user = getenv('ENTITYDB_MSSQL_USER') ?: 'sa';
$pass = getenv('ENTITYDB_MSSQL_PASSWORD') ?: 'EntityDb!Passw0rd';

$master = new PDO("sqlsrv:Server={$host},{$port};Database=master;Encrypt=no", $user, $pass);
$master->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$master->exec("IF DB_ID('{$name}') IS NULL CREATE DATABASE [{$name}]");
$master = null;

$pdo = new PDO("sqlsrv:Server={$host},{$port};Database={$name};Encrypt=no", $user, $pass);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->exec('DROP TABLE IF EXISTS orders');
$pdo->exec('DROP TABLE IF EXISTS users');
$pdo->exec('DROP TABLE IF EXISTS roles');

$pdo->exec('
    CREATE TABLE roles (
        id    INT IDENTITY(1,1) PRIMARY KEY,
        name  NVARCHAR(50) NOT NULL,
        level NVARCHAR(20) NOT NULL DEFAULT N\'junior\'
    )
');

$pdo->exec('
    CREATE TABLE users (
        id          INT IDENTITY(1,1) PRIMARY KEY,
        email       NVARCHAR(255) NOT NULL,
        is_active   BIT NOT NULL DEFAULT 1,
        balance     MONEY NOT NULL DEFAULT 0,
        external_id UNIQUEIDENTIFIER NOT NULL DEFAULT NEWID(),
        created_at  DATETIME2 NULL,
        role_id     INT NULL REFERENCES roles(id)
    )
');

$pdo->exec('
    CREATE TABLE orders (
        id          INT IDENTITY(1,1) PRIMARY KEY,
        user_id     INT NOT NULL REFERENCES users(id),
        total_price DECIMAL(10,2) NOT NULL,
        status      NVARCHAR(20) NOT NULL DEFAULT N\'new\',
        created_at  DATETIME2 NULL
    )
');

$pdo->exec("INSERT INTO roles (name, level) VALUES (N'admin', N'lead'), (N'member', N'junior')");

$pdo->exec("INSERT INTO users (email, is_active, balance, created_at, role_id) VALUES
    (N'admin@example.com',  1, 150.75, '2024-01-15 10:00:00', 1),
    (N'member@example.com', 1,   0.00, '2024-02-20 09:30:00', 2)
");

$pdo->exec("INSERT INTO orders (user_id, total_price, status, created_at) VALUES
    (1, 150.50, N'completed', '2024-03-01 12:00:00'),
    (1,  42.00, N'cancelled', '2024-03-05 08:00:00')
");

echo "SQL Server schema created + seeded in database '{$name}' at {$host}:{$port}\n";
