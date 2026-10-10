<?php

namespace StoneScriptPHP\Auth\Client;

use StoneScriptPHP\Exceptions\PublicMessage;

/**
 * An auth-service failure whose message is safe to show a user because the auth service SAID so: its error response
 * carried a structured `public_message` string (e.g. "That code is incorrect."). {@see getMessage()} is that text only;
 * the full upstream detail (status, `error`, `message`) is in {@see getPrevious()} for the sanitised log.
 *
 * Upstream free text without `public_message` is never turned into this class: it stays an {@see AuthServiceException}
 * and the client gets a generic sentence plus a correlation id.
 */
final class AuthServicePublicException extends AuthServiceException implements PublicMessage
{
    /** Longest public message accepted from upstream. */
    public const MAX_LENGTH = 300;

    /** The cleaned `public_message` of an error body, or null when absent/unusable. */
    public static function extract(mixed $errorBody): ?string
    {
        if (!is_array($errorBody) || !isset($errorBody['public_message']) || !is_string($errorBody['public_message'])) {
            return null;
        }
        $text = trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', $errorBody['public_message']) ?? '');
        if ($text === '') {
            return null;
        }
        return function_exists('mb_substr') ? mb_substr($text, 0, self::MAX_LENGTH) : substr($text, 0, self::MAX_LENGTH);
    }
}
