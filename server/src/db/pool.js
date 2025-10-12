import mysql from "mysql2/promise";

const config = {
  host: process.env.MYSQL_HOST || "127.0.0.1",
  port: Number.parseInt(process.env.MYSQL_PORT || "3306", 10),
  user: process.env.MYSQL_USER || "app",
  password: process.env.MYSQL_PASSWORD || "changeme",
  database: process.env.MYSQL_DATABASE || "app_core",
  waitForConnections: true,
  connectionLimit: Number.parseInt(process.env.MYSQL_POOL_MAX || "10", 10),
  minConnections: Number.parseInt(process.env.MYSQL_POOL_MIN || "0", 10),
};

export const pool = mysql.createPool(config);

export async function closePool() {
  await pool.end();
}
