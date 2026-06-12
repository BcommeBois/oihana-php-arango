<?php
namespace tests\oihana\arango\migrations\fixtures\live ;
use oihana\arango\migrations\Migration ;
/** Rename contacts.tel → contacts.phone (and back on rollback). */
class Version20260601000000_RenamePhone extends Migration
{
    public function description() : string { return 'rename contacts.tel to phone' ; }
    public function up()   : void { $this->renameField( 'contacts' , 'tel' , 'phone' ) ; }
    public function down() : void { $this->renameField( 'contacts' , 'phone' , 'tel' ) ; }
}
