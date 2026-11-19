<?php

namespace Patterns\DataConsistency\DistributedLocks\Newsletter;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class NewsLetterMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     */
    public function __construct(public User $user)
    {
        //
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'News Letter Mail',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        // htmlString: takes raw HTML. (html: expects the NAME of a Blade view.)
        return new Content(
            htmlString: '
            <h1>Hello ' . e($this->user->name) . '!</h1>
            <p>Welcome to our newsletter.</p>
            <p>Today is ' . now()->format('F j, Y') . '</p>
        ',
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
