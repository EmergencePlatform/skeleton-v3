<?php

class Validators
{
    public static function is($string, array $options = [])
    {
        $options = array_merge([
            'value' => false
        ], $options);

        if ($options['value']) {
            return ($string == $options['value']);
        }
        return ($string == true);
    }

    public static function string($string, array $options = [])
    {
        $options = array_merge([
            'minlength' => 1
            ,'maxlength' => false
        ], $options);

        return !empty($string) && is_string($string)
            && (strlen($string) >= $options['minlength'])
            && (($options['maxlength'] == false) || (strlen($string) <= $options['maxlength']));
    }

    public static function string_multiline($string, array $options = [])
    {
        $options = array_merge([
            'maxlength' => false,
            'maxwords' => false
        ], $options);

        return !empty($string)
                && (($options['maxlength'] == false) || (strlen((string) $string) <= $options['maxlength']))
                && (($options['maxwords'] == false) || (str_word_count((string) $string) <= $options['maxwords']));
    }

    public static function full_name($string)
    {
        return !empty($string) && ctype_print((string) $string) && preg_match('/[a-zA-Z][a-zA-Z\']+\s+([a-zA-Z][a-zA-Z\'.]*\s+)*[a-zA-Z][a-zA-Z\']+/', (string) $string);
    }

    public static function number($number, array $options = [])
    {
        $options = array_merge([
            'min' => false
            ,'max' => false
        ], $options);

        return is_numeric($number)
            && (($options['min'] === false) || ($number >= $options['min']))
            && (($options['max'] === false) || ($number <= $options['max']));
    }

    public static function email($email, array $options = [])
    {
        $pattern = '[_a-zA-Z0-9-+]+(\.[_+a-zA-Z0-9-]+)*@';

        if (empty($options['domain'])) {
            $pattern .= '[a-zA-Z0-9-]+(\.[a-zA-Z0-9-]+)*(\.[a-zA-Z]{2,})';
        } else {
            $pattern .= preg_quote($options['domain']);
        }

        return $email && preg_match('/^'.$pattern.'$/', (string) $email);
    }

    public static function URL($url, array $options = [])
    {
        $options = array_merge([
            'schemes' => ['http', 'https']
        ], $options);

        $scheme = parse_url((string) $url, PHP_URL_SCHEME);

        return $scheme
            && (empty($options['schemes']) || in_array(strtolower($scheme), $options['schemes']));
    }

    public static function datetime($datetime, array $options = [])
    {
        return !empty($datetime)
            && preg_match('/^\d{4}-\d{2}-\d{2}(\s*T?\d{2}:\d{2}(:\d{2})?)?$/', (string) $datetime);
    }

    public static function date_dmy($date, array $options = [])
    {
        $options = array_merge([
            'tokens' => '\\/-'
        ], $options);

        if (!is_array($date)) {
            $date = [strtok($date, $options['tokens'])];
            while (($part = strtok($options['tokens'])) !== false) {
                $date[] = $part;
            }
        }

        return is_array($date)
            && (count($date) === 3)
            && ctype_digit($date[0])
            && ctype_digit($date[1])
            && ctype_digit($date[2])
            && checkdate($date[1], $date[0], $date[2]);
    }

    public static function date_mdy($date, array $options = [])
    {
        $options = array_merge([
            'tokens' => '\\/-'
        ], $options);

        if (!is_array($date)) {
            $date = [strtok($date, $options['tokens'])];
            while (($part = strtok($options['tokens'])) !== false) {
                $date[] = $part;
            }
        }

        return is_array($date)
            && (count($date) === 3)
            && ctype_digit($date[0])
            && ctype_digit($date[1])
            && ctype_digit($date[2])
            && checkdate($date[0], $date[1], $date[2]);
    }

    public static function date_ymd($date, array $options = [])
    {
        $options = array_merge([
            'tokens' => '\\/-'
        ], $options);

        if (!is_array($date)) {
            $date = [strtok($date, $options['tokens'])];
            while (($part = strtok($options['tokens'])) !== false) {
                $date[] = $part;
            }
        }

        return is_array($date)
            && (count($date) === 3)
            && ctype_digit($date[0])
            && ctype_digit($date[1])
            && ctype_digit($date[2])
            && checkdate($date[1], $date[2], $date[0]);
    }

