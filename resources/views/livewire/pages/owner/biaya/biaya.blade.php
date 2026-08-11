{{--
    Biaya operasional: sewa, listrik, gaji, gas — beban warung yang berulang.

    Data dari komponen: $daftar (paginator BiayaOperasional ber-eager-load outlet), $perHari,
    $perBulan, $jumlahBerjalan, $periodeTersedia, $outletTersedia, plus properti formulir.

    Bentuknya menyalin layar Stok & hitung stok — patokan responsif di CLAUDE.md: kartu di
    bawah lg, tabel `table-fixed` berlebar PERSEN di atasnya, sel kosong selalu bertanda "—",
    dan 10 baris per halaman dengan ->links() yang selalu dirender.
--}}
@php
    $rp = fn ($nilai) => 'Rp '.number_format((float) $nilai, 0, ',', '.');

    $pesanHapusBiaya = 'Hitungan bulan-bulan SEBELUMNYA ikut berubah, seolah biaya ini tidak '
        .'pernah ada. Kalau biayanya memang pernah ada dan sekarang berhenti, pakai "Hentikan" '
        .'— riwayatnya tetap utuh.';
@endphp

<div x-data>
    <x-kartu-alat
        judul="Biaya operasional"
        jumlah="{{ $jumlahBerjalan }}"
        keterangan="Beban warung yang berulang: sewa, listrik, gaji, gas. Angka ini yang membuat margin di layar Produk berhenti jadi margin kotor."
    >
        <x-slot:aksi>
            <button type="button" wire:click="tambah"
                    class="flex h-11 cursor-pointer items-center gap-2 rounded-xl bg-terracotta px-5 text-[0.875rem] font-bold text-white transition-colors hover:bg-terracotta-deep">
                <svg viewBox="0 0 20 20" class="size-4" fill="none" aria-hidden="true">
                    <path d="M10 4v12M4 10h12" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
                </svg>
                <span class="sm:hidden">Baru</span>
                <span class="hidden sm:inline">Biaya baru</span>
            </button>
        </x-slot:aksi>

        <x-slot:saringan>
            <div class="grid gap-3 sm:grid-cols-[1fr_auto]">
                <div class="relative min-w-0">
                    <label for="cari-biaya" class="sr-only">Cari biaya</label>
                    <svg viewBox="0 0 20 20" class="pointer-events-none absolute top-1/2 left-4 size-4 -translate-y-1/2 text-umber-soft"
                         fill="none" aria-hidden="true">
                        <circle cx="9" cy="9" r="5.5" stroke="currentColor" stroke-width="1.6" />
                        <path d="m13.5 13.5 3 3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
                    </svg>
                    <input id="cari-biaya" type="search" wire:model.live.debounce.300ms="cari"
                           placeholder="Cari nama biaya…"
                           class="h-11 w-full rounded-xl border border-line bg-white pr-4 pl-11 text-[0.875rem] text-ink focus:border-terracotta focus:outline-none">
                </div>

                {{-- Bawaannya HANYA yang masih berjalan: yang sudah berhenti bukan beban hari
                     ini, dan mencampurnya membuat daftar penuh baris yang tidak berpengaruh
                     pada satu pun angka di halaman ini. --}}
                <button type="button" wire:click="$toggle('tampilkanBerhenti')"
                        aria-pressed="{{ $tampilkanBerhenti ? 'true' : 'false' }}"
                        @class([
                            'flex h-11 cursor-pointer items-center justify-center gap-2 rounded-xl border px-4 text-[0.875rem] font-semibold transition-colors',
                            'border-terracotta bg-terracotta/10 text-terracotta-deep' => $tampilkanBerhenti,
                            'border-line text-umber hover:bg-cream' => ! $tampilkanBerhenti,
                        ])>
                    Tampilkan yang berhenti
                </button>
            </div>
        </x-slot:saringan>
    </x-kartu-alat>

    <x-istilah-layar :kunci="['biaya-operasional', 'untung-bersih', 'titik-impas']" />

    {{-- Ringkasan beban. SELALU dirender, termasuk saat nol — beda dengan kartu piutang di
         layar Kasbon, dan sengaja: "Rp 0 per hari" DI SINI adalah kabar yang penting, bukan
         kotak kosong. Ia memberi tahu pemilik bahwa margin yang ia lihat di layar Produk
         masih margin kotor karena belum satu pun biaya dicatat. --}}
    <div class="kartu mb-4 p-5">
        <div class="grid gap-4 sm:grid-cols-2">
            <div class="min-w-0">
                <p class="eyebrow text-umber-soft"><x-jelaskan kunci="biaya-operasional" sebagai="Beban per hari" /></p>
                <p class="tabular mt-0.5 text-[1.75rem] font-bold text-ink">{{ $rp($perHari) }}</p>
                <p class="mt-1 text-[0.8125rem] text-umber">
                    Segini yang harus tertutup omzet sebelum warung mulai untung.
                </p>
            </div>
            <div class="min-w-0 rounded-xl border border-line px-4 py-3">
                <p class="eyebrow text-umber-soft">Setara per bulan</p>
                <p class="tabular mt-0.5 text-[1.125rem] font-bold text-ink">{{ $rp($perBulan) }}</p>
                {{-- Pembaginya disebutkan. Pemilik yang menghitung sendiri 1.500.000 ÷ 30
                     harus mendapat angka yang sama dengan aplikasinya; kalau tidak, ia
                     menganggap aplikasinya salah hitung dan berhenti memakai angkanya. --}}
                <p class="mt-1 text-[0.75rem] text-umber-soft">
                    Per bulan dihitung 30 hari, per minggu 7 hari — sama seperti kalau dihitung di kertas.
                </p>
            </div>
        </div>

        @if ($perHari <= 0)
            <p class="mt-4 rounded-xl border border-jingga/30 bg-jingga/10 px-4 py-3 text-[0.8125rem] text-ink">
                Belum ada biaya yang dicatat. Selama kosong, margin di layar Produk adalah
                <span class="font-semibold">margin kotor</span> — belum dipotong sewa, listrik, dan gas.
            </p>
        @endif
    </div>

    {{-- ── Daftar ────────────────────────────────────────────────────────── --}}
    @if ($daftar->isEmpty())
        <x-kosong
            :ikon="$cari !== '' ? 'cari' : 'lembar'"
            judul="{{ $cari !== '' ? 'Tidak ada biaya yang cocok' : 'Belum ada biaya dicatat' }}"
            keterangan="{{ $cari !== ''
                ? 'Coba ubah kata pencariannya, atau tampilkan yang sudah berhenti.'
                : 'Mulai dari yang paling besar: sewa tempat, listrik, dan gaji. Tiga itu saja sudah cukup membuat angka untung rugi jadi jujur.' }}"
        >
            <x-slot:aksi>
                <button type="button" wire:click="tambah"
                        class="h-11 cursor-pointer rounded-xl bg-terracotta px-5 text-[0.875rem] font-bold text-white transition-colors hover:bg-terracotta-deep">
                    Biaya baru
                </button>
            </x-slot:aksi>
        </x-kosong>
    @else
        <div class="space-y-3 lg:hidden">
            @foreach ($daftar as $biaya)
                @php $berhenti = $biaya->selesai !== null && ! $biaya->berlakuPada(); @endphp
                <div class="kartu p-4" wire:key="kartu-{{ $biaya->id }}">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="truncate text-[0.9375rem] font-bold text-ink">{{ $biaya->nama }}</p>
                            <p class="mt-0.5 text-[0.75rem] text-umber">
                                {{ $biaya->outlet?->outlet_name ?? 'Semua cabang' }}
                            </p>
                        </div>
                        @if ($berhenti)
                            <x-lencana :denyut="false" class="shrink-0">Berhenti</x-lencana>
                        @else
                            <x-lencana warna="hijau" :denyut="false" class="shrink-0">Berjalan</x-lencana>
                        @endif
                    </div>

                    <div class="mt-3 grid grid-cols-2 gap-3">
                        <div class="min-w-0 rounded-xl border border-line px-3 py-2">
                            <p class="text-[0.6875rem] font-semibold tracking-wide text-umber-soft uppercase">Nominal</p>
                            <p class="tabular text-[0.9375rem] font-bold text-ink">{{ $rp($biaya->nominal) }}</p>
                            <p class="text-[0.75rem] font-medium text-umber">{{ $biaya->periode->label() }}</p>
                        </div>
                        <div class="min-w-0 rounded-xl border border-line px-3 py-2">
                            <p class="text-[0.6875rem] font-semibold tracking-wide text-umber-soft uppercase">Per hari</p>
                            {{-- Angka inilah yang benar-benar dipakai hitungan margin, jadi ia
                                 ditampilkan di tiap baris — bukan cuma di ringkasan atas.
                                 Pemilik yang mau tahu "biaya mana yang paling menekan" membaca
                                 kolom ini, bukan nominalnya. --}}
                            <p class="tabular text-[0.9375rem] font-bold {{ $berhenti ? 'text-umber-soft' : 'text-ink' }}">
                                {{ $berhenti ? '—' : $rp($biaya->perHari()) }}
                            </p>
                            @if ($berhenti)
                                <p class="text-[0.75rem] font-medium text-umber-soft">tidak membebani lagi</p>
                            @endif
                        </div>
                    </div>

                    <div class="mt-3 flex items-center justify-end gap-1.5">
                        @unless ($berhenti)
                            <button type="button" wire:click="hentikan('{{ $biaya->id }}')"
                                    class="h-10 cursor-pointer rounded-lg border border-line px-3 text-[0.8125rem] font-semibold text-ink transition-colors hover:bg-cream">
                                Hentikan
                            </button>
                        @endunless

                        <x-aksi warna="utama" label="Ubah {{ $biaya->nama }}"
                                class="size-10" wire:click="ubah('{{ $biaya->id }}')">
                            <svg viewBox="0 0 20 20" class="size-4" fill="none" aria-hidden="true">
                                <path d="M13 3.5 16.5 7 8 15.5H4.5V12L13 3.5Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                        </x-aksi>

                        <x-aksi warna="bahaya" label="Hapus {{ $biaya->nama }}" class="size-10"
                                x-on:click="window.konfirmasiNampan({ judul: {{ \Illuminate\Support\Js::from('Hapus '.$biaya->nama.'?') }}, pesan: {{ \Illuminate\Support\Js::from($pesanHapusBiaya) }}, tombolYa: 'Ya, hapus', tombolBatal: 'Tidak jadi' }).then((ya) => ya && $wire.hapus({{ \Illuminate\Support\Js::from($biaya->id) }}))">
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
                        <th class="w-[24%] px-4 py-3.5 text-center text-[0.75rem] font-semibold tracking-wide text-umber uppercase">Nama biaya</th>
                        <th class="w-[16%] px-4 py-3.5 text-center text-[0.75rem] font-semibold tracking-wide text-umber uppercase">Cabang</th>
                        <th class="w-[18%] px-4 py-3.5 text-center text-[0.75rem] font-semibold tracking-wide text-umber uppercase">Nominal</th>
                        <th class="w-[14%] px-4 py-3.5 text-center text-[0.75rem] font-semibold tracking-wide text-umber uppercase">Per hari</th>
                        <th class="w-[16%] px-4 py-3.5 text-center text-[0.75rem] font-semibold tracking-wide text-umber uppercase">Berlaku</th>
                        <th class="w-[12%] px-4 py-3.5 text-center text-[0.75rem] font-semibold tracking-wide text-umber uppercase">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line-soft">
                    @foreach ($daftar as $biaya)
                        @php $berhenti = $biaya->selesai !== null && ! $biaya->berlakuPada(); @endphp
                        <tr class="transition-colors hover:bg-cream/60" wire:key="baris-{{ $biaya->id }}">
                            <td class="px-4 py-3.5">
                                <p class="truncate text-[0.9375rem] font-semibold text-ink">{{ $biaya->nama }}</p>
                                @if ($biaya->catatan)
                                    <span class="block truncate text-[0.6875rem] text-umber-soft">{{ $biaya->catatan }}</span>
                                @endif
                            </td>

                            <td class="px-4 py-3.5 text-[0.875rem] text-umber">
                                {{ $biaya->outlet?->outlet_name ?? 'Semua cabang' }}
                            </td>

                            <td class="tabular px-4 py-3.5 text-[0.9375rem]">
                                <span class="font-bold text-ink">{{ $rp($biaya->nominal) }}</span>
                                <span class="block text-[0.6875rem] text-umber-soft">{{ $biaya->periode->label() }}</span>
                            </td>

                            <td class="tabular px-4 py-3.5 text-[0.9375rem] font-bold {{ $berhenti ? 'text-umber-soft' : 'text-ink' }}">
                                {{ $berhenti ? '—' : $rp($biaya->perHari()) }}
                            </td>

                            <td class="px-4 py-3.5 text-[0.8125rem] text-umber">
                                {{ $biaya->mulai->locale('id')->translatedFormat('j M Y') }}
                                <span class="block text-[0.6875rem] text-umber-soft">
                                    @if ($biaya->selesai)
                                        sampai {{ $biaya->selesai->locale('id')->translatedFormat('j M Y') }}
                                    @else
                                        masih berjalan
                                    @endif
                                </span>
                            </td>

                            <td class="px-4 py-3.5">
                                <div class="flex items-center justify-center gap-1.5">
                                    <x-aksi warna="netral" label="Hentikan {{ $biaya->nama }}"
                                            :disabled="$berhenti" wire:click="hentikan('{{ $biaya->id }}')">
                                        <svg viewBox="0 0 20 20" class="size-4" fill="none" aria-hidden="true">
                                            <rect x="5.5" y="5.5" width="9" height="9" rx="1.5" stroke="currentColor" stroke-width="1.6" />
                                        </svg>
                                    </x-aksi>

                                    <x-aksi warna="utama" label="Ubah {{ $biaya->nama }}"
                                            wire:click="ubah('{{ $biaya->id }}')">
                                        <svg viewBox="0 0 20 20" class="size-4" fill="none" aria-hidden="true">
                                            <path d="M13 3.5 16.5 7 8 15.5H4.5V12L13 3.5Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                                        </svg>
                                    </x-aksi>

                                    <x-aksi warna="bahaya" label="Hapus {{ $biaya->nama }}"
                                            x-on:click="window.konfirmasiNampan({ judul: {{ \Illuminate\Support\Js::from('Hapus '.$biaya->nama.'?') }}, pesan: {{ \Illuminate\Support\Js::from($pesanHapusBiaya) }}, tombolYa: 'Ya, hapus', tombolBatal: 'Tidak jadi' }).then((ya) => ya && $wire.hapus({{ \Illuminate\Support\Js::from($biaya->id) }}))">
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
             wire:key="panel-biaya">
            <div class="kartu max-h-[92vh] w-full max-w-lg overflow-y-auto rounded-b-none sm:rounded-b-[20px] md:max-w-2xl">
                <div class="flex items-start justify-between gap-3 border-b border-line px-5 py-3.5">
                    <div class="min-w-0">
                        <h2 class="text-[1.0625rem] font-bold text-ink">
                            {{ $biayaId ? 'Ubah biaya' : 'Biaya baru' }}
                        </h2>
                        <p class="mt-0.5 text-[0.8125rem] text-umber">
                            Yang dicatat di sini beban yang BERULANG. Belanja sekali jalan — beli kompor, perbaiki kulkas — masuk ke nota belanja, bukan ke sini.
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
                            <label for="nama-biaya" class="block text-[0.8125rem] font-semibold text-ink">
                                Nama biaya <x-wajib />
                            </label>
                            {{-- value= DITULIS SENDIRI: Livewire tidak mencetak nilai awal
                                 untuk kolom ber-wire:model, jadi "Ubah" membuka formulir
                                 dengan kolom KOSONG walau biayanya jelas punya nama — lalu
                                 menyimpannya ditolak "nama wajib". --}}
                            <input id="nama-biaya" type="text" wire:model="nama" value="{{ $nama }}"
                                   autofocus autocomplete="off" placeholder="mis. Sewa tempat"
                                   class="mt-1.5 h-12 w-full rounded-xl border border-line bg-white px-4 text-[0.9375rem] text-ink placeholder:text-umber-soft/70 focus:border-terracotta focus:outline-none">
                            @error('nama')
                                <p class="mt-1.5 text-[0.8125rem] text-merah-tua">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label for="nominal-biaya" class="block text-[0.8125rem] font-semibold text-ink">
                                    Nominal <x-wajib />
                                </label>
                                <div class="relative mt-1.5">
                                    <span class="pointer-events-none absolute top-1/2 left-4 -translate-y-1/2 text-sm font-semibold text-umber-soft">Rp</span>
                                    <input id="nominal-biaya" type="text" inputmode="numeric"
                                           wire:model="nominal" value="{{ $nominal }}"
                                           autocomplete="off" placeholder="0"
                                           class="tabular h-12 w-full rounded-xl border border-line bg-white pr-4 pl-12 text-right text-[1rem] font-bold text-ink placeholder:text-umber-soft/70 focus:border-terracotta focus:outline-none">
                                </div>
                                @error('nominal')
                                    <p class="mt-1.5 text-[0.8125rem] text-merah-tua">{{ $message }}</p>
                                @enderror
                            </div>

                            <div>
                                <label for="periode-biaya" class="block text-[0.8125rem] font-semibold text-ink">
                                    Periode <x-wajib />
                                </label>
                                <select id="periode-biaya" wire:model="periode"
                                        class="mt-1.5 h-12 w-full rounded-xl border border-line bg-white px-3 text-[0.9375rem] text-ink focus:border-terracotta focus:outline-none">
                                    {{-- @selected WAJIB: tanpa itu <select> menampilkan pilihan
                                         PERTAMA sementara server memegang nilai lain — dan di
                                         kolom periode, salah baca berarti sewa setahun dihitung
                                         sebagai sewa sehari. --}}
                                    @foreach ($periodeTersedia as $p)
                                        <option value="{{ $p->value }}" @selected($periode === $p->value)>{{ $p->label() }}</option>
                                    @endforeach
                                </select>
                                @error('periode')
                                    <p class="mt-1.5 text-[0.8125rem] text-merah-tua">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>

                        {{-- Cabang dan catatan disejajarkan supaya panelnya muat tanpa
                             digulir di layar 1280. Terukur: sebagai dua baris terpisah,
                             formulir ini satu-satunya di aplikasi yang menggulir di lebar
                             itu — dan panel yang menggulir menyembunyikan tombol Simpan. --}}
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                {{-- TIDAK berbintang: validatornya `nullable`. Bintang pada medan
                                     opsional membuat orang berhenti memercayai bintangnya. --}}
                                <label for="outlet-biaya" class="block text-[0.8125rem] font-semibold text-ink">Cabang</label>
                                <select id="outlet-biaya" wire:model="outletId"
                                        class="mt-1.5 h-12 w-full rounded-xl border border-line bg-white px-3 text-[0.9375rem] text-ink focus:border-terracotta focus:outline-none">
                                    <option value="">Semua cabang</option>
                                    @foreach ($outletTersedia as $o)
                                        <option value="{{ $o->id }}" @selected($outletId === $o->id)>{{ $o->outlet_name }}</option>
                                    @endforeach
                                </select>
                                @error('outletId')
                                    <p class="mt-1.5 text-[0.8125rem] text-merah-tua">{{ $message }}</p>
                                @enderror
                            </div>
                            <div>
                                <label for="catatan-biaya" class="block text-[0.8125rem] font-semibold text-ink">Catatan</label>
                                <input id="catatan-biaya" type="text" wire:model="catatan" value="{{ $catatan }}"
                                       autocomplete="off" placeholder="boleh dikosongkan — mis. bayar tiap tanggal 5"
                                       class="mt-1.5 h-12 w-full rounded-xl border border-line bg-white px-4 text-[0.9375rem] text-ink placeholder:text-umber-soft/70 focus:border-terracotta focus:outline-none">
                                @error('catatan')
                                    <p class="mt-1.5 text-[0.8125rem] text-merah-tua">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label for="mulai-biaya" class="block text-[0.8125rem] font-semibold text-ink">
                                    Mulai membebani <x-wajib />
                                </label>
                                <input id="mulai-biaya" type="date" wire:model="mulai" value="{{ $mulai }}"
                                       class="mt-1.5 h-12 w-full rounded-xl border border-line bg-white px-4 text-[0.9375rem] text-ink focus:border-terracotta focus:outline-none">
                                @error('mulai')
                                    <p class="mt-1.5 text-[0.8125rem] text-merah-tua">{{ $message }}</p>
                                @enderror
                            </div>

                            <div>
                                <label for="selesai-biaya" class="block text-[0.8125rem] font-semibold text-ink">Berhenti</label>
                                <input id="selesai-biaya" type="date" wire:model="selesai" value="{{ $selesai }}"
                                       min="{{ $mulai }}"
                                       class="mt-1.5 h-12 w-full rounded-xl border border-line bg-white px-4 text-[0.9375rem] text-ink focus:border-terracotta focus:outline-none">
                                <p class="mt-1 text-[0.75rem] text-umber-soft">
                                    Kosongkan kalau masih berjalan.
                                </p>
                                @error('selesai')
                                    <p class="mt-1.5 text-[0.8125rem] text-merah-tua">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>

                        {{--
                            Perbedaan yang WAJIB dikatakan di layar, bukan cuma di komentar kode.

                            Tanpa kalimat ini, pemilik akan mengira mencatat sewa di sini berarti
                            sewanya sudah tercatat sebagai uang keluar — lalu laporan kasnya
                            kurang satu setengah juta dan ia tidak tahu kenapa.
                        --}}
                        <div class="rounded-xl border border-line p-3.5">
                            <p class="eyebrow text-umber-soft">Bedanya dengan kas keluar</p>
                            <ul class="mt-2 space-y-1.5 text-[0.8125rem] text-umber">
                                <li class="flex gap-2">
                                    <span aria-hidden="true" class="mt-2 size-1.5 shrink-0 rounded-full bg-terracotta"></span>
                                    <span>Ini <span class="font-semibold text-ink">angka perencanaan</span> — sewa tetap membebani hari ini walau baru dibayar tanggal 5, dan mencatatnya di sini <span class="font-semibold text-ink">tidak mengurangi uang kas</span>.</span>
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
