<?php

namespace Illuminate\Contracts\Queue;

/**
 * Polyfill for ShouldBeUnique interface (introduced in Laravel 8).
 * On Laravel 7, this empty definition allows code that implements
 * ShouldBeUnique to compile. Uniqueness enforcement won't be active.
 */
interface ShouldBeUnique
{
}
