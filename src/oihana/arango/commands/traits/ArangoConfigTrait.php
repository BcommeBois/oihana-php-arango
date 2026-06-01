<?php

namespace oihana\arango\commands\traits;

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
    protected ?string $password = null ;

    /**
     * The ArangoDB database username.
     * @var ?string
     */
    protected ?string $username = null ;

    /**
     * Assert the database reference.
     * @param string|null $database
     * @return void
     * @throws UnexpectedValueException
     */
    public function assertDatabase( ?string $database ):void
    {
        if ( $database === null )
        {
            throw new UnexpectedValueException( 'The database name must not be null.' ) ;
        }
    }

    /**
     * Assert the passed-in endpoint reference.
     * @param string|null $endpoint
     * @return void
     * @throws UnexpectedValueException
     */
    public function assertEndpoint( ?string $endpoint ):void
    {
        if ( $endpoint === null )
        {
            throw new UnexpectedValueException( 'The database endpoint must not be null.' ) ;
        }
    }

    /**
     * Assert the passed-in password reference.
     * @param string|null $password
     * @return void
     * @throws UnexpectedValueException
     */
    public function assertPassword( ?string $password ):void
    {
        if ( $password === null )
        {
            throw new UnexpectedValueException( 'The database password must not be null.' ) ;
        }
    }

    /**
     * Assert the passed-in username reference.
     * @param string|null $username
     * @return void
     * @throws UnexpectedValueException
     */
    public function assertUsername( ?string $username ):void
    {
        if ( $username === null )
        {
            throw new UnexpectedValueException( 'The database username must not be null.' ) ;
        }
    }

    /**
     * Returns the database name and asserts the existence of the value.
     * @return string
     * @throws UnexpectedValueException
     */
    public function getDatabase():string
    {
        $this->assertDatabase( $this->database );
        return $this->database ;
    }

    /**
     * Returns the database endpoint and throws an error if not set.
     * @return string
     * @throws UnexpectedValueException
     */
    public function getEndpoint():string
    {
        $this->assertEndpoint( $this->endpoint ) ;
        return $this->endpoint ;
    }

    /**
     * Returns the database password and throws an error if not set.
     * @return string
     * @throws UnexpectedValueException
     */
    public function getPassword():string
    {
        $this->assertPassword( $this->password ) ;
        return $this->password ;
    }

    /**
     * Returns the database username and throws an error if not set.
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
        $this->database = $init[ ArangoConfig::DATABASE ] ?? $this->database ;
        $this->endpoint = $init[ ArangoConfig::ENDPOINT ] ?? $this->endpoint ;
        $this->username = $init[ ArangoConfig::USER     ] ?? $this->username ;
        $this->password = $init[ ArangoConfig::PASSWORD ] ?? $this->password ;
        return $this ;
    }
}
