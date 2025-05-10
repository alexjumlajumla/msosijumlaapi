<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\User;
use App\Models\LoanRepayment;

class Loan extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'amount',
        'interest_rate',
        'repayment_amount',
        'disbursed_by',
        'disbursed_at',
        'due_date',
        'status',
    ];

    protected $dates = [
        'disbursed_at',
        'due_date',
    ];

    /**
     * Get the vendor (user) who received the loan.
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get the admin who disbursed the loan.
     */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disbursed_by');
    }

    /**
     * Get all repayments made for this loan.
     */
    public function repayments(): HasMany
    {
        return $this->hasMany(LoanRepayment::class);
    }

    /**
     * Accessor to get total amount repaid so far.
     */

    public function getTotalRepaidAttribute(): float
    {
        return $this->repayments()->sum('amount');
    }

    // /**
    //  * Determine if loan is fully repaid.
    //  */
    // public function isFullyRepaid(): bool
    // {
    //     return $this->total_repaid >= $this->repayment_amount;
    // }


	public function user()
    {
        return $this->belongsTo(User::class);
    }


    
}
