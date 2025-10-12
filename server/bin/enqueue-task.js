#!/usr/bin/env node
import "dotenv/config";
import { publishTask } from "../src/lib/queue.js";
import { ulid } from "ulid";
import fs from "node:fs";

function parseArgs(argv) {
  const args = { payload: "{}" };
  for (let i = 2; i < argv.length; i += 1) {
    const arg = argv[i];
    if (arg === "--type" || arg === "-t") {
      args.type = argv[++i];
    } else if (arg === "--payload" || arg === "-p") {
      args.payload = argv[++i];
    } else if (arg === "--payload-file") {
      const filePath = argv[++i];
      args.payload = fs.readFileSync(filePath, "utf8");
    }
  }
  return args;
}

async function main() {
  const args = parseArgs(process.argv);
  if (!args.type) {
    console.error("Usage: enqueue-task --type <task.type> [--payload '{""foo"":1}']");
    process.exit(1);
    return;
  }

  let payload;
  try {
    payload = JSON.parse(args.payload || "{}");
  } catch (err) {
    console.error("Payload must be valid JSON", err.message);
    process.exit(1);
    return;
  }

  const taskId = ulid();
  await publishTask({ taskId, type: args.type, payload });
  console.log(`Enqueued task ${taskId} (${args.type})`);
}

if (import.meta.url === `file://${process.argv[1]}`) {
  main().catch((err) => {
    console.error("Failed to enqueue task", err);
    process.exitCode = 1;
  });
}
