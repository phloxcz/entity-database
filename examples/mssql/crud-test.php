<?php
/**
 * CRUD smoke test against real SQL Server, focused on the type-mapping edge
 * cases: BIT → bool (needs explicit cast, unlike MONEY/DATETIME2), NVARCHAR →
 * string, MONEY → float, DATETIME2 → DateTime, UNIQUEIDENTIFIER → string,
 * plus BelongsTo/HasMany relations and update/delete.
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
    'name'  => 'guest',
    'level' => 'junior',
]);
check($role instanceof \Examples\Mssql\Entity\Role, 'insert() on roles returns a Role entity');

$user = $db->table('users')->insert([
    'email'      => 'crud@example.com',
    'is_active'  => true,
    'balance'    => 12.34,
    'created_at' => '2024-05-01 00:00:00',
    'role_id'    => $role->id,
]);
check($user instanceof \Examples\Mssql\Entity\User, 'insert() on users returns a User entity');

echo "\n== READ (typed round-trip) ==\n";
$fresh = $db->table('users')->get($user->id);
check(is_bool($fresh->isActive) && $fresh->isActive === true, 'BIT round-trips as bool');
check(is_float($fresh->balance) && abs($fresh->balance - 12.34) < 0.001, 'MONEY round-trips as float');
check($fresh->createdAt instanceof \DateTimeInterface, 'DATETIME2 round-trips as DateTime');
check(is_string($fresh->externalId) && strlen($fresh->externalId) === 36, 'UNIQUEIDENTIFIER round-trips as string GUID');
check($fresh->role instanceof \Examples\Mssql\Entity\Role && $fresh->role->name === 'guest', 'BelongsTo ref resolves');
check(is_string($fresh->role->level) && $fresh->role->level === 'junior', 'NVARCHAR round-trips as string');

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
check($updated->isActive === false, 'updated BIT reads back as false');
check(abs($updated->balance - 99.99) < 0.001, 'updated MONEY reads back correctly');

echo "\n== DELETE ==\n";
$deleted = $db->table('users')->where('id', $user->id)->delete();
check($deleted === 1, 'delete() affects 1 row');
$gone = $db->table('users')->get($user->id);
check($gone === null, 'row is gone after delete');

echo "\n" . ($failures === 0 ? "ALL CHECKS PASSED\n" : "{$failures} CHECK(S) FAILED\n");
exit($failures === 0 ? 0 : 1);
