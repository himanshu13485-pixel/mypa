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
        // Signing in with a password alone is one stolen password away from
        // somebody else's account, so a code confirms the person as well.
        //   new_device - asked once per device, then that device is trusted
        //   always     - asked every single time
        //   off        - password only, as it was
        'login_otp_mode' => 'new_device',
        // How long a device stays trusted before it is asked again.
        'trusted_device_days' => '60',
        // Who sign-in codes come FROM. Blank uses the server's own MAIL_FROM.
        // Never a CRM company's mailbox: those are for a company writing to
        // its clients, and a login code is Netvork writing to its own user.
        'platform_mail_from' => '',
        'platform_mail_name' => '',
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
