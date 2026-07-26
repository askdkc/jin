<?php

namespace Database\Factories;

use App\Enums\StoredFileContentStatus;
use App\Models\StoredFile;
use App\Models\StoredFileContent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StoredFileContent>
 */
class StoredFileContentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'stored_file_id' => StoredFile::factory(),
            'source_checksum' => hash('sha256', fake()->uuid()),
            'status' => StoredFileContentStatus::Pending,
            'extractor' => null,
            'content' => null,
            'content_bytes' => 0,
            'is_truncated' => false,
            'failure_code' => null,
            'processing_started_at' => null,
            'extracted_at' => null,
        ];
    }
}
