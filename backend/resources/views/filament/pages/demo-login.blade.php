<x-filament-panels::page.simple>
    <p style="text-align:center;line-height:1.6">Explore a working CMS and event pipeline. Publish a change, watch it retry, then bring both systems back into sync.</p>
    <form method="POST" action="{{ route('demo.sign-in') }}">
        @csrf
        <x-filament::button type="submit" size="lg" style="width:100%">View demo</x-filament::button>
    </form>
    <p style="text-align:center;font-size:13px;line-height:1.6">This is a shared demo with sample data. Faults switch off after 3 minutes. The demo is rebuilt nightly.</p>
</x-filament-panels::page.simple>
