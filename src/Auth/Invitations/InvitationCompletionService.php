<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth\Invitations;

use StoneScriptPHP\Auth\ExternalAuth\ExternalAuthServiceClient;
use StoneScriptPHP\Auth\PlatformCodeGuard;
use StoneScriptPHP\Auth\TokenExchangeException;
use StoneScriptPHP\Auth\TokenExchangeService;
use StoneScriptPHP\Auth\TenantRoles\DatabaseTenantRoleStore;
use StoneScriptPHP\Auth\TenantRoles\TenantRoleResolver;
use StoneScriptPHP\Tenancy\Provisioning\DatabaseMainTenantStore;
use StoneScriptPHP\Tenancy\Provisioning\DatabaseTenantDbSeeder;
use StoneScriptPHP\Tenancy\Provisioning\ProvisioningException;
use StoneScriptPHP\Tenancy\Provisioning\TenancyMode;
use StoneScriptPHP\Tenancy\Provisioning\TenantDbScope;
use StoneScriptPHP\Tenancy\Provisioning\TenantPrivilegeSeed;
use StoneScriptPHP\Tenancy\Provisioning\TenantStaffInvite;
use StoneScriptPHP\Tenancy\Provisioning\Uuid;

/**
 * InvitationCompletionService
 *
 * The generic, correctness-sensitive accept-invite orchestration piece —
 * the accept-invite sequence. Ships IN the framework (unlike the
 * table/routes/repository, which are generated per-platform by
 * `php stone generate invitations`) because this logic — token-hash lookup,
 * expiry/status checks, auth-token JWKS validation, the email-match guard, the
 * `create-membership` server-to-server call, and API-token-minting/passthrough —
 * is identical across every platform and is exactly the kind of
 * correctness-sensitive plumbing that should not be re-implemented (and
 * re-audited) per platform.
 *
 * What this class deliberately does NOT do, per the constraint that
 * "roles belong in api/platform" — it never
 * decides a role, and it never writes tenant-side membership data itself.
 * Both are supplied by the caller as closures (`$roleResolver`,
 * `$membershipWriter`), generated per-platform in `config/invitations.php`.
 * This keeps "roles belong in api/platform" intact even inside shared
 * framework code — the service orchestrates, the platform decides.
 *
 * @package StoneScriptPHP\Auth\Invitations
 */
class InvitationCompletionService
{
    /**
     * @param TenantStaffInvite|null $staffInvite tenancy v2 only: the writer that adds the member (authority membership,
     *        tenant-DB user + role, main membership row). Built from the environment when TENANCY_V2 is on and none is given.
     */
    public function __construct(
        private readonly TokenExchangeService $tokenExchangeService,
        private readonly ExternalAuthServiceClient $authClient,
        private readonly ?TenantStaffInvite $staffInvite = null,
    ) {
    }

