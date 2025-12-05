<?php

namespace Framework;

interface IRouteHandler
{
    public function validation_rules(): array;
    public function process(): ApiResponse;
}
