<?php

use App\Models\Search;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\{Title, Computed};
use Livewire\Volt\Component;

new #[Title('History')] class extends Component {
    use \Livewire\WithPagination;

    public string $sortBy = 'created_at';
    public string $sortDirection = 'desc';

    public function delete(Search $search)
    {
        try {
            Log::info('History: deleting search {search}', ['search' => $search->id]);
            $search->delete();
            $this->redirectRoute('history', navigate: true);
        } catch (\Exception $e) {
            Log::error('History: error deleting search {search}: {exception}', ['search' => $search, 'exception' => $e]);
        }
    }

    public function sort($column)
    {
        if ($this->sortBy === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDirection = 'asc';
        }
    }

    #[Computed]
    public function searches()
    {
        return Auth::user()->searches()
            ->tap(fn($query) => $this->sortBy ? $query->orderBy($this->sortBy, $this->sortDirection) : $query)
            ->paginate(20);
    }
}; ?>

<div>
    <flux:heading size="xl" level="1">History</flux:heading>

    <flux:table :paginate="$this->searches">
        <flux:table.columns>
            <flux:table.column sortable :sorted="$sortBy === 'query'" :direction="$sortDirection" wire:click="sort('query')">Query</flux:table.column>
            <flux:table.column sortable :sorted="$sortBy === 'query_total'" :direction="$sortDirection" wire:click="sort('query_total')">Matches</flux:table.column>
            <flux:table.column sortable :sorted="$sortBy === 'created_at'" :direction="$sortDirection" wire:click="sort('created_at')">Date</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @foreach ($this->searches as $search)
            <flux:table.row wire:key="{{ $search->id }}">
                <flux:table.cell>
                    <flux:button
                        href="search/{{ $search->id }}"
                        wire:navigate.hover
                        variant="ghost"
                        icon-trailing="arrow-top-right-on-square"
                        label="View the results for '{{ $search->query }}'">
                        {{ $search->query }}
                    </flux:button>
                </flux:table.cell>
                <flux:table.cell>{{ $search->query_total ? $search->query_total : '0' }}</flux:table.cell>
                <flux:table.cell>{{ $search->created_at->toDayDateTimeString() }}</flux:table.cell>

                <flux:table.cell>
                    <flux:dropdown position="bottom" align="start">
                        <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" inset="top bottom"></flux:button>

                        <flux:menu>
                            <flux:menu.item icon="trash" variant="danger">Delete</flux:menu.item>
                        </flux:menu>
                    </flux:dropdown>
                </flux:table.cell>
            </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>
</div>
