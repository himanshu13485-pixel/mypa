<?php

namespace App\Models\Crm;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContestAnswer extends Model
{
    protected $table = 'crm_contest_answers';

    protected $fillable = [
        'question_id', 'member_id', 'answer_option', 'answer_text',
        'is_correct', 'points_awarded',
    ];

    protected function casts(): array
    {
        return ['is_correct' => 'boolean'];
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(ContestQuestion::class, 'question_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id');
    }
}
