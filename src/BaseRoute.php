<?php

namespace StoneScriptPHP;

abstract class BaseRoute implements IRouteHandler
{
    abstract public function validation_rules(): array;
    abstract protected function buildRequest(): IRequest;
    abstract protected function execute(IRequest $request): IResponse;

    protected function custom_validation(): string|array|null
    {
        return null;
    }

    public function process(): ApiResponse
    {
        $error = $this->custom_validation();
        if ($error !== null) {
            return res_error($error, 400);
        }

        $request = $this->buildRequest();
        $response = $this->execute($request);
        return res_ok($response);
    }
}
