<?php

namespace App\Lib;

use Framework\Env;

class MyZeptoMail
{
    public static function send($recipient_email, $recipient_name, $email_subject, $email_body): bool
    {

        $env = Env::get_instance();

        // $zeptomail_bounce_address = getenv('ZEPTOMAIL_BOUNCE_ADDRESS') ?? '';
        // $zeptomail_sender_email = getenv('ZEPTOMAIL_SENDER_EMAIL') ?? '';
        // $zeptomail_sender_name = getenv('ZEPTOMAIL_SENDER_NAME') ?? '';
        // $zeptomail_send_mail_token = getenv('ZEPTOMAIL_SEND_MAIL_TOKEN') ?? '';

        $zeptomail_bounce_address = $env->ZEPTOMAIL_BOUNCE_ADDRESS;
        $zeptomail_sender_email = $env->ZEPTOMAIL_SENDER_EMAIL;
        $zeptomail_sender_name = $env->ZEPTOMAIL_SENDER_NAME;
        $zeptomail_send_mail_token = $env->ZEPTOMAIL_SEND_MAIL_TOKEN;

        if (!$zeptomail_bounce_address || !$zeptomail_sender_email || !$zeptomail_sender_name || !$zeptomail_send_mail_token) {
            log_error(__METHOD__ . ' Settings for zeptomail not properly configured. 
                                                Please configure bounce address, sender eamil, sender name, send mail token');
            return false;
        }

        $post_fields = [
            'bounce_address' => $zeptomail_bounce_address,
            'from' => [
                'address' => $zeptomail_sender_email,
                'name' => $zeptomail_sender_name
            ],
            'to' => [
                [
                    'email_address' => [
                        'address' => $recipient_email,
                        'name' => $recipient_name
                    ]
                ]
            ],
            'subject' => $email_subject,
            'htmlbody' => $email_body
        ];


        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => "https://api.zeptomail.in/v1.1/email",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_POSTFIELDS => json_encode($post_fields),
            CURLOPT_HTTPHEADER => array(
                "accept: application/json",
                "authorization: $zeptomail_send_mail_token",
                "cache-control: no-cache",
                "content-type: application/json",
            ),
        ));

        $response = curl_exec($curl);
        $error = curl_error($curl);

        curl_close($curl);

        if ($error) {
            // echo "cURL Error #:" . $error;
            log_debug(__METHOD__ . ' cURL error #' . $error);
            return false;
        } else {
            // echo $response;
            log_debug(__METHOD__ . ' email sent. response is ' . $response);
            return true;
        }
    }
}
