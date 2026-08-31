<?php

namespace App\Models\Crm;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalaryRecord extends Model
{
    protected $table = 'crm_salary_records';

    protected $fillable = ['member_id', 'amount', 'currency', 'effective_from', 'designation', 'note', 'created_by'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'effective_from' => 'date'];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
