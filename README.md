# Product Catalog

Ласкаво просимо до **Product Catalog** — сучасного веб-додатка для перегляду та фільтрації товарів! Це потужний каталог із динамічними фільтрами за категоріями, брендами та іншими параметрами, побудований на **Laravel** з можливітю імпорту XML файлів великих обсягів.

## Основні можливості
- 🛍️ **Фільтрація товарів**: Вибирайте категорії, бренди та інші параметри з миттєвим оновленням.
- 🔄 **Асинхронне завантаження**: AJAX-запити до API для швидкого рендеру фільтрів і товарів.
- 🕒 **Кешування з Redis**: Оптимізація фільтрів через Redis `SINTERSTORE` для перетину множин.
- 📄 **Пагінація**: Зручна навігація по сторінках товарів.

## Технології
- **Backend**: PHP 8.3, Laravel 11.x
- **Frontend**: Blade, Tailwind CSS, Vanilla JavaScript
- **Database**: MySQL 
- **Cache**: Redis 
- **Containerization**: Docker, Laravel Sail
- **API**: RESTful ендпоінти (`/api/catalog/filters`, `/api/catalog/products`)

## Вимоги
- **Docker** (Docker Desktop для Windows/Mac або Docker на Linux)
- **Docker Compose**
- **Git**
- **Composer** (для локального встановлення залежностей, якщо потрібно)

### 1. Встановлення

1. Скопіюйте .env.example у .env:
cp .env.example .env

2. Запустіть Docker-контейнери:./vendor/bin/sail up -d
Це підніме сервіси (Laravel, MySQL, Redis тощо) у фоновому режимі.

3. Згенеруйте ключ додатка
./vendor/bin/sail artisan key:generate

4. Виконайте міграції
./vendor/bin/sail artisan migrate

5. Імпорт XML файла
./vendor/bin/sail artisan import:products storage/app/xml/Ваш_файл.xml
(файл повинен бути в корені проекту наприклад в storage/app/xml/)

6. Доступ до додатка

Відкрийте браузер за адресою http://localhost.
API доступні на:
Фільтри: http://localhost/api/catalog/filters
Товари: http://localhost/api/catalog/products

Використання

Відкрийте http://localhost у браузері.
Використовуйте фільтри зліва для вибору категорій, брендів або інших параметрів.
Сортуйте товари за ціною (за зростанням/спаданням).
Натисніть "Скинути фільтри" для очищення всіх фільтрів.
Переглядайте товари з пагінацією.

Команди Sail:

Запустити контейнери:./vendor/bin/sail up -d


Зупинити контейнери:./vendor/bin/sail down


Виконати Artisan-команди:./vendor/bin/sail artisan <command>


Запустити npm:./vendor/bin/sail npm run dev


Доступ до контейнера:./vendor/bin/sail shell

Налагодження

Логи Laravel:
./vendor/bin/sail artisan tail

Або перевірте storage/logs/laravel.log.

Автор: Anatoliy Perkov

Happy coding with me! ⛵```
