{{--
    Lembar hitung fisik (stok opname).

    Data dari komponen: $daftar (paginator), $alasanTersedia, $jumlahTerisi,
    $outletTersedia, $outletDipakai. Ikatan per baris di-key id barang:
    fisik.<kunci> / alasan.<kunci> / catatan.<kunci> — dan galatnya di nama yang sama.

    DUA keputusan tampilan yang menentukan halaman ini terpakai atau tidak:

    1. **Selisih dihitung di Alpine, bukan di server.** Pemilik mengetik 80 baris; satu
       perjalanan Livewire per ketikan berarti menunggu jaringan di setiap angka, dan
       sesudah sepuluh baris ia berhenti memakai fitur ini. Ikatan ke server memakai
       `.blur` — satu permintaan per baris SELESAI diketik, bukan per huruf. Itu juga yang
       membuat angka "N baris terisi" di bar bawah ikut bergerak, dan yang mengisi
       $sistemSaatDibuka (lihat Opname::updatedFisik) sehingga kartu stok bisa menjelaskan
       selisih yang terjadi karena kasir masih berjualan saat penghitungan berlangsung.

    2. **Angka "baris terisi" adalah SELURUH lembar, bukan halaman ini.** Itulah satu
       satunya hal yang meyakinkan orang bahwa 40 baris yang diketik di halaman 1 tidak
       hilang saat ia pindah ke halaman 2 — dan tanpa keyakinan itu ia akan mengulang
       penghitungannya dari awal.
--}}
@php
    $angka = fn ($nilai) => rtrim(rtrim(number_format((float) $nilai, 3, ',', '.'), '0'), ',');

    /*
     * Selisih versi server, dipakai sebagai isi awal sel selisih.
     *
     * Gunanya bukan menggantikan Alpine: kalau baris sudah pernah diketik (mis. lembarnya
     * dibuka lagi, atau simpan gagal dan barisnya dibiarkan terisi), selnya harus sudah
     * menunjukkan angka yang benar sebelum satu tombol pun ditekan.
     */
    $bedaServer = function (array $b) use ($fisik): ?float {
        $nilai = $fisik[$b['kunci']] ?? null;

        if ($nilai === null || $nilai === '' || ! is_numeric($nilai)) {
            return null;
        }

        return round((float) $nilai - (float) $b['sistem'], 3);
    };

    // Nama barang per kunci untuk ringkasan galat: pesan validasi baris fisik menyebut
    // "jumlah fisik" tanpa nama barangnya, dan galat tanpa nama tidak bisa ditindaklanjuti.
    // $namaPerKunci datang dari komponen, BUKAN dari $daftar->items(): baris yang gagal
    // bisa berada di halaman lain, dan nama yang diambil dari halaman ini saja akan
    // menampilkannya sebagai "Baris lain". Lihat Opname::namaPerKunci().
@endphp

