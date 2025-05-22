<?php

namespace App;

class Env
{
    public static string $DATABASE_HOST = 'localhost';
    public static int    $DATABASE_PORT = 5432;
    public static string $DATABASE_USER = 'postgres';
    public static string $DATABASE_PASSWORD = 'postgres';
    public static string $DATABASE_DBNAME = 'postgres';
    // public static int    $DATABASE_TIMEOUT;
    public static string $DATABASE_APPNAME = '';

    public static string $ZEPTOMAIL_BOUNCE_ADDRESS = '';
    public static string $ZEPTOMAIL_SENDER_EMAIL = '';
    public static string $ZEPTOMAIL_SENDER_NAME = '';
    public static string $ZEPTOMAIL_SEND_MAIL_TOKEN = '';

    public static int    $DEBUG_MODE = 0;

    public static string $TIMEZONE = 'UTC';

    public static string $OAUTH_APP_DOMAIN = '.example.com';
}

