<?php

namespace oihana\arango\commands\traits;

use DateInvalidOperationException;
use DateTime;
use DateTimeImmutable;
use Exception;
use InvalidArgumentException;

use oihana\files\exceptions\DirectoryException;
use org\iso\Iso8601Duration;

use oihana\arango\commands\enums\ArchivePattern;
use oihana\arango\commands\options\RetentionOption;
use oihana\arango\commands\rotation\Archive;
use oihana\arango\commands\rotation\RotationPolicy;

use oihana\files\enums\FindFilesOption;

use Symfony\Component\Console\Style\SymfonyStyle;

use function oihana\files\findFiles;
use function org\iso\helpers\isIso8601Duration;

/**
 * Archive rotation for the `dump` action — **opt-in**.
 *
 * Old dump archives are pruned according to a retention policy
 * ({@see RetentionOption}); without a configured policy (and without `--prune`)
 * nothing is ever deleted.
 *
 * The unit of rotation is the **bucket**: the archive *suffix signature*
 * (`{database}[-partial][-{label}]`, i.e. the file name minus the leading ISO
 * date and the extension). Archives of the same nature rotate together.
 *
 * Per bucket: keep the `keep` most recent, and/or those younger than `max_age`
 * (an ISO 8601 duration). When both are set the rule is **conservative** — an
 * archive is deleted only if it is *both* beyond `keep` **and** older than
 * `max_age`. A global `max_total` size cap is applied last. Safety rails: at
 * least one archive is always kept per bucket, and the freshly created archive
 * is never pruned.
 */
trait ArangoRotationTrait
{
    /**
     * Plans the rotation: returns the archive paths to delete.
     *
     * Pure (no I/O, no clock): the caller supplies the resolved policy.
     *
     * @param array<int,Archive> $archives
     * @param RotationPolicy     $policy
     * @param string|null        $current The freshly created archive, never deleted.
     * @return array<int,string> The paths to delete.
     */
    public function planRotation( array $archives , RotationPolicy $policy , ?string $current = null ) :array
    {
        // Group by bucket, newest first.
        $byBucket = [] ;
        foreach( $archives as $archive )
        {
            $byBucket[ $archive->bucket ][] = $archive ;
        }
        foreach( $byBucket as &$list )
        {
            usort( $list , static fn( Archive $a , Archive $b ) => $b->date <=> $a->date ) ;
        }
        unset( $list ) ;

        $delete = [] ; // path => Archive

        foreach( $byBucket as $bucket => $list )
        {
            $bucketKeep = isset( $policy->buckets[ $bucket ] ) ? max( 0 , (int) $policy->buckets[ $bucket ] ) : $policy->keep ;

            foreach( $list as $rank => $archive )
            {
                if( $rank === 0 || ( $current !== null && $archive->path === $current ) )
                {
                    continue ; // never the newest of a bucket (floor ≥ 1), never the current archive
                }

                $tooMany = $bucketKeep !== null && $rank >= $bucketKeep ;
                $tooOld  = $policy->cutoff !== null && $archive->date < $policy->cutoff ;

                $flag = match( true )
                {
                    $bucketKeep !== null && $policy->cutoff !== null => $tooMany && $tooOld , // conservative
                    $bucketKeep !== null                            => $tooMany ,
                    $policy->cutoff !== null                        => $tooOld ,
                    default                                         => false ,
                } ;

                if( $flag )
                {
                    $delete[ $archive->path ] = $archive ;
                }
            }
        }

        // Global disk guard, applied last.
        if( $policy->maxTotalBytes !== null )
        {
            $this->applyMaxTotal( $archives , $delete , $policy->maxTotalBytes , $current ) ;
        }

        return array_values( array_map( static fn( Archive $archive ) => $archive->path , $delete ) ) ;
    }

