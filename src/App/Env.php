<?php

namespace App;

class Env
{
    public static string $DATABASE_HOST;
    public static string $DATABASE_PORT;
    public static string $DATABASE_USER;
    public static string $DATABASE_PASSWORD;
    public static string $DATABASE_DBNAME;
    public static int    $DATABASE_TIMEOUT;
    public static string $DATABASE_APPNAME;

    public static string $ZEPTOMAIL_BOUNCE_ADDRESS;
    public static string $ZEPTOMAIL_SENDER_EMAIL;
    public static string $ZEPTOMAIL_SENDER_NAME;
    public static string $ZEPTOMAIL_SEND_MAIL_TOKEN;

    public static int    $DEBUG_MODE;

    public static string $TIMEZONE;
}


function init_env()
{
    $env_file_path = ROOT_PATH . DIRECTORY_SEPARATOR . '.env';
    if (!file_exists($env_file_path)) {
        $message = 'missing .env file';
        throw new \Exception($message);
    }

    $class = get_class();

    $properties = array_filter(array_keys(get_class_vars($class)), function ($item) {
        return ($item !== '_instance');
    });

    // log_debug('env properties are ' . var_export($properties, true));

    $class = get_class();

    $missing_keys = [];

    $env = parse_ini_file($env_file_path);
    foreach ($properties as $key) {
        if (array_key_exists($key, $env)) {
            Env::$$key = $env[$key];
        } else {
            log_debug("missing setting in .env file [$key]");
            $missing_keys[] = $key;
        }
    }

    $num_missing_keys = count($missing_keys);
    if ($num_missing_keys > 0) {
        throw new \Exception($num_missing_keys . ' Settings missing in .env file');
    }
}

init_env();

