<?php

namespace oihana\arango\clients\helpers ;

use oihana\arango\clients\exceptions\ArangoException ;
use oihana\arango\clients\exceptions\HttpException ;

/**
 * Turns a server request into an existence answer: `true` when it succeeds,
 * `false` on a 404, and the original failure for anything else.
 *
 * Every resource handle of the client answers the same question the same way —
 * {@see \oihana\arango\clients\analyzer\Analyzer::exists()},
 * {@see \oihana\arango\clients\view\View::exists()},
 * {@see \oihana\arango\clients\graph\Graph::exists()},
 * {@see \oihana\arango\clients\transaction\Transaction::exists()},
 * {@see \oihana\arango\clients\collection\Collection::exists()} and the two
 * `documentExists()` of {@see \oihana\arango\clients\collection\Collection} and
 * {@see \oihana\arango\clients\graph\GraphCollection}. Only the request differs
 * (the verb, the path, and which handle carries the transport), which is why it
 * travels as a closure rather than as parameters.
 *
 * **A 404 is the only failure read as an answer.** The net is
 * {@see HttpException} — the type the response factory produces for an ordinary
 * HTTP status — and deliberately not its parent {@see ArangoException}: the
 * sibling classes `ConflictException` (409), `MaintenanceException` (503) and
 * `NetworkException` (0) describe a server that could not answer, not a resource
 * that is missing, and must reach the caller untouched.
 *
 * @param callable $request The request to run; its return value is discarded.
 *
 * @return bool `true` when the request succeeds, `false` on a 404.
 *
 * @throws ArangoException Any failure other than a 404, unchanged.
 *
 * @example
 * ```php
 * use function oihana\arango\clients\helpers\probeExists;
 *
 * public function exists() : bool
 * {
 *     return probeExists( fn() => $this->database->request( method : HttpMethod::GET , path : $this->path() ) ) ;
 * }
 * ```
 *
 * @package oihana\arango\clients\helpers
 * @since   1.6.0
 * @author  Marc Alcaraz
 */
function probeExists( callable $request ) : bool
{
    try
    {
        $request() ;
        return true ;
    }
    catch ( HttpException $e )
    {
        if ( $e->getCode() === 404 )
        {
            return false ;
        }
        throw $e ;
    }
}
