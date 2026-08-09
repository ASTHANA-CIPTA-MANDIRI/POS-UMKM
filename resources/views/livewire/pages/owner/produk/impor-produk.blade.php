{{--
    Impor produk dari CSV — unggah, LIHAT DULU, baru simpan.

    Data dari komponen: $pratinjau (null sebelum ada berkas), $maksBaris, $maksMb.

    Halaman ini sengaja BUKAN panel di atas layar Produk. Isinya tiga tahap yang harus
    terbaca berurutan (siapkan berkas → periksa hasilnya → simpan), dan tiga tahap di dalam
    kotak melayang memaksa orang menggulir di dalam gulungan untuk membandingkan daftar yang
    ditolak dengan berkasnya sendiri.
--}}
@php
    $rupiah = fn ($nilai) => 'Rp '.number_format((float) $nilai, 0, ',', '.');

    $adaPratinjau = $pratinjau !== null;
    $kolomHilang = $pratinjau['kolomHilang'] ?? [];
    $siap = $pratinjau['siap'] ?? [];
    $ditolak = $pratinjau['ditolak'] ?? [];
    $baru = collect($siap)->whereNull('menimpa')->count();
    $diperbarui = count($siap) - $baru;
@endphp

<div x-data>
    <x-kartu-alat
        judul="Impor produk"
        jumlah="{{ count($siap) }}"
        keterangan="Masukkan banyak barang sekaligus dari berkas CSV. Yang wajib cuma dua kolom: nama dan harga."
    >
        <x-slot:aksi>
            <a href="{{ route('owner.produk') }}" wire:navigate
               class="tombol-kedua flex h-11 cursor-pointer items-center gap-2 px-4 text-[0.875rem]">
                <svg viewBox="0 0 20 20" class="size-4" fill="none" aria-hidden="true">
                    <path d="M12 5 7 10l5 5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
                Kembali ke Produk
            </a>
        </x-slot:aksi>
    </x-kartu-alat>

    {{-- ── Tahap 1: siapkan berkas ───────────────────────────────────────── --}}
    <div class="kartu mb-4 p-5">
        <p class="eyebrow text-umber-soft">Langkah 1</p>
        <h2 class="mt-0.5 text-[1rem] font-bold text-ink">Siapkan berkasnya</h2>

        {{-- Templat ditawarkan LEBIH DULU daripada kotak unggah, dan itu bukan urutan yang
             acak: orang yang tidak tahu nama kolomnya akan mengunggah berkas apa adanya,
             ditolak, lalu menyimpulkan fiturnya tidak bisa membaca file Excel-nya. --}}
        <p class="mt-2 text-[0.875rem] text-umber">
            Belum punya bentuk berkasnya? Unduh templat ini, isi di Excel atau Google Sheets, lalu simpan sebagai CSV.
        </p>

        <button type="button" wire:click="unduhTemplat"
                class="tombol-kedua mt-3 flex h-11 cursor-pointer items-center gap-2 px-4 text-[0.875rem]">
            <svg viewBox="0 0 20 20" class="size-4" fill="none" aria-hidden="true">
                <path d="M10 3v9m0 0 3.5-3.5M10 12 6.5 8.5M4 15.5h12" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
            Unduh templat CSV
        </button>

        <div class="mt-4 rounded-xl border border-line p-3.5">
            <p class="eyebrow text-umber-soft">Yang perlu diketahui</p>
            <ul class="mt-2 space-y-1.5 text-[0.8125rem] text-umber">
                <li class="flex gap-2">
                    <span aria-hidden="true" class="mt-2 size-1.5 shrink-0 rounded-full bg-terracotta"></span>
                    <span>Wajib ada kolom <span class="font-semibold text-ink">nama</span> dan <span class="font-semibold text-ink">harga</span>. Sisanya boleh tidak ada.</span>
                </li>
                <li class="flex gap-2">
                    <span aria-hidden="true" class="mt-2 size-1.5 shrink-0 rounded-full bg-terracotta"></span>
                    {{-- Kalimat ini menutup keluhan yang paling sering datang dari Excel
                         berbahasa Indonesia: berkasnya berpemisah titik koma, dan pemilik
                         tidak punya cara tahu itu penting. Aplikasinya memang menebak sendiri
                         — dan justru karena itu ia harus mengatakannya. --}}
                    <span>Pemisahnya boleh koma, titik koma, atau tab — dikenali sendiri. Berkas dari Excel berbahasa Indonesia langsung bisa dipakai.</span>
                </li>
                <li class="flex gap-2">
                    <span aria-hidden="true" class="mt-2 size-1.5 shrink-0 rounded-full bg-terracotta"></span>
                    <span>Harga ditulis angkanya saja: <span class="tabular font-semibold text-ink">15000</span> atau <span class="tabular font-semibold text-ink">15.000</span>. Jangan pakai sen.</span>
                </li>
                <li class="flex gap-2">
                    <span aria-hidden="true" class="mt-2 size-1.5 shrink-0 rounded-full bg-terracotta"></span>
                    <span>Barang yang <span class="font-semibold text-ink">kodenya sama</span> akan diperbarui, bukan digandakan. Yang tanpa kode selalu jadi barang baru.</span>
                </li>
                <li class="flex gap-2">
                    <span aria-hidden="true" class="mt-2 size-1.5 shrink-0 rounded-full bg-terracotta"></span>
                    {{-- Wajib disebutkan: pemilik yang menaruh kolom stok di berkasnya akan
                         mengira stoknya ikut masuk, dan baru tahu tidak saat kasir menjual
                         barang yang saldonya nol. --}}
                    <span><span class="font-semibold text-ink">Stok tidak ikut diimpor.</span> Sisa barang diisi lewat Hitung stok atau nota belanja, supaya tiap perubahan punya catatannya.</span>
                </li>
                <li class="flex gap-2">
                    <span aria-hidden="true" class="mt-2 size-1.5 shrink-0 rounded-full bg-terracotta"></span>
                    <span>Paling banyak {{ number_format($maksBaris, 0, ',', '.') }} baris dan {{ $maksMb }} MB sekali unggah.</span>
                </li>
            </ul>
        </div>
    </div>

    {{-- ── Tahap 2: unggah ───────────────────────────────────────────────── --}}
    <div class="kartu mb-4 p-5">
        <p class="eyebrow text-umber-soft">Langkah 2</p>
        <h2 class="mt-0.5 text-[1rem] font-bold text-ink">Pilih berkasnya</h2>

        <div class="mt-3">
            <label for="berkas-impor" class="sr-only">Berkas CSV</label>
            <input id="berkas-impor" type="file" wire:model="berkas" accept=".csv,text/csv,text/plain"
                   class="block w-full cursor-pointer rounded-xl border border-line bg-white p-3 text-[0.875rem] text-ink file:mr-3 file:cursor-pointer file:rounded-lg file:border-0 file:bg-terracotta file:px-4 file:py-2 file:text-[0.8125rem] file:font-bold file:text-white">

            <p wire:loading wire:target="berkas" class="mt-2 text-[0.8125rem] font-semibold text-terracotta-deep">
                Membaca berkasnya…
            </p>

            @error('berkas')
                <p class="mt-2 text-[0.8125rem] text-merah-tua">{{ $message }}</p>
            @enderror
        </div>
    </div>

    {{-- ── Tahap 3: pratinjau ────────────────────────────────────────────── --}}
    @if ($adaPratinjau)
        <div class="kartu mb-4 p-5">
            <p class="eyebrow text-umber-soft">Langkah 3</p>
            <h2 class="mt-0.5 text-[1rem] font-bold text-ink">Periksa dulu sebelum disimpan</h2>
            <p class="mt-1 text-[0.8125rem] text-umber">
                Belum ada yang tersimpan. Semua di bawah ini baru rencana.
            </p>

            @if ($kolomHilang !== [])
                {{-- Kolom wajib yang hilang membatalkan segalanya, jadi ia diletakkan paling
                     atas dan sendirian — bukan sebagai satu baris di antara ringkasan angka
                     yang semuanya nol. --}}
                <div class="mt-4 rounded-xl border border-merah/25 bg-merah/8 p-4">
                    <p class="text-[0.9375rem] font-bold text-merah-tua">
                        Kolom {{ collect($kolomHilang)->join(' dan ') }} tidak ditemukan di berkasnya.
                    </p>
                    <p class="mt-1 text-[0.8125rem] text-umber">
                        Baris pertama berkas harus berisi judul kolom. Kalau bingung, unduh templat di Langkah 1 —
                        nama kolomnya sudah benar, tinggal diisi.
                    </p>
                </div>
            @else
                <div class="mt-4 grid gap-3 sm:grid-cols-3">
                    <div class="rounded-xl border border-line px-3 py-2.5">
                        <p class="text-[0.6875rem] font-semibold tracking-wide text-umber-soft uppercase">Barang baru</p>
                        <p class="tabular text-[1.125rem] font-bold text-ink">{{ $baru }}</p>
                    </div>
                    {{-- Dipisah dari "barang baru" dengan sengaja: yang diperbarui berarti
                         HARGA BARANG YANG SUDAH ADA akan berubah, dan itu keputusan yang
                         berbeda sifatnya. Satu angka gabungan menyembunyikannya. --}}
                    <div class="rounded-xl border border-line px-3 py-2.5">
                        <p class="text-[0.6875rem] font-semibold tracking-wide text-umber-soft uppercase">Diperbarui</p>
                        <p class="tabular text-[1.125rem] font-bold text-ink">{{ $diperbarui }}</p>
                        @if ($diperbarui > 0)
                            <p class="text-[0.6875rem] text-umber">kodenya sudah ada — harganya ikut berubah</p>
                        @endif
                    </div>
                    <div class="rounded-xl border px-3 py-2.5 {{ count($ditolak) > 0 ? 'border-merah/25 bg-merah/8' : 'border-line' }}">
                        <p class="text-[0.6875rem] font-semibold tracking-wide text-umber-soft uppercase">Dilewati</p>
                        <p class="tabular text-[1.125rem] font-bold {{ count($ditolak) > 0 ? 'text-merah-tua' : 'text-umber-soft' }}">
                            {{ count($ditolak) > 0 ? count($ditolak) : '—' }}
                        </p>
                    </div>
                </div>

                @if ($pratinjau['terpotong'] ?? false)
                    <p class="mt-3 rounded-xl border border-jingga/30 bg-jingga/10 px-4 py-3 text-[0.8125rem] text-ink">
                        Berkasnya lebih panjang daripada {{ number_format($maksBaris, 0, ',', '.') }} baris.
                        Yang di bawah itu <span class="font-semibold">tidak ikut terbaca</span> — pecah berkasnya lalu unggah bergantian.
                    </p>
                @endif

                @if (($pratinjau['kolomTakDikenal'] ?? []) !== [])
                    {{-- Dikabarkan, tidak diabaikan diam-diam: pemilik yang menaruh stok awal
                         di kolom "stok" berhak tahu kolom itu tidak dibaca — kalau tidak, ia
                         menyimpulkan stoknya sudah masuk. --}}
                    <p class="mt-3 rounded-xl border border-line bg-cream/60 px-4 py-3 text-[0.8125rem] text-umber">
                        Kolom yang tidak dibaca:
                        <span class="font-semibold text-ink">{{ collect($pratinjau['kolomTakDikenal'])->join(', ') }}</span>.
                        Isinya diabaikan.
                    </p>
                @endif

                {{-- Daftar yang DITOLAK ditaruh SEBELUM daftar yang siap, dan itu disengaja:
                     yang perlu diperbaiki orang adalah yang ditolak, dan menaruhnya di bawah
                     300 baris yang baik-baik saja membuatnya tidak pernah terbaca. --}}
                @if ($ditolak !== [])
                    <div class="mt-4">
                        <p class="eyebrow text-umber-soft">Baris yang dilewati</p>
                        <ul class="mt-2 space-y-1.5">
                            @foreach ($ditolak as $tolak)
                                <li class="rounded-lg border border-merah/20 bg-merah/6 px-3 py-2 text-[0.8125rem]">
                                    <span class="font-bold text-ink">Baris {{ $tolak['nomor'] }}</span>
                                    @if ($tolak['nama'] !== '')
                                        <span class="text-umber">· {{ $tolak['nama'] }}</span>
                                    @endif
                                    <span class="block text-merah-tua">{{ $tolak['sebab'] }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if ($siap !== [])
                    <div class="mt-4">
                        <p class="eyebrow text-umber-soft">Yang akan masuk</p>

                        <div class="mt-2 overflow-hidden rounded-xl border border-line">
                            {{-- Tabelnya digulir MENDATAR di dalam wadahnya sendiri, bukan
                                 membuat seluruh halaman menggulir ke samping. Wadah bergulir
                                 sengaja diberi tinggi maksimum juga: 2.000 baris pratinjau
                                 membuat tombol "Simpan" di bawahnya tidak akan pernah
                                 ditemukan siapa pun. --}}
                            <div class="max-h-[26rem] overflow-x-auto overflow-y-auto">
                                <table class="w-full min-w-[36rem] text-left">
                                    <thead class="sticky top-0 bg-cream-deep">
                                        <tr>
                                            <th class="px-3 py-2.5 text-[0.6875rem] font-semibold tracking-wide text-umber uppercase">Baris</th>
                                            <th class="px-3 py-2.5 text-[0.6875rem] font-semibold tracking-wide text-umber uppercase">Nama</th>
                                            <th class="px-3 py-2.5 text-right text-[0.6875rem] font-semibold tracking-wide text-umber uppercase">Harga</th>
                                            <th class="px-3 py-2.5 text-[0.6875rem] font-semibold tracking-wide text-umber uppercase">Satuan</th>
                                            <th class="px-3 py-2.5 text-[0.6875rem] font-semibold tracking-wide text-umber uppercase">Keadaan</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-line-soft">
                                        @foreach ($siap as $baris)
                                            <tr wire:key="siap-{{ $baris['nomor'] }}">
                                                <td class="tabular px-3 py-2 text-[0.8125rem] text-umber-soft">{{ $baris['nomor'] }}</td>
                                                <td class="px-3 py-2 text-[0.8125rem] font-semibold text-ink">{{ $baris['muatan']['nama_produk'] }}</td>
                                                <td class="tabular px-3 py-2 text-right text-[0.8125rem] font-bold text-ink">{{ $rupiah($baris['muatan']['harga_default']) }}</td>
                                                <td class="px-3 py-2 text-[0.8125rem] text-umber">{{ $baris['muatan']['satuan'] }}</td>
                                                <td class="px-3 py-2">
                                                    @if ($baris['menimpa'] !== null)
                                                        <x-lencana warna="jingga" :denyut="false">Diperbarui</x-lencana>
                                                    @else
                                                        <x-lencana warna="hijau" :denyut="false">Baru</x-lencana>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                @endif
            @endif

            <div class="mt-5 flex flex-col gap-2 border-t border-line pt-4 sm:flex-row sm:justify-end">
                <button type="button" wire:click="batal"
                        class="h-12 cursor-pointer rounded-xl border border-line px-5 text-[0.9375rem] font-semibold text-ink transition-colors hover:bg-cream sm:order-1">
                    Batal
                </button>
                <button type="button" wire:click="simpan" wire:loading.attr="disabled"
                        @disabled($siap === [])
                        class="h-12 cursor-pointer rounded-xl bg-terracotta px-6 text-[0.9375rem] font-bold text-white transition-colors hover:bg-terracotta-deep disabled:cursor-not-allowed disabled:opacity-60 sm:order-2">
                    <span wire:loading.remove wire:target="simpan">
                        {{ $siap === [] ? 'Tidak ada yang bisa disimpan' : 'Simpan '.count($siap).' barang' }}
                    </span>
                    <span wire:loading wire:target="simpan">Menyimpan…</span>
                </button>
            </div>
        </div>
    @endif
</div>
