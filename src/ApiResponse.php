<?php

namespace StoneScriptPHP;

class ApiResponse {
    public $status = '';
    public $message = '';
    public $data = null;
    public ?int $httpStatusCode = null;
    public ?array $errors = null;

    /**
     * Extra response headers to emit with this response (name => value), e.g.
     * `Allow` on a 405. Emitted by {@see \StoneScriptPHP\Http\ResponseEmitter}.
     *
     * @var array<string, string>
     */
    public array $headers = [];

    /** True for a HEAD answered without running the handler: the body length is unknown, so no Content-Length. */
    public bool $headProbe = false;

    public function __construct($status, $message, $data = null, ?int $httpStatusCode = null, ?array $errors = null)
    {
        $this->status = $status;
        $this->message = $message;
        $this->data = $data;
        $this->httpStatusCode = $httpStatusCode;
        $this->errors = $errors;
    }

    public function toJson(): string
    {
        $response = [
            'status'  => $this->status,
            'message' => $this->message,
            'data'    => $this->data,
        ];

        // Include errors array if present (for validation errors)
        if ($this->errors !== null) {
            $response['errors'] = $this->errors;
        }

        return (string) json_encode($response);
    }
}