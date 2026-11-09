# Rezervácie – WordPress plugin

Online rezervačný systém pre salón (náhrada za Bookio): služby, pracovníci, prenájmy, online formulár,
kalendár, e-maily, storno, čakacia listina, zľavové kódy, darčekové poukážky a permanentky,
QR platby (PAY by square), online platby kartou (Stripe), hodnotenia, vernostný program a štatistiky.

## Inštalácia

1. `dist/rezervacie-<verzia>.zip` → WordPress → Pluginy → Pridať nový → Nahrať plugin → Aktivovať.
2. Pri aktivácii vzniknú stránky **Rezervácia** (`[rezervacie]`) a **Darčekové poukážky** (`[rezervacie_poukazky]`).
3. Menu **Rezervácie** → Nastavenia: prevádzka, pracovný čas, e-maily, platby (IBAN / Stripe), poukážky, hodnotenia, vernostný program.
4. Pridať kategórie, služby, pracovníkov (prípadne miestnosti/predmety a prenájmy).

Požiadavky: WordPress 6.2+, PHP 8.0+, MySQL/MariaDB. Pre spoľahlivé pripomienky odporúčame skutočný cron
(`DISABLE_WP_CRON` + systémový cron volajúci `wp-cron.php` každých 5–15 minút).

## Shortcody

| Shortcode | Popis |
|---|---|
| `[rezervacie]` | Rezervačný formulár. Voliteľne `category="ID"`, `service="ID"`, `worker="ID"`. |
| `[rezervacie_poukazky]` | Predaj darčekových poukážok a permanentiek. |
| `[rezervacie_hodnotenia]` | Zverejnené hodnotenia s priemerom. Voliteľne `limit`, `min`, `worker`, `service`. |

## Vývoj a testy

Lokálne prostredie (Docker vo WSL): WordPress na http://localhost:8080 (admin/admin), e-maily v Mailpite na http://localhost:8025.

```bash
docker compose up -d
docker compose run --rm cli sh /dev-scripts/setup.sh                       # inštalácia + demo dáta
docker compose run --rm cli wp eval-file /dev-scripts/tests/f2-vouchers.php
docker compose run --rm cli wp eval-file /dev-scripts/tests/f2-online.php
docker compose run --rm cli sh -c "wp db query < /dev-scripts/tests/f3-reset.sql && wp eval-file /dev-scripts/tests/f3.php"
```

Balík na inštaláciu: `python dev/build-zip.py` → `dist/rezervacie-<verzia>.zip`, overenie čistou inštaláciou: `sh dev/test-zip.sh <verzia>` (vo WSL).

## Štruktúra

- `rezervacie/rezervacie.php` – hlavný súbor, verzie (`RZV_VERSION`, `RZV_DB_VERSION`)
- `rezervacie/includes/` – jadro: `Slots` (voľné termíny), `Booking`, `Pricing`, `Payments`, `Stripe`, `PayBySquare`, `Vouchers`, `Coupons`, `Loyalty`, `Ratings`, `Mailer`, `Rest` (REST `rzv/v1`)
- `rezervacie/includes/admin/` – administrácia (kalendár, zoznamy, nastavenia, štatistiky)
- `rezervacie/assets/` – formulár na webe (`widget.js`), admin (`admin.js`), `vendor/qrcode.js` (MIT)
- `dev/` – Docker, inštalačný skript, testy
