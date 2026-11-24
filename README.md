# Juvelyrinis darbas — Savoy Child

Дочерняя тема для литовского ювелирного каталога на WordPress, Savoy и WooCommerce. Страницы редактируются в WPBakery; отправку запросов планируется интегрировать с Contact Form 7.

## Начать работу

1. Открой в редакторе именно папку `savoy-child`.
2. Прочитай [AGENTS.md](AGENTS.md) — основной источник требований проекта.
3. Посмотри [аудит](docs/audit-2026-11-24.md), [план](docs/roadmap.md) и [открытые решения](docs/decisions.md).
4. Для Cursor используй [стартовый промпт](docs/cursor-start.md). Проектное правило находится в `.cursor/rules/project.mdc`.

Не запускай развёртывание WordPress заново: проект уже существует в Local. Не устанавливай Docker, ACF или другой page builder вместо имеющегося стека.

## Документация

| Файл | Назначение |
| --- | --- |
| [Архитектура](docs/architecture.md) | Предлагаемые модули и поток данных |
| [Решения](docs/decisions.md) | Подтверждённое, предложения, вопросы |
| [План](docs/roadmap.md) | Порядок задач и критерии готовности |
| [Savoy / WooCommerce](docs/workflows/savoy-woocommerce.md) | Каталог и ограничения покупки |
| [WPBakery](docs/workflows/wpbakery.md) | Редактируемые страницы и баннеры |
| [WP-CLI / Local](docs/workflows/wp-cli-local.md) | Подключение и безопасное чтение БД |
| [Импорт](docs/workflows/import.md) | Сопоставление изделий, медиа и URL |
| [Панель запроса](docs/workflows/inquiry.md) | Серверная проверка и интеграция CF7 |
| [SCSS и сборка](docs/workflows/styles.md) | Переменные, компоненты, minified CSS и watch |

## Стили

Нужен Node.js >=20.19.0. Из корня темы:

```sh
npm ci
npm run build
```

Для разработки запусти `npm run dev` (или `npm run watch`) и оставь процесс работающим. Изменения SCSS автоматически пересобирают CSS; страницу WordPress нужно обновить в браузере. Перед commit/deploy выполни `npm run build` для production-файла без source map.

Исходники: `assets/scss/main.scss`, общие токены — `settings/_tokens.scss`, компоненты — `components/_*.scss`. Подключаемый результат — `assets/css/main.min.css`; его нужно хранить в Git и включать в тему при передаче на сервер. `style.css` сохраняет метаданные темы. Скомпилированный CSS вручную не редактируется.

## Текущее состояние

Подготовлены проектные инструкции, литовский словарь Savoy и компонентная SCSS-сборка. Первый набор стилей — скругления hero и стандартных кнопок. `functions.php` подключает собранные стили с версией по времени изменения файла; формы запросов ещё не реализованы.

При первоначальном аудите 2026-11-24 `.git` отсутствовал, а удалённый репозиторий был пуст. Git инициализирован в этой папке с начальной веткой `chore/project-foundation`, origin `https://github.com/vivikiwi-web/wp-juvelyrinisdarbas.git`. Текущую ветку проверяй через `git status`.

Версии и состояние БД в аудите — снимок на дату проверки. Параметры Local и доступность источников нужно перепроверять при изменении окружения.
