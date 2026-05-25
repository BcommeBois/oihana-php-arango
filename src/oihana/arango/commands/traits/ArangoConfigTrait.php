<?php

namespace oihana\arango\commands\traits;

use AssertionError;
use UnexpectedValueException;

use oihana\arango\db\enums\ArangoConfig;

/**
 * The command to manage an ArangoDB database.
 */
trait ArangoConfigTrait
{
    /**
     * The ArangoDB  database name.
     * @var ?string
     */
    protected ?string $database = null ;

    /**
     * The ArangoDB database endpoint.
     * @var ?string
     */
    protected ?string $endpoint = null ;

    /**
     * The ArangoDB database password.
     * @var ?string
     */
    protected ?string $password ;

    /**
     * The ArangoDB database username.
     * @var string
     */
    protected string $username ;

    /**
     * Assert the database reference.
     * @param string|null $database
     * @return void
     * @throws AssertionError
     */
    public function assertDatabase( ?string $database ):void
    {
        assert( isset( $database ) , 'The database name not must be null.' ) ;
    }

    /**
     * Assert the passed-in endpoint reference.
     * @param string|null $endpoint
     * @return void
     * @throws AssertionError
     */
    public function assertEndpoint( ?string $endpoint ):void
    {
        assert( isset( $endpoint ) , 'The database endpoint not must be null.' ) ;
    }

    /**
     * Assert the passed-in username reference.
     * @param string|null $username
     * @return void
     * @throws AssertionError
     */
    public function assertUsername( ?string $username ):void
    {
        assert( isset( $username ) , 'The database username not must be null.' ) ;
    }

    /**
     * Assert the passed-in username reference.
     * @param string|null $password
     * @return void
     * @throws AssertionError
     */
    public function assertPassword( ?string $password ):void
    {
        assert( isset( $password ) , 'The database password not must be null.' ) ;
    }


    /**
     * Returns the database name and assert the existence of the value.
     * @return string
     */
    public function getDatabase():string
    {
        $this->assertDatabase( $this->database );
        return $this->database ;
    }

    /**
     * Returns the database endpoint and thrown an error if not exist.
     * @return string
     * @throws UnexpectedValueException
     */
    public function getEndpoint():string
    {
        $this->assertEndpoint( $this->endpoint ) ;
        return $this->endpoint ;
    }

    /**
     * Returns the database password and thrown an error if not exist.
     * @return string
     * @throws UnexpectedValueException
     */
    public function getPassword():string
    {
        $this->assertPassword( $this->password ) ;
        return $this->password ;
    }

    /**
     * Returns the database username and thrown an error if not exist.
     * @return string
     * @throws UnexpectedValueException
     */
    public function getUsername():string
    {
        $this->assertUsername( $this->username ) ;
        return $this->username ;
    }

    /**
     * Initialize the arangodb components.
     * @param array $init
     * @return static
     */
    public function initializeArangoDB( array $init = [] ):static
    {
        $this->database = $init[ ArangoConfig::DATABASE ] ?? $this->database   ;
        $this->endpoint = $init[ ArangoConfig::ENDPOINT ] ?? $this->endpoint   ;
        $this->username = $init[ ArangoConfig::USER     ] ?? $this->username   ;
        $this->password = $init[ ArangoConfig::PASSWORD ] ?? $this->password   ;
        return $this ;
    }
}