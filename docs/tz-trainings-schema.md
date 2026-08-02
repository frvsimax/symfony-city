# ТЗ: модуль тренировок — схема БД

Версия: **0.1**  
Основание:
- [бизнес-логика v0.4](./tz-trainings-business.md) (глоссарий — §2)
- [архитектура кода](./tz-trainings-architecture.md)  
СУБД: PostgreSQL (рекомендация) / MySQL 8+ — типы ниже в нейтральном SQL.

Принципы:
- только классический контур фиксированной группы;
- без `source`, front-записи, MIX, заморозок, пробных;
- внешние сущности (`player`, `coach`/`hdbk_coach`, `user`, платёж) — по id, без владения таблицами;
- явные FK и уникальные ограничения там, где в legacy их не было.

---

## 1. ER (логика)

```
program
  └── training_group
        ├── training_group_coach     → coach (external)
        ├── training_group_area      → area
        ├── training_group_level
        ├── schedule_slot  ──generates──► training_session_occurrence (training)
        │                                      ├── attendance
        │                                      ├── attendance_reschedule
        │                                      └── attendance_additional
        ├── group_membership (player_group)
        ├── product  → product_template
        └── abonement → abonement_payment_link

area → area_center
season ← training.season_id (optional)

camp / training_camp          — сборы (заготовка, детализация позже)
```

Имена в скобках — аналоги legacy, если удобнее для миграции данных.

---

## 2. Справочники и площадки

### 2.1. `area_center`

Кампус / центр.

| Column | Type | Notes |
|--------|------|--------|
| `id` | BIGINT PK | |
| `name` | VARCHAR(255) NOT NULL | |
| `address` | VARCHAR(512) NULL | |
| `is_active` | BOOLEAN NOT NULL DEFAULT TRUE | |
| `created_at` / `updated_at` | TIMESTAMPTZ NOT NULL | |

### 2.2. `area`

Площадка (поле / зал).

| Column | Type | Notes |
|--------|------|--------|
| `id` | BIGINT PK | |
| `center_id` | BIGINT NOT NULL FK → area_center | |
| `name` | VARCHAR(255) NOT NULL | |
| `address` | VARCHAR(512) NULL | |
| `is_active` | BOOLEAN NOT NULL DEFAULT TRUE | |
| `created_at` / `updated_at` | TIMESTAMPTZ NOT NULL | |

Индекс: `(center_id)`.

### 2.3. `program`

| Column | Type | Notes |
|--------|------|--------|
| `id` | BIGINT PK | |
| `name` | VARCHAR(255) NOT NULL | |
| `description` | TEXT NULL | |
| `sort_order` | INT NOT NULL DEFAULT 0 | |
| `is_published` | BOOLEAN NOT NULL DEFAULT FALSE | |
| `created_at` / `updated_at` | TIMESTAMPTZ NOT NULL | |
| `created_by` / `updated_by` | BIGINT NULL | → user (external) |

### 2.4. `season`

| Column | Type | Notes |
|--------|------|--------|
| `id` | BIGINT PK | |
| `title` | VARCHAR(255) NOT NULL | |
| `starts_on` | DATE NOT NULL | |
| `ends_on` | DATE NOT NULL | |
| `created_at` / `updated_at` | TIMESTAMPTZ NOT NULL | |

CHECK: `ends_on >= starts_on`.

---

## 3. Группа и состав

### 3.1. `training_group` (legacy: `group`)

| Column | Type | Notes |
|--------|------|--------|
| `id` | BIGINT PK | |
| `program_id` | BIGINT NULL FK → program | |
| `center_id` | BIGINT NOT NULL FK → area_center | |
| `name` | VARCHAR(128) NOT NULL | |
| `capacity` | INT NOT NULL | legacy `limit`; > 0 |
| `description` | TEXT NULL | |
| `color` | VARCHAR(32) NULL | UI |
| `can_additional` | BOOLEAN NOT NULL DEFAULT TRUE | доигровки/доп. на чужие группы |
| `additional_type` | VARCHAR(32) NULL | напр. `fitness` / `skills`; NULL если не используется |
| `is_published` | BOOLEAN NOT NULL DEFAULT FALSE | |
| `created_at` / `updated_at` | TIMESTAMPTZ NOT NULL | |
| `created_by` / `updated_by` | BIGINT NULL | |

