@props(['value' => null, 'label'])
{{-- Configured legal value, or a visible "to be defined" marker when the env var is empty. Kept on one line: no extra whitespace. --}}
@if ($value){{ $value }}@else<mark class="todo" title="Defina no .env antes de publicar">[a definir: {{ $label }}]</mark>@endif