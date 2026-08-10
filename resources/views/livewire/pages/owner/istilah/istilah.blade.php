{{--
    Halaman Istilah: arti tiap kata yang dipakai aplikasi, dalam bahasa warung.

    Data dari komponen: $kelompok (array kelompok → daftar istilah), $jumlah, $jumlahSemua.

    BENTUKNYA BUKAN TABEL dan bukan accordion.

    Bukan tabel karena isinya kalimat, bukan angka — kolom yang sempit memaksa kalimatnya
    dipotong, dan penjelasan yang terpotong tidak menjelaskan apa pun.

    Bukan accordion (semua tertutup, dibuka satu-satu) karena halaman ini dibaca SEKALI di
    awal, berurutan, oleh orang yang belum tahu istilah mana yang ia butuhkan. Menutup semuanya
    memaksa dua puluh ketukan untuk membaca dua puluh kalimat pendek — dan orang yang harus
    menekan dua puluh kali untuk tahu apa isinya akan berhenti di ketukan ketiga.
--}}
<div>
    <x-kartu-alat
        judul="Arti istilah"
        jumlah="{{ $jumlahSemua }}"
        keterangan="Kata-kata yang dipakai di aplikasi ini, dijelaskan dengan bahasa sehari-hari berikut contohnya dalam rupiah. Tidak perlu dihafal — cari saja saat butuh."
    >
        <x-slot:saringan>
            <div class="relative min-w-0">
                <label for="cari-istilah" class="sr-only">Cari istilah</label>
                <svg viewBox="0 0 20 20" class="pointer-events-none absolute top-1/2 left-4 size-4 -translate-y-1/2 text-umber-soft"
                     fill="none" aria-hidden="true">
                    <circle cx="9" cy="9" r="5.5" stroke="currentColor" stroke-width="1.6" />
                    <path d="m13.5 13.5 3 3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
                </svg>
                {{-- Petunjuk isinya memakai kata GEJALA, bukan nama istilah: orang yang bingung
                     tidak tahu istilahnya, ia tahu apa yang sedang ia cari. --}}
                <input id="cari-istilah" type="search" wire:model.live.debounce.300ms="cari"
                       placeholder="Ketik yang sedang dicari — mis. sewa, utang, untung…"
                       class="h-12 w-full rounded-xl border border-line bg-white pr-4 pl-11 text-[0.9375rem] text-ink focus:border-terracotta focus:outline-none">
            </div>
        </x-slot:saringan>
    </x-kartu-alat>

    @if ($jumlah === 0)
        <x-kosong
            ikon="cari"
            judul="Tidak ada istilah yang cocok"
            keterangan="Coba kata lain, atau kosongkan kotak pencarian untuk melihat semuanya. Kalau ada kata di aplikasi yang belum ada di sini, itu kekurangan kami — bukan kekurangan Anda."
        />
    @else
        <div class="space-y-4">
            @foreach ($kelompok as $namaKelompok => $daftar)
                <div class="kartu p-5" wire:key="kelompok-{{ Str::slug($namaKelompok) }}">
                    <h2 class="text-[1rem] font-bold text-ink">{{ $namaKelompok }}</h2>

                    <dl class="mt-3 space-y-4">
                        @foreach ($daftar as $kunci => $isi)
                            {{-- id= dipakai tautan "Lihat semua istilah" dari gelembung
                                 penjelasan di layar lain, supaya halaman ini terbuka LANGSUNG
                                 di kata yang tadi ditanyakan — bukan di paling atas, yang
                                 memaksa orangnya mencari lagi dari nol. --}}
                            <div id="{{ $kunci }}" class="scroll-mt-24 border-t border-line-soft pt-4 first:border-0 first:pt-0"
                                 wire:key="istilah-{{ $kunci }}">
                                <dt class="text-[0.9375rem] font-bold text-ink">{{ $isi['istilah'] }}</dt>
                                <dd class="mt-1 text-[0.9375rem] leading-relaxed text-umber">
                                    {{ $isi['arti'] }}

                                    @if ($isi['contoh'])
                                        <span class="mt-2 block rounded-xl border border-line bg-cream/70 px-3.5 py-2.5 text-[0.875rem] text-ink">
                                            <span class="font-semibold">Contohnya:</span> {{ $isi['contoh'] }}
                                        </span>
                                    @endif

                                    @if ($isi['lihatJuga'] !== [])
                                        {{-- Tautan silang, bukan mengulang penjelasannya di sini:
                                             istilah yang dijelaskan dua kali akan bercabang pada
                                             perbaikan pertama. --}}
                                        <span class="mt-2 block text-[0.8125rem] text-umber-soft">
                                            Berhubungan dengan:
                                            {{-- inline-block, bukan inline biasa, dan alasannya DUA.

                                                 Pertama untuk orangnya: tautan yang terbelah ke
                                                 dua baris jadi dua sasaran sentuh yang
                                                 masing-masing setengah — dan yang paling sering
                                                 meleset jari orang tua.

                                                 Kedua untuk alat ukurnya: getBoundingClientRect()
                                                 pada elemen inline yang membungkus mengembalikan
                                                 kotak GABUNGAN kedua barisnya, yang lalu
                                                 tampak bertumpuk dengan tetangganya. Terukur:
                                                 tiga "tumpang tindih" palsu di 390px yang tidak
                                                 ada satu pun di layar. --}}
                                            @foreach ($isi['lihatJuga'] as $lain)
                                                @php($tetangga = \App\Support\Istilah::ambil($lain))
                                                @if ($tetangga)
                                                    <a href="#{{ $lain }}"
                                                       class="inline-block font-semibold text-terracotta-deep underline decoration-terracotta/40 underline-offset-2">{{ $tetangga['istilah'] }}@if (! $loop->last),@endif</a>
                                                @endif
                                            @endforeach
                                        </span>
                                    @endif
                                </dd>
                            </div>
                        @endforeach
                    </dl>
                </div>
            @endforeach
        </div>
    @endif
</div>
