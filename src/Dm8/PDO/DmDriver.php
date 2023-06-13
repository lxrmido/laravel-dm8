<?php

namespace Lmo\LaravelDm8\Dm8\PDO;

// use Doctrine\DBAL\Driver\AbstractOracleDriver;
use Doctrine\DBAL\Driver\PDOOracle\Driver as PDOOracleDriver;
// use Illuminate\Database\PDO\Concerns\ConnectsToDatabase;

class DmDriver extends PDOOracleDriver
{
    public function getName()
    {
        return 'dm';
    }

    public function getDatabase(\Doctrine\DBAL\Connection $conn)
    {
        $params = $conn->getParams();
        return $params['dbname'];
    }
}
