#!/usr/bin/env node
import "dotenv/config";
import { withQueueConsumer } from "../lib/queue.js";
import {
  startTaskRun,
  completeTaskSuccess,
  completeTaskFailure,
} from "../services/taskService.js";
import { getHandler } from "./task-handlers.js";

export async function processMessage(message, queueAck) {
  const { taskId, type, payload } = message || {};
  if (!taskId || !type) {
    console.warn("Received malformed message", message);
    queueAck.ack();
    return;
  }

  const startResult = await startTaskRun(taskId);
  if (startResult.notFound) {
    console.warn(`Task ${taskId} not found`);
    queueAck.ack();
    return;
  }

  if (startResult.canceled) {
    console.log(`Task ${taskId} canceled before execution`);
    queueAck.ack();
    return;
  }

  const { task, runId } = startResult;
  const handler = getHandler(type);
  if (!handler) {
    console.error(`No handler registered for task type ${type}`);
    await completeTaskFailure(taskId, runId, `Unknown task type: ${type}`, task.attempts, task.maxAttempts);
    queueAck.ack();
    return;
  }

  try {
    await handler(payload ?? task.payload ?? {});
    await completeTaskSuccess(taskId, runId);
    queueAck.ack();
  } catch (err) {
    console.error(`Task ${taskId} failed`, err);
    const failure = await completeTaskFailure(
      taskId,
      runId,
      err?.message || "Task failed",
      task.attempts,
      task.maxAttempts
    );
    if (failure.terminal) {
      queueAck.ack();
    } else {
      queueAck.nack({ requeue: true });
    }
  }
}

async function main() {
  console.log("Task worker starting...");
  await withQueueConsumer(async (message, ack) => {
    await processMessage(message, ack);
  });
}

if (import.meta.url === `file://${process.argv[1]}`) {
  main().catch((err) => {
    console.error("Worker crashed", err);
    process.exitCode = 1;
  });
}
