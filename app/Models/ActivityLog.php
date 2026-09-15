<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ActivityLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'anonymous_id',
        'event',
        'screen',
        'device_model',
        'android_version',
        'metadata',
    ];

    protected $casts = [
        'android_version' => 'integer',
        'metadata' => 'array',
    ];
}