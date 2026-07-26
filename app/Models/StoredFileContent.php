<?php

namespace App\Models;

use App\Enums\StoredFileContentFailure;
use App\Enums\StoredFileContentStatus;
use Database\Factories\StoredFileContentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $stored_file_id
 * @property string $source_checksum
 * @property StoredFileContentStatus $status
 * @property string|null $extractor
 * @property string|null $content
 * @property int $content_bytes
 * @property bool $is_truncated
 * @property StoredFileContentFailure|null $failure_code
 * @property Carbon|null $processing_started_at
 * @property Carbon|null $extracted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read StoredFile $storedFile
 */
#[Fillable(['stored_file_id', 'source_checksum', 'status', 'extractor', 'content', 'content_bytes', 'is_truncated', 'failure_code', 'processing_started_at', 'extracted_at'])]
class StoredFileContent extends Model
{
    /** @use HasFactory<StoredFileContentFactory> */
    use HasFactory;

    protected $primaryKey = 'stored_file_id';

    public $incrementing = false;

    /** @var array<string, bool|int|string> */
    protected $attributes = [
        'content_bytes' => 0,
        'is_truncated' => false,
        'status' => StoredFileContentStatus::Pending->value,
    ];

    /** @var list<string> */
    protected $hidden = ['content'];

    /** @return BelongsTo<StoredFile, $this> */
    public function storedFile(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => StoredFileContentStatus::class,
            'failure_code' => StoredFileContentFailure::class,
            'content_bytes' => 'integer',
            'is_truncated' => 'boolean',
            'processing_started_at' => 'datetime',
            'extracted_at' => 'datetime',
        ];
    }
}
