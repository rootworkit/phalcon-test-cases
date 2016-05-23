<?php

namespace Rootwork\Phalcon\Test;

use Rootwork\PHPUnit\Helper\Accessor;
use Phalcon\Di\InjectionAwareInterface;
use Phalcon\DiInterface;
use Phalcon\Di;
use Phalcon\Db\Adapter\Pdo;
use PHPUnit_Framework_MockObject_MockObject as MockObject;

/**
 * MockedDbTestCase
 *
 * @package     Rootwork\Phalcon\Test
 */
class MockedDbTestCase extends \PHPUnit_Framework_TestCase implements InjectionAwareInterface
{
    use Accessor;

    /**
     * @var DiInterface
     */
    private $di;

    /**
     * Expected DB results.
     *
     * @var array
     */
    private static $dbResults = [];

    /**
     * Expected insert IDs.
     *
     * @var array
     */
    private static $insertIds = [];

    /**
     * ID of last inserted record.
     *
     * @var integer|null
     */
    private static $lastInsertId = null;

    /**
     * Set up the test.
     */
    public function setUp()
    {
        self::$dbResults = [];
        self::$insertIds = [];

        $db = $this->getDI()->get('db');
        if ($db instanceof Pdo && !($db instanceof MockObject)) {
            $this->mockDb('db');
        }
    }

    /**
     * Sets the dependency injector
     *
     * @param mixed $dependencyInjector
     *
     * @return $this
     */
    public function setDI(DiInterface $dependencyInjector)
    {
        $this->di = $dependencyInjector;

        return $this;
    }

    /**
     * Returns the internal dependency injector
     *
     * @return DiInterface
     */
    public function getDI()
    {
        if (!$this->di instanceof DiInterface) {
            $this->di = Di::getDefault();
        }

        return $this->di;
    }

    /**
     * Queue a DB query result.
     *
     * @param string    $sql
     * @param array     $bindParams
     * @param array     $fetchData
     *
     * @return $this
     */
    public function queueDbResult($sql, array $bindParams, array $fetchData)
    {
        $queryKey   = $sql . '::' . serialize($bindParams);
        $result     = $this->getMock(
            'Phalcon\\Db\\Result\\Pdo',
            ['numRows', 'setFetchMode', 'fetchAll', 'fetch'],
            [],
            '',
            false
        );

        $this->setPropertyValue($result, '_sqlStatement', $sql);
        $result->expects($this->any())->method('numRows')->will($this->returnValue(count($fetchData)));
        $result->expects($this->any())->method('fetchAll')->will($this->returnValue($fetchData));
        $result->expects($this->any())->method('fetch')->willReturnCallback(function () use ($fetchData) {
            if (count($fetchData)) {
                return array_shift($fetchData);
            }

            return false;
        });

        self::$dbResults[$queryKey] = $result;

        return $this;
    }

    /**
     * Queue a DB insert ID.
     *
     * @param string    $sql
     * @param array     $bindParams
     * @param integer   $insertId
     *
     * @return $this
     */
    public function queueInsertId($sql, array $bindParams, $insertId)
    {
        $insertKey = $sql . '::' . serialize($bindParams);
        self::$insertIds[$insertKey] = $insertId;

        return $this;
    }

    /**
     * Setup the DB mock.
     *
     * @param string $name
     */
    protected function mockDb($name = 'db')
    {
        $di             = $this->getDI();
        $db             = $di->get($name);
        $dbClass        = get_class($db);
        $dialectClass   = get_class($this->getPropertyValue($db, '_dialect'));
        $mockDialect    = $this->getMock($dialectClass, null, [], '', false);

        /** @var Pdo|MockObject $db */
        $mockDb = $this->getMock(
            $dbClass,
            ['tableExists', 'query', 'execute', 'lastInsertId'],
            [],
            '',
            false
        );

        $this->setPropertyValue($mockDb, '_dialect', $mockDialect);

        $mockDb->expects($this->any())->method('tableExists')->will($this->returnValue(true));

        $mockDb->expects($this->any())->method('execute')->willReturnCallback(function ($sql, $bindParams) {
            if (stripos($sql, 'INSERT' !== 0)) {
                return true;
            }

            $insertKey = $sql . '::' . serialize($bindParams);

            if (isset(self::$insertIds[$insertKey])) {
                self::$lastInsertId = self::$insertIds[$insertKey];

                return true;
            }

            throw new \InvalidArgumentException(
                "No insert ID queued for: $sql" . PHP_EOL .
                "Bind: " . var_export($bindParams, true)
            );
        });

        $mockDb->expects($this->any())->method('query')->willReturnCallback(function ($sql, $bindParams) {
            $queryKey = $sql . '::' . serialize($bindParams);

            if (isset(self::$dbResults[$queryKey])) {
                return self::$dbResults[$queryKey];
            }

            throw new \InvalidArgumentException(
                "No DB result queued for: $sql" . PHP_EOL .
                "Bind: " . var_export($bindParams, true)
            );
        });

        $mockDb->expects($this->any())->method('lastInsertId')->willReturnCallback(function () {
            return array_shift(self::$insertIds);
        });

        $di->remove($name);
        $di->setShared($name, function () use ($mockDb) {
            return $mockDb;
        });
    }
}
