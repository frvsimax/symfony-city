# ТЗ: модуль тренировок — архитектура кода

Версия: **0.1**  
Основание:
- [бизнес-логика v0.4](./tz-trainings-business.md)
- [схема БД](./tz-trainings-schema.md)

Документ фиксирует архитектурные принципы и ориентировочную структуру классов для переписывания модуля тренировок на Symfony. Это каркас для старта проекта, не финальный дизайн каждого класса.

---

## 1. Цели архитектуры

1. Реализовать **классический контур** фиксированной группы без front/MIX/заморозок.
2. Держать бизнес-правила (списание, отмена, генерация, доигровки) в явном, тестируемом коде — не в колбэках ActiveRecord и не в «хелперах на 2k строк».
3. Отделить модуль от платежей, карточки игрока/тренера и транспорта уведомлений через порты.
4. Дать команде предсказуемые места: «куда класть новый сценарий», «где инвариант», «где SQL».

---

## 2. Архитектурные принципы

### 2.1. Один bounded context на старте: Training

В контексте: программа, группа, состав, слот, тренировка, продукт, абонемент, посещаемость, доигровка; позже — сборы.

Снаружи (не владеем таблицами, только id + порты):
- игрок (`Player`);
- тренер (`Coach`);
- пользователь / роли (`User`);
- платёж / шлюз (`Payment`);
- отправка email.

Сборы (`Camp`) — тот же BC с отдельным подпакетом или соседний модуль с тонким API; детализация после ядра weekly-тренировок.

### 2.2. Слоистая структура внутри модуля

| Слой | Ответственность | Не знает о |
|------|-----------------|------------|
| **Domain** | Инварианты, сущности, VO, политики, доменные события, исключения | HTTP, Doctrine API, шаблоны писем, платёжный SDK |
| **Application** | Use-case (команды/запросы), транзакция сценария, порты | Детали SQL, Twig, конкретный почтовый провайдер |
| **Infrastructure** | Реализации портов: Doctrine, mail, webhook-адаптер, Clock | Бизнес-правил «с нуля» (только техническая реализация) |
| **UI** | HTTP Admin/Coach/Player, Console, Webhook | Прямого изменения баланса в обход хендлеров |

Зависимости только внутрь: `UI → Application → Domain`; `Infrastructure → Application (порты) + Domain`.

### 2.3. Тонкие контроллеры, явные сценарии

Одна пользовательская операция из бизнес-ТЗ ≈ один **Command + Handler** (или Query + Handler).

Контроллер: авторизация → валидация входа → вызов хендлера → ответ.  
Без `entity->save()` с побочными эффектами «на полсистемы».

### 2.4. Агрегаты и границы транзакции

- Меняем согласованный набор сущностей в рамках **одного application-сценария** и одной DB-транзакции.
- Чужие сущности — по id (игрок, тренер), без загрузки всего графа клуба.
- Пример: отметка «был» меняет `Attendance` и `Abonement.trainingsLeft` атомарно в `MarkAttendanceHandler`.

### 2.5. Идемпотентность учётных операций

- Повторное сохранение «был» не списывает второй раз (`debitApplied` / доменная проверка).
- Снятие «был» возвращает ровно одно занятие, если списание было.
- Confirm оплаты с тем же `payment_id` не создаёт дубликат абонемента (`abonement_payment` UNIQUE).

### 2.6. Побочные эффекты через доменные / application-события

Списание, отмена тренировки, обнуление абонемента → события → listener/outbox → email.

Домен и хендлер **не** вызывают почтовый SDK напрямую.

### 2.7. Политики отдельно от сущностей

Правила, которым нужны несколько сущностей или справочные данные:
- генерация дат на 30 дней;
- заполненность занятия / можно ли доигровку;
- лимиты current/miss;
- вместимость состава.

Это `Policy` / domain service, а не методы «сделай всё» на `Training` / `Abonement`.

### 2.8. Язык глоссария

Имена классов и полей — как в [§2 бизнес-ТЗ](./tz-trainings-business.md):  
`TrainingGroup`, `GroupMembership`, `ScheduleSlot`, `present`, `trainingsLeft`, а не legacy `in` / `count_training` в публичном API домена (в persistence допустим маппинг на старые колонки при миграции).

### 2.9. Не копировать legacy-форму

- Нет `SourceBehavior` и колонок `source` в ядре.
- Нет полей front/MIX «на будущее» в агрегатах v1.
- Нет гигантских `*Helper` / `afterSave` с неявными цепочками.
- Не тащить Event Sourcing / полный CQRS на старте: достаточно commands + domain events для интеграций.

### 2.10. Вертикальные срезы поставки

1. Group + Membership + Slot + Training (генерация)  
2. Product + Abonement + payment confirm  
3. Attendance + debit/credit  
4. Cancel training + refund  
5. Reschedule (доигровки)  
6. Email-каталог по событиям  
7. Camp  

---

## 3. Структура каталогов (ориентир)

