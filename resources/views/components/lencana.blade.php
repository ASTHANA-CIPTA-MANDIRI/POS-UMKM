@props(['warna' => 'netral', 'denyut' => true])

{{--
    Lencana status.

    Titik di depan teks membuat statusnya terbaca tanpa bergantung pada warna saja —
    syarat yang sama yang berlaku untuk pembaca layar dan mata yang sulit membedakan
    warna. Titiknya berdenyut supaya perubahan status menarik mata tanpa perlu teks
    tambahan.
--}}
@php
    $gaya = match ($warna) {
        'hijau' => 'bg-hijau/12 text-hijau-tua',
        'jingga' => 'bg-jingga/15 text-jingga-tua',
        'merah' => 'bg-merah/10 text-merah-deep',
        default => 'bg-cream-deep text-umber',
    };

    $titik = match ($warna) {
        'hijau' => 'text-hijau',
        'jingga' => 'text-jingga-deep',
        'merah' => 'text-merah-deep',
        default => 'text-umber-soft',
    };
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center gap-2 rounded-full px-2.5 py-1 text-[0.75rem] font-semibold whitespace-nowrap {$gaya}"]) }}>
    <span class="grid size-1.5 shrink-0 place-items-center {{ $titik }}" aria-hidden="true">
        <span @class(['size-1.5 rounded-full bg-current', 'titik-denyut' => $denyut])></span>
    </span>
    {{ $slot }}
</span>
