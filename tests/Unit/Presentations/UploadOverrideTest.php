<?php

namespace Tests\Unit\Presentations;

use App\Models\PresentationLink;
use App\Models\PresentationUpload;
use PHPUnit\Framework\TestCase;

class UploadOverrideTest extends TestCase
{
    /** @test */
    public function upload_getVerifiedData_returns_override_when_set(): void
    {
        $upload = new PresentationUpload();
        $upload->extraction_status = 'ok';
        $upload->extraction_json   = ['median_price' => 2000000, 'sold_count' => 15];
        $upload->override_json     = ['median_price' => 2200000, 'sold_count' => 15];

        $verified = $upload->getVerifiedData();

        $this->assertEquals(2200000, $verified['median_price']);
        $this->assertTrue($upload->isOverridden());
    }

    /** @test */
    public function upload_getVerifiedData_returns_extraction_when_no_override(): void
    {
        $upload = new PresentationUpload();
        $upload->extraction_status = 'ok';
        $upload->extraction_json   = ['median_price' => 2000000, 'sold_count' => 15];
        $upload->override_json     = null;

        $verified = $upload->getVerifiedData();

        $this->assertEquals(2000000, $verified['median_price']);
        $this->assertFalse($upload->isOverridden());
    }

    /** @test */
    public function upload_getVerifiedData_returns_null_when_nothing_extracted(): void
    {
        $upload = new PresentationUpload();
        $upload->extraction_status = 'pending';
        $upload->extraction_json   = null;
        $upload->override_json     = null;

        $this->assertNull($upload->getVerifiedData());
    }

    /** @test */
    public function link_getVerifiedData_returns_override_when_set(): void
    {
        $link = new PresentationLink();
        $link->extraction_status = 'ok';
        $link->extracted_json    = ['asking_price' => 3000000, 'beds' => 4];
        $link->override_json     = ['asking_price' => 2800000, 'beds' => 4];

        $verified = $link->getVerifiedData();

        $this->assertEquals(2800000, $verified['asking_price']);
        $this->assertTrue($link->isOverridden());
    }

    /** @test */
    public function link_getVerifiedData_returns_extraction_when_no_override(): void
    {
        $link = new PresentationLink();
        $link->extraction_status = 'ok';
        $link->extracted_json    = ['asking_price' => 3000000, 'beds' => 4];
        $link->override_json     = null;

        $verified = $link->getVerifiedData();

        $this->assertEquals(3000000, $verified['asking_price']);
        $this->assertFalse($link->isOverridden());
    }

    /** @test */
    public function link_getVerifiedData_returns_null_when_nothing(): void
    {
        $link = new PresentationLink();
        $link->extraction_status = 'pending';
        $link->extracted_json    = null;
        $link->override_json     = null;

        $this->assertNull($link->getVerifiedData());
    }

    /** @test */
    public function upload_isOverridden_false_when_null(): void
    {
        $upload = new PresentationUpload();
        $upload->override_json = null;

        $this->assertFalse($upload->isOverridden());
    }

    /** @test */
    public function upload_isOverridden_true_when_set(): void
    {
        $upload = new PresentationUpload();
        $upload->override_json = ['median_price' => 2200000];

        $this->assertTrue($upload->isOverridden());
    }
}
