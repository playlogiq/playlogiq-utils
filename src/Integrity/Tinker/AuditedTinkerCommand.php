<?php

declare(strict_types=1);

namespace PlaylogiqUtils\Integrity\Tinker;

use Illuminate\Support\Env;
use Laravel\Tinker\ClassAliasAutoloader;
use Laravel\Tinker\Console\TinkerCommand;
use Psy\Configuration;
use Psy\VersionUpdater\Checker;

/**
 * `php artisan tinker` running {@see AuditedShell}. Bound over the package command in
 * IntegrityServiceProvider.
 *
 * shortcut: handle() is laravel/tinker v2.9.0's with `new AuditedShell`, the package builds
 * its Shell inline; diff it against the parent on every laravel/tinker upgrade.
 */
class AuditedTinkerCommand extends TinkerCommand
{
    public function handle()
    {
        $this->getApplication()->setCatchExceptions(false);

        $config = Configuration::fromInput($this->input);
        $config->setUpdateCheck(Checker::NEVER);

        $config->getPresenter()->addCasters(
            $this->getCasters()
        );

        if ($this->option('execute')) {
            $config->setRawOutput(true);
        }

        $shell = new AuditedShell($config);
        $shell->addCommands($this->getCommands());
        $shell->setIncludes($this->argument('include'));

        $path = Env::get('COMPOSER_VENDOR_DIR', $this->getLaravel()->basePath().DIRECTORY_SEPARATOR.'vendor');

        $path .= '/composer/autoload_classmap.php';

        $config = $this->getLaravel()->make('config');

        $loader = ClassAliasAutoloader::register(
            $shell, $path, $config->get('tinker.alias', []), $config->get('tinker.dont_alias', [])
        );

        if ($code = $this->option('execute')) {
            try {
                $shell->setOutput($this->output);
                $shell->execute($code);
            } finally {
                $loader->unregister();
            }

            return 0;
        }

        try {
            return $shell->run();
        } finally {
            $loader->unregister();
        }
    }
}