CHECK: `capacity > 0`.

**Не включаем:** `registration_type`, `can_free_abonement`, `trial_training`, `visit_options`, `source`, SEO, `base_team_id`.

### 3.2. `training_group_coach`

| Column | Type |
|--------|------|
| `group_id` | BIGINT NOT NULL FK → training_group ON DELETE CASCADE |
| `coach_id` | BIGINT NOT NULL | → coach (external) |

PK `(group_id, coach_id)`.

### 3.3. `training_group_area`

| Column | Type |
|--------|------|
| `group_id` | BIGINT NOT NULL FK → training_group ON DELETE CASCADE |
| `area_id` | BIGINT NOT NULL FK → area |

PK `(group_id, area_id)`.

### 3.4. `training_group_level`

| Column | Type |
|--------|------|
| `group_id` | BIGINT NOT NULL FK → training_group ON DELETE CASCADE |
| `level` | VARCHAR(64) NOT NULL | код/имя уровня |

PK `(group_id, level)`.

### 3.5. `group_membership` (legacy: `player_group`)

| Column | Type | Notes |
|--------|------|--------|
| `id` | BIGINT PK | |
| `group_id` | BIGINT NOT NULL FK → training_group | |
| `player_id` | BIGINT NOT NULL | → player (external) |
| `status` | SMALLINT NOT NULL | `1=active`, `2=paused`, `3=gone` |
| `started_on` | DATE NULL | |
| `gone_on` | DATE NULL | |
| `started_at` | TIMESTAMPTZ NULL | |
| `gone_at` | TIMESTAMPTZ NULL | |
| `prolongated_at` | TIMESTAMPTZ NULL | смена ряда при продлении |
| `misses_annulled_at` | TIMESTAMPTZ NULL | учёт miss-доигровок |
| `created_at` / `updated_at` | TIMESTAMPTZ NOT NULL | |

Индексы: `(group_id, status)`, `(player_id, group_id)`.

Частичный уникальный индекс (рекомендация): не более одного `active`/`paused` на пару player+group:

```sql
-- PostgreSQL
CREATE UNIQUE INDEX uq_membership_open
  ON group_membership (player_id, group_id)
  WHERE status IN (1, 2);
```

---

## 4. Расписание и тренировки

### 4.1. `schedule_slot` (legacy: `schedule`)

Повторяющийся недельный слот.

| Column | Type | Notes |
|--------|------|--------|
| `id` | BIGINT PK | |
| `group_id` | BIGINT NOT NULL FK → training_group ON DELETE CASCADE | |
| `area_id` | BIGINT NOT NULL FK → area | |
| `coach_id` | BIGINT NOT NULL | → coach |
| `weekday` | SMALLINT NOT NULL | 1=пн … 7=вс (ISO) |
| `starts_at` | TIME NOT NULL | |
| `ends_at` | TIME NOT NULL | может быть «через полночь» — логика в приложении |
| `created_at` / `updated_at` | TIMESTAMPTZ NOT NULL | |
| `created_by` / `updated_by` | BIGINT NULL | |

CHECK: `weekday BETWEEN 1 AND 7`.  
Индекс: `(group_id, weekday)`.

### 4.2. `training` (конкретное занятие)

| Column | Type | Notes |
|--------|------|--------|
| `id` | BIGINT PK | |
| `group_id` | BIGINT NOT NULL FK → training_group | |
| `schedule_slot_id` | BIGINT NULL FK → schedule_slot ON DELETE SET NULL | NULL = разовая |
| `season_id` | BIGINT NULL FK → season | заполняется при создании |
| `area_id` | BIGINT NOT NULL FK → area | |
| `coach_id` | BIGINT NOT NULL | → coach |
| `starts_at` | TIMESTAMPTZ NOT NULL | |
| `ends_at` | TIMESTAMPTZ NOT NULL | |
| `status` | SMALLINT NOT NULL DEFAULT 0 | `0=new`, `1=finished`, `2=cancelled` |
| `additional_capacity` | INT NOT NULL DEFAULT 0 | legacy `additional_count`, init = group.capacity |
| `additional_taken` | INT NOT NULL DEFAULT 0 | legacy `additional_current` |
| `created_at` / `updated_at` | TIMESTAMPTZ NOT NULL | |
| `created_by` / `updated_by` | BIGINT NULL | |

