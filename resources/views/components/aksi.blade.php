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
        /*
         * Tidak bisa dibatalkan: MERAH SEJAK AWAL.
         *
         * Bentuk lama kelabu dan baru merah saat disentuh, dengan alasan "supaya tidak
         * memancing untuk ditekan sambil lalu". Alasannya sah untuk tetikus, tapi salah
         * untuk aplikasi ini: layar owner dipakai di tablet dan HP, dan di sana HOVER TIDAK
         * ADA — jadi tanda bahayanya tidak pernah muncul, tepat di tempat salah-tekan paling
         * mungkin terjadi. Tombol hapus yang terlihat sama dengan tombol lain adalah tombol
         * hapus yang akan tertekan.
         *
         * Yang menahannya tetap tidak memancing: tanpa latar dan tanpa garis, jadi bobotnya
         * tetap paling ringan di barisnya — yang membedakan warnanya, bukan besarnya.
         * merah-tua (7,14:1 di atas putih), bukan merah-deep yang jatuh ke 4,15:1 begitu
         * latarnya bertint saat disentuh.
         */
        'bahaya' => 'border-transparent text-merah-tua hover:bg-merah/10 hover:text-merah-tua',
        default => 'border-transparent text-umber hover:bg-cream',
    };
@endphp

{{--
    Keadaan MATI harus terlihat mati.

    Ditambahkan sesudah tombol "Nonaktifkan" pada baris akun sendiri terbukti terender sama
    persis dengan tombol yang hidup: atributnya benar (hanya baris itu yang `disabled`), tapi
    matanya tidak bisa membedakan. Tombol yang tampak bisa ditekan lalu diam saat ditekan
    terbaca sebagai aplikasi yang rusak, bukan sebagai aturan.

    `disabled:hover:*` ikut dikembalikan ke keadaan diam: tanpa itu, latar hover tetap muncul
    saat kursor lewat, dan justru itu yang paling meyakinkan orang bahwa tombolnya hidup.
--}}
<button
    {{ $attributes->merge([
        'type' => 'button',
        'class' => "grid size-9 shrink-0 cursor-pointer place-items-center rounded-lg border transition-colors {$gaya}"
            .' disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:bg-transparent disabled:hover:text-inherit',
    ]) }}
    aria-label="{{ $label }}"
    title="{{ $label }}"
>
    {{ $slot }}
</button>
