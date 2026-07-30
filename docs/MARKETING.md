# Lendyra — Demo Video Script & Screenshot Plan

> Видео: 2м 30с орчим, дуу хоолойгүй (accent эрсдэлгүй) — англи caption + чимээгүй
> хөгжим. Бичлэг: 1920×1080, browser 100% zoom, хавчуурга/extension нуусан цонх
> (Ctrl+Shift+N incognito тохиромжтой). Хэрэгсэл: OBS (үнэгүй) эсвэл Loom.

---

## Бэлтгэл (бичлэгийн өмнө 5 минут)

1. `php artisan demo:reset` — цэвэр өгөгдөл (3 жишээ зээл бэлэн болно)
2. demo.lendyra.dev-д admin-ээр нэвтэрсэн байх
3. Браузерын өөр tab, notification бүгдийг хаах
4. Хулганы хөдөлгөөнийг удаан, тогтуун хийх — бүх даралтын дараа 1-2с хүлээх

---

## ВИДЕО SCRIPT (нийт ~2:30)

### Scene 1 — Нүүр (0:00–0:10)
- **Үйлдэл:** Login хуудас → нэвтрэх → Dashboard (график харагдана)
- **Caption:** `Lendyra — Loan Management for Micro-Lenders`
- Dashboard дээр 2-3с тогтоно: стат картууд + collections chart

### Scene 2 — Зээл үүсгэх + live preview (0:10–0:50) ⭐ ГОЛ SCENE
- **Үйлдэл:** Loans → New loan. Зээлдэгч сонгох (searchable dropdown-д бичиж
  хайх!), бүтээгдэхүүн сонгох → rate/term автоматаар бөглөгдөнө
- **Caption:** `Create a loan — the schedule previews live as you type`
- **Гол момент:** Amount-д 10000 бичих → баруун талд хуваарь шууд гарч ирнэ.
  Дараа нь term-ийг 12→24 болгож өөрчлөх → хуваарь шинэчлэгдэнэ. Хүүг өөрчлөх →
  дахин шинэчлэгдэнэ. Энэ live update-ийг 2 удаа үзүүл — энэ бол хамгийн
  "wow" feature
- **Caption:** `Flat, declining, annuity & balloon methods — actual-day interest supported`
- Create loan дарна

### Scene 3 — Олголт (0:50–1:05)
- **Үйлдэл:** Show хуудсан дээр "Disburse & activate" дарна → хуваарь үүснэ
- **Caption:** `One click to disburse — double-entry ledger posts automatically`

### Scene 4 — Төлбөр + waterfall (1:05–1:35)
- **Үйлдэл:** "Record payment" → дүн оруулах (граафикаас бага дүн, ж: 500) →
  хадгалах → Payments хүснэгтэд allocation задаргаа харагдана
  (#1 interest: ..., #1 principal: ...)
- **Caption:** `Payments allocate automatically: penalty → interest → principal`
- **Үйлдэл:** Receipt линк дарж баримт харуулах (1-2с) → буцах
- **Caption:** `Printable receipts & statements`

### Scene 5 — Хэтэрсэн зээл + PAR (1:35–2:00)
- **Үйлдэл:** Loans жагсаалт → LN-xx-00002 (улаан Overdue badge-тэй) нээх →
  улаан мөрүүд + penalty багана харуулах
- **Caption:** `Automatic daily penalties with grace days & caps`
- **Үйлдэл:** Reports → Portfolio: PAR 30/60/90 картууд + overdue жагсаалт
- **Caption:** `PAR 30/60/90 — real MFI risk reporting`

### Scene 6 — Payoff quote (2:00–2:15)
- **Үйлдэл:** Идэвхтэй зээл → Payoff товч → modal нээгдэнэ, огноог өөрчлөх →
  quote шинэчлэгдэнэ (үзүүлээд Cancel)
- **Caption:** `Any-date payoff quotes — future interest always waived`

### Scene 7 — Төгсгөл (2:15–2:30)
- **Үйлдэл:** Trial Balance хуудас (2с) → Users хуудас (2с) → Dashboard руу буцна
- **Caption 1:** `Double-entry books that always balance`
- **Caption 2:** `Roles, branches, multi-language, web installer`
- **Финал caption:** `Lendyra — demo.lendyra.dev`

---

## СКРИНШОТ (10ш, 1920×1080, CodeCanyon-д эхний зураг нь thumbnail болно)

| # | Хуудас | Юу харагдах ёстой | Тэмдэглэл |
|---|---|---|---|
| 1 | Dashboard | 4 стат карт + collections chart | **Thumbnail — хамгийн чухал** |
| 2 | Loans / create | Форм + баруун талд бүтэн preview хуваарь | Wow feature |
| 3 | Loan show (эрүүл) | Стат картууд, хуваарь, Paid badge-ууд | LN-xx-00001 |
| 4 | Loan show (хэтэрсэн) | Улаан мөрүүд, penalty, Overdue badge | LN-xx-00002 |
| 5 | Payment modal + allocation | Payments хүснэгтийн задаргаатай нь | |
| 6 | Payoff modal | Quote задаргаа харагдаж байгаа | |
| 7 | Reports / Portfolio | PAR картууд + overdue жагсаалт | |
| 8 | Trial Balance | Данснууд + balanced totals | Нягтлан итгүүлнэ |
| 9 | Receipt/Statement | Хэвлэх хуудас | Печатийн харагдац |
| 10 | Installer (алхам 1) | Requirements чеклист бүгд ногоон | "Суулгахад амархан" мессеж |

Инсталлерын скриншот авахдаа: локал орчинд `storage/app/installed.lock`-оо
түр зөөгөөд /install нээнэ (демо дээр бүү хий).

---

## Листингийн бичвэрт онцлох дараалал (скриншотуудын тайлбарт)

1. Live schedule preview (өрсөлдөгчдөд байхгүй)
2. Зөв хүүгийн математик — 4 арга, 1,900 автомат тест
3. Waterfall төлбөр + reversal + audit trail
4. PAR 30/60/90 + double-entry ledger
5. Хялбар суулгалт (3 алхамт installer, shared hosting OK)
