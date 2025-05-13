<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AIAssistantLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'request_type',
        'input',
        'output',
        'filters_detected',
        'product_ids',
        'successful',
        'processing_time_ms',
    ];

    protected $casts = [
        'filters_detected' => 'array',
        'product_ids' => 'array',
        'successful' => 'boolean',
        'processing_time_ms' => 'integer',
    ];

    /**
     * Get the user that owns this log entry.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Log a new AI assistant interaction
     */
    public static function logInteraction(array $data): self
    {
        return self::create($data);
    }
} 