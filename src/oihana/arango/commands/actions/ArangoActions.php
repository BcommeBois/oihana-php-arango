<?php

namespace oihana\arango\commands\actions;

/**
 * The ArangoDB CLI subcommands database.
 */
trait ArangoActions
{
    use ArangoDoctorAction ,
        ArangoDumpAction ,
        ArangoListCollectionsAction ,
        ArangoListDumpsAction ,
        ArangoRestoreAction ,
        ArangoViewsAction ;
}