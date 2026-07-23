<?php
/**
 * Manual smoke test exercising the generated entities end-to-end:
 * typed reads, insert, BelongsTo ref, HasMany collection (+ condition),
 * virtual property, toArray()/searchables, and error handling.
 */

declare(strict_types=1);

use Phlox\EntityDatabase\Database\Explorer;
use Phlox\EntityDatabase\Exception\EntityException;
use Phlox\EntityDatabase\Exception\MappingException;
use Phlox\EntityDatabase\Metadata\MetaStorage;

/** @var Nette\DI\Container $container */
$container = require __DIR__ . '/bootstrap.php';

$db = $container->getByType(Explorer::class);

echo "== fetchAll users ==\n";
foreach ($db->table('users')->fetchAll() as $user) {
    printf(
        "#%d %s active=%s role=%s\n",
        $user->id,
        $user->email,
        var_export($user->isActive, true),
        $user->role?->name ?? 'null',
    );
}

echo "\n== get by primary key ==\n";
$u1 = $db->table('users')->get(1);
echo "{$u1->email} created " . $u1->createdAt->format('Y-m-d') . "\n";

echo "\n== hasMany: orders ==\n";
foreach ($u1->orders as $order) {
    printf("  order #%d %.2f %s\n", $order->id, $order->totalPrice, $order->status);
}

echo "\n== hasMany with condition: activeOrders ==\n";
foreach ($u1->activeOrders as $order) {
    printf("  active order #%d %.2f %s\n", $order->id, $order->totalPrice, $order->status);
}

echo "\n== insert ==\n";
$newUser = $db->table('users')->insert([
    'email'         => 'new@example.com',
    'password_hash' => 'hash3',
    'created_at'    => '2024-04-01 08:00:00',
    'is_active'     => 1,
    'role_id'       => 2,
]);
echo "inserted #{$newUser->id} {$newUser->email} (role: {$newUser->role->name})\n";

echo "\n== virtual property (set/get, not persisted) ==\n";
$u1->orderCount = count($u1->orders->fetchAll());
echo "orderCount = {$u1->orderCount}\n";

echo "\n== toArray() ==\n";
print_r($u1->toArray());

echo "\n== MetaStorage searchables ==\n";
print_r(MetaStorage::get($u1::class)->searchables);

echo "\n== error handling ==\n";
try {
    $db->table('audit_log')->fetch(); // exists in DB, but not mapped to an entity
} catch (MappingException $e) {
    echo "MappingException OK: {$e->getMessage()}\n";
}

$manual = new ($u1::class)();
try {
    $manual->update(['email' => 'x']); // __call on an entity without an ActiveRow
} catch (EntityException $e) {
    echo "EntityException OK: {$e->getMessage()}\n";
}

echo "\nDone.\n";
