<?php
declare(strict_types=1);

/**
 * Sportlog (https://sportlog.at)
 *
 * @license MIT License
 */

namespace Sportlog\FIT\Test\TestCase\Decoder;

use PHPUnit\Framework\TestCase;
use Sportlog\FIT\Decoder;
use Sportlog\FIT\Profile\Messages\RecordMessage;
use Sportlog\FIT\Profile\Types\MesgNum;
use Sportlog\FIT\Test\Support\FitFixtureBuilder;

/**
 * Verifies that the decoder handles RECORD messages where position_lat and
 * position_long are encoded as FLOAT32 degrees rather than the spec-compliant
 * SINT32 semicircles. Some non-spec devices emit position this way.
 */
final class DecodeFloat32PositionTest extends TestCase
{
    public function testFloat32PositionFieldsReturnDegrees(): void
    {
        $path = (new FitFixtureBuilder)
            ->withFloat32Record(51.501, -0.101, 1074247200)
            ->toTempFile('fit_float32_pos_');

        try {
            $records = $this->decodeRecords($path);

            $this->assertCount(1, $records);

            $lat = $records[0]->getPositionLat();
            $lng = $records[0]->getPositionLong();

            $this->assertIsFloat($lat);
            $this->assertIsFloat($lng);
            $this->assertEqualsWithDelta(51.501, $lat, 0.0001);
            $this->assertEqualsWithDelta(-0.101, $lng, 0.0001);
        } finally {
            @unlink($path);
        }
    }

    /** @return RecordMessage[] */
    private function decodeRecords(string $path): array
    {
        return (new Decoder)->read($path)->getMessages(MesgNum::RECORD);
    }
}
