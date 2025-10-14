<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Attachment extends Model
{
    protected $table = 'common_attachment';
    protected $fillable = [
        'attachment_type', 'path', 'file_type', 'size', 'hash_value', 'name'
    ];

    protected $keyType = 'string'; // UUID
    public $incrementing = false;
    const CREATED_AT = 'created';
    const UPDATED_AT = 'updated';

   public function setFileMetadata($file)
    {
        $this->file_type = $file->getMimeType(); // more standard than getClientMimeType()
        $this->size = $file->getSize();

        // Only calculate hash if file exists locally
        if ($file->isValid()) {
            $this->hash_value = md5_file($file->getPathname());
        } else {
            $this->hash_value = null;
        }

        $this->attachment_type = 'global';
    }

    // Return full URL (like Django get_absolute_url)
    public function getPathAttribute($value)
    {
        return url('storage/' . $value);
    }

    // UUID and attachment_type defaults
    protected static function booted()
    {
        static::creating(function ($attachment) {
            if (!$attachment->id) {
                $attachment->id = (string) Str::uuid();
            }
            if (!$attachment->attachment_type) {
                $attachment->attachment_type = 'global';
            }
        });
    }
}