```
src/Training/
  Domain/
    Model/
    ValueObject/
    Policy/
    Event/
    Exception/
  Application/
    Command/
      CreateGroup/
      UpsertScheduleSlot/
      CreateOneOffTraining/
      MarkAttendance/
      CancelTraining/
      DeleteTraining/
      CreateAbonementFromPayment/
      CreateReschedule/
      CancelReschedule/
      ...
    Query/
      GetTrainingForCoach/
      ListGroupTrainings/
      GetAbonementBalance/
      ...
    Port/
    Dto/                    # опционально, если не хватает Command/Query
  Infrastructure/
    Persistence/Doctrine/
    Mail/
    Payment/
    Clock/
  UI/
    Admin/
    Coach/
    Player/
    Webhook/
    Console/
```

Допустимы варианты нейминга (`src/Module/Training`, `src/CityFootball/Training`) — границы слоёв важнее префикса.

---

## 4. Domain: сущности и value objects

### 4.1. Модель (агрегаты / сущности)

| Класс | Ответственность |
|-------|-----------------|
| `Program` | Справочник линейки продукта |
| `TrainingGroup` | Название, capacity, canAdditional, center/program; инварианты группы |
| `GroupMembership` | Связь игрок↔группа; статусы active/paused/gone; даты |
| `ScheduleSlot` | Недельный шаблон: weekday, time, area, coach |
| `Training` | Экземпляр занятия; status; additional capacity/taken; `cancel()`, запрет delete при attendance |
| `ProductTemplate` | Размер пакета занятий, базовые флаги политик |
| `Product` | Продукт группы: цены, шаблон, признаки продажи |
| `Abonement` | Остаток, статус; `debitOne()`, `creditOne()`, переходы статусов |
| `Attendance` | present, debitApplied, флаги reschedule/additional; смена отметки через поведение |
| `AttendanceReschedule` | current/miss; from/to training |
| `Camp` | Заготовка сборов |

Ассоциации M2M (`TrainingGroupCoach`, `TrainingGroupArea`, `GroupAdditionalAllowance`) — отдельные сущности или value-коллекции внутри группы — на выбор реализации; в БД см. схему.

### 4.2. Value objects

| Класс | Смысл |
|-------|--------|
| `TrainingStatus` | new / finished / cancelled |
| `MembershipStatus` | active / paused / gone |
| `AbonementStatus` | unused / active / inactive |
| `RescheduleType` | current / miss |
| `Weekday` | 1–7 ISO |
| `TimeRange` | starts/ends time или datetime range для занятия |
| `Money` | цена в единой валютной единице проекта (если живёт в модуле) |

### 4.3. Политики (domain services)

| Класс | Правило |
|-------|---------|
| `ScheduleGenerationPolicy` | По слоту построить даты/интервалы на 30 календарных дней; правило «сегодня + время уже прошло» |
| `GroupCapacityPolicy` | Свободные места в составе (active; paused — по открытому решению ТЗ) |
| `TrainingRosterPolicy` | Список на занятие: состав − ушедшие доигровкой + пришедшие + additional |
| `SessionCapacityPolicy` | Можно ли ещё принять доигровку/доп. (лимит vs roster; additional slots) |
| `RescheduleEligibilityPolicy` | Лимиты пакета, miss vs пропуски, целевая группа, окно отказа |

### 4.4. Доменные события (минимум)

| Событие | Когда |
|---------|--------|
| `AttendanceMarkedPresent` | Первичный переход в «был» + списание |
| `AttendanceUnmarkedPresent` | Снятие «был» + возврат |
| `TrainingCancelled` | Отмена занятия (+ возвраты) |
| `AbonementCreated` | После confirm оплаты |
| `AbonementBalanceChanged` | Любое debit/credit |
| `AbonementExhausted` | Остаток стал 0 |
| `RescheduleCreated` / `RescheduleCancelled` | Доигровки |

### 4.5. Доменные исключения (примеры)

- `TrainingNotDeletable` — есть посещаемости  
- `AbonementEmpty` — нечего списывать  
- `GroupCapacityExceeded`  
- `RescheduleNotAllowed`  
- `TrainingAlreadyCancelled`  

---

## 5. Application: сценарии

### 5.1. Команды (запись)

| Command | Соответствие ТЗ |
|---------|-----------------|
| `CreateGroup` / `UpdateGroup` | Планирование |
| `UpsertScheduleSlot` | Создание/смена слота + перегенерация «новых» тренировок |
| `CreateOneOffTraining` | Разовая тренировка |
| `AddMember` / `ChangeMembershipStatus` | Состав |
| `CreateAbonementFromPayment` | Confirm шлюза |
| `MarkAttendance` | Был / не был + списание/возврат |
| `CancelTraining` | Отмена + возврат списанного |
| `DeleteTraining` | Только без attendance |
| `CreateReschedule` / `CancelReschedule` | Доигровки |

Каждая папка сценария:

```
MarkAttendance/
  MarkAttendanceCommand.php
  MarkAttendanceHandler.php
```

