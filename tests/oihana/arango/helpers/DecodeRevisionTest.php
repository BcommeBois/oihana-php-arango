<?php

namespace tests\oihana\arango\helpers;

use DateMalformedStringException;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

use RuntimeException;
use function oihana\arango\helpers\decodeRevision;

final class DecodeRevisionTest extends TestCase
{
    public function testStringRevision():void
    {
        $tests =
        [
            [
                "_rev"  => "_jGPSg12---",
                "date"  => "2025-01-20T15:28:40.830Z",
                "count" => 0
            ],
            [
                "_rev"  => "_kSxG8ii---",
                "date"  => "2025-09-15T09:51:03.753Z",
                "count" => 0
            ],
            [
                "_rev"  => "_jGPSg12---",
                "date"  => "2025-01-20T15:28:40.830Z",
                "count" => 0
            ],
            [
                "_rev"  => "_jLYcfSK---",
                "date"  => "2025-02-05T14:58:20.611Z",
                "count" => 0
            ],
            [
                "_rev"  => "_kS3s2Ke---",
                "date"  => "2025-09-15T17:31:53.416Z",
                "count" => 0
            ],
            [
                "_rev"  => "_kZ1uVZi---",
                "date"  => "2025-10-07T09:11:10.521Z",
                "count" => 0
            ],
            [
                "_rev"  => "_kczrpSy---",
                "date"  => "2025-10-16T14:30:12.045Z",
                "count" => 0
            ],
            [
                "_rev"  => "_keF82f6---",
                "date"  => "2025-10-20T14:21:12.607Z",
                "count" => 0
            ],
        ];

        foreach ( $tests as $test )
        {
            $this->assertSame( [ 'date' => $test['date'] , 'count' => $test['count'] ] , decodeRevision( $test['_rev'] ) );
        }
    }

    // Updated test for invalid formats
    public function testReturnsNullForInvalidFormat(): void
    {
        $this->assertNull( decodeRevision( '' ) ) ;                       // empty string
        $this->assertNull( decodeRevision( 'rev123' ) ) ;                 // missing underscore
        $this->assertNull( decodeRevision( '_!@#' ) ) ;                   // invalid chars
        $this->assertNull( decodeRevision( '_abc' ) ) ;                   // too short
        $this->assertNull( decodeRevision( '_' ) ) ;                      // underscore only
        $this->assertNull( decodeRevision( '_YXNkZg==' ) ) ;              // invalid base64url
        $this->assertNull( decodeRevision( '1234567890' ) ) ;             // no underscore
        $this->assertNull( decodeRevision( str_repeat('a', 12 ) ) ) ; // too long
    }

    // Updated test for decode errors
    public function testReturnsNullOnDecodeError(): void
    {
        $this->assertNull(decodeRevision('_a' ) ) ;                // too short
        $this->assertNull(decodeRevision('_12345678')  ) ;         // too short
    }

    // New test: minimum length
    public function testReturnsNullForTooShortRevision(): void
    {
        $this->assertNull(decodeRevision('_12345678'));  // 9 chars (too short)
        $this->assertNull(decodeRevision('_'));         // 1 char (too short)
    }

    // New test: maximum length
    public function testReturnsNullForTooLongRevision(): void
    {
        $this->assertNull(decodeRevision(str_repeat('a', 12))); // 12 chars
    }

    // New test: invalid characters
    public function testReturnsNullForInvalidChars(): void
    {
        $this->assertNull(decodeRevision('_jGPSg1!---'));       // contains '!'
        $this->assertNull(decodeRevision('_jGPSg1@---'));       // contains '@'
    }

    // New test: valid format but invalid content
    public function testReturnsNullForInvalidContent(): void
    {
        $this->assertNull(decodeRevision('_!@#-----'));  // contains invalid chars
        $this->assertNull(decodeRevision('_123'));       // too short
        $this->assertNull(decodeRevision('1234567890')); // does not start with '_'
        $this->assertNull(decodeRevision('_123456789')); // 10 chars, only digits and underscore
        $this->assertNull(decodeRevision('__________')); // 10 chars, only underscores
        $this->assertNull(decodeRevision('----------')); // 10 chars, only dashes
    }

    // New test: return type check
    public function testReturnType(): void
    {
        $result = decodeRevision('_jGPSg12---');
        $this->assertIsArray($result);
        $this->assertArrayHasKey('date', $result);
        $this->assertArrayHasKey('count', $result);
        $this->assertIsString($result['date']);
        $this->assertIsInt($result['count']);
    }

    // New test: date format check
    public function testDateFormat(): void
    {
        $result = decodeRevision('_jGPSg12---');
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $result['date']);
    }

    // New test: exception handling
    public function testThrowsExceptionWhenThrowable(): void
    {
        $this->expectException( InvalidArgumentException::class);
        decodeRevision(null, true);

        $this->expectException( RuntimeException::class);
        decodeRevision('_!@#', true);

        $this->expectException( RuntimeException::class);
        decodeRevision('_abc', true);
    }

    public function testThrowsOnInvalidLengthWhenThrowable(): void
    {
        $this->expectException( RuntimeException::class );
        $this->expectExceptionMessage( 'decodeRevision("short") failed, invalid revision length.' );
        decodeRevision( 'short', true );
    }

    public function testThrowsOnInvalidFormatWhenThrowable(): void
    {
        $this->expectException( RuntimeException::class );
        $this->expectExceptionMessage( 'decodeRevision("a234567890") failed, invalid revision format.' );
        decodeRevision( 'a234567890', true );
    }

    public function testThrowsOnInvalidContentWhenThrowable(): void
    {
        $this->expectException( RuntimeException::class );
        $this->expectExceptionMessage( 'decodeRevision("_123456789") failed, invalid revision content.' );
        decodeRevision( '_123456789', true );
    }

    public function testThrowsOnInvalidCharacterWhenThrowable(): void
    {
        $this->expectException( RuntimeException::class );
        $this->expectExceptionMessage( 'decodeRevision("_ABCDEF*--") failed, invalid character.' );
        decodeRevision( '_ABCDEF*--', true );
    }

    // New test: count value range
    public function testCountIsWithinValidRange(): void
    {
        $result = decodeRevision('_jGPSg12---');
        $this->assertGreaterThanOrEqual(0, $result['count']);
        $this->assertLessThanOrEqual(1048575, $result['count']); // 2^20 - 1
    }

    // New test: valid date check
    public function testDateIsValid(): void
    {
        $result = decodeRevision('_jGPSg12---');
        $date = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s.v\Z', $result['date']);
        $this->assertInstanceOf( DateTimeImmutable::class, $date);
    }

    /**
     * @throws DateMalformedStringException
     */
    public function testDateIsUtc(): void
    {
        $result = decodeRevision('_jGPSg12---');
        $date = new DateTimeImmutable($result['date']);
        $this->assertSame(0, $date->getOffset()); // UTC offset is always 0
    }
}