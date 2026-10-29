<?php

declare(strict_types=1);

namespace PlaylogiqUtils\Tests\Unit\ServiceConfiguration;

use Illuminate\Container\Container;
use Illuminate\Support\Arr;
use PHPUnit\Framework\TestCase;

/**
 * Binds a bare 'config' into a fresh container so config('service_configuration.types')
 * resolves without Testbench or illuminate/config (neither is a dependency of utils).
 */
abstract class ConfiguredTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $container = new Container();
        Container::setInstance($container);
        $container->instance('config', new class {
            /** @var array<string, mixed> */
            public $items = [];

            public function get($key, $default = null)
            {
                return Arr::get($this->items, $key, $default);
            }
        });
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);

        parent::tearDown();
    }

    /** @param array<int, array<string, mixed>> $types */
    protected function setTypes(array $types): void
    {
        $config = Container::getInstance()->make('config');
        $config->items['service_configuration'] = ['types' => $types];
    }
}
