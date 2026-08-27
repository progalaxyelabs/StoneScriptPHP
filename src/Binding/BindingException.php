<?php

declare(strict_types=1);

namespace StoneScriptPHP\Binding;

/**
 * Thrown by {@see DtoHydrator} when the request payload cannot be bound to
 * the declared DTO shape — a shape/type/presence problem, never a business
 * rule. Carries every collected error (hydration does not fail fast, so
 * every problem is reported at once rather than one-at-a-time) in the same
 * `{line, field, message}` shape a hand-rolled per-line validator would
 * emit, so `Router` can hand it straight to `ApiResponse`'s `$errors` slot.
 */
class BindingException extends \RuntimeException
{
    /** @var array<int, array{line: ?int, field: string, message: string}> */
    private array $errorList;
    private int $httpCode;

    /**
     * @param array<int, array{line: ?int, field: string, message: string}> $errors
     * @param int $httpCode Usually 400 (shape/type/presence). A handler's
     *   execute() may also throw this for a structured BUSINESS-rule
     *   rejection that wants the same {line,field,message}[] wire shape
     *   (e.g. a 409 duplicate-key conflict) — see
     *   PostDistributorInvoiceSubmitRoute for a real example.
     */
    public function __construct(array $errors, int $httpCode = 400)
    {
        $this->errorList = $errors;
        $this->httpCode = $httpCode;
        $first = $errors[0]['message'] ?? 'Validation failed';
        parent::__construct('Request binding failed: ' . $first, $httpCode);
    }

    /**
     * @return array<int, array{line: ?int, field: string, message: string}>
     */
    public function errors(): array
    {
        return $this->errorList;
    }

    public function httpCode(): int
    {
        return $this->httpCode;
    }
}
