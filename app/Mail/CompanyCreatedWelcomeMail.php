<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CompanyCreatedWelcomeMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(public readonly array $data) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Bienvenido a JOSARA CLOUD - Tu empresa fue creada correctamente',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.registration.company-created-welcome',
            text: 'emails.registration.company-created-welcome-text',
            with: ['data' => $this->data],
        );
    }
}
