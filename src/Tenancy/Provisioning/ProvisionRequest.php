<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tenancy\Provisioning;

/** Input of the provisioning sequence. $extra carries platform business fields for the hooks only. */
final class ProvisionRequest
{
    /** @param array<string,mixed> $extra */
    public function __construct(
        public readonly string $tenantName,
        public readonly string $idempotencyKey,
        public readonly string $identityId,
        /** The caller's identity token, forwarded to auth (external mode). Never logged. */
        #[\SensitiveParameter] public readonly ?string $userToken = null,
        public readonly ?string $displayName = null,
        public readonly ?string $slug = null,
        public readonly array $extra = [],
    ) {
    }
}
