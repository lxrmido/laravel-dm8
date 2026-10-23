<?php

namespace Lmo\LaravelDm8\Schema\Grammars;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Grammars\Grammar;
use Illuminate\Support\Fluent;

class DmGrammar extends Grammar
{
    protected $modifiers = ['Default', 'Nullable', 'Increment'];
    protected $serials = ['bigInteger', 'integer', 'mediumInteger', 'smallInteger', 'tinyInteger'];
    protected $schema_prefix = '';
    protected $transactions = false;

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
        $table = $table instanceof Blueprint ? $table->getTable() : $table;
        if ($this->isExpression($table)) {
            return $this->getValue($table);
        }

        return (str_contains($table, '.') ? '' : $this->getSchemaPrefix()).parent::wrapTable($table, $prefix);
    }

    protected function literal($value)
    {
        return "'".str_replace("'", "''", (string) $value)."'";
    }

    protected function owner($schema)
    {
        return $this->literal($schema ?? ($this->connection->getConfig('prefix_schema') ?: $this->connection->getConfig('username')));
    }

    protected function schemaFilter($schema, $column = 'owner')
    {
        $schemas = $schema === null
            ? [$this->connection->getConfig('prefix_schema') ?: $this->connection->getConfig('username')]
            : (array) $schema;

        return $column.' in ('.implode(', ', array_map($this->literal(...), $schemas)).')';
    }

    public function compileSchemas()
    {
        return 'select username as "name", case when username = user then 1 else 0 end as "default" from all_users order by username';
    }

    public function compileTableExists($schema, $table)
    {
        return 'select 1 from all_tables where owner = '.$this->owner($schema).' and table_name = '.$this->literal($table);
    }

    public function compileTables($schema)
    {
        return 'select t.table_name as "name", t.owner as "schema", c.comments as "comment" from all_tables t left join all_tab_comments c on c.owner = t.owner and c.table_name = t.table_name where '.$this->schemaFilter($schema, 't.owner').' order by t.owner, t.table_name';
    }

    public function compileViews($schema)
    {
        return 'select view_name as "name", owner as "schema", text as "definition" from all_views where '.$this->schemaFilter($schema).' order by owner, view_name';
    }

    public function compileColumns($schema, $table)
    {
        return 'select c.column_name, c.data_type, c.char_length, c.data_precision, c.data_scale, c.nullable, c.data_default, m.comments, '
            .'case when exists (select 1 from all_sequences s where s.sequence_owner = c.owner and s.sequence_name = substr(c.table_name || \'_\' || c.column_name || \'_seq\', 1, 128)) then 1 else 0 end as "auto_increment" '
            .'from all_tab_columns c left join all_col_comments m on m.owner = c.owner and m.table_name = c.table_name and m.column_name = c.column_name '
            .'where c.owner = '.$this->owner($schema).' and c.table_name = '.$this->literal($table).' order by c.column_id';
    }

    public function compileIndexes($schema, $table)
    {
        return 'select i.index_name, i.index_type, i.uniqueness, c.column_name, '
            .'case when exists (select 1 from all_constraints p where p.owner = i.owner and p.index_name = i.index_name and p.constraint_type = \'P\') then \'Y\' else \'N\' end as "is_primary" '
            .'from all_indexes i join all_ind_columns c on c.index_owner = i.owner and c.index_name = i.index_name '
            .'where i.table_owner = '.$this->owner($schema).' and i.table_name = '.$this->literal($table).' order by i.index_name, c.column_position';
    }

    public function compileForeignKeys($schema, $table)
    {
        return 'select f.constraint_name, c.column_name, r.owner as "foreign_schema", r.table_name as "foreign_table", rc.column_name as "foreign_column", f.delete_rule '
            .'from all_constraints f join all_cons_columns c on c.owner = f.owner and c.constraint_name = f.constraint_name '
            .'join all_constraints r on r.owner = f.r_owner and r.constraint_name = f.r_constraint_name '
            .'join all_cons_columns rc on rc.owner = r.owner and rc.constraint_name = r.constraint_name and rc.position = c.position '
            .'where f.constraint_type = \'R\' and f.owner = '.$this->owner($schema).' and f.table_name = '.$this->literal($table).' order by f.constraint_name, c.position';
    }

    public function compileCreate(Blueprint $blueprint, Fluent $command)
    {
        return 'create table '.$this->wrapTable($blueprint).' ('.implode(', ', $this->getColumns($blueprint)).')';
    }

    public function compileAdd(Blueprint $blueprint, Fluent $command)
    {
        return 'alter table '.$this->wrapTable($blueprint).' add ('.$this->getColumn($blueprint, $command->column).')';
    }

    public function compileChange(Blueprint $blueprint, Fluent $command)
    {
        return 'alter table '.$this->wrapTable($blueprint).' modify ('.$this->getColumn($blueprint, $command->column).')';
    }

    public function compilePrimary(Blueprint $blueprint, Fluent $command)
    {
        return 'alter table '.$this->wrapTable($blueprint).' add constraint '.$this->wrap($command->index).' primary key ('.$this->columnize($command->columns).')';
    }

    public function compileUnique(Blueprint $blueprint, Fluent $command)
    {
        return 'alter table '.$this->wrapTable($blueprint).' add constraint '.$this->wrap($command->index).' unique ('.$this->columnize($command->columns).')';
    }

    public function compileIndex(Blueprint $blueprint, Fluent $command)
    {
        return 'create index '.$this->wrap($command->index).' on '.$this->wrapTable($blueprint).' ('.$this->columnize($command->columns).')';
    }

    public function compileDrop(Blueprint $blueprint, Fluent $command)
    {
        return 'drop table '.$this->wrapTable($blueprint);
    }

    public function compileDropIfExists(Blueprint $blueprint, Fluent $command)
    {
        // Dameng 8 supports IF EXISTS; no quoted identifier inside a string comparison.
        return 'drop table if exists '.$this->wrapTable($blueprint);
    }

    public function compileDropAllTables()
    {
        return 'BEGIN
            FOR c IN (SELECT table_name FROM user_tables) LOOP
                EXECUTE IMMEDIATE (\'DROP TABLE "\' || REPLACE(c.table_name, \'"\', \'""\') || \'" CASCADE CONSTRAINTS\');
            END LOOP;
            FOR s IN (SELECT sequence_name FROM user_sequences) LOOP
                EXECUTE IMMEDIATE (\'DROP SEQUENCE "\' || REPLACE(s.sequence_name, \'"\', \'""\') || \'"\');
            END LOOP;
        END;';
    }

    public function compileDropColumn(Blueprint $blueprint, Fluent $command)
    {
        return 'alter table '.$this->wrapTable($blueprint).' drop ('.$this->columnize($command->columns).')';
    }

    public function compileDropPrimary(Blueprint $blueprint, Fluent $command)
    {
        return 'alter table '.$this->wrapTable($blueprint).' drop primary key';
    }

    public function compileDropUnique(Blueprint $blueprint, Fluent $command)
    {
        return 'alter table '.$this->wrapTable($blueprint).' drop constraint '.$this->wrap($command->index);
    }

    public function compileDropForeign(Blueprint $blueprint, Fluent $command)
    {
        return $this->compileDropUnique($blueprint, $command);
    }

    public function compileDropIndex(Blueprint $blueprint, Fluent $command)
    {
        return 'drop index '.$this->wrap($command->index);
    }

    public function compileRename(Blueprint $blueprint, Fluent $command)
    {
        return 'alter table '.$this->wrapTable($blueprint).' rename to '.parent::wrapTable($command->to);
    }

    public function compileRenameIndex(Blueprint $blueprint, Fluent $command)
    {
        return 'alter index '.$this->wrap($command->from).' rename to '.$this->wrap($command->to);
    }

    public function compileTableComment(Blueprint $blueprint, Fluent $command)
    {
        return 'comment on table '.$this->wrapTable($blueprint).' is '.$this->literal($command->comment);
    }

    protected function typeChar(Fluent $column) { return 'char('.$column->length.')'; }
    protected function typeString(Fluent $column) { return 'varchar('.$column->length.')'; }
    protected function typeNvarchar2(Fluent $column) { return 'nvarchar('.$column->length.')'; }
    protected function typeText(Fluent $column) { return 'clob'; }
    protected function typeMediumText(Fluent $column) { return 'clob'; }
    protected function typeLongText(Fluent $column) { return 'clob'; }
    protected function typeInteger(Fluent $column) { return 'int'; }
    protected function typeBigInteger(Fluent $column) { return 'bigint'; }
    protected function typeMediumInteger(Fluent $column) { return 'int'; }
    protected function typeSmallInteger(Fluent $column) { return 'smallint'; }
    protected function typeTinyInteger(Fluent $column) { return 'tinyint'; }
    protected function typeFloat(Fluent $column) { return 'float('.($column->precision ?? 53).')'; }
    protected function typeDouble(Fluent $column) { return 'double precision'; }
    protected function typeDecimal(Fluent $column) { return 'decimal('.$column->total.', '.$column->places.')'; }
    protected function typeBoolean(Fluent $column) { return 'tinyint'; }
    protected function typeEnum(Fluent $column) { return 'varchar('.($column->length ?? 255).') check ('.$this->wrap($column->name).' in ('.implode(', ', array_map($this->literal(...), $column->allowed)).'))'; }
    protected function typeDate(Fluent $column) { return 'date'; }
    protected function typeDateTime(Fluent $column) { return $this->typeTimestamp($column); }
    protected function typeDateTimeTz(Fluent $column) { return $this->typeTimestampTz($column); }
    protected function typeTime(Fluent $column) { return 'time('.($column->precision ?? 0).')'; }
    protected function typeTimeTz(Fluent $column) { return 'time('.($column->precision ?? 0).') with time zone'; }
    protected function typeTimestamp(Fluent $column)
    {
        return 'timestamp('.($column->precision ?? 0).')'.($column->useCurrent ? ' default CURRENT_TIMESTAMP' : '');
    }
    protected function typeTimestampTz(Fluent $column)
    {
        return 'timestamp('.($column->precision ?? 0).') with time zone'.($column->useCurrent ? ' default CURRENT_TIMESTAMP' : '');
    }
    protected function typeBinary(Fluent $column) { return 'blob'; }
    protected function typeUuid(Fluent $column) { return 'varchar(36)'; }
    protected function typeUlid(Fluent $column) { return 'char(26)'; }
    protected function typeIpAddress(Fluent $column) { return 'varchar(45)'; }
    protected function typeMacAddress(Fluent $column) { return 'varchar(17)'; }
    protected function typeJson(Fluent $column) { return 'clob'; }
    protected function typeJsonb(Fluent $column) { return 'clob'; }

    protected function modifyDefault(Blueprint $blueprint, Fluent $column)
    {
        if ($column->default !== null) {
            return ' default '.$this->getDefaultValue($column->default);
        }

        return $column->change ? ' default null' : '';
    }

    protected function modifyNullable(Blueprint $blueprint, Fluent $column)
    {
        return $column->nullable ? ' null' : ' not null';
    }

    protected function modifyIncrement(Blueprint $blueprint, Fluent $column)
    {
        if (! in_array($column->type, $this->serials) || ! $column->autoIncrement || $column->change) {
            return '';
        }

        foreach ($blueprint->getCommands() as $command) {
            if ($command->name === 'primary') {
                return '';
            }
        }

        return ' primary key';
    }
}
