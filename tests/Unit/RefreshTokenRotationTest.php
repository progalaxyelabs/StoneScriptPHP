<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\Auth\InMemoryRefreshTokenStore;
use StoneScriptPHP\Auth\RefreshTokens\RefreshRejectedException;
use StoneScriptPHP\Auth\RefreshTokens\RefreshTokenIssuer;
use StoneScriptPHP\Auth\RefreshTokens\RotationResult;
use StoneScriptPHP\Auth\RefreshTokens\TokenInspection;
use StoneScriptPHP\Auth\TokenClaims;
use StoneScriptPHP\Tests\Fixtures\FakeJwt;
use StoneScriptPHP\Tests\Fixtures\ThrowingStore;

/** L3: session families, rotation, reuse detection, grace, revocation, purge. */
final class RefreshTokenRotationTest extends TestCase
{
    private int $now = 1_000_000;

    private function store(int $grace = 10): InMemoryRefreshTokenStore
    {
        return new InMemoryRefreshTokenStore($grace, fn (): int => $this->now);
    }

    private function h(string $s): string
    {
        return hash('sha256', $s);
    }

    // ---- store semantics (reference implementation) ----

    public function test_rotation_keeps_family_and_marks_old_token_spent(): void
    {
        $s = $this->store();
        $s->store($this->h('a'), 'u1', 'authentication', $this->now + 1000);
        $r = $s->rotate($this->h('a'), $this->h('b'), $this->now + 1000);

        $this->assertSame(RotationResult::OK, $r->status);
        $this->assertSame($s->inspect($this->h('b'))->familyId, $r->familyId);
        $this->assertSame('u1', $r->subject);
        $this->assertTrue($s->exists($this->h('b')));
        $this->assertSame(2, $s->count(), 'spent token stays as a tombstone');
    }

    public function test_old_token_is_in_grace_then_reused(): void
    {
        $s = $this->store(10);
        $s->store($this->h('a'), 'u1', 'authentication', $this->now + 1000);
        $s->rotate($this->h('a'), $this->h('b'), $this->now + 1000);

        $this->assertSame(TokenInspection::GRACE, $s->inspect($this->h('a'))->status);
        $this->assertTrue($s->exists($this->h('a')), 'inside grace the old token is still presentable');

        $this->now += 11;
        $this->assertSame(TokenInspection::REUSED, $s->inspect($this->h('a'))->status);
        $this->assertFalse($s->exists($this->h('a')));
    }

    public function test_reuse_beyond_grace_revokes_the_whole_family(): void
    {
        $s = $this->store(10);
        $s->store($this->h('a'), 'u1', 'authentication', $this->now + 1000);
        $s->rotate($this->h('a'), $this->h('b'), $this->now + 1000);
        $s->rotate($this->h('b'), $this->h('c'), $this->now + 1000);
        $this->now += 60;

        $r = $s->rotate($this->h('a'), $this->h('x'), $this->now + 1000); // attacker replays the oldest token
        $this->assertSame(RotationResult::REUSED, $r->status);
        $this->assertFalse($s->exists($this->h('c')), 'the legitimate live token dies with its family');
        $this->assertFalse($s->exists($this->h('x')));
        $this->assertSame(0, $s->count());
    }

    public function test_grace_allows_exactly_one_extra_exchange_then_it_is_reuse(): void
    {
        $s = $this->store(10);
        $s->store($this->h('a'), 'u1', 'authentication', $this->now + 1000);
        $this->assertTrue($s->rotate($this->h('a'), $this->h('b1'), $this->now + 1000)->ok());
        $this->assertTrue($s->rotate($this->h('a'), $this->h('b2'), $this->now + 1000)->ok(), 'the ONE extra exchange');
        $this->assertSame($s->inspect($this->h('b1'))->familyId, $s->inspect($this->h('b2'))->familyId);
        $this->assertSame(TokenInspection::REUSED, $s->inspect($this->h('a'))->status, 'spent for good after the extra exchange');

        $r = $s->rotate($this->h('a'), $this->h('b3'), $this->now + 1000);
        $this->assertSame(RotationResult::REUSED, $r->status, 'a third use inside grace is an attack');
        $this->assertSame(0, $s->count(), 'family wiped');
    }

