<?php

namespace oihana\arango\clients\exceptions ;

/**
 * Thrown when an HTTP request to the ArangoDB server fails with a non-2xx
 * response that does not map to a more specific {@see ArangoException} subclass.
 *
 * Use this for generic server-side failures (4xx/5xx) that do not carry an
 * Arango internal error number, or when the parser cannot recognise the
 * response shape.
 *
 * @package oihana\arango\clients\exceptions
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.0.0
 */
class HttpException extends ArangoException
{
}
