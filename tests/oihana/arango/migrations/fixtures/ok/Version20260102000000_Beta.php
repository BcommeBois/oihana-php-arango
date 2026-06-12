<?php
namespace tests\oihana\arango\migrations\fixtures\ok ;
use oihana\arango\migrations\Migration ;
class Version20260102000000_Beta extends Migration
{
    public static array $ran = [] ;
    public function description() : string { return 'beta' ; }
    public function up()   : void { self::$ran[] = 'beta.up' ; }
    public function down() : void { self::$ran[] = 'beta.down' ; }
}
