@extends('layouts.publik')

@section('judul', 'Untuk Siapa')
@section('deskripsi', 'Nampan dibuat untuk warteg, rumah makan, toko kelontong, depot air isi ulang, dan laundry kiloan — bukan untuk resto mal atau retail formal.')

@section('konten')
    @include('partials.landing.page-header', [
        'eyebrow' => '03 — Untuk siapa',
        'judul' => 'Dibuat dari cara kerja<br class="hidden sm:block"> yang sudah ada.',
        'intro' => 'Bukan menyuruh pedagang mengubah kebiasaan supaya cocok dengan aplikasi. Empat jenis usaha ini yang jadi acuan sejak awal.',
    ])

    <section class="relative grain py-16 sm:py-20">
        <div class="relative mx-auto max-w-7xl space-y-5 px-5 sm:px-6 lg:px-8">
            @foreach ([
                [
                    'anchor' => 'warteg',
                    'nama' => 'Warteg &amp; rumah makan',
                    'masalah' => 'Pelanggan nambah lauk beberapa kali, bayarnya di akhir. Kalau dicatat di kertas, sering ada yang kelewat.',
                    'poin' => [
                        'Bill per meja tetap terbuka sampai pelanggan pulang',
                        'Grid tombol besar per lauk — kasir tinggal tap sesuai yang diambil',
                        'Stok beras, ayam, dan minyak berkurang otomatis dari resep tiap porsi',
                        'Struk dapur bisa dicetak terpisah, atau dimatikan untuk warung kecil',
                    ],
                ],
                [
                    'anchor' => 'kelontong',
                    'nama' => 'Toko kelontong',
                    'masalah' => 'Langganan sering ngutang dan catatannya di buku tulis. Susah tahu siapa yang sudah bayar.',
                    'poin' => [
                        'Kasbon tercatat per pelanggan dengan jatuh tempo dan sisa utang',
                        'Rekap utang bisa dicetak lewat printer thermal yang sudah ada',
                        'Scan barcode cepat untuk banyak item',
                        'Beli per dus, jual per pcs — konversi satuan otomatis',
                        'Alert stok menipis dan produk mendekati kadaluarsa',
                    ],
                ],
                [
                    'anchor' => 'depot-air',
                    'nama' => 'Depot air isi ulang',
                    'masalah' => 'Galon kosong dititip pelanggan, gampang tertukar dengan galon yang dijual.',
                    'poin' => [
                        'Galon kosong titipan dilacak terpisah dari stok galon isi',
                        'Isi ulang bayar langsung, atau dititip untuk diambil nanti',
                        'Pesan antar ke rumah dengan status berjalan',
                        'Bisa digabung ke tagihan langganan bulanan',
                    ],
                ],
                [
                    'anchor' => 'laundry',
                    'nama' => 'Laundry kiloan',
                    'masalah' => 'Cucian masuk hari ini, diambil tiga hari lagi, bayarnya belakangan. Perlu bukti titipan.',
                    'poin' => [
                        'Nota titipan tercetak saat terima, jadi bukti klaim pelanggan',
                        'Hitung per kg atau per item (bed cover, jas)',
                        'Status jalan dari diproses sampai siap diambil',
                        'Struk lunas tercetak terpisah saat pengambilan',
                    ],
                ],
            ] as $segmen)
                <article id="{{ $segmen['anchor'] }}" data-tilt-in class="scroll-mt-28 rounded-2xl border border-line-soft bg-paper p-6 sm:p-9">
                    <div class="grid gap-8 lg:grid-cols-[1fr_1.2fr] lg:gap-14">
                        <div>
                            <h2 class="text-[1.5rem] font-semibold text-balance text-ink sm:text-[1.875rem]">{!! $segmen['nama'] !!}</h2>
                            <p class="mt-5 border-l-2 border-terracotta-soft/40 pl-4 text-[0.9375rem] text-umber italic">
                                {{ $segmen['masalah'] }}
                            </p>
                        </div>

                        <ul class="space-y-3 lg:border-l lg:border-line-soft lg:pl-10">
                            @foreach ($segmen['poin'] as $poin)
                                <li class="flex items-start gap-3 text-[0.9375rem] text-ink">
                                    <svg viewBox="0 0 16 16" class="mt-1 size-4 shrink-0 text-olive-soft" fill="none" aria-hidden="true">
                                        <path d="m3 8.4 3.2 3.2L13 5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
                                    </svg>
                                    {{ $poin }}
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </article>
            @endforeach
        </div>
    </section>

    {{-- Menyebut siapa yang TIDAK cocok justru membangun kepercayaan. --}}
    <section class="relative grain border-t border-line-soft bg-cream-deep/40 py-16 sm:py-20">
        {{-- Wadah max-w-7xl agar tepi kiri sejajar dengan section lain. --}}
        <div class="relative mx-auto max-w-7xl px-5 sm:px-6 lg:px-8" data-reveal>
            <p class="eyebrow text-terracotta">Sejujurnya</p>
            <h2 class="mt-5 text-[1.75rem] font-semibold text-balance text-ink sm:text-[2rem]">
                Kalau usaha Anda seperti ini, cari yang lain.
            </h2>
            <p class="mt-5 max-w-3xl text-[1.0625rem] text-pretty text-umber">
                Nampan sengaja tidak mengejar resto mal dan retail formal berskala besar. Kalau Anda butuh layout
                meja visual yang rumit, manajemen reservasi, integrasi ERP, atau akuntansi penuh, POS lain lebih
                cocok — dan kami akan bilang begitu dari awal daripada Anda kecewa setelah berlangganan.
            </p>
        </div>
    </section>
@endsection
