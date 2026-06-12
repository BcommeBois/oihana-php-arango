<?php
namespace tests\oihana\arango\migrations\fixtures\ok ;
use oihana\arango\migrations\Migration ;
class Version20260101000000_Alpha extends Migration
{
    public static array $ran = [] ;
    public function description() : string { return 'alpha' ; }
    public function up()   : void { self::$ran[] = 'alpha.up' ; }
    public function down() : void { self::$ran[] = 'alpha.down' ; }
}
