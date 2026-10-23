<?php

namespace Lmo\LaravelDm8\Connectors;

use Illuminate\Database\Connectors\Connector;
use Illuminate\Database\Connectors\ConnectorInterface;
use PDO;

class DmConnector extends Connector implements ConnectorInterface
{
    /**
     * The default PDO connection options.
     *
     * @var array
     */
    protected $options = [
        PDO::ATTR_CASE               => PDO::CASE_LOWER,
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_ORACLE_NULLS       => PDO::NULL_NATURAL,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
    ];

    /**
     * Establish a database connection.
     *
     * @param  array  $config
     * @return PDO
     */
    public function connect(array $config)
    {
        if (! empty($config['dynamic'])) {
            call_user_func_array($config['dynamic'], [&$config]);
        }
        $tns = ! empty($config['tns']) ? $config['tns'] : $this->getDsn($config);

        $options = $this->getOptions($config);

        $connection = $this->createConnection($tns, $config, $options);

        return $connection;
    }

    /**
     * Create a DSN string from a configuration.
     *
     * @param  array  $config
     * @return string
     */
    protected function getDsn(array $config)
    {
        if (! empty($config['tns'])) {
            return $config['tns'];
        }

        // parse configuration
        $config = $this->parseConfig($config);

        // return generated tns
        return $config['tns'];
    }

    /**
     * Parse configurations.
     *
     * @param  array  $config
     * @return array
     */
    protected function parseConfig(array $config)
    {
        $config = $this->setHost($config);
        $config = $this->setPort($config);
        $config = $this->setTNS($config);
        $config = $this->setCharset($config);

        return $config;
    }

    /**
     * Set host from config.
     *
     * @param  array  $config
     * @return array
     */
    protected function setHost(array $config)
    {
        $config['host'] = $config['host'] ?? $config['hostname'] ?? '127.0.0.1';

        return $config;
    }

    /**
     * Set port from config.
     *
     * @param  array  $config
     * @return array
     */
    private function setPort(array $config)
    {
        $config['port'] = $config['port'] ?? '5236';

        return $config;
    }

    /**
     * Set tns from config.
     *
     * @param  array  $config
     * @return array
     */
    protected function setTNS(array $config)
    {
        $config['tns'] = "dm:host={$config['host']};dbname={$config['database']};port={$config['port']};";

        return $config;
    }

    /**
     * Set charset from config.
     *
     * @param  array  $config
     * @return array
     */
    protected function setCharset(array $config)
    {
        if (! isset($config['charset'])) {
            $config['charset'] = 'UTF8';
        }

        return $config;
    }

    /**
     * Create a new PDO connection.
     *
     * @param  string  $tns
     * @param  array  $config
     * @param  array  $options
     * @return PDO
     */
    public function createConnection($tns, array $config, array $options)
    {
        // Keep the vendor-specific charset option, but do not pass our retry list to PDO.
        $options['charset'] = $options['charset'] ?? $config['charset'] ?? 'UTF8';
        unset($options['reconnect_errors']);

        return parent::createConnection($tns, $config, $options);
    }
}
