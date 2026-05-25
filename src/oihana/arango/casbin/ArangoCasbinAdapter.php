<?php

namespace oihana\arango\casbin;

use Closure;
use DateInvalidTimeZoneException;
use DateMalformedStringException;
use Exception;
use ReflectionException;
use Throwable;

use DI\DependencyException;
use DI\NotFoundException;

use Casbin\Exceptions\InvalidFilterTypeException;
use Casbin\Model\Model;
use Casbin\Persist\Adapter;
use Casbin\Persist\AdapterHelper;
use Casbin\Persist\Adapters\Filter;
use Casbin\Persist\BatchAdapter;
use Casbin\Persist\FilteredAdapter;
use Casbin\Persist\UpdatableAdapter;

use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\enums\Arango;
use oihana\arango\models\Documents;
use oihana\exceptions\http\Error409;
use oihana\exceptions\BindException;
use oihana\exceptions\UnsupportedOperationException;
use oihana\models\interfaces\DocumentsModel;
use oihana\reflect\exceptions\ConstantException;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;

/**
 * ArangoDB adapter for Casbin using composition with a Documents model.
 *
 * Delegates all CRUD operations to an `oihana\models\interfaces\Documents`
 * instance configured for the `rbac` collection. This ensures consistent
 * query building, bind variable management, and alignment with the
 * project's existing model architecture.
 *
 * The adapter implements all Casbin persistence interfaces:
 * - Adapter       : basic load/save/add/remove policy
 * - BatchAdapter  : batch add/remove policies
 * - FilteredAdapter : filtered policy loading
 * - UpdatableAdapter : policy updates
 *
 * @package oihana\arango\casbin
 * @author  Marc Alcaraz
 */
class ArangoCasbinAdapter implements Adapter, BatchAdapter, FilteredAdapter, UpdatableAdapter
{
    /**
     * Creates a new ArangoCasbinAdapter instance.
     *
     * @param Documents|DocumentsModel $model  The Documents model for the rbac collection.
     * @param LoggerInterface|null    $logger An optional PSR logger.
     */
    public function __construct( Documents|DocumentsModel $model , ?LoggerInterface $logger = null )
    {
        $this->model  = $model  ;
        $this->logger = $logger ;
    }

    use AdapterHelper;

    /**
     * The Casbin policy field keys.
     */
    public const array KEYS = [ 'ptype' , 'v0' , 'v1' , 'v2' , 'v3' , 'v4' , 'v5' ] ;

    /**
     * Whether the loaded policies have been filtered.
     */
    private bool $filtered = false ;

    /**
     * The Documents model for the rbac collection.
     */
    protected Documents|DocumentsModel $model ;

    /**
     * The optional logger.
     */
    protected ?LoggerInterface $logger ;

    // ----------- Adapter

    /**
     * Loads all policy rules from the storage.
     *
     * @param Model $model
     * @return void
     *
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws DependencyException
     * @throws NotFoundException
     * @throws ArangoException
     * @throws UnsupportedOperationException
     * @throws ConstantException
     */
    public function loadPolicy( Model $model ): void
    {
        $rows = $this->model->list([ Arango::RAW => true ]) ;

        foreach ( $rows as $row )
        {
            $this->loadPolicyFromRow( (array) $row , $model ) ;
        }
    }

    /**
     * Saves all policy rules to the storage.
     * Clears the collection first, then inserts all rules.
     *
     * @param Model $model
     *
     * @return void
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DateInvalidTimeZoneException
     * @throws DateMalformedStringException
     * @throws DependencyException
     * @throws Error409
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     * @throws UnsupportedOperationException
     */
    public function savePolicy( Model $model ): void
    {
        $this->model->truncate() ;

        foreach ( $model['p'] as $ptype => $ast )
        {
            foreach ( $ast->policy as $rule )
            {
                $this->insertPolicyLine( $ptype , $rule ) ;
            }
        }

        foreach ( $model['g'] as $ptype => $ast )
        {
            foreach ( $ast->policy as $rule )
            {
                $this->insertPolicyLine( $ptype , $rule ) ;
            }
        }
    }

    /**
     * Adds a single policy rule to the storage.
     *
     * @param string $sec
     * @param string $ptype
     * @param array $rule
     *
     * @return void
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DateInvalidTimeZoneException
     * @throws DateMalformedStringException
     * @throws DependencyException
     * @throws Error409
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     * @throws UnsupportedOperationException
     */
    public function addPolicy( string $sec , string $ptype , array $rule ): void
    {
        $this->insertPolicyLine( $ptype , $rule ) ;
    }

