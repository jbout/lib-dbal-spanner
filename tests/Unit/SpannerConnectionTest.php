<?php

namespace OAT\Library\DBALSpanner\Tests\Unit;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Exception\InvalidArgumentException;
use Google\Cloud\Spanner\Database;
use Google\Cloud\Spanner\Transaction;
use OAT\Library\DBALSpanner\SpannerConnection;
use OAT\Library\DBALSpanner\SpannerStatement;
use PHPUnit\Framework\TestCase;
use ReflectionException;
use ReflectionProperty;

class SpannerConnectionTest extends TestCase
{
    protected function getSpannerConnection(Driver $driver = null, Database $database = null)
    {
        if (null === $driver) {
            $driver = $this->getMockForAbstractClass(Driver::class);
        }

        if (null === $database) {
            $database = $this->getMockBuilder(Database::class)->disableOriginalConstructor()->getMock();
        }

        return new SpannerConnection($driver, $database);
    }

    public function testPrepare()
    {
        $connection = $this->getSpannerConnection();
        $statement = $connection->prepare('sql-query');

        $this->assertInstanceOf(SpannerStatement::class, $statement);

        $property = new ReflectionProperty(SpannerStatement::class, 'sql');
        $property->setAccessible(true);
        $this->assertEquals('sql-query', $property->getValue($statement));

        $property = new ReflectionProperty(SpannerConnection::class, 'cachedStatements');
        $property->setAccessible(true);
        $cachedStatements = $property->getValue($connection);

        $this->assertArrayHasKey('sql-query', $cachedStatements);
        $this->assertEquals($statement, $cachedStatements['sql-query']);
    }

    public function testPrepareFromCache()
    {
        $connection = $this->getSpannerConnection();

        $property = new ReflectionProperty(SpannerConnection::class, 'cachedStatements');
        $property->setAccessible(true);
        $property->setValue($connection, ['sql-query' => 'preparedStatement']);

        $this->assertEquals('preparedStatement', $connection->prepare('sql-query'));
    }

    public function testQuery()
    {
        $connection = $this->getSpannerConnection();
        $statement = $this->createMock(SpannerStatement::class);
        $statement->expects($this->once())->method('execute');

        $property = new ReflectionProperty(SpannerConnection::class, 'cachedStatements');
        $property->setAccessible(true);
        $property->setValue($connection, ['sql-query' => $statement]);

        $this->assertEquals($statement, $connection->query('sql-query'));
    }

    public function ddlQueriesProvider()
    {
        return [
            ['CREATE table'],
            ['DROP table'],
            ['ALTER table'],
        ];
    }

    /**
     * @todo adapt this test when DDL is implemented
     * @dataProvider ddlQueriesProvider
     * @param $query
     * @throws ReflectionException
     */
    public function testQueryWithDdl($query)
    {
        $connection = $this->getSpannerConnection();

        $this->assertNull($connection->query($query));

        $property = new ReflectionProperty(SpannerConnection::class, 'cachedStatements');
        $property->setAccessible(true);
        $this->assertEmpty($property->getValue($connection));
    }

    public function deleteDataProvider()
    {
        return [
            ['users', ['identifier' => 1], 'DELETE FROM users WHERE identifier = 1'],
            ['events', ['event_id' => 1, 'id' => 4 ], 'DELETE FROM events WHERE event_id = 1 AND id = 4'],
            ['test-takers',  ['firstname' => 'firstname', 'lastname' => 'lastname' ], 'DELETE FROM test-takers WHERE firstname = "firstname" AND lastname = "lastname"'],
        ];
    }

    /**
     * @dataProvider deleteDataProvider
     * @param $tableName
     * @param $identifiers
     * @param $expectedQuery
     * @throws InvalidArgumentException
     */
    public function testDelete($tableName, $identifiers, $expectedQuery)
    {
        $transaction = $this->createMock(Transaction::class);
        $transaction->expects($this->once())->method('commit');
        $transaction->expects($this->once())->method('executeUpdate')->will($this->returnCallback(function ($arg) { return $arg; }));

        $database = $this->createMock(Database::class);
        $database
            ->expects($this->once())
            ->method('runTransaction')
            ->with($this->callback(function($closure) use ($transaction, $expectedQuery) {
                return $closure($transaction) == $expectedQuery;
            }));

        $connection = $this->getSpannerConnection(null, $database);
        $connection->delete($tableName, $identifiers);
    }

    /**
     * @throws InvalidArgumentException
     */
    public function testDeleteWithEmptyIdentifier()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Empty criteria was used, expected non-empty criteria');
        $this->getSpannerConnection()->delete('users', []);
    }

    public function updateDataProvider()
    {
        return [
            ['users', ['lastname' => 'fixture'], ['id' => 1], 'UPDATE users SET lastname = "fixture" WHERE id = 1'],
            ['events', ['event_name' => 'name', 'event_log' => 4 ], ['name' => "fixture"], 'UPDATE events SET event_name = "name", event_log = 4 WHERE name = "fixture"'],
        ];
    }

    /**
     * @dataProvider updateDataProvider
     * @param $tableName
     * @param $data
     * @param $identifiers
     * @param $expectedQuery
     * @throws InvalidArgumentException
     */
    public function testUpdate($tableName, $data, $identifiers, $expectedQuery)
    {
        $transaction = $this->createMock(Transaction::class);
        $transaction->expects($this->once())->method('commit');
        $transaction->expects($this->once())->method('executeUpdate')->will($this->returnCallback(function ($arg) { return $arg; }));

        $database = $this->createMock(Database::class);
        $database
            ->expects($this->once())
            ->method('runTransaction')
            ->with($this->callback(function($closure) use ($transaction, $expectedQuery) {
                return $closure($transaction) == $expectedQuery;
            }));

        $connection = $this->getSpannerConnection(null, $database);
        $connection->update($tableName, $data, $identifiers);
    }

    /**
     * @throws InvalidArgumentException
     */
    public function testUpdateWithEmptyIdentifier()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Empty criteria was used, expected non-empty criteria');
        $this->getSpannerConnection()->update('users', [], []);
    }

    public function testInsert()
    {
        $tableName = 'users';
        $data = ['id' => 1, 'name' => 'fixture'];
        $database = $this->createMock(Database::class);
        $database->expects($this->once())->method('insert')->with($tableName, $data);
        $this->getSpannerConnection(null, $database)->insert($tableName, $data);
    }
}