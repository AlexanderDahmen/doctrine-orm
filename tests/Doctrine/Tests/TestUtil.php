<?php

declare(strict_types=1);

namespace Doctrine\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use UnexpectedValueException;

use function explode;
use function fwrite;
use function get_class;
use function getenv;
use function sprintf;
use function strlen;
use function strpos;
use function substr;
use function unlink;

use const STDERR;

/**
 * TestUtil is a class with static utility methods used during tests.
 */
class TestUtil
{
    /** @var bool Whether the database schema is initialized. */
    private static $initialized = false;

    /**
     * Gets a <b>real</b> database connection using the following parameters
     * of the $GLOBALS array:
     *
     * 'db_type' : The name of the Doctrine DBAL database driver to use.
     * 'db_username' : The username to use for connecting.
     * 'db_password' : The password to use for connecting.
     * 'db_host' : The hostname of the database to connect to.
     * 'db_server' : The server name of the database to connect to
     *               (optional, some vendors allow multiple server instances with different names on the same host).
     * 'db_name' : The name of the database to connect to.
     * 'db_port' : The port of the database to connect to.
     *
     * Usually these variables of the $GLOBALS array are filled by PHPUnit based
     * on an XML configuration file. If no such parameters exist, an SQLite
     * in-memory database is used.
     *
     * IMPORTANT:
     * 1) Each invocation of this method returns a NEW database connection.
     * 2) The database is dropped and recreated to ensure it's clean.
     *
     * @return Connection The database connection instance.
     */
    public static function getConnection(): Connection
    {
        $params = self::getConnectionParams();
        $conn   = DriverManager::getConnection($params);
        // Note, writes direct to STDERR to prevent phpunit detecting output - otherwise this would cause either an
        // "unexpected output" warning or a failure on the first test case to call this method.
        fwrite(
            STDERR,
            sprintf(
                "\nUsing DB driver %s (from %s connection params)\n",
                get_class($conn->getDriver()),
                $params['is_fallback'] ? 'fallback' : 'specified'
            )
        );

        $expectDriver = getenv('EXPECT_DB_DRIVER');
        if ($expectDriver && ($expectDriver !== $params['driver'])) {
            // Compare the resolved driver type manually rather than using a phpunit assert method, so that we don't
            // affect phpunit's count of assertions run during the calling test.
            throw new UnexpectedValueException(
                sprintf(
                    "Invalid test environment config\n - EXPECT_DB_DRIVER = `%s`\n - Actual driver    = `%s`",
                    $expectDriver,
                    $params['driver']
                )
            );
        }

        self::addDbEventSubscribers($conn);

        return $conn;
    }

    public static function getTempConnection(): Connection
    {
        return DriverManager::getConnection(self::getParamsForTemporaryConnection());
    }

    /**
     * @psalm-return array<string, mixed>
     */
    private static function getConnectionParams()
    {
        if (self::hasRequiredConnectionParams()) {
            return self::getSpecifiedConnectionParams();
        }

        return self::getFallbackConnectionParams();
    }

    private static function hasRequiredConnectionParams(): bool
    {
        return isset($GLOBALS['db_driver']);
    }

    /**
     * @psalm-return array<string, mixed>
     */
    private static function getSpecifiedConnectionParams()
    {
        $realDbParams                = self::getParamsForMainConnection();
        $realDbParams['is_fallback'] = false;

        if (! self::$initialized) {
            $tmpDbParams = self::getParamsForTemporaryConnection();

            $realConn = DriverManager::getConnection($realDbParams);

            // Connect to tmpdb in order to drop and create the real test db.
            $tmpConn = DriverManager::getConnection($tmpDbParams);

            $platform = $tmpConn->getDatabasePlatform();

            if ($platform->supportsCreateDropDatabase()) {
                $dbname = $realConn->getDatabase();
                $realConn->close();

                $tmpConn->getSchemaManager()->dropAndCreateDatabase($dbname);

                $tmpConn->close();
            } else {
                $sm = $realConn->getSchemaManager();

                $schema = $sm->createSchema();
                $stmts  = $schema->toDropSql($realConn->getDatabasePlatform());

                foreach ($stmts as $stmt) {
                    $realConn->exec($stmt);
                }
            }

            self::$initialized = true;
        }

        return $realDbParams;
    }

    /**
     * @psalm-return array<string, mixed>
     */
    private static function getFallbackConnectionParams()
    {
        $params = [
            'driver' => 'pdo_sqlite',
            'memory' => true,
            'is_fallback' => true,
        ];

        if (isset($GLOBALS['db_path'])) {
            $params['path'] = $GLOBALS['db_path'];
            unlink($GLOBALS['db_path']);
        }

        return $params;
    }

    private static function addDbEventSubscribers(Connection $conn): void
    {
        if (isset($GLOBALS['db_event_subscribers'])) {
            $evm = $conn->getEventManager();
            foreach (explode(',', $GLOBALS['db_event_subscribers']) as $subscriberClass) {
                $subscriberInstance = new $subscriberClass();
                $evm->addEventSubscriber($subscriberInstance);
            }
        }
    }

    /**
     * @psalm-return array<string, mixed>
     */
    private static function getParamsForTemporaryConnection()
    {
        if (isset($GLOBALS['tmpdb_driver'])) {
            return self::mapConnectionParameters($GLOBALS, 'tmpdb_');
        }

        $parameters = self::mapConnectionParameters($GLOBALS, 'db_');
        unset($parameters['dbname']);

        return $parameters;
    }

    /**
     * @psalm-return array<string, mixed>
     */
    private static function getParamsForMainConnection()
    {
        return self::mapConnectionParameters($GLOBALS, 'db_');
    }

    /**
     * @param array<string,mixed> $configuration
     *
     * @return array<string,mixed>
     */
    private static function mapConnectionParameters(array $configuration, string $prefix): array
    {
        $parameters = [];

        foreach (
            [
                'driver',
                'user',
                'password',
                'host',
                'dbname',
                'port',
                'server',
                'ssl_key',
                'ssl_cert',
                'ssl_ca',
                'ssl_capath',
                'ssl_cipher',
                'unix_socket',
            ] as $parameter
        ) {
            if (! isset($configuration[$prefix . $parameter])) {
                continue;
            }

            $parameters[$parameter] = $configuration[$prefix . $parameter];
        }

        foreach ($configuration as $param => $value) {
            if (strpos($param, $prefix . 'driver_option_') !== 0) {
                continue;
            }

            $parameters['driverOptions'][substr($param, strlen($prefix . 'driver_option_'))] = $value;
        }

        return $parameters;
    }
}
