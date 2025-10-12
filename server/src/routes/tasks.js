import { Router } from "express";
import rateLimit from "express-rate-limit";
import { createTask, getTaskWithLatestRun, markCancelRequested } from "../services/taskService.js";

const router = Router();

const limiter = rateLimit({
  windowMs: 60_000,
  limit: 10,
  standardHeaders: "draft-7",
  legacyHeaders: false,
});

function parseCreateBody(body) {
  if (!body || typeof body !== "object") {
    return { valid: false, errors: { message: "Body must be an object" } };
  }
  const errors = {};
  if (typeof body.type !== "string" || !body.type.trim()) {
    errors.type = "type is required";
  }
  if ("payload" in body && (body.payload === null || typeof body.payload !== "object" || Array.isArray(body.payload))) {
    errors.payload = "payload must be an object";
  }
  if (
    "idempotencyKey" in body &&
    (typeof body.idempotencyKey !== "string" || !body.idempotencyKey.trim())
  ) {
    errors.idempotencyKey = "idempotencyKey must be a string";
  }
  if (Object.keys(errors).length > 0) {
    return { valid: false, errors };
  }
  return {
    valid: true,
    data: {
      type: body.type.trim(),
      payload: body.payload && typeof body.payload === "object" ? body.payload : {},
      idempotencyKey:
        typeof body.idempotencyKey === "string" && body.idempotencyKey.trim()
          ? body.idempotencyKey.trim()
          : undefined,
    },
  };
}

export async function handleCreateTask(req, res) {
  const parsed = parseCreateBody(req.body);
  if (!parsed.valid) {
    return res.status(400).json({ error: "Invalid request", details: parsed.errors });
  }

  const result = await createTask(parsed.data);

  return res.status(result.created ? 201 : 200).json({ taskId: result.taskId, created: result.created });
}

export async function handleGetTask(req, res) {
  const { id } = req.params || {};
  if (!id || id.length !== 26) {
    return res.status(400).json({ error: "Invalid task id" });
  }

  const task = await getTaskWithLatestRun(id);
  if (!task) {
    return res.status(404).json({ error: "Task not found" });
  }

  return res.json(task);
}

export async function handleCancelTask(req, res) {
  const { id } = req.params || {};
  if (!id || id.length !== 26) {
    return res.status(400).json({ error: "Invalid task id" });
  }

  await markCancelRequested(id);
  return res.json({ taskId: id, cancelRequested: true });
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

router.post("/tasks", limiter, asyncHandler(handleCreateTask));
router.get("/tasks/:id", limiter, asyncHandler(handleGetTask));
router.post("/tasks/:id/cancel", limiter, asyncHandler(handleCancelTask));

export default router;
