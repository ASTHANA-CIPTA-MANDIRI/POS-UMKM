@props(['judul', 'keterangan' => null, 'jumlah' => null])

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
    {{-- Baris judul dan KETERANGAN dipisah, bukan keterangan yang ikut menumpuk di kolom
         kiri bersama judulnya.

         Alasannya terukur di 390px: selama keterangan berada satu kolom dengan judul,
         kolom itu selebar kalimat penuh (max-content), sehingga tombol aksi selalu
         terdorong turun ke baris berikutnya — di layar Stok tombolnya duduk 88px DI BAWAH
         judulnya. Dan memaksa kolom itu menyusut supaya tombolnya naik justru memerasnya
         jadi ±90px, tempat kalimat sepanjang ini jatuh satu-dua kata per baris.

         Dengan keterangan pindah ke bawah, barisnya cuma berisi judul + jumlah + aksi, dan
         tombolnya SEJAJAR dengan judulnya sejak ponsel — sementara kalimatnya tetap dapat
         lebar penuh untuk dibaca. --}}
    <div class="px-5 py-4 sm:px-6 sm:py-5">
        <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-3">
            {{-- flex-1 dengan basis 0: judulnya boleh menyusut supaya aksi tetap sebaris,
                 dan boleh melebar mengisi sisa ruang kalau aksinya pendek. --}}
            <div class="flex min-w-0 flex-1 flex-wrap items-center gap-2">
                <h2 class="text-[1.0625rem] font-bold text-ink">{{ $judul }}</h2>

                @if ($jumlah !== null)
                    <span class="tabular rounded-full bg-cream-deep px-2.5 py-0.5 text-[0.75rem] font-semibold text-umber">
                        {{ $jumlah }}
                    </span>
                @endif
            </div>

            {{-- min-w-0, bukan shrink-0: dua kontrol berdampingan yang menolak menyusut
                 akan mendorong lebar halaman di ponsel.

                 Kelasnya ditulis sebagai atribut biasa, BUKAN `{{ $kondisi ? 'class="…"' : … }}`.
                 Bentuk lama itu diloloskan escape oleh Blade, jadi yang sampai ke peramban
                 adalah `class=&quot;flex` — nama kelasnya berubah jadi tanda kutip dan SELURUH
                 kelasnya tidak pernah terpasang. Tidak ada satu pun angka pemeriksa yang
                 menangkapnya; yang terlihat hanya tombol yang letaknya "agak aneh". --}}
            @if (isset($aksi))
                <div class="flex min-w-0 flex-wrap items-center gap-2">{{ $aksi }}</div>
            @endif
        </div>

        @if ($keterangan)
            <p class="mt-2 max-w-2xl text-[0.875rem] text-umber">{{ $keterangan }}</p>
        @endif
    </div>

    @if (isset($saringan))
        <div class="border-t border-line-soft bg-cream/50 px-5 py-4 sm:px-6">{{ $saringan }}</div>
    @endif
</div>
