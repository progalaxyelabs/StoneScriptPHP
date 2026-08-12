<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\Auth\InMemoryRefreshTokenStore;
use StoneScriptPHP\Auth\TokenClaims;

/**
 * RefreshTokenStore contract — persist ALL refresh tokens (identity + API token, keyed
 * by purpose) and REVOKE = DELETE the row (hard, no soft revoked_at).
 *
 * @covers \StoneScriptPHP\Auth\InMemoryRefreshTokenStore
 */
class RefreshTokenStoreTest extends TestCase
{
    private InMemoryRefreshTokenStore $store;

    protected function setUp(): void
    {
        $this->store = new InMemoryRefreshTokenStore();
    }

    private function hash(string $t): string
    {
        return hash('sha256', $t);
    }

    public function test_stored_token_exists(): void
    {
        $h = $this->hash('refresh-1');
        $this->store->store($h, 'subject-1', TokenClaims::PURPOSE_AUTHORIZATION, time() + 1000);

        $this->assertTrue($this->store->exists($h));
    }

    public function test_absent_token_does_not_exist(): void
    {
        $this->assertFalse($this->store->exists($this->hash('never-issued')));
    }

    public function test_revoke_deletes_the_row(): void
    {
        $h = $this->hash('refresh-2');
        $this->store->store($h, 'subject-1', TokenClaims::PURPOSE_AUTHENTICATION, time() + 1000);
        $this->assertTrue($this->store->exists($h));

        $this->store->revoke($h);

        $this->assertFalse($this->store->exists($h), 'revoke() must DELETE the row (hard revoke)');
        $this->assertSame(0, $this->store->count(), 'No soft-deleted rows may linger');
    }

    public function test_both_identity_and_api_token_refresh_tokens_persist_with_purpose_discriminator(): void
    {
        $identity = $this->hash('identity-refresh');
        $apiToken = $this->hash('api-token-refresh');

        $this->store->store($identity, 'subject-1', TokenClaims::PURPOSE_AUTHENTICATION, time() + 1000);
        $this->store->store($apiToken, 'subject-1', TokenClaims::PURPOSE_AUTHORIZATION, time() + 1000);

        $this->assertTrue($this->store->exists($identity));
        $this->assertTrue($this->store->exists($apiToken));
        $this->assertSame(2, $this->store->count());
    }

    public function test_revoke_all_for_subject_deletes_all_that_subjects_rows(): void
    {
        $this->store->store($this->hash('a'), 'subject-1', TokenClaims::PURPOSE_AUTHENTICATION, time() + 1000);
        $this->store->store($this->hash('b'), 'subject-1', TokenClaims::PURPOSE_AUTHORIZATION, time() + 1000);
        $this->store->store($this->hash('c'), 'subject-2', TokenClaims::PURPOSE_AUTHORIZATION, time() + 1000);

        $deleted = $this->store->revokeAllForSubject('subject-1');

        $this->assertSame(2, $deleted);
        $this->assertFalse($this->store->exists($this->hash('a')));
        $this->assertFalse($this->store->exists($this->hash('b')));
        $this->assertTrue($this->store->exists($this->hash('c')), 'Other subjects untouched');
    }

    public function test_revoke_all_for_subject_can_filter_by_purpose(): void
    {
        $this->store->store($this->hash('a'), 'subject-1', TokenClaims::PURPOSE_AUTHENTICATION, time() + 1000);
        $this->store->store($this->hash('b'), 'subject-1', TokenClaims::PURPOSE_AUTHORIZATION, time() + 1000);

        $deleted = $this->store->revokeAllForSubject('subject-1', TokenClaims::PURPOSE_AUTHORIZATION);

        $this->assertSame(1, $deleted);
        $this->assertTrue($this->store->exists($this->hash('a')), 'authentication row retained');
        $this->assertFalse($this->store->exists($this->hash('b')), 'authorization row deleted');
    }

    public function test_expired_row_is_treated_as_absent(): void
    {
        $h = $this->hash('expired');
        $this->store->store($h, 'subject-1', TokenClaims::PURPOSE_AUTHORIZATION, time() - 5);

        $this->assertFalse($this->store->exists($h));
    }
}
