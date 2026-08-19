<?php

declare(strict_types=1);

namespace StoneScriptPHP\Analytics\Dto;

/**
 * Response contract for POST {prefix}/track. See
 * {@see \StoneScriptPHP\Analytics\Routes\PostTrackEventRoute}.
 *
 * @package StoneScriptPHP\Analytics\Dto
 */
class TrackEventResponseDto
{
    public bool $tracked = false;
}
