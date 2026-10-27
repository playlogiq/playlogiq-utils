<?php

declare(strict_types=1);

namespace PlaylogiqUtils\Tests\Unit\ServiceConfiguration;

use PlaylogiqUtils\ServiceConfiguration\Models\ServiceConfigurationProfile;
use PlaylogiqUtils\ServiceConfiguration\SchemaResolver;
use RuntimeException;

class SchemaResolverTest extends ConfiguredTestCase
{
    private const NS = 'PlaylogiqUtils\\Tests\\Unit\\ServiceConfiguration\\Fixtures\\';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setTypes([
            1 => [
                'label'           => 'Plain',
                'namespace'       => self::NS,
                'implementations' => ['FakeImplementation', 'NoSchemaImplementation', 'BadSchemaImplementation', 'MissingClass'],
            ],
            2 => [
                'label'           => 'With extras',
                'namespace'       => self::NS,
                'implementations' => ['FakeImplementation'],
                'extra_fields'    => [
                    ['key' => 'include_test_players', 'label' => 'Include test players', 'type' => 'boolean', 'required' => false],
                ],
            ],
        ]);
    }

    public function test_it_returns_the_implementation_schema(): void
    {
        $this->assertSame(Fixtures\FakeImplementation::configSchema(), $this->resolve(1, 'FakeImplementation'));
    }

    public function test_it_appends_the_type_extra_fields(): void
    {
        $schema = $this->resolve(2, ' FakeImplementation ');

        $this->assertCount(3, $schema);
        $this->assertSame(['url', 'api_key', 'include_test_players'], array_column($schema, 'key'));
    }

    public function test_unknown_code_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid implementation for type=2, code=NoSchemaImplementation');

        $this->resolve(2, 'NoSchemaImplementation');
    }

    public function test_missing_class_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid implementation for type=1, code=MissingClass');

        $this->resolve(1, 'MissingClass');
    }

    public function test_class_without_config_schema_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Implementation ' . self::NS . 'NoSchemaImplementation missing configSchema().');

        $this->resolve(1, 'NoSchemaImplementation');
    }

    public function test_non_array_schema_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Implementation ' . self::NS . 'BadSchemaImplementation configSchema() must return array.');

        $this->resolve(1, 'BadSchemaImplementation');
    }

    private function resolve(int $type, string $code): array
    {
        return (new SchemaResolver())->resolve(new ServiceConfigurationProfile(['type' => $type, 'code' => $code]));
    }
}
