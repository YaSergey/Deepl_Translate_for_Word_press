# Polylang Mass Translation with DeepL

Плагин WordPress добавляет массовый перевод постов и товаров через Polylang с помощью API DeepL. Дополнительно переводит Oxygen Builder шаблоны, меню навигации, ACF поля и SEO-метаданные Yoast/RankMath, поддерживая REST/CLI вызовы.

## Установка
1. Скопируйте файлы плагина в каталог `wp-content/plugins/polylang-mass-translation-deepl`.
2. Активируйте плагин в панели **Плагины**.
3. Убедитесь, что Polylang установлен и активирован, а языки исходного и целевого перевода уже созданы.

## Настройка движка перевода
1. Перейдите в **Настройки → Mass Translation**.
2. Выберите движок: **DeepL** или **Google Cloud Translation**. DeepL даёт более “литературный” стиль для европейских языков, Google покрывает больше языков и гибко работает с кодировками.
3. Для DeepL укажите API Key и URL (`https://api-free.deepl.com/v2/translate` для бесплатного аккаунта или `https://api.deepl.com/v2/translate` для Pro) и нажмите **Сохранить**.
4. Для Google Cloud укажите Project ID, Location (обычно `global` или `us-central1`) и либо API key, либо JSON ключ сервисного аккаунта (рекомендуется для продакшена; ключ хранится в базе и конвертируется в короткоживущий токен).
5. Укажите целевой язык перевода страниц (используется для автоматического и ручного перевода опубликованных страниц в черновики).
6. Проверьте, что в выпадающих списках выбраны нужные языки, а переводимые части (заголовок, контент, краткое описание) отмечены галочками.
7. Выберите статус создаваемых переводов (черновик, опубликовано и т. д.), чтобы понимать, где искать результат.
8. Сохраните настройки.

## Проверка работоспособности
1. На странице настроек нажмите **Проверить подключение к DeepL** — при успешном ответе появится сообщение с лимитом символов или возможной ошибкой авторизации.
2. В настройках нажмите **Перевести опубликованные страницы**, чтобы создать черновики переводов всех опубликованных страниц на выбранный язык. Новые страницы будут переводиться автоматически раз в час через cron.
3. Для полного перевода сайта включите опцию «Перевести весь сайт» и запустите действие **Перевести весь сайт** (страницы, шаблоны, меню, ACF, SEO).
3. Откройте список постов или товаров и отметьте несколько записей.
4. Нажмите **Создать переводы (DeepL)** или выберите одноимённое действие в выпадающем списке массовых действий.
5. После завершения обновите список: для целевых языков должны появиться переводы в выбранном статусе. Содержимое и краткое описание должны быть переведены DeepL.
6. При проблемах проверьте журнал (`log.txt`) и права доступа на запись в каталог плагина.

## CLI

```
wp deepl translate-all --lang=fr --provider=google
```

## REST webhook

`POST /wp-json/deepl/v1/translate` с параметрами `lang`, `page_ids` и `template_ids`.

## Журнал
Файл `log.txt` в корне плагина содержит отладочную информацию, записанную методом `toLog`.


## Architecture and safety
- DeepL client now runs through a rate limiter (requests + characters/minute) with configurable thresholds and logs when nearing a pause.
- Jobs are tracked via an internal job manager that stores status, language and affected entities; previews persist in a transient for review before writing.
- Rollback deletes created translations and restores captured metadata/menu labels when possible.
- Translation rules let you include/exclude post types, IDs, ACF keys or component selectors; an advanced JSON block allows power-user overrides.
- Style controls (formality + glossary) are passed to DeepL through the `pmt_deepl_style_args` filter and `deepl_pre_translate_text` hook.

## Testing workflow
1. Run a **предпросмотр** (dry-run) from the settings page. Review the preview table.
2. Apply the preview or rerun with adjustments.
3. Validate translated pages/templates/menus on staging.
4. Download `log.txt` for audit if needed.
5. Use the rollback action on the last job if smoke tests fail.
