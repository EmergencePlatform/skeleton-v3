<?php

namespace Emergence\Mailer;

interface IMailer
{
    /**
     * Hand one message to the mail transport.
     *
     * Returns true when the transport accepted the message and false when it
     * did not. Callers count and branch on this value, so every
     * implementation must return a bool — never a transport response.
     */
    public static function send($to, $subject, $body, $from = false): bool;

    /**
     * Render a template and send it; returns what send() returns.
     */
    public static function sendFromTemplate($to, $template, $data = [], $options = []): bool;

    public static function renderTemplate($template, $data = []);
}
