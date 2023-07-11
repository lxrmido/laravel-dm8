<?php

namespace Lmo\LaravelDm8\DBAL;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection as DoctrineConnection;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use SensitiveParameter;

class Dm8DoctrineConnection extends DoctrineConnection
{
    protected ?DmPlatform $platform = null;
    protected array $config;

    public function __construct(array $dbConfig, array $params, Driver $driver, ?Configuration $config = null, ?EventManager $eventManager = null)
    {
        parent::__construct($params, $driver, $config, $eventManager);
        $this->config = $dbConfig;
    }

    /**
     * Gets the DatabasePlatform for the connection.
     *
     * @return AbstractPlatform
     *
     * @throws Exception
     */
    public function getDatabasePlatform()
    {
        if ($this->platform === null) {
            $this->platform = new DmPlatform($this->config);
            $this->platform->setEventManager($this->_eventManager);
        }

        return $this->platform;
    }

    /**
     * Gets the name of the currently selected database.
     *
     * @return string|null The name of the database or NULL if a database is not selected.
     *                     The platforms which don't support the concept of a database (e.g. embedded databases)
     *                     must always return a string as an indicator of an implicitly selected database.
     *
     * @throws Exception
     */
//    public function getDatabase()
//    {
//        // todo
//    }
}
