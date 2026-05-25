<?php

namespace oihana\arango\clients\http ;

use oihana\arango\clients\exceptions\ArangoException ;

/**
 * Retry policy for the ArangoDB HTTP transport.
 *
 * Encapsulates two decisions:
 * - whether a given failed attempt should be retried (based on
 *   {@see ArangoException::isSafeToRetry()} and the per-policy attempt budget),
 * - how long to wait before the next attempt (capped exponential back-off).
 *
 * The default policy retries up to three times with a delay sequence of
 * 100 ms, 200 ms, 400 ms, … capped at 5 000 ms.
 *
 * @package oihana\arango\clients\http
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.0.0
 */
readonly class RetryPolicy
{
    /**
     * @param int $maxAttempts Maximum number of attempts (1 = no retry).
     * @param int $baseDelayMs Base delay in milliseconds for the first retry; doubled on each subsequent retry.
     * @param int $maxDelayMs  Upper bound on the delay (the back-off is capped at this value).
     */
    public function __construct
    (
        public int $maxAttempts = self::DEFAULT_MAX_ATTEMPTS ,
        public int $baseDelayMs = self::DEFAULT_BASE_DELAY_MS ,
        public int $maxDelayMs  = self::DEFAULT_MAX_DELAY_MS ,
    )
    {
    }

    /**
     * Default base delay between two attempts, in milliseconds.
     */
    public const int DEFAULT_BASE_DELAY_MS = 100 ;

    /**
     * Default maximum number of attempts (initial attempt + retries).
     */
    public const int DEFAULT_MAX_ATTEMPTS = 3 ;

    /**
     * Default upper bound for the back-off delay, in milliseconds.
     */
    public const int DEFAULT_MAX_DELAY_MS = 5000 ;

    /**
     * Computes the delay (in milliseconds) to wait before attempt number `$attempt`.
     *
     * The sequence is `baseDelayMs * 2^(attempt-1)`, capped at `maxDelayMs`.
     * Returns 0 for attempt numbers lower than 1 (defensive).
     *
     * @param int $attempt The attempt number that just failed (1 = first attempt).
     * @return int Delay in milliseconds.
     */
    public function delayMs( int $attempt ) : int
    {
        if ( $attempt < 1 )
        {
            return 0 ;
        }
        $delay = $this->baseDelayMs * ( 2 ** ( $attempt - 1 ) ) ;
        return (int) min( $delay , $this->maxDelayMs ) ;
    }

    /**
     * Decides whether a failed attempt should be retried.
     *
     * A retry is allowed when both conditions are met:
     * - the attempt budget is not exhausted (`$attempt < $maxAttempts`),
     * - the exception reports itself as safe to retry.
     *
     * @param ArangoException $exception The exception raised by the failed attempt.
     * @param int             $attempt   The attempt number that just failed (1-indexed).
     *
     * @return bool
     */
    public function shouldRetry( ArangoException $exception , int $attempt ) : bool
    {
        return $attempt < $this->maxAttempts && $exception->isSafeToRetry() ;
    }
}
