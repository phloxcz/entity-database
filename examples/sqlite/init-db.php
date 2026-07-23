<?php
/**
 * Creates (or recreates) the demo SQLite database with a few seed rows.
 * Run before generate.php / demo.php.
 */

declare(strict_types=1);

$dbFile = __DIR__ . '/data/app.db';

if (!is_dir(dirname($dbFile))) {
    mkdir(dirname($dbFile), 0777, true);
}
if (is_file($dbFile)) {
    unlink($dbFile);
}

$pdo = new PDO('sqlite:' . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON');

$pdo->exec('
    CREATE TABLE roles (
        id   INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL
    )
');

$pdo->exec('
    CREATE TABLE users (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        email         TEXT NOT NULL,
        password_hash TEXT,
        created_at    TIMESTAMP,
        is_active     INTEGER,
        role_id       INTEGER REFERENCES roles(id)
    )
');

$pdo->exec("
    CREATE TABLE orders (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id      INTEGER NOT NULL REFERENCES users(id),
        total_price  REAL NOT NULL,
        status       TEXT NOT NULL DEFAULT 'new',
        created_at   TIMESTAMP
    )
");

// Table intentionally NOT listed in entities.neon — used to demo MappingException.
$pdo->exec('
    CREATE TABLE audit_log (
        id      INTEGER PRIMARY KEY AUTOINCREMENT,
        message TEXT NOT NULL
    )
');

$pdo->exec("INSERT INTO roles (id, name) VALUES (1, 'admin'), (2, 'member')");

$pdo->exec("INSERT INTO users (id, email, password_hash, created_at, is_active, role_id) VALUES
    (1, 'admin@example.com',  'hash1', '2024-01-15 10:00:00', 1, 1),
    (2, 'member@example.com', 'hash2', '2024-02-20 09:30:00', 1, 2)
");

$pdo->exec("INSERT INTO orders (user_id, total_price, status, created_at) VALUES
    (1, 150.50, 'completed', '2024-03-01 12:00:00'),
    (1,  42.00, 'cancelled', '2024-03-05 08:00:00'),
    (2,  99.99, 'new',       '2024-03-10 15:00:00')
");

$pdo->exec("INSERT INTO audit_log (message) VALUES ('example seed data created')");

echo "SQLite DB created at {$dbFile}\n";
