<?php
/**
 * Example repository showing a typical CRUD + password-handling pattern on
 * top of Phlox\EntityDatabase — see repository-test.php for a runnable
 * end-to-end verification of everything below.
 */

declare(strict_types=1);

namespace Examples\Sqlite;

use Examples\Sqlite\Entity\User;
use Phlox\EntityDatabase\Database\Explorer;

final class UserRepository
{
    public function __construct(private readonly Explorer $db)
    {
    }


    // -------------------------------------------------------------------------
    // Create
    // -------------------------------------------------------------------------

    public function create(string $email, string $plainPassword, bool $active = true, ?int $roleId = null): User
    {
        return $this->db->table('users')->insert([
            'email'         => $email,
            'password_hash' => $this->hashPassword($plainPassword),
            'is_active'     => $active,
            'created_at'    => (new \DateTime())->format('Y-m-d H:i:s'),
            'role_id'       => $roleId,
        ]);
    }


    // -------------------------------------------------------------------------
    // Read
    // -------------------------------------------------------------------------

    public function find(int $id): ?User
    {
        return $this->db->table('users')->get($id);
    }

    public function findByEmail(string $email): ?User
    {
        return $this->db->table('users')->where('email', $email)->fetch();
    }

    /** @return User[] */
    public function findActive(): array
    {
        return $this->db->table('users')->where('is_active', 1)->fetchAll();
    }

    /** @return User[] */
    public function search(string $term): array
    {
        return $this->db->table('users')->search($term)->fetchAll();
    }


    // -------------------------------------------------------------------------
    // Update
    // -------------------------------------------------------------------------

    /**
     * Generic field update — $changes is keyed by property name (not column
     * name), so each value goes through the property's own typed setter
     * (dirty-tracking + type checks included) before saveModified() flushes it.
     *
     * @param array<string, mixed> $changes
     */
    public function update(User $user, array $changes): bool
    {
        foreach ($changes as $property => $value) {
            $user->{$property} = $value;
        }
        return $user->saveModified();
    }

    public function activate(User $user): bool
    {
        $user->isActive = true;
        return $user->saveModified();
    }

    public function deactivate(User $user): bool
    {
        $user->isActive = false;
        return $user->saveModified();
    }


    // -------------------------------------------------------------------------
    // Password
    //
    // `password_hash` is generated with access: w in entities.neon — there is
    // no getter at all, so $user->passwordHash can only ever be *written*.
    // The only way to read the stored hash back (to verify a login attempt)
    // is Entity::toArrayDb(), which reads straight from the underlying
    // ActiveRow and bypasses property hooks entirely. That's a deliberate
    // asymmetry: application code that only has a User instance can never
    // accidentally leak the hash through a typed property/toArray()/var_dump
    // of the "normal" property set — reaching for toArrayDb() is a visible,
    // deliberate opt-in.
    // -------------------------------------------------------------------------

    public function setPassword(User $user, string $newPlainPassword): bool
    {
        $user->passwordHash = $this->hashPassword($newPlainPassword);
        return $user->saveModified();
    }

    public function verifyPassword(User $user, string $plainPassword): bool
    {
        $hash = $user->toArrayDb()['password_hash'] ?? null;
        return is_string($hash) && $hash !== '' && password_verify($plainPassword, $hash);
    }

    private function hashPassword(string $plain): string
    {
        return password_hash($plain, PASSWORD_DEFAULT);
    }


    // -------------------------------------------------------------------------
    // Delete
    // -------------------------------------------------------------------------

    public function delete(User $user): int
    {
        return $user->delete();
    }
}
