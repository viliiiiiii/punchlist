# Punch List App

A local-only hotel punch list manager built with React (Vite).

## Getting started

```bash
npm install
npm run dev
```

Open the development server URL printed in the terminal. Data is stored in your browser's `localStorage`.

## Features
- Rooms-first workflow with building and section filters
- Kanban board grouped by task status
- Dashboard KPIs, top sections/rooms, and recent activity
- Task modal with photo capture/upload and client-side resizing
- JSON import/export, demo data seeding, and local data clearing
- PDF exports (room, building, all) using jsPDF + autotable with HTML fallback

## Image Uploads (S3)

Configure the optional presign backend to store images in a private S3 bucket using short-lived pre-signed URLs.

| Environment Variable | Description |
| --- | --- |
| `AWS_REGION` | AWS region where the S3 bucket resides. |
| `S3_BUCKET` | Name of the private S3 bucket used for uploads. |
| `ALLOWED_IMAGE_TYPES` | Comma-separated list of allowed MIME types for uploads. |
| `MAX_UPLOAD_BYTES` | Maximum upload size in bytes (e.g., `5242880` for 5&nbsp;MB). |
| `ALLOWED_ORIGIN` | Frontend origin allowed to call the presign endpoints. |

### Requesting an upload URL

```bash
curl -X POST $API/presign/upload \
  -H "Content-Type: application/json" \
  -d '{"contentType":"image/png","fileSize":12345}'
```

The response includes the `url` and `key`. Upload the file with the returned `url`, ensuring these headers are set:

```
Content-Type: <mime>
x-amz-server-side-encryption: AES256
```

### Requesting a download URL

```bash
curl -X POST $API/presign/download \
  -H "Content-Type: application/json" \
  -d '{"key":"uploads/demo-user/uuid"}'
```

The response contains a short-lived `url` that can be used to retrieve the object.
