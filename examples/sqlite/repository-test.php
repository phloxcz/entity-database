<?php
/**
 * End-to-end verification of UserRepository — CRUD + password handling,
 * including the write-only password_hash + toArrayDb() pattern.
 */

declare(strict_types=1);

use Examples\Sqlite\Entity\User;
use Examples\Sqlite\UserRepository;
use Phlox\EntityDatabase\Database\Explorer;

/** @var Nette\DI\Container $container */
$container = require __DIR__ . '/bootstrap.php';

$db   = $container->getByType(Explorer::class);
$repo = new UserRepository($db);

$failures = 0;
function check(bool $cond, string $label): void
{
    global $failures;
    echo ($cond ? "  PASS  " : "  FAIL  ") . $label . "\n";
    if (!$cond) $failures++;
}

echo "== CREATE ==\n";
$user = $repo->create('repo-test@example.com', 'correct-horse-battery-staple');
check($user instanceof User, 'create() returns a User entity');
check($user->email === 'repo-test@example.com', 'email set correctly');
check($user->isActive === true, 'is active by default');

echo "\n== password is hashed, not stored as plaintext ==\n";
$rawHash = $user->toArrayDb()['password_hash'] ?? null;
check(is_string($rawHash) && $rawHash !== 'correct-horse-battery-staple', 'stored hash differs from the plaintext password');
check(is_string($rawHash) && password_verify('correct-horse-battery-staple', $rawHash), 'stored hash is a genuine password_hash() output');

echo "\n== password verification ==\n";
check($repo->verifyPassword($user, 'correct-horse-battery-staple') === true, 'correct password verifies');
check($repo->verifyPassword($user, 'wrong-password') === false, 'wrong password fails verification');

echo "\n== READ ==\n";
$found = $repo->find($user->id);
check($found !== null && $found->email === $user->email, 'find() by id works');
check($repo->findByEmail('repo-test@example.com') !== null, 'findByEmail() works');
check($repo->findByEmail('nobody@example.com') === null, 'findByEmail() returns null when not found');
$bySearch = $repo->search('repo-test');
check(count($bySearch) === 1 && $bySearch[array_key_first($bySearch)]->email === 'repo-test@example.com', 'search() finds the user');

echo "\n== UPDATE ==\n";
check($repo->update($user, ['email' => 'repo-test-renamed@example.com']) === true, 'update() returns true');
check($repo->find($user->id)->email === 'repo-test-renamed@example.com', 'update() persisted the change');

$repo->deactivate($user);
check($repo->find($user->id)->isActive === false, 'deactivate() persisted');
$repo->activate($user);
check($repo->find($user->id)->isActive === true, 'activate() persisted');

echo "\n== PASSWORD CHANGE ==\n";
$repo->setPassword($user, 'new-password-123');
check($repo->verifyPassword($user, 'new-password-123') === true, 'new password verifies');
check($repo->verifyPassword($user, 'correct-horse-battery-staple') === false, 'old password no longer verifies');

echo "\n== DELETE ==\n";
$id = $user->id;
check($repo->delete($user) === 1, 'delete() removes the row');
check($repo->find($id) === null, 'user is gone after delete');

echo "\n" . ($failures === 0 ? "ALL CHECKS PASSED\n" : "{$failures} CHECK(S) FAILED\n");
exit($failures === 0 ? 0 : 1);
