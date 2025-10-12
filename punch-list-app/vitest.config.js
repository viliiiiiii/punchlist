import { defineConfig } from 'vitest/config';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';
import react from '@vitejs/plugin-react';

const __filename = fileURLToPath(import.meta.url);
const __dirname = dirname(__filename);

export default defineConfig({
  plugins: [react()],
  resolve: {
    alias: {
      express: resolve(__dirname, '../server/test-stubs/express.js'),
      cors: resolve(__dirname, '../server/test-stubs/noop-middleware.js'),
      'express-rate-limit': resolve(__dirname, '../server/test-stubs/noop-rate-limit.js'),
      ulid: resolve(__dirname, '../server/test-stubs/ulid.js'),
      'dotenv/config': resolve(__dirname, '../server/test-stubs/dotenv-config.js'),
      'mysql2/promise': resolve(__dirname, '../server/test-stubs/mysql2-promise.js'),
      amqplib: resolve(__dirname, '../server/test-stubs/amqplib.js'),
    },
  },
  test: {
    environment: 'node',
    include: [
      'src/**/*.{test,spec}.?(c|m)[jt]s?(x)',
      '../server/__tests__/**/*.{test,spec}.?(c|m)[jt]s?(x)'
    ],
  },
});
