{{--
    Resep menu: 1 porsi habis berapa bahan mentah.

    Data dari komponen: $daftar (paginator Product ber-eager-load recipeItems.rawMaterial),
    $bahanTersedia, $jumlahSemua/$jumlahAda/$jumlahBelum, plus properti panel.

    Bentuknya menyalin layar Stok & hitung stok (patokan responsif di CLAUDE.md): kartu di
    bawah lg, tabel table-fixed berlebar PERSEN di atasnya, sel kosong bertanda "—", dan 10
    baris per halaman dengan ->links() yang selalu dirender.
--}}
@php
    $angka = fn ($nilai) => rtrim(rtrim(number_format((float) $nilai, 3, ',', '.'), '0'), ',');
    $rupiah = fn ($nilai) => 'Rp '.number_format((float) $nilai, 0, ',', '.');

    /*
     * Resep dibaca sebagai KALIMAT, bukan tabel di dalam tabel.
     *
     * "1 porsi habis 0,25 kg Lele Segar · 0,03 liter Minyak Goreng" bisa dipindai sekilas;
     * tabel bersarang menuntut orang membaca kepala kolomnya dulu. Dipotong di bahan
     * ketiga supaya tinggi barisnya tidak meloncat-loncat antar-baris di tabel.
     */
    $kalimatResep = function ($produk) use ($angka) {
        $bagian = $produk->recipeItems
            ->take(3)
            ->map(fn ($r) => $angka($r->jumlah_terpakai).' '.($r->rawMaterial?->satuan?->value ?? '')
                .' '.($r->rawMaterial?->nama ?? '(bahan terhapus)'))
            ->all();

        $sisa = $produk->recipeItems->count() - count($bagian);

        return implode(' · ', $bagian).($sisa > 0 ? ' dan '.$sisa.' bahan lain' : '');
    };

    /*
     * Modal per porsi — dan kalau ADA SATU bahan yang harga belinya kosong, jangan dihitung.
     *
     * Rp 0 di kolom modal berarti untung 100%, dan pemilik yang membacanya akan menaikkan
     * menu itu justru karena angkanya bohong. Lebih baik mengaku belum bisa menghitung.
     */
    $modalPorsi = function ($produk) {
        if ($produk->recipeItems->isEmpty()) {
            return null;
        }

        $belumBerharga = $produk->recipeItems
            ->first(fn ($r) => $r->rawMaterial?->harga_beli_terakhir === null);

        if ($belumBerharga !== null) {
            return ['kurang' => $belumBerharga->rawMaterial?->nama ?? 'bahan'];
        }

        return ['nilai' => $produk->recipeItems->sum(
            fn ($r) => (float) $r->jumlah_terpakai * (float) $r->rawMaterial->harga_beli_terakhir,
        )];
    };

    $pil = [
        'semua' => ['Semua menu', $jumlahSemua],
        'belum' => ['Belum ada resep', $jumlahBelum],
        'ada' => ['Sudah ada resep', $jumlahAda],
    ];
@endphp

