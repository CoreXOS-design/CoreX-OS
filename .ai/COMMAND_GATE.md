# Command Gate (Local Dev)

Allowed without asking:
- php -l <file>
- php artisan view:clear
- php artisan route:clear
- php artisan cache:clear
- php artisan config:clear
- php artisan optimize:clear
- php artisan test
- composer dump-autoload
- composer install (local)
- npm install / npm run build (local)

Requires explicit approval:
- php artisan migrate
- php artisan migrate:fresh --seed
- Any command that deletes data
- Modifying .env beyond local configuration
- Anything touching production servers