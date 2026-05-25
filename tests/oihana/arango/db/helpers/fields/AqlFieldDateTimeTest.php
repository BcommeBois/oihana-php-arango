<?php

namespace tests\oihana\arango\db\helpers\fields;

use PHPUnit\Framework\TestCase;
use function oihana\arango\db\helpers\fields\aqlFieldDateTime;

final class AqlFieldDateTimeTest extends TestCase
{
    public function testFieldDateTimeDefault(): void
    {
        $result = aqlFieldDateTime('created');
        $this->assertEquals('created:IS_DATESTRING(doc.created) ? DATE_FORMAT(doc.created,"%yyyy-%mm-%ddT%hh:%ii:%ssZ") : null', $result);
    }

    public function testFieldDateTimeWithCustomDoc(): void
    {
        $result = aqlFieldDateTime('modified', 'user');

        $this->assertEquals('modified:IS_DATESTRING(user.modified) ? DATE_FORMAT(user.modified,"%yyyy-%mm-%ddT%hh:%ii:%ssZ") : null', $result);
    }

    public function testFieldDateTimeWithCustomFieldName(): void
    {
        $result = aqlFieldDateTime('createdAt', keyName:  'created');

        $this->assertEquals('createdAt:IS_DATESTRING(doc.created) ? DATE_FORMAT(doc.created,"%yyyy-%mm-%ddT%hh:%ii:%ssZ") : null', $result);
    }

    public function testFieldDateTimeWithAllParameters(): void
    {
        $result = aqlFieldDateTime('publishedDate', 'article', 'published');

        $this->assertEquals('publishedDate:IS_DATESTRING(article.published) ? DATE_FORMAT(article.published,"%yyyy-%mm-%ddT%hh:%ii:%ssZ") : null', $result);
    }
}