Хендлер:
1. загружает репозитории по портам;
2. вызывает домен / политики;
3. сохраняет;
4. (опционально) диспатчит события после commit.

### 5.2. Запросы (чтение)

| Query | Назначение |
|-------|------------|
| `GetTrainingForCoach` | Карточка занятия: roster + отметки |
| `ListGroupTrainings` | Календарь/список группы |
| `GetAbonementBalance` | Кабинет игрока |
| `ListCoachTrainingsForDate` | «На завтра» для писем и UI |

Чтение может идти через Doctrine напрямую в query-хендлере или read-model — без обязательного зеркала write-модели.

### 5.3. Порты (интерфейсы)

```
Application/Port/
  TrainingGroupRepository.php
  ScheduleSlotRepository.php
  TrainingRepository.php
  GroupMembershipRepository.php
  AbonementRepository.php
  AttendanceRepository.php
  ProductRepository.php
  PlayerDirectoryPort.php          # exists / базовые поля для писем
  CoachDirectoryPort.php
  PaymentConfirmationPort.php      # или входящий DTO уже в Command
  MailNotifierPort.php
  ClockPort.php
  TransactionPort.php              # optional: явная обёртка транзакции
```

---

## 6. Infrastructure

| Область | Содержимое |
|---------|------------|
| `Persistence/Doctrine` | Entity mapping (атрибуты/XML/YAML), репозитории, миграции модуля |
| `Mail` | Реализация `MailNotifierPort` (Symfony Mailer) |
| `Payment` | Адаптер webhook → `CreateAbonementFromPaymentCommand` |
| `Clock` | Системные часы / тестируемая замена |

**Прагматика Symfony:** допустимо, чтобы Domain Model = Doctrine Entity с богатым поведением (без анемичных сеттеров).  
Отдельный слой «Doctrine Entity ≠ Domain» — только если появится реальная боль; на старте не усложнять.

---

## 7. UI

| Пакет | Акторы / вход |
|-------|----------------|
| `UI/Admin` | Группы, слоты, состав, продукты, ручные операции |
| `UI/Coach` | Список тренировок, посещаемость, отмена |
| `UI/Player` | Расписание группы, абонемент, доигровки |
| `UI/Webhook` | Confirm оплаты |
| `UI/Console` | Догенерация горизонта, one-off jobs, backfill |

Авторизация по ролям — на границе UI (security voters / attributes), до вызова хендлера или первым шагом хендлера для критичных инвариантов («тренер только своих»).

---

## 8. Пример потока: отметка «был»

```
Coach UI
  → MarkAttendanceCommand(trainingId, playerId, present=true)
  → MarkAttendanceHandler
       → TrainingRepository.get()
       → Attendance (create/load)
       → AbonementRepository.getForPlayerGroup()
       → attendance.markPresent()        # domain
       → abonement.debitOne()            # если только что debitApplied
       → persist + commit
       → dispatch AttendanceMarkedPresent
  → Mail listener (если нужно)
```

Отмена тренировки:

```
CancelTrainingHandler
  → training.cancel()
  → для каждой attendance с debitApplied: abonement.creditOne(); clear debit
  → commit
  → TrainingCancelled → email игрокам/тренеру
```

Генерация при Upsert слота:

```
UpsertScheduleSlotHandler
  → save slot
  → delete future Training where status=new and schedule_slot_id=...
  → ScheduleGenerationPolicy.generate(slot, clock, horizon=30 days)
  → persist new Training rows
```

---

## 9. Тестирование (минимум)

| Уровень | Что |
|---------|-----|
| Unit (Domain) | `Abonement.debitOne/creditOne`, смена статусов, `Training.cancel`, идемпотентность attendance |
| Unit (Policy) | Генерация 30 дней, roster, eligibility доигровки |
| Integration | Handler + Doctrine + транзакция (MarkAttendance, CancelTraining, CreateAbonementFromPayment) |
| Application smoke | Webhook confirm → абонемент + membership |

UI E2E — по возможности позже; ядро ценности — domain + handlers.

---

## 10. Анти-паттерны (явный запрет на старте)

1. `TrainingManager` / `AbonementHelper` с десятками несвязанных методов.  
2. Списание в `Doctrine\EventSubscriber` на любой `flush`.  
3. Общий `BaseModel` с `source`, soft magic behaviors.  
4. Дублирование write/read моделей «на всякий CQRS».  
5. Поля и ветки MIX/front в агрегатах «потом пригодится».  
6. Письма и HTTP-клиенты внутри `Entity`.

---

## 11. Связь с документами

| Документ | Роль |
|----------|------|
| [tz-trainings-business.md](./tz-trainings-business.md) | Что система делает; глоссарий |
| [tz-trainings-schema.md](./tz-trainings-schema.md) | Таблицы и ограничения |
| **Этот документ** | Как режем код и где живут правила |

При конфликте: сначала правим бизнес-ТЗ, потом схему и архитектуру.

---

## История

| Дата | Решение |
|------|---------|
| 2026-08-01 | Черновик архитектуры: слои, принципы, скелет классов, порядок срезов |
