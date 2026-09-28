<?php

declare(strict_types=1);

use PlaylogiqUtils\Support\BuildInfo;

/**
 * Merged into the application's `app` config key by BuildInfoServiceProvider.
 *
 * It is a separate file rather than something the application pastes into its
 * own config/app.php so that every project reports build identity the same way.
 * An application that does define `app.build` or `app.version` itself wins:
 * mergeConfigFrom lets the application's value override the package default.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Build Version
    |--------------------------------------------------------------------------
    |
    | Identity of the commit this code was built from, read by
    | PlaylogiqUtils\Support\BuildInfo from build-info.txt, which git expands
    | when the artifact is built (export-subst). A plain checkout has nothing to
    | expand and reports 'unknown' — never assume a real SHA is present.
    |
    */

    'build' => [
        'commit'  => BuildInfo::commit(),
        'short'   => BuildInfo::short(),
        'date'    => BuildInfo::date(),
        'ref'     => BuildInfo::ref(),
        'version' => BuildInfo::version(),
    ],

    /*
    |--------------------------------------------------------------------------
    | Application Version
    |--------------------------------------------------------------------------
    |
    | The release this code belongs to, as people talk about it ("2.14.0"). It
    | is declared, not derived: unlike app.build it says nothing about which
    | commit is actually running, so the two are shown together rather than one
    | standing in for the other.
    |
    */

    'version' => env('APP_VERSION'),

];