    /**
     * Enumerates the dump directory and prunes (or lists, on a dry run) the
     * archives the policy designates for deletion.
     *
     * @param string $directory The dump directory.
     * @param RotationPolicy $policy The resolved policy ({@see resolveRetentionPolicy()}).
     * @param string|null $current The freshly created archive, never pruned.
     * @param bool $dryRun List only, delete nothing.
     * @param SymfonyStyle $io
     *
     * @return int The number of archives deleted (or that would be).
     *
     * @throws DirectoryException
     */
    public function pruneDumps( string $directory , RotationPolicy $policy , ?string $current , bool $dryRun , SymfonyStyle $io ) :int
    {
        $files = findFiles
        (
            $directory ,
            [
                FindFilesOption::PATTERN => ArchivePattern::REGEXP ,
                FindFilesOption::FILTER  => fn( $file ) => $file->getFilename() ,
            ]
        ) ;

        $archives = [] ;
        foreach( $files as $name )
        {
            $path       = $directory . DIRECTORY_SEPARATOR . $name ;
            $archives[] = new Archive
            ([
                Archive::PATH   => $path ,
                Archive::BUCKET => $this->archiveBucket( $name ) ,
                Archive::DATE   => $this->archiveDate( $name ) ,
                Archive::SIZE   => filesize( $path ) ?: 0 ,
            ]) ;
        }

        $toDelete = $this->planRotation( $archives , $policy , $current ) ;

        if( $toDelete === [] )
        {
            $io->text( 'Rotation: nothing to prune.' ) ;
            return 0 ;
        }

        foreach( $toDelete as $path )
        {
            if( $dryRun )
            {
                $io->text( 'Rotation (dry-run) would delete: ' . basename( $path ) ) ;
            }
            else
            {
                @unlink( $path ) ;
                $io->text( 'Rotation deleted: ' . basename( $path ) ) ;
            }
        }

        $io->text( sprintf( 'Rotation: %d archive(s) %s.' , count( $toDelete ) , $dryRun ? 'would be pruned' : 'pruned' ) ) ;

        return count( $toDelete ) ;
    }

    /**
     * Resolves the `[arango.dump.retention]` config into a pure policy for
     * {@see planRotation()} (parses the ISO 8601 `max_age` into a cutoff and the
     * `max_total` size into bytes).
     *
     * @param array $retention The `[arango.dump.retention]` config section.
     * @return RotationPolicy
     *
     * @throws DateInvalidOperationException
     */
    public function resolveRetentionPolicy( array $retention ) :RotationPolicy
    {
        $keep    = isset( $retention[ RetentionOption::KEEP ] ) ? max( 0 , (int) $retention[ RetentionOption::KEEP ] ) : null ;
        $buckets = is_array( $retention[ RetentionOption::BUCKETS ] ?? null ) ? $retention[ RetentionOption::BUCKETS ] : [] ;

        $cutoff = null ;
        $maxAge = $retention[ RetentionOption::MAX_AGE ] ?? null ;
        if( is_string( $maxAge ) && $maxAge !== '' )
        {
            if( !isIso8601Duration( $maxAge ) )
            {
                throw new InvalidArgumentException( sprintf( 'Invalid retention max_age "%s": expected an ISO 8601 duration (e.g. P30D, P6M, P1Y).' , $maxAge ) ) ;
            }
            $cutoff = DateTimeImmutable::createFromInterface( new Iso8601Duration( $maxAge )->subtractFrom( new DateTime() ) ) ;
        }

        $maxTotal      = $retention[ RetentionOption::MAX_TOTAL ] ?? null ;
        $maxTotalBytes = ( $maxTotal === null || $maxTotal === '' ) ? null : $this->parseRetentionSize( $maxTotal ) ;

        return new RotationPolicy
        ([
            RotationPolicy::KEEP            => $keep ,
            RotationPolicy::BUCKETS         => $buckets ,
            RotationPolicy::CUTOFF          => $cutoff ,
            RotationPolicy::MAX_TOTAL_BYTES => $maxTotalBytes ,
        ]) ;
    }

