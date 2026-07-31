<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth\Invitations;

/**
 * InvitationRecord
 *
 * Plain, framework-owned value object representing one row of a platform's
 * OWN `platform_invitations` table (generated per-platform by
 * `php stone generate invitations` — see src/Templates/Invitations/). This
 * class carries no persistence logic; it is the shape
 * {@see InvitationRepositoryInterface} hands to
 * {@see InvitationCompletionService}.
 *
 * `tenantId` is nullable and NOT populated by the framework-generated
 * `platform_invitations` table by default (for T2
 * platforms, tenant isolation is per-DB via the gateway, so the table has no
 * `tenant_id` column at all). T3 platforms (shared-schema multi-tenancy)
 * that add their own `tenant_id` column to the generated
 * migration should populate this field from their own repository
 * implementation — the framework does not assume either model.
 *
 * @package StoneScriptPHP\Auth\Invitations
 */
final class InvitationRecord
{
    public function __construct(
        public readonly string $id,
        public readonly string $email,
        public readonly string $role,
        public readonly string $status,
        public readonly string $expiresAt,
        public readonly ?string $invitedBy = null,
        public readonly ?string $tenantId = null,
        public readonly ?string $acceptedAt = null,
        public readonly ?string $acceptedByIdentityId = null,
    ) {
    }
}
