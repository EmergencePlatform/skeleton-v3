<?php

namespace Emergence\Mailer;

abstract class AbstractMailer implements IMailer
{
    public static function getDefaultFrom()
    {
        return Mailer::$defaultFrom ? Mailer::$defaultFrom : '"'.\Site::getConfig('label').'" <support@'.\Site::getConfig('primary_hostname').'>';
    }

    public static function sendFromTemplate($to, $template, $data = [], $options = []): bool
    {
        $email = static::renderTemplate($template, $data);

        return static::send($to, $email['subject'], $email['body'], $email['from'], $options);
    }

    /**
     * Take the custom headers out of a send() $options array, in every shape
     * IMailer callers pass them, as a list of name/value pairs. Accepted:
     *
     * - `Headers` as a `Name => value` map (PHPMailer's contract, e.g.
     *   ContactRequestHandler's `['Reply-To' => ...]`)
     * - `Headers` as a list of raw `Name: value` lines, or one string of
     *   newline-separated lines
     * - `Headers` as a list of `['Name' => ..., 'Value' => ...]` pairs
     *   (Postmark's own shape)
     * - raw `Name: value` lines as numeric-keyed $options entries (the
     *   Email::send tradition)
     *
     * The consumed entries are removed from $options.
     *
     * @return list<array{Name: string, Value: string}>
     */
    protected static function extractHeaders(array &$options): array
    {
        $given = $options['Headers'] ?? [];
        unset($options['Headers']);

        if (is_string($given)) {
            $given = preg_split('/\r\n|\r|\n/', $given);
        }

        $headers = [];

        foreach (is_array($given) ? $given : [] as $key => $value) {
            if (is_string($key) && is_scalar($value)) {
                $headers[] = ['Name' => trim($key), 'Value' => trim((string) $value)];
            } elseif (is_array($value) && isset($value['Name'], $value['Value'])) {
                $headers[] = ['Name' => trim((string) $value['Name']), 'Value' => trim((string) $value['Value'])];
            } elseif (is_string($value)) {
                $header = static::parseHeaderLine($value);

                if ($header !== null) {
                    $headers[] = $header;
                }
            }
        }

        foreach ($options as $key => $value) {
            if (!is_int($key)) {
                continue;
            }
            if (!is_string($value)) {
                continue;
            }

            $header = static::parseHeaderLine($value);

            if ($header !== null) {
                unset($options[$key]);
                $headers[] = $header;
            }
        }

        return $headers;
    }

    /**
     * @return array{Name: string, Value: string}|null
     */
    protected static function parseHeaderLine(string $line): ?array
    {
        if (!str_contains($line, ':')) {
            return null;
        }

        [$name, $value] = array_map(trim(...), explode(':', $line, 2));

        return $name === '' ? null : ['Name' => $name, 'Value' => $value];
    }

    public static function renderTemplate($template, $data = [])
    {
        $email = [
            'from' => null,
            'subject' => null,
            'body' => trim((string) \Emergence\Dwoo\Engine::getSource($template.'.email', $data))
        ];

        $templateVars = \Emergence\Dwoo\Engine::getInstance()->scope;

        if (isset($templateVars['from'])) {
            $email['from'] = trim(preg_replace('/\s+/', ' ', $templateVars['from']));
        }

        if (isset($templateVars['subject'])) {
            $email['subject'] = trim(preg_replace('/\s+/', ' ', $templateVars['subject']));
        }

        return $email;
    }
}
