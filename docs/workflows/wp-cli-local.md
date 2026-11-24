# WP-CLI в Local

Сначала прочитай [AGENTS.md](../../AGENTS.md). Данные ниже проверены 2026-11-24; пути, site ID и версии перепроверяются после изменения Local.

## Окружение

```sh
WP_ROOT='/Users/vitalijvirsulo/Local Sites/juvelyrinisdarbas/app/public'
LOCAL_RUN='/Users/vitalijvirsulo/Library/Application Support/Local/run/_dumnQz1T'
LOCAL_SERVICES='/Users/vitalijvirsulo/Library/Application Support/Local/lightning-services'
WP_AUDIT_PHP="$LOCAL_SERVICES/php-8.2.29+0/bin/darwin-arm64/bin/php"
LOCAL_MYSQL_BIN="$LOCAL_SERVICES/mysql-8.4.0+2/bin/darwin-arm64/bin"
```

Это переменные текущего shell, не настройки WordPress. CLI PHP 8.2.29 использован для чтения БД из-за предупреждений WP-CLI на 8.5; это не рекомендация менять PHP сайта или запускать всю его логику на другой версии. Local был настроен на PHP 8.5.13, системный `wp` использовал PHP 8.5.1.

Проверь `command -v wp`, `wp --info`, существование binary, сокета и путь к сайту. Не выводи `wp-config.php`, полное окружение, пароли, salts или авторизационные данные. Не меняй `DB_HOST` ради подключения CLI.

## Проверенный пример чтения

Команда использует MySQL-клиент Local и правильный сокет, не загружает тему/плагины, ограничивает SQL транзакцией только на чтение:

```sh
MYSQL_UNIX_PORT="$LOCAL_RUN/mysql/mysqld.sock" \
PATH="$LOCAL_MYSQL_BIN:$PATH" \
"$WP_AUDIT_PHP" /usr/local/bin/wp --path="$WP_ROOT" db query '
START TRANSACTION READ ONLY;
SELECT option_name, option_value
FROM wp_options
WHERE option_name IN ("template", "stylesheet", "show_on_front", "page_on_front");
COMMIT;
'
```

Префикс `wp_` был проверен для этой установки; перед применением к другой базе проверь префикс. Используй явные разрешённые поля, а не `SELECT *`. Даже в READ ONLY не выводи клиентские данные и секретные опции.

SQL через `wp db query` подходит для исследования. Для записи товаров, страниц и настроек используй API WordPress/WooCommerce после поручения на соответствующие изменения. `wp eval`, `wp option`, `wp post` и HTTP-запросы к динамическим страницам могут загружать WordPress и выполнять побочные действия плагинов; это иной режим, чем прямой SELECT.

## Диагностика

- Ошибка `/tmp/mysql.sock`: проверь `MYSQL_UNIX_PORT` и правильный binary MySQL.
- Ошибка доступа к сокету: проверь работающий Local и ограничения окружения; не делай `chmod`, не меняй пользователей БД и конфиг наугад.
- Отсутствующий сокет/порт: сообщи, что сайт не запущен или окружение изменилось. Не создавай новый сайт вместо существующего.
- Доступ через connector, shell или браузер проверяется отдельно; успешный доступ одним способом не подтверждает остальные.

Перед разрешённой записью: точный список данных, проверенное восстановление вне Git, пробное выполнение и сравнение результата. В отчёте отделяй изменения БД от изменений файлов. Письма проверяй через подтверждённый перехватчик Local; наличие конфигурации Mailpit само по себе не подтверждает доставку.
