import { Client } from "minio";
import { ulid } from "ulid";
import { URL } from "node:url";

const endpoint = process.env.MINIO_ENDPOINT || "http://127.0.0.1:9000";
const accessKey = process.env.MINIO_ACCESS_KEY || "";
const secretKey = process.env.MINIO_SECRET_KEY || "";
export const bucket = process.env.MINIO_BUCKET || "";
export const prefix = process.env.MINIO_PREFIX || "uploads";
const putExpiry = Number.parseInt(process.env.PRESIGN_PUT_EXPIRES || "60", 10);
const getExpiry = Number.parseInt(process.env.PRESIGN_GET_EXPIRES || "300", 10);

const parsed = new URL(endpoint);

export const minio = new Client({
  endPoint: parsed.hostname,
  port: parsed.port ? Number.parseInt(parsed.port, 10) : parsed.protocol === "https:" ? 443 : 80,
  useSSL: parsed.protocol === "https:",
  accessKey,
  secretKey,
});

if (!bucket) {
  console.warn("MINIO_BUCKET is not configured. Presign endpoints will fail until it is set.");
}

export function makeKey({ userId, extension }) {
  const id = ulid();
  const safeExt = extension ? extension.replace(/[^a-z0-9]/gi, "") : "";
  const suffix = safeExt ? `.${safeExt}` : "";
  return `${prefix}/${userId}/${id}${suffix}`;
}

export async function getPresignedPutUrl({ key, contentType, expires = putExpiry }) {
  return minio.presignedPutObject(bucket, key, expires, {
    "Content-Type": contentType,
  });
}

export async function getPresignedGetUrl({ key, expires = getExpiry }) {
  return minio.presignedGetObject(bucket, key, expires);
}

export async function deleteObject({ key }) {
  return minio.removeObject(bucket, key);
}
