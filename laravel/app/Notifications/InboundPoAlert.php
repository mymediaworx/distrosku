<?php

namespace App\Notifications;

use App\Security\PasswordAdmin;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** 0.20.1: bell (database) alert to the receiving team; also mail when mail is configured. Sent synchronously. */
class InboundPoAlert extends Notification
{
    /** @param list<array{label:string,url:string}> $links */
    public function __construct(public string $title, public string $body, public ?string $url = null, public array $links = [], public string $status = 'info') {}

    public function via(object $notifiable): array
    {
        return PasswordAdmin::mailConfigured() && filled($notifiable->email ?? null) ? ['database', 'mail'] : ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $actions = [];
        if ($this->url) {
            $actions[] = Action::make('open')->label('Open')->button()->url($this->url)->markAsRead();
        }
        foreach (array_slice($this->links, 0, 3) as $i => $l) {
            $actions[] = Action::make('track'.$i)->label($l['label'])->link()->url($l['url'], shouldOpenInNewTab: true);
        }

        return FilamentNotification::make()->title($this->title)->body($this->body)->status($this->status)->icon('heroicon-o-truck')
            ->actions($actions)->getDatabaseMessage();
    }

    public function toMail(object $notifiable): MailMessage
    {
        $m = (new MailMessage)->subject($this->title)->line(strip_tags(str_replace(['<br>', '<br/>'], "\n", $this->body)));
        foreach ($this->links as $l) {
            $m->line($l['label'].': '.$l['url']);
        }

        return $this->url ? $m->action('Open', $this->url) : $m;
    }
}
