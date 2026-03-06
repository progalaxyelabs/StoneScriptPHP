<?php

namespace StoneScriptPHP;

class ApiResponse {
    public $status = '';
    public $message = '';
    public $data = null;
    public ?int $httpStatusCode = null;

    public function __construct($status, $message, $data = null, ?int $httpStatusCode = null)
    {
        $this->status = $status;
        $this->message = $message;
        $this->data = $data;
        $this->httpStatusCode = $httpStatusCode;
    }

    public function toJson(): string
    {
        return (string) json_encode([
            'status'  => $this->status,
            'message' => $this->message,
            'data'    => $this->data,
        ]);
    }
}