# Loan Management Script — Product Plan

> Нэр: **Lendyra** (2026-07-30 — loanpilot олон компанитай давхцсан тул сольсон; lendyra.io/.dev чөлөөтэй).
> Зарах суваг: CodeCanyon ($69) + өөрийн сайт/Lemon Squeezy ($79).
> Target: жижиг зээлдүүлэгчид, MFI, ломбард, кооператив — Африк, ЗӨА, Латин Америк.
> **IP дүрэм: ERP3-ийн ямар ч файлыг нээхгүй. Бүх код 0-оос. Домэйн мэдлэг толгойноос, код шинээр.**

---

## 1. Яагаад ялах вэ (differentiators)

Өрсөлдөгч script-үүд (ViserBank, Signal Loans гэх мэт) ихэвчлэн naive CRUD:
хүү нь энгийн үржвэр, хуваарь дахин тооцдоггүй, ledger байхгүй.

Бидний ялгарал:
1. **Зөв хүүгийн математик** — flat / declining / annuity / interest-only+balloon,
   360/365 basis, actual-days, leap year, month-end edge cases — бүгд тесттэй
2. **Идемпотент recompute** — partial payment, хугацаа хэтрэлт, restructure,
   early payoff quote (өдрөөр)
3. **Жинхэнэ double-entry ledger** — журнал бичилт автомат, trial balance export
4. **PAR тайлан** (Portfolio at Risk 30/60/90) — MFI салбарын стандарт метрик,
   өрсөлдөгчдөд байдаггүй
5. **Орчин үеийн UI** — Tailwind + Livewire 3 (өрсөлдөгчид хуучин Bootstrap)

## 2. MVP scope (v1.0)

### Core lending
- Зээлдэгч: профайл, ID/бичиг баримт upload, гэрээ PDF (template-ээс авто),
  батлан даагч
- Зээлийн бүтээгдэхүүн: хүүгийн арга, basis (360/365), хугацаа, шимтгэл
  (processing/disbursement), алдангийн тохиргоо (хувь, grace хоног,
  суурь = хэтэрсэн үндсэн төлбөр эсвэл график төлбөр)
- Lifecycle: application → approved → disbursed → active → closed / written-off
  (+ overdue, restructured төлөвүүд)
- Хуваарь: батлахын өмнө preview, батлахад generate
- Төлбөр: хуваарилалтын дараалал тохируулгатай (алданги→хүү→үндсэн),
  дутуу/илүү төлөлт, early payoff quote, баримт хэвлэх
- Алданги: өдөр бүр scheduler-ээр accrue
- Барьцаа: бүртгэл, зураг, чөлөөлөлт

### Books
- Multi-currency (зээл тус бүр нэг валют; FX хөрвүүлэлт v1-д байхгүй)
- Касс/банкны данс, автомат журнал бичилт, энгийн данс төлөвлөгөө
- Мөнгө = integer minor units (бутархай float ХЭЗЭЭ Ч үгүй)

### Ops
- Салбар (branch), эрхийн систем: admin / loan officer / cashier / accountant
- Dashboard: portfolio outstanding, PAR 30/90, өнөөдөр/энэ 7 хоногийн
  collection, хугацаа хэтэрсэн жагсаалт
- Тайлан: portfolio, collections, PAR aging, ажилтны гүйцэтгэл, day book,
  бүгд XLSX/PDF export
- Зээлдэгчийн statement PDF
- SMS/email сануулга — driver-based (Twilio, Africa's Talking, generic HTTP)
- Borrower portal (энгийн: үлдэгдэл + хуваарь харах) — buyers маш их асуудаг

### Packaging (CodeCanyon шаардлага)
- Web installer wizard (DB, admin бүртгэл, license key)
- Demo data seeder + demo site (цагаар reset cron)
- i18n: бүх string JSON lang файлд — худалдан авагч өөрөө орчуулна
- Documentation site (суулгах, тохируулах, FAQ)
- ENV шалгагч, cPanel/shared hosting дээр ажиллах (script buyers-ийн бодит орчин!)

### v1-д ОРУУЛАХГҮЙ (сахилга бат)
- Savings/deposit модуль (v2-ийн upsell)
- Mobile app
- Multi-tenant SaaS горим (v2: "SaaS edition" өндөр үнээр)
- Online payment gateway интеграц (v1.1)

## 3. Архитектур

- Laravel 12+, Livewire 3, Tailwind 4, TableWire (өөрийн багц — dogfooding!)
- Engine-ийг тусад нь namespace-д UI-аас салгаж бичих:
  - `ScheduleGenerator` (strategy per interest method)
  - `PaymentAllocator` (configurable waterfall)
  - `PenaltyAccruer` (daily, идемпотент)
  - `PayoffQuoter` (any-date quote)
  - `LoanLedger` (journal postings, double-entry invariant)
- Тест: engine-д golden-file тестүүд (арга бүр × edge case бүр);
  зорилго — engine coverage ~100%, UI smoke
- DB: MySQL 8 / MariaDB (shared hosting нийцтэй)

## 4. Хуваарь (7-8 долоо хоног, оройн цагаар)

| 7 хоног | Ажил |
|---|---|
| 1 | Repo, auth/roles, зээлдэгч CRUD, бүтээгдэхүүн CRUD |
| 2-3 | **Schedule engine + бүх тест** (хамгийн чухал, яарахгүй) |
| 4 | Төлбөр + allocation + penalty job + payoff |
| 5 | Барьцаа, PDF-үүд, ledger, dashboard, тайлангууд |
| 6 | Installer, demo seed, docs, i18n, security pass |
| 7 | CodeCanyon submission (1-2 удаа буцаагдахыг тооцох), landing + Lemon Squeezy |
| 8 | Launch: demo video (YouTube), MFI Facebook групп (Кени/Нигери/Филиппин), niche форумууд |

## 5. Үнэ ба орлогын төсөөлөл

- CodeCanyon Regular $69 (launch промо $49), Extended $299
- Өөрийн сайт $79 (шимтгэлгүй, lifetime updates)
- Бодит зорилт: эхний жил 150-300 борлуулалт ≈ $8-20k gross
- Support: Envato стандарт 6 сар; docs-first, canned replies

## 6. Эрсдэл

| Эрсдэл | Хариу |
|---|---|
| Ажил олгогчийн IP | Clean-room: ERP3 файл нээхгүй, гэрээгээ шалгах |
| CodeCanyon reject | Submission-ий өмнө тэдний checklist-ээр self-review |
| Support дарамт | Docs + FAQ + demo видео урьдчилж хийх |
| Nulled хувилбар | Тоолохгүй — үнэлгээ/update-ээр албан ёсныг нь үнэ цэнтэй байлгах |
| Хямд өрсөлдөөн | Үнээр биш чанараар — review-д математикийн зөв нь ялгарна |

---
Created: 2026-07-30
