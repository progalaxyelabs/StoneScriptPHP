<?php

namespace Framework;

use Exception;

class Env
{

    private static ?Env $_instance = null;
        
    public $DATABASE_HOST;
    public $DATABASE_PORT;
    public $DATABASE_USER;
    public $DATABASE_PASSWORD;
    public $DATABASE_DBNAME;
    public $DATABASE_TIMEOUT;
    public $DATABASE_APPNAME;

    public $ZEPTOMAIL_BOUNCE_ADDRESS;
    public $ZEPTOMAIL_SENDER_EMAIL;
    public $ZEPTOMAIL_SENDER_NAME;
    public $ZEPTOMAIL_SEND_MAIL_TOKEN;

    private function __construct()
    {        
        $env_file_path = ROOT_PATH . DIRECTORY_SEPARATOR . '.env';
        if (!file_exists($env_file_path)) {
            $message = 'missing .env file';
            throw new Exception($message);
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
                $this->$key = $env[$key];
            } else {
                log_debug("missing setting in .env file [$key]");
                $missing_keys[] = $key;
            }
        }

        $num_missing_keys = count($missing_keys);
        if ($num_missing_keys > 0) {
            throw new Exception($num_missing_keys . ' Settings missing in .env file');
        }
    }


    public static function get_instance(): Env
    {
        if (!self::$_instance) {
            self::$_instance = new Env();
        }

        return self::$_instance;
    }
}
