<?php

namespace App\Models\Crm;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContestQuestion extends Model
{
    protected $table = 'crm_contest_questions';

    protected $fillable = [
        'contest_id', 'type', 'question', 'options', 'correct_option',
        'correct_text', 'points', 'sort',
    ];

    protected function casts(): array
    {
        return ['options' => 'array'];
    }

    public function contest(): BelongsTo
    {
        return $this->belongsTo(Contest::class, 'contest_id');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(ContestAnswer::class, 'question_id');
    }

    /** Grade an answer; null means a human has to look at it. */
    public function grade(?int $option, ?string $text): ?bool
    {
        if ($this->type === 'option') {
            return $option !== null && $option === (int) $this->correct_option;
        }
        if ($this->correct_text !== null && $this->correct_text !== '') {
            return trim(mb_strtolower((string) $text)) === trim(mb_strtolower($this->correct_text));
        }

        return null;
    }
}
