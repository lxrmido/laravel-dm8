<?php

namespace Lmo\LaravelDm8\Dm8\Schema;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Grammars\Grammar;
use Lmo\LaravelDm8\Dm8\Dm8ReservedWords;

class Comment extends Grammar
{
    use Dm8ReservedWords;

    /**
     * @var \Illuminate\Database\Connection
     */
    protected $connection;

    /**
     * @param  Connection  $connection
     */
    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * Set table and column comments.
     *
     * @param  DmBlueprint  $blueprint
     */
    public function setComments(DmBlueprint $blueprint)
    {
        $this->commentTable($blueprint);

        $this->fluentComments($blueprint);

        $this->commentColumns($blueprint);
    }

    /**
     * Run the comment on table statement.
     * Comment set by $table->comment = 'comment';.
     *
     * @param  DmBlueprint  $blueprint
     */
    private function commentTable(DmBlueprint $blueprint)
    {
        $table = $this->wrapValue($blueprint->getTable());

        if ($blueprint->comment != null) {
            $this->connection->statement("comment on table {$table} is '{$blueprint->comment}'");
        }
    }

    /**
     * Wrap reserved words.
     *
     * @param  string  $value
     * @return string
     */
    protected function wrapValue($value)
    {
        return $this->isReserved($value) ? parent::wrapValue($value) : $value;
    }

    /**
     * Add comments set via fluent setter.
     * Comments set by $table->string('column')->comment('comment');.
     *
     * @param  DmBlueprint  $blueprint
     */
    private function fluentComments(DmBlueprint $blueprint)
    {
        foreach ($blueprint->getColumns() as $column) {
            if (isset($column['comment'])) {
                $this->commentColumn($blueprint->getTable(), $column['name'], $column['comment']);
            }
        }
    }

    /**
     * Run the comment on column statement.
     *
     * @param  string  $table
     * @param  string  $column
     * @param  string  $comment
     */
    private function commentColumn($table, $column, $comment)
    {
        $table = $this->wrapValue($table);
        $table = $this->connection->getTablePrefix().$table;
        $column = $this->wrapValue($column);

        $this->connection->statement("comment on column {$table}.{$column} is '{$comment}'");
    }

    /**
     * Add comments on columns.
     * Comments set by $table->commentColumns = ['column' => 'comment'];.
     *
     * @param  DmBlueprint  $blueprint
     */
    private function commentColumns(DmBlueprint $blueprint)
    {
        foreach ($blueprint->commentColumns as $column => $comment) {
            $this->commentColumn($blueprint->getTable(), $column, $comment);
        }
    }
}
