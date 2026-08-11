{{--
    Kelola karyawan: daftar, tambah, ubah, nonaktifkan, hapus.

    Data dari komponen: $daftar (paginator User ber-eager-load outlet), $jumlahKaryawan,
    $jumlahAktif, $peranTersedia, $outletTersedia, $panjangPin, plus properti formulir.

    Bentuknya menyalin layar Stok & hitung stok — patokan responsif di CLAUDE.md: kartu di
    bawah lg, tabel `table-fixed` berlebar PERSEN di atasnya, sel kosong selalu bertanda "—",
    dan 10 baris per halaman dengan ->links() yang selalu dirender.
--}}
@php
    $pesanHapusKaryawan = 'Namanya hilang dari daftar ini dan akunnya tidak bisa dipakai masuk lagi. '
        .'Riwayat transaksi dan catatan kas yang pernah ia buat TETAP tersimpan.';

    // Peran yang sedang dipilih di formulir, untuk memutuskan medan mana yang muncul.
    // tryFrom, bukan from: nilai dari klien tidak dipercaya, dan `from` melempar sebelum
    // validasi sempat berjalan.
    $peranForm = \App\Enums\UserRole::tryFrom($peran);
    $pakaiPin = $peranForm?->requiresOutlet() ?? false;
@endphp

