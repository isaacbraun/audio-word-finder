<?php

use App\Models\AudioFile;
use Livewire\Volt\Component;

new class extends Component {
    public AudioFile $file;
}; ?>

<div>
    <flux:card>
        <flux:heading size="lg">
            <span class="mr-1 mt-1">{{ $file->audio_filename }}</span>

            @if (!$file->transcription_path && !$file->query_count)
            <flux:badge color="zinc" inset="top bottom">Transcribing</flux:badge>
            @elseif ($file->transcription_path)
            <flux:badge color="Sky" inset="top bottom">Searching</flux:badge>
            @else
            <flux:badge color="emerald" inset="top bottom">Completed</flux:badge>
            @endif
        </flux:heading>

        <div class="mt-4">
            @if ($file->transcription_path && file->query_count)
            <flux:accordion>
                <flux:accordion.item heading="View Transcription Results">

                    <flux:accordion.content>

                    </flux:accordion.content>
                </flux:accordion.item>
            </flux:accordion>
            @else
            <flux:icon.loading variant="micro" />
            @endif
        </div>
    </flux:card>
</div>
