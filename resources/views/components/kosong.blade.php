@props(['judul', 'keterangan' => null, 'ikon' => 'kotak'])

{{--
    Keadaan kosong yang MENUNTUN, bukan sekadar memberitahu bahwa data tidak ada.

    Diberi ikon dalam bidangnya sendiri, bukan teks telanjang di tengah kotak putus-putus:
    layar kosong adalah kesan PERTAMA yang didapat pemilik baru — sebelum ada satu pun
    barang, inilah seluruh isi layarnya. Kalau di situ ia terlihat belum selesai, kesan itu
    menempel ke seluruh aplikasi.

    Latarnya gradien tipis ke arah cream supaya bidang kosongnya terbaca sebagai sesuatu
    yang disengaja, bukan tempat yang gagal terisi. Garis putus-putusnya dipertahankan —
    itu bahasa "belum ada isinya" yang sudah dikenal, dan ia membedakan keadaan kosong dari
    kartu biasa tanpa perlu tulisan tambahan.
--}}
@php
    $jalurIkon = match ($ikon) {
        // Kotak/barang — untuk daftar produk, stok, bahan baku.
        'kotak' => 'M4 7.5 12 4l8 3.5v9L12 20l-8-3.5v-9Zm0 0 8 3.5m0 0 8-3.5M12 11v9',
        // Kaca pembesar — untuk hasil pencarian/saringan yang tidak cocok.
        'cari' => 'M9 15.5a6.5 6.5 0 1 1 0-13 6.5 6.5 0 0 1 0 13Zm4.6.1L19 21',
        // Lembar — untuk riwayat/mutasi yang belum ada.
        'lembar' => 'M6 3.5h9l3 3v14H6v-17Zm3 6h6M9 13h6M9 16.5h3',
        // Orang — untuk daftar pelanggan/karyawan yang belum ada. Bentuknya disamakan dengan
        // ikon 'orang' di sidebar supaya butir menu dan layar kosongnya saling mengenali.
        'orang' => 'M12 11a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Zm-7 9c0-3.3 3.1-6 7-6s7 2.7 7 6',
        default => 'M4 7.5 12 4l8 3.5v9L12 20l-8-3.5v-9Zm0 0 8 3.5m0 0 8-3.5M12 11v9',
    };
@endphp

<div {{ $attributes->merge(['class' => 'rounded-[20px] border border-dashed border-line bg-gradient-to-b from-white to-cream px-4 py-12 text-center sm:py-14']) }}>
    <span class="mx-auto grid size-14 place-items-center rounded-2xl bg-white text-umber-soft shadow-[0_6px_16px_-10px_rgb(112_144_176/0.6)]">
        <svg viewBox="0 0 24 24" class="size-7" fill="none" aria-hidden="true">
            <path d="{{ $jalurIkon }}" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
        </svg>
    </span>

    <p class="mt-4 text-[1rem] font-bold text-ink">{{ $judul }}</p>

    @if ($keterangan)
        <p class="mx-auto mt-1.5 max-w-md text-[0.8125rem] leading-relaxed text-umber">{{ $keterangan }}</p>
    @endif

    @if (isset($aksi))
        <div class="mt-5 flex flex-wrap justify-center gap-2">{{ $aksi }}</div>
    @endif
</div>
