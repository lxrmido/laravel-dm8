<?php

namespace Lmo\LaravelDm8\Tests;

use PDO;
use PDOStatement;

final class RecordingPdo extends PDO
{
    public array $statements = [];
    public int $commits = 0;
    private bool $transaction = false;

    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return $this->statements[] = new RecordingStatement($query);
    }
    public function beginTransaction(): bool { return $this->transaction = true; }
    public function commit(): bool { ++$this->commits; $this->transaction = false; return true; }
    public function rollBack(): bool { $this->transaction = false; return true; }
    public function inTransaction(): bool { return $this->transaction; }
}

final class RecordingStatement extends PDOStatement
{
    public array $bindings = [];
    private array $output = [];

    public function __construct(public string $sql) {}
    public function bindValue(string|int $param, mixed $value, int $type = PDO::PARAM_STR): bool
    {
        $this->bindings[$param] = [$value, $type];
        return true;
    }
    public function bindParam(string|int $param, mixed &$var, int $type = PDO::PARAM_STR, int $maxLength = 0, mixed $driverOptions = null): bool
    {
        $this->output[$param] = &$var;
        return true;
    }
    public function execute(?array $params = null): bool
    {
        if ($this->output !== []) {
            $this->output[array_key_last($this->output)] = 42;
        }
        return true;
    }
    public function setFetchMode(int $mode, mixed ...$args): true { return true; }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return []; }
    public function rowCount(): int { return 1; }
}