    public static function creditcard($cc, array $options = [])
    {
        $options = array_merge([
            'type' => false
        ], $options);

        if (!ctype_digit((string) $cc)) {
            return false;
        }

        //Match prefix to type
        if ($options['type'] !== false) {
            switch ($options['type']) {
                case 'visa':
                    if (substr((string) $cc, 0, 1) != 4) {
                        return false;
                    }
                    if ((strlen((string) $cc) !== 13) && (strlen((string) $cc) !== 16)) {
                        return false;
                    }
                    break;

                case 'mc':
                    $prefix = substr((string) $cc, 0, 2);
                    if (($prefix < 51) || ($prefix > 55)) {
                        return false;
                    }
                    if (strlen((string) $cc) !== 16) {
                        return false;
                    }
                    break;

                case 'amex':
                    $prefix = substr((string) $cc, 0, 2);
                    if (($prefix !== '34') && ($prefix !== '37')) {
                        return false;
                    }
                    if (strlen((string) $cc) !== 15) {
                        return false;
                    }
                    break;

                case 'disc':
                    if ((!str_starts_with((string) $cc, '65')) && (!str_starts_with((string) $cc, '6011'))) {
                        return false;
                    }
                    if (strlen((string) $cc) !== 16) {
                        return false;
                    }
                    break;
            }
        }

        $odd = true;
        $sum = 0;

        foreach (array_reverse(str_split((string) $cc)) as $num) {
            $sum += array_sum(str_split(($odd = !$odd) ? $num*2 : $num));
        }

        return (($sum % 10 === 0) && ($sum != 0));
    }

    public static function cvv($cvv, array $options = [])
    {
        $options = array_merge([
            'type' => false
        ], $options);

        if (!ctype_digit((string) $cvv)) {
            return false;
        }

        switch ($options['type']) {
            case 'visa':

            case 'mc':

            case 'disc':
                return (strlen((string) $cvv) === 3);
            case 'amex':
                return (strlen((string) $cvv) === 4);

            default:
                return ((strlen((string) $cvv) === 3) || (strlen((string) $cvv) === 4));
        }
    }

    public static function csc($csc, array $options = [])
    {
        return self::cvv($csc, $options);
    }


    public static function state($state, array $options = [])
    {
        $options = array_merge([
            'country' => 'US'
        ], $options);

        return self::state_province($state, $options);
    }

    public static function province($state, array $options = [])
    {
        $options = array_merge([
            'country' => 'CA'
        ], $options);

        return self::state_province($state, $options);
    }

    public static function state_province($state_province, array $options = [])
    {
        $options = array_merge([
            'country' => 'US'
        ], $options);

        // capitalize
        $state_province = strtoupper((string) $state_province);

        switch ($options['country']) {
            case 'US':
            return in_array($state_province, array_keys(Address::$usStates));

            case 'AU':
            return in_array($state_province, array_keys(Address::$auStates));

            case 'CA':
            return in_array($state_province, array_keys(Address::$caProvinces));

            default:
            return true;
        }
    }

    public static function zip($zip, array $options = [])
    {
        $options = array_merge([
            'country' => 'US'
        ], $options);

        return self::zip_postal($zip, $options);
    }

    public static function postal($postal, array $options = [])
    {
        $options = array_merge([
            'country' => 'CA'
        ], $options);

        return self::zip_postal($postal, $options);
    }

    public static function zip_postal($postal, array $options = [])
    {
        $options = array_merge([
            'country' => 'US'
        ], $options);
        if ($options['country'] == 'US') {
            return !empty($postal) && preg_match('/^\d{5}([^a-zA-Z0-9]*\d{4})?$/', (string) $postal);
        }
        if ($options['country'] == 'CA') {
            return !empty($postal) && preg_match('/^[a-zA-Z]\d[a-zA-Z][[:space:]]?\d[a-zA-Z]\d$/', (string) $postal);
        }

        if (in_array($options['country'], ['AD','AR', 'BM', 'BN', 'JM', 'MT', 'MD', 'NL', 'GB', 'VE'])) {
            return !empty($postal) && preg_match('/^[a-zA-Z0-9 \-]+$/', (string) $postal);
        }
        return true;
    }

    public static function address($address)
    {
        return !empty($address)
            && preg_match('/\d+/', $address) && preg_match('/[a-zA-Z]+/', $address);
    }

    public static function aim($screenname)
    {
        return !empty($screenname)
            && preg_match('/^[a-zA-Z][a-zA-Z0-9 ]{2,15}$/', (string) $screenname);
    }

    public static function phone($phone, array $options = [])
    {
        $options = array_merge([
            'country' => 'US'
            ,'fakeDetector' => false
        ], $options);


        // flatten array
        if (is_array($phone)) {
            $phone = implode('', $phone);
        }

        //Strip all non-numeric characters
        $phone = preg_replace('/\D/', '', (string) $phone);

        // validate based on counttry
        switch ($options['country']) {
            case 'US':
            case 'CA':
                // strip leading 1
                if ((strlen((string) $phone) === 11) && ($phone[0] == '1')) {
                    $phone = substr((string) $phone, 1);
                }
                return
                (
                    (strlen((string) $phone) === 10)
                    && (!$options['fakeDetector'] || substr((string) $phone, 3, 3) !== '555')
                );

            default:
            return
            (
                (strlen((string) $phone) >= 8)
                && (strlen((string) $phone) <= 15)
            );
        }
        return null;
    }

    public static function match($fields)
    {
        return is_array($fields)
            && (count($fields) === 2)
            && ($fields[0] == $fields[1]);
    }

