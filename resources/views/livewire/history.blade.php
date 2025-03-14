<?php

use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Title('History')] class extends Component {}; ?>

<div>
    <flux:table>
        <flux:table.columns>
            <flux:table.column>Query</flux:table.column>
            <flux:table.column>Date</flux:table.column>
            <flux:table.column>Matches</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @foreach (Auth::user()->searches as $search)
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
                <flux:table.cell>{{ $search->created_at }}</flux:table.cell>
                <flux:table.cell variant="strong">{{ $search->query_total ? $search->query_total : '0' }}</flux:table.cell>
            </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>
</div>
