<?php

use App\Models\Search;
use App\Enums\SearchStatus;
use Livewire\Volt\Component;
use Livewire\Attributes\{Computed};
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

new class extends Component {
    use \Livewire\WithPagination;

    public int $id;
    public Search $search;
    public string $query;
    public string $sort = 'query_count|desc';
    public string $activeTab = 'all';

    public function mount($id)
    {
        $this->id = $id;
        $this->search = Search::findOrFail($id);

        $this->authorize('view', $this->search);

        $this->query = $this->search->query;
    }

    public function rendering(View $view): void
    {
        $view->title('Results for "' . $this->query . '"');
    }

    #[Computed]
    public function filteredFiles()
    {
        // Create where clause based on active tab
        $whereClause = [];
        if ($this->activeTab === 'matches') {
            $whereClause[] = ['query_count', '>', 0];
        } elseif ($this->activeTab === 'misses') {
            $whereClause[] = ['query_count', '=', 0];
        }

        // Parse sort column and direction
        [$sortBy, $sortDirection] = explode('|', $this->sort);

        return $this->search->files()
            ->where($whereClause)
            ->orderBy($sortBy, $sortDirection)
            ->get();
    }

    #[Computed]
    public function status(): array
    {
        if ($this->search->status === SearchStatus::Completed) {
            return [
                'color' => 'green',
                'icon' => 'check-circle',
            ];
        } elseif ($this->search->status === SearchStatus::Processing) {
            return [
                'color' => 'cyan',
                'icon' => '',
            ];
        } elseif ($this->search->status === SearchStatus::Pending) {
            return [
                'color' => 'yellow',
                'icon' => 'arrow-up-circle',
            ];
        } else {
            return [
                'color' => 'red',
                'icon' => 'exclamation-triangle',
            ];
        }
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

    public function downloadReport(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $name = 'audio-search-report-' . $this->search->id . '.csv';

        return Storage::download($this->search->report_path, $name);
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
        <flux:callout class="my-8" inline color="{{ $this->status['color'] }}" icon="{{ $this->status['icon'] }}">
            @if ($search->status === SearchStatus::Processing)
            <flux:callout.heading class="justify-between">
                Processing {{ count($search->files) }} {{ Str::plural('file', count($search->files)) }}
                <flux:icon.loading variant="micro" />
            </flux:callout.heading>
            @elseif ($search->status === SearchStatus::Completed && $search->query_total > 0)
            <flux:callout.heading>
                Processing Completed
                <flux:text>{{ $search->query_total }} Total {{ Str::plural('Match', $search->query_total) }}</flux:text>
            </flux:callout.heading>

            @if (Auth::user()->subscribed() && $search->report_path !== null)
            <x-slot name="actions">
                <flux:button wire:click="downloadReport" icon="arrow-down-tray">Export Matches</flux:button>
            </x-slot>
            @endif
            @elseif ($search->status === SearchStatus::Completed && $search->query_total === 0)
            <flux:callout.heading>
                Processing Completed
                <flux:text>No matches found</flux:text>
            </flux:callout.heading>
            @elseif ($search->status === SearchStatus::Pending)
            <flux:callout.heading class="justify-between">
                Uploading Files
                <flux:icon.loading variant="micro" />
            </flux:callout.heading>
            @else
            <flux:callout.heading>Processing failed</flux:callout.heading>
            @endif
        </flux:callout>
    </div>

    @if ($search->status !== SearchStatus::Pending)
    <!-- File controls -->
    <div class="flex flex-row flex-wrap gap-2 justify-between items-end">
        <div>
            <flux:heading size="lg" level="2">Files</flux:heading>
            <flux:text>
                {{ $search->status === SearchStatus::Completed ? 'Searched' : 'Searching' }} {{ count($search->files) }} {{ Str::plural('file', count($search->files)) }}
            </flux:text>
        </div>

        <div class="flex flex-row flex-wrap gap-2">
            <flux:tabs variant="segmented" wire:model.live="activeTab">
                <flux:tab name="all">All</flux:tab>
                <flux:tab name="matches">Matches</flux:tab>
                <flux:tab name="misses">Misses</flux:tab>
            </flux:tabs>

            <flux:select variant="listbox" class="sm:max-w-fit" wire:model.live="sort">
                <x-slot name="trigger">
                    <flux:select.button>
                        <flux:icon.arrows-up-down variant="micro" class="mr-2 text-zinc-400" />
                        <flux:select.selected />
                    </flux:select.button>
                </x-slot>

                <flux:select.option value="query_count|desc" selected>Matches</flux:select.option>
                <flux:select.option value="parsed_date|asc">Date - Ascending</flux:select.option>
                <flux:select.option value="parsed_date|desc">Date - Descending</flux:select.option>
                <flux:select.option value="created_at|asc">Upload Order</flux:select.option>
            </flux:select>
        </div>
    </div>
    <!-- File list -->
    <div wire:loading.delay.shortest wire:target="activeTab, sort" class="mt-4">
        <flux:icon.loading />
    </div>

    <div wire:loading.remove.delay.shortest wire:target="activeTab, sort" class="mt-4 flex flex-col gap-2">
        @if ($this->filteredFiles->isNotEmpty())
        @foreach ($this->filteredFiles as $file)
        <livewire:file-results :lazy="$loop->index > 10 ? 'on-load' : ''" :file="$file" wire:key="{{ $file->id }}" />
        @endforeach
        @else
        <flux:subheading>No results found</flux:subheading>
        @endif
    </div>
    @endif
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
