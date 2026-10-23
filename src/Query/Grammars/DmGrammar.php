<?php

namespace Lmo\LaravelDm8\Query\Grammars;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\Grammar;

class DmGrammar extends Grammar
{
    protected $schema_prefix = '';

    public function setSchemaPrefix($prefix)
    {
        $this->schema_prefix = $prefix;
    }

    public function getSchemaPrefix()
    {
        return $this->schema_prefix !== '' ? $this->wrapValue($this->schema_prefix).'.' : '';
    }

    public function wrapTable($table, $prefix = null)
    {
        if ($this->isExpression($table)) {
            return $this->getValue($table);
        }

        // Keep the legacy "table alias" form while accepting Laravel's "table as alias".
        if (preg_match('/^(.+?)\s+(?:as\s+)?(\S+)$/i', $table, $parts)) {
            return $this->wrapTable($parts[1], $prefix).' '.$this->wrapValue(($prefix ?? $this->connection->getTablePrefix()).$parts[2]);
        }

        return (str_contains($table, '.') ? '' : $this->getSchemaPrefix()).parent::wrapTable($table, $prefix);
    }

    protected function wrapSegments($segments)
    {
        if (count($segments) === 2) {
            // A column qualifier may be a table alias: never attach a schema to it.
            return parent::wrapTable($segments[0]).'.'.$this->wrapValue($segments[1]);
        }

        return implode('.', array_map($this->wrapValue(...), $segments));
    }

    public function compileExists(Builder $query)
    {
        return 'select case when exists ('.$this->compileSelect($query).') then 1 else 0 end as "exists" from dual';
    }

    protected function compileDeleteWithJoins(Builder $query, $table, $where)
    {
        $segments = preg_split('/\s+(?:as\s+)?/i', $query->from);
        $qualifier = count($segments) > 1 ? end($segments) : last(explode('.', $query->from));
        $rowid = parent::wrapTable($qualifier).'.rowid';
        $joins = $this->compileJoins($query, $query->joins);

        return 'delete from '.$table.' where '.$rowid.' in (select '.$rowid.' from '.$table.' '.$joins.' '.$where.')';
    }

    public function compileInsertGetId(Builder $query, $values, $sequence = null)
    {
        return $this->compileInsert($query, $values).' returning '.$this->wrap($sequence ?: 'id').' into ?';
    }

    public function compileInsert(Builder $query, array $values)
    {
        if ($values === []) {
            return 'insert into '.$this->wrapTable($query->from).' default values';
        }

        if (! is_array(reset($values))) {
            $values = [$values];
        }

        if (count($values) === 1) {
            return parent::compileInsert($query, $values);
        }

        $rows = array_map(fn ($row) => 'select '.$this->parameterize($row).' from dual', $values);

        return 'insert into '.$this->wrapTable($query->from).' ('.$this->columnize(array_keys(reset($values))).') '.implode(' union all ', $rows);
    }

    public function compileInsertLob(Builder $query, $values, $binaries, $sequence = 'id')
    {
        return $this->compileInsertGetId($query, array_merge($values, $binaries), $sequence);
    }

    public function compileUpdateLob(Builder $query, array $values, array $binaries, $sequence = 'id')
    {
        if ($query->joins) {
            throw new \RuntimeException('LOB updates with joins are not supported.');
        }

        $columns = [];
        foreach ($values as $key => $value) {
            $columns[] = $this->wrap($key).' = '.$this->parameter($value);
        }
        foreach ($binaries as $key => $value) {
            $columns[] = $this->wrap($key).' = ?';
        }

        return 'update '.$this->wrapTable($query->from).' set '.implode(', ', $columns).' '.$this->compileWheres($query).' returning '.$this->wrap($sequence ?: 'id').' into ?';
    }

    protected function compileLock(Builder $query, $value)
    {
        return is_string($value) ? $value : ($value ? 'for update' : '');
    }

    protected function whereDate(Builder $query, $where)
    {
        return 'trunc('.$this->wrap($where['column']).') '.$where['operator'].' '.$this->parameter($where['value']);
    }

    protected function dateBasedWhere($type, Builder $query, $where)
    {
        return 'extract ('.$type.' from '.$this->wrap($where['column']).') '.$where['operator'].' '.$this->parameter($where['value']);
    }

    protected function whereInRaw(Builder $query, $where)
    {
        return $this->compileIntegerIn($where, false);
    }

    protected function whereNotInRaw(Builder $query, $where)
    {
        return $this->compileIntegerIn($where, true);
    }

    private function compileIntegerIn(array $where, bool $not): string
    {
        if ($where['values'] === []) {
            return $not ? '1 = 1' : '0 = 1';
        }

        $clauses = array_map(
            fn ($chunk) => $this->wrap($where['column']).($not ? ' not in (' : ' in (').implode(', ', $chunk).')',
            array_chunk($where['values'], 1000)
        );

        return count($clauses) === 1 ? $clauses[0] : '('.implode($not ? ' and ' : ' or ', $clauses).')';
    }
}
