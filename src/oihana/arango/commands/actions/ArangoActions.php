<?php

namespace oihana\arango\commands\actions;

/**
 * The ArangoDB CLI subcommands database.
 */
trait ArangoActions
{
    use ArangoAnalyzersAction ,
        ArangoDoctorAction ,
        ArangoDumpAction ,
        ArangoListCollectionsAction ,
        ArangoListDumpsAction ,
        ArangoMigrateAction ,
        ArangoRestoreAction ,
        ArangoViewsAction ;
}