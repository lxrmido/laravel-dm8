<?php

namespace Lmo\LaravelDm8\Query;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;

class DmBuilder extends Builder
{
    public function whereIn($column, $values, $boolean = 'and', $not = false)
    {
        if ($values instanceof Arrayable) {
            $values = $values->toArray();
        }

        if (is_array($values) && count($values) > 1000) {
            return $this->where(function ($query) use ($column, $values, $not) {
                foreach (array_chunk($values, 1000) as $chunk) {
                    $query->whereIn($column, $chunk, $not ? 'and' : 'or', $not);
                }
            }, null, null, $boolean);
        }

        return parent::whereIn($column, $values, $boolean, $not);
    }

    // Transaction ownership stays with the caller, including SELECT FOR UPDATE.

    public function fromSub($query, $as)
    {
        [$query, $bindings] = $this->createSub($query);

        return $this->fromRaw('('.$query.') '.$this->wrapAlias($as), $bindings);
    }

    public function joinSub($query, $as, $first, $operator = null, $second = null, $type = 'inner', $where = false)
    {
        [$query, $bindings] = $this->createSub($query);
        $this->addBinding($bindings, 'join');

        return $this->join(new Expression('('.$query.') '.$this->wrapAlias($as)), $first, $operator, $second, $type, $where);
    }

    public function crossJoinSub($query, $as)
    {
        [$query, $bindings] = $this->createSub($query);
        $this->addBinding($bindings, 'join');
        $this->joins[] = $this->newJoinClause($this, 'cross', new Expression('('.$query.') '.$this->wrapAlias($as)));

        return $this;
    }

    private function wrapAlias($as)
    {
        return $this->grammar->wrap($this->connection->getTablePrefix().$as);
    }

    public function insertLob(array $values, array $binaries, $sequence = 'id')
    {
        $sql = $this->grammar->compileInsertLob($this, $values, $binaries, $sequence);

        return $this->processor->saveLob($this, $sql, $this->cleanBindings($values), array_values($binaries));
    }

    public function updateLob(array $values, array $binaries, $sequence = 'id')
    {
        $sql = $this->grammar->compileUpdateLob($this, $values, $binaries, $sequence);
        return $this->processor->saveLob($this, $sql, $this->cleanBindings($values), array_values($binaries), $this->cleanBindings($this->getBindings()));
    }
}
