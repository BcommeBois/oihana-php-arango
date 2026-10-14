<?php

namespace oihana\arango\commands;

use DI\DependencyException;
use DI\NotFoundException;
use InvalidArgumentException;

use oihana\exceptions\BindException;
use oihana\exceptions\UnsupportedOperationException;
use oihana\reflect\exceptions\ConstantException;
use org\schema\constants\Schema;

use oihana\arango\clients\aql\AqlQuery;
use oihana\arango\commands\enums\PreservedCollection;
use oihana\arango\db\enums\AQL;
use oihana\arango\enums\Arango;
use oihana\arango\models\traits\aql\FilterTrait;

use oihana\exceptions\ValidationException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionException;

/**
 * The compiled rule of one `[arango.preserves.<collection>]` entry, and the AQL
 * queries the `preserve` and `reinject` actions run with it.
 *
 * The rule is written in the `?filter=` grammar and compiled by the very compiler
 * of the models ({@see FilterTrait}) — no free AQL ever reaches a query :
 *
 *  - the **values** are bind parameters ;
 *  - the **keys** are written into the AQL, but only those the rule declares in
 *    its `filters` list, as `AQL::FILTERS` does on a model ;
 *  - the **operators**, the groups and every shape the compiler cannot read are
 *    refused by the compiler itself ;
 *  - the **collections** are bind parameters, and the queries are written here.
 *
 * A refusal of the compiler — a {@see ValidationException} meant for an HTTP
 * answer — is turned into an {@see InvalidArgumentException} naming the collection.
 *
 * @package oihana\arango\commands
 * @since 1.10.0
 * @author Marc Alcaraz
 */
final class PreserveFilter
{
    /**
     * Compiles the rule of a collection.
     *
     * @param string $collection The collection whose documents the rule names.
     * @param array $filters The keys the filter may name, and their `FilterType`.
     * @param array $filter The decoded filter : a segment or a group.
     *
     * @throws BindException
     * @throws ConstantException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     */
    public function __construct( string $collection , array $filters , array $filter )
    {
        $this->collection = $collection ;
        $this->backup     = PreservedCollection::backupOf( $collection ) ;
        $this->filters    = $filters ;

        $this->initializeQueryID( null ) ;

        $binds = [] ;

        try
        {
            $condition = $this->prepareFilter( [ Arango::FILTER => $filter ] , $binds , AQL::DOC ) ;
        }
        catch( ValidationException $exception )
        {
            throw new InvalidArgumentException( sprintf( "The preserve rule of '%s' is refused : %s" , $collection , $exception->getMessage() ) , 0 , $exception ) ;
        }

        if( $condition === null || $condition === '' )
        {
            throw new InvalidArgumentException( sprintf( "The preserve rule of '%s' constrains nothing : it would set the whole collection aside." , $collection ) ) ;
        }

        $this->condition = $condition ;
        $this->binds     = $binds ;
    }

    use FilterTrait ;

    /**
     * The bind parameter naming the backup collection in the queries.
     */
    public const string BACKUP_BIND = 'backup' ;

    /**
     * The bind parameter naming the preserved collection in the queries.
     */
    public const string COLLECTION_BIND = 'collection' ;

    /**
     * The name of the backup collection, `<collection>__preserved`.
     */
    public readonly string $backup ;

    /**
     * The bind parameters of the compiled condition.
     */
    public readonly array $binds ;

    /**
     * The preserved collection.
     */
    public readonly string $collection ;

    /**
     * The compiled AQL condition, written against `doc`.
     */
    public readonly string $condition ;

    /**
     * No edge : a rule reads the fields of the document itself, never a relation.
     */
    protected ?array $edges = null ;

    /**
     * No projection : nothing gates the keys but the `filters` of the rule.
     */
    protected ?array $fields = null ;

    /**
     * No join : a rule reads the fields of the document itself, never a relation.
     */
    protected ?array $joins = null ;

    /**
     * No ArangoSearch view : a rule filters the collection itself.
     */
    protected ?array $view = null ;

    /**
     * The query counting the documents of the backup.
     *
     * @return AqlQuery
     */
    public function backupCountQuery() :AqlQuery
    {
        return new AqlQuery
        (
            sprintf( 'RETURN LENGTH( @@%s )' , self::BACKUP_BIND ) ,
            [ '@' . self::BACKUP_BIND => $this->backup ] ,
        ) ;
    }

    /**
     * The query copying the documents the rule names into the backup, and
     * returning how many it copied.
     *
     * @return AqlQuery
     */
    public function copyQuery() :AqlQuery
    {
        return new AqlQuery
        (
            sprintf
            (
                "FOR %1\$s IN @@%2\$s FILTER %3\$s INSERT UNSET( %1\$s , '%4\$s' , '%5\$s' ) INTO @@%6\$s COLLECT WITH COUNT INTO total RETURN total" ,
                AQL::DOC , self::COLLECTION_BIND , $this->condition , Schema::_ID , Schema::_REV , self::BACKUP_BIND
            ) ,
            [ ...$this->binds , '@' . self::COLLECTION_BIND => $this->collection , '@' . self::BACKUP_BIND => $this->backup ] ,
        ) ;
    }

    /**
     * The query counting the documents of the collection the rule names.
     *
     * @return AqlQuery
     */
    public function countQuery() :AqlQuery
    {
        return new AqlQuery
        (
            sprintf( 'RETURN LENGTH( FOR %1$s IN @@%2$s FILTER %3$s RETURN 1 )' , AQL::DOC , self::COLLECTION_BIND , $this->condition ) ,
            [ ...$this->binds , '@' . self::COLLECTION_BIND => $this->collection ] ,
        ) ;
    }

    /**
     * The query copying the whole backup back into the collection, and
     * returning how many documents it put back.
     *
     * @return AqlQuery
     */
    public function putBackQuery() :AqlQuery
    {
        return new AqlQuery
        (
            sprintf
            (
                "FOR %1\$s IN @@%2\$s INSERT UNSET( %1\$s , '%3\$s' , '%4\$s' ) INTO @@%5\$s COLLECT WITH COUNT INTO total RETURN total" ,
                AQL::DOC , self::BACKUP_BIND , Schema::_ID , Schema::_REV , self::COLLECTION_BIND
            ) ,
            [ '@' . self::BACKUP_BIND => $this->backup , '@' . self::COLLECTION_BIND => $this->collection ] ,
        ) ;
    }

    /**
     * The query removing from the collection the documents the rule names, and
     * returning how many it removed.
     *
     * @return AqlQuery
     */
    public function removeQuery() :AqlQuery
    {
        return new AqlQuery
        (
            sprintf
            (
                'FOR %1$s IN @@%2$s FILTER %3$s REMOVE %1$s IN @@%2$s COLLECT WITH COUNT INTO total RETURN total' ,
                AQL::DOC , self::COLLECTION_BIND , $this->condition
            ) ,
            [ ...$this->binds , '@' . self::COLLECTION_BIND => $this->collection ] ,
        ) ;
    }
}
