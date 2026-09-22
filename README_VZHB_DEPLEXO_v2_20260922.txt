VZHB DEPLEXO ALL-IN-ONE
========================

File utama:
vzhb_deplexo_market_allinone_v2_20260922.php

Fungsi:
- Market VZHB internal VRILZHUB.
- Harga bergerak kira-kira setiap 1 detik.
- Tidak memakai BTC, Gold, Binance, atau harga eksternal.
- API dan engine berjalan dalam SATU proses PHP.
- History terakhir disimpan ke file JSON lokal.
- Ada endpoint health, market, dan admin price adjustment.

Deploy:
1. Upload semua file ke GitHub repository:
   vzhb-market-worker
2. Di Deplexo pilih repository tersebut.
3. Framework: PHP.
4. Start command:
   php vzhb_deplexo_market_allinone_v2_20260922.php
5. Port: 3000.
6. Tambahkan environment variable:
   PORT=3000
   ADMIN_KEY=BUAT_KUNCI_RAHASIA_SENDIRI
   START_PRICE=1000
   MAX_TICK_PERCENT=0.25
   MIN_PRICE=1
   MAX_PRICE=1000000000

API:
GET /
GET /health
GET /api/market
GET /api/market?limit=120

Admin:
POST /api/admin/price
Header:
X-VZHB-Admin-Key: KUNCI_RAHASIA

Body JSON:
{"price":1500,"note":"Penyesuaian admin"}

CATATAN PENTING:
- File JSON state berada di container Deplexo. Jika container benar-benar dibuat ulang
  dari image baru, state lokal dapat hilang; karena itu untuk sistem produksi dengan
  saldo/portfolio nyata, database persisten tetap diperlukan.
- Worker ini hanya mengurus harga dan history VZHB. Saldo users/portfolio di InfinityFree
  belum disentuh.
- Jangan menaruh ADMIN_KEY di repository GitHub. Gunakan Environment Variables Deplexo.
