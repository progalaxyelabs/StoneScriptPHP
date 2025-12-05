<?php

namespace App\Lib;

class Emails
{

    public static function is_valid_email(string $email): bool
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $matches = [];
        if (!preg_match('#^[a-z0-9\.]+@(.*)$#i', $email, $matches)) {
            return false;
        }

        $hosts = [];
        if (!getmxrr($matches[1], $hosts)) {
            return false;
        }

        $found = false;
        foreach ($hosts as $host) {
            if (!$host || ($host === '0.0.0.0')) {
                continue;
            } else {
                $found = true;
                break;
            }
        }

        if (!$found) {
            return false;
        }

        return true;
    }
}
