<?php

namespace Emergence\Mailer;

class Mailgun extends AbstractMailer
{
    public static $domain;
    public static $apiKey;

    /**
     * The decoded Mailgun API response for the last message accepted by
     * send(), or null when the last send failed. send() itself returns a
     * bool, as IMailer requires.
     */
    public static ?array $lastResponse = null;

    public static function send($to, $subject, $body, $from = false, array $options = []): bool
    {
        if (!$from) {
            $from = static::getDefaultFrom();
        }

        $response = static::apiPost(array_merge($options, [
            'to' => $to,
            'from' => $from,
            'subject' => $subject,
            'html' => $body
        ]));

        static::$lastResponse = is_array($response) ? $response : null;

        // apiPost() returns false on any non-200 response
        return $response !== false;
    }

    protected static function apiPost(array $data)
    {
        $ch = curl_init('https://api.mailgun.net/v3/'.static::$domain.'/messages');

        curl_setopt($ch, CURLOPT_USERPWD, 'api:'.static::$apiKey);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);

        $result = curl_exec($ch);
        $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($httpStatus == 200) {
            return json_decode($result, true);
        }
        \Emergence\Logger::general_error('Mailgun Delivery Error', [
            'exceptionClass' => static::class,
            'exceptionMessage' => $result,
            'exceptionCode' => $httpStatus
        ]);
        return false;
    }
}
