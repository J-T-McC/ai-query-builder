<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Query Resources
    |--------------------------------------------------------------------------
    |
    | Classes implementing DefinesQuerySchema. Each one declares exactly what an
    | AI agent may select, filter, join, group and sort on a single resource.
    | Nothing is exposed to an agent until it is declared in one of these.
    |
    */

    'resources' => [
        //
    ],

    /*
    |--------------------------------------------------------------------------
    | Execution Guardrails
    |--------------------------------------------------------------------------
    |
    | Defaults applied to every query run through the QueryRunner. Each can be
    | overridden per call. Pointing "connection" at a read-only replica means a
    | compiler bug cannot write, no matter what a plan asks for.
    |
    | "timeout" is in milliseconds and is enforced with a statement timeout.
    | Only pgsql, mysql and mariadb can enforce one; on any other driver a
    | non-null timeout raises rather than being silently ignored.
    |
    */

    'execution' => [

        'connection' => null,

        'timeout' => null,

        'max_rows' => 1000,

    ],

    /*
    |--------------------------------------------------------------------------
    | Schema Generator
    |--------------------------------------------------------------------------
    |
    | Columns matching any of these patterns are left out of a generated schema
    | draft entirely rather than being scaffolded commented out, so that
    | uncommenting a block wholesale cannot expose them. Patterns use Str::is.
    |
    */

    'generator' => [

        'sensitive_columns' => [
            'password',
            'remember_token',
            'secret',
            '*_secret',
            '*_token',
            '*_hash',
            '*_key',
            'two_factor_*',
            'ssn',
            'social_security_number',
            'card_number',
            'cvv',
        ],

    ],

];