    /**
     * Removes a single policy rule from the storage.
     *
     * @param string $sec
     * @param string $ptype
     * @param array $rule
     *
     * @return void
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws Throwable
     */
    public function removePolicy( string $sec , string $ptype , array $rule ): void
    {
        $this->logger?->debug( __METHOD__ . ' sec:' . $sec . ' ptype:' . $ptype . ' rule:' . json_encode( $rule ) ) ;

        $conditions = $this->buildConditions( $ptype , $rule ) ;

        try
        {
            // `Documents::delete()` requires `Arango::VALUE` to be non-empty —
            // passing only `Arango::CONDITIONS` no-ops silently because the
            // AQL builder exits early. We use the same "list then delete by
            // _key" pattern as `_removeFilteredPolicy`: resolve the matching
            // rows first, then delete them by their `_key`.
            $rows = $this->model->list
            ([
                Arango::RAW        => true ,
                Arango::CONDITIONS => $conditions ,
            ]) ;

            $keysToDelete = [] ;

            foreach ( $rows as $row )
            {
                $row = (array) $row ;
                $key = $row[ '_key' ] ?? null ;

                if( $key !== null )
                {
                    $keysToDelete[] = (string) $key ;
                }
            }

            if( !empty( $keysToDelete ) )
            {
                $this->model->delete
                ([
                    Arango::VALUE => $keysToDelete ,
                ]) ;
            }
        }
        catch ( Exception $e )
        {
            $this->logger?->warning( __METHOD__ . ' failed: ' . $e->getMessage() ) ;
        }
    }

    /**
     * Removes policy rules that match the filter from the storage.
     *
     * @param string $sec
     * @param string $ptype
     * @param int $fieldIndex
     * @param string ...$fieldValues
     *
     * @return void
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ConstantException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     * @throws UnsupportedOperationException
     */
    public function removeFilteredPolicy( string $sec , string $ptype , int $fieldIndex , string ...$fieldValues ): void
    {
        $this->_removeFilteredPolicy( $sec , $ptype , $fieldIndex , ...$fieldValues ) ;
    }

    // ----------- BatchAdapter

    /**
     * Adds multiple policy rules to the storage.
     *
     * @param string $sec
     * @param string $ptype
     * @param array $rules
     *
     * @return void
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DateInvalidTimeZoneException
     * @throws DateMalformedStringException
     * @throws DependencyException
     * @throws Error409
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     * @throws UnsupportedOperationException
     */
    public function addPolicies( string $sec , string $ptype , array $rules ): void
    {
        foreach ( $rules as $rule )
        {
            $this->insertPolicyLine( $ptype , $rule ) ;
        }
    }

    /**
     * Removes multiple policy rules from the storage.
     *
     * @param string $sec
     * @param string $ptype
     * @param array $rules
     *
     * @return void
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws Throwable
     */
    public function removePolicies( string $sec , string $ptype , array $rules ): void
    {
        foreach ( $rules as $rule )
        {
            $this->removePolicy( $sec , $ptype , $rule ) ;
        }
    }

    // ----------- FilteredAdapter

    /**
     * Returns whether the loaded policy has been filtered.
     *
     * @inheritDoc
     */
    public function isFiltered(): bool
    {
        return $this->filtered ;
    }

    /**
     * Sets the filtered flag.
     */
    public function setFiltered( bool $filtered ): void
    {
        $this->filtered = $filtered ;
    }

    /**
     * Loads only policy rules that match the filter.
     *
     * Supported filter types:
     * - `Filter` object : uses `$filter->p` as field names and `$filter->g` as values
     * - `Closure`       : receives `($this->model, self::KEYS, &$rows)` for custom loading
     * - `array`         : associative array of field => value conditions
     *
     * @inheritDoc
     *
     * @param Model $model
     * @param mixed $filter
     *
     * @throws BindException
     * @throws ConstantException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws InvalidFilterTypeException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     * @throws ArangoException
     */
    public function loadFilteredPolicy( Model $model , mixed $filter ): void
    {
        $rows = [] ;

        if ( $filter instanceof Filter )
        {
            $conditions = [] ;

            foreach ( $filter->p as $k => $v )
            {
                if ( isset( $filter->g[ $k ] ) )
                {
                    $conditions[] = [ $v , $filter->g[ $k ] ] ;
                }
            }

            $rows = $this->model->list
            ([
                Arango::RAW        => true ,
                Arango::CONDITIONS => $this->buildFilterConditions( $conditions ) ,
            ]) ;
        }
        elseif ( $filter instanceof Closure )
        {
            $filter( $this->model , self::KEYS , $rows ) ;
        }
        elseif ( is_array( $filter ) )
        {
            $rows = $this->model->list
            ([
                Arango::RAW        => true ,
                Arango::CONDITIONS => $this->buildConditionsFromArray( $filter ) ,
            ]) ;
        }
        else
        {
            throw new InvalidFilterTypeException( 'Invalid filter type' ) ;
        }

        foreach ( $rows as $row )
        {
            $this->loadPolicyFromRow( (array) $row , $model ) ;
        }

        $this->setFiltered( true ) ;
    }

