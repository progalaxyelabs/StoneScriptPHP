<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\Auth\BuiltinOAuth\LoginUser;

/**
 * Unit tests for the LoginUser chokepoint: it is the ONLY way to build a
 * login-user payload, and it must be structurally impossible to emit a
 * contract-violating one (missing/misnamed email or display_name).
 *
 * @covers \StoneScriptPHP\Auth\BuiltinOAuth\LoginUser
 */
class LoginUserTest extends TestCase
{
    // ─── create() — R1 structural guard ─────────────────────────────────────

    public function testCreateThrowsWhenEmailEmpty(): void
    {
        $this->expectException(\RuntimeException::class);
        LoginUser::create('1', '', 'Ann Lee', true, null);
    }

    public function testCreateThrowsWhenEmailWhitespaceOnly(): void
    {
        $this->expectException(\RuntimeException::class);
        LoginUser::create('1', '   ', 'Ann Lee', true, null);
    }

    public function testCreateThrowsWhenDisplayNameEmpty(): void
    {
        $this->expectException(\RuntimeException::class);
        LoginUser::create('1', 'ann@example.com', '', true, null);
    }

    public function testCreateThrowsWhenDisplayNameWhitespaceOnly(): void
    {
        $this->expectException(\RuntimeException::class);
        LoginUser::create('1', 'ann@example.com', '   ', true, null);
    }

    public function testCreateSucceedsWithValidFields(): void
    {
        $user = LoginUser::create('1', 'ann@example.com', 'Ann Lee', true, 'https://x/pic.png');
        $this->assertSame('ann@example.com', $user->getEmail());
        $this->assertSame('Ann Lee', $user->getDisplayName());
        $this->assertTrue($user->isEmailVerified());
        $this->assertSame('https://x/pic.png', $user->getPhotoUrl());
    }

    public function testEmptyPhotoUrlIsNormalizedToNullNeverFabricated(): void
    {
        $user = LoginUser::create('1', 'ann@example.com', 'Ann Lee', true, '');
        $this->assertNull($user->getPhotoUrl());
    }

    // ─── toArray() — exact emitted shape ────────────────────────────────────

    public function testToArrayEmitsExactCanonicalShape(): void
    {
        $user = LoginUser::create('1', 'ann@example.com', 'Ann Lee', true, 'https://x/pic.png');
        $arr = $user->toArray();

        $this->assertSame('1', $arr['user_id']);
        $this->assertSame('1', $arr['identity_id']); // defaults to user_id
        $this->assertSame('ann@example.com', $arr['email']);
        $this->assertSame('Ann Lee', $arr['display_name']);
        $this->assertSame('Ann Lee', $arr['name']); // serializer-set alias mirror
        $this->assertTrue($arr['is_email_verified']);
        $this->assertSame('https://x/pic.png', $arr['photo_url']);
    }

    public function testToArrayOmitsPhotoUrlAsNullWhenGenuinelyUnknown(): void
    {
        $user = LoginUser::create('1', 'ann@example.com', 'Ann Lee', true, null);
        $this->assertNull($user->toArray()['photo_url']);
    }

    public function testExtraClaimsAreMerged(): void
    {
        $user = LoginUser::create('1', 'ann@example.com', 'Ann Lee', true, null, ['role' => 'admin']);
        $this->assertSame('admin', $user->toArray()['role']);
    }

    public function testExtraClaimsCannotOverrideCoreFields(): void
    {
        // A resolver smuggling `display_name` into extra_claims must not be
        // able to override the real value passed to create().
        $user = LoginUser::create('1', 'ann@example.com', 'Ann Lee', true, null, [
            'display_name' => 'Hijacked',
            'email' => 'hijacked@example.com',
            'is_email_verified' => false,
        ]);
        $arr = $user->toArray();
        $this->assertSame('Ann Lee', $arr['display_name']);
        $this->assertSame('ann@example.com', $arr['email']);
        $this->assertTrue($arr['is_email_verified']);
    }

    public function testIdentityIdViaExtraClaimsOverridesDefault(): void
    {
        $user = LoginUser::create('1', 'ann@example.com', 'Ann Lee', true, null, ['identity_id' => 'uuid-123']);
        $arr = $user->toArray();
        $this->assertSame('uuid-123', $arr['identity_id']);
    }

    // ─── fromResolverArray() — matrix C11 guard ─────────────────────────────

    public function testFromResolverArrayThrowsOnLegacyNameKeyWithoutDisplayName(): void
    {
        // The exact bug this design closes: a resolver returning `name`
        // instead of `display_name` must NOT silently produce a login user.
        $this->expectException(\RuntimeException::class);
        LoginUser::fromResolverArray(
            ['user_id' => '1', 'email' => 'ann@example.com', 'name' => 'Ann Lee'],
            ['email' => 'ann@example.com', 'email_verified' => true]
        );
    }

    public function testFromResolverArrayThrowsOnEmptyDisplayName(): void
    {
        $this->expectException(\RuntimeException::class);
        LoginUser::fromResolverArray(
            ['user_id' => '1', 'email' => 'ann@example.com', 'display_name' => ''],
            ['email' => 'ann@example.com', 'email_verified' => true]
        );
    }

    public function testFromResolverArraySucceedsWithCorrectShape(): void
    {
        $user = LoginUser::fromResolverArray(
            ['user_id' => '1', 'email' => 'ann@example.com', 'display_name' => 'Ann Lee', 'is_email_verified' => true, 'photo_url' => 'P1'],
            ['email' => 'ann@example.com', 'email_verified' => true]
        );
        $arr = $user->toArray();
        $this->assertSame('Ann Lee', $arr['display_name']);
        $this->assertSame('Ann Lee', $arr['name']);
        $this->assertTrue($arr['is_email_verified']);
        $this->assertSame('P1', $arr['photo_url']);
    }

    public function testFromResolverArrayIsEmailVerifiedFallsBackToProfileNotHardcodedTrue(): void
    {
        // D1: is_email_verified must be a REAL sourced boolean. When the
        // resolver doesn't supply one, fall back to the verified ID-token
        // payload's actual value — never fabricate true.
        $user = LoginUser::fromResolverArray(
            ['user_id' => '1', 'email' => 'ann@example.com', 'display_name' => 'Ann Lee'],
            ['email' => 'ann@example.com', 'email_verified' => false]
        );
        $this->assertFalse($user->toArray()['is_email_verified']);
    }

    public function testFromResolverArrayExtraClaimsPassThrough(): void
    {
        $user = LoginUser::fromResolverArray(
            ['user_id' => '1', 'email' => 'ann@example.com', 'display_name' => 'Ann Lee', 'role' => 'admin', 'plan' => 'pro'],
            ['email' => 'ann@example.com', 'email_verified' => true]
        );
        $arr = $user->toArray();
        $this->assertSame('admin', $arr['role']);
        $this->assertSame('pro', $arr['plan']);
    }
}
