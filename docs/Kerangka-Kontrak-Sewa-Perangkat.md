# Kerangka Kontrak Sewa Perangkat (Paket Sistem + Device)

> **Catatan penting:** Ini kerangka poin-poin yang perlu ada, bukan dokumen hukum siap pakai. Sebelum dipakai resmi, konsultasikan ke notaris/konsultan hukum untuk memastikan bahasa dan klausulnya sah secara hukum Indonesia dan melindungi kedua pihak dengan wajar.

## 1. Identitas Para Pihak
- Nama platform (Pihak Pertama/Penyedia)
- Nama merchant/pemilik usaha (Pihak Kedua/Penyewa)
- Nomor identitas (KTP/NPWP) penyewa untuk keperluan verifikasi

## 2. Objek Sewa
- Daftar perangkat yang disewakan: jenis, merek/model, nomor seri/IMEI per unit
- Kondisi perangkat saat diserahkan (baru/refurbished), dicatat dengan foto sebagai bukti kondisi awal

## 3. Jangka Waktu & Biaya
- Durasi sewa (mengikuti periode langganan sistem — bulanan/tahunan)
- Biaya sewa perangkat (terpisah dari biaya langganan software, atau digabung — sesuaikan skema Anda)
- Mekanisme perpanjangan otomatis

## 4. Deposit/Jaminan
- Jumlah deposit per jenis perangkat (mengacu skema bertingkat yang sudah didiskusikan)
- Mekanisme pengembalian deposit saat kontrak selesai & perangkat dikembalikan dalam kondisi wajar
- Mekanisme pemotongan deposit jika terjadi kerusakan/kehilangan

## 5. Tanggung Jawab Penyewa (Merchant)
- Wajib menggunakan perangkat sesuai fungsinya (POS, tidak untuk keperluan pribadi/dijual/digadaikan)
- Wajib melaporkan kerusakan/kehilangan dalam waktu tertentu (misal maksimal 1x24 jam) sejak kejadian diketahui
- Dilarang membawa perangkat keluar dari lokasi outlet yang terdaftar tanpa izin tertulis dari penyedia
- Wajib mengembalikan perangkat dalam kondisi baik saat kontrak berakhir

## 6. Klausul Kerusakan
- Definisi "kerusakan wajar" (wear-and-tear normal pemakaian) vs "kerusakan akibat kelalaian/kesengajaan"
- Kerusakan wajar: biaya perbaikan/penggantian ditanggung penyedia (bagian dari biaya sewa)
- Kerusakan akibat kelalaian: biaya dibebankan ke penyewa, dipotong dari deposit; jika melebihi deposit, penyewa wajib membayar kekurangannya
- Proses verifikasi kerusakan (siapa yang menilai, berapa lama prosesnya)

## 7. Klausul Kehilangan atau Tidak Dikembalikan ("Dibawa Kabur")
- Kewajiban lapor segera oleh penyewa
- Hak penyedia untuk melakukan **remote lock/disable** perangkat via sistem MDM segera setelah laporan diterima atau setelah masa tunggakan pembayaran melewati batas tertentu
- Deposit otomatis menjadi milik penyedia sebagai kompensasi awal jika perangkat tidak dikembalikan dalam waktu yang disepakati (misal 14 hari sejak kontrak berakhir/diputus)
- Jika nilai kerugian melebihi deposit dan ada indikasi kesengajaan (pencurian), penyedia berhak menempuh jalur hukum (laporan polisi), dengan data log sistem (riwayat pemakaian, lokasi terakhir online untuk perangkat yang mendukung GPS) sebagai bukti pendukung

## 8. Persetujuan Pelacakan Lokasi (Klausul Privasi)
- Penjelasan bahwa sebagian perangkat (tablet, dan EDC tertentu) mencatat **lokasi terakhir saat perangkat online** untuk keperluan keamanan aset, bukan pelacakan real-time terus-menerus
- Penyewa memberikan persetujuan eksplisit atas pencatatan ini sebagai syarat pengambilan paket +device
- Data lokasi hanya digunakan untuk investigasi kehilangan/kerusakan, tidak dibagikan ke pihak ketiga tanpa dasar hukum yang sah

## 9. Penghentian Kontrak
- Mekanisme berhenti berlangganan lebih awal (denda/tidak, tergantung kebijakan)
- Kewajiban pengembalian perangkat dalam waktu tertentu sejak kontrak berhenti
- Konsekuensi jika perangkat tidak dikembalikan (lanjut ke klausul 7)

## 10. Force Majeure
- Kejadian di luar kendali (bencana alam, kebakaran, dsb) yang membebaskan penyewa dari tanggung jawab penuh atas kerusakan/kehilangan perangkat, dengan pembuktian yang wajar

## 11. Penyelesaian Sengketa
- Mekanisme mediasi sebelum jalur hukum
- Domisili hukum yang dipilih (pengadilan negeri mana yang berwenang)

## 12. Tanda Tangan Digital
- Kontrak disetujui merchant saat proses onboarding paket +device di sistem (checkbox + tanda tangan digital/OTP sebagai bukti persetujuan)
- Salinan kontrak final tersimpan dan bisa diunduh merchant kapan saja dari dashboard mereka

---

### Catatan Tambahan untuk EDC
Jika EDC yang digunakan disediakan oleh bank/payment provider (bukan aset milik platform Anda), klausul-klausul di atas terkait EDC perlu disesuaikan atau bahkan dipisah ke perjanjian tersendiri antara merchant dan penyedia EDC tersebut — pastikan hal ini diklarifikasi dengan partner EDC sebelum kontrak dengan merchant difinalisasi, supaya tidak ada klausul yang bertentangan atau tanggung jawab yang tumpang tindih.
