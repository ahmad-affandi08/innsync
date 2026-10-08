<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Persistence;

use Illuminate\Database\Connectors\MySqlConnector;
use PDO;
use PDOException;

/** Connects with the configured collation, and falls back to one every MariaDB knows when the server does not know it (a MariaDB host with the MySQL 8 default). */
final class CompatibleMySqlConnector extends MySqlConnector
{
    protected function configureConnection(PDO $connection, array $config)
    {
        try {
            parent::configureConnection($connection, $config);
        } catch (PDOException $e) {
            if (! str_contains($e->getMessage(), 'Unknown collation')) {
                throw $e;
            }

            parent::configureConnection($connection, [...$config, 'collation' => TableCollation::FALLBACK]);
        }
    }
}
