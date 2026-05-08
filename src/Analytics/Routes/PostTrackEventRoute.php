<?php

declare(strict_types=1);

namespace StoneScriptPHP\Analytics\Routes;

use StoneScriptPHP\IRouteHandler;
use StoneScriptPHP\ApiResponse;
use StoneScriptPHP\Database;
use StoneScriptPHP\Auth\AuthContext;
use StoneScriptPHP\Analytics\AnalyticsConfig;
use StoneScriptPHP\Security\RateLimiter;

/**
 * POST /portal/analytics/track
 *
 * Receives a single analytics event from the Angular frontend and stores it
 * in the analytics_events table via the gateway.
 *
 * Public endpoint (no JWT required) — secured by IP-based rate limiting only.
 * Optionally attaches user_id and tenant_id if a valid JWT is present.
 *
 * Expected request body:
 * {
 *   "event":      "page_view",
 *   "data":       { "page": "dashboard" },
 *   "session_id": "uuid-v4",
 *   "timestamp":  "ISO-8601"
 * }
 *
 * @package StoneScriptPHP\Analytics\Routes
 */
class PostTrackEventRoute implements IRouteHandler
{
    public string $event = '';
    public array $data = [];
    public string $session_id = '';
    public string $timestamp = '';

    public function __construct(private readonly AnalyticsConfig $config)
    {
    }

    public function validation_rules(): array
    {
        return [
            'event'      => 'required|string|max:100',
            'data'       => 'optional',
            'session_id' => 'optional|string|max:36',
            'timestamp'  => 'optional|string|max:50',
        ];
    }

    public function process(): ApiResponse
    {
        // Rate limit: N events per minute per IP (soft limit, per FPM worker)
        $limiter = new RateLimiter();
        if (!$limiter->check('analytics_track', $this->config->rateLimit, 60)) {
            return res_error('Rate limit exceeded', 429);
        }
        $limiter->record('analytics_track');

        // Attach user_id and tenant_id from JWT when present (optional auth)
        $userId   = null;
        $tenantId = null;
        if (AuthContext::check()) {
            $user     = AuthContext::getUser();
            $userId   = (string) ($user->user_id ?? '');
            $tenantId = (string) ($user->tenant_id ?? '');
        }

        // Resolve session_id — accept from body or fall back to null
        $sessionId = $this->session_id ?: null;

        // Parse session_id: must be a valid UUID to store as UUID type
        if ($sessionId !== null && !$this->isValidUuid($sessionId)) {
            $sessionId = null;
        }

        $ip        = client_ip();
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        $referrer  = $_SERVER['HTTP_REFERER'] ?? null;

        try {
            Database::fn('ana_insert_event', [
                $this->event,
                !empty($this->data) ? json_encode($this->data) : '{}',
                $sessionId,
                $userId,
                $tenantId,
                $ip,
                $userAgent,
                $referrer,
            ]);
        } catch (\Throwable $e) {
            // Log but return ok — analytics failures must not disrupt the caller
            log_error('[Analytics] Failed to store event "' . $this->event . '": ' . $e->getMessage());
        }

        return res_ok(['tracked' => true]);
    }

    /**
     * Validate UUID v4 format (xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx).
     */
    private function isValidUuid(string $value): bool
    {
        return (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $value
        );
    }
}