    public static function selection($value, array $options = [])
    {
        $options = array_merge([
            'choices' => []
        ], $options);

        return in_array($value, $options['choices']);
    }

    public static function set($value, array $options = [])
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }

        $options = array_merge([
            'choices' => []
            ,'minCount' => 0
        ], $options);

        if (!$value) {
            return $options['minCount'] == 0;
        }

        return !count(array_diff($value, $options['choices'])) && (count($value) >= $options['minCount']);
    }

    public static function time_hm($value, array $options = [])
    {
        $options = array_merge([
            'delimiter' => ':'
            ,'24hour' => false
        ], $options);

        if (empty($value) || (substr_count((string) $value, $options['delimiter']) !== 1)) {
            return false;
        }


        if (!$options['24hour']) {
            switch (strtolower(substr((string) $value, -2))) {
                case 'am': case 'pm':
                    $value = substr((string) $value, 0, -2);
                    break;
            }
        }

        [$hour, $minute] = explode($options['delimiter'], (string) $value);

        return ctype_digit($hour)
            && ($hour >= 0)
            && ($hour <= ($options['24hour'] ? 24 : 12))
            && ctype_digit($minute)
            && ($minute >= 0)
            && ($minute <= 59);
    }

    public static function time_24hm($value, array $options = [])
    {
        $options = array_merge([
            'delimiter' => ':'
            ,'24hour' => true
        ], $options);

        return self::time_hm($value, $options);
    }

    public static function apple_serial($serial, array $options = [])
    {
        return preg_match('/^([a-z0-9]{2})([0-9])([0-9]{2})([a-z0-9]{6})$/i', (string) $serial);
    }

    public static function identifier_string($string, array $options = [])
    {
        $options = array_merge([
            'minlength' => 0
            ,'maxlength' => false
        ], $options);

        return !empty($string) && ctype_print((string) $string)
            && (strlen((string) $string) > $options['minlength'])
            && (($options['maxlength'] == false) || (strlen((string) $string) <= $options['maxlength']))
            && preg_match('/^[a-zA-Z][a-zA-Z0-9_.-]*[a-zA-Z0-9]$/', (string) $string);
    }

    public static function regexp($string, array $options = [])
    {
        $options = array_merge([
            'regexp' => '/^[a-zA-Z][a-zA-Z0-9_.-]*[a-zA-Z0-9]$/'
        ], $options);

        return preg_match($options['regexp'], (string) $string);
    }

    public static function ctype($string, array $options = [])
    {
        $options = array_merge([
            'ctype' => 'alnum'
        ], $options);

        $func = 'ctype_'.$options['ctype'];

        return $func($string);
    }

    public static function macaddr($string, array $options = [])
    {
        return preg_match('/^[0-9a-f]{2}:?[0-9a-f]{2}:?[0-9a-f]{2}:?[0-9a-f]{2}:?[0-9a-f]{2}:?[0-9a-f]{2}$/i', (string) $string);
    }

    public static function className($string, array $options = [])
    {
        $options = array_merge([
            'ancestor' => false
        ], $options);

        return preg_match('/^[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff\\\\]*$/', (string) $string) && class_exists($string)
            && (
                empty($options['ancestor'])
                || ($string == $options['ancestor'])
                || is_subclass_of($string, $options['ancestor'])
            );
    }

    public static function SSN($string)
    {
        //Strip all non-numeric characters
        $string = preg_replace('/\D/', '', (string) $string);

        return (strlen((string) $string) === 9);
    }

    public static function handle($string, array $options = [])
    {
        $options = array_merge([
            'pattern' => '/^[\\pL][\\pL\d_:\-\.]*$/u'
            ,'allowNumeric' => false
        ], $options);

        return ($options['allowNumeric'] || !is_numeric($string)) && preg_match($options['pattern'], (string) $string);
    }

    public static function FQDN($string, array $options = [])
    {
        $options = array_merge([
            'pattern' => '/(?=^.{1,254}$)(^(?:(?!\d+\.)[a-zA-Z0-9_\-]{1,63}\.?)+(?:[a-zA-Z]{2,})$)/'
        ], $options);

        return preg_match($options['pattern'], (string) $string);
    }

    public static function items($value, array $options = [])
    {
        $options = array_merge([
            'itemValidator' => 'string'
            ,'itemValidatorOptions' => []
            ,'delimiter' => '/\s*,\s*/'
            ,'minItems' => 0
            ,'maxItems' => 0
        ], $options);

        if (is_string($value)) {
            $value = preg_split($options['delimiter'], $value);
        }
        if (!is_array($value)) {
            return false;
        }
        if (!empty($options['minItems']) && count($value) < $options['minItems']) {
            return false;
        }
        if (!empty($options['maxItems']) && count($value) > $options['maxItems']) {
            return false;
        }

        if (empty($options['itemValidator'])) {
            return true;
        }

        foreach ($value AS $item) {
            if (!call_user_func([self::class,$options['itemValidator']], $item, $options['itemValidatorOptions'])) {
                return false;
            }
        }

        return true;
    }
}