<?php

namespace PlaylogiqUtils\ServiceConfiguration;

/**
 * The one reader of an implementation's structured `$config_keys` (PQPL-7470):
 *
 *   public $config_keys = ['secrets' => [...], 'non_secrets' => [...], 'ignored' => [...]];
 *
 * Read from the property's default value, so the class is never constructed. Other shapes
 * (e.g. betmaker MobileValidators' flat list) are not structured and give null.
 */
class ConfigKeys
{
    /** @return array{secrets: string[], non_secrets: string[], ignored: string[]}|null */
    public static function of(string $class): ?array
    {
        if (!class_exists($class)) {
            return null;
        }
        $declared = (new \ReflectionClass($class))->getDefaultProperties()['config_keys'] ?? null;
        if (!is_array($declared) || !is_array($declared['secrets'] ?? null) || !is_array($declared['non_secrets'] ?? null)) {
            return null;
        }

        // Same normalisation as backoffice's PaymentProvider::configKeysOf(): a key in both lists is secret.
        $secrets = array_values(array_unique($declared['secrets']));

        return [
            'secrets' => $secrets,
            'non_secrets' => array_values(array_diff(array_unique($declared['non_secrets']), $secrets)),
            'ignored' => array_values((array) ($declared['ignored'] ?? [])),
        ];
    }
}
