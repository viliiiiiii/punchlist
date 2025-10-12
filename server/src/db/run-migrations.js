#!/usr/bin/env node
import fs from "node:fs/promises";
import path from "node:path";
import { fileURLToPath } from "node:url";
import "dotenv/config";
import { pool, closePool } from "./pool.js";

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const migrationsDir = path.resolve(__dirname, "../../migrations");

async function run() {
  const files = (await fs.readdir(migrationsDir)).filter((file) => file.endsWith(".sql")).sort();
  for (const file of files) {
    const sql = await fs.readFile(path.join(migrationsDir, file), "utf8");
    if (!sql.trim()) continue;
    console.log(`\nRunning migration ${file}`);
    await pool.query(sql);
  }
  await closePool();
  console.log("\nMigrations complete.");
}

run().catch((err) => {
  console.error("Migration failed", err);
  process.exitCode = 1;
});
