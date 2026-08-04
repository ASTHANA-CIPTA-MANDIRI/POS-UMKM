@props(['judul', 'keterangan' => null, 'jumlah' => null, 'aksiPenuh' => false])

{{--
    Kartu alat: kepala halaman (judul seksi, jumlah, aksi) menyatu dengan barisan
    saringan di bawahnya.

    Sebelumnya keterangan dan saringan berdiri sebagai dua blok terpisah tanpa wadah,
    dan batas antara "penjelasan halaman" dan "alat untuk menyaring" tidak terbaca.
    Disatukan dalam satu kartu dengan pita bertint, keduanya jelas satu kelompok.

    Judul di sini adalah judul SEKSI, bukan judul halaman — judul halaman sudah
    dicetak besar di navbar mengikuti pola Horizon. Mencetaknya dua kali membuat
    halaman terlihat seperti dua halaman bertumpuk.
--}}
<div {{ $attributes->merge(['class' => 'kartu mb-4 overflow-hidden sm:mb-5']) }}>
    <div class="flex flex-wrap items-start justify-between gap-4 px-5 py-4 sm:px-6 sm:py-5">
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
                <h2 class="text-[1.0625rem] font-bold text-ink">{{ $judul }}</h2>

                @if ($jumlah !== null)
                    <span class="tabular rounded-full bg-cream-deep px-2.5 py-0.5 text-[0.75rem] font-semibold text-umber">
                        {{ $jumlah }}
                    </span>
                @endif
            </div>

            @if ($keterangan)
                <p class="mt-1 max-w-2xl text-[0.875rem] text-umber">{{ $keterangan }}</p>
            @endif
        </div>

        {{-- min-w-0, bukan shrink-0: dua kontrol berdampingan yang menolak menyusut
             akan mendorong lebar halaman di ponsel.

             `aksiPenuh` MEMBUKA ruang selebar kartu di ponsel, dan hanya dipakai halaman
             yang tombolnya memang tindakan utama (mis. "Hitung stok sekarang"). Tanpa itu
             wadahnya selebar isinya, sehingga tautan kembali seperti "Daftar stok" duduk
             SEJAJAR dengan judul seksinya alih-alih turun jadi tombol selebar kartu — dan
             tombol selebar kartu terbaca setara dengan tindakan utama, padahal bukan. --}}
        @if (isset($aksi))
            <div {{ ($aksiPenuh ?? false) ? 'class="flex min-w-0 flex-wrap items-center gap-2 max-sm:w-full"' : 'class="flex min-w-0 flex-wrap items-center gap-2"' }}>{{ $aksi }}</div>
        @endif
    </div>

    @if (isset($saringan))
        <div class="border-t border-line-soft bg-cream/50 px-5 py-4 sm:px-6">{{ $saringan }}</div>
    @endif
</div>
