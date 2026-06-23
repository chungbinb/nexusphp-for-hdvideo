<x-filament-panels::page>
    <x-filament-panels::form wire:submit="submit">
        {{ $this->content }}

        <x-filament::button type="submit">
            {{ __('label.save') }}
        </x-filament::button>
    </x-filament-panels::form>
</x-filament-panels::page>
