<?php

namespace Tests\Support;

use Illuminate\Http\UploadedFile;

final class ImportForm
{
    /** @param array<string, string> $overrides
     * @return array<string, string>
     */
    public static function row(array $overrides = []): array
    {
        return [
            'source_reference' => 'OLD-001', 'room_type_code' => 'DLX', 'rate_plan_code' => 'BB',
            'arrival' => '2026-12-10', 'departure' => '2026-12-12', 'adults' => '2', 'children' => '1',
            'booker_name' => 'Travel Booker', 'booker_email' => 'booker@example.test',
            'guest_name' => 'Amina Guest', 'guest_email' => 'amina@example.test',
            'status' => 'confirmed', 'restriction_override_reason' => '',
            ...$overrides,
        ];
    }

    /** @param list<array<string, string>> $rows */
    public static function csv(array $rows): UploadedFile
    {
        $stream = fopen('php://temp', 'w+');
        fputcsv($stream, array_keys(self::row()), escape: '');
        foreach ($rows as $row) {
            fputcsv($stream, array_values($row), escape: '');
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return UploadedFile::fake()->createWithContent('future-reservations.csv', $csv);
    }
}
