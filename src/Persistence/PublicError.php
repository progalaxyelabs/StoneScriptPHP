<?php

declare(strict_types=1);

namespace StoneScriptPHP\Persistence;

use StoneScriptPHP\ApiResponse;

/**
 * A fully specified, user-facing error response: what a {@see DbErrorMapper::extend()} classifier returns
 * when it needs more than `[status, message]` (dynamic data such as an invoice number, a structured
 * `errors` list). Everything in it is shown to the client as-is, so only put PUBLIC text in it.
 */
final class PublicError
{
    /**
     * @param array<string, mixed>|null $data
     * @param array<int, mixed>|null    $errors
     */
    public function __construct(
        public readonly int $status,
        public readonly string $message,
        public readonly ?array $data = null,
        public readonly ?array $errors = null,
        public readonly ?string $errorCode = null,
        /** True when the message is an explicit business message; false for a framework-classified generic one. */
        public readonly bool $public = true,
        /** False when `$status` is only the default 400 (a "not found" wording may then upgrade it to 404). */
        public readonly bool $explicitStatus = true,
    ) {
    }

    public function toResponse(): ApiResponse
    {
        $status = ($this->status >= 400 && $this->status < 600) ? $this->status : 500;
        $data = $this->data;
        if ($this->errorCode !== null) {
            $data = ['error_code' => $this->errorCode] + ($data ?? []);
        }
        if (!headers_sent()) {
            http_response_code($status);
        }
        return new ApiResponse('error', $this->message, $data, $status, $this->errors);
    }
}
