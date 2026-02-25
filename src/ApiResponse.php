<?php

namespace StoneScriptPHP;

class ApiResponse {
    public $status = '';
    public $message = '';
    // public $error_codes = [];
    public $data = null;

    public function __construct($status, $message, $data = null)
    {
        $this->status = $status;
        $this->message = $message;
        $this->data = $data;
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