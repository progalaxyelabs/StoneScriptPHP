<?php

declare(strict_types=1);

namespace StoneScriptPHP\Subscriptions\Dto;

/**
 * Response contract for POST {prefix}/webhook/razorpay. Razorpay does not
 * read this body — it only cares about the HTTP status — but the response
 * is typed anyway for consistency with every other route (CLIENT-SDK-SPEC §10).
 *
 * @package StoneScriptPHP\Subscriptions\Dto
 */
class RazorpayWebhookAckDto
{
    public string $status = 'received';
}
