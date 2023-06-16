<?php

namespace Lmo\LaravelDm8\DBAL;
use Lmo\LaravelDm8\DBAL\DmPlatform;
use Lmo\LaravelDm8\DBAL\DmSchemaManager;

class DmDriver implements \Doctrine\DBAL\Driver
{

    public function connect(array $params, $username = null, $password = null, array $driverOptions = array())
    {
        return new \Doctrine\DBAL\Driver\PDOConnection(
            $this->_constructPdoDsn($params),
            $username,
            $password,
            $driverOptions
        );
    }

    /**
     * Constructs the Oracle PDO DSN.
     *
     * @return string  The DSN.
     */
    private function _constructPdoDsn(array $params)
    {
        $dsn = 'oci:';
        if (isset($params['host'])) {
            $dsn .= 'dbname=(DESCRIPTION=(ADDRESS_LIST=(ADDRESS=(PROTOCOL=TCP)' .
            '(HOST=' . $params['host'] . ')';

            if (isset($params['port'])) {
                $dsn .= '(PORT=' . $params['port'] . ')';
            } else {
                $dsn .= '(PORT=1521)';
            }

            if (isset($params['service']) && $params['service'] == true) {
                $dsn .= '))(CONNECT_DATA=(SERVICE_NAME=' . $params['dbname'] . ')))';
            } else {
                $dsn .= '))(CONNECT_DATA=(SID=' . $params['dbname'] . ')))';
            }
        } else {
            $dsn .= 'dbname=' . $params['dbname'];
        }

        if (isset($params['charset'])) {
            $dsn .= ';charset=' . $params['charset'];
        }

        return $dsn;
    }

    public function getDatabasePlatform()
    {
        return new DmPlatform();
    }

    public function getSchemaManager(\Doctrine\DBAL\Connection $conn)
    {
        return new DmSchemaManager($conn);
    }

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
