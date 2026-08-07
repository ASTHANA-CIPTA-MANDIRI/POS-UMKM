@php
    $sisaTrial = $tenant?->trial_ends_at ? (int) now()->diffInDays($tenant->trial_ends_at, false) : null;
    $plan = $langganan?->plan;
    $hargaBerjalan = $plan
        ? ($langganan->device_bundle && $plan->harga_bulanan_device !== null ? $plan->harga_bulanan_device : $plan->harga_bulanan)
        : null;
@endphp

<div>
    <p class="eyebrow text-terracotta">Langganan</p>
    <h1 class="mt-2 text-[1.75rem] font-bold text-ink sm:text-[2.25rem]">Paket &amp; tagihan</h1>

    <div class="mt-8 grid gap-5 lg:grid-cols-[1fr_1.3fr]">
        {{-- Status langganan --}}
        <div class="kartu p-5 sm:p-6">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <p class="eyebrow text-umber-soft">Status</p>
                    <p class="mt-2 text-[1.5rem] font-bold text-ink">{{ $tenant->status->label() }}</p>
                </div>
                @unless ($tenant->canTransact())
                    <span class="shrink-0 rounded-full bg-terracotta/12 px-2.5 py-1 font-mono text-[0.5625rem] tracking-wider text-terracotta uppercase">
                        Transaksi dihentikan
                    </span>
                @endunless
            </div>

            @if ($sisaTrial !== null && $tenant->status->value === 'trial')
                <p class="mt-3 text-[0.875rem] text-umber">
                    @if ($sisaTrial > 0)
                        Masa coba berakhir {{ $tenant->trial_ends_at->translatedFormat('j F Y') }} — tinggal
                        <span class="font-bold text-ink">{{ $sisaTrial }} hari</span>.
                    @else
                        Masa coba berakhir pada {{ $tenant->trial_ends_at->translatedFormat('j F Y') }}.
                    @endif
                </p>
            @endif

            <dl class="mt-6 space-y-3 rule pt-5">
                <div class="flex items-baseline justify-between gap-3">
                    <dt class="text-[0.8125rem] text-umber">Paket</dt>
                    <dd class="text-[0.875rem] font-medium text-ink">{{ $plan?->nama_paket ?? '—' }}</dd>
                </div>
                <div class="flex items-baseline justify-between gap-3">
                    <dt class="text-[0.8125rem] text-umber">Biaya bulanan</dt>
                    <dd class="tabular text-[0.875rem] font-medium text-ink">
                        {{ $hargaBerjalan !== null ? 'Rp '.number_format($hargaBerjalan, 0, ',', '.') : '—' }}
                    </dd>
                </div>
                <div class="flex items-baseline justify-between gap-3">
                    <dt class="text-[0.8125rem] text-umber">Sewa perangkat</dt>
                    <dd class="text-[0.875rem] font-medium text-ink">{{ $langganan?->device_bundle ? 'Ya' : 'Tidak (BYOD)' }}</dd>
                </div>
                <div class="flex items-baseline justify-between gap-3">
                    <dt class="text-[0.8125rem] text-umber">Berlaku sampai</dt>
                    <dd class="text-[0.875rem] font-medium text-ink">
                        {{ $langganan?->tanggal_berakhir?->translatedFormat('j F Y') ?? '—' }}
                    </dd>
                </div>
                @if ($plan)
                    <div class="flex items-baseline justify-between gap-3">
                        <dt class="text-[0.8125rem] text-umber">Batas outlet</dt>
                        <dd class="text-[0.875rem] font-medium text-ink">{{ $plan->limit_outlet ?? 'Tanpa batas' }}</dd>
                    </div>
                    <div class="flex items-baseline justify-between gap-3">
                        <dt class="text-[0.8125rem] text-umber">Batas akun kasir</dt>
                        <dd class="text-[0.875rem] font-medium text-ink">{{ $plan->limit_user ?? 'Tanpa batas' }}</dd>
                    </div>
                @endif
            </dl>

            <a
                href="{{ route('harga') }}"
                data-tap
                class="mt-6 inline-flex min-h-11 w-full items-center justify-center rounded-full border border-line bg-paper text-[0.875rem] font-medium text-ink transition-colors hover:border-terracotta-soft/50"
            >
                Bandingkan paket
            </a>
        </div>

        {{-- Riwayat tagihan --}}
        <div class="kartu p-5 sm:p-6">
            <h2 class="text-[1.0625rem] font-bold text-ink">Riwayat tagihan</h2>

            @if ($tagihan->isEmpty())
                <div class="mt-6 rounded-[20px] border border-dashed border-line px-4 py-10 text-center">
                    <p class="text-[0.875rem] text-umber">Belum ada tagihan.</p>
                </div>
            @else
                <div class="mt-5 overflow-x-auto">
                    <table class="w-full min-w-[30rem] border-collapse text-left">
                        <thead>
                            <tr class="border-b border-line">
                                <th scope="col" class="pb-3 font-mono text-[0.625rem] tracking-[0.12em] text-umber uppercase">Periode</th>
                                <th scope="col" class="pb-3 font-mono text-[0.625rem] tracking-[0.12em] text-umber uppercase">Jatuh tempo</th>
                                <th scope="col" class="pb-3 text-right font-mono text-[0.625rem] tracking-[0.12em] text-umber uppercase">Jumlah</th>
                                <th scope="col" class="pb-3 text-right font-mono text-[0.625rem] tracking-[0.12em] text-umber uppercase">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($tagihan as $invoice)
                                @php
                                    $warna = match ($invoice->status->value) {
                                        'lunas' => 'bg-olive/12 text-olive',
                                        'telat' => 'bg-terracotta/12 text-terracotta',
                                        default => 'bg-amber-glow/20 text-amber-warm',
                                    };
                                @endphp
                                <tr class="border-b border-line-soft">
                                    <td class="py-3.5 pr-3 text-[0.875rem] text-ink">
                                        {{ $invoice->periode_mulai->translatedFormat('M Y') }}
                                    </td>
                                    <td class="py-3.5 pr-3 text-[0.8125rem] text-umber">
                                        {{ $invoice->jatuh_tempo->translatedFormat('j M Y') }}
                                        @if ($invoice->isOverdue())
                                            <span class="ml-1 text-terracotta">&middot; lewat</span>
                                        @endif
                                    </td>
                                    <td class="tabular py-3.5 pr-3 text-right text-[0.875rem] text-ink">
                                        Rp {{ number_format($invoice->jumlah_tagihan, 0, ',', '.') }}
                                    </td>
                                    <td class="py-3.5 text-right">
                                        <span class="rounded-full px-2.5 py-1 font-mono text-[0.5625rem] tracking-wider uppercase {{ $warna }}">
                                            {{ $invoice->status->label() }}
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            <p class="mt-5 text-[0.75rem] text-umber-soft">
                Pembayaran otomatis lewat payment gateway dan unggah bukti transfer belum tersedia
                di versi ini. Sementara ini pembayaran dicatat manual oleh pengelola platform.
            </p>
        </div>
    </div>
</div>
