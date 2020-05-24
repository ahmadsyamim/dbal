<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Tools\Console;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Tools\Console\Command\ReservedWordsCommand;
use Doctrine\DBAL\Tools\Console\Command\RunSqlCommand;
use Doctrine\DBAL\Tools\Console\Helper\ConnectionHelper;
use PackageVersions\Versions;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\HelperSet;
use TypeError;
use function sprintf;
use function trigger_error;
use const E_USER_DEPRECATED;

/**
 * Handles running the Console Tools inside Symfony Console context.
 */
class ConsoleRunner
{
    /**
     * Create a Symfony Console HelperSet
     *
     * @deprecated use a ConnectionProvider instead.
     */
    public static function createHelperSet(Connection $connection) : HelperSet
    {
        return new HelperSet([
            'db' => new ConnectionHelper($connection),
        ]);
    }

    /**
     * Runs console with the given connection provider or helperset (deprecated).
     *
     * @param ConnectionProvider|HelperSet $helperSetOrConnectionProvider
     * @param array<int, Command>          $commands
     */
    public static function run($helperSetOrConnectionProvider, $commands = []) : void
    {
        $cli = new Application('Doctrine Command Line Interface', Versions::getVersion('doctrine/dbal'));

        $cli->setCatchExceptions(true);

        $connectionProvider = null;
        if ($helperSetOrConnectionProvider instanceof HelperSet) {
            @trigger_error(sprintf('Passing an instance of "%s" as the first argument is deprecated. Pass an instance of "%s" instead.', HelperSet::class, ConnectionProvider::class), E_USER_DEPRECATED);
            $connectionProvider = null;
            $cli->setHelperSet($helperSetOrConnectionProvider);
        } elseif ($helperSetOrConnectionProvider instanceof ConnectionProvider) {
            $connectionProvider = $helperSetOrConnectionProvider;
        } else {
            throw new TypeError(sprintf('First argument must be an instance of "%s" or "%s"', HelperSet::class, ConnectionProvider::class));
        }

        self::addCommands($cli, $connectionProvider);

        $cli->addCommands($commands);
        $cli->run();
    }

    public static function addCommands(Application $cli, ?ConnectionProvider $connectionProvider = null) : void
    {
        $cli->addCommands([
            new RunSqlCommand(),
            new ReservedWordsCommand(),
            new ReservedWordsCommand($connectionProvider),
        ]);
    }

    /**
     * Prints the instructions to create a configuration file
     */
    public static function printCliConfigTemplate() : void
    {
        echo <<<'HELP'
You are missing a "cli-config.php" or "config/cli-config.php" file in your
project, which is required to get the Doctrine-DBAL Console working. You can use the
following sample as a template:

<?php
use Doctrine\DBAL\Tools\Console\ConnectionProvider\SingleConnectionProvider;

// You can append new commands to $commands array, if needed

// replace with the mechanism to retrieve DBAL connection(s) in your app
// and return a Doctrine\DBAL\Tools\Console\ConnectionProvider instance.
$connection = getDBALConnection();

// in case you have a single connection you can use SingleConnectionProvider
// otherwise you need to implement the Doctrine\DBAL\Tools\Console\ConnectionProvider interface with your custom logic
return new SingleConnectionProvider($connection);

HELP;
    }
}
