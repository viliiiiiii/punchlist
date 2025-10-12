#!/usr/bin/env node
import "dotenv/config";
import fs from "node:fs";
import path from "node:path";
import mime from "mime";
import { minio, bucket, prefix } from "../src/lib/storage.js";

function usage() {
  console.log("Usage: node migrate-local-uploads-to-minio.js --from-dir <path> [--dry-run]");
}

function parseArgs() {
  const args = { dryRun: false };
  for (let i = 2; i < process.argv.length; i += 1) {
    const arg = process.argv[i];
    if (arg === "--from-dir") {
      args.fromDir = process.argv[++i];
    } else if (arg === "--dry-run") {
      args.dryRun = true;
    }
  }
  return args;
}

async function uploadFile(filePath, key) {
  if (fs.statSync(filePath).isDirectory()) return { skipped: true };
  if (!bucket) throw new Error("MINIO_BUCKET is not configured");
  const contentType = mime.getType(filePath) || "application/octet-stream";
  const readStream = fs.createReadStream(filePath);
  const normalizedKey = key.startsWith("/") ? key.slice(1) : key;
  await minio.putObject(bucket, normalizedKey, readStream, undefined, { "Content-Type": contentType });
  return { uploaded: true, contentType };
}

async function main() {
  const args = parseArgs();
  if (!args.fromDir) {
    usage();
    process.exit(1);
    return;
  }
  const entries = [];
  function walk(dir) {
    const list = fs.readdirSync(dir);
    list.forEach((entry) => {
      const full = path.join(dir, entry);
      if (fs.statSync(full).isDirectory()) {
        walk(full);
      } else {
        entries.push(full);
      }
    });
  }
  walk(args.fromDir);
  console.log(`Found ${entries.length} files to migrate.`);
  console.log("local_path,key,bytes,status");
  for (const filePath of entries) {
    const rel = path.relative(args.fromDir, filePath);
    const key = `${prefix}/${rel.replace(/\\/g, "/")}`;
    const bytes = fs.statSync(filePath).size;
    if (args.dryRun) {
      console.log(`${filePath},${key},${bytes},dry-run`);
      continue;
    }
    try {
      const result = await uploadFile(filePath, key);
      console.log(`${filePath},${key},${bytes},${result.uploaded ? "uploaded" : "skipped"}`);
    } catch (err) {
      console.error(`${filePath},${key},${bytes},error:${err.message}`);
    }
  }
}

if (import.meta.url === `file://${process.argv[1]}`) {
  main().catch((err) => {
    console.error("Migration failed", err);
    process.exitCode = 1;
  });
}