    /**
     * True when the retention config carries at least one deletion criterion
     * (`keep`, `max_age` or `max_total`). `auto` alone never deletes anything.
     *
     * @param array $retention
     * @return bool
     */
    public function retentionEnabled( array $retention ) :bool
    {
        foreach( [ RetentionOption::KEEP , RetentionOption::MAX_AGE , RetentionOption::MAX_TOTAL ] as $key )
        {
            $value = $retention[ $key ] ?? null ;
            if( $value !== null && $value !== '' )
            {
                return true ;
            }
        }
        return false ;
    }

    /**
     * The bucket of an archive — its suffix signature (file name minus the
     * leading 19-character ISO date, the joining dash and the extension).
     *
     * @param string $filename
     * @return string
     */
    private function archiveBucket( string $filename ) :string
    {
        $stem = preg_replace( '/\.tar(\.gz(\.enc)?)?$/' , '' , $filename ) ?? $filename ;
        return strlen( $stem ) > 20 ? substr( $stem , 20 ) : $stem ; // drop "YYYY-MM-DDTHH:MM:SS-"
    }

    /**
     * The date embedded at the start of an archive file name.
     *
     * @param string $filename
     * @return DateTimeImmutable
     */
    private function archiveDate( string $filename ) :DateTimeImmutable
    {
        try
        {
            return new DateTimeImmutable( substr( $filename , 0 , 19 ) ) ;
        }
        catch( Exception )
        {
            return new DateTimeImmutable( '@0' ) ; // unparsable → treat as oldest
        }
    }

    /**
     * Applies the global `max_total` size cap: deletes the oldest survivors
     * across all buckets until the total fits, never violating the per-bucket
     * floor nor pruning the current archive.
     *
     * @param array<int,Archive>      $archives
     * @param array<string,Archive>   $delete  The path => Archive deletion set, mutated in place.
     * @param int                     $maxTotalBytes
     * @param string|null             $current
     * @return void
     */
    private function applyMaxTotal( array $archives , array &$delete , int $maxTotalBytes , ?string $current ) :void
    {
        $survivors = array_values( array_filter( $archives , static fn( Archive $a ) => !isset( $delete[ $a->path ] ) ) ) ;

        $total = array_sum( array_map( static fn( Archive $a ) => $a->size , $survivors ) ) ;
        if( $total <= $maxTotalBytes )
        {
            return ;
        }

        $perBucket = [] ;
        foreach( $survivors as $a )
        {
            $perBucket[ $a->bucket ] = ( $perBucket[ $a->bucket ] ?? 0 ) + 1 ;
        }

        usort( $survivors , static fn( Archive $a , Archive $b ) => $a->date <=> $b->date ) ; // oldest first

        foreach( $survivors as $a )
        {
            if( $total <= $maxTotalBytes )
            {
                break ;
            }
            if( ( $current !== null && $a->path === $current ) || ( $perBucket[ $a->bucket ] ?? 0 ) <= 1 )
            {
                continue ; // never the current archive, never the last of a bucket
            }
            $delete[ $a->path ] = $a ;
            $total -= $a->size ;
            $perBucket[ $a->bucket ]-- ;
        }
    }

    /**
     * Parses a human size (`5G`, `500M`, `1.5G`, `2k`, `1024`) into bytes.
     *
     * @param int|string $value
     * @return int
     * @throws InvalidArgumentException When the value is not a valid size.
     */
    private function parseRetentionSize( int|string $value ) :int
    {
        if( is_int( $value ) )
        {
            return max( 0 , $value ) ;
        }

        $value = trim( $value ) ;
        if( preg_match( '/^([\d.]+)\s*([KMGT]?)B?$/i' , $value , $m ) !== 1 )
        {
            throw new InvalidArgumentException( sprintf( 'Invalid retention max_total "%s": expected a size such as 5G, 500M or a byte count.' , $value ) ) ;
        }

        $units = [ '' => 0 , 'K' => 1 , 'M' => 2 , 'G' => 3 , 'T' => 4 ] ;
        return (int) ( (float) $m[ 1 ] * ( 1024 ** $units[ strtoupper( $m[ 2 ] ) ] ) ) ;
    }
}
