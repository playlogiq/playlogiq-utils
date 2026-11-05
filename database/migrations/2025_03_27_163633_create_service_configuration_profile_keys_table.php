<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Moved from betmaker under the same filename, guarded like the profiles table (PQPL-7443).
 */
return new class extends Migration
{
    public function up()
    {
        if (Schema::hasTable('service_configuration_profile_keys')) {
            return;
        }

        Schema::create('service_configuration_profile_keys', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('scp_id')->unsigned();
            $table->string('key')->nullable(false);
            $table->string('value')->nullable(true);

            $table->foreign('scp_id')->references('id')->on('service_configuration_profiles');
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('service_configuration_profile_keys');
    }
};
