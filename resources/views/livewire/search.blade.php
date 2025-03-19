<?php

use App\Models\Search;
use App\Enums\SearchStatus;
use Livewire\Volt\Component;
use Livewire\Attributes\{Title, Computed};
use Illuminate\Support\Facades\Log;

new #[Title('Search Results')] class extends Component {
    use \Livewire\WithPagination;

    public int $id;
    public Search $search;
    public string $query;
    /** @var \App\Models\AudioFile[] */
    public $files;
    public string $summarySortBy = 'parsed_date';
    public string $summarySortDirection = 'desc';

    public function mount($id)
    {
        $this->id = $id;
        $this->search = Search::findOrFail($id);

        $this->authorize('view', $this->search);

        $this->query = $this->search->query;
        $this->files = $this->search->files;

        foreach ($this->files as $file) {
            Log::info('Search View: search "{query}" - adding file {name}', ['query' => $this->query, 'name' => $file->audio_filename]);
        }
    }

    public function delete()
    {
        try {
            Log::info('Search: deleting search {search}', ['search' => $this->search->id]);
            $this->search->delete();
            $this->redirectRoute('history', navigate: true);
        } catch (\Exception $e) {
            Log::error('Search: error deleting search {search}: {exception}', ['search' => $this->search, 'exception' => $e]);
        }
    }

    public function sort($column)
    {
        if ($this->summarySortBy === $column) {
            $this->summarySortDirection = $this->summarySortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->summarySortBy = $column;
            $this->summarySortDirection = 'asc';
        }
    }

    #[Computed]
    public function summaryFiles()
    {
        return $this->search->files()->where('query_count', '>', 0)
            ->tap(fn($query) => $this->summarySortBy ? $query->orderBy($this->summarySortBy, $this->summarySortDirection) : $query)
            ->paginate(10);
    }
}; ?>

<div>
    <div class="flex flex-row flex-wrap items-center justify-between gap-2">
        <flux:heading size="xl" level="1">Results</flux:heading>
        <flux:modal.trigger name="delete-search">
            <flux:button size="sm" variant="danger" icon="trash">Delete</flux:button>
        </flux:modal.trigger>
    </div>

    <livewire:confirm-delete @delete="delete" name="delete-search" />

    <flux:subheading class="mt-2">Searching {{ count($files) }} files for "{{ $query }}"</flux:subheading>

    <div class="flex flex-row flex-wrap items-center justify-between gap-2">
        <flux:heading size="lg" level="2" class="mt-8">Summary</flux:heading>
    </div>
    <div @if ($search->status !== SearchStatus::Completed) wire:poll.2s @endif>
        @if ($search->status === SearchStatus::Completed && $search->query_total > 0)
        <flux:table :paginate="$this->summaryFiles">
            <flux:table.columns>
                <flux:table.column sortable :sorted="$summarySortBy === 'parsed_date'" :direction="$summarySortDirection" wire:click="sort('parsed_date')">File</flux:table.column>
                <flux:table.column sortable :sorted="$summarySortBy === 'query_count'" :direction="$summarySortDirection" wire:click="sort('query_count')">Matches</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->summaryFiles as $file)
                <flux:table.row :key="$file->id">
                    @if ($file->parsed_date)
                    <flux:table.cell>{{ $file->parsed_date->toDayDateTimeString() }}</flux:table.cell>
                    @else
                    <flux:table.cell>{{ $file->audio_filename }}</flux:table.cell>
                    @endif

                    <flux:table.cell>{{ $file->query_count }}</flux:table.cell>
                </flux:table.row>
                @endforeach

                <flux:table.row class="border-t-2 border-t-accent font-extrabold">
                    <flux:table.cell>Total</flux:table.cell>
                    <flux:table.cell>{{ $search->query_total }}</flux:table.cell>
                </flux:table.row>
            </flux:table.rows>
        </flux:table>
        @elseif ($search->status === SearchStatus::Processing)
        <flux:icon.loading variant="micro" class="mt-4" />
        @else
        <flux:subheading>No matches found</flux:subheading>
        @endif
    </div>


    <flux:heading size="lg" level="2" class="mt-8">Files</flux:heading>
    <div class="mt-4 flex flex-col gap-4">
        @foreach ($files as $file)
        <livewire:result-card :lazy="$loop->index > 10 ? 'on-load' : ''" :file="$file" wire:key="{{ $file->id }}" />
        @endforeach
    </div>
</div>
