<?php

namespace PlaylogiqUtils\ServiceConfiguration;

/**
 * The profile types an application offers and the implementations each one may point at,
 * read from config('service_configuration.types') so every application registers its own
 * (PQPL-7443):
 *
 *   [typeId => [
 *       'label'           => 'Tracking systems',
 *       'namespace'       => 'App\\Services\\TrackingSystems\\',
 *       'implementations' => ['KludataTS', ...],   // exact codes, the allow-list
 *       'extra_fields'    => [[schema row], ...],   // appended to every schema of the type
 *   ]]
 */
class TypeRegistry
{
    public static function types(): array
    {
        return (array) config('service_configuration.types', []);
    }

    /** @return array<int, string> */
    public static function typeOptions(): array
    {
        $options = [];
        foreach (static::types() as $type => $definition) {
            $options[$type] = (string) ($definition['label'] ?? $type);
        }

        return $options;
    }

    /** @return array<int, string> types without a namespace are left out */
    public static function implementationNamespaceByType(): array
    {
        $namespaces = [];
        foreach (static::types() as $type => $definition) {
            if (!empty($definition['namespace'])) {
                $namespaces[$type] = (string) $definition['namespace'];
            }
        }

        return $namespaces;
    }

    /** @return array<int, string[]> */
    public static function allowedImplementationsByType(): array
    {
        $allowed = [];
        foreach (static::types() as $type => $definition) {
            $allowed[$type] = array_values((array) ($definition['implementations'] ?? []));
        }

        return $allowed;
    }

    /**
     * The implementation class of an allowed code, or null. Only an exact allowed code
     * resolves, so a profile can never be pointed at an arbitrary class.
     */
    public static function resolveImplementationClass(int $type, ?string $code): ?string
    {
        $code = trim((string) $code);

        if ($code === '') {
            return null;
        }

        $ns = static::implementationNamespaceByType()[$type] ?? null;
        if (!$ns) {
            return null;
        }

        if (!static::isAllowedImplementation($type, $code)) {
            return null;
        }

        return $ns . ltrim($code, '\\');
    }

    public static function isAllowedImplementation(int $type, string $code): bool
    {
        $allowed = static::allowedImplementationsByType()[$type] ?? [];

        return in_array($code, $allowed, true);
    }

    /** Schema rows every implementation of the type gets on top of its own configSchema(). */
    public static function extraFields(int $type): array
    {
        return array_values((array) (static::types()[$type]['extra_fields'] ?? []));
    }
}
