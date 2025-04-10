<?php

use App\Models\Search;
use App\Enums\SearchStatus;
use Livewire\Volt\Component;
use Livewire\Attributes\{Computed};
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

new class extends Component {
    use \Livewire\WithPagination;

    public int $id;
    public Search $search;
    public string $query;
    /** @var \App\Models\AudioFile[] $files */
    public $files;
    public string $sortBy = 'query_count';
    public string $activeTab = 'all';
    public string $sortDirection = 'desc';

    public function mount($id)
    {
        $this->id = $id;
        $this->search = Search::findOrFail($id);

        $this->authorize('view', $this->search);

        $this->query = $this->search->query;
        $this->files = $this->search->files;
    }

    public function rendering(View $view): void
    {
        $view->title('Results for "' . $this->query . '"');
    }

    public function delete()
    {
        try {
            Log::info('Search: deleting search {search}', ['search' => $this->search->id]);
            $this->redirectRoute('history', navigate: true);
            $this->search->delete();
        } catch (\Exception $e) {
            Log::error('Search: error deleting search {search}: {exception}', ['search' => $this->search, 'exception' => $e]);
        }
    }

    public function toggleSort(): void
    {
        if ($this->sortDirection === 'desc') {
            $this->sortDirection = 'asc';
        } else {
            $this->sortDirection = 'desc';
        }
    }

    #[Computed]
    public function filteredFiles()
    {
        $whereClause = [];

        if ($this->activeTab === 'matches') {
            $whereClause[] = ['query_count', '>', 0];
        } elseif ($this->activeTab === 'misses') {
            $whereClause[] = ['query_count', '=', 0];
        }

        return $this->search->files()
            ->where($whereClause)
            ->orderBy($this->sortBy, $this->sortDirection)
            ->get();
    }

    #[Computed]
    public function statusVariant(): string
    {
        if ($this->search->status === SearchStatus::Completed) {
            return 'success';
        } elseif ($this->search->status === SearchStatus::Processing) {
            return 'secondary';
        } else {
            return 'danger';
        }
    }
}; ?>

<div>
    <div class="flex flex-row flex-wrap items-center justify-between gap-2">
        <flux:heading size="xl" level="1">Results for "{{ $query }}"</flux:heading>

        <flux:modal.trigger name="delete-search">
            <flux:button size="sm" variant="danger" icon="trash">Delete</flux:button>
        </flux:modal.trigger>
    </div>

    <!-- Mount confirm delete modal -->
    <livewire:confirm-delete @delete="delete" name="delete-search" />

    <!-- Processing status/results -->
    <div @if ($search->status !== SearchStatus::Completed) wire:poll.2s @endif>
        <flux:callout class="my-8" inline variant="{{ $this->statusVariant }}">
            @if ($search->status === SearchStatus::Completed && $search->query_total > 0)
            <flux:callout.heading>
                Processing Completed
                <flux:text>{{ $search->query_total }} Total {{ Str::plural('Match', $search->query_total) }}</flux:text>
            </flux:callout.heading>

            <x-slot name="actions">
                <flux:button icon="arrow-down-tray">Export Matches</flux:button>
            </x-slot>
            @elseif ($search->status === SearchStatus::Processing)
            <flux:callout.heading>
                Processing {{ count($files) }} {{ Str::plural('file', count($files)) }}
                <flux:icon.loading variant="mini" />
            </flux:callout.heading>
            @else
            <flux:callout.heading>No matches found</flux:callout.heading>
            @endif
        </flux:callout>
    </div>

    <!-- File controls -->
    <div class="flex flex-row flex-wrap gap-2 justify-between items-end">
        <div>
            <flux:heading size="lg" level="2">Files</flux:heading>
            <flux:text>
                {{ $search->status === SearchStatus::Completed ? 'Searched' : 'Searching' }} {{ count($files) }} {{ Str::plural('file', count($files)) }}
            </flux:text>
        </div>

        <div class="flex flex-row flex-wrap gap-2">
            <flux:tabs variant="segmented" wire:model.live="activeTab">
                <flux:tab name="all">All</flux:tab>
                <flux:tab name="matches">Matches</flux:tab>
                <flux:tab name="misses">Misses</flux:tab>
            </flux:tabs>

            <flux:select variant="listbox" class="sm:max-w-fit" wire:model.live="sortBy">
                <x-slot name="trigger">
                    <flux:select.button>
                        <flux:icon.arrows-up-down variant="micro" class="mr-2 text-zinc-400" />
                        <flux:select.selected />
                    </flux:select.button>
                </x-slot>

                <flux:select.option value="query_count" selected>Match Count</flux:select.option>
                <flux:select.option value="parsed_date">Parsed Date</flux:select.option>
                <flux:select.option value="created_at">Order Uploaded</flux:select.option>
            </flux:select>

            <!-- <flux:button icon="{{ $sortDirection === 'desc' ? 'arrow-down' : 'arrow-up' }}" wire:click="toggleSort"></flux:button> -->
        </div>
    </div>
    <!-- File list -->
    <div class="mt-4 flex flex-col gap-2">
        @if ($this->filteredFiles->isNotEmpty())
        @foreach ($this->filteredFiles as $file)
        <livewire:result-card :lazy="$loop->index > 10 ? 'on-load' : ''" :file="$file" wire:key="{{ $file->id }}" />
        @endforeach
        @else
        <flux:subheading>No results found</flux:subheading>
        @endif
    </div>
</div>

@script
<script>
    $wire.on('copy-to-clipboard', (event) => {
        if (event.transcription) {
            navigator.clipboard.writeText(event.transcription);
            Flux.toast({
                heading: 'Copied!',
                text: 'The transcription has been copied to your clipboard.',
                variant: 'success',
            })
        } else {
            Flux.toast({
                heading: 'Uh oh!',
                text: 'The transcription could not be copied to your clipboard.',
                variant: 'failure',
            })
        }
    });
</script>
@endscript