<div>
    <x-kartu-alat
        judul="Lembar hitung fisik"
        jumlah="{{ $daftar->total() }}"
        keterangan="Isi jumlah yang benar-benar ada di rak. Baris yang dikosongkan berarti belum dihitung — bukan nol, karena nol berarti raknya kosong."
    >
        <x-slot:aksi>
            <a href="{{ route('owner.stok', $outletDipakai !== null ? ['outlet' => $outletDipakai] : []) }}"
               wire:navigate
               class="flex h-11 items-center gap-2 rounded-xl border border-line bg-white px-5 text-[0.875rem] font-semibold text-ink transition-colors hover:bg-cream">
                <svg viewBox="0 0 20 20" class="size-4" fill="none" aria-hidden="true">
                    <path d="M11.5 5.5 7 10l4.5 4.5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
                Daftar stok
            </a>
        </x-slot:aksi>

        <x-slot:saringan>
            <div class="grid gap-3 lg:grid-cols-[1fr_11rem_13rem]">
                <div class="min-w-0">
                    <label for="cari" class="sr-only">Cari barang</label>
                    <div class="relative">
                        <svg viewBox="0 0 20 20" class="pointer-events-none absolute top-1/2 left-4 size-4 -translate-y-1/2 text-umber-soft"
                             fill="none" aria-hidden="true">
                            <circle cx="9" cy="9" r="5.5" stroke="currentColor" stroke-width="1.6" />
                            <path d="m13.5 13.5 3 3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
                        </svg>
                        <input id="cari" type="search" wire:model.live.debounce.300ms="cari"
                               placeholder="Cari nama barang, SKU, atau barcode…"
                               class="h-11 w-full rounded-xl border border-line bg-white pr-4 pl-11 text-[0.875rem] text-ink focus:border-terracotta focus:outline-none">
                    </div>
                </div>

                <div class="min-w-0">
                    <label for="jenis" class="sr-only">Jenis barang</label>
                    <select id="jenis" wire:model.live="jenis"
                            class="h-11 w-full rounded-xl border border-line bg-white px-3 text-[0.875rem] font-medium text-ink focus:border-terracotta focus:outline-none">
                        <option value="semua">Produk &amp; bahan</option>
                        <option value="produk">Produk saja</option>
                        <option value="bahan">Bahan baku saja</option>
                    </select>
                </div>

                <div class="min-w-0">
                    <label for="status" class="sr-only">Saringan status</label>
                    <select id="status" wire:model.live="status"
                            class="h-11 w-full rounded-xl border border-line bg-white px-3 text-[0.875rem] font-medium text-ink focus:border-terracotta focus:outline-none">
                        <option value="semua">Semua status</option>
                        <option value="minus">Minus</option>
                        <option value="habis">Habis</option>
                        <option value="menipis">Menipis</option>
                        <option value="aman">Aman</option>
                        <option value="perlu_diperiksa">Perlu dihitung ulang</option>
                        <option value="belum_pernah">Belum pernah diopname</option>
                    </select>
                </div>

                @if ($outletTersedia !== [])
                    <div class="min-w-0 lg:col-span-full lg:w-64">
                        <label for="outlet" class="sr-only">Outlet</label>
                        <select id="outlet" wire:model.live="outletId"
                                class="h-11 w-full rounded-xl border border-line bg-white px-3 text-[0.875rem] font-medium text-ink focus:border-terracotta focus:outline-none">
                            @foreach ($outletTersedia as $o)
                                <option value="{{ $o['id'] }}">{{ $o['nama'] }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
            </div>
        </x-slot:saringan>
    </x-kartu-alat>

    {{-- Ringkasan galat SELURUH lembar.
         Validasi berjalan atas semua baris terisi, termasuk yang sedang tidak tampak —
         jadi tanpa ringkasan ini, satu baris di halaman 3 bisa menahan simpan tanpa
         penjelasan apa pun di layar yang sedang dilihat. --}}
    @if ($errors->any())
        <div role="alert" class="kartu mb-4 border border-merah/30 px-5 py-4 sm:mb-5 sm:px-6">
            <p class="text-[0.875rem] font-bold text-merah-deep">
                {{ $errors->count() }} baris belum bisa disimpan
            </p>
            {{-- Dua kalimat berbeda karena dua keadaan yang berbeda, dan keliru memilihnya
                 berarti memberi keterangan yang salah tentang data yang sudah berubah:
                 galat validasi menahan seluruh lembar, sedangkan kegagalan sesudah simpan
                 menyisakan baris-baris yang SUDAH tercatat. Pemilik yang membaca "tidak ada
                 yang tersimpan" padahal sebagiannya sudah masuk akan menghitung ulang
                 barang yang sudah benar. --}}
            <p class="mt-0.5 text-[0.8125rem] text-umber">
                @if ($sebagianTersimpan)
                    Baris lain sudah tersimpan. Yang di bawah ini belum, dan angkanya masih terisi — perbaiki lalu simpan lagi.
                @else
                    Tidak ada satu baris pun yang tersimpan sampai semuanya benar — angka yang sudah diketik tetap ada.
                @endif
            </p>
            <ul class="mt-2 space-y-1">
                @foreach ($errors->keys() as $kunciGalat)
                    @php $kunciBaris = str($kunciGalat)->after('.')->toString(); @endphp
                    <li class="text-[0.8125rem] text-ink">
                        <span class="font-semibold">{{ $namaPerKunci[$kunciBaris] ?? 'Baris lain' }}:</span>
                        {{ $errors->first($kunciGalat) }}
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($daftar->isEmpty())
        <x-kosong judul="Tidak ada barang yang cocok"
                  keterangan="Coba ubah kata pencarian atau saringannya. Produk tanpa pelacakan stok dan menu berbasis resep tidak dihitung di sini — yang dihitung adalah bahan bakunya.">
            <x-slot:aksi>
                <button type="button" wire:click="$set('status', 'semua')"
                        class="h-11 cursor-pointer rounded-xl bg-terracotta px-5 text-[0.875rem] font-bold text-white transition-colors hover:bg-terracotta-deep">
                    Tampilkan semua
                </button>
            </x-slot:aksi>
        </x-kosong>
    @else
        {{-- Kartu di <lg, tabel di ≥lg — pola yang sama dengan daftar produk & stok. --}}
        <div class="space-y-3 lg:hidden">
            @foreach ($daftar as $b)
                @php
                    $kunci = $b['kunci'];
                    $beda = $bedaServer($b);
                    $satuan = $b['satuan_dasar'] ?? $b['satuan'] ?? '';
                @endphp

                <div wire:key="kartu-{{ $kunci }}"
                     x-data="barisOpname(@js((string) ($fisik[$kunci] ?? '')), @js((float) $b['sistem']), @js((string) ($alasan[$kunci] ?? '')))"
                     x-bind:class="selisih ? 'border-jingga bg-jingga/5' : 'border-transparent'"
                     @class(['kartu border p-4', 'border-jingga bg-jingga/5' => $beda !== null && $beda !== 0.0, 'border-transparent' => $beda === null || $beda === 0.0])>
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-[0.9375rem] font-bold text-ink">{{ $b['nama'] }}</p>
                            <p class="mt-0.5 text-[0.75rem] text-umber">
                                {{ $b['jenis'] === 'produk' ? 'Produk' : 'Bahan baku' }}
                                · {{ $b['kode'] ?: 'tanpa kode' }}
                                · sistem
                                <span class="tabular font-semibold text-ink">
                                    {{ $b['punya_baris'] ? trim($angka($b['sistem']).' '.$satuan) : 'belum dihitung' }}
                                </span>
                            </p>
                        </div>
                        @if ($b['perlu_diperiksa'])
                            <span class="shrink-0 rounded-full bg-jingga/15 px-2 py-0.5 text-[0.6875rem] font-semibold text-jingga-tua">
                                perlu dihitung ulang
                            </span>
                        @endif
                    </div>

                    <div class="mt-3 grid gap-3 sm:grid-cols-[1fr_7rem]">
                        <div class="min-w-0">
                            <label for="fisik-hp-{{ $kunci }}" class="block text-[0.8125rem] font-semibold text-ink">
                                Jumlah fisik {{ $satuan !== '' ? '('.$satuan.')' : '' }}
                            </label>
                            <input id="fisik-hp-{{ $kunci }}" type="text" inputmode="decimal" autocomplete="off"
                                   wire:model.blur="fisik.{{ $kunci }}"
                                   x-on:input="ketik($event.target)"
                                   placeholder="belum dihitung"
                                   class="tabular mt-1.5 h-12 w-full rounded-xl border border-line bg-white px-4 text-[0.9375rem] text-ink placeholder:text-umber-soft/70 focus:border-terracotta focus:outline-none">
                            @error('fisik.'.$kunci)
                                <p class="mt-1.5 text-[0.8125rem] text-merah-deep">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="min-w-0">
                            <span class="block text-[0.8125rem] font-semibold text-ink">Selisih</span>
                            <p class="tabular mt-1.5 flex h-12 items-center justify-end rounded-xl border border-line px-4 text-[0.9375rem] font-bold"
                               x-bind:class="selisih ? 'text-jingga-tua' : 'text-umber-soft'"
                               x-text="teksBeda">{{ $beda === null ? '—' : ($beda > 0 ? '+' : '').$angka($beda) }}</p>
                        </div>
                    </div>

                    <div class="mt-3">
                        <label for="alasan-hp-{{ $kunci }}" class="block text-[0.8125rem] font-semibold text-ink">
                            Alasan selisih
                            <span class="font-normal text-umber-soft" x-cloak x-show="selisih">— wajib dipilih</span>
                        </label>
                        <select id="alasan-hp-{{ $kunci }}" wire:model.blur="alasan.{{ $kunci }}"
                                x-on:change="alasan = $event.target.value"
                                class="mt-1.5 h-12 w-full rounded-xl border border-line bg-white px-3 text-[0.9375rem] text-ink focus:border-terracotta focus:outline-none">
                            <option value="">Belum dipilih</option>
                            @foreach ($alasanTersedia as $a)
                                <option value="{{ $a['nilai'] }}">{{ $a['label'] }}</option>
                            @endforeach
                        </select>
                        @error('alasan.'.$kunci)
                            <p class="mt-1.5 text-[0.8125rem] text-merah-deep">{{ $message }}</p>
                        @enderror

                        {{-- Catatan hanya untuk "Lainnya": mewajibkannya di semua alasan
                             membuat kolomnya diisi "-" oleh orang yang sedang menghitung 200
                             barang, dan sesudah itu isinya tidak berarti apa pun. --}}
                        <div class="mt-3" x-cloak x-show="butuhCatatan">
                            <label for="catatan-hp-{{ $kunci }}" class="block text-[0.8125rem] font-semibold text-ink">
                                Catatan (wajib untuk alasan "Lainnya")
                            </label>
                            <input id="catatan-hp-{{ $kunci }}" type="text" wire:model.blur="catatan.{{ $kunci }}"
                                   placeholder="mis. dipakai sendiri untuk contoh"
                                   class="mt-1.5 h-12 w-full rounded-xl border border-line bg-white px-4 text-[0.9375rem] text-ink placeholder:text-umber-soft/70 focus:border-terracotta focus:outline-none">
                            @error('catatan.'.$kunci)
                                <p class="mt-1.5 text-[0.8125rem] text-merah-deep">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="kartu hidden overflow-hidden lg:block">
            <table class="w-full text-left">
                <thead>
                    <tr class="border-b border-line">
                        <th class="px-5 py-3.5 text-[0.75rem] font-semibold tracking-wide text-umber uppercase">Barang</th>
                        <th class="w-28 px-5 py-3.5 text-right text-[0.75rem] font-semibold tracking-wide text-umber uppercase">Sistem</th>
                        <th class="w-36 px-5 py-3.5 text-right text-[0.75rem] font-semibold tracking-wide text-umber uppercase">Fisik</th>
                        <th class="w-28 px-5 py-3.5 text-right text-[0.75rem] font-semibold tracking-wide text-umber uppercase">Selisih</th>
                        <th class="w-72 px-5 py-3.5 text-[0.75rem] font-semibold tracking-wide text-umber uppercase">Alasan selisih</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line-soft">
                    @foreach ($daftar as $b)
                        @php
                            $kunci = $b['kunci'];
                            $beda = $bedaServer($b);
                            $satuan = $b['satuan_dasar'] ?? $b['satuan'] ?? '';
                        @endphp

                        {{-- Baris yang fisiknya ≠ sistem berwarna SEBELUM disimpan: itu satu
                             satunya cara pemilik melihat berapa baris yang akan menghasilkan
                             mutasi, saat lembarnya masih bisa diperbaiki. --}}
                        <tr wire:key="baris-{{ $kunci }}"
                            x-data="barisOpname(@js((string) ($fisik[$kunci] ?? '')), @js((float) $b['sistem']), @js((string) ($alasan[$kunci] ?? '')))"
                            x-bind:class="selisih ? 'bg-jingga/10' : ''"
                            @class(['bg-jingga/10' => $beda !== null && $beda !== 0.0])>
                            <td class="px-5 py-3">
                                <div class="min-w-0">
                                    <p class="truncate text-[0.9375rem] font-semibold text-ink">{{ $b['nama'] }}</p>
                                    <p class="truncate text-[0.75rem] text-umber-soft">
                                        {{ $b['jenis'] === 'produk' ? 'Produk' : 'Bahan baku' }}
                                        · {{ $b['kode'] ?: 'tanpa kode' }}
                                        @if ($b['perlu_diperiksa'])
                                            · <span class="font-semibold text-jingga-tua">perlu dihitung ulang</span>
                                        @endif
                                    </p>
                                </div>
                            </td>

                            <td class="tabular px-5 py-3 text-right text-[0.875rem]">
                                @if ($b['punya_baris'])
                                    <span class="font-semibold text-ink">{{ $angka($b['sistem']) }}</span>
                                    <span class="block text-[0.6875rem] text-umber-soft">{{ $satuan !== '' ? $satuan : '—' }}</span>
                                @else
                                    <span class="text-umber-soft">—</span>
                                    <span class="block text-[0.6875rem] text-umber-soft">belum dihitung</span>
                                @endif
                            </td>

                            <td class="px-5 py-3">
                                <label for="fisik-{{ $kunci }}" class="sr-only">Jumlah fisik {{ $b['nama'] }}</label>
                                <input id="fisik-{{ $kunci }}" type="text" inputmode="decimal" autocomplete="off"
                                       wire:model.blur="fisik.{{ $kunci }}"
                                       x-on:input="ketik($event.target)"
                                       placeholder="—"
                                       class="tabular h-12 w-full rounded-xl border border-line bg-white px-3 text-right text-[0.9375rem] text-ink placeholder:text-umber-soft/70 focus:border-terracotta focus:outline-none">
                                @error('fisik.'.$kunci)
                                    <p class="mt-1 text-[0.75rem] text-merah-deep">{{ $message }}</p>
                                @enderror
                            </td>

                            <td class="tabular px-5 py-3 text-right text-[0.9375rem] font-bold">
                                <span x-bind:class="selisih ? 'text-jingga-tua' : 'text-umber-soft'"
                                      x-text="teksBeda">{{ $beda === null ? '—' : ($beda > 0 ? '+' : '').$angka($beda) }}</span>
                            </td>

                            <td class="px-5 py-3">
                                <label for="alasan-{{ $kunci }}" class="sr-only">Alasan selisih {{ $b['nama'] }}</label>
                                <select id="alasan-{{ $kunci }}" wire:model.blur="alasan.{{ $kunci }}"
                                        x-on:change="alasan = $event.target.value"
                                        class="h-12 w-full rounded-xl border border-line bg-white px-3 text-[0.875rem] text-ink focus:border-terracotta focus:outline-none">
                                    <option value="">Belum dipilih</option>
                                    @foreach ($alasanTersedia as $a)
                                        <option value="{{ $a['nilai'] }}">{{ $a['label'] }}</option>
                                    @endforeach
                                </select>
                                <p class="mt-1 text-[0.75rem] text-jingga-tua" x-cloak x-show="selisih && alasan === ''">
                                    Wajib dipilih karena fisiknya berbeda dari sistem.
                                </p>
                                @error('alasan.'.$kunci)
                                    <p class="mt-1 text-[0.75rem] text-merah-deep">{{ $message }}</p>
                                @enderror

                                <div class="mt-2" x-cloak x-show="butuhCatatan">
                                    <label for="catatan-{{ $kunci }}" class="sr-only">Catatan {{ $b['nama'] }}</label>
                                    <input id="catatan-{{ $kunci }}" type="text" wire:model.blur="catatan.{{ $kunci }}"
                                           placeholder="Catatan wajib untuk alasan “Lainnya”"
                                           class="h-12 w-full rounded-xl border border-line bg-white px-3 text-[0.875rem] text-ink placeholder:text-umber-soft/70 focus:border-terracotta focus:outline-none">
                                    @error('catatan.'.$kunci)
                                        <p class="mt-1 text-[0.75rem] text-merah-deep">{{ $message }}</p>
                                    @enderror
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-4">{{ $daftar->links() }}</div>
    @endif

    {{-- ── Bar simpan ──────────────────────────────────────────────────────
         sticky, bukan fixed: ia tetap terlihat selama menggulir, tapi tetap punya tempat
         sendiri di alur halaman sehingga tidak pernah menutupi baris terakhir saat lembar
         sudah digulir sampai bawah. --}}
    <div class="sticky bottom-0 z-10 mt-4 flex flex-wrap items-center justify-between gap-3 rounded-[20px] border border-line bg-white/95 px-4 py-3 backdrop-blur sm:px-5">
        <div class="min-w-0">
            <p class="text-[0.9375rem] font-bold text-ink">
                <span class="tabular">{{ $jumlahTerisi }}</span> baris terisi, belum disimpan
            </p>
            <p class="text-[0.75rem] text-umber">
                Angka ini seluruh lembar, bukan hanya halaman ini — pindah halaman tidak menghapus yang sudah diketik.
                Desimal ditulis dengan titik, mis. 1.5.
            </p>
        </div>

        <button type="button" wire:click="simpan" wire:loading.attr="disabled"
                class="h-12 shrink-0 cursor-pointer rounded-xl bg-terracotta px-6 text-[0.9375rem] font-bold text-white transition-colors hover:bg-terracotta-deep disabled:opacity-60">
            <span wire:loading.remove wire:target="simpan">Simpan hasil hitung</span>
            <span wire:loading wire:target="simpan">Menyimpan…</span>
        </button>
    </div>
</div>
