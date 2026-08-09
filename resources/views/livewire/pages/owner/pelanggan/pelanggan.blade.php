{{--
    Kelola pelanggan (orang yang namanya ditempeli kasbon dan struk).

    Data dari komponen: $daftar (paginator Customer dengan `sisa_utang` hasil withSum),
    $jumlahPelanggan, $totalPiutang, plus properti formulir.

    Bentuknya menyalin layar Stok & hitung stok — patokan responsif di CLAUDE.md — bukan
    mengarang sendiri: kartu di bawah lg, tabel `table-fixed` berlebar PERSEN di atasnya,
    sel kosong selalu bertanda "—", dan 10 baris per halaman dengan ->links() yang selalu
    dirender.
--}}
@php
    /*
     * Kalimat dialog hapus diketik SEKALI di sini, bukan dua kali.
     *
     * Barisnya digambar dua bentuk (kartu di ponsel, tabel di layar lebar), jadi teks yang
     * ditulis di masing-masing tempat pasti bercabang begitu salah satunya diperbaiki.
     *
     * Isinya menyebut apa yang TIDAK hilang: pelanggan memakai soft delete, jadi struk lama
     * dan catatan kasbon lama tetap terbaca apa adanya. Peringatan yang lebih menakutkan
     * daripada kenyataannya membuat orang berhenti memercayai peringatan berikutnya.
     */
    $pesanHapusPelanggan = 'Namanya hilang dari daftar ini. Struk lama dan catatan kasbon '
        .'yang sudah lunas tetap tersimpan. Pelanggan yang masih berutang tidak bisa dihapus.';

    $rupiah = fn ($nilai) => 'Rp '.number_format((float) $nilai, 0, ',', '.');
@endphp

{{-- x-data di akar: pemicu hapus memakai x-on:click, dan Alpine hanya menghidupkan elemen
     yang berada di dalam sebuah lingkup x-data. Tanpa ini tombol hapusnya diam saja —
     tanpa galat yang terlihat di layar. --}}
