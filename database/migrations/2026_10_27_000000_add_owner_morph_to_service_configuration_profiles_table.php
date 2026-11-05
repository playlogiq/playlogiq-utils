<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The profile's owner, polymorphic so each application scopes profiles to its own entity
 * (betmaker 'skin', backoffice 'brand') through its morph map (PQPL-7443).
 */
return new class extends Migration
{
    public function up()
    {
        if (Schema::hasColumn('service_configuration_profiles', 'owner_type')) {
            return;
        }

        Schema::table('service_configuration_profiles', function (Blueprint $table) {
            $table->nullableMorphs('owner');
        });
    }

    public function down()
    {
        if (!Schema::hasColumn('service_configuration_profiles', 'owner_type')) {
            return;
        }

        Schema::table('service_configuration_profiles', function (Blueprint $table) {
            $table->dropMorphs('owner');
        });
    }
};
