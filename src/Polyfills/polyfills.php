<?php

/**
 * Laravel compatibility polyfills.
 *
 * Conditionally loads interface/class definitions that don't exist
 * in the current Laravel version but are used by this package.
 */

if (!interface_exists('Illuminate\Contracts\Queue\ShouldBeUnique')) {
    require_once __DIR__ . '/ShouldBeUniquePolyfill.php';
}
