<?php

namespace oihana\arango\models\traits\aql\facets;

use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\Logic;
use oihana\arango\db\enums\Traversal;
use oihana\enums\Char;
use oihana\exceptions\BindException;
use oihana\exceptions\UnsupportedOperationException;
use org\schema\constants\Prop;

use ReflectionException;

use function oihana\arango\db\functions\arrays\length;
use function oihana\arango\db\functions\strings\contains;

use function oihana\arango\db\operations\aqlFilter;
use function oihana\arango\db\operations\aqlFor;
use function oihana\arango\db\operations\aqlReturn;
use function oihana\arango\db\operators\greaterThan;
use function oihana\arango\db\operators\logicalNot;
use function oihana\core\strings\betweenParentheses;
use function oihana\core\strings\compile;
use function oihana\core\strings\key;
use function oihana\core\strings\predicates;

/**
 * This trait defines all facet helpers in the Model class.
 */
trait HasFacetThesaurus
{
    /**
     * Prepare a thesaurus facet.
     *
     * @param string $key
     * @param mixed $value
     * @param array $binds
     * @param array $facet
     * @param string $doc
     *
     * @return string
     *
     * @throws BindException
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     */
    protected function prepareFacetThesaurus( string $key , mixed $value , array &$binds , array $facet , string $doc ) :string
    {
        $docKey  = AQL::DOC_PREFIX . $key ;
        $fields  = $facet[ AQL::FIELDS ] ?? '_key,name,alternateName' ;
        $edge    = $facet[ AQL::EDGE   ] ?? null ;
        $filter  = [] ;

        $fields = explode( Char::COMMA , $fields ) ;
        $values = explode( Char::COMMA , $value  ) ;

        foreach( $values as $subKey => $s )
        {
            $negative        = $s[0] == Char::HYPHEN ;
            $logicalOperator = $negative ? Logic::AND : Logic::OR ;
            $s               = ltrim( $s , Char::HYPHEN );
            $search          = [] ;
            foreach( $fields as $field )
            {
                $predicate = contains
                (
                    key( $field , AQL::DOC_PREFIX . $key ) ,
                    $this->bind( $s , $binds , $key . $subKey ) ,
                ) ;
                $search[] = $negative ? logicalNot( $predicate ) : $predicate ;
            }
            $filter[] = betweenParentheses( predicates( $search , $logicalOperator ) );
        }

        //  LENGTH( FOR doc_.$key IN INBOUND $doc $edge FILTER ...filter RETURN doc_$key._key) > 0
        return greaterThan( length
        ([
            aqlFor    ( [ AQL::DOC_REF => $docKey , AQL::IN => compile( [ Traversal::INBOUND , $doc , $edge ] ) ] )    ,
            aqlFilter ( $filter ) ,
            aqlReturn ( key( Prop::_KEY , $docKey ) )
        ]) , 0 ) ;
    }
}