    // ----------- UpdatableAdapter

    /**
     * Updates a policy rule in the storage.
     *
     * @param string $sec
     * @param string $ptype
     * @param array $oldRule
     * @param array $newPolicy
     *
     * @return void
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     * @throws UnsupportedOperationException
     * @throws DateInvalidTimeZoneException
     * @throws DateMalformedStringException
     * @throws Error409
     */
    public function updatePolicy( string $sec , string $ptype , array $oldRule , array $newPolicy ): void
    {
        $conditions = $this->buildConditions( $ptype , $oldRule ) ;

        $doc = [ 'ptype' => $ptype ] ;

        foreach ( $newPolicy as $key => $value )
        {
            $doc[ 'v' . $key ] = $value ;
        }

        $this->model->update
        ([
            Arango::DOC        => $doc        ,
            Arango::CONDITIONS => $conditions ,
        ]) ;
    }

    /**
     * Updates multiple policy rules in the storage.
     *
     * @param string $sec
     * @param string $ptype
     * @param array $oldRules
     * @param array $newRules
     *
     * @return void
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DateInvalidTimeZoneException
     * @throws DateMalformedStringException
     * @throws DependencyException
     * @throws Error409
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     * @throws UnsupportedOperationException
     */
    public function updatePolicies( string $sec , string $ptype , array $oldRules , array $newRules ): void
    {
        foreach ( $oldRules as $i => $oldRule )
        {
            $this->updatePolicy( $sec , $ptype , $oldRule , $newRules[ $i ] ) ;
        }
    }

    /**
     * Deletes old rules matching the filter and adds new rules.
     *
     * @param string $sec
     * @param string $ptype
     * @param array $newPolicies
     * @param int $fieldIndex
     * @param string ...$fieldValues
     *
     * @return array
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DateInvalidTimeZoneException
     * @throws DateMalformedStringException
     * @throws DependencyException
     * @throws Error409
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     * @throws UnsupportedOperationException
     */
    public function updateFilteredPolicies
    (
        string $sec ,
        string $ptype ,
        array  $newPolicies ,
        int    $fieldIndex  ,
        string ...$fieldValues
    ): array
    {
        $oldRules = $this->_removeFilteredPolicy( $sec , $ptype , $fieldIndex , ...$fieldValues ) ;
        $this->addPolicies( $sec , $ptype , $newPolicies ) ;
        return $oldRules ;
    }

    // ----------- Protected / Private

    /**
     * Inserts a single policy line as a document in the rbac collection.
     * @param string $ptype
     * @param array $rule
     * @return void
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DateInvalidTimeZoneException
     * @throws DateMalformedStringException
     * @throws DependencyException
     * @throws Error409
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     * @throws UnsupportedOperationException
     */
    protected function insertPolicyLine( string $ptype , array $rule ): void
    {
        $doc = [ 'ptype' => $ptype ] ;

        foreach ( $rule as $key => $value )
        {
            $doc[ 'v' . $key ] = $value ;
        }

        $this->model->insert([ Arango::DOC => $doc ]) ;
    }

    /**
     * Loads a policy line from a raw document row into the Casbin model.
     */
    protected function loadPolicyFromRow( array $row , Model $model ): void
    {
        $filtered = array_filter
        (
            $row ,
            fn ( $val , $key ) => in_array( $key , self::KEYS ) && $val !== '' && $val !== null ,
            ARRAY_FILTER_USE_BOTH
        ) ;

        $line = implode( ', ' , $filtered ) ;

        if ( $line !== '' )
        {
            $this->loadPolicyLine( trim( $line ) , $model ) ;
        }
    }

