<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\Auth\ExternalAuth\ExternalAuthServiceClient;
use StoneScriptPHP\Auth\Invitations\InvitationCompletionService;
use StoneScriptPHP\Auth\Invitations\InvitationException;
use StoneScriptPHP\Auth\Invitations\InvitationRecord;
use StoneScriptPHP\Auth\Invitations\InvitationRepositoryInterface;
use StoneScriptPHP\Auth\TokenExchangeException;
use StoneScriptPHP\Auth\TokenExchangeService;

/**
 * Unit tests for InvitationCompletionService — the shared, framework-owned
 * accept-invite orchestration piece.
 *
 * Mocks the HTTP boundary (TokenExchangeService::validateIdentityToken()'s
 * JWKS fetch, ExternalAuthServiceClient::createMembershipForInvite()'s
 * outbound call to auth) via PHPUnit's createMock() on both concrete
 * classes — neither call ever hits a real network in these tests.
 * API-token minting (exchangeApiToken()) IS exercised for real, with a throwaway
 * RSA keypair generated per test (mirrors RsaJwtHandlerTypedClaimsTest's
 * own pattern), since that path is pure local crypto, not an HTTP call.
 *
 * @covers \StoneScriptPHP\Auth\Invitations\InvitationCompletionService
 * @covers \StoneScriptPHP\Auth\Invitations\InvitationException
 */
class InvitationCompletionServiceTest extends TestCase
{
    private string $keysDir;
    private string $privateKeyPath;

