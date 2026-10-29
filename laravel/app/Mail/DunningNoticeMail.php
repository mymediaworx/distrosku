<?php

namespace App\Mail;

use App\Models\DunningNotice;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** 0.20.18: a dunning notice (plain text body from the notice) with the customer's statement PDF attached. */
class DunningNoticeMail extends Mailable
{
    public function __construct(public DunningNotice $notice, protected string $pdf, protected ?string $replyAddress = null) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->notice->subject, replyTo: filled($this->replyAddress) ? [$this->replyAddress] : []);
    }

    public function content(): Content
    {
        return new Content(text: 'mail.dunning-notice');
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        $code = preg_replace('/[^A-Za-z0-9_-]+/', '', (string) $this->notice->customer?->code) ?: 'account';

        return [Attachment::fromData(fn () => $this->pdf, "statement-{$code}.pdf")->withMime('application/pdf')];
    }
}
