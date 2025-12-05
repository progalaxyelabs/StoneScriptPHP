<?php

namespace Framework;

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
}