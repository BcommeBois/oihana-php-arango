<?php

namespace tests\oihana\arango\controllers\mocks;

use Closure;
use RuntimeException;

use org\schema\helpers\SchemaResolver;

use tests\oihana\arango\models\traits\documents\mocks\MockDocuments;

/**
 * A {@see MockDocuments} whose fetch seams always throw — used to exercise the
 * controller handlers' `catch` → `fail()` branch.
 *
 * @package tests\oihana\arango\controllers\mocks
 * @author  Marc Alcaraz
 */
class ThrowingDocuments extends MockDocuments
{
    /**
     * @inheritDoc
     */
    public function getDocuments
    (
        string                             $query    ,
        array                              $bindVars = [] ,
        array                              $options  = [] ,
        bool                               $raw      = false ,
        null|SchemaResolver|Closure|string $schema   = null ,
    )
    :array
    {
        throw new RuntimeException( 'boom getDocuments' ) ;
    }

    /**
     * @inheritDoc
     */
    public function getFirstResult
    (
        string                             $query    ,
        array                              $bindVars = [] ,
        array                              $options  = [] ,
        bool                               $raw      = false ,
        null|SchemaResolver|Closure|string $schema   = null ,
    )
    :mixed
    {
        throw new RuntimeException( 'boom getFirstResult' ) ;
    }

    /**
     * @inheritDoc
     */
    public function getObject
    (
        string                             $query    ,
        array                              $bindVars = [] ,
        array                              $options  = [] ,
        bool                               $raw      = false ,
        null|SchemaResolver|Closure|string $schema   = null ,
    )
    :?object
    {
        throw new RuntimeException( 'boom getObject' ) ;
    }
}
