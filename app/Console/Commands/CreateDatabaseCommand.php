<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CreateDatabaseCommand extends Command
{
    protected $signature = 'make:database {name}';

    protected $description = 'Creates my_idlers database';

    public function handle()
    {
        $schemaName = $this->argument('name') ?: config("database.connections.mysql.database");
        $charset = config("database.connections.mysql.charset", 'utf8mb4');
        $collation = config("database.connections.mysql.collation", 'utf8mb4_unicode_ci');

        // Identifiers can't be parameter-bound, so allowlist them instead: a
        // hostile argument or DB_CHARSET/DB_COLLATION env value would
        // otherwise be interpolated straight into the DDL statement.
        foreach (['name' => $schemaName, 'charset' => $charset, 'collation' => $collation] as $what => $value) {
            if (!is_string($value) || !preg_match('/^\w+$/', $value)) {
                $this->error("Invalid database $what: only letters, numbers and underscores are allowed.");

                return 1;
            }
        }

        config(["database.connections.mysql.database" => null]);

        DB::statement("CREATE DATABASE IF NOT EXISTS `$schemaName` CHARACTER SET `$charset` COLLATE `$collation`;");

        config(["database.connections.mysql.database" => $schemaName]);

        return 0;
    }
}
