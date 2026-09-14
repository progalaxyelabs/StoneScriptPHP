<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth\BuiltinOAuth;

/**
 * The single chokepoint every builtin-OAuth login funnels through.
 * Immutable value object — the ONLY way to produce the login-user payload
 * minted into the JWT claims and returned as the client `user` object. A
 * resolver can no longer freely shape that payload: it returns a plain
 * array (see GoogleOAuthUserResolver), and GoogleOAuthCallbackRoute converts
 * it through LoginUser::fromResolverArray(), which routes through create()
 * below — the factory that structurally enforces the contract.
 *
 * `create()` THROWS \RuntimeException when `email` or `displayName` is
 * empty, rather than emitting a malformed/blank login user. Callers (the
 * callback route) already wrap resolver work in a try/catch that bridges
 * `oauth_error` and mints NO token — so a thrown LoginUser exception fails
 * the login loudly instead of silently shipping bad data to the client.
 *
 * A legacy consumer's refresh-token pipeline may still read a bare `name`
 * claim rather than `display_name` — toArray() sets a `name` alias itself
 * (never left to the resolver) so those consumers keep working without any
 * app-specific knowledge living in the framework.
 */
final class LoginUser
{
    /**
     * @param array<string,mixed> $extraClaims Extra JWT claims (e.g. role,
     *   plan) merged into toArray() — but NEVER allowed to override the
     *   canonical fields below (see toArray()).
     */
    private function __construct(
        private readonly string $userId,
        private readonly string $email,
        private readonly string $displayName,
        private readonly bool $isEmailVerified,
        private readonly ?string $photoUrl,
        private readonly ?string $identityId,
        private readonly array $extraClaims,
    ) {
    }

    /**
     * @throws \RuntimeException when $email or $displayName is empty (after
     *   trimming). This is the structural R1 guard — no resolver, mapper, or
     *   caller can construct a LoginUser without a real email + display name.
     */
    public static function create(
        string $userId,
        string $email,
        string $displayName,
        bool $isEmailVerified,
        ?string $photoUrl,
        array $extraClaims = []
    ): self {
        $email = trim($email);
        $displayName = trim($displayName);

        if ($email === '') {
            throw new \RuntimeException(
                'LoginUser::create: email must not be empty — refusing to mint a login user without one (R1).'
            );
        }
        if ($displayName === '') {
            throw new \RuntimeException(
                'LoginUser::create: display_name must not be empty — refusing to mint a login user without one (R1).'
            );
        }

        // identity_id travels as a reserved extraClaims key so create()'s
        // positional signature stays simple; pull it out
        // so it isn't duplicated when extraClaims is merged in toArray().
        $identityId = null;
        if (array_key_exists('identity_id', $extraClaims)) {
            $rawIdentityId = $extraClaims['identity_id'];
            $identityId = ($rawIdentityId !== null && $rawIdentityId !== '') ? (string) $rawIdentityId : null;
            unset($extraClaims['identity_id']);
        }

        // photo_url: empty string treated the same as omitted — "genuinely
        // unknown" per R1/R2, never fabricated, never a bare ''.
        if ($photoUrl !== null && trim($photoUrl) === '') {
            $photoUrl = null;
        }

        return new self($userId, $email, $displayName, $isEmailVerified, $photoUrl, $identityId, $extraClaims);
    }

    /**
     * Converts a resolver's plain-array output (GoogleOAuthUserResolver::resolve())
     * into a LoginUser. This is the seam GoogleOAuthCallbackRoute calls — a
     * resolver returning a legacy/misnamed shape (e.g. `{name: 'X'}` instead
     * of `display_name`, or an empty display_name) has no `display_name` key
     * reach this factory, so create() throws (matrix C11).
     *
     * `is_email_verified` is read from the resolver's own return value when
     * present (e.g. sourced from the platform's own users table) and falls
     * back to the verified-ID-token payload's `email_verified` otherwise —
     * always a REAL sourced boolean per D1, never hardcoded true.
     *
     * @param array<string,mixed> $resolved The resolver's return value.
     * @param array{sub?:string,email?:?string,email_verified?:bool,name?:?string,picture?:?string} $profile
     *   The verified Google ID-token profile (GoogleOAuthCallbackRoute), used
     *   only as a fallback for fields the resolver didn't supply.
     * @throws \RuntimeException see create().
     */
    public static function fromResolverArray(array $resolved, array $profile = []): self
    {
        $userId = (string) ($resolved['user_id'] ?? '');
        $email = (string) ($resolved['email'] ?? $profile['email'] ?? '');
        $displayName = (string) ($resolved['display_name'] ?? '');
        $isEmailVerified = array_key_exists('is_email_verified', $resolved)
            ? (bool) $resolved['is_email_verified']
            : (bool) ($profile['email_verified'] ?? false);
        $photoUrl = $resolved['photo_url'] ?? ($profile['picture'] ?? null);
        $photoUrl = $photoUrl !== null ? (string) $photoUrl : null;

        $reserved = ['user_id', 'identity_id', 'email', 'display_name', 'name', 'is_email_verified', 'photo_url', 'extra_claims'];
        $extraClaims = $resolved['extra_claims'] ?? array_diff_key($resolved, array_flip($reserved));
        if (!is_array($extraClaims)) {
            $extraClaims = [];
        }
        if (isset($resolved['identity_id'])) {
            $extraClaims['identity_id'] = $resolved['identity_id'];
        }

        return self::create($userId, $email, $displayName, $isEmailVerified, $photoUrl, $extraClaims);
    }

    /**
     * Emits EXACTLY: user_id, identity_id, email, display_name,
     * is_email_verified, photo_url — plus a `name` alias mirror of
     * display_name (set BY this serializer, never by the resolver — some
     * legacy consumer refresh-token pipelines read `name`) — plus any extra
     * claims (e.g. role, plan). Core fields always win over a same-named key
     * smuggled into extraClaims.
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        $core = [
            'user_id' => $this->userId,
            'identity_id' => $this->identityId ?? $this->userId,
            'email' => $this->email,
            'display_name' => $this->displayName,
            'name' => $this->displayName,
            'is_email_verified' => $this->isEmailVerified,
            'photo_url' => $this->photoUrl,
        ];

        return array_merge($this->extraClaims, $core);
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    public function isEmailVerified(): bool
    {
        return $this->isEmailVerified;
    }

    public function getPhotoUrl(): ?string
    {
        return $this->photoUrl;
    }
}
