import { beforeEach, describe, expect, it, vi } from "vitest";

const startTaskRun = vi.fn();
const completeTaskSuccess = vi.fn();
const completeTaskFailure = vi.fn();
const getHandlerMock = vi.fn();

vi.mock("../src/services/taskService.js", () => ({
  startTaskRun: startTaskRun,
  completeTaskSuccess: completeTaskSuccess,
  completeTaskFailure: completeTaskFailure,
}));

vi.mock("../src/workers/task-handlers.js", () => ({
  getHandler: getHandlerMock,
}));

const { processMessage } = await import("../src/workers/task-runner.js");

beforeEach(() => {
  startTaskRun.mockReset();
  completeTaskSuccess.mockReset();
  completeTaskFailure.mockReset();
  getHandlerMock.mockReset();
});

describe("worker processMessage", () => {
  it("acks malformed messages", async () => {
    const ack = vi.fn();
    const nack = vi.fn();
    await processMessage({}, { ack, nack });
    expect(ack).toHaveBeenCalled();
    expect(nack).not.toHaveBeenCalled();
  });

  it("acks canceled tasks", async () => {
    startTaskRun.mockResolvedValueOnce({ canceled: true });
    const ack = vi.fn();
    const nack = vi.fn();
    await processMessage({ taskId: "1", type: "noop" }, { ack, nack });
    expect(ack).toHaveBeenCalled();
  });

  it("executes handlers for running tasks", async () => {
    startTaskRun.mockResolvedValueOnce({
      task: { id: "1", type: "noop", attempts: 1, maxAttempts: 3 },
      runId: 42,
    });
    const handler = vi.fn(async () => {});
    getHandlerMock.mockReturnValueOnce(handler);
    const ack = vi.fn();
    const nack = vi.fn();
    await processMessage({ taskId: "1", type: "noop", payload: { foo: "bar" } }, { ack, nack });
    expect(handler).toHaveBeenCalledWith({ foo: "bar" });
    expect(completeTaskSuccess).toHaveBeenCalledWith("1", 42);
    expect(ack).toHaveBeenCalled();
    expect(nack).not.toHaveBeenCalled();
  });

  it("nacks on retryable failures", async () => {
    startTaskRun.mockResolvedValueOnce({
      task: { id: "1", type: "noop", attempts: 1, maxAttempts: 3 },
      runId: 99,
    });
    const handler = vi.fn(async () => {
      throw new Error("boom");
    });
    getHandlerMock.mockReturnValueOnce(handler);
    completeTaskFailure.mockResolvedValueOnce({ terminal: false });
    const ack = vi.fn();
    const nack = vi.fn();
    await processMessage({ taskId: "1", type: "noop" }, { ack, nack });
    expect(completeTaskFailure).toHaveBeenCalled();
    expect(nack).toHaveBeenCalledWith({ requeue: true });
    expect(ack).not.toHaveBeenCalled();
  });

  it("acks on terminal failures", async () => {
    startTaskRun.mockResolvedValueOnce({
      task: { id: "1", type: "noop", attempts: 3, maxAttempts: 3 },
      runId: 11,
    });
    const handler = vi.fn(async () => {
      throw new Error("boom");
    });
    getHandlerMock.mockReturnValueOnce(handler);
    completeTaskFailure.mockResolvedValueOnce({ terminal: true });
    const ack = vi.fn();
    const nack = vi.fn();
    await processMessage({ taskId: "1", type: "noop" }, { ack, nack });
    expect(completeTaskFailure).toHaveBeenCalled();
    expect(ack).toHaveBeenCalled();
    expect(nack).not.toHaveBeenCalled();
  });
});
