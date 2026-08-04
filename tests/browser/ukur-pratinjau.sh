#!/usr/bin/env bash
#
# Mengukur kerapian tangkapan pratinjau — dengan penyiapan yang BENAR.
#
# Kenapa ada berkas ini dan bukan sekadar "jalankan ukur.mjs": tangkapan di
# storage/pratinjau memuat app.js & livewire.js dari APP_URL, yaitu http://localhost
# TANPA porta. Membukanya langsung lewat file:// atau dengan porta yang salah membuat
# skripnya tidak pernah sampai, Alpine tidak jalan, dan halaman yang JS-nya mati tetap
# melaporkan tujuh angka nol — lolos padahal tidak terukur sama sekali. Itu sudah
# terjadi, dan sempat dibaca sebagai "cacat tata letak" selama beberapa putaran.
#
# Jadi dua hal ini wajib, dan itulah isi skrip ini:
#   1. alamat aset ditulis ulang ke porta server yang benar
#   2. halaman disajikan dari ORIGIN YANG SAMA dengan asetnya (bukan file://),
#      supaya tidak diblokir CORS
#
# ukur.mjs sendiri sekarang menolak diam-diam: berkas penting yang gagal dimuat
# menghasilkan tanda TIDAK SAH dan kode keluar 2.
#
# Pakai:
#   php artisan serve --port=8000 &
#   Chrome headless di porta 9333 (lihat kepala ukur.mjs)
#   PRATINJAU=1 php artisan test --filter=PratinjauTest   # menghasilkan tangkapannya
#   tests/browser/ukur-pratinjau.sh owner-stok owner-opname
#
# Tanpa argumen: semua tangkapan yang ada diukur.
set -euo pipefail

PORTA="${PORTA:-8000}"
AKAR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$AKAR"

# Disajikan dari public/ supaya origin-nya sama dengan aset. Nama foldernya khusus dan
# dibersihkan di akhir; JANGAN pernah menyentuh storage/app/public/produk.
SAJI="public/pratinjau"
bersihkan() { rm -rf "$SAJI"; }
trap bersihkan EXIT

mkdir -p "$SAJI"

BERKAS=("$@")
if [ ${#BERKAS[@]} -eq 0 ]; then
    for f in storage/pratinjau/*.html; do BERKAS+=("$(basename "$f" .html)"); done
fi

for nama in "${BERKAS[@]}"; do
    asal="storage/pratinjau/${nama}.html"
    [ -f "$asal" ] || { echo "tidak ada: $asal — jalankan PRATINJAU=1 php artisan test --filter=PratinjauTest"; exit 1; }
    sed "s|http://localhost/|http://localhost:${PORTA}/|g" "$asal" > "${SAJI}/${nama}.html"
done

# 390 = ponsel kasir, 768 = tablet, 1280 = laptop pemilik. Tingginya sengaja pendek:
# panel yang menggulir hanya terlihat di layar yang benar-benar pendek.
GAGAL=0

for nama in "${BERKAS[@]}"; do
    for pasangan in "390 844" "768 1024" "1280 800"; do
        set -- $pasangan
        lebar="$1"; tinggi="$2"
        keluaran=$(node tests/browser/ukur.mjs \
            "http://localhost:${PORTA}/pratinjau/${nama}.html" \
            "$lebar" "/tmp/ukur-${nama}-${lebar}.png" \
            tests/browser/periksa-rapi.js "$tinggi" 2>&1) || GAGAL=1
        printf '%-24s %-5s %s\n' "$nama" "$lebar" "$(echo "$keluaran" | grep -E 'px \|' | head -1)"
        echo "$keluaran" | grep -E '^(GAGAL MUAT|TIDAK SAH:|  )' || true
    done
done

[ "$GAGAL" -eq 0 ] || { echo "ADA PENGUKURAN TIDAK SAH — angkanya jangan dipakai."; exit 2; }
