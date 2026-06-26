
# 🚀 Project Setup (Laravel 12)

This project is built using **Laravel 12**. Follow these steps to set it up locally:

### ✅ Requirements
- **PHP 8.1 or 8.2**
- **Composer**
- **PostgreSQL**

### 📥 Installation Steps
```bash
# 1. Clone the project
git clone https://gitlab.com/ekahal-clients/sensei-php-api.git

# 2. Go inside the project folder
cd sensei-php-api

# 3. Install PHP dependencies
composer install

# 4. Copy .env file
cp .env.example .env

# 5. Generate application key
php artisan key:generate

# 6. Configure database in .env
DB_DATABASE=your_database
DB_USERNAME=your_username
DB_PASSWORD=your_password

# 9. Start the local server
php artisan serve