CHECK: `ends_at > starts_at`; `status IN (0,1,2)`; `additional_taken >= 0`; `additional_capacity >= 0`.

Индексы: `(group_id, starts_at)`, `(schedule_slot_id)`, `(coach_id, starts_at)`, `(status, starts_at)`.

**Не включаем:** `use_for_front`, `theme_front`, `source`.

Уникальность слота (опционально, против дублей генерации):

```sql
CREATE UNIQUE INDEX uq_training_slot_start
  ON training (schedule_slot_id, starts_at)
  WHERE schedule_slot_id IS NOT NULL;
```

---

## 5. Продукт и абонемент

### 5.1. `product_template`

| Column | Type | Notes |
|--------|------|--------|
| `id` | BIGINT PK | |
| `name` | VARCHAR(128) NOT NULL | |
| `trainings_count` | INT NOT NULL | размер пакета |
| `allow_additional_games` | BOOLEAN NOT NULL DEFAULT TRUE | политика доигровок (если нужна на шаблоне) |
| `created_at` / `updated_at` | TIMESTAMPTZ NOT NULL | |

CHECK: `trainings_count > 0`.

**Не включаем:** `count_freezing`, `count_cancel`, `show_in_*`, front `limit*`, `season_freezes_limits`, `source`.

### 5.2. `product`

Продукт, привязанный к группе.

| Column | Type | Notes |
|--------|------|--------|
| `id` | BIGINT PK | |
| `template_id` | BIGINT NOT NULL FK → product_template | |
| `group_id` | BIGINT NOT NULL FK → training_group | legacy: `item_id` + `module=group` |
| `name` | VARCHAR(255) NOT NULL | |
| `price` | INT NOT NULL | копейки/рубли — единая единица в проекте |
| `prolongation_price` | INT NOT NULL | |
| `prolongation_price_promo` | INT NULL | |
| `description` | TEXT NULL | |
| `is_buyable_public` | BOOLEAN NOT NULL DEFAULT FALSE | |
| `is_buyable_private` | BOOLEAN NOT NULL DEFAULT FALSE | |
| `send_email_on_buy` | BOOLEAN NOT NULL DEFAULT FALSE | |
| `created_at` / `updated_at` | TIMESTAMPTZ NOT NULL | |
| `created_by` / `updated_by` | BIGINT NULL | |

**Не включаем:** `type` (FREE/MIX), freeze-поля, `source`, `module` (только group).

Индекс: `(group_id)`.

### 5.3. `abonement`

| Column | Type | Notes |
|--------|------|--------|
| `id` | BIGINT PK | |
| `player_id` | BIGINT NOT NULL | → player |
| `group_id` | BIGINT NOT NULL FK → training_group | |
| `product_id` | BIGINT NOT NULL FK → product | |
| `trainings_left` | INT NOT NULL | legacy `count_training` |
| `status` | SMALLINT NOT NULL | `0=unused`, `1=active`, `2=inactive` |
| `created_on` | DATE NOT NULL | |
| `activated_on` | DATE NULL | |
| `inactivated_at` | TIMESTAMPTZ NULL | |
| `is_extended` | BOOLEAN NOT NULL DEFAULT FALSE | |
| `is_first` | BOOLEAN NOT NULL DEFAULT FALSE | legacy `new` |
| `created_at` / `updated_at` | TIMESTAMPTZ NOT NULL | |

CHECK: `trainings_left >= 0`; `status IN (0,1,2)`.

Индексы: `(player_id, status)`, `(group_id, status)`.

