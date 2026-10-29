<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Moved from betmaker under the same filename, so a database that already ran it skips it;
 * the hasTable guard covers one that has the table under another migration name (PQPL-7443).
 * Anonymous, so it cannot clash with a class of the same name in the application.
 */
return new class extends Migration
{
    public function up()
    {
        if (Schema::hasTable('service_configuration_profiles')) {
            return;
        }

        Schema::create('service_configuration_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable(false);
            $table->smallInteger('type')->nullable(false)->comment('1: sport book');
            $table->integer('create_by')->unsigned();
            $table->integer('edit_by')->unsigned()->nullable(true)->default(null);

            $table->foreign('create_by')->references('id')->on('users');
            $table->foreign('edit_by')->references('id')->on('users');
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('service_configuration_profiles');
    }
};
