<?php

namespace oihana\arango\models\traits\documents;

use ReflectionException;

use DI\DependencyException;
use DI\NotFoundException;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\db\enums\ArrayComparator;
use oihana\arango\enums\Arango;
use oihana\arango\models\traits\ArangoTrait;
use oihana\arango\models\traits\queries\ExistQueryTrait;
use oihana\exceptions\BindException;

use function oihana\core\arrays\toArray;
use function oihana\core\arrays\unique;

trait DocumentsExistTrait
{
    use ArangoTrait ,
        ExistQueryTrait ;

    /**
     * Checks for the existence of one or more documents based on their keys or specified attributes.
     *
     * This method constructs and executes an AQL query to determine if documents matching the provided
     * criteria exist within the collection.
     *
     * The behavior for multiple values depends on the `$match` parameter, allowing you to check if *any*, *all*, or *none* of the specified values exist.
     *
     * Generated the AQL query :
     *  `RETURN LENGTH(FOR doc IN @@collection FILTER doc._key IN @values [ && ...additionalConditions ] RETURN 1)`
     *
     * @param array{ value?:int|string|array<int|string> , key?:null|string , prefix?:null|string , match?:null|string , conditions?:null|array } $init
     * An associative array of optional settings to define the search criteria:
     * <ul>
     *     <li>value : The single value or an array of values to search for. These values will be matched against the document key or the attribute specified by `key`.
     *     <li>match : Determines the matching strategy when multiple `value`s are provided. Uses constants from `ArrayComparator` enumeration.
     *         <ul>
     *             <li>`ArrayComparator::ALL` (default): Returns `true` only if *all* specified `value`s exist as documents.</li>
     *             <li>`ArrayComparator::ANY`: Returns `true` if *at least one* of the specified `value`s exists as a document.</li>
     *         </ul>
     *      <li>key : The document attribute to target for the existence check (e.g., `_key`, `name`, `userId`). Defaults to `_key`.
     *      <li>prefix : The document alias used in the AQL query (e.g., "doc" in `doc._key`). Defaults to "doc".
     *      <li>conditions : An array of additional AQL filter conditions to append to the query.
     * </ul>
     *
     * @return bool True of the value exist in the model.
     *
     * @throws ArangoException If there's an issue with the ArangoDB query execution.
     * @throws BindException If there's an error binding parameters to the AQL query.
     * @throws ReflectionException If a reflection error occurs (e.g., during internal AQL building).
     * @throws DependencyException
     * @throws NotFoundException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function exist( array $init = [] ) :bool
    {
        $values = $init[ Arango::VALUE ] ?? [] ;

        if( !is_array( $values ) )
        {
            $values = toArray( $values ) ;
        }

        $values = unique($values);
        $count  = count($values);

        if ( $count === 0 )
        {
            return false ;
        }

        $bindVars = [];
        $debug    = $init[ Arango::DEBUG ] ?? $this->debug ;
        $match    = match( $init[ Arango::MATCH ] ?? null )
        {
            ArrayComparator::ANY => ArrayComparator::ANY ,
            default              => ArrayComparator::ALL ,
        };

        $query = $this->buildExistQuery( $init , $bindVars ) ;

        if( $debug === true )
        {
            $this->debugQuery( __METHOD__ , $query , $bindVars ) ;
            if( $this->isMock( $init ) )
            {
                return false ;
            }
        }

        $result = $this->getFirstResult( $query , $bindVars ) ;

        return $match === ArrayComparator::ANY ? ( $result > 0 ) : ( $result === $count ) ;
    }

    /**
     * Check if a document exist with the specific key.
     * @param string $key The key index of the object resource to target.
     * @param array $init The optional setting definition.
     * @return bool True of the value exist in the model.
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function existByKey( string $key , array $init = [] ) :bool
    {
        return $this->exist( [ ...$init , Arango::KEY => $key ] ) ;
    }

    /**
     * Check if the given list of values exist in the collection.
     *
     * @param array $values
     * @param string $match
     * Determines the matching strategy when multiple `value`s are provided. Uses constants from `ArrayComparator` enumeration.
     *  <ul>
     *      <li>`ArrayComparator::ALL` (default): Returns `true` only if *all* specified `value`s exist as documents.</li>
     *      <li>`ArrayComparator::ANY`: Returns `true` if *at least one* of the specified `value`s exists as a document.</li>
     *  </ul>
     * @param array $init
     *
     * @return bool
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function existIn( array $values , string $match = ArrayComparator::ALL , array $init = [] ):bool
    {
        return $this->exist( [ ...$init , Arango::VALUE => $values , Arango::MATCH => $match ] ) ;
    }
}