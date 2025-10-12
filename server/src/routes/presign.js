import { Router } from "express";
import rateLimit from "express-rate-limit";
import { v4 as uuid } from "uuid";
import { z } from "zod";
import { PutObjectCommand, GetObjectCommand } from "@aws-sdk/client-s3";
import { getSignedUrl } from "@aws-sdk/s3-request-presigner";
import { s3, bucket } from "../lib/s3.js";
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

const uploadSchema = z
  .object({
    contentType: z.string().min(1, "contentType is required"),
    fileSize: z.number({ invalid_type_error: "fileSize must be a number" }).int().positive("fileSize must be positive"),
  })
  .strict();

const downloadSchema = z
  .object({
    key: z.string().min(1, "key is required"),
  })
  .strict();

function asyncHandler(handler) {
  return async (req, res, next) => {
    try {
      await handler(req, res, next);
    } catch (err) {
      next(err);
    }
  };
}

router.post(
  "/presign/upload",
  limiter,
  asyncHandler(async (req, res) => {
    const parsed = uploadSchema.safeParse(req.body ?? {});
    if (!parsed.success) {
      return res.status(400).json({ error: "Invalid request", details: parsed.error.flatten() });
    }

    const { contentType, fileSize } = parsed.data;

    if (!allowedTypes.has(contentType)) {
      return res.status(400).json({ error: "Unsupported content type" });
    }

    if (Number.isNaN(MAX_BYTES) || MAX_BYTES <= 0) {
      return res.status(500).json({ error: "Upload limit is not configured" });
    }

    if (fileSize > MAX_BYTES) {
      return res.status(413).json({ error: "File too large" });
    }

    if (!bucket) {
      return res.status(500).json({ error: "Storage bucket is not configured" });
    }

    const userId = getAuthenticatedUserId(req);
    const key = `uploads/${userId}/${uuid()}`;

    const command = new PutObjectCommand({
      Bucket: bucket,
      Key: key,
      ContentType: contentType,
      ServerSideEncryption: "AES256",
    });

    const url = await getSignedUrl(s3, command, { expiresIn: 60 });

    return res.json({ url, key });
  })
);

router.post(
  "/presign/download",
  limiter,
  asyncHandler(async (req, res) => {
    const parsed = downloadSchema.safeParse(req.body ?? {});
    if (!parsed.success) {
      return res.status(400).json({ error: "Invalid request", details: parsed.error.flatten() });
    }

    if (!bucket) {
      return res.status(500).json({ error: "Storage bucket is not configured" });
    }

    const { key } = parsed.data;
    const userId = getAuthenticatedUserId(req);
    const userPrefix = `uploads/${userId}/`;

    if (!key.startsWith(userPrefix)) {
      return res.status(403).json({ error: "Forbidden" });
    }

    const command = new GetObjectCommand({
      Bucket: bucket,
      Key: key,
      ResponseContentType: undefined,
    });

    const url = await getSignedUrl(s3, command, { expiresIn: 300 });

    return res.json({ url });
  })
);

export default router;
