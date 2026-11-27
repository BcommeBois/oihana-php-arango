<?php

namespace tests\oihana\arango\auth;

use oihana\arango\enums\Arango;

use stdClass;

use tests\oihana\arango\auth\mocks\FakeDocuments;
use tests\oihana\arango\auth\mocks\FakeEdges;

/**
 * Fixture graphs for the Casbin-sync tests : edge relations and a permissions
 * model that answer the reads the sync makes, from plain arrays.
 *
 * The host class provides `documents( string $collection ) :FakeDocuments` and
 * `edges( string $collection ) :FakeEdges`.
 *
 * @package tests\oihana\arango\auth
 * @author  Marc Alcaraz
 */
trait GraphFixturesTrait
{
    /**
     * An edge relation that answers `doc._from IN @fromIds` from a fixture graph.
     *
     * @param string                            $collection The edge collection.
     * @param array<string, array<int, string>> $targets    The `_to` ids, by `_from` id.
     *
     * @return FakeEdges
     */
    protected function edgesFrom( string $collection , array $targets ) :FakeEdges
    {
        $edges = $this->edges( $collection ) ;

        $edges->listResolver = function( array $init ) use ( $targets ) :array
        {
            $rows = [] ;

            foreach( $init[ Arango::BINDS ][ 'fromIds' ] ?? [] as $from )
            {
                foreach( $targets[ $from ] ?? [] as $to )
                {
                    $rows[] = $this->graphEdge( $from , $to ) ;
                }
            }

            return $rows ;
        } ;

        return $edges ;
    }

    /**
     * An edge relation over a fixture list of edges, answering the two reads the
     * sync makes : `doc._from IN @fromIds` and `doc._to == @policyId`.
     *
     * @param string                              $collection The edge collection.
     * @param array<int, array{0:string,1:string}> $pairs      The edges, as `[ _from , _to ]`.
     *
     * @return FakeEdges
     */
    protected function edgesOf( string $collection , array $pairs ) :FakeEdges
    {
        $edges = $this->edges( $collection ) ;

        $edges->listResolver = function( array $init ) use ( $pairs ) :array
        {
            $binds = $init[ Arango::BINDS ] ?? [] ;
            $rows  = [] ;

            foreach( $pairs as [ $from , $to ] )
            {
                $matches = isset( $binds[ 'fromIds' ] )
                         ? in_array( $from , $binds[ 'fromIds' ] , true )
                         : ( $binds[ 'policyId' ] ?? null ) === $to ;

                if( $matches )
                {
                    $rows[] = $this->graphEdge( $from , $to ) ;
                }
            }

            return $rows ;
        } ;

        return $edges ;
    }

    /**
     * A permissions model that gets a permission by its key and lists the ones
     * `doc._key IN @permissionKeys` names.
     *
     * @param array<string, stdClass> $byKey The permissions, by `_key`.
     *
     * @return FakeDocuments
     */
    protected function permissionsByKey( array $byKey ) :FakeDocuments
    {
        $permissions = $this->documents( 'permissions' ) ;
        $permissions->getResults = $byKey ;

        $permissions->listResolver = function( array $init ) use ( $byKey ) :array
        {
            $rows = [] ;

            foreach( $init[ Arango::BINDS ][ 'permissionKeys' ] ?? [] as $key )
            {
                if( isset( $byKey[ $key ] ) )
                {
                    $rows[] = $byKey[ $key ] ;
                }
            }

            return $rows ;
        } ;

        return $permissions ;
    }

    /**
     * One edge, as the sync reads it.
     *
     * @param string $from The `_from` id.
     * @param string $to   The `_to` id.
     *
     * @return stdClass
     */
    private function graphEdge( string $from , string $to ) :stdClass
    {
        $edge        = new stdClass() ;
        $edge->_from = $from ;
        $edge->_to   = $to ;

        return $edge ;
    }
}
