<?php

namespace PlaylogiqUtils\ServiceConfiguration;

use PlaylogiqUtils\ServiceConfiguration\Models\ServiceConfigurationProfile;
use RuntimeException;

/**
 * The form schema of a profile: its implementation's configSchema() plus the type's
 * extra_fields from the registry (PQPL-7443; betmaker hard-coded tracking's
 * include_test_players here, it now comes from config).
 */
class SchemaResolver
{
    public function resolve(ServiceConfigurationProfile $scp): array
    {
        $type = (int) $scp->type;
        $code = trim((string) ($scp->code ?? ''));

        $fqcn = TypeRegistry::resolveImplementationClass($type, $code);

        if (!$fqcn || !class_exists($fqcn)) {
            throw new RuntimeException("Invalid implementation for type={$type}, code={$code}");
        }

        if (!method_exists($fqcn, 'configSchema')) {
            throw new RuntimeException("Implementation {$fqcn} missing configSchema().");
        }

        $schema = $fqcn::configSchema();

        if (!is_array($schema)) {
            throw new RuntimeException("Implementation {$fqcn} configSchema() must return array.");
        }

        foreach (TypeRegistry::extraFields($type) as $field) {
            $schema[] = $field;
        }

        return $schema;
    }
}
