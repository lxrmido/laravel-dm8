<?php

namespace Lmo\LaravelDm8\Query\Processors;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Processors\Processor;
use PDO;

class DmProcessor extends Processor
{
    public function processInsertGetId(Builder $query, $sql, $values, $sequence = null)
    {
        return $this->executeReturning($query, $sql, $values, []);
    }

    public function saveLob(Builder $query, $sql, array $values, array $binaries, array $whereBindings = [])
    {
        return $this->executeReturning($query, $sql, $values, $binaries, $whereBindings);
    }

    private function executeReturning(Builder $query, string $sql, array $values, array $binaries, array $whereBindings = []): int
    {
        $connection = $query->getConnection();
        $binaryOffset = count($values);
        $bindings = array_merge($values, $binaries, $whereBindings);

        return $connection->runReturning($sql, $bindings, function ($connection, $sql, $values) use ($binaries, $binaryOffset) {
            $statement = $connection->getPdo()->prepare($sql);
            $connection->bindValues($statement, $values);
            $parameter = count($values) + 1;
            foreach ($binaries as $offset => $binary) {
                $statement->bindValue($binaryOffset + $offset + 1, $binary, PDO::PARAM_LOB);
            }

            $id = 0;
            $statement->bindParam($parameter, $id, PDO::PARAM_INT | PDO::PARAM_INPUT_OUTPUT, 20);
            $statement->execute();
            $connection->recordsHaveBeenModified();

            return (int) $id;
        });
    }

    public function processColumns($results)
    {
        return array_map(static function ($result) {
            $column = array_change_key_case((array) $result);
            $type = strtolower($column['data_type']);
            $definition = $type;
            if (in_array($type, ['varchar', 'varchar2', 'nvarchar', 'nvarchar2', 'char'])) {
                $definition .= '('.$column['char_length'].')';
            } elseif ($column['data_precision'] !== null) {
                $definition .= '('.$column['data_precision'].', '.($column['data_scale'] ?? 0).')';
            }

            return [
                'name' => $column['column_name'],
                'type_name' => $type,
                'type' => $definition,
                'collation' => null,
                'nullable' => $column['nullable'] === 'Y',
                'default' => $column['data_default'],
                'auto_increment' => (bool) ($column['auto_increment'] ?? false),
                'comment' => $column['comments'] ?? null,
                'generation' => null,
            ];
        }, $results);
    }

    public function processIndexes($results)
    {
        $indexes = [];
        foreach ($results as $result) {
            $row = array_change_key_case((array) $result);
            $key = $row['index_name'];
            $indexes[$key] ??= [
                'name' => $key, 'columns' => [], 'type' => strtolower($row['index_type']),
                'unique' => $row['uniqueness'] === 'UNIQUE', 'primary' => $row['is_primary'] === 'Y',
            ];
            $indexes[$key]['columns'][] = $row['column_name'];
        }

        return array_values($indexes);
    }

    public function processForeignKeys($results)
    {
        $keys = [];
        foreach ($results as $result) {
            $row = array_change_key_case((array) $result);
            $key = $row['constraint_name'];
            $keys[$key] ??= [
                'name' => $key, 'columns' => [], 'foreign_schema' => $row['foreign_schema'],
                'foreign_table' => $row['foreign_table'], 'foreign_columns' => [],
                'on_update' => 'no action', 'on_delete' => strtolower($row['delete_rule']),
            ];
            $keys[$key]['columns'][] = $row['column_name'];
            $keys[$key]['foreign_columns'][] = $row['foreign_column'];
        }

        return array_values($keys);
    }
}
