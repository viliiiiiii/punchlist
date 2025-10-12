# Punch List App

A self-hosted hotel punch list manager built with React (Vite) on the frontend and an Express API on the backend. Tasks, uploads, and asynchronous work are coordinated entirely on your own VPS stack (MySQL + MinIO + RabbitMQ + cron).

## Getting started

```bash
npm install
npm run migrate   # applies SQL migrations to MySQL
npm run server    # starts the Express API
npm run worker    # starts the RabbitMQ task worker
```

The React UI can still be run locally via `npm run dev`, but all persistent storage now flows through the backend services configured via `.env`.

## Infrastructure overview

```
React UI  ──▶ Express API ──▶ MySQL (task state)
   │              │
   │              ├─▶ MinIO (presigned uploads/downloads)
   │              └─▶ RabbitMQ (task queue)
   │                               │
   │                               └─▶ Worker (Node.js) ──▶ stdout (forward to your logging stack)
   │
Cron/systemd timers ──▶ enqueue-task CLI ──▶ RabbitMQ
```

### Environment variables

| Variable | Description |
| --- | --- |
| `MYSQL_HOST` | MySQL host address. |
| `MYSQL_PORT` | MySQL port (default `3306`). |
| `MYSQL_USER` | Database user. |
| `MYSQL_PASSWORD` | Database password. |
| `MYSQL_DATABASE` | Database name. |
| `MYSQL_POOL_MIN` / `MYSQL_POOL_MAX` | Pool sizing hints for the API/worker. |
| `MINIO_ENDPOINT` | MinIO endpoint (including protocol and port). |
| `MINIO_ACCESS_KEY` / `MINIO_SECRET_KEY` | MinIO credentials. |
| `MINIO_BUCKET` | Bucket used for uploads. |
| `MINIO_PREFIX` | Key prefix for uploaded assets (default `uploads`). |
| `PRESIGN_PUT_EXPIRES` / `PRESIGN_GET_EXPIRES` | Expiration (seconds) for presigned URLs. |
| `AMQP_URL` | RabbitMQ connection URI. |
| `ALLOWED_IMAGE_TYPES` | Allowed MIME types for uploads. |
| `MAX_UPLOAD_BYTES` | Maximum upload size (bytes). |
| `ALLOWED_ORIGIN` | Frontend origin allowed by CORS. |

Copy `.env.example` to `.env` and adjust the values for your environment.

### MinIO usage

1. Create the bucket specified in `MINIO_BUCKET` (e.g. with `mc mb local/my-bucket`).
2. Grant the API credentials read/write access to that bucket.
3. Verify credentials locally:
   ```bash
   mc alias set local $MINIO_ENDPOINT $MINIO_ACCESS_KEY $MINIO_SECRET_KEY
   mc ls local/$MINIO_BUCKET
   ```

### RabbitMQ & cron

The API publishes every new task to the `app.tasks` exchange. The bundled worker listens on the `app.tasks.default` queue and executes handlers defined in `server/src/workers/task-handlers.js`.

Schedule recurring jobs with cron or systemd timers via the CLI:

```bash
npm run enqueue-task -- --type heartbeat.ping --payload '{}'
```

Example cron entries:

```
*/10 * * * * /usr/bin/node /srv/app/server/bin/enqueue-task.js --type heartbeat.ping --payload '{}'
0 3 * * *   /usr/bin/node /srv/app/server/bin/enqueue-task.js --type image.cleanup --payload '{"days":30}'
```

## Image uploads (MinIO presign)

Request a presigned PUT URL and upload directly to MinIO:

```bash
curl -X POST $API/presign/upload \
  -H "Content-Type: application/json" \
  -d '{"contentType":"image/png","fileSize":12345}'
```

The response includes `{ "url", "key" }`. Upload with the returned URL:

```
PUT <url>
Content-Type: image/png
```

To obtain a download link:

```bash
curl -X POST $API/presign/download \
  -H "Content-Type: application/json" \
  -d '{"key":"uploads/demo-user/01H..."}'
```

The API validates ownership (the key must start with `MINIO_PREFIX/<userId>/`) before issuing a short-lived presigned GET URL.

## Database migrations & scripts

* `npm run migrate` — applies SQL migrations in `server/migrations/` to provision `tasks` and `task_runs` tables.
* `server/scripts/migrate-local-uploads-to-minio.js` — scans a directory of legacy files and uploads them to MinIO. Supports `--dry-run` and prints CSV progress.
* `server/scripts/migrate-local-tasks-to-mysql.js` — imports a JSON dump of tasks into MySQL. Pass `--enqueue` to republish pending work to RabbitMQ.

## Worker lifecycle

The worker consumes messages from RabbitMQ, updates MySQL task state, and records each attempt in `task_runs`. It honors cancellation flags, retries until `max_attempts`, and logs failures to stdout so you can ship logs using your preferred VPS tooling.

## Testing

Run all unit tests (no `supertest` usage anywhere):

```bash
npm test
```

The suite covers API validation, MinIO presign logic (via mocks), task idempotency, and worker retry behavior.
