<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Moved from betmaker under the same filename (PQPL-7443), without its data step: backfilling
 * the sportbook code was betmaker data and already ran there.
 */
return new class extends Migration
{
    public function up()
    {
        if (Schema::hasColumn('service_configuration_profiles', 'code')) {
            return;
        }

        Schema::table('service_configuration_profiles', function (Blueprint $table) {
            $table->string('code', 100)->after('name');
        });
    }

    public function down()
    {
        if (!Schema::hasColumn('service_configuration_profiles', 'code')) {
            return;
        }

        Schema::table('service_configuration_profiles', function (Blueprint $table) {
            $table->dropColumn('code');
        });
    }
};
