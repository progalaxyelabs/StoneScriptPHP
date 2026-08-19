<?php

declare(strict_types=1);

namespace Tests\Fixtures\Dto;

/**
 * Minimal fixture DTO used by ClientGeneratorRequestDtoAndStrictGateTest to
 * exercise `request:` DTO reflection without depending on a real framework
 * route's request shape.
 */
class CreateWidgetRequestFixtureDto
{
    public string $name = '';
    public int $quantity = 0;
}
