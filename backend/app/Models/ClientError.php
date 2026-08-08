<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientError extends Model
{
    protected $fillable = [
        'fingerprint', 'message', 'stack', 'url', 'release', 'hits',
        'last_user_id', 'last_agent', 'first_seen_at', 'last_seen_at', 'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function lastUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_user_id');
    }

    /**
     * What makes two reports the same fault.
     *
     * The message plus the first frame of the stack: the same bug from the
     * same place, however many people hit it. The whole stack would split one
     * fault into a row per browser, since minified frame numbers differ.
     */
    public static function fingerprintFor(string $message, ?string $stack): string
    {
        $frame = '';
        if ($stack) {
            foreach (preg_split('/\r?\n/', $stack) as $line) {
                if (str_contains($line, 'at ') || str_contains($line, '@')) {
                    $frame = trim($line);
                    break;
                }
            }
        }

        return hash('sha256', $message . '|' . $frame);
    }
}
