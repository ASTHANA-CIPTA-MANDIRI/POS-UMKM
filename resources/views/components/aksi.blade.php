@props(['warna' => 'netral', 'label'])

{{--
    Tombol aksi di kolom Action.

    SEMUA tombol aksi berukuran sama (36×36) dan hanya berisi ikon. Ini yang membuat
    barisnya sejajar rapi di setiap baris tabel: begitu satu tombol memakai teks dan
    yang lain ikon, lebarnya berbeda-beda dan kolomnya bergerigi dari baris ke baris.

    Karena ikon saja tidak menjelaskan dirinya, label WAJIB diisi — dipakai sekaligus
    untuk aria-label (pembaca layar) dan title (tooltip). Ikon tanpa label adalah
    tombol yang hanya bisa dipakai oleh orang yang sudah tahu fungsinya.
--}}
@php
    $gaya = match ($warna) {
        // Aksi utama baris: bergaris, jadi ia yang pertama terlihat.
        'utama' => 'border-line bg-white text-ink hover:border-terracotta hover:text-terracotta',
        // Bisa dibalik kapan saja: tanpa garis, bobotnya paling ringan.
        'netral' => 'border-transparent text-umber hover:bg-cream hover:text-ink',
        // Tidak bisa dibatalkan: kelabu dulu, merah hanya saat disentuh, supaya
        // tidak memancing untuk ditekan sambil lalu.
        'bahaya' => 'border-transparent text-umber-soft hover:bg-merah/10 hover:text-merah-deep',
        default => 'border-transparent text-umber hover:bg-cream',
    };
@endphp

<button
    {{ $attributes->merge([
        'type' => 'button',
        'class' => "grid size-9 shrink-0 cursor-pointer place-items-center rounded-lg border transition-colors {$gaya}",
    ]) }}
    aria-label="{{ $label }}"
    title="{{ $label }}"
>
    {{ $slot }}
</button>
