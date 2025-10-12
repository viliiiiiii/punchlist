import { Router } from "express";
import rateLimit from "express-rate-limit";
import { makeKey, getPresignedPutUrl, getPresignedGetUrl, prefix } from "../lib/storage.js";
import { getAuthenticatedUserId } from "../lib/auth.js";

const router = Router();

const limiter = rateLimit({
  windowMs: 60_000,
  limit: 10,
  standardHeaders: "draft-7",
  legacyHeaders: false,
});

const allowedTypes = new Set(
  (process.env.ALLOWED_IMAGE_TYPES || "image/jpeg,image/png,image/webp")
    .split(",")
    .map((type) => type.trim())
    .filter(Boolean)
);

const MAX_BYTES = Number.parseInt(process.env.MAX_UPLOAD_BYTES || "5242880", 10);

function validateUploadPayload(body) {
  const errors = {};
  if (!body || typeof body !== "object") {
    return { valid: false, errors: { message: "Body must be an object" } };
  }
  const { contentType, fileSize } = body;
  if (typeof contentType !== "string" || !contentType.trim()) {
    errors.contentType = "contentType is required";
  }
  if (!Number.isInteger(fileSize) || fileSize <= 0) {
    errors.fileSize = "fileSize must be a positive integer";
  }
  return Object.keys(errors).length > 0
    ? { valid: false, errors }
    : { valid: true, data: { contentType: contentType.trim(), fileSize } };
}

function validateDownloadPayload(body) {
  if (!body || typeof body !== "object" || typeof body.key !== "string" || !body.key.trim()) {
    return { valid: false, errors: { key: "key is required" } };
  }
  return { valid: true, data: { key: body.key.trim() } };
}

export async function handlePresignUpload(req, res) {
  const result = validateUploadPayload(req.body);
  if (!result.valid) {
    return res.status(400).json({ error: "Invalid request", details: result.errors });
  }

  const { contentType, fileSize } = result.data;

  if (!allowedTypes.has(contentType)) {
    return res.status(400).json({ error: "Unsupported content type" });
  }

  if (Number.isNaN(MAX_BYTES) || MAX_BYTES <= 0) {
    return res.status(500).json({ error: "Upload limit is not configured" });
  }

  if (fileSize > MAX_BYTES) {
    return res.status(413).json({ error: "File too large" });
  }

  const userId = getAuthenticatedUserId(req);
  const extension = contentType.includes("/") ? contentType.split("/")[1] : undefined;
  const key = makeKey({ userId, extension });

  const url = await getPresignedPutUrl({ key, contentType });

  return res.json({ url, key });
}

export async function handlePresignDownload(req, res) {
  const result = validateDownloadPayload(req.body);
  if (!result.valid) {
    return res.status(400).json({ error: "Invalid request", details: result.errors });
  }

  const { key } = result.data;
  const userId = getAuthenticatedUserId(req);
  const userPrefix = `${prefix}/${userId}/`;

  if (!key.startsWith(userPrefix)) {
    return res.status(403).json({ error: "Forbidden" });
  }

  const url = await getPresignedGetUrl({ key });

  return res.json({ url });
}

function asyncHandler(handler) {
  return async (req, res, next) => {
    try {
      await handler(req, res, next);
    } catch (err) {
      next(err);
    }
  };
}

router.post("/presign/upload", limiter, asyncHandler(handlePresignUpload));
router.post("/presign/download", limiter, asyncHandler(handlePresignDownload));

export default router;
