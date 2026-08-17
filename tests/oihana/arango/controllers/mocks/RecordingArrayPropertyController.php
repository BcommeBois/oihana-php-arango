<?php

namespace tests\oihana\arango\controllers\mocks;

use Closure;

use Psr\Http\Message\ServerRequestInterface as Request;

use oihana\arango\controllers\ArrayPropertyController;

/**
 * An {@see ArrayPropertyController} that records every call to the post-write hook,
 * and optionally runs a closure inside it.
 *
 * The closure is what lets a test pin the **ordering** rather than merely the
 * existence of the hook: mutating the model's canned document from inside it, then
 * asserting the mutation is visible in the response, proves the hook ran before the
 * body was built.
 *
 * @package tests\oihana\arango\controllers\mocks
 * @author  Marc Alcaraz
 */
class RecordingArrayPropertyController extends ArrayPropertyController
{
    /**
     * The documents handed to the hook, in call order.
     * @var array<int,?object>
     */
    public array $written = [] ;

    /**
     * An optional side effect to run inside the hook.
     * @var ?Closure
     */
    public ?Closure $onWrite = null ;

    /**
     * @inheritDoc
     */
    protected function afterArrayWrite( ?Request $request , array $args , array $init , ?object $document ) : void
    {
        $this->written[] = $document ;

        if ( $this->onWrite instanceof Closure )
        {
            ( $this->onWrite )( $document ) ;
        }
    }
}