<div x-data>
    <x-kartu-alat
        judul="Karyawan"
        jumlah="{{ $jumlahKaryawan }}"
        keterangan="Siapa saja yang bisa masuk ke aplikasi ini, dan sampai mana. Kasir dan dapur masuk pakai username + PIN; pemilik dan manajer pakai email."
    >
        <x-slot:aksi>
            <button type="button" wire:click="tambah"
                    class="flex h-11 cursor-pointer items-center gap-2 rounded-xl bg-terracotta px-5 text-[0.875rem] font-bold text-white transition-colors hover:bg-terracotta-deep">
                <svg viewBox="0 0 20 20" class="size-4" fill="none" aria-hidden="true">
                    <path d="M10 4v12M4 10h12" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
                </svg>
                <span class="sm:hidden">Baru</span>
                <span class="hidden sm:inline">Karyawan baru</span>
            </button>
        </x-slot:aksi>

        <x-slot:saringan>
            <div class="grid gap-3 sm:grid-cols-[1fr_auto]">
                <div class="relative min-w-0">
                    <label for="cari-karyawan" class="sr-only">Cari karyawan</label>
                    <svg viewBox="0 0 20 20" class="pointer-events-none absolute top-1/2 left-4 size-4 -translate-y-1/2 text-umber-soft"
                         fill="none" aria-hidden="true">
                        <circle cx="9" cy="9" r="5.5" stroke="currentColor" stroke-width="1.6" />
                        <path d="m13.5 13.5 3 3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
                    </svg>
                    <input id="cari-karyawan" type="search" wire:model.live.debounce.300ms="cari"
                           placeholder="Cari nama, username, atau email…"
                           class="h-11 w-full rounded-xl border border-line bg-white pr-4 pl-11 text-[0.875rem] text-ink focus:border-terracotta focus:outline-none">
                </div>

                <div>
                    <label for="peran-saring" class="sr-only">Saring peran</label>
                    <select id="peran-saring" wire:model.live="saringPeran"
                            class="h-11 w-full rounded-xl border border-line bg-white px-3 text-[0.875rem] font-semibold text-ink focus:border-terracotta focus:outline-none sm:w-auto">
                        <option value="">Semua peran</option>
                        @foreach ($peranTersedia as $p)
                            <option value="{{ $p->value }}">{{ $p->label() }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </x-slot:saringan>
    </x-kartu-alat>

    <x-istilah-layar :kunci="['peran', 'pin', 'cabang', 'shift']" />

    {{-- Jumlah yang AKTIF disebut terpisah dari jumlah total, dan hanya kalau memang berbeda.
         Angka gabungan menyembunyikan hal yang menentukan: berapa orang yang benar-benar
         bisa masuk hari ini. --}}
    @if ($jumlahKaryawan > $jumlahAktif)
        <div class="kartu mb-4 flex flex-wrap items-center justify-between gap-3 px-5 py-4">
            <div class="min-w-0">
                <p class="eyebrow text-umber-soft">Yang bisa masuk sekarang</p>
                <p class="tabular mt-0.5 text-[1.375rem] font-bold text-ink">{{ $jumlahAktif }} dari {{ $jumlahKaryawan }}</p>
            </div>
            <p class="text-[0.8125rem] text-umber">
                Yang nonaktif tetap ada di daftar supaya riwayat transaksinya masih menunjuk nama yang jelas.
            </p>
        </div>
    @endif

    {{-- ── Daftar ────────────────────────────────────────────────────────── --}}
    @if ($daftar->isEmpty())
        <x-kosong
            :ikon="$cari !== '' || $saringPeran !== '' ? 'cari' : 'orang'"
            judul="{{ $cari !== '' || $saringPeran !== '' ? 'Tidak ada karyawan yang cocok' : 'Belum ada karyawan lain' }}"
            keterangan="{{ $cari !== '' || $saringPeran !== ''
                ? 'Coba ubah kata pencariannya, atau pilih semua peran.'
                : 'Tambahkan kasir supaya ia bisa membuka layar jualan dengan akunnya sendiri — jadi tiap transaksi dan tiap sesi kas jelas siapa yang mengerjakannya.' }}"
        >
            <x-slot:aksi>
                <button type="button" wire:click="tambah"
                        class="h-11 cursor-pointer rounded-xl bg-terracotta px-5 text-[0.875rem] font-bold text-white transition-colors hover:bg-terracotta-deep">
                    Karyawan baru
                </button>
            </x-slot:aksi>
        </x-kosong>
    @else
        <div class="space-y-3 lg:hidden">
            @foreach ($daftar as $orang)
                @php $sendiri = $orang->id === auth()->id(); @endphp
                <div class="kartu p-4" wire:key="kartu-{{ $orang->id }}">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="truncate text-[0.9375rem] font-bold text-ink">
                                {{ $orang->name }}
                                @if ($sendiri)
                                    <span class="text-[0.75rem] font-medium text-umber">(Anda)</span>
                                @endif
                            </p>
                            <p class="mt-0.5 text-[0.75rem] text-umber">{{ $orang->role->label() }}</p>
                        </div>

                        @if ($orang->is_active)
                            <x-lencana warna="hijau" :denyut="false" class="shrink-0">Aktif</x-lencana>
                        @else
                            <x-lencana :denyut="false" class="shrink-0">Nonaktif</x-lencana>
                        @endif
                    </div>

                    <div class="mt-3 grid grid-cols-2 gap-3">
                        <div class="min-w-0 rounded-xl border border-line px-3 py-2">
                            <p class="text-[0.6875rem] font-semibold tracking-wide text-umber-soft uppercase">Masuk pakai</p>
                            {{-- Yang ditampilkan JALAN MASUKNYA, bukan cuma nilainya: pemilik
                                 yang mengira kasir bisa login pakai email akan memberikan
                                 alamat halaman yang salah, dan staffnya tertahan di gerbang
                                 yang tidak menyebutkan apa-apa. --}}
                            <p class="truncate text-[0.9375rem] font-bold text-ink">
                                {{ $orang->username ?: ($orang->email ?: '—') }}
                            </p>
                            <p class="text-[0.75rem] font-medium text-umber-soft">
                                {{ $orang->username ? 'username + PIN' : ($orang->email ? 'email + kata sandi' : 'belum bisa masuk') }}
                            </p>
                        </div>

                        <div class="min-w-0 rounded-xl border border-line px-3 py-2">
                            <p class="text-[0.6875rem] font-semibold tracking-wide text-umber-soft uppercase">Cabang</p>
                            <p class="truncate text-[0.9375rem] font-bold {{ $orang->outlet ? 'text-ink' : 'text-umber-soft' }}">
                                {{ $orang->outlet?->outlet_name ?? '—' }}
                            </p>
                            @unless ($orang->outlet)
                                <p class="text-[0.75rem] font-medium text-umber-soft">
                                    {{ $orang->role->requiresOutlet() ? 'belum dipilih' : 'semua cabang' }}
                                </p>
                            @endunless
                        </div>
                    </div>

                    <div class="mt-3 flex items-center justify-end gap-1.5">
                        <button type="button" wire:click="saklarAktif('{{ $orang->id }}')"
                                @disabled($sendiri)
                                class="h-10 cursor-pointer rounded-lg border border-line px-3 text-[0.8125rem] font-semibold text-ink transition-colors hover:bg-cream disabled:cursor-not-allowed disabled:opacity-40">
                            {{ $orang->is_active ? 'Nonaktifkan' : 'Aktifkan' }}
                        </button>

                        <x-aksi warna="utama" label="Ubah {{ $orang->name }}"
                                class="size-10" wire:click="ubah('{{ $orang->id }}')">
                            <svg viewBox="0 0 20 20" class="size-4" fill="none" aria-hidden="true">
                                <path d="M13 3.5 16.5 7 8 15.5H4.5V12L13 3.5Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                        </x-aksi>

                        {{-- Tombol hapus TIDAK dimatikan untuk akun sendiri, dan itu disengaja:
                             tombol mati tanpa penjelasan terbaca sebagai aplikasi yang rusak.
                             Gerbangnya di server (Karyawan::hapus), dan pesannya menjelaskan
                             kenapa — sama seperti gerbang owner terakhir dan sesi kas terbuka
                             yang tidak mungkin diketahui layar sebelum tombolnya ditekan. --}}
                        <x-aksi warna="bahaya" label="Hapus {{ $orang->name }}" class="size-10"
                                x-on:click="window.konfirmasiNampan({ judul: {{ \Illuminate\Support\Js::from('Hapus '.$orang->name.'?') }}, pesan: {{ \Illuminate\Support\Js::from($pesanHapusKaryawan) }}, tombolYa: 'Ya, hapus', tombolBatal: 'Tidak jadi' }).then((ya) => ya && $wire.hapus({{ \Illuminate\Support\Js::from($orang->id) }}))">
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
                         menyerap SELURUH sisa lebar kartu — sudah terjadi dua kali di layar
                         lain. Judul rata tengah dan isinya juga (keputusan pemilik proyek). --}}
                    <tr class="border-b border-line">
                        <th class="w-[24%] px-4 py-3.5 text-center text-[0.75rem] font-semibold tracking-wide text-umber uppercase">Nama</th>
                        <th class="w-[16%] px-4 py-3.5 text-center text-[0.75rem] font-semibold tracking-wide text-umber uppercase">Peran</th>
                        <th class="w-[22%] px-4 py-3.5 text-center text-[0.75rem] font-semibold tracking-wide text-umber uppercase">Masuk pakai</th>
                        <th class="w-[16%] px-4 py-3.5 text-center text-[0.75rem] font-semibold tracking-wide text-umber uppercase">Cabang</th>
                        <th class="w-[10%] px-4 py-3.5 text-center text-[0.75rem] font-semibold tracking-wide text-umber uppercase">Status</th>
                        <th class="w-[12%] px-4 py-3.5 text-center text-[0.75rem] font-semibold tracking-wide text-umber uppercase">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line-soft">
                    @foreach ($daftar as $orang)
                        @php $sendiri = $orang->id === auth()->id(); @endphp
                        <tr class="transition-colors hover:bg-cream/60" wire:key="baris-{{ $orang->id }}">
                            <td class="px-4 py-3.5">
                                <p class="truncate text-[0.9375rem] font-semibold text-ink">{{ $orang->name }}</p>
                                @if ($sendiri)
                                    <span class="block text-[0.6875rem] text-umber-soft">Anda</span>
                                @endif
                            </td>

                            <td class="px-4 py-3.5 text-[0.875rem] text-umber">{{ $orang->role->label() }}</td>

                            <td class="px-4 py-3.5 text-[0.875rem]">
                                <span class="block truncate font-semibold text-ink">{{ $orang->username ?: ($orang->email ?: '—') }}</span>
                                <span class="block text-[0.6875rem] text-umber-soft">
                                    {{ $orang->username ? 'username + PIN' : ($orang->email ? 'email + kata sandi' : 'belum bisa masuk') }}
                                </span>
                            </td>

                            <td class="px-4 py-3.5 text-[0.875rem]">
                                @if ($orang->outlet)
                                    <span class="text-umber">{{ $orang->outlet->outlet_name }}</span>
                                @else
                                    <span class="text-umber-soft">—</span>
                                    <span class="block text-[0.6875rem] text-umber-soft">
                                        {{ $orang->role->requiresOutlet() ? 'belum dipilih' : 'semua cabang' }}
                                    </span>
                                @endif
                            </td>

                            <td class="px-4 py-3.5">
                                @if ($orang->is_active)
                                    <x-lencana warna="hijau" :denyut="false">Aktif</x-lencana>
                                @else
                                    <x-lencana :denyut="false">Nonaktif</x-lencana>
                                @endif
                            </td>

                            <td class="px-4 py-3.5">
                                {{-- Tiga tombol seukuran (36×36) dan sejajar. Ukuran yang
                                     berbeda-beda membuat kolomnya bergerigi. --}}
                                <div class="flex items-center justify-center gap-1.5">
                                    {{-- :disabled, BUKAN @disabled(), dan ini terbukti lewat
                                         layar yang gagal dirender sama sekali.

                                         @disabled() DI DALAM TAG KOMPONEN dikompilasi jadi
                                         `<?php if(...): echo 'disabled'; endif; ?>` yang
                                         disisipkan ke tengah pengurai atribut komponen —
                                         hasilnya satu `endif` tanpa pasangan, dan seluruh
                                         layar mati dengan pesan "unexpected endif" yang tidak
                                         menunjuk barisnya sama sekali.

                                         :disabled aman: ComponentAttributeBag membuang
                                         atribut bernilai false (baris 494 berkas itu), jadi
                                         `disabled` hanya tercetak saat nilainya benar. --}}
                                    <x-aksi warna="netral" label="{{ $orang->is_active ? 'Nonaktifkan' : 'Aktifkan' }} {{ $orang->name }}"
                                            :disabled="$sendiri" wire:click="saklarAktif('{{ $orang->id }}')">
                                        @if ($orang->is_active)
                                            <svg viewBox="0 0 20 20" class="size-4" fill="none" aria-hidden="true">
                                                <path d="M10 2.5v7M6 5a6 6 0 1 0 8 0" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
                                            </svg>
                                        @else
                                            <svg viewBox="0 0 20 20" class="size-4" fill="none" aria-hidden="true">
                                                <path d="m4 10.5 4 4 8-9" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
                                            </svg>
                                        @endif
                                    </x-aksi>

                                    <x-aksi warna="utama" label="Ubah {{ $orang->name }}"
                                            wire:click="ubah('{{ $orang->id }}')">
                                        <svg viewBox="0 0 20 20" class="size-4" fill="none" aria-hidden="true">
                                            <path d="M13 3.5 16.5 7 8 15.5H4.5V12L13 3.5Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                                        </svg>
                                    </x-aksi>

                                    <x-aksi warna="bahaya" label="Hapus {{ $orang->name }}"
                                            x-on:click="window.konfirmasiNampan({ judul: {{ \Illuminate\Support\Js::from('Hapus '.$orang->name.'?') }}, pesan: {{ \Illuminate\Support\Js::from($pesanHapusKaryawan) }}, tombolYa: 'Ya, hapus', tombolBatal: 'Tidak jadi' }).then((ya) => ya && $wire.hapus({{ \Illuminate\Support\Js::from($orang->id) }}))">
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

        <div class="mt-4">{{ $daftar->links() }}</div>
    @endif

    {{-- ── Panel formulir ────────────────────────────────────────────────── --}}
    @if ($panel)
        <div class="fixed inset-0 z-40 flex items-end justify-center bg-navy-900/45 p-0 sm:items-center sm:p-4"
             wire:key="panel-karyawan">
            <div class="kartu max-h-[92vh] w-full max-w-lg overflow-y-auto rounded-b-none sm:rounded-b-[20px] md:max-w-2xl">
                <div class="flex items-start justify-between gap-3 border-b border-line px-5 py-3.5">
                    <div class="min-w-0">
                        <h2 class="text-[1.0625rem] font-bold text-ink">
                            {{ $karyawanId ? 'Ubah karyawan' : 'Karyawan baru' }}
                        </h2>
                        <p class="mt-0.5 text-[0.8125rem] text-umber">
                            Perannya menentukan sampai mana ia bisa melihat — termasuk apakah ia bisa melihat untung rugi.
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
                            <label for="nama-karyawan" class="block text-[0.8125rem] font-semibold text-ink">
                                Nama karyawan <x-wajib />
                            </label>
                            {{-- value= DITULIS SENDIRI: Livewire tidak mencetak nilai awal untuk
                                 kolom ber-wire:model, jadi "Ubah" membuka formulir dengan kolom
                                 KOSONG walau orangnya jelas punya nama — lalu menyimpannya
                                 ditolak "nama wajib". Cacat yang sama sudah pernah terjadi di
                                 kolom batas minimal layar Stok. --}}
                            <input id="nama-karyawan" type="text" wire:model="nama" value="{{ $nama }}"
                                   autofocus autocomplete="off" placeholder="mis. Andi Saputra"
                                   class="mt-1.5 h-12 w-full rounded-xl border border-line bg-white px-4 text-[0.9375rem] text-ink placeholder:text-umber-soft/70 focus:border-terracotta focus:outline-none">
                            @error('nama')
                                <p class="mt-1.5 text-[0.8125rem] text-merah-tua">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label for="peran-karyawan" class="block text-[0.8125rem] font-semibold text-ink">
                                    Peran <x-wajib />
                                </label>
                                {{-- .live supaya medan di bawahnya ikut berganti: peran kasir
                                     meminta username + PIN dan WAJIB satu cabang, peran pemilik
                                     meminta email. Tanpa .live, orang mengisi medan yang salah
                                     lalu ditolak dengan alasan yang tidak ia lihat sebabnya. --}}
                                <select id="peran-karyawan" wire:model.live="peran"
                                        class="mt-1.5 h-12 w-full rounded-xl border border-line bg-white px-3 text-[0.9375rem] text-ink focus:border-terracotta focus:outline-none">
                                    {{-- @selected WAJIB: tanpa itu <select> menampilkan pilihan
                                         PERTAMA sementara server memegang nilai lain — dan di
                                         kolom PERAN, salah baca berarti memberi orang akses
                                         yang tidak dimaksudkan. --}}
                                    @foreach ($peranTersedia as $p)
                                        <option value="{{ $p->value }}" @selected($peran === $p->value)>{{ $p->label() }}</option>
                                    @endforeach
                                </select>
                                @error('peran')
                                    <p class="mt-1.5 text-[0.8125rem] text-merah-tua">{{ $message }}</p>
                                @enderror
                            </div>

                            <div>
                                <label for="outlet-karyawan" class="block text-[0.8125rem] font-semibold text-ink">
                                    Cabang @if ($pakaiPin) <x-wajib /> @endif
                                </label>
                                <select id="outlet-karyawan" wire:model="outletId" @disabled(! $pakaiPin)
                                        class="mt-1.5 h-12 w-full rounded-xl border border-line bg-white px-3 text-[0.9375rem] text-ink focus:border-terracotta focus:outline-none disabled:cursor-not-allowed disabled:bg-cream disabled:text-umber-soft">
                                    <option value="">{{ $pakaiPin ? '— pilih cabang —' : 'Semua cabang' }}</option>
                                    @foreach ($outletTersedia as $o)
                                        <option value="{{ $o->id }}" @selected($outletId === $o->id)>{{ $o->outlet_name }}</option>
                                    @endforeach
                                </select>
                                <p class="mt-1 text-[0.75rem] text-umber-soft">
                                    {{ $pakaiPin
                                        ? 'Dikunci ke satu cabang. Tanpa ini akunnya tidak bisa dipakai masuk.'
                                        : 'Peran ini melihat semua cabang, jadi tidak dikunci.' }}
                                </p>
                                @error('outletId')
                                    <p class="mt-1.5 text-[0.8125rem] text-merah-tua">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>

                        {{-- Medan kredensial DIGANTI, bukan ditumpuk: menampilkan username DAN
                             email sekaligus membuat pemilik mengisi keduanya, lalu bingung
                             kenapa kasirnya diminta kata sandi di layar masuk kasir. --}}
                        @if ($pakaiPin)
                            <div class="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <label for="username-karyawan" class="block text-[0.8125rem] font-semibold text-ink">
                                        Username @unless ($karyawanId) <x-wajib /> @endunless
                                    </label>
                                    <input id="username-karyawan" type="text" wire:model="username" value="{{ $username }}"
                                           autocomplete="off" placeholder="mis. andi-cabang1"
                                           class="mt-1.5 h-12 w-full rounded-xl border border-line bg-white px-4 text-[0.9375rem] text-ink placeholder:text-umber-soft/70 focus:border-terracotta focus:outline-none">
                                    {{-- Kalimat ini WAJIB ada: usernamenya unik SE-APLIKASI,
                                         bukan se-warung, jadi "kasir1" bisa ditolak karena
                                         dipakai warung lain — dan pemilik akan mencari nama itu
                                         di daftarnya sendiri tanpa pernah menemukannya. --}}
                                    <p class="mt-1 text-[0.75rem] text-umber">
                                        Harus unik di seluruh aplikasi, jadi sebaiknya diberi nama cabang atau nama warung.
                                    </p>
                                    @error('username')
                                        <p class="mt-1.5 text-[0.8125rem] text-merah-tua">{{ $message }}</p>
                                    @enderror
                                </div>

                                <div>
                                    <label for="pin-karyawan" class="block text-[0.8125rem] font-semibold text-ink">
                                        PIN @unless ($karyawanId) <x-wajib /> @endunless
                                    </label>
                                    <input id="pin-karyawan" type="text" inputmode="numeric" maxlength="{{ $panjangPin }}"
                                           wire:model="rahasiaBaru" value="{{ $rahasiaBaru }}"
                                           autocomplete="new-password" placeholder="{{ str_repeat('•', $panjangPin) }}"
                                           class="tabular mt-1.5 h-12 w-full rounded-xl border border-line bg-white px-4 text-[1.125rem] tracking-[0.3em] text-ink placeholder:tracking-[0.3em] placeholder:text-umber-soft/70 focus:border-terracotta focus:outline-none">
                                    <p class="mt-1 text-[0.75rem] text-umber">
                                        {{ $panjangPin }} angka.
                                        {{ $karyawanId
                                            ? 'Kosongkan kalau PIN-nya tidak diubah — PIN lama tidak bisa ditampilkan lagi.'
                                            : 'Beritahukan langsung ke orangnya; sesudah disimpan tidak bisa dilihat lagi.' }}
                                    </p>
                                    @error('rahasiaBaru')
                                        <p class="mt-1.5 text-[0.8125rem] text-merah-tua">{{ $message }}</p>
                                    @enderror
                                </div>
                            </div>
                        @else
                            <div class="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <label for="email-karyawan" class="block text-[0.8125rem] font-semibold text-ink">
                                        Email @unless ($karyawanId) <x-wajib /> @endunless
                                    </label>
                                    <input id="email-karyawan" type="email" wire:model="email" value="{{ $email }}"
                                           autocomplete="off" placeholder="mis. manajer@warung.test"
                                           class="mt-1.5 h-12 w-full rounded-xl border border-line bg-white px-4 text-[0.9375rem] text-ink placeholder:text-umber-soft/70 focus:border-terracotta focus:outline-none">
                                    @error('email')
                                        <p class="mt-1.5 text-[0.8125rem] text-merah-tua">{{ $message }}</p>
                                    @enderror
                                </div>

                                <div>
                                    <label for="sandi-karyawan" class="block text-[0.8125rem] font-semibold text-ink">
                                        Kata sandi @unless ($karyawanId) <x-wajib /> @endunless
                                    </label>
                                    <input id="sandi-karyawan" type="text" wire:model="rahasiaBaru" value="{{ $rahasiaBaru }}"
                                           autocomplete="new-password" placeholder="minimal 8 karakter"
                                           class="mt-1.5 h-12 w-full rounded-xl border border-line bg-white px-4 text-[0.9375rem] text-ink placeholder:text-umber-soft/70 focus:border-terracotta focus:outline-none">
                                    <p class="mt-1 text-[0.75rem] text-umber">
                                        {{ $karyawanId
                                            ? 'Kosongkan kalau kata sandinya tidak diubah.'
                                            : 'Beritahukan langsung ke orangnya; sesudah disimpan tidak bisa dilihat lagi.' }}
                                    </p>
                                    @error('rahasiaBaru')
                                        <p class="mt-1.5 text-[0.8125rem] text-merah-tua">{{ $message }}</p>
                                    @enderror
                                </div>
                            </div>
                        @endif

                        <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-line p-3.5">
                            <input type="checkbox" wire:model="aktif" @checked($aktif)
                                   class="mt-0.5 size-5 shrink-0 cursor-pointer rounded border-line text-terracotta focus:ring-terracotta">
                            <span class="min-w-0">
                                <span class="block text-[0.875rem] font-semibold text-ink">Bisa masuk ke aplikasi</span>
                                <span class="block text-[0.75rem] text-umber">
                                    Matikan kalau orangnya berhenti kerja. Riwayat transaksinya tetap tersimpan — lebih baik
                                    daripada dihapus, karena struk lama tetap menunjuk nama yang jelas.
                                </span>
                            </span>
                        </label>
                        @error('aktif')
                            <p class="text-[0.8125rem] text-merah-tua">{{ $message }}</p>
                        @enderror
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
