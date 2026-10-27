<?php

declare(strict_types=1);

namespace PlaylogiqUtils\Tests\Unit\ServiceConfiguration;

use PlaylogiqUtils\ServiceConfiguration\Models\ServiceConfigurationProfile;
use PlaylogiqUtils\ServiceConfiguration\TypeRegistry;

class TypeRegistryTest extends ConfiguredTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setTypes([
            1 => [
                'label'           => 'Sportbook',
                'namespace'       => 'App\\Services\\Sportbooks\\',
                'implementations' => ['LogiqsportPLQ'],
            ],
            2 => [
                'label'           => 'Tracking systems',
                'namespace'       => 'App\\Services\\TrackingSystems\\',
                'implementations' => ['KludataTS', 'PrismeTS'],
                'extra_fields'    => [
                    ['key' => 'include_test_players', 'label' => 'Include test players', 'type' => 'boolean', 'required' => false],
                ],
            ],
            3 => [
                'label' => 'No namespace',
                'implementations' => ['Orphan'],
            ],
        ]);
    }

    public function test_type_options(): void
    {
        $this->assertSame([1 => 'Sportbook', 2 => 'Tracking systems', 3 => 'No namespace'], TypeRegistry::typeOptions());
    }

    public function test_namespaces_and_allowed_implementations(): void
    {
        $this->assertSame(
            [1 => 'App\\Services\\Sportbooks\\', 2 => 'App\\Services\\TrackingSystems\\'],
            TypeRegistry::implementationNamespaceByType()
        );
        $this->assertSame(
            [1 => ['LogiqsportPLQ'], 2 => ['KludataTS', 'PrismeTS'], 3 => ['Orphan']],
            TypeRegistry::allowedImplementationsByType()
        );
    }

    public function test_resolve_implementation_class(): void
    {
        $this->assertSame('App\\Services\\TrackingSystems\\PrismeTS', TypeRegistry::resolveImplementationClass(2, 'PrismeTS'));
        $this->assertSame('App\\Services\\TrackingSystems\\PrismeTS', TypeRegistry::resolveImplementationClass(2, '  PrismeTS '));
        $this->assertNull(TypeRegistry::resolveImplementationClass(2, null));
        $this->assertNull(TypeRegistry::resolveImplementationClass(2, ''));
        $this->assertNull(TypeRegistry::resolveImplementationClass(2, 'LogiqsportPLQ'), 'code of another type');
        $this->assertNull(TypeRegistry::resolveImplementationClass(2, '\\PrismeTS'), 'only exact allowed codes');
        $this->assertNull(TypeRegistry::resolveImplementationClass(3, 'Orphan'), 'type without namespace');
        $this->assertNull(TypeRegistry::resolveImplementationClass(99, 'PrismeTS'));
    }

    public function test_is_allowed_implementation(): void
    {
        $this->assertTrue(TypeRegistry::isAllowedImplementation(1, 'LogiqsportPLQ'));
        $this->assertFalse(TypeRegistry::isAllowedImplementation(1, 'logiqsportplq'));
        $this->assertFalse(TypeRegistry::isAllowedImplementation(99, 'LogiqsportPLQ'));
    }

    public function test_extra_fields(): void
    {
        $this->assertSame('include_test_players', TypeRegistry::extraFields(2)[0]['key']);
        $this->assertSame([], TypeRegistry::extraFields(1));
        $this->assertSame([], TypeRegistry::extraFields(99));
    }

    public function test_empty_config_means_no_types(): void
    {
        $this->setTypes([]);

        $this->assertSame([], TypeRegistry::typeOptions());
        $this->assertNull(TypeRegistry::resolveImplementationClass(1, 'LogiqsportPLQ'));
    }

    public function test_model_static_methods_delegate_to_the_registry(): void
    {
        $this->assertSame(TypeRegistry::typeOptions(), ServiceConfigurationProfile::typeOptions());
        $this->assertSame(TypeRegistry::implementationNamespaceByType(), ServiceConfigurationProfile::implementationNamespaceByType());
        $this->assertSame(TypeRegistry::allowedImplementationsByType(), ServiceConfigurationProfile::allowedImplementationsByType());
        $this->assertSame('App\\Services\\Sportbooks\\LogiqsportPLQ', ServiceConfigurationProfile::resolveImplementationClass(1, 'LogiqsportPLQ'));
        $this->assertTrue(ServiceConfigurationProfile::isAllowedImplementation(1, 'LogiqsportPLQ'));
    }
}
