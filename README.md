## Northstar Authentication Starter

A Vite + React authentication frontend backed by a PHP 8.2 JSON API, PDO SQLite, and secure PHP sessions. In this workspace the existing repository root is the frontend; `backend/` is the API project alongside it.

## Requirements

- Node.js 20.19+ or 22.12+
- PHP 8.2+
- PHP extensions: `pdo`, `pdo_sqlite`, `session`, `json`, and `mbstring`
- PHP `curl` extension to run the API test script

Confirm PHP extensions with `php -m`. The SQLite database is created automatically on the first API request. By default it lives in a project-specific directory under PHP's system temporary directory, outside both served roots. Set `AUTH_DATABASE_PATH` to a durable, private location when needed.

## Start Locally

Open two terminals in the repository root.

Terminal 1, start the PHP API:

```powershell
cd backend
$env:AUTH_FRONTEND_ORIGIN = 'http://localhost:5173'
$env:AUTH_COOKIE_SECURE = 'false'
php -S localhost:8000 router.php
```

Terminal 2, install and start the frontend:

```powershell
npm install
npm run dev
```

Open the Vite URL, normally `http://localhost:5173`. Vite proxies `/api/*` to `http://localhost:8000`, so the browser uses same-origin requests and session cookies. The root redirects to `/login`.

The frontend's optional `.env` value is documented in `.env.example`. Leave `VITE_API_BASE_URL` blank to use the Vite proxy; set it to an absolute API origin only when intentionally making cross-origin requests. PHP does not load `.env` files automatically: set backend environment variables in the process manager or web server. `backend/.env.example` lists the supported values.

## API

All request and response bodies are JSON. State-changing requests require a session-bound token from `GET /api/csrf`, sent as `X-CSRF-Token`.

- `POST /api/register`: accepts `name`, `email`, `password`, and `password_confirmation`; creates a user and returns `201`, or `409` for an existing email and `422` for field errors.
- `POST /api/login`: accepts `email`, `password`, and optional `remember_me`; returns the sanitized user and a rotated CSRF token. Invalid credentials receive the same `401` message.
- `GET /api/me`: returns the current sanitized user or `401`.
- `POST /api/logout`: invalidates the session and clears its cookie.
- `GET /api/csrf`: starts/continues the session and issues the token used by the frontend.

Login sessions expire after 30 minutes of inactivity; “Remember me” extends the absolute session lifetime and cookie to 30 days. Login and registration have per-IP SQLite rate limits. Passwords use `password_hash()` and are verified with `password_verify()`; they are never returned to the frontend or stored in browser storage.

## API Tests

Keep the PHP server running in Terminal 1, then run from the repository root:

```powershell
npm run test:api
```

The script uses an isolated cookie jar and covers registration, validation, duplicate email, generic login errors, login, session lookup, CSRF rejection, logout, and unauthenticated access. Set `AUTH_TEST_BASE_URL` to use a different test server URL.

## Security and Deployment

- Set `APP_ENV=production`, `AUTH_FRONTEND_ORIGIN` to the exact trusted HTTPS frontend origin, and `AUTH_COOKIE_SECURE=true` in production.
- Serve the API over HTTPS. Do not use PHP's built-in server outside local development.
- Set `AUTH_DATABASE_PATH` to a durable path outside every web document root. Restrict filesystem permissions and back up the database securely. The development default is outside the workspace; `backend/database/.htaccess` adds defense in depth if the directory is used manually.
- Configure PHP session storage and garbage collection for the deployment. The application enforces 30-minute idle expiration and 30-day remembered-session expiration itself.
- The built-in SQLite rate limiter is appropriate for a single local instance. Multi-instance deployments need a shared rate-limit store and a database/locking strategy designed for that topology.
- Configure the reverse proxy/web server to serve the frontend and proxy `/api` to PHP under the same site when possible. If deploying cross-origin, keep credentialed CORS restricted to the exact origin and configure cookie `SameSite`/domain policy for that deployment.
- Configure TLS termination, security headers, monitoring, backups, and account recovery/email verification before production. No OAuth provider or password-recovery endpoint is included.
- The PHP development server router returns JSON for unknown paths and never falls through to serving database files. In production, explicitly deny access to backend source and database files at the web-server layer too.

## Troubleshooting

- `could not find driver`: enable `pdo_sqlite` in the active PHP configuration, then restart PHP.
- API connection errors: confirm PHP is listening on port `8000`; check `php -m` and the Vite proxy target.
- CORS `403`: set `AUTH_FRONTEND_ORIGIN` to the exact origin shown in the browser, including scheme and port.
- CSRF `419`: reload the frontend so it can request a new `/api/csrf` token; check that browser cookies are enabled and both requests use the same host (`localhost` versus `127.0.0.1` creates different cookies).
- SQLite permission errors: set `AUTH_DATABASE_PATH` to a directory writable by the PHP process and outside the web root.
- Port already in use: start Vite on another port and update `AUTH_FRONTEND_ORIGIN`; or choose another PHP port and update `vite.config.js`'s proxy target.

Currently, two official plugins are available:

- [@vitejs/plugin-react](https://github.com/vitejs/vite-plugin-react/blob/main/packages/plugin-react) uses [Oxc](https://oxc.rs)
- [@vitejs/plugin-react-swc](https://github.com/vitejs/vite-plugin-react/blob/main/packages/plugin-react-swc) uses [SWC](https://swc.rs/)

## React Compiler

The React Compiler is not enabled on this template because of its impact on dev & build performances. To add it, see [this documentation](https://react.dev/learn/react-compiler/installation).

## Expanding the ESLint configuration

If you are developing a production application, we recommend using TypeScript with type-aware lint rules enabled. Check out the [TS template](https://github.com/vitejs/vite/tree/main/packages/create-vite/template-react-ts) for information on how to integrate TypeScript and [`typescript-eslint`](https://typescript-eslint.io) in your project.
"# ViteJSBlank" 
"# ViteJSBlank_SQLITE" 
