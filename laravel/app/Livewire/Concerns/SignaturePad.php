<?php

namespace App\Livewire\Concerns;

/** Signature canvas state shared by the driver stop, van sale, will-call pickup and counter POS screens. */
trait SignaturePad
{
    public string $sigImage = '';

    public string $sigName = '';

    /** @var array<int, bool> */
    public array $sigAccepted = [];

    public string $lat = '';

    public string $lng = '';

    protected function sigInput(): ?array
    {
        return $this->sigImage === '' && $this->sigName === '' ? null
            : ['image' => $this->sigImage, 'name' => $this->sigName, 'accepted' => $this->sigAccepted, 'lat' => $this->lat, 'lng' => $this->lng];
    }

    protected function resetSignature(): void
    {
        $this->sigImage = $this->sigName = '';
        $this->sigAccepted = [];
    }
}
