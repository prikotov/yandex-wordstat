# Справка по API: Yandex Search API v2 (Wordstat)

Проверено живыми запросами 13.11.2026. Старое Wordstat API (`api.wordstat.yandex.net`,
OAuth client_id/client_secret) закрыто Яндексом — не использовать.

## Подключение

- Base URL: `https://searchapi.api.cloud.yandex.net/v2/wordstat`
- Метод: POST, заголовки `Authorization: Api-Key <key>`, `Content-Type: application/json`.
- Аутентификация: API-ключ сервисного аккаунта Yandex Cloud со scope `yc.search-api.execute`;
  аккаунту нужна роль `search-api.webSearch.user` на каталог; к облаку привязан billing.
- `folderId` — обязательное поле тела каждого запроса.

## Методы

| Метод | Путь | Назначение |
|---|---|---|
| TopRequests | `/topRequests` | Частотность фразы + вложенные запросы (топ) |
| Dynamics | `/dynamics` | Динамика показов по периодам |
| Regions | `/regions` | Распределение показов по регионам |
| GetRegionsTree | `/getRegionsTree` | Дерево регионов (id, имя, родитель) |

Асинхронных методов у Wordstat API нет. Отложенный режим (`/v2/web/searchAsync`,
25 ₽/1000) относится к веб-поиску, не к Wordstat; polling операций — на отдельном
хосте `https://operation.api.cloud.yandex.net/operations/{id}`.

## Форматы тел (wire format)

- `phrase` — строка, обязательна.
- `numPhrases` — **строка** с числом 1–2000 (int64 в proto); для `/regions` лимит 1–50.
- `regions` — массив **строк** с ID регионов (например `["225"]`), не чисел.
- `period` — enum: `PERIOD_DAILY`, `PERIOD_WEEKLY`, `PERIOD_MONTHLY`.
- Даты — RFC3339: `YYYY-MM-DDT00:00:00Z`.
- `devices` — enum: `DEVICE_DESKTOP`, `DEVICE_PHONE`, `DEVICE_TABLET` (опционально).

Окна дат (валидация API):
- daily: не более 60 дней, завершённые дни (клиент использует 59);
- weekly: понедельник–воскресенье, только завершённые недели;
- monthly: только завершённые месяцы.

## Форматы ответов

- `/topRequests`: `results[]` и `associations[]` с полями `phrase`, `count`; `totalCount`.
- `/dynamics`: `results[]` с `date`, `count`, `share`.
- `/regions`: `results[]` с `region` (ID региона), `count`, `share`, `affinityIndex`.
- Счётчики (`count`, `totalCount`) — **строки** (int64), приводи к int при расчётах.
- Имена регионов в `/regions` не приходят — резолви через `/getRegionsTree` (клиент кэширует).

## Тарифы и лимиты

- Wordstat (синхронные методы): 480 ₽ за 1000 запросов; free tier на v2 не действует.
- Батчи лимитируй: dozens запросов ≈ 10–25 ₽; следи за балансом облака.
- Пустой `results` = фраза ниже порога отображения Wordstat, не ошибка.

## Типичные ошибки

| HTTP | Причина | Действие |
|---|---|---|
| 401 | неверный/отозванный ключ | проверь ключ и scope |
| 403 `PermissionDenied` | нет роли `search-api.webSearch.user` у сервисного аккаунта ключа | назначь роль на аккаунт (не на пользователя!) |
| 400 | неверный wire-формат (числа вместо строк, даты не RFC3339, окно дат) | сверь с разделом «Форматы тел» |
| 404 на `/v2/operations/*` | операции живут на другом хосте | `operation.api.cloud.yandex.net` |

## Источники

- Официальная документация: https://yandex.cloud/ru/docs/search-api/
- MIT-референс wire-формата: https://github.com/stufently/yandex-mcp