    protected function setUp(): void
    {
        $this->keysDir = sys_get_temp_dir() . '/ssp-invite-svc-' . uniqid('', true);
        @mkdir($this->keysDir, 0755, true);
        $this->privateKeyPath = $this->keysDir . '/private.pem';

        $res = openssl_pkey_new([
            'digest_alg' => 'sha256',
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        openssl_pkey_export($res, $pem);
        file_put_contents($this->privateKeyPath, $pem);
    }

    protected function tearDown(): void
    {
        @unlink($this->privateKeyPath);
        @rmdir($this->keysDir);
    }

    private function makeInvitation(array $overrides = []): InvitationRecord
    {
        return new InvitationRecord(
            id: $overrides['id'] ?? 'inv-1',
            email: $overrides['email'] ?? 'invitee@example.com',
            role: $overrides['role'] ?? 'employee',
            status: $overrides['status'] ?? 'pending',
            expiresAt: $overrides['expiresAt'] ?? (new \DateTimeImmutable('+1 day'))->format('c'),
            invitedBy: $overrides['invitedBy'] ?? 'inviter-id',
        );
    }

    private function authClaims(array $overrides = []): array
    {
        return array_merge([
            'sub'   => 'identity-123',
            'email' => 'invitee@example.com',
        ], $overrides);
    }

    private function authConfig(): array
    {
        return ['jwks_url' => 'https://auth.example.test/.well-known/jwks.json', 'issuer' => 'https://auth.example.test'];
    }

    // ── Happy paths ──────────────────────────────────────────────────────

    public function test_complete_returns_passthrough_response_for_t3_platform(): void
    {
        $repository = $this->createMock(InvitationRepositoryInterface::class);
        $repository->method('findByTokenHash')->willReturn($this->makeInvitation());
        $repository->expects($this->once())->method('markAccepted')->with('inv-1', 'identity-123');

        $tokenExchange = $this->createMock(TokenExchangeService::class);
        $tokenExchange->method('validateIdentityToken')->willReturn($this->authClaims());

        $authClient = $this->createMock(ExternalAuthServiceClient::class);
        $authClient->expects($this->once())
            ->method('createMembershipForInvite')
            ->with(
                $this->callback(function (array $data) {
                    // role must NEVER be sent, even though $role below is 'employee'.
                    $this->assertArrayNotHasKey('role', $data);
                    $this->assertSame('identity-123', $data['identity_id']);
                    $this->assertSame('tenant-1', $data['tenant_id']);

                    // REGRESSION (2026-07-28): an idempotency_key is MANDATORY here.
                    //
                    // auth's auth_register_account refuses to create a membership when
                    // the identity already belongs to a DIFFERENT tenant on the same
                    // platform AND no idempotency_key was supplied — it returns
                    // `tenant_already_exists` (409), which surfaces here as a 502.
                    // Accepting an invitation is EXACTLY that shape: the invitee is
                    // being added to the inviter's tenant, which is by definition not
                    // their own. So without a key, accept fails for every invitee who
                    // already has any tenant — the common case, since platforms
                    // typically auto-provision a personal workspace at signup.
                    //
                    // The key must be DERIVED FROM THE INVITATION so a retry replays
                    // (auth dedupes on the key and returns the existing membership)
                    // rather than creating a duplicate.
                    $this->assertArrayHasKey('idempotency_key', $data);
                    $this->assertStringContainsString('inv-1', (string) $data['idempotency_key']);
                    return true;
                }),
                'secret-123'
            )
            ->willReturn(['membership_id' => 'mem-1', 'access_token' => 'auth-issued-token']);

        $service = new InvitationCompletionService($tokenExchange, $authClient);

        $result = $service->complete(
            'raw-token',
            'auth-token',
            $repository,
            fn(InvitationRecord $inv) => $inv->role,
            fn(InvitationRecord $inv, array $claims, string $role) => ['tenant_id' => 'tenant-1'],
            $this->authConfig(),
            ['platform_secret' => 'secret-123', 'card' => false]
        );

        $this->assertSame('passthrough', $result['mode']);
        $this->assertSame('auth-issued-token', $result['identity_token']);
        $this->assertSame('tenant-1', $result['tenant_id']);
        $this->assertSame('employee', $result['membership']['role']);
        $this->assertSame('mem-1', $result['membership']['id']);
    }

    public function test_complete_mints_a_local_api_token_for_t2_platform(): void
    {
        $repository = $this->createMock(InvitationRepositoryInterface::class);
        $repository->method('findByTokenHash')->willReturn($this->makeInvitation());

        // Partial mock: only validateIdentityToken() (the HTTP/JWKS boundary)
        // is stubbed — exchangeApiToken() runs FOR REAL below, since it's pure
        // local RSA signing, not a network call, and is exactly the
        // behavior this test wants to prove actually works.
        $tokenExchangeMock = $this->getMockBuilder(TokenExchangeService::class)
            ->onlyMethods(['validateIdentityToken'])
            ->getMock();
        $tokenExchangeMock->method('validateIdentityToken')->willReturn($this->authClaims());

        $authClient = $this->createMock(ExternalAuthServiceClient::class);
        $authClient->method('createMembershipForInvite')->willReturn(['membership_id' => 'mem-1']);

        $service = new InvitationCompletionService($tokenExchangeMock, $authClient);

        $result = $service->complete(
            'raw-token',
            'auth-token',
            $repository,
            fn(InvitationRecord $inv) => $inv->role,
            fn(InvitationRecord $inv, array $claims, string $role) => ['tenant_id' => 'tenant-1'],
            $this->authConfig(),
            [
                'platform_secret' => 'secret-123',
                'card' => true,
                'signing_private_key_path' => $this->privateKeyPath,
                'signing_issuer' => 'https://api.testapp.in',
                'ttl' => 3600,
            ]
        );

        $this->assertSame('card', $result['mode']);
        $this->assertNotNull($result['access_token']);
        // A real signed JWT has 3 dot-separated segments.
        $this->assertCount(3, explode('.', $result['access_token']));
        $this->assertSame('Bearer', $result['token_type']);
    }

    public function test_complete_falls_back_to_auth_returned_token_when_api_token_signing_not_configured(): void
    {
        $repository = $this->createMock(InvitationRepositoryInterface::class);
        $repository->method('findByTokenHash')->willReturn($this->makeInvitation());

        $tokenExchange = $this->createMock(TokenExchangeService::class);
        $tokenExchange->method('validateIdentityToken')->willReturn($this->authClaims());

        $authClient = $this->createMock(ExternalAuthServiceClient::class);
        $authClient->method('createMembershipForInvite')->willReturn(['access_token' => 'fallback-token']);

        $service = new InvitationCompletionService($tokenExchange, $authClient);

        $result = $service->complete(
            'raw-token',
            'auth-token',
            $repository,
            fn(InvitationRecord $inv) => $inv->role,
            fn(InvitationRecord $inv, array $claims, string $role) => ['tenant_id' => 'tenant-1'],
            $this->authConfig(),
            ['platform_secret' => 'secret-123', 'card' => true] // no signing_private_key_path
        );

        $this->assertSame('card', $result['mode']);
        $this->assertSame('fallback-token', $result['access_token']);
    }

    // ── Taxonomy failures ────────────────────────────────────────────

    public function test_complete_throws_not_found_when_repository_returns_null(): void
    {
        $repository = $this->createMock(InvitationRepositoryInterface::class);
        $repository->method('findByTokenHash')->willReturn(null);

        $service = new InvitationCompletionService(
            $this->createMock(TokenExchangeService::class),
            $this->createMock(ExternalAuthServiceClient::class)
        );

        try {
            $service->complete('bad-token', 'auth-token', $repository, fn($i) => $i->role, fn($i, $c, $r) => ['tenant_id' => 't'], $this->authConfig(), ['platform_secret' => 's']);
            $this->fail('expected InvitationException');
        } catch (InvitationException $e) {
            $this->assertSame(InvitationException::NOT_FOUND, $e->getErrorCode());
            $this->assertSame(404, $e->getHttpStatusCode());
        }
    }

    public function test_complete_throws_already_used_when_status_not_pending(): void
    {
        $repository = $this->createMock(InvitationRepositoryInterface::class);
        $repository->method('findByTokenHash')->willReturn($this->makeInvitation(['status' => 'accepted']));

        $service = new InvitationCompletionService(
            $this->createMock(TokenExchangeService::class),
            $this->createMock(ExternalAuthServiceClient::class)
        );

        try {
            $service->complete('token', 'auth-token', $repository, fn($i) => $i->role, fn($i, $c, $r) => ['tenant_id' => 't'], $this->authConfig(), ['platform_secret' => 's']);
            $this->fail('expected InvitationException');
        } catch (InvitationException $e) {
            $this->assertSame(InvitationException::ALREADY_USED, $e->getErrorCode());
            $this->assertSame(409, $e->getHttpStatusCode());
        }
    }

    public function test_complete_throws_expired_when_expires_at_in_the_past(): void
    {
        $repository = $this->createMock(InvitationRepositoryInterface::class);
        $repository->method('findByTokenHash')->willReturn($this->makeInvitation([
            'expiresAt' => (new \DateTimeImmutable('-1 day'))->format('c'),
        ]));

        $service = new InvitationCompletionService(
            $this->createMock(TokenExchangeService::class),
            $this->createMock(ExternalAuthServiceClient::class)
        );

        try {
            $service->complete('token', 'auth-token', $repository, fn($i) => $i->role, fn($i, $c, $r) => ['tenant_id' => 't'], $this->authConfig(), ['platform_secret' => 's']);
            $this->fail('expected InvitationException');
        } catch (InvitationException $e) {
            $this->assertSame(InvitationException::EXPIRED, $e->getErrorCode());
            $this->assertSame(410, $e->getHttpStatusCode());
        }
    }

    public function test_complete_throws_invalid_auth_token_when_jwks_validation_fails(): void
    {
        $repository = $this->createMock(InvitationRepositoryInterface::class);
        $repository->method('findByTokenHash')->willReturn($this->makeInvitation());

        $tokenExchange = $this->createMock(TokenExchangeService::class);
        $tokenExchange->method('validateIdentityToken')
            ->willThrowException(new TokenExchangeException('signature invalid', 'INVALID_SIGNATURE'));

        $service = new InvitationCompletionService($tokenExchange, $this->createMock(ExternalAuthServiceClient::class));

        try {
            $service->complete('token', 'bad-auth-token', $repository, fn($i) => $i->role, fn($i, $c, $r) => ['tenant_id' => 't'], $this->authConfig(), ['platform_secret' => 's']);
            $this->fail('expected InvitationException');
        } catch (InvitationException $e) {
            $this->assertSame(InvitationException::INVALID_AUTH_TOKEN, $e->getErrorCode());
            $this->assertSame(401, $e->getHttpStatusCode());
        }
    }

    /**
     * This failure mode did not exist
     * under auth's old atomic accept; it's an emergent consequence of
     * decoupling identity creation from invitation acceptance.
     */
    public function test_complete_throws_email_mismatch_when_auth_token_email_differs(): void
    {
        $repository = $this->createMock(InvitationRepositoryInterface::class);
        $repository->method('findByTokenHash')->willReturn($this->makeInvitation(['email' => 'invited@example.com']));

        $tokenExchange = $this->createMock(TokenExchangeService::class);
        $tokenExchange->method('validateIdentityToken')->willReturn($this->authClaims(['email' => 'someone-else@example.com']));

        $service = new InvitationCompletionService($tokenExchange, $this->createMock(ExternalAuthServiceClient::class));

        try {
            $service->complete('token', 'auth-token', $repository, fn($i) => $i->role, fn($i, $c, $r) => ['tenant_id' => 't'], $this->authConfig(), ['platform_secret' => 's']);
            $this->fail('expected InvitationException');
        } catch (InvitationException $e) {
            $this->assertSame(InvitationException::EMAIL_MISMATCH, $e->getErrorCode());
            $this->assertSame(403, $e->getHttpStatusCode());
        }
    }

    public function test_complete_email_match_is_case_insensitive(): void
    {
        $repository = $this->createMock(InvitationRepositoryInterface::class);
        $repository->method('findByTokenHash')->willReturn($this->makeInvitation(['email' => 'Invitee@Example.com']));

        $tokenExchange = $this->createMock(TokenExchangeService::class);
        $tokenExchange->method('validateIdentityToken')->willReturn($this->authClaims(['email' => 'invitee@example.com']));

        $authClient = $this->createMock(ExternalAuthServiceClient::class);
        $authClient->method('createMembershipForInvite')->willReturn(['access_token' => 'tok']);

        $service = new InvitationCompletionService($tokenExchange, $authClient);

        $result = $service->complete(
            'token',
            'auth-token',
            $repository,
            fn($i) => $i->role,
            fn($i, $c, $r) => ['tenant_id' => 't'],
            $this->authConfig(),
            ['platform_secret' => 's', 'card' => false]
        );

        $this->assertSame('passthrough', $result['mode']);
    }

    public function test_complete_throws_config_error_when_membership_writer_omits_tenant_id(): void
    {
        $repository = $this->createMock(InvitationRepositoryInterface::class);
        $repository->method('findByTokenHash')->willReturn($this->makeInvitation());

        $tokenExchange = $this->createMock(TokenExchangeService::class);
        $tokenExchange->method('validateIdentityToken')->willReturn($this->authClaims());

        $service = new InvitationCompletionService($tokenExchange, $this->createMock(ExternalAuthServiceClient::class));

        try {
            $service->complete('token', 'auth-token', $repository, fn($i) => $i->role, fn($i, $c, $r) => [], $this->authConfig(), ['platform_secret' => 's']);
            $this->fail('expected InvitationException');
        } catch (InvitationException $e) {
            $this->assertSame(InvitationException::CONFIG_ERROR, $e->getErrorCode());
        }
    }

    public function test_complete_throws_config_error_when_platform_secret_missing(): void
    {
        $repository = $this->createMock(InvitationRepositoryInterface::class);
        $repository->method('findByTokenHash')->willReturn($this->makeInvitation());

        $tokenExchange = $this->createMock(TokenExchangeService::class);
        $tokenExchange->method('validateIdentityToken')->willReturn($this->authClaims());

        $service = new InvitationCompletionService($tokenExchange, $this->createMock(ExternalAuthServiceClient::class));

        try {
            $service->complete('token', 'auth-token', $repository, fn($i) => $i->role, fn($i, $c, $r) => ['tenant_id' => 't'], $this->authConfig(), ['platform_secret' => null]);
            $this->fail('expected InvitationException');
        } catch (InvitationException $e) {
            $this->assertSame(InvitationException::CONFIG_ERROR, $e->getErrorCode());
            $this->assertSame(500, $e->getHttpStatusCode());
        }
    }

    public function test_complete_throws_membership_failed_when_create_membership_call_throws(): void
    {
        $repository = $this->createMock(InvitationRepositoryInterface::class);
        $repository->method('findByTokenHash')->willReturn($this->makeInvitation());
        // REGRESSION (2026-07-28): markAccepted() must NOT run when step 8 fails.
        //
        // This assertion previously read `->expects($this->once())` and its comment
        // called the old ordering "the accepted ordering" — i.e. the test PINNED the
        // bug as correct behaviour. It is not correct: markAccepted() burns a
        // single-use invitation token. Running it before the only step that can fail
        // leaves the invitation permanently `status='accepted'` with NO membership
        // granted, and a retry then dies on `invite_already_used`. The invitee is
        // locked out with no recovery path short of a manual DB edit.
        //
        // Found live in production: an invitee who already had any tenant on the
        // platform hit auth's `tenant_already_exists` (409) at step 8 -> 502 here ->
        // invitation destroyed. Marking acceptance is now the LAST thing that
        // happens, so a failed accept is retryable.
        $repository->expects($this->never())->method('markAccepted');

        $tokenExchange = $this->createMock(TokenExchangeService::class);
        $tokenExchange->method('validateIdentityToken')->willReturn($this->authClaims());

        $authClient = $this->createMock(ExternalAuthServiceClient::class);
        $authClient->method('createMembershipForInvite')->willThrowException(new \RuntimeException('auth unreachable'));

        $service = new InvitationCompletionService($tokenExchange, $authClient);

        try {
            $service->complete('token', 'auth-token', $repository, fn($i) => $i->role, fn($i, $c, $r) => ['tenant_id' => 't'], $this->authConfig(), ['platform_secret' => 's']);
            $this->fail('expected InvitationException');
        } catch (InvitationException $e) {
            $this->assertSame(InvitationException::MEMBERSHIP_FAILED, $e->getErrorCode());
            $this->assertSame(502, $e->getHttpStatusCode());
        }
    }

    public function test_invitation_exception_to_array_shape(): void
    {
        $e = new InvitationException('boom', InvitationException::EXPIRED, 410);
        $this->assertSame(['error' => 'invite_expired', 'message' => 'boom'], $e->toArray());
    }
}
