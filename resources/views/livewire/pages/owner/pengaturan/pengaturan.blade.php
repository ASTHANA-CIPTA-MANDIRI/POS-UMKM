{{--
    Pengaturan warung: rumah semua angka yang berlaku untuk seluruh aplikasi.

    Data dari komponen: $targetMargin, $contoh (hasil SaranHargaAction untuk modal Rp 10.000),
    $bebanHarian.

    TIAP SETELAN DITEMANI CONTOH HIDUP, bukan cuma kotak isian. Persentase adalah bentuk yang
    paling sering salah dipahami — "30%" baru berarti begitu ia berwujud "modal Rp 10.000 jadi
    dijual Rp 14.500". Contohnya ikut berubah saat angkanya diubah, jadi orang bisa mencoba
    sampai angkanya terasa benar tanpa harus menyimpan dulu lalu memeriksa di layar lain.
--}}
@php
    $rp = fn ($nilai) => 'Rp '.number_format((float) $nilai, 0, ',', '.');
@endphp

<div>
    <x-kartu-alat
        judul="Pengaturan warung"
        keterangan="Angka-angka yang berlaku untuk seluruh warung. Semuanya juga bisa diubah langsung dari layar tempat angkanya dipakai — halaman ini supaya jelas ke mana mencarinya."
    />

    {{-- ── Target untung ─────────────────────────────────────────────────── --}}
    <div class="kartu mb-4 p-5">
        <h2 class="text-[1.0625rem] font-bold text-ink">
            <x-jelaskan kunci="untung" sebagai="Target untung" />
        </h2>
        <p class="mt-1 text-[0.9375rem] text-umber">
            Dipakai aplikasi untuk <span class="font-semibold text-ink">menyarankan harga jual</span> tiap kali
            kamu membuat atau mengubah barang. Sarannya tidak pernah mengganti harga sendiri —
            harga tetap keputusanmu.
        </p>

        <form wire:submit="simpanTargetMargin" class="mt-4 flex flex-wrap items-end gap-3">
            <div>
                <label for="target-untung" class="block text-[0.8125rem] font-semibold text-ink">
                    Dari tiap penjualan, berapa persen yang mau jadi untung?
                </label>
                {{-- Pembungkusnya HARUS selebar kotaknya (w-32), bukan selebar induknya.
                     Tanda "%" diposisikan `right-4` relatif ke pembungkus ini — kalau
                     pembungkusnya selebar penuh, tandanya melayang jauh dari kotak dan
                     terbaca sebagai tulisan lepas, bukan satuan dari angka yang diketik.
                     Terukur dari potret: tandanya berjarak 330px dari kotaknya. --}}
                <div class="relative mt-1.5 w-32">
                    <input id="target-untung" type="text" inputmode="decimal"
                           wire:model.live.debounce.500ms="targetMargin" value="{{ $targetMargin }}"
                           class="tabular h-12 w-full rounded-xl border border-line bg-white pr-9 pl-4 text-right text-[1.125rem] font-bold text-ink focus:border-terracotta focus:outline-none">
                    <span class="pointer-events-none absolute top-1/2 right-4 -translate-y-1/2 text-sm font-semibold text-umber-soft">%</span>
                </div>
            </div>

            <button type="submit" wire:loading.attr="disabled"
                    class="h-12 cursor-pointer rounded-xl bg-terracotta px-6 text-[0.9375rem] font-bold text-white transition-colors hover:bg-terracotta-deep disabled:opacity-60">
                <span wire:loading.remove wire:target="simpanTargetMargin">Simpan</span>
                <span wire:loading wire:target="simpanTargetMargin">Menyimpan…</span>
            </button>
        </form>

        @error('targetMargin')
            <p class="mt-2 text-[0.875rem] text-merah-tua">{{ $message }}</p>
        @enderror

        {{-- Contoh HIDUP: berubah saat angkanya diubah, sebelum disimpan. Orang bisa mencoba
             sampai angkanya terasa benar tanpa menyimpan dulu lalu memeriksa di layar lain —
             dan perjalanan bolak-balik itulah yang membuat orang berhenti mengubahnya. --}}
        @if ($contoh['hargaBulat'] !== null)
            <div class="mt-4 rounded-xl border border-line bg-cream/70 p-4">
                <p class="eyebrow text-umber-soft">Artinya begini</p>
                <p class="mt-1.5 text-[0.9375rem] leading-relaxed text-ink">
                    Barang yang modalnya <span class="tabular font-bold">{{ $rp(10000) }}</span>
                    akan disarankan dijual <span class="tabular font-bold">{{ $rp($contoh['hargaBulat']) }}</span>.
                    Untungnya <span class="tabular font-bold">{{ $rp($contoh['hargaBulat'] - 10000) }}</span> per barang.
                </p>
                <p class="mt-2 text-[0.8125rem] text-umber">
                    Angka ini belum dipotong sewa dan listrik.
                    @if ($bebanHarian > 0)
                        Beban warung sekarang {{ $rp($bebanHarian) }} sehari —
                    @else
                        Biaya warung belum dicatat, jadi
                    @endif
                    <a href="{{ route('owner.biaya') }}" wire:navigate
                       class="font-semibold text-terracotta-deep underline decoration-terracotta/40 underline-offset-2">lihat Biaya operasional</a>.
                </p>
            </div>
        @endif
    </div>

    {{--
        Angka perencanaan LAIN ditunjuk, bukan disalin ke sini.

        Menyalinnya berarti dua tempat mengubah hal yang sama, dan itu justru menambah
        kebingungan yang mau dihilangkan halaman ini. Yang dibutuhkan cuma satu hal: orang
        yang membuka Pengaturan harus TAHU angka itu ada dan di mana letaknya.
    --}}
    <div class="kartu p-5">
        <h2 class="text-[1.0625rem] font-bold text-ink">Angka lain yang punya layarnya sendiri</h2>
        <p class="mt-1 text-[0.9375rem] text-umber">
            Ini bukan setelan sekali isi — isinya berubah terus, jadi tempatnya di layar masing-masing.
        </p>

        <ul class="mt-4 space-y-2">
            <li>
                <a href="{{ route('owner.biaya') }}" wire:navigate
                   class="flex items-center justify-between gap-3 rounded-xl border border-line px-4 py-3.5 transition-colors hover:bg-cream">
                    <span class="min-w-0">
                        <span class="block text-[0.9375rem] font-bold text-ink">Biaya operasional</span>
                        <span class="block text-[0.8125rem] text-umber">Sewa, listrik, gas, gaji — beban warung tiap hari.</span>
                    </span>
                    <span class="tabular shrink-0 text-[0.9375rem] font-bold text-ink">{{ $rp($bebanHarian) }}<span class="text-[0.75rem] font-medium text-umber-soft">/hari</span></span>
                </a>
            </li>
            <li>
                <a href="{{ route('owner.istilah') }}" wire:navigate
                   class="flex items-center justify-between gap-3 rounded-xl border border-line px-4 py-3.5 transition-colors hover:bg-cream">
                    <span class="min-w-0">
                        <span class="block text-[0.9375rem] font-bold text-ink">Arti istilah</span>
                        <span class="block text-[0.8125rem] text-umber">Kata-kata di aplikasi ini, dijelaskan pakai bahasa sehari-hari.</span>
                    </span>
                    <svg viewBox="0 0 20 20" class="size-4 shrink-0 text-umber-soft" fill="none" aria-hidden="true">
                        <path d="m8 5 5 5-5 5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </a>
            </li>
        </ul>
    </div>
</div>