<div x-data>
    <x-kartu-alat
        judul="Resep menu"
        jumlah="{{ $jumlahSemua }}"
        keterangan="Satu porsi menu habis berapa bahan mentah. Setelah resepnya diisi, yang berkurang saat menu terjual adalah bahannya — bukan menunya."
    >
        <x-slot:saringan>
            <div class="grid gap-3 lg:grid-cols-[minmax(0,1fr)_auto]">
                <div class="relative min-w-0">
                    <label for="cari-menu" class="sr-only">Cari menu</label>
                    <svg viewBox="0 0 20 20" class="pointer-events-none absolute top-1/2 left-4 size-4 -translate-y-1/2 text-umber-soft"
                         fill="none" aria-hidden="true">
                        <circle cx="9" cy="9" r="5.5" stroke="currentColor" stroke-width="1.6" />
                        <path d="m13.5 13.5 3 3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
                    </svg>
                    <input id="cari-menu" type="search" wire:model.live.debounce.300ms="cari"
                           placeholder="Cari nama menu…"
                           class="h-11 w-full rounded-xl border border-line bg-white pr-4 pl-11 text-[0.875rem] text-ink focus:border-terracotta focus:outline-none">
                </div>

                {{-- Pil selebar penuh di ponsel (grid-cols-3), seukuran isi di ≥lg. Pil yang
                     menyempit di layar sempit membuat angkanya terpotong. --}}
                <div class="grid grid-cols-3 gap-2 lg:flex lg:items-center">
                    @foreach ($pil as $nilai => [$label, $hitung])
                        <button type="button" wire:click="$set('saring', '{{ $nilai }}')"
                                @class([
                                    'flex h-11 cursor-pointer items-center justify-center gap-1.5 rounded-xl border px-3 text-[0.8125rem] font-semibold transition-colors lg:px-4',
                                    'border-terracotta bg-terracotta text-white' => $saring === $nilai,
                                    'border-line bg-white text-ink hover:bg-cream' => $saring !== $nilai,
                                ])>
                            <span class="truncate">{{ $label }}</span>
                            {{-- Angkanya diberi latar sendiri: pada pil aktif yang sudah
                                 berlatar terracotta, angka polos menghilang ke dalamnya. --}}
                            <span @class([
                                'tabular shrink-0 rounded-md px-1.5 py-0.5 text-[0.75rem] font-bold',
                                'bg-white/25 text-white' => $saring === $nilai,
                                'bg-cream text-umber' => $saring !== $nilai,
                            ])>{{ $hitung }}</span>
                        </button>
                    @endforeach
                </div>
            </div>
        </x-slot:saringan>
    </x-kartu-alat>

    {{-- ── Daftar menu ───────────────────────────────────────────────────── --}}
    @if ($daftar->isEmpty())
        <x-kosong
            :ikon="$cari !== '' ? 'cari' : 'kotak'"
            judul="{{ $cari !== '' ? 'Tidak ada menu yang cocok' : ($saring === 'ada' ? 'Belum ada menu yang punya resep' : 'Belum ada menu') }}"
            keterangan="{{ $cari !== ''
                ? 'Coba ubah kata pencariannya.'
                : ($saring === 'ada'
                    ? 'Pilih satu menu di daftar "Belum ada resep", lalu sebutkan bahan apa saja yang habis untuk satu porsinya.'
                    : 'Menu diisi lewat layar Produk dulu. Sesudah menunya ada, resepnya disusun di sini.') }}"
        />
    @else
        {{-- Kartu di bawah lg, tabel di atasnya: dua bentuk untuk data yang sama. --}}
        <div class="mt-5 grid gap-3 lg:hidden">
            @foreach ($daftar as $menu)
                @php($modal = $modalPorsi($menu))
                <div class="rounded-2xl border border-line bg-white p-4">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="truncate text-[0.9375rem] font-bold text-ink">{{ $menu->nama_produk }}</p>
                            <p class="mt-0.5 text-[0.75rem] text-umber-soft">
                                Dijual {{ $rupiah($menu->harga_default) }} / {{ $menu->satuan?->value ?? 'porsi' }}
                            </p>
                        </div>
                        <x-lencana :warna="$menu->recipeItems->isNotEmpty() ? 'hijau' : 'kelabu'">
                            {{ $menu->recipeItems->isNotEmpty() ? 'Ada resep' : 'Belum ada' }}
                        </x-lencana>
                    </div>

                    <p class="mt-3 text-[0.8125rem] leading-relaxed text-umber">
                        @if ($menu->recipeItems->isNotEmpty())
                            <span class="font-semibold text-ink">1 porsi habis</span> {{ $kalimatResep($menu) }}
                        @else
                            Belum ada resep — stoknya masih dihitung sebagai barang jadi.
                        @endif
                    </p>

                    @if ($modal !== null)
                        <p class="mt-1.5 text-[0.75rem] text-umber-soft">
                            @isset($modal['nilai'])
                                Modal ± {{ $rupiah($modal['nilai']) }} · sisa
                                {{ $rupiah(max(0, (float) $menu->harga_default - $modal['nilai'])) }}
                            @else
                                Modal belum bisa dihitung: harga beli {{ $modal['kurang'] }} belum ada.
                            @endisset
                        </p>
                    @endif

                    <button type="button" wire:click="atur('{{ $menu->id }}')"
                            class="tombol-kedua mt-3 h-11 w-full cursor-pointer text-[0.8125rem]">
                        {{ $menu->recipeItems->isNotEmpty() ? 'Ubah resep' : 'Atur resep' }}
                    </button>
                </div>
            @endforeach
        </div>

        <div class="mt-5 hidden overflow-hidden rounded-2xl border border-line bg-white lg:block">
            <table class="w-full table-fixed text-center">
                <thead class="bg-cream/60 text-[0.6875rem] font-bold tracking-wide text-umber-soft uppercase">
                    <tr>
                        <th class="w-[26%] px-5 py-3">Menu</th>
                        <th class="w-[38%] px-5 py-3">1 porsi habis</th>
                        <th class="w-[18%] px-5 py-3">Modal per porsi</th>
                        <th class="w-[18%] px-5 py-3">Resep</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line-soft">
                    @foreach ($daftar as $menu)
                        @php($modal = $modalPorsi($menu))
                        <tr class="align-middle">
                            <td class="px-5 py-3.5">
                                <p class="truncate text-[0.875rem] font-semibold text-ink">{{ $menu->nama_produk }}</p>
                                <p class="text-[0.75rem] text-umber-soft">{{ $rupiah($menu->harga_default) }}</p>
                            </td>
                            <td class="px-5 py-3.5 text-[0.8125rem] break-words text-umber">
                                {{ $menu->recipeItems->isNotEmpty() ? $kalimatResep($menu) : '—' }}
                            </td>
                            <td class="tabular px-5 py-3.5 text-[0.8125rem]">
                                @if ($modal === null)
                                    <span class="text-umber-soft">—</span>
                                @elseif (isset($modal['nilai']))
                                    <span class="font-semibold text-ink">{{ $rupiah($modal['nilai']) }}</span>
                                @else
                                    <span class="text-umber-soft">belum bisa dihitung</span>
                                @endif
                            </td>
                            <td class="px-5 py-3.5">
                                <button type="button" wire:click="atur('{{ $menu->id }}')"
                                        class="tombol-kedua h-9 cursor-pointer px-4 text-[0.8125rem]">
                                    {{ $menu->recipeItems->isNotEmpty() ? 'Ubah' : 'Atur' }}
                                </button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-4">{{ $daftar->links() }}</div>
    @endif

    {{-- ── Panel resep ───────────────────────────────────────────────────── --}}
    @if ($panel)
        <div class="fixed inset-0 z-40 flex items-end justify-center bg-ink/40 p-0 sm:items-center sm:p-6"
             role="dialog" aria-modal="true" aria-label="Resep {{ $namaMenu }}">
            <div class="kartu max-h-[92vh] w-full max-w-2xl overflow-y-auto rounded-t-2xl bg-white p-5 sm:rounded-2xl sm:p-6">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h2 class="truncate text-[1.0625rem] font-bold text-ink">Resep {{ $namaMenu }}</h2>
                        <p class="mt-0.5 text-[0.8125rem] text-umber">Sebutkan bahan yang habis untuk <strong>satu porsi</strong>.</p>
                    </div>
                    <button type="button" wire:click="tutupPanel" aria-label="Tutup"
                            class="tombol-ikon size-9 shrink-0 cursor-pointer">
                        <svg viewBox="0 0 20 20" class="size-4" fill="none" aria-hidden="true">
                            <path d="m5 5 10 10M15 5 5 15" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
                        </svg>
                    </button>
                </div>

                {{-- Peringatan giliran pertama. Ditampilkan SEBELUM tombol simpan, dan
                     menyebut angka & cabangnya: "cara hitungnya berubah" tanpa angka tidak
                     memberi tahu apa yang akan hilang dari layar Stok besok. --}}
                @if ($stokMenggantung !== [])
                    <div class="mt-4 rounded-xl border border-merah/25 bg-merah/5 p-4">
                        <p class="text-[0.8125rem] font-semibold text-merah-tua">Menu ini masih punya sisa tercatat</p>
                        <p class="mt-1 text-[0.8125rem] leading-relaxed text-umber">
                            {{ implode(', ', $stokMenggantung) }}. Setelah resepnya diisi, angka itu
                            berhenti dihitung — yang dihitung bahannya. Hitung stok dulu kalau angkanya
                            mau dinolkan.
                        </p>
                    </div>
                @endif

                @if ($bahanTersedia->isEmpty())
                    <div class="mt-4 rounded-xl border border-line bg-cream/50 p-4 text-[0.8125rem] text-umber">
                        Belum ada bahan mentah sama sekali. Daftarkan bahannya dulu — mis. Lele Segar
                        per kg — lalu resep ini bisa diisi.
                    </div>
                @else
                    <div class="mt-4 grid gap-3">
                        @foreach ($baris as $i => $b)
                            @php($bahanTerpilih = $bahanTersedia->firstWhere('id', $b['bahan'] ?? ''))
                            <div class="rounded-xl border border-line p-3" wire:key="baris-{{ $i }}">
                                <div class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_9rem_auto] sm:items-end">
                                    <div class="min-w-0">
                                        <label for="bahan-{{ $i }}" class="block text-[0.8125rem] font-semibold text-ink">
                                            Bahan<x-wajib />
                                        </label>
                                        <select id="bahan-{{ $i }}" wire:model="baris.{{ $i }}.bahan"
                                                class="mt-1.5 h-11 w-full rounded-xl border border-line bg-white px-3 text-[0.875rem] text-ink focus:border-terracotta focus:outline-none">
                                            <option value="">Pilih bahan…</option>
                                            @foreach ($bahanTersedia as $bahan)
                                                <option value="{{ $bahan->id }}">{{ $bahan->nama }} ({{ $bahan->satuan?->value }})</option>
                                            @endforeach
                                        </select>
                                    </div>

                                    <div class="min-w-0">
                                        <label for="jumlah-{{ $i }}" class="block text-[0.8125rem] font-semibold text-ink">
                                            Per porsi<x-wajib />
                                        </label>
                                        <div class="relative mt-1.5">
                                            {{-- inputmode decimal, TANPA masker uang: kolom ini
                                                 pecahan dan tidak boleh bertitik ribuan. --}}
                                            <input id="jumlah-{{ $i }}" type="text" inputmode="decimal" autocomplete="off"
                                                   wire:model="baris.{{ $i }}.jumlah" placeholder="0,25"
                                                   class="tabular h-11 w-full rounded-xl border border-line bg-white py-0 pr-14 pl-3 text-[0.875rem] text-ink placeholder:text-umber-soft/70 focus:border-terracotta focus:outline-none">
                                            <span class="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-[0.8125rem] font-semibold text-umber-soft">
                                                {{ $bahanTerpilih?->satuan?->value ?? '—' }}
                                            </span>
                                        </div>
                                    </div>

                                    {{-- Seukuran isinya dan rata kanan, TIDAK selebar layar.
                                         Terukur di 390px: sebagai blok merah selebar penuh ia
                                         jadi elemen paling dominan di panel — lebih menonjol
                                         daripada "Simpan resep" — padahal barisnya sering
                                         masih kosong dan tidak ada yang hilang. Merah harus
                                         TERLIHAT, bukan berteriak paling keras; kalau ia
                                         mengalahkan tindakan utama, orang berhenti membaca
                                         merah sebagai peringatan. --}}
                                    <div class="flex justify-end">
                                        <button type="button" wire:click="buangBaris({{ $i }})"
                                                aria-label="Buang baris {{ $bahanTerpilih?->nama ?? 'kosong' }}"
                                                class="tombol-bahaya h-11 cursor-pointer px-5 text-[0.8125rem]">
                                            Buang
                                        </button>
                                    </div>
                                </div>

                                @error('baris.'.$i.'.bahan')
                                    <p class="mt-2 text-[0.75rem] font-semibold text-merah-tua">{{ $message }}</p>
                                @enderror
                                @error('baris.'.$i.'.jumlah')
                                    <p class="mt-2 text-[0.75rem] font-semibold text-merah-tua">{{ $message }}</p>
                                @enderror

                                {{-- Gema pengaman salah desimal 1000×.
                                     Yang mengetik "25" alih-alih "0,25" melihat "10 porsi =
                                     250 kg" dan tahu itu mustahil — jauh lebih murah dan
                                     lebih jujur daripada aturan yang menebak maksudnya. --}}
                                @if ($bahanTerpilih !== null && trim((string) ($b['jumlah'] ?? '')) !== '')
                                    {{-- Lewat komponen, BUKAN Uang langsung: Uang::bacaJumlah melempar
                                         untuk bentuk yang menebak ("1.500"), dan gema ini dihitung pada
                                         SETIAP ketikan — jadi layarnya akan jatuh tepat saat orang sedang
                                         salah ketik, yaitu saat ia paling butuh membaca pesannya. --}}
                                    @php($jml = $this->jumlahAman($b['jumlah']))
                                    @if ($jml !== null && $jml > 0)
                                        <p class="mt-2 text-[0.75rem] text-umber-soft">
                                            1 porsi = {{ $angka($jml) }} {{ $bahanTerpilih->satuan?->value }} ·
                                            <strong class="text-umber">10 porsi = {{ $angka($jml * 10) }} {{ $bahanTerpilih->satuan?->value }}</strong>
                                        </p>
                                    @endif
                                @endif
                            </div>
                        @endforeach
                    </div>

                    <button type="button" wire:click="tambahBaris"
                            class="tombol-kedua mt-3 h-11 w-full cursor-pointer text-[0.8125rem]">
                        + Tambah bahan
                    </button>
                @endif

                <div class="mt-5 flex flex-col gap-2 border-t border-line-soft pt-4 sm:flex-row sm:justify-end">
                    <button type="button" wire:click="tutupPanel"
                            class="h-11 w-full cursor-pointer rounded-xl border border-line px-5 text-[0.875rem] font-semibold text-ink transition-colors hover:bg-cream sm:w-auto">
                        Tidak jadi
                    </button>
                    <button type="button" wire:click="simpan"
                            class="tombol-utama h-11 w-full cursor-pointer px-5 text-[0.875rem] sm:w-auto">
                        Simpan resep
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
