<?php

namespace oihana\arango\clients\enums ;

use oihana\reflect\traits\ConstantsTrait ;

/**
 * Authentication schemes supported by the ArangoDB client.
 *
 * @package oihana\arango\clients\enums
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.0.0
 */
class AuthType
{
    use ConstantsTrait ;

    /**
     * HTTP Basic authentication. The user and password are sent on every request.
     */
    public const string BASIC = 'Basic' ;

    /**
     * JWT Bearer authentication. A signed token is sent on every request.
     *
     * @see https://docs.arangodb.com/stable/develop/http-api/authentication/#jwt-user-tokens
     */
    public const string JWT = 'JWT' ;
}