**Не включаем:** `count_freezing`, `count_cancel`, `source`.

### 5.4. `abonement_payment`

Связь confirm шлюза → абонемент (граница биллинга).

| Column | Type | Notes |
|--------|------|--------|
| `id` | BIGINT PK | |
| `abonement_id` | BIGINT NOT NULL FK → abonement | |
| `payment_id` | BIGINT NOT NULL | → платёж/pay во внешнем модуле |
| `created_at` | TIMESTAMPTZ NOT NULL | |

UNIQUE `(abonement_id, payment_id)`; индекс `(payment_id)`.

---

## 6. Посещаемость и доигровки

### 6.1. `attendance`

| Column | Type | Notes |
|--------|------|--------|
| `id` | BIGINT PK | |
| `training_id` | BIGINT NOT NULL FK → training | |
| `player_id` | BIGINT NOT NULL | → player |
| `abonement_id` | BIGINT NULL FK → abonement | с какого пакета списали |
| `present` | BOOLEAN NOT NULL DEFAULT FALSE | legacy `in` |
| `is_reschedule` | BOOLEAN NOT NULL DEFAULT FALSE | |
| `reschedule_type` | VARCHAR(16) NULL | `current` / `miss` |
| `is_additional` | BOOLEAN NOT NULL DEFAULT FALSE | |
| `goals` | INT NULL | |
| `assists` | INT NULL | |
| `is_best_player` | BOOLEAN NOT NULL DEFAULT FALSE | |
| `comment` | TEXT NULL | |
| `debit_applied` | BOOLEAN NOT NULL DEFAULT FALSE | списано ли −1 за «был» (удобно для идемпотентности) |
| `notification_sent` | BOOLEAN NOT NULL DEFAULT FALSE | legacy `sent` |
| `created_at` / `updated_at` | TIMESTAMPTZ NOT NULL | |
| `created_by` / `updated_by` | BIGINT NULL | |

UNIQUE `(training_id, player_id)`.  
Индексы: `(abonement_id)`, `(player_id, created_at)`.

CHECK: `reschedule_type IS NULL OR reschedule_type IN ('current','miss')`.

**Не включаем:** `trial`, `front_record`, `freezing`, `freeze_by_player`, `source`.

Правило списания (приложение): при `present = true` и `debit_applied = false` → `abonement.trainings_left -= 1`, `debit_applied = true`; при снятии «был» / отмене тренировки — обратная операция.

### 6.2. `attendance_reschedule`

Доигровка: перенос с тренировки A на B.

| Column | Type | Notes |
|--------|------|--------|
| `id` | BIGINT PK | |
| `attendance_id` | BIGINT NULL FK → attendance ON DELETE SET NULL | может появиться позже |
| `abonement_id` | BIGINT NOT NULL FK → abonement | |
| `player_id` | BIGINT NOT NULL | |
| `from_training_id` | BIGINT NOT NULL FK → training | legacy `training_id` |
| `to_training_id` | BIGINT NOT NULL FK → training | legacy `new_training_id` |
| `type` | VARCHAR(16) NOT NULL | `current` / `miss` |
| `created_at` / `updated_at` | TIMESTAMPTZ NOT NULL | |
| `created_by` / `updated_by` | BIGINT NULL | |

CHECK: `from_training_id <> to_training_id`; `type IN ('current','miss')`.  
Индексы: `(player_id)`, `(from_training_id)`, `(to_training_id)`.  
UNIQUE опционально: `(player_id, from_training_id, type)` — одна доигровка с одного занятия.

### 6.3. `attendance_additional`

Учёт additional-визита (если отделяем от флага на attendance).

| Column | Type |
|--------|------|
| `id` | BIGINT PK |
| `attendance_id` | BIGINT NOT NULL FK → attendance ON DELETE CASCADE |
| `abonement_id` | BIGINT NOT NULL FK → abonement |
| `player_id` | BIGINT NOT NULL |
| `training_id` | BIGINT NOT NULL FK → training |
| `created_at` / `updated_at` | TIMESTAMPTZ NOT NULL |
| `created_by` / `updated_by` | BIGINT NULL |

