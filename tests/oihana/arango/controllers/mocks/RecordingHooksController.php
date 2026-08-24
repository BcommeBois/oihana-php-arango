<?php

namespace tests\oihana\arango\controllers\mocks;

use oihana\arango\controllers\DocumentsController;
use oihana\arango\enums\Arango;

use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * A consumer subclass that writes down what each hook call says it is doing.
 *
 * Stands for the consumer the operation key exists for: one that overrides the
 * lifecycle hooks and needs to know which model call it is serving. Both hooks are
 * recorded, in order, so a test can read the sequence a single HTTP verb produces —
 * a `PATCH` runs `beforeModelCall()` three times and `afterModelCall()` twice.
 *
 * @package tests\oihana\arango\controllers\mocks
 * @author  Marc Alcaraz
 */
class RecordingHooksController extends DocumentsController
{
    /**
     * What every `beforeModelCall()` announced, in order.
     * @var array<int,string>
     */
    public array $before = [] ;

    /**
     * What every `afterModelCall()` announced, in order.
     * @var array<int,string>
     */
    public array $after = [] ;

    /**
     * The trace of one hook call: the operation, plus the flag when the call is the
     * read that follows a write.
     */
    public static function trace( array $init ) :string
    {
        $operation = $init[ Arango::OPERATION ] ?? 'none' ;

        return ( $init[ Arango::AFTER_WRITE ] ?? false ) ? $operation . '+afterWrite' : $operation ;
    }

    protected function afterModelCall( ?Request $request , array &$init , mixed &$result ) :void
    {
        parent::afterModelCall( $request , $init , $result ) ;

        $this->after[] = self::trace( $init ) ;
    }

    protected function beforeModelCall( ?Request $request , array &$init ) :void
    {
        parent::beforeModelCall( $request , $init ) ;

        $this->before[] = self::trace( $init ) ;
    }
}