    /** Is this accept running under tenancy v2? `$platformConfig['tenancy_v2']` decides when present, else TENANCY_V2. */
    private function tenancyV2(array $platformConfig): bool
    {
        if (array_key_exists('tenancy_v2', $platformConfig)) {
            return (bool) $platformConfig['tenancy_v2'];
        }
        try {
            return TenancyMode::v2Enabled();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Tenancy v2 accept: the member is added through the MembershipAuthority (auth v2 add member), the tenant database
     * (`users` + `user_roles` with the invitation's role) and the main `tenant_memberships` table. The invitation's
     * role must be one the platform's privilege seed knows (it has privileges): an unknown or missing role refuses
     * the invite BEFORE anything is written. markAccepted() still runs last, so a failed accept is retryable.
     * `membership_writer` is not called (the framework writes the membership); the invitation must carry its tenant id.
     */
    private function completeTenancyV2(
        InvitationRecord $invitation,
        InvitationRepositoryInterface $repository,
        string $authToken,
        array $authClaims,
        string $identityId,
        string $role,
        array $platformConfig
    ): array {
        try {
            $grants = $platformConfig['tenant_privileges']
                ?? TenantPrivilegeSeed::fromFile(rtrim((string) ROOT_PATH, '/\\') . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'tenant-privileges.php');
        } catch (\Throwable $e) {
            throw new InvitationException('The privilege seed could not be read', InvitationException::CONFIG_ERROR, 500, $e);
        }
        if ($role === '' || empty($grants[$role] ?? [])) {
            throw new InvitationException('The invitation names a role this platform does not know', InvitationException::INVALID_ROLE, 422);
        }
        // The tenant: the invitation's own, else the tenant this request is routed to (the invite is accepted inside
        // that tenant's context). Refused only when neither exists.
        $tenantId = (string) ($invitation->tenantId ?? '');
        if ($tenantId === '') {
            $tenantId = (string) ($this->routedTenantId() ?? '');
        }
        if (!Uuid::isValid($tenantId)) {
            throw new InvitationException('The invitation carries no tenant and the request is not routed to one', InvitationException::CONFIG_ERROR, 500);
        }
        // Every role assignment records who made it: an invitation without an inviter is refused before any write.
        if ($invitation->invitedBy === null || trim($invitation->invitedBy) === '') {
            throw new InvitationException('The invitation records no inviter', InvitationException::MISSING_INVITER, 422);
        }

        $displayName = $authClaims['display_name'] ?? $authClaims['name'] ?? null;
        try {
            $membershipId = ($this->staffInvite ?? $this->buildStaffInvite())->add(
                $tenantId,
                $identityId,
                $role,
                $invitation->invitedBy,
                'invite:' . $invitation->id,
                is_string($displayName) && $displayName !== '' ? $displayName : null
            );
        } catch (ProvisioningException $e) {
            throw new InvitationException('Failed to add the member to the tenant', InvitationException::MEMBERSHIP_FAILED, 502, $e);
        }

        $repository->markAccepted($invitation->id, $identityId);

        $identity = [
            'id'           => $identityId,
            'email'        => $authClaims['email'] ?? $invitation->email,
            'display_name' => $displayName,
        ];
        $membership = ['id' => $membershipId, 'tenant_id' => $tenantId];

        if (!($platformConfig['card'] ?? true)) {
            return ['mode' => 'passthrough', 'identity_token' => $authToken, 'tenant_id' => $tenantId, 'identity' => $identity, 'membership' => $membership];
        }

        // Card mode: the token carries ALL the member's known roles in this tenant (read from the tenant database).
        try {
            $access = $this->tenantAccess($tenantId, $identityId);
        } catch (\Throwable $e) {
            throw new InvitationException('Could not read the member\'s roles', InvitationException::MEMBERSHIP_FAILED, 502, $e);
        }
        $token = $access['roles'] === [] ? null : $this->mintRolesToken($identityId, $tenantId, $access['roles'], $identity, $platformConfig);
        if ($token === null) {
            throw new InvitationException('Could not issue a platform token for the new member', InvitationException::CONFIG_ERROR, 500);
        }

        return [
            'mode'         => 'card',
            'access_token' => $token,
            'token_type'   => 'Bearer',
            'expires_in'   => $platformConfig['ttl'] ?? 3600,
            'identity'     => $identity,
            'membership'   => $membership,
            'roles'        => $access['roles'],
            'privileges'   => $access['privileges'],
        ];
    }

    /** The tenant this request is routed to (scope or gateway client), or null. Protected: tests override. */
    protected function routedTenantId(): ?string
    {
        return TenantDbScope::routedTenantId();
    }

    private function buildStaffInvite(): TenantStaffInvite
    {
        $env = \StoneScriptPHP\Env::get_instance();
        $store = new DatabaseMainTenantStore();
        return new TenantStaffInvite(TenancyMode::authority($env, $store), $store, new DatabaseTenantDbSeeder());
    }

    /** @return array{roles:list<string>,privileges:list<string>} strict read: a failed read throws */
    protected function tenantAccess(string $tenantId, string $identityId): array
    {
        return TenantDbScope::run($tenantId, static fn (): array => (new TenantRoleResolver(new DatabaseTenantRoleStore()))->strictAccess($identityId));
    }

    /** @param list<string> $roles @param array{id:string,email:?string,display_name:?string} $identity */
    private function mintRolesToken(string $identityId, string $tenantId, array $roles, array $identity, array $platformConfig): ?string
    {
        $privateKeyPath = $platformConfig['signing_private_key_path'] ?? null;
        if (empty($privateKeyPath)) {
            return null;
        }
        try {
            return $this->tokenExchangeService->exchangeApiTokenWithRoles([
                'sub'          => $identityId,
                'identity_id'  => $identityId,
                'tenant_id'    => $tenantId,
                'email'        => ($identity['email'] ?? null) ?: null,
                'display_name' => ($identity['display_name'] ?? null) ?: null,
            ], $roles, [
                'private_key_path'       => $privateKeyPath,
                'private_key_passphrase' => $platformConfig['signing_private_key_passphrase'] ?? null,
                'issuer'                 => $platformConfig['signing_issuer'] ?? '',
                'ttl'                    => $platformConfig['ttl'] ?? 3600,
                'custom_claims'          => ['platform_code' => $platformConfig['platform_code'] ?? null],
            ]);
        } catch (TokenExchangeException $e) {
            log_error('InvitationCompletionService: API token minting failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Run the full accept-invite sequence.
     *
     * @param string $rawToken Raw invite token from the URL/path param —
     *   NEVER the hash. Hashed internally before any repository lookup.
     * @param string $authToken Bearer identity token (auth token) issued
     *   by the auth service — the caller extracts this from the inbound
     *   `Authorization: Bearer <token>` header (see the generated
     *   `PostAcceptInvitationRoute`, which is registered PUBLIC precisely
     *   because this is an auth token, not a platform API token — mirrors
     *   `ExchangeRoute`'s own public+JWKS-validated pattern).
     * @param InvitationRepositoryInterface $repository Platform's generated
     *   repository, backed by the `platform_invitations` table.
     * @param callable $roleResolver `fn(InvitationRecord $invitation): string`
     *   — decides the role actually granted. Role
     *   authority lives in `platform_invitations.role`, never an auth claim
     *   — the generated default simply returns `$invitation->role` as-is;
     *   this seam exists so a platform can re-validate/re-map at accept
     *   time without this service ever hardcoding that policy.
     * @param callable $membershipWriter
     *   `fn(InvitationRecord $invitation, array $authClaims, string $role): array`
     *   — writes THIS platform's own tenant-membership record (step 6) and
     *   MUST return an array containing at least a `tenant_id` string key;
     *   every other key is passed through into the response's `membership`
     *   field as-is.
     * @param array{jwks_url: string, issuer: string, audience?: string|null} $authConfig
     *   Auth-token (JWKS) validation config, forwarded to
     *   `TokenExchangeService::validateIdentityToken()`.
     * @param array{
     *   platform_secret: string|null,
     *   platform_code?: string|null,
     *   tenant_db_schema?: string|null,
     *   card?: bool,
     *   signing_private_key_path?: string|null,
     *   signing_private_key_passphrase?: string|null,
     *   signing_issuer?: string|null,
     *   ttl?: int
     * } $platformConfig
     *   `card` selects T2 (mint a local API token) vs T3
     *   (passthrough identity token + tenant_id, matching the existing T3
     *   contract unchanged). Defaults to T2 (`card` => true) when omitted.
     *   NOTE: the `card` config key and the `mode: 'card'` response value below
     *   are preserved as-is — they are a platform-facing config contract and a
     *   wire-level response value respectively, not framework-internal
     *   identifiers, so this rename intentionally leaves them unrenamed (see
     *   this repo's rename report for the full reasoning).
     * @return array Response envelope — see class docblock's two shapes
     *   under "Response shapes" below.
     * @throws InvitationException On any of the taxonomy failures
     *   (token/status/expiry/email-match) or on a downstream failure calling auth
     *   (`MEMBERSHIP_FAILED`) / missing config (`CONFIG_ERROR`).
     *
     * Response shapes:
     *   T2 (card):  ['mode'=>'card', 'access_token', 'token_type', 'expires_in',
     *                'identity'=>[...], 'membership'=>[...]]
     *   T3 (passthrough): ['mode'=>'passthrough', 'identity_token', 'tenant_id',
     *                'identity'=>[...], 'membership'=>[...]]
     */
    public function complete(
        string $rawToken,
        string $authToken,
        InvitationRepositoryInterface $repository,
        callable $roleResolver,
        callable $membershipWriter,
        array $authConfig,
        array $platformConfig
    ): array {
        // Step 1: look up by sha256(token) — never the raw token.
        $tokenHash = hash('sha256', $rawToken);
        $invitation = $repository->findByTokenHash($tokenHash);
        if ($invitation === null) {
            throw new InvitationException('Invitation not found', InvitationException::NOT_FOUND, 404);
        }

        // Step 2: status + expiry.
        if ($invitation->status !== 'pending') {
            throw new InvitationException('Invitation has already been used', InvitationException::ALREADY_USED, 409);
        }
        if ($this->isExpired($invitation->expiresAt)) {
            throw new InvitationException('Invitation has expired', InvitationException::EXPIRED, 410);
        }

        // Step 3: validate the auth token via JWKS. Read-only — no
        // auth DB write, no auth DB read even (JWKS is a published key set).
        try {
            $authClaims = $this->tokenExchangeService->validateIdentityToken(
                $authToken,
                $authConfig['jwks_url'],
                $authConfig['issuer'],
                $authConfig['audience'] ?? null
            );
        } catch (TokenExchangeException $e) {
            throw new InvitationException(
                'Invalid or expired session',
                InvitationException::INVALID_AUTH_TOKEN,
                401,
                $e
            );
        }

        // Step 3b — SECURITY: reject an auth token minted for a DIFFERENT
        // platform before this identity is ever granted a membership on
        // THIS one. See PlatformCodeGuard's class docblock — accept-invite
        // is exactly the kind of platform-scoped-resource-creation action
        // the cross-platform-token gap targets: without this, an invite link
        // sent for platform B could be completed with an auth token minted
        // at platform A, joining the invitee to B's tenant on the strength
        // of a token B never issued.
        $configuredPlatformCode = $platformConfig['platform_code'] ?? null;
        $guardReason = PlatformCodeGuard::check($authClaims['platform_code'] ?? null, $configuredPlatformCode);
        if ($guardReason === PlatformCodeGuard::UNCONFIGURED) {
            // FAIL CLOSED (15.0.0): without a platform_code nothing proves the token is for this platform.
            log_error(
                'InvitationCompletionService: refusing accept-invite — no platform_code was passed in ' .
                '$platformConfig, so the token cannot be tied to this platform. Pass platform_code.'
            );
            throw new InvitationException(
                'This platform is not configured to accept invitations',
                InvitationException::CONFIG_ERROR,
                500
            );
        } elseif (PlatformCodeGuard::isRejection($guardReason)) {
            log_warning(
                "InvitationCompletionService: rejecting accept-invite — platform_code guard failed ({$guardReason}); " .
                'token platform_code=' . ($authClaims['platform_code'] ?? '(none)') .
                ', this platform=' . ($configuredPlatformCode ?? '(unset)')
            );
            throw new InvitationException(
                'This invitation link is not valid for the account you are signed in with',
                InvitationException::WRONG_PLATFORM,
                403
            );
        }

        // Step 4: email-match guard. This failure mode is a direct
        // emergent consequence of decoupling identity creation from
        // invitation acceptance — it did not exist
        // when auth owned both sides of accept-invite.
        $claimedEmail = strtolower(trim((string) ($authClaims['email'] ?? '')));
        $invitedEmail = strtolower(trim($invitation->email));
        if ($claimedEmail === '' || $claimedEmail !== $invitedEmail) {
            throw new InvitationException(
                'This invitation was sent to a different email address',
                InvitationException::EMAIL_MISMATCH,
                403
            );
        }

        $identityId = (string) ($authClaims['sub'] ?? $authClaims['identity_id'] ?? '');
        if ($identityId === '') {
            throw new InvitationException(
                'Auth token is missing an identity id',
                InvitationException::INVALID_AUTH_TOKEN,
                401
            );
        }

        // Step 5: role decision — platform-owned, never this service.
        $role = $roleResolver($invitation);

        // Tenancy v2: the framework adds the member (authority + tenant DB + main row) with this role; see
        // completeTenancyV2(). An unknown role is refused there before anything is written.
        if ($this->tenancyV2($platformConfig)) {
            return $this->completeTenancyV2($invitation, $repository, $authToken, $authClaims, $identityId, (string) $role, $platformConfig);
        }

        // Step 6: platform's own tenant-membership write — also
        // platform-owned. Must return at least ['tenant_id' => string].
        $membershipData = $membershipWriter($invitation, $authClaims, $role);
        $tenantId = (string) ($membershipData['tenant_id'] ?? '');
        if ($tenantId === '') {
            throw new InvitationException(
                'membership_writer did not return a tenant_id',
                InvitationException::CONFIG_ERROR,
                500
            );
        }

        // Step 8: the ONLY outbound call to auth — carries no
        // invitation data, only the resulting membership fact. Role
        // is deliberately never sent (createMembershipForInvite()
        // enforces this even if a caller mistakenly includes it).
        //
        // ORDERING CHANGED 2026-07-28: this used to run AFTER step 7
        // (markAccepted). It now runs BEFORE — see the step 7 block below for
        // why. Step 8 has no dependency on the invitation being marked, so the
        // swap is behaviour-preserving on the success path.
        $platformSecret = $platformConfig['platform_secret'] ?? null;
        if (empty($platformSecret)) {
            throw new InvitationException(
                'Server configuration error: platform_secret is not configured',
                InvitationException::CONFIG_ERROR,
                500
            );
        }

        try {
            $membershipResult = $this->authClient->createMembershipForInvite([
                'identity_id'      => $identityId,
                'tenant_id'        => $tenantId,
                'platform_code'    => $platformConfig['platform_code'] ?? null,
                'tenant_db_schema' => $platformConfig['tenant_db_schema'] ?? null,
                'email'            => $invitation->email,
                // MANDATORY (added 2026-07-28) — without this, accept fails for
                // every invitee who already has ANY tenant on this platform.
                //
                // auth's auth_register_account refuses to create a membership when
                // the identity already belongs to a DIFFERENT tenant on the same
                // platform and no idempotency_key is supplied, returning
                // `tenant_already_exists` (409) -> AuthServiceException -> the 502
                // below. Accepting an invitation is precisely that shape: the invitee
                // joins the INVITER's tenant, which is by definition not their own.
                // Platforms that auto-provision a personal workspace at signup (the
                // common pattern) therefore broke for every single invitee.
                //
                // Derived from the invitation id so it is STABLE across retries:
                // auth dedupes on the key and replays the existing membership
                // instead of creating a duplicate. A different invitation yields a
                // different key, so genuine multi-tenant membership still works.
                'idempotency_key'  => 'invite:' . $invitation->id,
            ], $platformSecret);
        } catch (\Throwable $e) {
            throw new InvitationException(
                'Failed to notify auth of the new membership',
                InvitationException::MEMBERSHIP_FAILED,
                502,
                $e
            );
        }

        // Step 7: mark accepted — LAST, and only once every step that can
        // fail has succeeded.
        //
        // ORDERING FIX (2026-07-28): this previously ran BEFORE step 8. Because
        // markAccepted() spends a single-use token, a step-8 failure left the
        // invitation permanently `status='accepted'` with NO membership granted:
        // the invitee could not retry (`invite_already_used`), and the only
        // recovery was a manual DB edit or re-issuing the invitation. Found live
        // in production, where step 8 failed for EVERY invitee who already had a
        // tenant (see the idempotency_key note above) — so the token-burn was not
        // a rare edge case but the default outcome.
        //
        // Marking last makes a failed accept safely retryable. The cost of the
        // reverse ordering — a membership created in auth but the invitation left
        // `pending` if THIS call fails — is strictly better: re-accepting is
        // idempotent (auth replays on the stable key above; the platform's own
        // membership writer in step 6 is required to be idempotent too), whereas
        // an unusable burnt token is not recoverable in-band at all.
        $repository->markAccepted($invitation->id, $identityId);

        $identity = [
            'id'           => $identityId,
            'email'        => $authClaims['email'] ?? $invitation->email,
            'display_name' => $authClaims['display_name'] ?? $authClaims['name'] ?? null,
        ];
        $membership = array_merge($membershipData, [
            'role' => $role,
            'id'   => $membershipData['id'] ?? ($membershipResult['membership_id'] ?? null),
        ]);

        // Step 9: T2 mints a local API token (same pattern as
        // ProvisionTenantRoute::mintProvisionApiToken()); T3 passes through the
        // identity token + explicit tenant_id, matching the auth contract's
        // existing T3 response shape unchanged.
        // NOTE: the 'card' config key and 'mode'=>'card' response value below are
        // preserved as-is — see this method's docblock NOTE for why.
        $wantsApiToken = $platformConfig['card'] ?? true;
        if ($wantsApiToken) {
            $apiToken = $this->mintApiToken($identityId, $tenantId, $role, $identity, $platformConfig);

            return [
                'mode'         => 'card',
                'access_token' => $apiToken ?? ($membershipResult['access_token'] ?? null),
                'token_type'   => 'Bearer',
                'expires_in'   => $platformConfig['ttl'] ?? 3600,
                'identity'     => $identity,
                'membership'   => $membership,
            ];
        }

        return [
            'mode'           => 'passthrough',
            'identity_token' => $membershipResult['access_token'] ?? $authToken,
            'tenant_id'      => $tenantId,
            'identity'       => $identity,
            'membership'     => $membership,
        ];
    }

    /**
     * @param string $expiresAt Raw `expires_at` value from the invitation
     *   row (TIMESTAMPTZ -> string, per generate-model.php's type mapping).
     */
    private function isExpired(string $expiresAt): bool
    {
        try {
            $expiry = new \DateTimeImmutable($expiresAt);
        } catch (\Exception $e) {
            // Unparseable expiry is treated as expired — fail closed, never open.
            return true;
        }

        return $expiry < new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /**
     * Mint a platform API token, mirroring
     * ProvisionTenantRoute::mintProvisionApiToken() verbatim (same config keys,
     * same null-on-not-configured fallback condition).
     *
     * @param array{id: string, email: string|null, display_name: string|null} $identity
     */
    private function mintApiToken(
        string $identityId,
        string $tenantId,
        string $role,
        array $identity,
        array $platformConfig
    ): ?string {
        $privateKeyPath = $platformConfig['signing_private_key_path'] ?? null;
        if (empty($privateKeyPath)) {
            return null;
        }

        $claims = [
            'sub'           => $identityId,
            'identity_id'   => $identityId,
            'tenant_id'     => $tenantId,
            'email'         => $identity['email'] ?: null,
            'display_name'  => $identity['display_name'] ?: null,
            'platform_code' => $platformConfig['platform_code'] ?? null,
        ];

        try {
            return $this->tokenExchangeService->exchangeApiToken($claims, $role, [
                'private_key_path'       => $privateKeyPath,
                'private_key_passphrase' => $platformConfig['signing_private_key_passphrase'] ?? null,
                'issuer'                 => $platformConfig['signing_issuer'] ?? '',
                'ttl'                    => $platformConfig['ttl'] ?? 3600,
            ]);
        } catch (TokenExchangeException $e) {
            log_error('InvitationCompletionService: API token minting failed: ' . $e->getMessage());
            return null;
        }
    }
}
