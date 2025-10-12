import { describe, expect, it, vi } from "vitest";
import { createTask } from "../src/services/taskService.js";

class MockConnection {
  constructor(state) {
    this.state = state;
  }

  async beginTransaction() {}

  async execute(sql, params) {
    if (sql.startsWith("SELECT id FROM tasks WHERE type")) {
      const [, type, key] = [null, params[0], params[1]];
      const found = this.state.tasks.find((task) => task.type === type && task.idempotencyKey === key);
      return [found ? [{ id: found.id }] : [], null];
    }
    if (sql.startsWith("INSERT INTO tasks")) {
      const [id, type, payload, , idempotencyKey] = params;
      this.state.tasks.push({ id, type, payload: JSON.parse(payload), idempotencyKey });
      return [{ insertId: id }, null];
    }
    throw new Error(`Unhandled SQL: ${sql}`);
  }

  async commit() {}

  async rollback() {}

  release() {}
}

describe("taskService createTask", () => {
  it("inserts new tasks and publishes messages", async () => {
    const state = { tasks: [] };
    const publishTask = vi.fn(async () => {});
    const pool = {
      getConnection: vi.fn(async () => new MockConnection(state)),
    };

    const result = await createTask(
      { type: "noop", payload: { foo: "bar" }, idempotencyKey: "abc" },
      { pool, publishTask }
    );

    expect(result.created).toBe(true);
    expect(state.tasks).toHaveLength(1);
    expect(publishTask).toHaveBeenCalledOnce();

    const repeat = await createTask(
      { type: "noop", payload: { foo: "bar" }, idempotencyKey: "abc" },
      { pool, publishTask }
    );
    expect(repeat.created).toBe(false);
    expect(state.tasks).toHaveLength(1);
    expect(publishTask).toHaveBeenCalledOnce();
  });
});
