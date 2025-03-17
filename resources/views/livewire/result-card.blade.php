<?php

use App\Jobs\ProcessFile;
use App\Models\AudioFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;

new class extends Component {
    public AudioFile $file;
    public $failed = false;

    #[Computed()]
    public function transcription(): array
    {
        if ($this->file->transcription_path === 'failed') {
            $this->failed = true;
        } elseif ($this->file->transcription_path) {
            $file = Storage::json($this->file->transcription_path);
        }

        return $file ?? [];
    }

    public function retry()
    {
        // Reset transcription path and failed var to show correct UI state
        $this->failed = false;
        $this->file->transcription_path = null;
        $this->file->save();

        ProcessFile::dispatch($this->file->search, $this->file);
    }
}; ?>

<div>
    <flux:card wire:poll.2s>
        <flux:heading>
            <span class="mr-1 mt-1">{{ $file->audio_filename }}</span>

            @if ($this->transcription)
            <flux:badge size="sm" inset="top bottom" color="{{ $this->transcription['matchCount'] === 0 ? 'rose' : 'emerald' }}">
                {{ $this->transcription["matchCount"] }} Matches
            </flux:badge>
            @endif
        </flux:heading>

        @if ($file->parsed_date)
        <flux:subheading>{{ $file->parsed_date->toDayDateTimeString() }}</flux:subheading>
        @endif


        <div class="mt-4">
            @if ($this->transcription)
            <flux:accordion class="mt-4" variant="reverse">
                <flux:accordion.item heading="View Transcription">
                    <flux:accordion.content class="leading-loose">
                        @foreach ($this->transcription["segments"] as $segment)
                        @if ($segment["match"])
                        <flux:badge color="yellow" size="sm" inset="top bottom">{{ $segment["text"] }}</flux:badge>
                        @else
                        <span wire:key="$loop->index">{{ $segment["text"] }}</span>
                        @endif
                        @endforeach
                    </flux:accordion.content>
                </flux:accordion.item>
            </flux:accordion>
            @elseif ($this->failed)
            <flux:callout icon="exclamation-triangle" color="red" inline>
                <flux:callout.heading>Processing failed</flux:callout.heading>

                <x-slot name="actions">
                    <flux:button wire:click="retry">Retry</flux:button>
                </x-slot>
            </flux:callout>
            @else
            <flux:icon.loading variant="micro" />
            @endif
        </div>
    </flux:card>
</div>
