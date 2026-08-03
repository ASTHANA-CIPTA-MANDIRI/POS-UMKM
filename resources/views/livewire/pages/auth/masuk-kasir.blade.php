{{--
    Nomor seri perangkat diambil dari localStorage dan dikirim bersama form.
    Nilai ini BUKAN bukti identitas — server tetap memverifikasi perangkat itu
    terhadap outlet milik user. Fungsinya hanya menghindari kasir mengetik nomor
    seri setiap kali login.
--}}
<div
    x-data="{
        serial: localStorage.getItem('nampan.perangkat') ?? '',
        init() { $wire.serialPerangkat = this.serial },
        simpan() {
            if (this.serial.trim() !== '') {
                localStorage.setItem('nampan.perangkat', this.serial.trim())
            }
            $wire.serialPerangkat = this.serial.trim()
        },
    }"
>
    <h1 class="text-[2rem] leading-tight font-bold text-ink">Masuk kasir</h1>
    <p class="mt-2 text-[0.9375rem] text-umber">
        Pakai username dan PIN yang diberikan pemilik usaha.
    </p>

    <form wire:submit="masuk" class="mt-8 space-y-5">
        <div>
            <label for="username" class="ml-1.5 block text-[0.875rem] font-medium text-ink">
                Username <span class="text-terracotta">*</span>
            </label>
            <input
                id="username"
                type="text"
                autocomplete="username"
                autocapitalize="none"
                required
                wire:model="username"
                placeholder="ani.pusat"
                class="mt-2 block h-12 w-full rounded-xl border border-line bg-transparent p-3 text-[0.875rem] text-ink transition-colors placeholder:text-umber-soft focus:border-terracotta-soft focus:outline-none"
            >
            @error('username')
                <p class="mt-2 text-[0.8125rem] text-terracotta" role="alert">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="pin" class="ml-1.5 block text-[0.875rem] font-medium text-ink">
                PIN <span class="text-terracotta">*</span>
            </label>
            <input
                id="pin"
                type="password"
                {{-- inputmode numeric memunculkan papan angka di HP, bukan huruf. --}}
                inputmode="numeric"
                autocomplete="current-password"
                required
                wire:model="pin"
                placeholder="••••••"
                class="tabular mt-2 block min-h-12 w-full rounded-2xl border border-line bg-paper px-5 text-center text-lg tracking-[0.4em] text-ink transition-colors placeholder:tracking-normal focus:border-terracotta-soft"
            >
            @error('pin')
                <p class="mt-2 text-[0.8125rem] text-terracotta" role="alert">{{ $message }}</p>
            @enderror
        </div>

        <div class="rounded-2xl bg-cream-deep/50 px-5 py-4">
            <label for="serial" class="ml-1.5 block text-[0.875rem] font-medium text-ink">Nomor seri perangkat</label>
            <p class="mt-1 text-[0.75rem] text-umber">
                Cukup diisi sekali di perangkat ini. Nomor serinya ada di stiker perangkat,
                atau bisa diminta dari pemilik usaha.
            </p>
            <input
                id="serial"
                type="text"
                x-model="serial"
                @blur="simpan()"
                placeholder="TAB-BJM-0001"
                class="mt-3 block min-h-12 w-full rounded-2xl border border-line bg-paper px-5 font-mono text-[0.875rem] text-ink transition-colors placeholder:text-umber-soft focus:border-terracotta-soft"
            >
        </div>

        <button
            type="submit"
            data-tap
            @click="simpan()"
            wire:loading.attr="disabled"
            class="inline-flex w-full cursor-pointer items-center justify-center rounded-xl bg-terracotta py-3 text-[1rem] font-medium text-cream transition-colors hover:bg-terracotta-deep disabled:opacity-60"
        >
            <span wire:loading.remove wire:target="masuk">Mulai shift</span>
            <span wire:loading wire:target="masuk">Memeriksa…</span>
        </button>
    </form>

    <div class="mt-7 rounded-2xl bg-cream-deep/50 px-5 py-4">
        <p class="text-[0.8125rem] text-umber">
            Pemilik usaha?
            <a href="{{ route('masuk') }}" wire:navigate class="font-bold text-terracotta transition-colors hover:text-terracotta-deep">
                Masuk lewat halaman pemilik
            </a>
        </p>
    </div>
</div>