UNIQUE `(training_id, player_id)`.

*Альтернатива упрощения:* не заводить таблицу и хранить только `attendance.is_additional` — решить при реализации доигровок.

### 6.4. `group_additional_allowance` (legacy: `additional_group_assn`)

Какие группы можно посещать как additional относительно своей.

| Column | Type |
|--------|------|
| `group_id` | BIGINT NOT NULL FK → training_group ON DELETE CASCADE |
| `allowed_group_id` | BIGINT NOT NULL FK → training_group ON DELETE CASCADE |

PK `(group_id, allowed_group_id)`.  
CHECK: `group_id <> allowed_group_id`.

### 6.5. `additional_quota_setting` (legacy: `attendance_additional_settings`) — опционально

| Column | Type |
|--------|------|
| `id` | BIGINT PK |
| `center_id` | BIGINT NOT NULL FK → area_center |
| `additional_type` | VARCHAR(32) NOT NULL |
| `quota` | INT NOT NULL DEFAULT 0 |

UNIQUE `(center_id, additional_type)`.

---

## 7. Сборы (заготовка)

Детализация по ТЗ — позже. Минимальный каркас:

### 7.1. `camp`

| Column | Type | Notes |
|--------|------|--------|
| `id` | BIGINT PK | |
| `name` | VARCHAR(255) NOT NULL | |
| `description` | TEXT NULL | |
| `is_published` | BOOLEAN NOT NULL DEFAULT FALSE | |
| `sort_order` | INT NOT NULL DEFAULT 0 | |
| `starts_on` / `ends_on` | DATE NULL | расширить при детализации |
| `created_at` / `updated_at` | TIMESTAMPTZ NOT NULL | |

Связи состав/оплата сборов — отдельным ADR, когда будет бизнес-ТЗ по сборам.

---

## 8. Внешние ссылки (не таблицы модуля)

| Ссылка | Где используется |
|--------|------------------|
| `player.id` | membership, abonement, attendance, reschedule |
| `coach.id` (hdbk_coach) | group_coach, schedule_slot, training |
| `user.id` | created_by / updated_by |
| `payment.id` / `pay.id` | abonement_payment |

---

## 9. Что сознательно не создаём

| Legacy | Причина |
|--------|---------|
| `training_record` | front-запись вне scope |
| `player_freeze`, `training_player_freeze`, `player_freeze_history`, `season_freeze_limit` | заморозки вне scope |
| колонки `source` | один продукт City |
| `product.type` / MIX templates | свободное посещение вне scope |
| `attendance.trial` / `front_record` / freeze-флаги | вне scope |

---

## 10. Соответствие бизнес-правилам → схема

| Правило ТЗ | Как отражено |
|------------|--------------|
| Фиксированная группа | `group_membership` + нет `training_record` |
| Генерация 30 дней | `schedule_slot` → строки `training` |
| Списание только за «был» | `attendance.present` + `debit_applied` / `abonement.trainings_left` |
| Отмена после «был» → возврат | приложение: `debit_applied` rollback |
| Вместимость состава | `training_group.capacity` vs membership status |
| Доп./доигровки | `additional_*` на training + reschedule/additional tables |
| Confirm шлюза | `abonement_payment` |

---

## 11. Открытые решения по схеме

1. **Именование:** `training_group` vs оставить `group` (зарезервировано в SQL) — рекомендовано `training_group`.
2. **`attendance_additional`:** отдельная таблица или только флаг на `attendance`.
3. **Деньги:** INT в копейках vs DECIMAL — зафиксировать стандарт проекта.
4. **`debit_applied`:** явное поле vs вывод из истории проводок — для v1 поле проще.
5. **Сборы:** расширить `camp` или отдельный BC со своими таблицами.
6. **Миграция с Yii:** маппинг старых id / backfill `debit_applied` из факта `in=1` и текущего баланса.

---

## История

| Дата | Решение |
|------|---------|
| 2026-08-01 | Черновик схемы под бизнес-ТЗ v0.3 (классический контур) |
