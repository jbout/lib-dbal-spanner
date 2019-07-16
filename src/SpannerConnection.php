<?php

declare(strict_types=1);

namespace OAT\Library\DBALSpanner;

use Doctrine\DBAL\DBALException;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Exception\InvalidArgumentException;
use Doctrine\DBAL\ParameterType;
use Google\Cloud\Spanner\Database;
use Google\Cloud\Spanner\Transaction;

class SpannerConnection implements Connection
{
    /** @var Database */
    protected $database;

    public function setDatabase(Database $database)
    {
        $this->database = $database;
    }

    public function prepare($prepareString)
    {
        return new SpannerStatement($this->_conn->database, $prepareString);
    }

    public function query()
    {
        $args = func_get_args();
        $sql = $args[0];
        // Runs schema manipulation on the DDL endpoint.
        if ($this->isDdlStatement($sql)) {
            // Don't run schema changes on spanner for the moment.
            return null;
            // TODO: this generates a long running query which has to be managed.
            $longRunningQuery = $this->database->updateDdl($statement);
        }

        $statement = $this->prepare($sql);
        $statement->execute();

        return $statement;
    }

    public function delete($tableExpression, array $identifier, array $types = [])
    {
        if (empty($identifier)) {
            throw InvalidArgumentException::fromEmptyCriteria();
        }

        $statement = sprintf(
            'DELETE FROM %s WHERE %s',
            $tableExpression,
            $this->forgeWhereClause($identifier)
        );

        return $this->exec($statement);
    }

    /**
     * Gathers conditions for an update or delete call.
     *
     * @param mixed[] $identifiers Input array of columns to values
     *
     * @return string the conditions
     * @throws DBALException If an invalid platform was specified for this connection.
     */
    protected function forgeWhereClause(array $identifiers)
    {
        $conditions = [];

        foreach ($identifiers as $columnName => $value) {
            $conditions[] = $value !== null
                ? $columnName . ' = ' . $this->quoteString($value)
                : $this->getDatabasePlatform()->getIsNullExpression($columnName);
        }

        return implode(' AND ', $conditions);
    }

    public function update($tableExpression, array $data, array $identifier, array $types = [])
    {
        $sets = [];

        foreach ($data as $columnName => $value) {
            $sets[] = $columnName . ' = ' . $this->quoteString($value);
        }

        $statement = sprintf(
            'UPDATE %s SET %s WHERE %s',
            $tableExpression,
            implode(', ', $sets),
            $this->forgeWhereClause($identifier)
        );

        return $this->exec($statement);
    }

    public function insert($tableExpression, array $data, array $types = [])
    {
        return $this->database->insert($tableExpression, $data);
    }

    /**
     * Quotes strings only.
     *
     * @param mixed $value
     *
     * @return mixed
     */
    public function quoteString($value)
    {
        return $this->quote($value, is_string($value) ? ParameterType::STRING : ParameterType::INTEGER);
    }

    public function quote($input, $type = ParameterType::STRING)
    {
        return $type === ParameterType::STRING || $type === ParameterType::BINARY
            ? '"' . $input . '"'
            : $input;
    }

    public function exec($statement)
    {
        // Runs schema manipulation on the DDL endpoint.
        if ($this->isDdlStatement($statement)) {
            // Don't run schema changes on spanner for the moment.
            return 0;
            // TODO: this generates a long running query which has to be managed.
            $longRunningQuery = $this->database->updateDdl($statement);
        }

        return $this->database->runTransaction(
            function (Transaction $t) use ($statement) {
                $rowCount = $t->executeUpdate($statement);
                $t->commit();
                return $rowCount;
            }
        );
    }

    public function isDdlStatement($statement)
    {
        $statement = ltrim($statement);

        return stripos($statement, 'CREATE ') === 0
            || stripos($statement, 'DROP ') === 0
            || stripos($statement, 'ALTER ') === 0;
    }

    public function lastInsertId($name = null)
    {
        throw new \Exception("\e[31m\e[1m" . __METHOD__ . "\e[21m\e[0m" . ' not implemented.');
    }

    public function beginTransaction()
    {
        throw new \Exception("\e[31m\e[1m" . __METHOD__ . "\e[21m\e[0m" . ' not implemented.');
    }

    public function commit()
    {
        throw new \Exception("\e[31m\e[1m" . __METHOD__ . "\e[21m\e[0m" . ' not implemented.');
    }

    public function rollBack()
    {
        throw new \Exception("\e[31m\e[1m" . __METHOD__ . "\e[21m\e[0m" . ' not implemented.');
    }

    public function errorCode()
    {
        throw new \Exception("\e[31m\e[1m" . __METHOD__ . "\e[21m\e[0m" . ' not implemented.');
    }

    public function errorInfo()
    {
        throw new \Exception("\e[31m\e[1m" . __METHOD__ . "\e[21m\e[0m" . ' not implemented.');
    }
}