    /**
     * Builds AQL filter conditions from a ptype and a Casbin rule array.
     *
     * Returns an array of AQL condition strings like :
     * `['doc.ptype == "p"', 'doc.v0 == "role:admin"', ...]`
     *
     * @param string $ptype The policy type (p, g, etc.)
     * @param array  $rule  The rule values [v0, v1, ...]
     * @return array<string>
     */
    protected function buildConditions( string $ptype , array $rule ): array
    {
        $conditions = [] ;
        $conditions[] = 'doc.ptype == "' . addslashes( $ptype ) . '"' ;

        foreach ( $rule as $key => $value )
        {
            $conditions[] = 'doc.v' . $key . ' == "' . addslashes( $value ) . '"' ;
        }

        return $conditions ;
    }

    /**
     * Builds AQL conditions from Filter pairs [[field, value], ...].
     *
     * @param array $pairs
     * @return array<string>
     */
    protected function buildFilterConditions( array $pairs ): array
    {
        $conditions = [] ;

        foreach ( $pairs as [ $field , $value ] )
        {
            $conditions[] = 'doc.' . $field . ' == "' . addslashes( $value ) . '"' ;
        }

        return $conditions ;
    }

    /**
     * Builds AQL conditions from an associative array.
     *
     * @param array $filter Associative array [ 'ptype' => 'p', 'v0' => 'role:admin', ... ]
     * @return array<string>
     */
    protected function buildConditionsFromArray( array $filter ): array
    {
        $conditions = [] ;

        foreach ( $filter as $field => $value )
        {
            $conditions[] = 'doc.' . $field . ' == "' . addslashes( $value ) . '"' ;
        }

        return $conditions ;
    }

    /**
     * Removes filtered policies and returns the removed rules.
     *
     * @param string $sec
     * @param string $ptype
     * @param int $fieldIndex
     * @param string ...$fieldValues
     *
     * @return array The removed rules
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ConstantException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     * @throws UnsupportedOperationException
     */
    protected function _removeFilteredPolicy( string $sec , string $ptype , int $fieldIndex , string ...$fieldValues ): array
    {
        $conditions = [] ;
        $conditions[] = 'doc.ptype == "' . addslashes( $ptype ) . '"' ;

        foreach ( range( 0 , 5 ) as $i )
        {
            if
            (
                   $fieldIndex <= $i
                && $i < $fieldIndex + count( $fieldValues )
                && $fieldValues[ $i - $fieldIndex ] !== ''
            )
            {
                $conditions[] = 'doc.v' . $i . ' == "' . addslashes( $fieldValues[ $i - $fieldIndex ] ) . '"' ;
            }
        }

        // List the matching rules before deleting them
        $rows = $this->model->list
        ([
            Arango::RAW        => true ,
            Arango::CONDITIONS => $conditions ,
        ]) ;

        $removedRules = [] ;
        $keysToDelete = [] ;

        foreach ( $rows as $row )
        {
            $row = (array) $row ;
            $key = $row[ '_key' ] ?? null ;

            if( $key !== null )
            {
                $keysToDelete[] = (string) $key ;
            }

            // Keep only the v* slots and drop ptype + Arango system fields
            // (_key/_rev/_id) — otherwise filterRule() would leak _key into
            // the returned rule and break callers that compare against the
            // canonical [v0..v5] shape (notably updateFilteredPolicies).
            $rule = array_intersect_key( $row , array_flip( self::KEYS ) ) ;
            unset( $rule['ptype'] ) ;

            $removedRules[] = $this->filterRule( $rule ) ;
        }

        // Delete matching documents by _key. `Documents::delete()` requires
        // `Arango::VALUE` to be non-empty — passing only `Arango::CONDITIONS`
        // no-ops silently because the AQL builder exits early. The correct
        // pattern is "list then delete by _key": we resolved the matching
        // keys above, feed them in here.
        if( !empty( $keysToDelete ) )
        {
            $this->model->delete
            ([
                Arango::VALUE => $keysToDelete ,
            ]) ;
        }

        return $removedRules ;
    }

    /**
     * Filters trailing empty values from a rule array.
     *
     * @param array $rule
     * @return array
     */
    public function filterRule( array $rule ): array
    {
        $rule = array_values( $rule ) ;
        $i = count( $rule ) - 1 ;

        for ( ; $i >= 0 ; $i-- )
        {
            if ( $rule[ $i ] !== '' && $rule[ $i ] !== null )
            {
                break ;
            }
        }

        return array_slice( $rule , 0 , $i + 1 ) ;
    }
}
