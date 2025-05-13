<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AIAssistantLog extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'a_i_assistant_logs';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'user_id',
        'request_type',
        'request_content',
        'response_content',
        'successful',
        'processing_time_ms',
        'metadata',
        'is_feedback_provided',
        'was_helpful',
        'feedback_comment',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'successful' => 'boolean',
        'is_feedback_provided' => 'boolean',
        'was_helpful' => 'boolean',
        'metadata' => 'array',
        'processing_time_ms' => 'integer',
    ];

    /**
     * Get the user that owns the log.
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