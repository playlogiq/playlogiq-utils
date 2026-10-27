<?php

namespace PlaylogiqUtils\ServiceConfiguration\Models;

use Illuminate\Database\Eloquent\Model;
use PlaylogiqUtils\ServiceConfiguration\TypeRegistry;

/**
 * A named set of key/value settings for one implementation (code) of one service type,
 * owned by whatever the application scopes services to (PQPL-7443).
 *
 * The owner is polymorphic: each application registers its own morph alias, e.g.
 * Relation::morphMap(['skin' => Skin::class]) in betmaker, 'brand' in backoffice.
 *
 * Applications subclass this to add their own behaviour (betmaker: audit, skin helpers,
 * TYPE_* constants) and point $keyModel at their key subclass. A subclass that overrides
 * booted() must call parent::booted(), or deleting a profile leaves its keys behind.
 */
class ServiceConfigurationProfile extends Model
{
    protected $table = 'service_configuration_profiles';

    protected $fillable = ['name', 'code', 'type', 'owner_type', 'owner_id', 'create_by', 'edit_by'];

    /** @var class-string<ServiceConfigurationProfileKey> overridden by subclasses with their own key model */
    protected static $keyModel = ServiceConfigurationProfileKey::class;

    /** @return class-string<ServiceConfigurationProfileKey> */
    public static function keyModel(): string
    {
        return static::$keyModel;
    }

    public function keys()
    {
        return $this->hasMany(static::keyModel(), 'scp_id');
    }

    public function owner()
    {
        return $this->morphTo();
    }

    public static function typeOptions(): array
    {
        return TypeRegistry::typeOptions();
    }

    public static function implementationNamespaceByType(): array
    {
        return TypeRegistry::implementationNamespaceByType();
    }

    public static function allowedImplementationsByType(): array
    {
        return TypeRegistry::allowedImplementationsByType();
    }

    public static function resolveImplementationClass(int $type, ?string $code): ?string
    {
        return TypeRegistry::resolveImplementationClass($type, $code);
    }

    public static function isAllowedImplementation(int $type, string $code): bool
    {
        return TypeRegistry::isAllowedImplementation($type, $code);
    }

    protected static function booted()
    {
        // Mass delete, so no per-key events. Trait boot methods run before booted(), so an
        // audit trait's own `deleting` hook (betmaker, PQPL-6313) has already snapshotted
        // the keys by the time this runs; keep it registered here, not in a trait.
        static::deleting(function (ServiceConfigurationProfile $scp) {
            $scp->keys()->delete();
        });
    }
}
