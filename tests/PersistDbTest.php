<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Kingsoft\Db\Persis23tDb;

class PersistDbTest extends TestCase
{

    // Test case for PersistDb class

    // create a docker container with mariaDB and run the test
    // docker run --name mariadb -e MYSQL_ROOT_PASSWORD=root -e MYSQL_DATABASE=test -p 8306:3306 -d mariadb:latest
    // docker exec -it mariadb mysql -uroot -proot -e "CREATE DATABASE test;"
    // docker exec -it mariadb mysql -uroot -proot -e "CREATE TABLE test.users (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL);"
    // docker exec -it mariadb mysql -uroot -proot -e "INSERT INTO test.users (name) VALUES ('John Doe');"
    
    public function testPersistDb()
    {
        // Create a new instance of PersistDb
        $persistDb = new PersistDb();

        // Check if the instance is created successfully
        $this->assertInstanceOf(PersistDb::class, $persistDb);

        // Test the getConnection method
        try {
            $connection = $persistDb->getConnection();
            $this->assertNotNull($connection);
        } catch (PersistDbException $e) {
            $this->fail("Failed to get connection: " . $e->getMessage());
        }
    }

}