<?php

use App\Jobs\ProcessFile;
use App\Models\AudioFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Arr;
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

    #[Computed()]
    public function badgeString(): string
    {
        return $this->file->query_count . ' ' . Str::plural('Match', $this->file->query_count);
    }

    public function retry()
    {
        // Reset transcription path and failed var to show correct UI state
        $this->failed = false;
        $this->file->transcription_path = null;
        $this->file->save();

        ProcessFile::dispatch($this->file->search, $this->file);
    }

    public function copyTranscription(): void
    {
        $fullText = Arr::get($this->transcription(), "fullText");
        $this->dispatch('copy-to-clipboard', transcription: $fullText);
    }

    public function placeholder()
    {
        return <<<'BLADE'
        <div>
            <flux:card class="h-28 bg-zinc-100/50" />
        </div>
        BLADE;
    }
}; ?>

<div @if (!$this->transcription) wire:poll.2s @endif>
    <flux:card class="!p-4">
        <div class="flex flex-row flex-wrap gap-2 justify-between">
            <div>
                <flux:heading>
                    <span class="break-all mr-1">
                        @if ($file->parsed_date)
                        {{ $file->parsed_date->toDayDateTimeString() }}
                        @else
                        {{ $file->audio_filename }}
                        @endif
                    </span>

                    @if ($this->file->query_count !== null)
                    <flux:badge size="sm" inset="top bottom" color="{{ $this->file->query_count === 0 ? 'rose' : 'emerald' }}">
                        {{ $this->file->query_count }} Matches
                    </flux:badge>
                    @endif
                </flux:heading>

                @if ($file->parsed_date)
                <flux:text class="mt-1 break-all">{{ $file->audio_filename }}</flux:text>
                @endif
            </div>

            @if (!$this->transcription)
            <flux:icon.loading variant="micro" />
            @elseif (Arr::has($this->transcription, "fullText"))
            <flux:button
                wire:click="copyTranscription"
                icon:trailing="document-duplicate"
                variant="subtle"
                size="sm"
                title="Copy transcription to clipboard"
                inset></flux:button>
            @endif
        </div>

        @if ($this->transcription)
        <flux:accordion class="mt-2" variant="reverse">
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
        <flux:callout class="mt-2" icon="exclamation-triangle" color="red" inline>
            <flux:callout.heading>Processing failed</flux:callout.heading>

            <x-slot name="actions">
                <flux:button wire:click="retry">Retry</flux:button>
            </x-slot>
        </flux:callout>
        @endif
    </flux:card>
</div>
