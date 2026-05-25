<?php

namespace oihana\arango\models\traits\documents;

use ReflectionException;
use Throwable;

use DI\DependencyException;
use DI\NotFoundException;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\Clause;
use oihana\arango\enums\Arango;
use oihana\arango\models\traits\aql\BindTrait;
use oihana\arango\models\traits\ArangoTrait;

use oihana\enums\Char;

use oihana\exceptions\BindException;
use oihana\exceptions\UnsupportedOperationException;

use oihana\models\enums\ModelParam;
use oihana\models\traits\signals\HasDeleteSignals;

use function oihana\arango\db\operations\aqlFilter;
use function oihana\arango\db\operations\aqlFor;
use function oihana\arango\db\operations\aqlRemove;
use function oihana\arango\db\operations\aqlReturn;
use function oihana\arango\db\operators\in;

use function oihana\arango\models\helpers\edges\resolveEdges;
use function oihana\core\arrays\unique;
use function oihana\core\strings\compile;
use function oihana\core\strings\key;

use org\schema\constants\Schema;

use oihana\models\notices\AfterDelete;
use oihana\models\notices\BeforeDelete;

trait DocumentsDeleteTrait
{
    use ArangoTrait ,
        BindTrait ,
        HasDeleteSignals ;

    /**
     * Deletes an document or a set of documents in the model.
     *
     * @param array $init The optional setting definition :
     * - value (mixed) : The value of the key to identify the document to remove in the collection.
     * - key (string|null) : The key attribute to target (default _key)
     * - prefix (string|null) : The prefix document of the key (default use "doc" -> "doc.key" )
     * - binds (array|null) : The prefix document of the key (default use "doc" -> "doc.key" )
     *
     * @return null|array|object
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     * @throws Throwable
     */
    public function delete( array $init = [] ) :null|array|object
    {
        // Important: enforce the DI container to generates the edges references.
        resolveEdges( $this->edges , $this->container ) ;

        $this->beforeDelete?->emit( new BeforeDelete
        (
            target  : $this ,
            context : $init
        )) ;

        $result   = null ;
        $bindVars = $init[ Arango::BINDS ] ?? [] ;
        $values   = $init[ Arango::VALUE ] ?? [] ;

        if( !is_array( $values ) )
        {
            $values = [ $values ] ;
        }

        $values = unique ( $values ) ;
        $count  = count  ( $values ) ;

        if( $count > 0 )
        {
            $conditions = $init[ Arango::CONDITIONS ] ?? [] ;
            $key        = $init[ Arango::KEY        ] ?? Schema::_KEY ;
            $docRef     = $init[ Arango::DOC_REF    ] ?? AQL::DOC  ;
            $docKey     = key( $key , $docRef ) ;

            $in = [] ;
            foreach( $values as $value )
            {
                $in[] = $this->bind( $value , $bindVars ) ;
            }

            $in = Char::LEFT_BRACKET . implode( Char::COMMA , $in ) . Char::RIGHT_BRACKET ;

            $query = compile
            ([
                aqlFor    ( [ AQL::IN => $this->bindCollection( $bindVars ) ] ) , // FOR doc in @@collection
                aqlFilter ( [ in( $docKey , $in ) , ...$conditions ] ) , // FILTER doc.key in [ ...values ]
                aqlRemove ( [ AQL::COLLECTION => $this->collection ] ) , // REMOVE { _key: doc.[_key] } IN @@collection
                aqlReturn ( Clause::OLD                    ) , // RETURN OLD
            ]);

            // echo 'delete : ' . $query . PHP_EOL;
            // echo 'binds  : ' . json_encode( $bindVars ) . PHP_EOL;

            $debug = $init[ ModelParam::DEBUG ] ?? false ;
            if( $debug === true )
            {
                $this->debugQuery( __METHOD__ , $query , $bindVars ) ;
            }

            // FOR doc IN @@collection
            // FILTER doc.[_key] IN [ @key , .... ] [ && ...conditions ]
            // REMOVE { _key: doc.[_key] } IN @@collection
            // RETURN OLD

            $result = $count == 1 ? $this->getObject( $query , $bindVars ) : $this->getDocuments( $query , $bindVars ) ;
        }

        $this->afterDelete?->emit( new AfterDelete
        (
            data    : $result ,
            target  : $this   ,
            context : $init
        )) ;

        return $result ;
    }
}