<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * A key's value becomes TEXT (PQPL-7470): an encrypted secret is ~4/3 of its length plus ~35
 * chars, so a varchar(191/255) cannot hold long tokens or PEM keys, and non-strict MySQL cuts
 * them silently. Raw SQL because ->change() needs doctrine/dbal, which betmaker does not have.
 * MySQL only; guarded like the other SCP migrations, so a database already on TEXT skips it.
 */
return new class extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('service_configuration_profile_keys')) {
            return;
        }

        $connection = Schema::getConnection();
        if ($connection->getDriverName() !== 'mysql') {
            return;
        }

        $table = $connection->getTablePrefix() . 'service_configuration_profile_keys';
        $type = $connection->selectOne(
            'SELECT DATA_TYPE AS type FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, 'value']
        );
        if ($type === null || in_array(strtolower($type->type), ['text', 'mediumtext', 'longtext'], true)) {
            return;
        }

        $connection->statement("ALTER TABLE `{$table}` MODIFY `value` TEXT NULL");
    }

    public function down()
    {
        // Deliberately a no-op: narrowing back to varchar would silently truncate longer values.
    }
};
