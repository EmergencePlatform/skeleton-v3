<?php

namespace Emergence\Util;

class Capitalizer
{
    public static $familyNamePrefixes = [
        'Mc',
        'Mac',
        'Van',
        // these don't work consistently:
#        'De',
#        'Di',
    ];

    public static function capitalizePronoun($word, $familyName = false)
    {
        $me = static::class;
        $_recurse = (fn($word) => $me::capitalizePronoun($word, $familyName));
        if (preg_match('/^[ea]l-\pL/u', (string) $word)) {
            // el- / al- prefixes stay lowercase
            return strtolower(substr((string) $word, 0, 2)).'-'.static::capitalizePronoun(substr((string) $word, 3));
        }

        if (str_contains((string) $word, '-')) {
            // process hyphenated-separated bits independently
            return implode('-', array_map($_recurse, explode('-', (string) $word)));
        }

        if (str_contains((string) $word, ' ')) {
            // process space-separated bits independently
            return implode(' ', array_map($_recurse, explode(' ', (string) $word)));
        }

        if (str_contains((string) $word, '\'')) {
            // process apostrophe-separated bits independently
            return implode('\'', array_map($_recurse, explode('\'', (string) $word)));
        }

        if (str_contains((string) $word, '.')) {
            // process .-separated bits independently
            return implode('.', array_map($_recurse, explode('.', (string) $word)));
        }

        // roman numerals (only detects 1-14) should be all caps
        if (preg_match('/^(i{1,3}|i?vi{0,3}|i?xi{0,3})$/i', (string) $word)) {
            return strtoupper((string) $word);
        }

        // start out all-lowercase
        $word = strtolower((string) $word);

        // first letter always capitalized
        $word = ucfirst($word);

        // handly family name prefixes
        if ($familyName) {
            foreach (static::$familyNamePrefixes AS $prefix) {
                if (str_starts_with($word, (string) $prefix)) {
                    $prefixLen = strlen((string) $prefix);

                    if (
                        // skip if a double letter follows the prefix (e.g. Derry)
                        !preg_match('/^([a-z])\1/', substr($word, $prefixLen)) &&

                        // skip if only one letter is left after the prefix
                        strlen($word) > $prefixLen + 1
                    ) {
                        $word = substr($word, 0, $prefixLen).ucfirst(substr($word, $prefixLen));
                    }
                    break;
                }
            }
        }

        return $word;
    }
}