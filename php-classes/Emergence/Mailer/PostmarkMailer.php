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

    public static function send($to, $subject, $body, $from = false, $options = [])
    {
        // callers in the Email::send tradition pass recipient lists as arrays
        // (see PHPMailer::send); Postmark's To is a comma-separated string
        if (is_array($to)) {
            $to = implode(', ', $to);
        }

        if (!$from) {
            $from = static::getDefaultFrom();
        }

        // callers in the Email::send tradition pass raw header lines as
        // numeric-keyed $options entries; translate them into API fields
        // (Postmark ignores unknown numeric members, so these were dropped)
        $headers = isset($options['Headers']) && is_array($options['Headers']) ? $options['Headers'] : [];
        foreach ($options as $key => $value) {
            if (!is_int($key)) {
                continue;
            }
            if (!is_string($value)) {
                continue;
            }
            if (!str_contains($value, ':')) {
                continue;
            }
            unset($options[$key]);
            [$name, $content] = array_map(trim(...), explode(':', $value, 2));

            if (strcasecmp($name, 'Reply-To') === 0) {
                $options['ReplyTo'] = empty($options['ReplyTo']) ? $content : $options['ReplyTo'].', '.$content;
            } else {
                $headers[] = ['Name' => $name, 'Value' => $content];
            }
        }
        if (count($headers)) {
            $options['Headers'] = $headers;
        }

        if (count(static::$verifiedFromDomains) > 0) {
            $fromAddress = preg_match('/<([^>]+)>/', (string) $from, $matches) ? $matches[1] : trim((string) $from);
            $fromDomain = strtolower(substr(strrchr($fromAddress, '@'), 1));

            if (!in_array($fromDomain, array_map(strtolower(...), static::$verifiedFromDomains))) {
                if (empty($options['ReplyTo'])) {
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

        return static::apiPost(array_merge($options, [
            'To' => $to
            ,'From' => $from
            ,'Subject' => $subject
            ,'HtmlBody' => $body
        ]));
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
