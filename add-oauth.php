<?php

if ($argc !== 2) {
    echo 'usage example: php add-oauth.php provider' . PHP_EOL;
    echo 'provider is one of the following' . PHP_EOL;
    echo 'google' . PHP_EOL;
    die(0);
}