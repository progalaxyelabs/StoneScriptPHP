<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tenancy\Provisioning;

/**
 * The platform-wide privilege seed: which privileges each role has. It is written into EVERY tenant database at
 * provisioning (user_role_privileges), so a role means the same in every tenant and a returning user's roles
 * resolve to real privileges. The platform supplies it in `config/tenant-privileges.php`
 * (`php stone generate tenancy` writes a stub):
 *
 *     return ['owner' => ['tenant.manage', 'sales.create'], 'cashier' => ['sales.create']];
 *
 * Role codes are lower-case codes (`^[a-z][a-z0-9_]{0,63}$`), privilege codes `^[a-z][a-z0-9_.:-]{0,99}$`.
 * Seeding is idempotent (existing rows are kept, none are removed). Provisioning refuses to finish when the owner
 * role ends up with no privileges.
 */
final class TenantPrivilegeSeed
{
    private function __construct()
    {
    }

    /**
     * @param array<mixed> $grants
     * @return array<string,list<string>> role => privileges (de-duplicated, sorted)
     * @throws \InvalidArgumentException on a malformed role or privilege code
     */
    public static function fromArray(array $grants): array
    {
        $out = [];
        foreach ($grants as $role => $privileges) {
            if (!is_string($role) || preg_match('/\A[a-z][a-z0-9_]{0,63}\z/', $role) !== 1) {
                throw new \InvalidArgumentException('Tenant privilege seed: role codes must be lower-case codes');
            }
            if (!is_array($privileges)) {
                throw new \InvalidArgumentException("Tenant privilege seed: role '$role' must list its privileges in an array");
            }
            $codes = [];
            foreach ($privileges as $p) {
                if (!is_string($p) || preg_match('/\A[a-z][a-z0-9_.:-]{0,99}\z/', $p) !== 1) {
                    throw new \InvalidArgumentException("Tenant privilege seed: invalid privilege code for role '$role'");
                }
                $codes[$p] = true;
            }
            $list = array_map('strval', array_keys($codes));
            sort($list);
            $out[$role] = $list;
        }
        return $out;
    }

    /**
     * Load the seed from a PHP file returning the array. A missing file is an empty seed (a platform that seeds
     * user_role_privileges in its own tenant schema still passes the owner check).
     *
     * @return array<string,list<string>>
     */
    public static function fromFile(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $grants = (static function (string $file): mixed {
            return require $file;
        })($path);
        if (!is_array($grants)) {
            throw new \InvalidArgumentException('Tenant privilege seed: the config file must return an array');
        }
        return self::fromArray($grants);
    }
}
