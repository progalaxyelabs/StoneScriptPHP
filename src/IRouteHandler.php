<?php

namespace StoneScriptPHP;

interface IRouteHandler
{
    public function validation_rules(): array;
    public function process(): ApiResponse;
}
