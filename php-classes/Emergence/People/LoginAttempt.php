<?php

namespace Emergence\People;

/**
 * A single FAILED password-login attempt, recorded by PasswordAuthenticator's
 * brute-force / spray throttle. The table is created on demand by
 * ActiveRecord's create-on-TableNotFound mechanism — no migration needed.
 * Rows are cleared for a username on its next successful login and pruned
 * past the throttle window; only failures are ever stored.
 */
class LoginAttempt extends \ActiveRecord
{
    public static $tableName = 'login_attempts';
    public static $singularNoun = 'login attempt';
    public static $pluralNoun = 'login attempts';

    public static $fields = [
        'Username' => [
            'type' => 'string',
            'notnull' => true,
        ],
        'IP' => [
            'type' => 'integer',
            'unsigned' => true,
            'notnull' => false,
        ],
    ];

    public static $indexes = [
        'UsernameCreated' => ['fields' => ['Username', 'Created']],
        'IPCreated' => ['fields' => ['IP', 'Created']],
        'Created' => ['fields' => ['Created']],
    ];
}
