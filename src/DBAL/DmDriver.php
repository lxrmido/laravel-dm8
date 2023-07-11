<?php

namespace Lmo\LaravelDm8\DBAL;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\API\ExceptionConverter;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Lmo\LaravelDm8\DBAL\DmPlatformOld;
use Lmo\LaravelDm8\DBAL\DmSchemaManager;

class DmDriver extends \Doctrine\DBAL\Driver\AbstractMySQLDriver
{
    private array $config;
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function connect(array $params)
    {
        /** @var \PDO $pdo */
        $pdo = $params["pdo"];
        return new \Doctrine\DBAL\Driver\PDO\Connection($pdo);
    }

    public function getDatabasePlatform()
    {
        return new DmPlatform($this->config);
    }

    public function getSchemaManager(Connection $conn, AbstractPlatform $platform)
    {
        $sm = new DmSchemaManager($conn, $this->getDatabasePlatform());
        $sm->setConfig($this->config);
        return $sm;
    }

    public function getExceptionConverter(): ExceptionConverter
    {
        return new DmExceptionConverter();
    }
}
