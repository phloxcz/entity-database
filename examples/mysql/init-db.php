<?php
/**
 * Creates (or recreates) the demo MySQL schema and seeds it — deliberately
 * uses column types that exercise the type-mapping edge cases: TINYINT(1)
 * (boolean convention), JSON, SET, ENUM, DECIMAL, DATETIME, FKs.
 *
 * Connection is controlled by the same ENTITYDB_MYSQL_* env vars as bootstrap.php.
 */

declare(strict_types=1);

$host = getenv('ENTITYDB_MYSQL_HOST') ?: '127.0.0.1';
$port = getenv('ENTITYDB_MYSQL_PORT') ?: '3316';
$name = getenv('ENTITYDB_MYSQL_DB') ?: 'entity_database_example';
$user = getenv('ENTITYDB_MYSQL_USER') ?: 'root';
$pass = getenv('ENTITYDB_MYSQL_PASSWORD') ?: '';

$pdo = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->exec("CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET utf8mb4");
$pdo->exec("USE `{$name}`");

$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
$pdo->exec('DROP TABLE IF EXISTS orders');
$pdo->exec('DROP TABLE IF EXISTS users');
$pdo->exec('DROP TABLE IF EXISTS roles');
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

$pdo->exec("
    CREATE TABLE roles (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        name        VARCHAR(50) NOT NULL,
        permissions SET('read','write','admin') NOT NULL DEFAULT '',
        level       ENUM('junior','senior','lead') NOT NULL DEFAULT 'junior'
    )
");

$pdo->exec("
    CREATE TABLE users (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        email       VARCHAR(255) NOT NULL,
        is_active   TINYINT(1) NOT NULL DEFAULT 1,
        preferences JSON NULL,
        balance     DECIMAL(10,2) NOT NULL DEFAULT 0,
        created_at  DATETIME NULL,
        role_id     INT NULL,
        FOREIGN KEY (role_id) REFERENCES roles(id)
    )
");

$pdo->exec("
    CREATE TABLE orders (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        user_id     INT NOT NULL,
        total_price DECIMAL(10,2) NOT NULL,
        status      ENUM('new','completed','cancelled') NOT NULL DEFAULT 'new',
        created_at  DATETIME NULL,
        FOREIGN KEY (user_id) REFERENCES users(id)
    )
");

$pdo->exec("INSERT INTO roles (name, permissions, level) VALUES
    ('admin',  'read,write,admin', 'lead'),
    ('member', 'read',             'junior')
");

$pdo->exec('INSERT INTO users (email, is_active, preferences, balance, created_at, role_id) VALUES
    (\'admin@example.com\',  1, \'{"theme":"dark","locale":"cs"}\',  150.75, \'2024-01-15 10:00:00\', 1),
    (\'member@example.com\', 1, \'{"theme":"light"}\',                 0.00, \'2024-02-20 09:30:00\', 2)
');

$pdo->exec("INSERT INTO orders (user_id, total_price, status, created_at) VALUES
    (1, 150.50, 'completed', '2024-03-01 12:00:00'),
    (1,  42.00, 'cancelled', '2024-03-05 08:00:00')
");

echo "MySQL schema created + seeded in database '{$name}' at {$host}:{$port}\n";
