#!/usr/bin/env node
import "dotenv/config";
import fs from "node:fs";
import path from "node:path";
import { ulid } from "ulid";
import { pool } from "../src/db/pool.js";
import { publishTask } from "../src/lib/queue.js";

function usage() {
  console.log("Usage: node migrate-local-tasks-to-mysql.js --from-file <path> [--enqueue]");
}

function parseArgs() {
  const args = { enqueue: false };
  for (let i = 2; i < process.argv.length; i += 1) {
    const arg = process.argv[i];
    if (arg === "--from-file") {
      args.fromFile = process.argv[++i];
    } else if (arg === "--enqueue") {
      args.enqueue = true;
    }
  }
  return args;
}

async function insertTask(conn, raw) {
  const id = raw.id || ulid();
  const type = raw.type || "unknown";
  const payload = raw.payload || {};
  const status = raw.status || "queued";
  const maxAttempts = raw.maxAttempts || 3;
  const idempotencyKey = raw.idempotencyKey || null;
  const scheduled = raw.scheduled ? 1 : 0;
  const scheduleRef = raw.scheduleRef || null;
  const cancelRequested = raw.cancelRequested ? 1 : 0;
  const attempts = raw.attempts || 0;
  const lastError = raw.lastError || null;
  await conn.execute(
    "INSERT INTO tasks (id, type, payload_json, status, attempts, max_attempts, idempotency_key, scheduled, schedule_ref, cancel_requested, last_error) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
    [
      id,
      type,
      JSON.stringify(payload),
      status,
      attempts,
      maxAttempts,
      idempotencyKey,
      scheduled,
      scheduleRef,
      cancelRequested,
      lastError,
    ]
  );
  return { id, type, payload };
}

async function main() {
  const args = parseArgs();
  if (!args.fromFile) {
    usage();
    process.exit(1);
    return;
  }
  const fullPath = path.resolve(process.cwd(), args.fromFile);
  const raw = JSON.parse(fs.readFileSync(fullPath, "utf8"));
  const tasks = Array.isArray(raw) ? raw : raw.tasks || [];
  const conn = await pool.getConnection();
  try {
    await conn.beginTransaction();
    let success = 0;
    for (const task of tasks) {
      try {
        await insertTask(conn, task);
        success += 1;
      } catch (err) {
        console.error("Failed to import task", task?.id, err.message);
      }
    }
    await conn.commit();
    console.log(`Imported ${success} tasks.`);
    if (args.enqueue) {
      for (const task of tasks) {
        try {
          const id = task.id || ulid();
          await publishTask({ taskId: id, type: task.type || "unknown", payload: task.payload || {} });
        } catch (err) {
          console.error("Failed to enqueue task", task?.id, err.message);
        }
      }
    }
  } finally {
    conn.release();
  }
}

if (import.meta.url === `file://${process.argv[1]}`) {
  main().catch((err) => {
    console.error("Migration failed", err);
    process.exitCode = 1;
  });
}
