<?php

namespace Lmo\LaravelDm8\Schema;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Builder;
use Lmo\LaravelDm8\Schema\DmBlueprint;

class DmBuilder extends Builder
{
    /**
     * @var OracleAutoIncrementHelper
     */
    public $helper;

    /**
     * @var Comment
     */
    public $comment;

    /**
     * @param  Connection  $connection
     */
    public function __construct(Connection $connection)
    {
        parent::__construct($connection);
        $this->helper = new DmAutoIncrementHelper($connection);
        $this->comment = new Comment($connection);
    }

    /**
     * Create a new table on the schema.
     *
     * @param  string  $table
     * @param  Closure  $callback
     * @return \Illuminate\Database\Schema\Blueprint
     */
    public function create($table, Closure $callback)
    {
        $blueprint = $this->createBlueprint($table);

        $blueprint->create();

        $callback($blueprint);

        $this->build($blueprint);

        $this->comment->setComments($blueprint);

        $this->helper->createAutoIncrementObjects($blueprint, $table);
    }

    /**
     * Create a new command set with a Closure.
     *
     * @param  string  $table
     * @param  Closure  $callback
     * @return \Illuminate\Database\Schema\Blueprint
     */
    protected function createBlueprint($table, ?Closure $callback = null)
    {
        if (isset($this->resolver)) {
            return ($this->resolver)($this->connection, $table, $callback);
        }

        $blueprint = new DmBlueprint($this->connection, $table);
        $blueprint->setTablePrefix($this->connection->getTablePrefix());
        if ($callback !== null) {
            $callback($blueprint);
        }

        return $blueprint;
    }

    /**
     * Changes an existing table on the schema.
     *
     * @param  string  $table
     * @param  Closure  $callback
     * @return \Illuminate\Database\Schema\Blueprint
     */
    public function table($table, Closure $callback)
    {
        $blueprint = $this->createBlueprint($table);

        $callback($blueprint);

        foreach ($blueprint->getCommands() as $command) {
            if ($command->get('name') == 'drop') {
                $this->helper->dropAutoIncrementObjects($table);
            }
        }

        $this->build($blueprint);

        $this->comment->setComments($blueprint);
    }

    /**
     * Drop a table from the schema.
     *
     * @param  string  $table
     * @return \Illuminate\Database\Schema\Blueprint
     */
    public function drop($table)
    {
        $this->helper->dropAutoIncrementObjects($table);
        parent::drop($table);
    }

    /**
     * Drop all tables from the database.
     *
     * @return void
     */
    public function dropAllTables()
    {
        $this->connection->statement($this->grammar->compileDropAllTables());
    }

    /**
     * Indicate that the table should be dropped if it exists.
     *
     * @param  string  $table
     * @return \Illuminate\Support\Fluent
     */
    public function dropIfExists($table)
    {
        $this->helper->dropAutoIncrementObjects($table);
        parent::dropIfExists($table);
    }

    /**
     * Determine if the given table exists.
     *
     * @param  string  $table
     * @return bool
     */
    public function hasTable($table)
    {
        [$database, $table] = $this->parseSchemaAndTable($table, true);
        $table = $this->connection->getTablePrefix().$table;

        return count($this->connection->selectFromWriteConnection(
            $this->grammar->compileTableExists($database, $table)
        )) > 0;
    }

    /**
     * Get the column listing for a given table.
     *
     * @param  string  $table
     * @return array
     */
    public function getColumnListing($table)
    {
        return parent::getColumnListing($table);
    }

    public function getCurrentSchemaListing()
    {
        return [$this->connection->getConfig('prefix_schema') ?: $this->connection->getConfig('username')];
    }

    public function parseSchemaAndTable($reference, $withDefaultSchema = null)
    {
        return parent::parseSchemaAndTable($reference, $withDefaultSchema ?? true);
    }
}
