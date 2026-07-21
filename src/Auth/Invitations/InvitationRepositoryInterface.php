<?php

declare(strict_types=1);

namespace StoneScriptPHP\Auth\Invitations;

/**
 * InvitationRepositoryInterface
 *
 * The minimal read/write contract {@see InvitationCompletionService} needs
 * against a platform's OWN `platform_invitations` table. Implemented by the
 * generated `PlatformInvitationRepository` (see
 * src/Templates/Invitations/routes/PlatformInvitationRepository.php.template
 * — copied into the consuming platform's own repo by
 * `php stone generate invitations`, never shipped as a concrete class here).
 *
 * Deliberately does NOT include invitation creation (`create()`) — invite
 * creation is a platform-authorization concern ("who can invite, at what
 * role" — see design doc §1.2/§4.2), not something the shared completion
 * service ever calls. The generated repository is free to add a `create()`
 * method (and does, by default) for `PostCreateInvitationRoute` to call, but
 * it is not part of this interface because `InvitationCompletionService`
 * never needs it.
 *
 * @package StoneScriptPHP\Auth\Invitations
 */
interface InvitationRepositoryInterface
{
    /**
     * Look up a pending (or already-resolved) invitation by the SHA-256 hex
     * hash of its raw token — never the raw token itself (design doc §1.1 /
     * §3.4 step 1: the raw token is never stored or compared).
     *
     * @param string $tokenHash sha256() hex digest of the raw invite token.
     * @return InvitationRecord|null Null when no row matches.
     */
    public function findByTokenHash(string $tokenHash): ?InvitationRecord;

    /**
     * Mark an invitation as accepted (design doc §3.4 step 7).
     *
     * @param string $id Invitation row id.
     * @param string $identityId The accepting identity's id, taken from the
     *   validated passport's `sub`/`identity_id` claim.
     */
    public function markAccepted(string $id, string $identityId): void;
}
