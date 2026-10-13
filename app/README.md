# Chirak control plane (Laravel 12, Livewire 3, Tailwind v4)

Orchestrates the GPU worker described in `../docs/worker-api.md`.

```bash
cp .env.example .env && php artisan key:generate
docker compose up -d                 # MariaDB on 33071, Redis on 63801
composer install && npm install && npm run build
php artisan migrate
php artisan serve                    # UI on http://127.0.0.1:8000
php artisan horizon                  # queue worker (separate terminal)
php artisan test                     # SQLite in memory, no Docker needed
```

Set `WORKER_BASE_URL` / `WORKER_BEARER_TOKEN` (SSH tunnel to the pod), `ANTHROPIC_API_KEY` and `DEMO_TOKEN` in `.env`.
Starting a run, approving the review checkpoint and the playground need the demo token.
