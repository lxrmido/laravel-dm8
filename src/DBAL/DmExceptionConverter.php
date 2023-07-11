<?php

namespace Lmo\LaravelDm8\DBAL;

use Doctrine\DBAL\Driver\Exception;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Query;

class DmExceptionConverter implements \Doctrine\DBAL\Driver\API\ExceptionConverter
{
    public function convert(Exception $exception, ?Query $query): DriverException
    {
        return new DriverException($exception, $query);
    }
}
