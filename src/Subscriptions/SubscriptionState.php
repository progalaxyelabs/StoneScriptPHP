<?php

declare(strict_types=1);

namespace StoneScriptPHP\Subscriptions;

/**
 * Immutable, pure description of a tenant's subscription situation, derived
 * from the `sub_get_status()` row. Everything the middleware, the response
 * header and the read-only refusal payload need comes from here.
 *
 * States:
 *  - ok            active, nothing to warn about
 *  - trial_ending  active trial inside the warning window
 *  - plan_ending   active paid plan inside the warning window
 *  - read_only     expired / cancelled / no subscription row
 *
 * @package StoneScriptPHP\Subscriptions
 */
final class SubscriptionState
{
    public const OK           = 'ok';
    public const TRIAL_ENDING = 'trial_ending';
    public const PLAN_ENDING  = 'plan_ending';
    public const READ_ONLY    = 'read_only';
    /** block mode only: reads are refused as well (never `read_only`). */
    public const BLOCKED      = 'blocked';

    public const REASON_TRIAL_EXPIRED   = 'trial_expired';
    public const REASON_PLAN_ENDED      = 'plan_ended';
    public const REASON_NO_SUBSCRIPTION = 'no_subscription';

    public function __construct(
        public readonly string $state,
        public readonly ?string $reason = null,
        public readonly ?\DateTimeImmutable $endsAt = null,
        public readonly ?int $daysRemaining = null,
        public readonly ?string $status = null,
        public readonly bool $isTrial = false,
    ) {
    }

    public function asBlocked(): self
    {
        return new self(self::BLOCKED, $this->reason, $this->endsAt, $this->daysRemaining, $this->status, $this->isTrial);
    }

    public function isReadOnly(): bool
    {
        return $this->state === self::READ_ONLY;
    }

    /** Machine-readable code placed in `data.error_code` of a 423 refusal. */
    public function errorCode(): string
    {
        return match ($this->reason) {
            self::REASON_TRIAL_EXPIRED   => 'READ_ONLY_TRIAL_EXPIRED',
            self::REASON_NO_SUBSCRIPTION => 'READ_ONLY_NO_SUBSCRIPTION',
            default                      => 'READ_ONLY_PLAN_ENDED',
        };
    }

    /**
     * Build the state from a decoded `sub_get_status()` row.
     *
     * @param array|null $row null = no subscription row for the tenant
     */
    public static function fromRow(?array $row, \DateTimeImmutable $now, int $warningDays): self
    {
        if (!$row) {
            return new self(self::READ_ONLY, self::REASON_NO_SUBSCRIPTION);
        }

        $status  = isset($row['status']) ? (string) $row['status'] : null;
        $isTrial = !empty($row['is_trial']) || $status === 'trial';
        $endsAt  = self::parseDate($row['expires_at'] ?? null);

        // Prefer our own clock-based evaluation of expires_at so the result is
        // deterministic; fall back to the SQL-computed is_active when the date
        // is absent/unparseable.
        $active = $endsAt !== null
            ? ($endsAt > $now)   // cancelled-at-period-end stays active until expires_at
            : (bool) ($row['is_active'] ?? false);

        if (!$active) {
            return new self(
                self::READ_ONLY,
                $isTrial ? self::REASON_TRIAL_EXPIRED : self::REASON_PLAN_ENDED,
                $endsAt,
                0,
                $status,
                $isTrial,
            );
        }

        $days = null;
        if ($endsAt !== null) {
            $days = (int) ceil(($endsAt->getTimestamp() - $now->getTimestamp()) / 86400);
            if ($days <= max(0, $warningDays)) {
                return new self(
                    $isTrial ? self::TRIAL_ENDING : self::PLAN_ENDING,
                    null,
                    $endsAt,
                    $days,
                    $status,
                    $isTrial,
                );
            }
        }

        return new self(self::OK, null, $endsAt, $days, $status, $isTrial);
    }

    /** Value of the `X-Subscription-State` response header. */
    public function toHeaderValue(): string
    {
        $parts = [$this->state];
        $date  = $this->endsAt?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');

        if ($this->state === self::TRIAL_ENDING || $this->state === self::PLAN_ENDING) {
            $parts[] = 'ends_at=' . $date;
            $parts[] = 'days=' . (int) $this->daysRemaining;
        } elseif ($this->state === self::READ_ONLY || $this->state === self::BLOCKED) {
            if ($date !== null) {
                $parts[] = 'ended_at=' . $date;
            }
            if ($this->reason !== null) {
                $parts[] = 'reason=' . $this->reason;
            }
        }

        return implode('; ', $parts);
    }

    /** Payload merged into `data` of a read-only refusal. */
    public function toRefusalData(): array
    {
        return [
            'error_code'         => $this->errorCode(),
            'subscription_state' => $this->state,
            'reason'             => $this->reason,
            'status'             => $this->status,
            'is_trial'           => $this->isTrial,
            'ended_at'           => $this->endsAt?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    private static function parseDate(mixed $value): ?\DateTimeImmutable
    {
        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value);
        }
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }
}
