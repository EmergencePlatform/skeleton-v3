<?php

namespace Emergence\Mailer;

class PostmarkMailer extends AbstractMailer
{
    public static $apiKey = '';

    // Domains this Postmark server may send From. When non-empty, a From
    // address outside the list is rewritten to the site default sender
    // (keeping the original display name) and the original address joins
    // ReplyTo — Postmark hard-rejects unverified sender domains, which
    // otherwise silently kills mail authored by accounts on external
    // domains (e.g. district addresses).
    public static $verifiedFromDomains = [];

    /**
     * The decoded Postmark API response (MessageID, SubmittedAt, ...) for the
     * last message accepted by send(), or null when the last send failed.
     * send() itself returns a bool, as IMailer requires.
     */
    public static ?array $lastResponse = null;

    public static function send($to, $subject, $body, $from = false, $options = []): bool
    {
        // callers in the Email::send tradition pass recipient lists as arrays
        // (see PHPMailer::send); Postmark's To is a comma-separated string
        if (is_array($to)) {
            $to = implode(', ', $to);
        }

        if (!$from) {
            $from = static::getDefaultFrom();
        }

        // Postmark takes custom headers as a list of {Name, Value} objects;
        // callers pass PHPMailer's `Name => value` map or raw header lines,
        // which Postmark rejects or drops, so translate them. Reply-To is
        // Postmark's own ReplyTo field rather than a custom header.
        $headers = [];
        foreach (static::extractHeaders($options) as $header) {
            if (strcasecmp($header['Name'], 'Reply-To') === 0) {
                $options['ReplyTo'] = isset($options['ReplyTo']) && $options['ReplyTo'] !== ''
                    ? $options['ReplyTo'].', '.$header['Value']
                    : $header['Value'];
            } else {
                $headers[] = $header;
            }
        }
        if (count($headers) > 0) {
            $options['Headers'] = $headers;
        }

        if (count(static::$verifiedFromDomains) > 0) {
            $fromAddress = preg_match('/<([^>]+)>/', (string) $from, $matches) ? $matches[1] : trim((string) $from);
            $fromDomain = strtolower(substr(strrchr($fromAddress, '@'), 1));

            if (!in_array($fromDomain, array_map(strtolower(...), static::$verifiedFromDomains), true)) {
                if (!isset($options['ReplyTo']) || $options['ReplyTo'] === '') {
                    $options['ReplyTo'] = $from;
                } elseif (stripos($options['ReplyTo'], $fromAddress) === false) {
                    $options['ReplyTo'] .= ', '.$from;
                }

                $fromName = preg_match('/^\s*"?([^"<]+?)"?\s*</', (string) $from, $matches) ? trim($matches[1]) : $fromAddress;
                $defaultFrom = static::getDefaultFrom();
                $defaultAddress = preg_match('/<([^>]+)>/', (string) $defaultFrom, $matches) ? $matches[1] : trim((string) $defaultFrom);
                $from = sprintf('"%s" <%s>', addslashes($fromName), $defaultAddress);
            }
        }

        $response = static::apiPost(array_merge($options, [
            'To' => $to
            ,'From' => $from
            ,'Subject' => $subject
            ,'HtmlBody' => $body
        ]));

        static::$lastResponse = is_array($response) ? $response : null;

        // apiPost() returns false on any non-200 response; Postmark answers
        // 200 only when it has accepted the message
        return $response !== false;
    }


    protected static function apiPost($data)
    {
        $ch = curl_init('https://api.postmarkapp.com/email');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json'
            ,'Accept: application/json'
            ,'X-Postmark-Server-Token: '.static::$apiKey
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        if ($data) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $result = curl_exec($ch);
        $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($httpStatus == 200) {
            return json_decode($result, true);
        }
        \Emergence\Logger::general_error('PostmarkMailer Delivery Error', [
            'exceptionClass' => static::class,
            'exceptionMessage' => $result,
            'exceptionCode' => $httpStatus
        ]);
        return false;
    }
}
