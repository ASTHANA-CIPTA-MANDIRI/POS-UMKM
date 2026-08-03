{{-- Layout memberi lebar penuh tanpa padding saat $penuh, jadi jaraknya diatur
     di sini. max-w-[120rem] menahan agar di monitor sangat lebar barisnya tidak
     memanjang sampai sulit dibaca. --}}
<div class="mx-auto max-w-[120rem] space-y-4 p-4 sm:space-y-5 sm:p-6">
    {{-- Status shift paling atas: hal pertama yang perlu dipastikan kasir sebelum
         mulai melayani adalah laci kasnya sudah dibuka. --}}
    @if ($sesi)
        <div class="kartu flex flex-wrap items-center justify-between gap-4 p-5 sm:p-6">
            <div class="flex items-center gap-4">
                <span class="lencana-ikon bg-hijau/12 text-hijau-tua">
                    <svg viewBox="0 0 24 24" class="size-6" fill="none" aria-hidden="true">
                        <path d="M12 8v4l3 2m6-2a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" stroke="currentColor"
                              stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </span>
                <div>
                    <p class="text-[0.875rem] font-medium text-umber">Shift berjalan</p>
                    <p class="text-[1.0625rem] font-bold text-ink">
                        Sejak {{ $sesi->dibuka_pada->translatedFormat('H:i') }}
                        <span class="text-[0.875rem] font-medium text-umber-soft">
                            &middot; {{ $sesi->dibuka_pada->locale('id')->diffForHumans(null, true) }}
                        </span>
                    </p>
                </div>
            </div>

            {{-- Dua angka yang dipakai kasir saat menutup shift, berdampingan supaya
                 selisihnya terlihat tanpa berpindah halaman. --}}
            <dl class="flex flex-wrap items-start gap-6">
                <div>
                    <dt class="text-[0.75rem] text-umber-soft">Modal awal</dt>
                    <dd class="tabular text-[1rem] font-bold text-ink">
                        Rp {{ number_format((float) $sesi->modal_awal, 0, ',', '.') }}
                    </dd>
                    {{-- Salah hitung itu kejadian nyata, jadi harus ada jalan keluarnya.
                         Yang tidak boleh: menimpanya tanpa jejak — angka ini pembanding
                         yang menahan selisih akhir shift. --}}
                    <button type="button" wire:click="bukaKoreksi"
                            class="mt-0.5 cursor-pointer text-[0.75rem] font-semibold text-terracotta transition-colors hover:text-terracotta-deep">
                        Koreksi
                    </button>
                </div>
                <div>
                    <dt class="text-[0.75rem] text-umber-soft">Kas di laci (sistem)</dt>
                    <dd class="tabular text-[1rem] font-bold text-terracotta">
                        Rp {{ number_format($kasSistem, 0, ',', '.') }}
                    </dd>
                </div>
            </dl>
        </div>

        {{-- ── Panel koreksi modal awal ─────────────────────────────────────── --}}
        @if ($panelKoreksi)
            <div class="kartu border border-terracotta/30 p-5 sm:p-6" x-data="{ nilai: null }">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h2 class="text-[1.0625rem] font-bold text-ink">Koreksi modal awal</h2>
                        <p class="mt-1 max-w-xl text-[0.875rem] text-umber">
                            Hitung ulang uang di laci saat shift dibuka, lalu tulis jumlah yang
                            benar. Angka lama tidak dihapus — perubahannya dicatat beserta
                            alasan dan nama Anda.
                        </p>
                    </div>
                    <button type="button" wire:click="tutupKoreksi" aria-label="Tutup"
                            class="grid size-9 shrink-0 cursor-pointer place-items-center rounded-lg text-umber transition hover:bg-cream">
                        <svg viewBox="0 0 20 20" class="size-4" fill="none" aria-hidden="true">
                            <path d="M5 5l10 10M15 5L5 15" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
                        </svg>
                    </button>
                </div>

                <div class="mt-4 grid gap-4 sm:grid-cols-[14rem_1fr]">
                    <div>
                        <label for="modal-koreksi" class="block text-[0.8125rem] font-semibold text-ink">
                            Jumlah yang benar <span class="text-merah">*</span>
                        </label>
                        <div class="relative mt-1.5">
                            <span class="pointer-events-none absolute top-1/2 left-4 -translate-y-1/2 text-sm font-semibold text-umber-soft">Rp</span>
                            <input
                                id="modal-koreksi"
                                type="text"
                                inputmode="numeric"
                                placeholder="0"
                                :value="nilai === null ? '' : new Intl.NumberFormat('id-ID').format(nilai)"
                                @input="
                                    const digit = String($event.target.value).replace(/\D/g, '');
                                    nilai = digit === '' ? null : Number(digit);
                                    $event.target.value = nilai === null ? '' : new Intl.NumberFormat('id-ID').format(nilai);
                                    $wire.set('modalKoreksi', nilai, false);
                                "
                                class="tabular h-12 w-full rounded-xl border border-line bg-white pr-4 pl-12 text-right text-lg font-semibold text-ink placeholder:text-umber-soft/60 focus:border-terracotta focus:outline-none"
                            >
                        </div>
                    </div>

                    <div>
                        <label for="alasan-koreksi" class="block text-[0.8125rem] font-semibold text-ink">
                            Alasan <span class="text-merah">*</span>
                        </label>
                        {{-- Alasan diwajibkan: koreksi uang tanpa keterangan tidak bisa
                             ditelusuri siapa pun, termasuk oleh kasir itu sendiri nanti. --}}
                        <input
                            id="alasan-koreksi"
                            type="text"
                            wire:model="alasanKoreksi"
                            placeholder="mis. salah hitung, ada uang di amplop terpisah"
                            class="mt-1.5 h-12 w-full rounded-xl border border-line bg-white px-4 text-[0.9375rem] text-ink placeholder:text-umber-soft/70 focus:border-terracotta focus:outline-none"
                        >
                    </div>
                </div>

                @if ($galatKoreksi)
                    <p class="mt-3 text-[0.8125rem] text-merah-deep" role="alert">{{ $galatKoreksi }}</p>
                @endif

                <div class="mt-4 flex flex-wrap gap-2">
                    <button type="button" wire:click="simpanKoreksi" wire:loading.attr="disabled"
                            :disabled="nilai === null"
                            class="h-12 cursor-pointer rounded-xl bg-terracotta px-6 text-[0.9375rem] font-bold text-white transition-colors hover:bg-terracotta-deep disabled:cursor-not-allowed disabled:opacity-40">
                        <span wire:loading.remove wire:target="simpanKoreksi">Simpan koreksi</span>
                        <span wire:loading wire:target="simpanKoreksi">Menyimpan…</span>
                    </button>
                    <button type="button" wire:click="tutupKoreksi"
                            class="h-12 cursor-pointer rounded-xl border border-line px-5 text-[0.9375rem] font-semibold text-ink transition-colors hover:bg-cream">
                        Batal
                    </button>
                </div>
            </div>
        @endif

        {{-- Riwayat koreksi selalu terlihat, tidak hanya saat panelnya terbuka:
             koreksi yang tersembunyi sama saja dengan tidak tercatat. --}}
        @if ($riwayatKoreksi->isNotEmpty())
            <div class="rounded-[20px] border border-jingga/35 bg-jingga/8 p-4 sm:p-5">
                <p class="text-[0.8125rem] font-bold text-jingga-tua">
                    Modal awal shift ini pernah dikoreksi {{ $riwayatKoreksi->count() }}&times;
                </p>
                <ul class="mt-2 space-y-1.5">
                    @foreach ($riwayatKoreksi as $catatan)
                        <li class="text-[0.8125rem] text-umber">
                            <span class="tabular font-semibold text-ink">
                                Rp {{ number_format((float) ($catatan->detail_json['modal_awal_lama'] ?? 0), 0, ',', '.') }}
                                &rarr;
                                Rp {{ number_format((float) ($catatan->detail_json['modal_awal_baru'] ?? 0), 0, ',', '.') }}
                            </span>
                            &middot; {{ $catatan->detail_json['alasan'] ?? '—' }}
                            &middot; {{ $catatan->created_at->translatedFormat('H:i') }}
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    @else
        {{--
            Formulir buka kasir, bukan sekadar peringatan.
            Kasir yang membaca "catat modal awal dulu" tanpa diberi kolomnya harus
            menebak ke mana pergi; kolomnya ditaruh langsung di sini.
        --}}
        <div class="kartu p-5 sm:p-6">
            <div class="flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
                <div class="flex items-start gap-4">
                    <span class="lencana-ikon bg-jingga/15 text-jingga-tua">
                        <svg viewBox="0 0 24 24" class="size-6" fill="none" aria-hidden="true">
                            <path d="M3 8h18v10H3V8Zm0 3h18M6.5 15H10" stroke="currentColor"
                                  stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    </span>
                    <div>
                        <p class="text-[0.875rem] font-medium text-umber">Kasir belum dibuka</p>
                        <p class="text-[1.125rem] font-bold text-ink">Catat modal awal dulu</p>
                        <p class="mt-1 max-w-md text-[0.875rem] text-umber">
                            Hitung uang yang ada di laci sekarang. Angka ini yang nanti
                            dibandingkan dengan hitungan sistem saat tutup shift.
                        </p>
                    </div>
                </div>

                {{--
                    x-data membungkus kolom DAN tombol, supaya tombolnya bisa dimatikan
                    selama belum ada isian. nilai === null berarti belum diisi; 0 tidak
                    dipakai sebagai penanda karena laci kosong adalah keadaan yang sah.
                --}}
                <div class="w-full lg:w-auto" x-data="{ nilai: null }">
                    <label for="modal-awal" class="block text-[0.8125rem] font-semibold text-ink">
                        Modal awal <span class="text-merah">*</span>
                    </label>

                    <div class="mt-1.5 flex flex-col gap-2 sm:flex-row lg:items-center">
                        <div class="relative sm:w-56">
                            <span class="pointer-events-none absolute top-1/2 left-4 -translate-y-1/2 text-sm font-semibold text-umber-soft">Rp</span>
                            <input
                                id="modal-awal"
                                type="text"
                                inputmode="numeric"
                                placeholder="0"
                                :value="nilai === null ? '' : new Intl.NumberFormat('id-ID').format(nilai)"
                                @input="
                                    const digit = String($event.target.value).replace(/\D/g, '');
                                    nilai = digit === '' ? null : Number(digit);
                                    $event.target.value = nilai === null ? '' : new Intl.NumberFormat('id-ID').format(nilai);
                                    $wire.set('modalAwal', nilai, false);
                                "
                                class="tabular h-12 w-full rounded-xl border border-line bg-white pr-4 pl-12 text-right text-lg font-semibold text-ink placeholder:text-umber-soft/60 focus:border-terracotta focus:outline-none"
                            >
                        </div>

                        <button
                            type="button"
                            wire:click="bukaSesi"
                            wire:loading.attr="disabled"
                            :disabled="nilai === null"
                            class="h-12 shrink-0 cursor-pointer rounded-xl bg-terracotta px-6 text-[0.9375rem] font-bold text-white transition-colors hover:bg-terracotta-deep disabled:cursor-not-allowed disabled:opacity-40"
                        >
                            <span wire:loading.remove wire:target="bukaSesi">Buka kasir</span>
                            <span wire:loading wire:target="bukaSesi">Membuka…</span>
                        </button>
                    </div>

                    {{-- Nominal cepat: mempercepat pengetikan tanpa mengisikan angka
                         lebih dulu. Kasir tetap memilih sendiri, satu ketukan. --}}
                    <div class="mt-2 flex flex-wrap gap-1.5">
                        @foreach ([50000, 100000, 200000, 500000] as $cepat)
                            <button
                                type="button"
                                @click="nilai = {{ $cepat }}; $wire.set('modalAwal', nilai, false)"
                                class="tabular h-9 cursor-pointer rounded-lg bg-cream-deep px-2.5 text-xs font-bold text-ink transition-colors hover:bg-cream"
                            >
                                {{ number_format($cepat, 0, ',', '.') }}
                            </button>
                        @endforeach
                        <button
                            type="button"
                            x-show="nilai !== null"
                            x-cloak
                            @click="nilai = null; $wire.set('modalAwal', null, false)"
                            class="h-9 cursor-pointer rounded-lg px-2.5 text-xs font-semibold text-merah-deep transition-colors hover:bg-merah/10"
                        >
                            Hapus
                        </button>
                    </div>

                    @if ($galat)
                        <p class="mt-2 text-[0.8125rem] text-merah-deep" role="alert">{{ $galat }}</p>
                    @endif
                </div>
            </div>
        </div>
    @endif

    {{-- Kartu ringkas mengikuti pola MiniStatistics: baris mendatar, lencana bundar
         di kiri, label kecil di atas angka. --}}
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4 sm:gap-5">
        @php
            $kartuRingkas = [
                [
                    'label' => 'Transaksi',
                    'nilai' => (string) $ringkas['jumlah'],
                    'ket' => $rentangAktif === 'shift' ? 'shift ini' : 'hari ini',
                    'warna' => 'text-terracotta',
                    'ikon' => 'M4 7h16v12H4V7Zm0 4h16M8 15h3',
                ],
                [
                    'label' => 'Omzet',
                    'nilai' => 'Rp '.number_format($ringkas['omzet'], 0, ',', '.'),
                    'ket' => 'tanpa void & refund',
                    'warna' => 'text-hijau-tua',
                    'ikon' => 'M4 20V6m0 14h16M8 16V11m4 5V8m4 8v-4',
                ],
                [
                    'label' => 'Tunai diterima',
                    'nilai' => 'Rp '.number_format($ringkas['tunai'], 0, ',', '.'),
                    'ket' => 'yang masuk laci',
                    'warna' => 'text-ink',
                    'ikon' => 'M3 8h18v10H3V8Zm0 3h18M6.5 15H10',
                ],
                [
                    'label' => 'Kasbon',
                    'nilai' => 'Rp '.number_format($ringkas['belum_lunas'], 0, ',', '.'),
                    'ket' => 'belum dibayar',
                    'warna' => 'text-jingga-tua',
                    'ikon' => 'M5 4h9a3 3 0 0 1 3 3v13H8a3 3 0 0 1-3-3V4Zm3 4h6m-6 4h6',
                ],
            ];
        @endphp

        @foreach ($kartuRingkas as $kartu)
            <div class="kartu flex min-h-[5.625rem] min-w-0 items-center gap-4 pr-5 pl-[1.125rem]">
                <span class="lencana-ikon bg-cream-deep {{ $kartu['warna'] }}">
                    <svg viewBox="0 0 24 24" class="size-6" fill="none" aria-hidden="true">
                        <path d="{{ $kartu['ikon'] }}" stroke="currentColor" stroke-width="1.6"
                              stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </span>
                <div class="min-w-0">
                    <p class="truncate text-[0.875rem] font-medium text-umber">{{ $kartu['label'] }}</p>
                    <p class="tabular truncate text-[1.25rem] leading-tight font-bold text-ink">{{ $kartu['nilai'] }}</p>
                    <p class="truncate text-[0.75rem] text-umber-soft">{{ $kartu['ket'] }}</p>
                </div>
            </div>
        @endforeach
    </div>

    <div class="grid gap-4 sm:gap-5 lg:grid-cols-[1.6fr_1fr]">
        {{-- ── Riwayat transaksi ───────────────────────────────────────────── --}}
        <div class="kartu min-w-0 p-5 sm:p-6">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-[1.0625rem] font-bold text-ink">Riwayat transaksi</h2>
                    <p class="text-[0.8125rem] text-umber-soft">
                        Transaksi yang Anda layani, terbaru di atas
                    </p>
                </div>

                {{-- Rentang "shift" hanya bermakna kalau ada laci yang terbuka; saat
                     kasir tutup, pilihannya disembunyikan daripada menampilkan tombol
                     yang tidak mengubah apa pun. --}}
                @if ($sesi)
                    <div class="inline-flex items-center gap-1 rounded-full bg-cream-deep/70 p-1">
                        @foreach ([['shift', 'Shift ini'], ['hari', 'Hari ini']] as [$nilai, $teks])
                            <button
                                type="button"
                                wire:click="pilihRentang('{{ $nilai }}')"
                                aria-pressed="{{ $rentangAktif === $nilai ? 'true' : 'false' }}"
                                @class([
                                    'h-9 cursor-pointer rounded-full px-3.5 text-[0.8125rem] transition',
                                    'bg-white font-semibold text-terracotta shadow-[0_2px_8px_rgb(112_144_176/0.28)]' => $rentangAktif === $nilai,
                                    'font-medium text-umber hover:text-ink' => $rentangAktif !== $nilai,
                                ])
                            >
                                {{ $teks }}
                            </button>
                        @endforeach
                    </div>
                @endif
            </div>

            @if ($riwayat->isEmpty())
                <div class="mt-5 rounded-[20px] border border-dashed border-line px-4 py-12 text-center">
                    <p class="text-[0.9375rem] font-medium text-ink">Belum ada transaksi</p>
                    <p class="mt-1 text-[0.8125rem] text-umber">
                        Transaksi yang Anda selesaikan akan tercatat di sini.
                    </p>
                </div>
            @else
                {{-- Dibuat daftar, bukan tabel: di layar sentuh sempit tabel memaksa
                     gulir mendatar, dan nomor transaksi jadi terpotong. --}}
                <ul class="mt-4 divide-y divide-line-soft">
                    @foreach ($riwayat as $trx)
                        @php
                            $dibatalkan = in_array($trx->status->value, ['void', 'refund'], true);
                            $belumLunas = $trx->status->value === 'belum_lunas';
                        @endphp
                        <li class="flex items-start justify-between gap-3 py-3.5">
                            <div class="min-w-0">
                                <p class="flex flex-wrap items-center gap-2">
                                    <span class="font-mono text-[0.8125rem] font-medium {{ $dibatalkan ? 'text-umber-soft line-through' : 'text-ink' }}">
                                        {{ $trx->nomor_transaksi }}
                                    </span>

                                    @if ($dibatalkan)
                                        <span class="rounded-full bg-merah/10 px-2 py-0.5 text-[0.6875rem] font-semibold text-merah-deep"
                                        >{{ $trx->status->label() }}</span>
                                    @elseif ($belumLunas)
                                        <span class="rounded-full bg-jingga/15 px-2 py-0.5 text-[0.6875rem] font-semibold text-jingga-tua">
                                            Kasbon
                                        </span>
                                    @endif

                                    {{-- Asal transaksi hanya ditandai kalau dibuat offline:
                                         itu keterangan yang menjelaskan kenapa waktunya bisa
                                         lebih awal dari saat datanya sampai ke server. --}}
                                    @if ($trx->origin?->value === 'offline')
                                        <span class="rounded-full bg-cream-deep px-2 py-0.5 text-[0.6875rem] font-medium text-umber">
                                            Dibuat offline
                                        </span>
                                    @endif
                                </p>

                                <p class="mt-1 truncate text-[0.75rem] text-umber">
                                    {{ $trx->waktu_transaksi->translatedFormat('H:i') }}
                                    &middot; {{ $trx->items_count }} item
                                    &middot; {{ $trx->mode->label() }}
                                    @if ($trx->payments->isNotEmpty())
                                        &middot; {{ $trx->payments->map(fn ($p) => $p->metode->label())->unique()->join(' + ') }}
                                    @endif
                                </p>
                            </div>

                            <span class="tabular shrink-0 text-[0.9375rem] font-bold {{ $dibatalkan ? 'text-umber-soft line-through' : 'text-ink' }}">
                                Rp {{ number_format((float) $trx->total, 0, ',', '.') }}
                            </span>
                        </li>
                    @endforeach
                </ul>

                @if ($riwayat->count() === 40)
                    <p class="mt-4 text-center text-[0.75rem] text-umber-soft">
                        Menampilkan 40 transaksi terakhir. Riwayat lengkap ada di laporan pemilik.
                    </p>
                @endif
            @endif
        </div>

        {{-- ── Bill terbuka ────────────────────────────────────────────────── --}}
        <div class="kartu min-w-0 p-5 sm:p-6">
            <div class="flex items-baseline justify-between gap-3">
                <h2 class="text-[1.0625rem] font-bold text-ink">Bill terbuka</h2>
                <span class="tabular rounded-full bg-cream-deep px-2.5 py-1 text-[0.75rem] font-semibold text-umber">
                    {{ $jumlahBillTerbuka }}
                </span>
            </div>

            @if ($billTerbuka->isEmpty())
                <div class="mt-5 rounded-[20px] border border-dashed border-line px-4 py-10 text-center">
                    <p class="text-[0.875rem] text-umber">Tidak ada bill yang menunggu.</p>
                </div>
            @else
                <ul class="mt-4 divide-y divide-line-soft">
                    @foreach ($billTerbuka as $bill)
                        <li class="flex items-start justify-between gap-3 py-3">
                            <div class="min-w-0">
                                <p class="truncate text-[0.9375rem] font-medium text-ink">{{ $bill->label }}</p>
                                <p class="truncate text-[0.75rem] text-umber">
                                    {{ $bill->mode->label() }} &middot; sejak {{ $bill->dibuka_pada->translatedFormat('H:i') }}
                                    @if ($bill->estimasi_selesai)
                                        &middot; selesai {{ $bill->estimasi_selesai->translatedFormat('j M H:i') }}
                                    @endif
                                </p>
                            </div>
                            <span class="shrink-0 rounded-full bg-cream-deep px-2.5 py-1 text-[0.6875rem] font-medium text-umber">
                                {{ $bill->status->label() }}
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>

    {{-- Jalan masuk utama kasir. Dibuat besar dan di paling bawah agar mudah
         dijangkau jempol di layar sentuh. --}}
    {{-- Hanya ditampilkan saat laci sudah terbuka: tanpa sesi, tombol ini akan
         membawa kasir ke gerbang yang menanyakan hal yang sama seperti formulir di
         atas — dua tempat untuk satu pekerjaan. --}}
    @if ($sesi)
        <a
            href="{{ route('kasir.transaksi') }}"
            wire:navigate
            data-tap
            class="flex min-h-16 w-full items-center justify-center gap-3 rounded-2xl bg-terracotta text-[1.0625rem] font-bold text-white transition-colors hover:bg-terracotta-deep"
        >
            <svg viewBox="0 0 24 24" class="size-5" fill="none" aria-hidden="true">
                <path d="M4 7h16v12H4V7Zm0 4h16M8 15h3" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
            Lanjut transaksi
        </a>
    @endif

    <div class="rounded-[20px] border border-dashed border-line bg-cream-deep/40 p-5 sm:p-6">
        <p class="eyebrow text-umber-soft">Belum tersedia di layar transaksi</p>
        <p class="mt-2 text-[0.875rem] text-umber">
            Diskon, varian produk, modifier, serta void &amp; refund berikut persetujuannya
            belum ada. Void yang tampil di riwayat hanya berasal dari data contoh.
        </p>
    </div>
</div>
