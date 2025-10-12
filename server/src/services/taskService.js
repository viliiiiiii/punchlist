import { ulid } from "ulid";
import { pool } from "../db/pool.js";
import { publishTask } from "../lib/queue.js";

const defaultMaxAttempts = 3;

function parsePayload(payloadJson) {
  if (payloadJson == null) return {};
  if (typeof payloadJson === "object") return payloadJson;
  try {
    return JSON.parse(payloadJson);
  } catch (err) {
    console.warn("Failed to parse payload JSON", err);
    return {};
  }
}

export async function createTask(input, deps = { pool, publishTask }) {
  const { type, payload = {}, idempotencyKey = null } = input;
  const conn = await deps.pool.getConnection();
  try {
    await conn.beginTransaction();
    if (idempotencyKey) {
      const [existing] = await conn.execute(
        "SELECT id FROM tasks WHERE type = ? AND idempotency_key = ?",
        [type, idempotencyKey]
      );
      if (existing.length > 0) {
        await conn.commit();
        return { taskId: existing[0].id, created: false };
      }
    }
    const taskId = ulid();
    await conn.execute(
      "INSERT INTO tasks (id, type, payload_json, status, max_attempts, idempotency_key) VALUES (?, ?, ?, 'queued', ?, ?)",
      [taskId, type, JSON.stringify(payload ?? {}), defaultMaxAttempts, idempotencyKey]
    );
    await conn.commit();
    await deps.publishTask({ taskId, type, payload });
    return { taskId, created: true };
  } catch (err) {
    await conn.rollback();
    throw err;
  } finally {
    conn.release();
  }
}

export async function getTaskWithLatestRun(taskId, deps = { pool }) {
  const [tasks] = await deps.pool.execute(
    "SELECT id, type, payload_json, status, attempts, max_attempts, idempotency_key, scheduled, schedule_ref, cancel_requested, created_at, updated_at, last_error FROM tasks WHERE id = ?",
    [taskId]
  );
  if (tasks.length === 0) {
    return null;
  }
  const task = tasks[0];
  const [runs] = await deps.pool.execute(
    "SELECT id, run_started, run_finished, result, error FROM task_runs WHERE task_id = ? ORDER BY run_started DESC LIMIT 1",
    [taskId]
  );
  const payload = parsePayload(task.payload_json);
  return {
    id: task.id,
    type: task.type,
    payload,
    status: task.status,
    attempts: task.attempts,
    maxAttempts: task.max_attempts,
    idempotencyKey: task.idempotency_key,
    scheduled: Boolean(task.scheduled),
    scheduleRef: task.schedule_ref,
    cancelRequested: Boolean(task.cancel_requested),
    createdAt: task.created_at,
    updatedAt: task.updated_at,
    lastError: task.last_error,
    latestRun: runs.length
      ? {
          id: runs[0].id,
          runStarted: runs[0].run_started,
          runFinished: runs[0].run_finished,
          result: runs[0].result,
          error: runs[0].error,
        }
      : null,
  };
}

export async function markCancelRequested(taskId, deps = { pool }) {
  const [result] = await deps.pool.execute("UPDATE tasks SET cancel_requested = 1 WHERE id = ?", [taskId]);
  return result.affectedRows > 0;
}

export async function loadTaskForWorker(taskId, deps = { pool }) {
  const [rows] = await deps.pool.execute(
    "SELECT id, type, payload_json, status, attempts, max_attempts, cancel_requested FROM tasks WHERE id = ?",
    [taskId]
  );
  if (rows.length === 0) return null;
  const task = rows[0];
  return {
    id: task.id,
    type: task.type,
    payload: parsePayload(task.payload_json),
    status: task.status,
    attempts: task.attempts,
    maxAttempts: task.max_attempts,
    cancelRequested: Boolean(task.cancel_requested),
  };
}

export async function startTaskRun(taskId, deps = { pool }) {
  const conn = await deps.pool.getConnection();
  try {
    await conn.beginTransaction();
    const [rows] = await conn.execute(
      "SELECT id, type, payload_json, attempts, max_attempts, cancel_requested FROM tasks WHERE id = ? FOR UPDATE",
      [taskId]
    );
    if (rows.length === 0) {
      await conn.rollback();
      return { notFound: true };
    }
    const row = rows[0];
    if (row.cancel_requested) {
      await conn.execute("UPDATE tasks SET status = 'canceled', cancel_requested = 0 WHERE id = ?", [taskId]);
      await conn.commit();
      return { canceled: true };
    }
    await conn.execute("UPDATE tasks SET status = 'running', attempts = attempts + 1 WHERE id = ?", [taskId]);
    const [result] = await conn.execute("INSERT INTO task_runs (task_id) VALUES (?)", [taskId]);
    await conn.commit();
    return {
      runId: result.insertId,
      task: {
        id: row.id,
        type: row.type,
        payload: parsePayload(row.payload_json),
        attempts: row.attempts + 1,
        maxAttempts: row.max_attempts,
      },
    };
  } catch (err) {
    await conn.rollback();
    throw err;
  } finally {
    conn.release();
  }
}

export async function completeTaskSuccess(taskId, runId, deps = { pool }) {
  await deps.pool.execute("UPDATE tasks SET status = 'succeeded', last_error = NULL WHERE id = ?", [taskId]);
  await deps.pool.execute(
    "UPDATE task_runs SET run_finished = NOW(), result = 'succeeded', error = NULL WHERE id = ?",
    [runId]
  );
}

export async function completeTaskFailure(taskId, runId, errorMessage, attempts, maxAttempts, deps = { pool }) {
  const finalMessage = errorMessage?.toString().slice(0, 2000) || "Task failed";
  const isTerminal = attempts >= maxAttempts;
  const status = isTerminal ? "failed" : "queued";
  await deps.pool.execute("UPDATE tasks SET status = ?, last_error = ? WHERE id = ?", [status, finalMessage, taskId]);
  await deps.pool.execute(
    "UPDATE task_runs SET run_finished = NOW(), result = 'failed', error = ? WHERE id = ?",
    [finalMessage, runId]
  );
  return { terminal: isTerminal };
}
