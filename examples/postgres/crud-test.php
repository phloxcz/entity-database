<?php
/**
 * CRUD smoke test against real PostgreSQL, focused on the type-mapping edge
 * cases: BOOLEAN, JSONB → array, TEXT[] → array, NUMERIC → float, TIMESTAMP →
 * DateTime, plus BelongsTo/HasMany relations and update/delete.
 */

declare(strict_types=1);

use Phlox\EntityDatabase\Database\Explorer;

/** @var Nette\DI\Container $container */
$container = require __DIR__ . '/bootstrap.php';

$db = $container->getByType(Explorer::class);

$failures = 0;
function check(bool $cond, string $label): void
{
    global $failures;
    if ($cond) {
        echo "  PASS  {$label}\n";
    } else {
        echo "  FAIL  {$label}\n";
        $failures++;
    }
}

echo "== CREATE ==\n";
$role = $db->table('roles')->insert([
    'name'        => 'guest',
    'permissions' => '{read}',
    'level'       => 'junior',
]);
check($role instanceof \Examples\Postgres\Entity\Role, 'insert() on roles returns a Role entity');

$user = $db->table('users')->insert([
    'email'       => 'crud@example.com',
    'is_active'   => true,
    'preferences' => json_encode(['theme' => 'system']),
    'balance'     => 12.34,
    'created_at'  => '2024-05-01 00:00:00',
    'role_id'     => $role->id,
]);
check($user instanceof \Examples\Postgres\Entity\User, 'insert() on users returns a User entity');

echo "\n== READ (typed round-trip) ==\n";
$fresh = $db->table('users')->get($user->id);
check(is_bool($fresh->isActive) && $fresh->isActive === true, 'BOOLEAN round-trips as bool');
check(is_array($fresh->preferences) && $fresh->preferences['theme'] === 'system', 'JSONB round-trips as array');
check(is_float($fresh->balance) && abs($fresh->balance - 12.34) < 0.001, 'NUMERIC round-trips as float');
check($fresh->createdAt instanceof \Nette\Utils\DateTime, 'TIMESTAMP round-trips as DateTime');
check($fresh->role instanceof \Examples\Postgres\Entity\Role && $fresh->role->name === 'guest', 'BelongsTo ref resolves');
check(is_array($fresh->role->permissions) && $fresh->role->permissions === ['read'], 'TEXT[] round-trips as array');
check(is_string($fresh->role->level) && $fresh->role->level === 'junior', 'VARCHAR round-trips as string');

echo "\n== hasMany (seeded admin user) ==\n";
$admin = $db->table('users')->where('email', 'admin@example.com')->fetch();
$orderCount = 0;
foreach ($admin->orders as $order) {
    $orderCount++;
    printf("  order #%d %.2f %s\n", $order->id, $order->totalPrice, $order->status);
}
check($orderCount === 2, 'hasMany orders iterates seeded rows (2)');

echo "\n== UPDATE ==\n";
$affected = $db->table('users')->where('id', $user->id)->update(['is_active' => false, 'balance' => 99.99]);
check($affected === 1, 'update() affects 1 row');
$updated = $db->table('users')->get($user->id);
check($updated->isActive === false, 'updated BOOLEAN reads back as false');
check(abs($updated->balance - 99.99) < 0.001, 'updated NUMERIC reads back correctly');

echo "\n== DELETE ==\n";
$deleted = $db->table('users')->where('id', $user->id)->delete();
check($deleted === 1, 'delete() affects 1 row');
$gone = $db->table('users')->get($user->id);
check($gone === null, 'row is gone after delete');

echo "\n" . ($failures === 0 ? "ALL CHECKS PASSED\n" : "{$failures} CHECK(S) FAILED\n");
exit($failures === 0 ? 0 : 1);
