<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tenancy\Provisioning;

/**
 * A provisioning (or staff-invite) step failed. $step names the step so a caller and the logs say
 * exactly where the sequence stopped; the message never contains secrets or response bodies.
 */
final class ProvisioningException extends \RuntimeException
{
    public const STEP_RESERVE = 'reserve_tenant';
    public const STEP_MAIN_TENANT = 'main_tenant_row';
    public const STEP_DATABASE = 'create_database';
    public const STEP_SEED_PRIVILEGES = 'seed_privileges';
    public const STEP_OWNER_USER = 'owner_user';
    public const STEP_MAIN_MEMBERSHIP = 'main_membership';
    public const STEP_AUTH_PROVISIONED = 'auth_provisioned';
    public const STEP_MAIN_PROVISIONED = 'main_provisioned';
    public const STEP_ADD_MEMBER = 'add_member';

    public function __construct(public readonly string $step, string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
