<?php

namespace App\Notifications\Channels;

use App\Services\Crm\CompanyMailer;
use Illuminate\Contracts\Mail\Mailable;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\Notification;

/**
 * Whose letterhead a notification arrives on.
 *
 * Somebody who signs up on Netvork is Netvork's user, and everything the app
 * writes to them comes from the platform. The day a company takes them on as
 * an employee, the company becomes the one writing: a payment recorded, an
 * expense approved, a task assigned - all of it is work, sent by the people
 * they work for, and it should arrive from an address they recognise.
 *
 * Which also happens to be the only way it passes SPF and DKIM for that
 * domain rather than landing in spam, which was the practical problem: mail
 * about Acme's invoices, sent as Netvork, from Netvork's server, about a
 * company the recipient's mail host has never heard us mention.
 *
 * Registered over the framework's own 'mail' driver rather than named in
 * each notification's via(), so a notification added next year is routed
 * correctly without anybody remembering this exists.
 */
class CompanyMailChannel extends MailChannel
{
    public function send($notifiable, Notification $notification)
    {
        $message = $notification->toMail($notifiable);

        if (! $notifiable->routeNotificationFor('mail', $notification) && ! $message instanceof Mailable) {
            return;
        }

        // A Mailable carries its own sender and its own mailer; it is not
        // this channel's business to rewrite one.
        if ($message instanceof Mailable) {
            return $message->send($this->mailer);
        }

        $company = $notifiable instanceof \App\Models\User
            ? CompanyMailer::forStaff($notifiable)
            : null;

        // Nobody's employee, or a company with no mailbox of its own: the
        // platform sends it, exactly as before.
        if (! $company) {
            return parent::send($notifiable, $notification);
        }

        /*
         * The company's address, unless the notification named one.
         *
         * A sign-in code already resolves its own sender and says so; this
         * must not overwrite a decision that has already been made.
         */
        if (! $message->from) {
            $message->from($company['address'], $company['name']);
        }

        try {
            return $company['mailer']->send(
                $this->buildView($message),
                array_merge($message->data(), $this->additionalMessageData($notification)),
                $this->messageBuilder($notifiable, $notification, $message),
            );
        } catch (\Throwable $e) {
            /*
             * A company mailbox that stops working must not stop the mail.
             *
             * The alternative is a queue full of failed jobs and a person
             * who hears nothing at all because their employer's SMTP
             * password expired - which is a worse failure than an e-mail
             * arriving from the platform instead.
             */
            report($e);

            return parent::send($notifiable, $notification);
        }
    }
}
