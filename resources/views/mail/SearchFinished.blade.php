<x-mail::message>
# Your search for "{{ $query }}" has finished processing.

Files searched: {{ $count }}<br>
Matches found: {{ $query_total }}

<x-mail::button :url="$url">
View Results
</x-mail::button>

Happy searching,<br>
{{ config('app.name') }}
</x-mail::message>
