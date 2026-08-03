@extends('layouts.publik')

@section('judul', 'Harga')
@section('deskripsi', 'Paket kasir mulai Rp 39.000 per bulan. Branding sendiri di struk tersedia di semua paket. Tanpa biaya setup, tanpa kontrak tahunan, berhenti kapan saja.')

{{-- Penutup bawaan dimatikan: halaman ini sudah punya CTA di tiap kartu paket. --}}
@section('tanpa_cta', 'ya')

@section('konten')
    @include('partials.landing.page-header', [
        'eyebrow' => '04 — Harga',
        'judul' => 'Bayar bulanan.<br class="hidden sm:block"> Berhenti kapan saja.',
        'intro' => 'Tanpa biaya setup dan tanpa kontrak tahunan. Pakai perangkat sendiri, atau sewa tablet dan printer sekalian. Data tetap milik Anda dan bisa diekspor kalau berhenti berlangganan.',
    ])

    <section class="relative grain py-16 sm:py-20">
        <div class="relative mx-auto max-w-7xl px-5 sm:px-6 lg:px-8">
            {{-- Branding struk dinaikkan jadi pembeda utama karena berlaku di semua paket,
                 sementara pesaing umumnya menahan fitur ini di paket tertinggi. --}}
            <div class="flex flex-col gap-4 rounded-2xl border border-terracotta-soft/30 bg-gradient-to-br from-cream-deep to-cream p-5 sm:flex-row sm:items-center sm:justify-between sm:p-6" data-reveal>
                <div class="flex items-start gap-4">
                    <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-gradient-to-br from-terracotta-soft to-terracotta-deep">
                        <svg viewBox="0 0 20 20" class="size-5 text-cream" fill="none" aria-hidden="true">
                            <path d="M5 3.5h10v13l-2.5-1.6L10 16.5l-2.5-1.6L5 16.5v-13Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                            <path d="M8 7h4M8 10h4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" />
                        </svg>
                    </span>
                    <div>
                        <h2 class="text-[1.0625rem] font-semibold text-ink">Branding sendiri di struk — di semua paket</h2>
                        <p class="mt-1 text-[0.875rem] text-umber">
                            Nama, logo, dan catatan promo warung Anda yang tercetak di struk. Bukan nama kami.
                            Termasuk sejak paket paling murah, tidak perlu naik paket.
                        </p>
                    </div>
                </div>
            </div>

            <div class="mt-10 grid items-start gap-5 lg:grid-cols-3">
                @foreach ([
                    [
                        'nama' => 'Basic',
                        'harga' => '39',
                        'ringkas' => 'Satu warung, satu kasir utama',
                        'catatan' => 'Pakai perangkat sendiri (HP/tablet & printer)',
                        'unggulan' => false,
                        'fitur' => [
                            '1 outlet, 2 akun kasir',
                            '5.000 transaksi / bulan',
                            'Tiga mode jualan: langsung, buka bill, titip-ambil',
                            'Kasbon pelanggan + rekap utang',
                            'Stok dasar + alert stok menipis',
                            'Mode offline',
                            '<strong class="font-semibold">Branding sendiri di struk</strong>',
                            'Tutup kasir harian & laporan penjualan',
                            'Dukungan lewat email',
                        ],
                    ],
                    [
                        'nama' => 'Pro',
                        'harga' => '119',
                        'ringkas' => 'Sudah punya cabang atau dapur sendiri',
                        'catatan' => 'Opsi sewa tablet + printer: +Rp 129rb/bln',
                        'unggulan' => true,
                        'fitur' => [
                            'Sampai 3 outlet, 5 akun kasir',
                            'Transaksi tanpa batas',
                            'Semua fitur Basic',
                            'Resep / BOM — stok bahan baku otomatis',
                            'Transfer stok antar outlet',
                            'Purchase order &amp; data supplier',
                            'Laporan lengkap + ekspor Excel &amp; PDF',
                            '<strong class="font-semibold">Branding sendiri di struk</strong>',
                            'Dukungan email + chat',
                        ],
                    ],
                    [
                        'nama' => 'Enterprise',
                        'harga' => '279',
                        'ringkas' => 'Banyak cabang, butuh kendali penuh',
                        'catatan' => 'Outlet tanpa batas — bukan per outlet',
                        'unggulan' => false,
                        'fitur' => [
                            'Outlet &amp; akun tanpa batas',
                            'Semua fitur Pro',
                            'Perkiraan kebutuhan stok',
                            'Akses API &amp; webhook',
                            '<strong class="font-semibold">Branding struk + white-label aplikasi</strong>',
                            'Integrasi pesan-antar (GoFood, GrabFood, ShopeeFood)',
                            'Manajemen aset perangkat + remote lock',
                            'Dukungan khusus',
                        ],
                    ],
                ] as $paket)
                    <article data-tilt-in class="relative flex h-full flex-col rounded-2xl border p-6 sm:p-7 {{ $paket['unggulan'] ? 'border-terracotta-soft/45 bg-paper lift' : 'border-line-soft bg-paper/70' }}">
                        @if ($paket['unggulan'])
                            <span class="absolute -top-3 left-6 rounded-full bg-gradient-to-br from-terracotta-soft to-terracotta-deep px-3 py-1 font-mono text-[0.5625rem] tracking-[0.14em] text-cream uppercase">
                                Paling dipakai
                            </span>
                        @endif

                        <h3 class="text-lg font-semibold text-ink">{{ $paket['nama'] }}</h3>
                        <p class="mt-1 text-[0.8125rem] text-umber">{{ $paket['ringkas'] }}</p>

                        <p class="mt-5 flex items-baseline gap-1.5">
                            <span class="text-sm text-umber">Rp</span>
                            <span class="tabular font-display text-[2.75rem] leading-none font-semibold text-ink">{{ $paket['harga'] }}</span>
                            <span class="text-sm text-umber">rb / bln</span>
                        </p>
                        <p class="mt-2 text-[0.8125rem] text-umber-soft">{{ $paket['catatan'] }}</p>

                        <ul class="mt-6 flex-1 space-y-2.5 rule pt-5">
                            @foreach ($paket['fitur'] as $fitur)
                                <li class="flex items-start gap-2.5 text-[0.875rem] text-ink">
                                    <svg viewBox="0 0 16 16" class="mt-1 size-3.5 shrink-0 {{ $paket['unggulan'] ? 'text-terracotta' : 'text-olive-soft' }}" fill="none" aria-hidden="true">
                                        <path d="m3 8.4 3.2 3.2L13 5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
                                    </svg>
                                    <span>{!! $fitur !!}</span>
                                </li>
                            @endforeach
                        </ul>

                        <a
                            href="#"
                            data-tap
                            class="mt-7 inline-flex min-h-12 items-center justify-center rounded-full px-6 text-[0.9375rem] font-semibold transition-colors {{ $paket['unggulan'] ? 'bg-gradient-to-br from-terracotta-soft to-terracotta-deep text-cream shadow-[0_10px_24px_-10px_rgb(66_42_251/0.6)]' : 'border border-line bg-paper text-ink hover:border-terracotta-soft/50' }}"
                        >
                            {{ $paket['unggulan'] ? 'Mulai 14 hari gratis' : 'Pilih ' . $paket['nama'] }}
                        </a>
                    </article>
                @endforeach
            </div>

            <p class="mt-8 max-w-3xl text-[0.8125rem] text-umber-soft" data-reveal>
                Semua paket termasuk cetak struk thermal, buku kasbon, tutup kasir harian, dan mode offline.
                Tidak ada biaya setup dan tidak perlu kartu kredit untuk mencoba.
                Bayar tahunan hemat dua bulan.
            </p>
        </div>
    </section>

    {{-- Pembanding pasar. Sengaja tanpa menyebut nama vendor: klaim pembanding
         bermerek butuh bukti terverifikasi dan berisiko secara hukum. --}}
    <section class="relative grain border-t border-line-soft bg-cream-deep/40 py-16 sm:py-20">
        {{-- Lebar wadah disamakan dengan section lain (max-w-7xl) supaya tepi kiri
             seluruh halaman sejajar; pembatasan lebar teks dilakukan di dalamnya. --}}
        <div class="relative mx-auto max-w-7xl px-5 sm:px-6 lg:px-8">
            <div class="max-w-2xl" data-reveal>
                <p class="eyebrow text-terracotta">Pembanding</p>
                <h2 class="mt-5 text-[1.75rem] font-semibold text-balance text-ink sm:text-[2rem]">
                    Kenapa bisa lebih murah.
                </h2>
                <p class="mt-5 text-[1.0625rem] text-pretty text-umber">
                    Kami tidak membangun modul reservasi, layout meja visual, atau akuntansi penuh yang tidak
                    dipakai warung. Biaya pengembangan yang tidak keluar itu yang membuat harganya bisa ditekan.
                </p>
            </div>

            <div class="mt-10 max-w-4xl overflow-x-auto" data-reveal="0.1">
                <table class="w-full min-w-[34rem] border-collapse text-left">
                    <caption class="sr-only">Perbandingan harga bulanan Nampan dengan kisaran harga di pasar</caption>
                    <thead>
                        <tr class="border-b border-line">
                            <th scope="col" class="pb-3 font-mono text-[0.625rem] tracking-[0.14em] text-umber uppercase">Kebutuhan</th>
                            <th scope="col" class="pb-3 font-mono text-[0.625rem] tracking-[0.14em] text-umber uppercase">Kisaran di pasar</th>
                            <th scope="col" class="pb-3 font-mono text-[0.625rem] tracking-[0.14em] text-terracotta uppercase">Nampan</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ([
                            ['Satu warung, fitur dasar', 'Rp 42rb – 79rb / bln', 'Rp 39rb / bln'],
                            ['Multi-outlet + stok lengkap', 'Rp 129rb – 166rb / bln', 'Rp 119rb / bln'],
                            ['Tiga outlet', 'Rp 299rb per outlet', 'Rp 119rb total'],
                            ['Branding sendiri di struk', 'Umumnya paket tertinggi', 'Semua paket'],
                        ] as [$kebutuhan, $pasar, $kami])
                            <tr class="border-b border-line-soft">
                                <td class="py-4 pr-4 text-[0.9375rem] text-ink">{{ $kebutuhan }}</td>
                                <td class="tabular py-4 pr-4 text-[0.9375rem] text-umber">{{ $pasar }}</td>
                                <td class="tabular py-4 text-[0.9375rem] font-semibold text-terracotta">{{ $kami }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <p class="mt-5 max-w-3xl text-[0.75rem] text-umber-soft">
                Kisaran pasar dihimpun dari harga publik beberapa penyedia POS di Indonesia per Juli 2026,
                sebagian ditagih tahunan dan sudah dikonversi ke bulanan. Harga vendor bisa berubah kapan saja —
                silakan cek ulang sebelum memutuskan.
            </p>
        </div>
    </section>

    {{-- Pertanyaan yang paling sering muncul sebelum bayar. --}}
    <section class="relative grain py-16 sm:py-20">
        <div class="relative mx-auto max-w-7xl px-5 sm:px-6 lg:px-8">
            <h2 class="text-[1.75rem] font-semibold text-balance text-ink sm:text-[2rem]" data-reveal>
                Pertanyaan sebelum bayar.
            </h2>

            <div class="mt-8 max-w-3xl space-y-4" data-stagger>
                @foreach ([
                    ['Kalau berhenti, data saya bagaimana?', 'Data disimpan dulu selama 90 hari, tidak langsung dihapus. Selama masa itu Anda bisa mengekspornya kapan saja. Setelah lewat, baru dihapus permanen.'],
                    ['Perlu beli perangkat baru?', 'Tidak. HP atau tablet Android yang sudah ada bisa dipakai, begitu juga printer thermal yang sudah Anda punya. Sewa perangkat hanya opsi, bukan syarat.'],
                    ['Apa bedanya branding struk dengan white-label?', 'Branding struk berarti nama dan logo Anda yang tercetak di struk — ini ada di semua paket. White-label aplikasi berarti seluruh tampilan aplikasi memakai merek Anda sendiri, dan itu hanya di Enterprise.'],
                    ['Kena biaya tambahan per transaksi?', 'Tidak ada potongan per transaksi dari kami. Biaya QRIS atau e-wallet mengikuti tarif penyedia pembayaran, bukan tarif kami.'],
                ] as [$tanya, $jawab])
                    <div class="rounded-xl border border-line-soft bg-paper p-5">
                        <h3 class="text-[0.9375rem] font-semibold text-ink">{{ $tanya }}</h3>
                        <p class="mt-2 text-[0.875rem] text-umber">{{ $jawab }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>
@endsection
