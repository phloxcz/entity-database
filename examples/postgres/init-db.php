<?php
/**
 * Creates (or recreates) the demo Postgres schema and seeds it — deliberately
 * uses column types that exercise the type-mapping edge cases: native BOOLEAN,
 * JSONB, TEXT[] arrays, NUMERIC, TIMESTAMP, FKs.
 *
 * Connection is controlled by the same ENTITYDB_PG_* env vars as bootstrap.php.
 */

declare(strict_types=1);

$host = getenv('ENTITYDB_PG_HOST') ?: '127.0.0.1';
$port = getenv('ENTITYDB_PG_PORT') ?: '5442';
$name = getenv('ENTITYDB_PG_DB') ?: 'entity_database_example';
$user = getenv('ENTITYDB_PG_USER') ?: 'postgres';
$pass = getenv('ENTITYDB_PG_PASSWORD') ?: 'postgres';

$pdo = new PDO("pgsql:host={$host};port={$port};dbname={$name}", $user, $pass);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->exec('DROP TABLE IF EXISTS orders');
$pdo->exec('DROP TABLE IF EXISTS users');
$pdo->exec('DROP TABLE IF EXISTS roles');

$pdo->exec("
    CREATE TABLE roles (
        id          SERIAL PRIMARY KEY,
        name        VARCHAR(50) NOT NULL,
        permissions TEXT[] NOT NULL DEFAULT '{}',
        level       VARCHAR(20) NOT NULL DEFAULT 'junior'
    )
");

$pdo->exec('
    CREATE TABLE users (
        id          SERIAL PRIMARY KEY,
        email       VARCHAR(255) NOT NULL,
        is_active   BOOLEAN NOT NULL DEFAULT true,
        preferences JSONB NULL,
        balance     NUMERIC(10,2) NOT NULL DEFAULT 0,
        created_at  TIMESTAMP NULL,
        role_id     INTEGER NULL REFERENCES roles(id)
    )
');

$pdo->exec("
    CREATE TABLE orders (
        id          SERIAL PRIMARY KEY,
        user_id     INTEGER NOT NULL REFERENCES users(id),
        total_price NUMERIC(10,2) NOT NULL,
        status      VARCHAR(20) NOT NULL DEFAULT 'new',
        created_at  TIMESTAMP NULL
    )
");

$pdo->exec("INSERT INTO roles (name, permissions, level) VALUES
    ('admin',  ARRAY['read','write','admin'], 'lead'),
    ('member', ARRAY['read'],                 'junior')
");

$pdo->exec('INSERT INTO users (email, is_active, preferences, balance, created_at, role_id) VALUES
    (\'admin@example.com\',  true, \'{"theme":"dark","locale":"cs"}\'::jsonb,  150.75, \'2024-01-15 10:00:00\', 1),
    (\'member@example.com\', true, \'{"theme":"light"}\'::jsonb,                 0.00, \'2024-02-20 09:30:00\', 2)
');

$pdo->exec("INSERT INTO orders (user_id, total_price, status, created_at) VALUES
    (1, 150.50, 'completed', '2024-03-01 12:00:00'),
    (1,  42.00, 'cancelled', '2024-03-05 08:00:00')
");

echo "Postgres schema created + seeded in database '{$name}' at {$host}:{$port}\n";
