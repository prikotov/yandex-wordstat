---
name: yandex-wordstat
description: Анализ поисковых запросов через Яндекс.Wordstat
---

## Когда использовать

- Подбор ключевых слов для SEO и контекстной рекламы
- Анализ частотности поисковых запросов
- Поиск похожих запросов
- Анализ сезонности запросов

## Запуск

```bash
php .opencode/skills/yandex-wordstat/wordstat.php [опции] <фраза>
```

### Обязательный параметр

- `фраза` — поисковый запрос для анализа (в кавычках если есть пробелы)

### Опции

| Опция | Сокращение | Описание | Значения | По умолчанию |
|-------|------------|----------|----------|--------------|
| `--geo` | `-g` | Регион поиска | ID региона (например: 1 - Москва, 2 - СПб) | без региона |
| `--type` | `-t` | Тип отчёта | `freq`, `similar`, `history` | `freq` |
| `--limit` | `-l` | Лимит записей | число (например: 10, 20, 50) | все записи |
| `--period` | `-p` | Период для history | `daily`, `weekly`, `monthly` | `monthly` |
| `--count` | `-c` | Количество периодов | число (для history) | 12 |

### Типы отчётов

| Параметр | Описание |
|----------|----------|
| `freq` | Частотность фразы (по умолчанию) |
| `similar` | Похожие запросы |
| `history` | Динамика показов (день/неделя/месяц) |

### Примеры

```bash
# Частотность фразы
php .opencode/skills/yandex-wordstat/wordstat.php "купить ноутбук"

# Частотность для Москвы (geo=1)
php .opencode/skills/yandex-wordstat/wordstat.php -g 1 "веб-разработка"

# Похожие запросы
php .opencode/skills/yandex-wordstat/wordstat.php --type similar "seo продвижение"

# Динамика по дням за 30 дней
php .opencode/skills/yandex-wordstat/wordstat.php -t history -p daily -c 30 "opencode"

# Динамика по неделям за 8 недель
php .opencode/skills/yandex-wordstat/wordstat.php -t history -p weekly -c 8 "ai"

# Топ-20 похожих запросов
php .opencode/skills/yandex-wordstat/wordstat.php -t similar -l 20 "маркетинг"
```

## Результат

`wordstat_reports/YYYY-MM-DD/`:
- `wordstat_YYYY-MM-DD_HH-MM-SS.csv` / `.md` — данные Wordstat

### Поля в отчёте freq/similar

| Поле | Описание |
|------|----------|
| `phrase` | Поисковая фраза |
| `shows` | Показы за месяц |

### Поля в отчёте history

| Поле | Описание |
|------|----------|
| `date` | Дата (YYYY-MM-DD) |
| `shows` | Показы |
| `share` | Доля от всех запросов (%) |

## Требования

- Приложение Яндекс.OAuth с доступом к API Вордстата
- Токен авторизации

## Ограничения API

⚠️ API Вордстата имеет лимиты на количество запросов. При частом использовании возможно временное ограничение.
