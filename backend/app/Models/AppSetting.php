<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppSetting extends Model
{
    protected $fillable = ['key', 'value'];

    public const DEFAULTS = [
        // Days a user must wait between username changes (admin-editable).
        'username_change_days' => '30',
        // OTP lifetime in minutes.
        'otp_expiry_minutes' => '10',
        // Voice assistant AI fallback — managed from Admin → Settings, no
        // server access needed: on/off switch, Anthropic API key, model.
        'voice_ai_enabled' => '0',
        'voice_ai_key' => '',
        'voice_ai_model' => 'claude-opus-5',
    ];

    /** Keys whose stored values must never be sent back to the browser. */
    public const SECRET_KEYS = ['voice_ai_key'];

    public static function get(string $key): string
    {
        return static::where('key', $key)->value('value')
            ?? self::DEFAULTS[$key]
            ?? '';
    }

    public static function set(string $key, string $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