<div x-data>
    <x-kartu-alat
        judul="Pelanggan"
        jumlah="{{ $jumlahPelanggan }}"
        keterangan="Orang yang namanya ditempeli kasbon dan struk. Nomor HP-nya yang dipakai membedakan orang — nama boleh kembar, nomor tidak."
    >
        <x-slot:aksi>
            <button type="button" wire:click="tambah"
                    class="flex h-11 cursor-pointer items-center gap-2 rounded-xl bg-terracotta px-5 text-[0.875rem] font-bold text-white transition-colors hover:bg-terracotta-deep">
                <svg viewBox="0 0 20 20" class="size-4" fill="none" aria-hidden="true">
                    <path d="M10 4v12M4 10h12" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
                </svg>
                {{-- Bentuk pendek di ponsel: judul kartu pecah jadi tiga baris kalau
                     tombolnya selebar "Pelanggan baru" (terukur di layar Stok). --}}
                <span class="sm:hidden">Baru</span>
                <span class="hidden sm:inline">Pelanggan baru</span>
            </button>
        </x-slot:aksi>

        <x-slot:saringan>
            <div class="grid gap-3 sm:grid-cols-[1fr_auto]">
                <div class="relative min-w-0">
                    <label for="cari-pelanggan" class="sr-only">Cari pelanggan</label>
                    <svg viewBox="0 0 20 20" class="pointer-events-none absolute top-1/2 left-4 size-4 -translate-y-1/2 text-umber-soft"
                         fill="none" aria-hidden="true">
                        <circle cx="9" cy="9" r="5.5" stroke="currentColor" stroke-width="1.6" />
                        <path d="m13.5 13.5 3 3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
                    </svg>
                    <input id="cari-pelanggan" type="search" wire:model.live.debounce.300ms="cari"
                           placeholder="Cari nama atau nomor HP…"
                           class="h-11 w-full rounded-xl border border-line bg-white pr-4 pl-11 text-[0.875rem] text-ink focus:border-terracotta focus:outline-none">
                </div>

                {{-- Saringan "masih berutang" adalah jalan tercepat dari daftar ini ke
                     pekerjaan yang sebenarnya dibawa orang ke sini: menagih. Dibuat tombol
                     dwi-keadaan, bukan <select>, karena pilihannya cuma dua dan satu ketukan
                     lebih pendek daripada membuka daftar. --}}
                <button type="button" wire:click="$toggle('hanyaBerutang')"
                        aria-pressed="{{ $hanyaBerutang ? 'true' : 'false' }}"
                        @class([
                            'flex h-11 cursor-pointer items-center justify-center gap-2 rounded-xl border px-4 text-[0.875rem] font-semibold transition-colors',
                            'border-terracotta bg-terracotta/10 text-terracotta-deep' => $hanyaBerutang,
                            'border-line text-umber hover:bg-cream' => ! $hanyaBerutang,
                        ])>
                    <svg viewBox="0 0 20 20" class="size-4" fill="none" aria-hidden="true">
                        <path d="M4 5h12M4 10h12M4 15h7" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
                    </svg>
                    Masih berutang
                </button>
            </div>
        </x-slot:saringan>
    </x-kartu-alat>

    {{-- Total piutang ditulis di ATAS daftar, bukan disembunyikan di dasbor saja: orang yang
         membuka layar pelanggan sedang memikirkan uang yang belum kembali, dan angka itu
         menentukan apakah ia perlu menyaring "masih berutang" sama sekali.

         Hanya muncul kalau ADA piutangnya. Kotak berbunyi "Rp 0" tiap hari mengajarkan mata
         untuk melewatinya, dan hari angkanya benar-benar besar ia ikut terlewat. --}}
    @if ($totalPiutang > 0)
        <div class="kartu mb-4 flex flex-wrap items-center justify-between gap-3 px-5 py-4">
            <div class="min-w-0">
                <p class="eyebrow text-umber-soft">Kasbon belum lunas</p>
                <p class="tabular mt-0.5 text-[1.375rem] font-bold text-ink">{{ $rupiah($totalPiutang) }}</p>
            </div>
            <p class="text-[0.8125rem] text-umber">
                Uang yang sudah keluar tapi belum kembali. Angka ini sama dengan yang di dasbor.
            </p>
        </div>
    @endif

    {{-- ── Daftar ────────────────────────────────────────────────────────── --}}
    @if ($daftar->isEmpty())
        <x-kosong
            :ikon="$cari !== '' || $hanyaBerutang ? 'cari' : 'orang'"
            judul="{{ $hanyaBerutang
                ? 'Tidak ada yang berutang'
                : ($cari !== '' ? 'Tidak ada pelanggan yang cocok' : 'Belum ada pelanggan') }}"
            keterangan="{{ $hanyaBerutang
                ? 'Semua kasbon sudah lunas. Matikan saringannya untuk melihat seluruh pelanggan.'
                : ($cari !== ''
                    ? 'Coba ubah kata pencariannya, atau tambahkan pelanggan baru.'
                    : 'Masukkan pelanggan yang biasa berutang dulu — namanya dipakai saat mencatat kasbon, dan nomornya yang membedakan orang.') }}"
        >
            <x-slot:aksi>
                @if ($hanyaBerutang)
                    <button type="button" wire:click="$set('hanyaBerutang', false)"
                            class="h-11 cursor-pointer rounded-xl border border-line px-5 text-[0.875rem] font-semibold text-ink transition-colors hover:bg-cream">
                        Lihat semua pelanggan
                    </button>
                @else
                    <button type="button" wire:click="tambah"
                            class="h-11 cursor-pointer rounded-xl bg-terracotta px-5 text-[0.875rem] font-bold text-white transition-colors hover:bg-terracotta-deep">
                        Pelanggan baru
                    </button>
                @endif
            </x-slot:aksi>
        </x-kosong>
    @else
        {{-- Dua bentuk untuk data yang sama: kartu di layar sempit, tabel di ≥lg. Tabel yang
             dipaksa masuk ke ponsel menuntut gulir mendatar, dan kolom kasbon justru yang
             pertama hilang dari pandangan. --}}
        <div class="space-y-3 lg:hidden">
            @foreach ($daftar as $pelanggan)
                @php $utang = (float) ($pelanggan->sisa_utang ?? 0); @endphp
                <div class="kartu p-4" wire:key="kartu-{{ $pelanggan->id }}">
                    <div class="min-w-0">
                        <p class="truncate text-[0.9375rem] font-bold text-ink">{{ $pelanggan->nama }}</p>
                        <p class="mt-0.5 truncate text-[0.75rem] text-umber">
                            {{ $pelanggan->email ?: 'tanpa email' }}
                        </p>
                    </div>

                    <div class="mt-3 grid grid-cols-2 gap-3">
                        <div class="min-w-0 rounded-xl border border-line px-3 py-2">
                            <p class="text-[0.6875rem] font-semibold tracking-wide text-umber-soft uppercase">Nomor HP</p>
                            @if ($pelanggan->no_hp)
                                {{-- Tautan wa.me, bukan tombol tagih. Bedanya disengaja: ini
                                     cuma membuka percakapan, sementara "tagih lewat WhatsApp
                                     sekali tekan" (di RENCANA) menyusun teks tagihannya
                                     sendiri dan itu pekerjaan tersendiri. --}}
                                <a href="https://wa.me/{{ \App\Support\NomorHp::untukWhatsapp($pelanggan->no_hp) }}"
                                   target="_blank" rel="noopener"
                                   class="tabular block truncate text-[0.9375rem] font-bold text-terracotta-deep underline decoration-terracotta/40 underline-offset-2">
                                    {{ $pelanggan->no_hp }}
                                </a>
                            @else
                                {{-- "—" WAJIB, bukan kosong: kosong membuat pembacanya menebak
                                     apakah datanya hilang atau memang tidak ada. --}}
                                <p class="text-[0.9375rem] font-bold text-umber-soft">—</p>
                                <p class="text-[0.75rem] font-medium text-umber-soft">belum diisi</p>
                            @endif
                        </div>

                        <div class="min-w-0 rounded-xl border border-line px-3 py-2">
                            <p class="text-[0.6875rem] font-semibold tracking-wide text-umber-soft uppercase">Kasbon</p>
                            @if ($utang > 0)
                                <p class="tabular text-[0.9375rem] font-bold text-merah-deep">{{ $rupiah($utang) }}</p>
                                <p class="text-[0.75rem] font-medium text-umber">belum lunas</p>
                            @else
                                <p class="text-[0.9375rem] font-bold text-umber-soft">—</p>
                                <p class="text-[0.75rem] font-medium text-umber-soft">tidak ada utang</p>
                            @endif
                        </div>
                    </div>

                    <div class="mt-3 flex items-center justify-end gap-1.5">
                        <x-aksi warna="utama" label="Ubah {{ $pelanggan->nama }}"
                                class="size-10" wire:click="ubah('{{ $pelanggan->id }}')">
                            <svg viewBox="0 0 20 20" class="size-4" fill="none" aria-hidden="true">
                                <path d="M13 3.5 16.5 7 8 15.5H4.5V12L13 3.5Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                        </x-aksi>

                        {{-- Konfirmasinya lewat pembungkus SweetAlert bersama
                             (window.konfirmasiNampan, resources/js/toast.js).

                             Dialog ini BUKAN pengamannya. Gerbang sungguhannya di
                             Pelanggan::hapus(): yang masih berutang DITOLAK di server, karena
                             soft delete menyembunyikan orangnya tanpa melunasi utangnya. --}}
                        <x-aksi warna="bahaya" label="Hapus {{ $pelanggan->nama }}" class="size-10"
                                x-on:click="window.konfirmasiNampan({ judul: {{ \Illuminate\Support\Js::from('Hapus '.$pelanggan->nama.'?') }}, pesan: {{ \Illuminate\Support\Js::from($pesanHapusPelanggan) }}, tombolYa: 'Ya, hapus', tombolBatal: 'Tidak jadi' }).then((ya) => ya && $wire.hapus({{ \Illuminate\Support\Js::from($pelanggan->id) }}))">
                            <svg viewBox="0 0 20 20" class="size-4" fill="none" aria-hidden="true">
                                <path d="M4 6h12M8 6V4.5h4V6m-6 0v9.5A1.5 1.5 0 0 0 7.5 17h5a1.5 1.5 0 0 0 1.5-1.5V6M8.5 9v5m3-5v5"
                                      stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                        </x-aksi>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="kartu hidden overflow-hidden lg:block">
            <table class="w-full table-fixed text-center">
                <thead>
                    {{-- Lebar kolom PERSEN + table-fixed, jumlahnya 100%. Kolom tanpa lebar
                         menyerap SELURUH sisa lebar kartu, dan lubangnya cuma berpindah
                         tempat — sudah terjadi dua kali di layar lain.

                         Judul rata TENGAH dan isinya juga (keputusan pemilik proyek). --}}
                    <tr class="border-b border-line">
                        <th class="w-[28%] px-5 py-3.5 text-center text-[0.75rem] font-semibold tracking-wide text-umber uppercase">Nama</th>
                        <th class="w-[20%] px-5 py-3.5 text-center text-[0.75rem] font-semibold tracking-wide text-umber uppercase">Nomor HP</th>
                        <th class="w-[22%] px-5 py-3.5 text-center text-[0.75rem] font-semibold tracking-wide text-umber uppercase">Kasbon belum lunas</th>
                        <th class="w-[18%] px-5 py-3.5 text-center text-[0.75rem] font-semibold tracking-wide text-umber uppercase">Ulang tahun</th>
                        {{-- Kolom aksi DIBERI NAMA: dua ikon tanpa keterangan di ujung baris
                             membuat orang harus menekannya untuk tahu apa yang akan terjadi. --}}
                        <th class="w-[12%] px-5 py-3.5 text-center text-[0.75rem] font-semibold tracking-wide text-umber uppercase">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line-soft">
                    @foreach ($daftar as $pelanggan)
                        @php $utang = (float) ($pelanggan->sisa_utang ?? 0); @endphp
                        <tr class="transition-colors hover:bg-cream/60" wire:key="baris-{{ $pelanggan->id }}">
                            <td class="px-5 py-3.5">
                                <p class="truncate text-[0.9375rem] font-semibold text-ink">{{ $pelanggan->nama }}</p>
                                @if ($pelanggan->email)
                                    <span class="block truncate text-[0.6875rem] text-umber-soft">{{ $pelanggan->email }}</span>
                                @endif
                            </td>

                            <td class="tabular px-5 py-3.5 text-[0.875rem]">
                                @if ($pelanggan->no_hp)
                                    <a href="https://wa.me/{{ \App\Support\NomorHp::untukWhatsapp($pelanggan->no_hp) }}"
                                       target="_blank" rel="noopener"
                                       class="font-semibold text-terracotta-deep underline decoration-terracotta/40 underline-offset-2">
                                        {{ $pelanggan->no_hp }}
                                    </a>
                                @else
                                    <span class="text-umber-soft">—</span>
                                @endif
                            </td>

                            <td class="tabular px-5 py-3.5 text-[0.9375rem]">
                                @if ($utang > 0)
                                    <span class="font-bold text-merah-deep">{{ $rupiah($utang) }}</span>
                                @else
                                    <span class="text-umber-soft">—</span>
                                    <span class="block text-[0.6875rem] text-umber-soft">tidak ada utang</span>
                                @endif
                            </td>

                            <td class="px-5 py-3.5 text-[0.875rem] text-umber">
                                {{ $pelanggan->tanggal_lahir?->translatedFormat('j M') ?? '—' }}
                            </td>

                            <td class="px-5 py-3.5">
                                {{-- Dua tombol seukuran (36×36) dan sejajar. Bobotnya dibedakan
                                     lewat garis dan warna, bukan ukuran — ukuran yang
                                     berbeda-beda membuat kolomnya bergerigi. --}}
                                <div class="flex items-center justify-center gap-1.5">
                                    <x-aksi warna="utama" label="Ubah {{ $pelanggan->nama }}"
                                            wire:click="ubah('{{ $pelanggan->id }}')">
                                        <svg viewBox="0 0 20 20" class="size-4" fill="none" aria-hidden="true">
                                            <path d="M13 3.5 16.5 7 8 15.5H4.5V12L13 3.5Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                                        </svg>
                                    </x-aksi>

                                    <x-aksi warna="bahaya" label="Hapus {{ $pelanggan->nama }}"
                                            x-on:click="window.konfirmasiNampan({ judul: {{ \Illuminate\Support\Js::from('Hapus '.$pelanggan->nama.'?') }}, pesan: {{ \Illuminate\Support\Js::from($pesanHapusPelanggan) }}, tombolYa: 'Ya, hapus', tombolBatal: 'Tidak jadi' }).then((ya) => ya && $wire.hapus({{ \Illuminate\Support\Js::from($pelanggan->id) }}))">
                                        <svg viewBox="0 0 20 20" class="size-4" fill="none" aria-hidden="true">
                                            <path d="M4 6h12M8 6V4.5h4V6m-6 0v9.5A1.5 1.5 0 0 0 7.5 17h5a1.5 1.5 0 0 0 1.5-1.5V6M8.5 9v5m3-5v5"
                                                  stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                                        </svg>
                                    </x-aksi>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- ->links() SELALU dirender: daftar tanpa navigasi halaman menyembunyikan barisnya
             tanpa memberi tahu siapa pun. --}}
        <div class="mt-4">{{ $daftar->links() }}</div>
    @endif

    {{-- ── Panel formulir ────────────────────────────────────────────────── --}}
    @if ($panel)
        <div class="fixed inset-0 z-40 flex items-end justify-center bg-navy-900/45 p-0 sm:items-center sm:p-4"
             wire:key="panel-pelanggan">
            <div class="kartu max-h-[92vh] w-full max-w-lg overflow-y-auto rounded-b-none sm:rounded-b-[20px] md:max-w-2xl">
                <div class="flex items-start justify-between gap-3 border-b border-line px-5 py-3.5">
                    <div class="min-w-0">
                        <h2 class="text-[1.0625rem] font-bold text-ink">
                            {{ $pelangganId ? 'Ubah pelanggan' : 'Pelanggan baru' }}
                        </h2>
                        <p class="mt-0.5 text-[0.8125rem] text-umber">
                            Nomor HP-nya yang membedakan orang. Nama boleh kembar; nomor tidak boleh.
                        </p>
                    </div>
                    <button type="button" wire:click="tutupPanel" aria-label="Tutup"
                            class="grid size-10 shrink-0 cursor-pointer place-items-center rounded-lg text-umber transition hover:bg-cream">
                        <svg viewBox="0 0 20 20" class="size-4" fill="none" aria-hidden="true">
                            <path d="M5 5l10 10M15 5L5 15" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
                        </svg>
                    </button>
                </div>

                <form wire:submit="simpan" class="px-5 py-4">
                    <div class="space-y-4">
                        <div>
                            <label for="nama-pelanggan" class="block text-[0.8125rem] font-semibold text-ink">
                                Nama pelanggan <x-wajib />
                            </label>
                            {{-- value= DITULIS SENDIRI, dan itu bukan berlebihan: Livewire
                                 tidak mencetak nilai awal untuk kolom ber-wire:model, jadi
                                 menekan "Ubah" membuka formulir dengan kolom KOSONG walau
                                 pelanggannya bernama Budi — lalu menyimpannya ditolak "nama
                                 wajib" untuk orang yang jelas punya nama. Cacat yang sama
                                 sudah pernah terjadi di kolom batas minimal layar Stok. --}}
                            <input id="nama-pelanggan" type="text" wire:model="nama" value="{{ $nama }}"
                                   autofocus autocomplete="off" placeholder="mis. Budi Santoso"
                                   class="mt-1.5 h-12 w-full rounded-xl border border-line bg-white px-4 text-[0.9375rem] text-ink placeholder:text-umber-soft/70 focus:border-terracotta focus:outline-none">
                            @error('nama')
                                <p class="mt-1.5 text-[0.8125rem] text-merah-deep">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                {{-- TIDAK berbintang: validatornya `nullable`. Bintang pada medan
                                     yang sebenarnya opsional membuat orang mengisi hal yang
                                     tidak perlu, lalu berhenti memercayai bintangnya — dan
                                     melewatkan yang sungguh wajib. --}}
                                <label for="hp-pelanggan" class="block text-[0.8125rem] font-semibold text-ink">
                                    Nomor HP
                                </label>
                                <input id="hp-pelanggan" type="text" inputmode="tel" wire:model="noHp"
                                       value="{{ $noHp }}" autocomplete="off" placeholder="0812-3456-7890"
                                       class="tabular mt-1.5 h-12 w-full rounded-xl border border-line bg-white px-4 text-[0.9375rem] text-ink placeholder:text-umber-soft/70 focus:border-terracotta focus:outline-none">
                                {{-- Kalimat ini WAJIB ada: nomornya memang berubah bentuk
                                     sendiri saat disimpan (+62 jadi 0, tanda hubung hilang).
                                     Perubahan yang tidak diberitahukan terbaca sebagai
                                     aplikasi yang mengubah data orang tanpa izin. --}}
                                <p class="mt-1 text-[0.75rem] text-umber">
                                    Boleh ditulis 0812…, +62812…, atau pakai tanda hubung — disimpan seragam jadi 0812…
                                </p>
                                @error('noHp')
                                    <p class="mt-1.5 text-[0.8125rem] text-merah-deep">{{ $message }}</p>
                                @enderror
                            </div>

                            <div>
                                <label for="lahir-pelanggan" class="block text-[0.8125rem] font-semibold text-ink">
                                    Tanggal lahir
                                </label>
                                <input id="lahir-pelanggan" type="date" wire:model="tanggalLahir"
                                       value="{{ $tanggalLahir }}" max="{{ now()->subDay()->toDateString() }}"
                                       class="mt-1.5 h-12 w-full rounded-xl border border-line bg-white px-4 text-[0.9375rem] text-ink focus:border-terracotta focus:outline-none">
                                <p class="mt-1 text-[0.75rem] text-umber-soft">
                                    Boleh dikosongkan. Dipakai nanti untuk ucapan ulang tahun.
                                </p>
                                @error('tanggalLahir')
                                    <p class="mt-1.5 text-[0.8125rem] text-merah-deep">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>

                        <div>
                            <label for="email-pelanggan" class="block text-[0.8125rem] font-semibold text-ink">
                                Email
                            </label>
                            <input id="email-pelanggan" type="email" wire:model="email" value="{{ $email }}"
                                   autocomplete="off" placeholder="boleh dikosongkan"
                                   class="mt-1.5 h-12 w-full rounded-xl border border-line bg-white px-4 text-[0.9375rem] text-ink placeholder:text-umber-soft/70 focus:border-terracotta focus:outline-none">
                            @error('email')
                                <p class="mt-1.5 text-[0.8125rem] text-merah-deep">{{ $message }}</p>
                            @enderror
                        </div>

                        {{--
                            Dua hal yang SENGAJA tidak ada di formulir ini, dan alasannya ditulis
                            di LAYAR — bukan cuma di komentar kode.

                            Medan yang hilang tanpa penjelasan terbaca sebagai fitur yang belum
                            jadi, dan orang lalu mencarinya di tempat yang salah. Yang
                            menghilangkan kesimpulan itu bukan komentar di berkas PHP; kalimat
                            inilah yang dibaca pemiliknya.
                        --}}
                        <div class="rounded-xl border border-line p-3.5">
                            <p class="eyebrow text-umber-soft">Diatur di tempat lain</p>
                            <ul class="mt-2 space-y-1.5 text-[0.8125rem] text-umber">
                                <li class="flex gap-2">
                                    <span aria-hidden="true" class="mt-2 size-1.5 shrink-0 rounded-full bg-terracotta"></span>
                                    <span><span class="font-semibold text-ink">Kasbon</span> dicatat di layar Kasbon, per utang — bukan sebagai satu angka saldo di sini, supaya tiap utang punya tanggal dan catatannya sendiri.</span>
                                </li>
                                <li class="flex gap-2">
                                    <span aria-hidden="true" class="mt-2 size-1.5 shrink-0 rounded-full bg-terracotta"></span>
                                    <span><span class="font-semibold text-ink">Riwayat belanja</span> terbentuk sendiri dari struk kasir yang memilih pelanggan ini.</span>
                                </li>
                            </ul>
                        </div>
                    </div>

                    <div class="mt-5 flex flex-col gap-2 border-t border-line pt-4 sm:flex-row sm:justify-end">
                        <button type="button" wire:click="tutupPanel"
                                class="h-12 cursor-pointer rounded-xl border border-line px-5 text-[0.9375rem] font-semibold text-ink transition-colors hover:bg-cream sm:order-1">
                            Batal
                        </button>
                        <button type="submit" wire:loading.attr="disabled"
                                class="h-12 cursor-pointer rounded-xl bg-terracotta px-6 text-[0.9375rem] font-bold text-white transition-colors hover:bg-terracotta-deep disabled:opacity-60 sm:order-2">
                            <span wire:loading.remove wire:target="simpan">Simpan</span>
                            <span wire:loading wire:target="simpan">Menyimpan…</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
