<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class NewTenantRegisteredAdminMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(public readonly array $data) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Nueva empresa registrada en JOSARA CLOUD',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.registration.new-tenant-registered-admin',
            text: 'emails.registration.new-tenant-registered-admin-text',
            with: ['data' => $this->data],
        );
    }
}