    public function test_absolute_session_cap_never_slides(): void
    {
        $s = new InMemoryRefreshTokenStore(10, fn (): int => $this->now, 1000);
        $s->store($this->h('a'), 'u1', 'authentication', $this->now + 5000);
        $this->assertSame(TokenInspection::VALID, $s->inspect($this->h('a'))->status);

        // keep refreshing every 400s: each successor asks for a fresh 5000s but is capped at the original +1000
        for ($i = 0, $prev = 'a'; $i < 2; $i++) {
            $this->now += 400;
            $next = 'n' . $i;
            $this->assertTrue($s->rotate($this->h($prev), $this->h($next), $this->now + 5000)->ok());
            $prev = $next;
        }
        $this->now += 400; // 1200s after login: past the 1000s cap although the token itself is fresh
        $this->assertSame(TokenInspection::EXPIRED, $s->inspect($this->h('n0'))->status, 'every token of the session is capped');
        $this->assertSame(RotationResult::EXPIRED, $s->rotate($this->h($prev), $this->h('late'), $this->now + 5000)->status);
    }

    public function test_unknown_and_expired(): void
    {
        $s = $this->store();
        $this->assertSame(RotationResult::UNKNOWN, $s->rotate($this->h('nope'), $this->h('n'), $this->now + 1)->status);
        $s->store($this->h('a'), 'u1', 'authentication', $this->now + 5);
        $this->now += 6;
        $this->assertSame(RotationResult::EXPIRED, $s->rotate($this->h('a'), $this->h('b'), $this->now + 100)->status);
        $this->assertSame(0, $s->count());
    }

    public function test_revoke_session_deletes_every_token_of_the_family_only(): void
    {
        $s = $this->store();
        $s->store($this->h('a'), 'u1', 'authentication', $this->now + 1000);
        $s->rotate($this->h('a'), $this->h('b'), $this->now + 1000);
        $s->store($this->h('other'), 'u1', 'authentication', $this->now + 1000);

        $this->assertSame(2, $s->revokeSession($this->h('b')));
        $this->assertTrue($s->exists($this->h('other')));
        $this->assertSame(0, $s->revokeSession($this->h('missing')));
    }

    public function test_revoke_all_for_subject_by_purpose(): void
    {
        $s = $this->store();
        $s->store($this->h('a'), 'u1', 'authentication', $this->now + 1000);
        $s->store($this->h('b'), 'u1', 'authorization', $this->now + 1000);
        $this->assertSame(1, $s->revokeAllForSubject('u1', 'authorization'));
        $this->assertTrue($s->exists($this->h('a')));
        $this->assertSame(1, $s->revokeAllForSubject('u1'));
    }

    public function test_purge_expired_is_batched_and_keeps_live_rows(): void
    {
        $s = $this->store();
        for ($i = 0; $i < 5; $i++) {
            $s->store($this->h("e$i"), 'u', 'authentication', $this->now + 1);
        }
        $s->store($this->h('live'), 'u', 'authentication', $this->now + 1000);
        $this->now += 10;

        $this->assertSame(2, $s->purgeExpired(2));
        $this->assertSame(3, $s->purgeExpired(100));
        $this->assertSame(0, $s->purgeExpired(100));
        $this->assertSame(1, $s->count());
        $this->assertSame(0, $s->purgeExpired(100, 3600), 'older-than window respected');
    }

    public function test_store_is_idempotent_for_the_same_hash(): void
    {
        $s = $this->store();
        $s->store($this->h('a'), 'u1', 'authentication', $this->now + 1000);
        $family = $s->inspect($this->h('a'))->familyId;
        $s->store($this->h('a'), 'u1', 'authentication', $this->now + 1000);
        $this->assertSame(1, $s->count());
        $this->assertSame($family, $s->inspect($this->h('a'))->familyId);
    }

    // ---- issuer ----

    private function issuer(?InMemoryRefreshTokenStore $store = null, bool $rotate = false, ?callable $provider = null): RefreshTokenIssuer
    {
        return new RefreshTokenIssuer(new FakeJwt(), $store ?? $this->store(), 900, 100000, $rotate, $provider);
    }

