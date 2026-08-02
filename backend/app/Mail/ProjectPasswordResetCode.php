<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ProjectPasswordResetCode extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $projectName,
        public string $ownerName,
        public string $code,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Project password reset code — {$this->projectName}");
    }

    public function content(): Content
    {
        $html = <<<HTML
        <p>Hello {$this->ownerName},</p>
        <p>An admin issued a password reset code for your project <b>{$this->projectName}</b>:</p>
        <p style="font-size:28px;font-weight:bold;letter-spacing:6px">{$this->code}</p>
        <p>Open the project &rarr; <b>Have a reset code?</b> &rarr; enter this code and choose a new
        password. The code expires in 30 minutes.</p>
        <p style="color:#64748b;font-size:12px">If you did not ask for this, you can ignore it —
        your current password stays valid until the code is used.</p>
        HTML;

        return new Content(htmlString: $html);
    }
}
