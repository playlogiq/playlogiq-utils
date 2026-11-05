<?php

declare(strict_types=1);

namespace PlaylogiqUtils\Support;

use Composer\Autoload\ClassLoader;
use ReflectionClass;

/**
 * Root directory of the consuming application, without the container.
 *
 * Shared by BuildInfo and ManifestInfo, both of which may run while
 * config/app.php is being loaded — before the container instance is bound,
 * when base_path() is a defined function that still fatals. Composer's
 * autoloader lives at <root>/vendor/composer/ClassLoader.php, so its own file
 * locates the root whether this package is installed into vendor/ or symlinked
 * there from a path repository. When Composer is not in play at all (a bare
 * include), fall back to this package's root, which is the right answer when
 * this package *is* the checkout.
 */
final class ApplicationRoot
{
    public static function detect(): string
    {
        if (class_exists(ClassLoader::class, false)) {
            $file = (new ReflectionClass(ClassLoader::class))->getFileName();

            if (is_string($file) && $file !== '') {
                // …/vendor/composer/ClassLoader.php → …/vendor → the root.
                return dirname($file, 3);
            }
        }

        return dirname(__DIR__, 2);
    }
}