    public function test_issue_session_persists_the_hash_never_the_token(): void
    {
        $store = $this->store();
        $t = $this->issuer($store)->issueSession(['user_id' => 'u1', 'email' => 'a@b.c']);

        $this->assertNotNull($t->refreshToken);
        $this->assertTrue($store->exists(hash('sha256', $t->refreshToken)));
        $this->assertFalse($store->exists($t->refreshToken), 'a raw token is not a key');
        $ref = new \ReflectionProperty($store, 'rows');
        foreach (array_keys($ref->getValue($store)) as $key) {
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $key);
        }
    }

    public function test_issue_session_fails_closed_when_the_row_cannot_be_written(): void
    {
        $issuer = new RefreshTokenIssuer(new FakeJwt(), new ThrowingStore());
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('sign-in aborted');
        $issuer->issueSession(['user_id' => 'u1']);
    }

    public function test_refresh_tokens_minted_in_the_same_second_are_distinct(): void
    {
        $i = $this->issuer();
        $a = $i->issueSession(['user_id' => 'u1']);
        $b = $i->issueSession(['user_id' => 'u1']);
        $this->assertNotSame($a->refreshToken, $b->refreshToken, 'jti makes identical claims unique');
    }

    public function test_issue_requires_a_subject(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->issuer()->issueSession(['email' => 'a@b.c']);
    }

    public function test_refresh_without_rotation_returns_access_only(): void
    {
        $i = $this->issuer();
        $s = $i->issueSession(['user_id' => 'u1']);
        $r = $i->refresh($s->refreshToken);
        $this->assertNull($r->refreshToken);
        $this->assertSame('u1', (new FakeJwt())->verifyToken($r->accessToken)['user_id']);
        // and again: same refresh token still good
        $i->refresh($s->refreshToken);
        $this->addToAssertionCount(1);
    }

    public function test_refresh_with_rotation_returns_new_pair_and_old_dies_after_grace(): void
    {
        $store = $this->store(10);
        $i = $this->issuer($store, true);
        $s = $i->issueSession(['user_id' => 'u1']);
        $r = $i->refresh($s->refreshToken);

        $this->assertNotNull($r->refreshToken);
        $this->assertNotSame($s->refreshToken, $r->refreshToken);
        $this->assertSame('authentication', (new FakeJwt())->verifyToken($r->refreshToken)['purpose']);

        $this->now += 30;
        try {
            $i->refresh($s->refreshToken);
            $this->fail('replay must be refused');
        } catch (RefreshRejectedException $e) {
            $this->assertSame(RefreshRejectedException::REUSE_DETECTED, $e->reason());
        }
        // family revoked: the legitimate new token is dead too
        $this->expectException(RefreshRejectedException::class);
        $i->refresh($r->refreshToken);
    }

    public function test_refresh_rejects_bad_tokens_with_a_reason(): void
    {
        $i = $this->issuer();
        $access = $i->issueSession(['user_id' => 'u1'])->accessToken;
        foreach (['garbage' => RefreshRejectedException::INVALID_TOKEN, $access => RefreshRejectedException::WRONG_TYPE] as $tok => $reason) {
            try {
                $i->refresh((string) $tok);
                $this->fail();
            } catch (RefreshRejectedException $e) {
                $this->assertSame($reason, $e->reason());
            }
        }
        $orphan = (new FakeJwt())->generateToken(['user_id' => 'u9', 'jti' => 'x'], 100, 'refresh');
        $this->expectException(RefreshRejectedException::class);
        $i->refresh($orphan); // valid signature, no row: never issued / revoked
    }

    public function test_claims_provider_refreshes_claims_or_rejects_when_subject_gone(): void
    {
        $i = $this->issuer(null, false, fn (array $c): ?array => $c['user_id'] === 'gone' ? null : $c + ['role' => 'admin']);
        $ok = $i->issueSession(['user_id' => 'u1']);
        $this->assertSame('admin', (new FakeJwt())->verifyToken($i->refresh($ok->refreshToken)->accessToken)['role']);

        $gone = $i->issueSession(['user_id' => 'gone']);
        $this->expectException(RefreshRejectedException::class);
        $i->refresh($gone->refreshToken);
    }

    public function test_revoke_session_logs_out_and_unknown_is_harmless(): void
    {
        $i = $this->issuer();
        $s = $i->issueSession(['user_id' => 'u1']);
        $this->assertSame(1, $i->revokeSession($s->refreshToken));
        $this->assertSame(0, $i->revokeSession($s->refreshToken));
        $this->expectException(RefreshRejectedException::class);
        $i->refresh($s->refreshToken);
    }

    public function test_rotation_requires_a_rotating_store(): void
    {
        $this->expectException(\LogicException::class);
        new RefreshTokenIssuer(new FakeJwt(), new ThrowingStore(), 900, 100, true);
    }

    public function test_revoke_all_delegates(): void
    {
        $store = $this->store();
        $i = $this->issuer($store);
        $i->issueSession(['user_id' => 'u1']);
        $i->issueSession(['user_id' => 'u1']);
        $this->assertSame(2, $i->revokeAll('u1'));
    }
}
