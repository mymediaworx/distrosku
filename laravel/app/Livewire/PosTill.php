<?php

namespace App\Livewire;

use App\Exceptions\WorkflowException;
use App\Models\TillMovement;
use App\Models\TillSession;
use App\Models\Warehouse;
use App\Till\TillService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/** #44: the counter's cash drawer for this register: open with a float, paid-in / paid-out / safe drop, close with a count. */
#[Layout('layouts.pos')]
#[Title('Till')]
class PosTill extends Component
{
    public string $float = '';

    public string $type = 'drop';

    public string $amount = '';

    public string $reason = '';

    /** Count by denomination at close (bills and a coin total). */
    public array $count = ['100' => '', '50' => '', '20' => '', '10' => '', '5' => '', '1' => '', 'coins' => ''];

    public string $closeNotes = '';

    public ?string $message = null;

    public ?string $error = null;

    public ?int $closedId = null;

    public const BILLS = ['100', '50', '20', '10', '5', '1'];

    private function session(): ?TillSession
    {
        return TillService::current(CounterPos::register());
    }

    public function getCountedProperty(): string
    {
        $t = 0.0;
        foreach (self::BILLS as $b) {
            $t += (int) ($this->count[$b] ?: 0) * (int) $b;
        }

        return number_format($t + (float) ($this->count['coins'] ?: 0), 2, '.', '');
    }

    private function run(callable $fn, string $ok): void
    {
        $this->reset('message', 'error');
        try {
            $fn();
            $this->message = $ok;
        } catch (WorkflowException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function open(TillService $svc): void
    {
        $this->run(fn () => $svc->open(CounterPos::register(), Warehouse::where('code', 'MAIN')->value('id'), $this->float, auth()->user()), 'Till open.');
        $this->float = '';
    }

    public function record(TillService $svc): void
    {
        $s = $this->session();
        if (! $s) {
            $this->error = 'Open the till first.';

            return;
        }
        $this->run(fn () => $svc->move($s, $this->type, $this->amount, $this->reason, auth()->user()), (TillMovement::TYPES[$this->type] ?? 'Cash').' recorded.');
        if (! $this->error) {
            $this->reset('amount', 'reason');
        }
    }

    public function close(TillService $svc): void
    {
        $s = $this->session();
        if (! $s) {
            $this->error = 'No open till on this register.';

            return;
        }
        $detail = array_filter($this->count, fn ($v) => $v !== '' && $v !== null);
        $this->run(function () use ($svc, $s, $detail) {
            $closed = $svc->close($s, $this->counted, auth()->user(), $detail, $this->closeNotes ?: null);
            $this->closedId = $closed->id;
        }, 'Till closed.');
        if (! $this->error) {
            $this->reset('count', 'closeNotes');
        }
    }

    public function render()
    {
        $s = $this->session();

        return view('livewire.pos-till', [
            'till' => $s,
            'report' => $s ? TillService::report($s) : null,
            'register' => CounterPos::register(),
            'types' => TillMovement::TYPES,
        ]);
    }
}
