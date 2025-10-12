import { beforeAll, beforeEach, describe, expect, it, vi } from "vitest";

const authMock = vi.hoisted(() => ({
  getAuthenticatedUserId: vi.fn(() => "demo-user"),
}));

const storageMocks = vi.hoisted(() => ({
  makeKey: vi.fn(({ userId }) => `uploads/${userId}/abc123`),
  getPresignedPutUrl: vi.fn(async ({ key }) => `http://minio.local/${key}?put`),
  getPresignedGetUrl: vi.fn(async ({ key }) => `http://minio.local/${key}?get`),
  prefix: "uploads",
}));

const serviceMocks = vi.hoisted(() => ({
  createTask: vi.fn(async ({ idempotencyKey, ...rest }) => {
    if (idempotencyKey === "existing") {
      return { taskId: "01EXISTINGTASK00000000000000", created: false };
    }
    return { taskId: "01NEWNEWTASK000000000000000", created: true, details: rest };
  }),
  getTaskWithLatestRun: vi.fn(async (id) => {
    if (id === "01MISSINGTASK000000000000000") return null;
    return {
      id,
      type: "noop",
      payload: {},
      status: "queued",
      attempts: 0,
      maxAttempts: 3,
      idempotencyKey: null,
      scheduled: false,
      scheduleRef: null,
      cancelRequested: false,
      createdAt: new Date().toISOString(),
      updatedAt: new Date().toISOString(),
      lastError: null,
      latestRun: null,
    };
  }),
  markCancelRequested: vi.fn(async () => true),
}));

vi.mock("../src/lib/auth.js", () => authMock);
vi.mock("../src/lib/storage.js", () => storageMocks);
vi.mock("../src/services/taskService.js", () => serviceMocks);

let handlePresignUpload;
let handlePresignDownload;
let handleCreateTask;
let handleGetTask;
let handleCancelTask;

beforeAll(async () => {
  process.env.ALLOWED_IMAGE_TYPES = "image/jpeg,image/png,image/webp";
  process.env.MAX_UPLOAD_BYTES = "5242880";
  const presignModule = await import("../src/routes/presign.js");
  handlePresignUpload = presignModule.handlePresignUpload;
  handlePresignDownload = presignModule.handlePresignDownload;
  const taskModule = await import("../src/routes/tasks.js");
  handleCreateTask = taskModule.handleCreateTask;
  handleGetTask = taskModule.handleGetTask;
  handleCancelTask = taskModule.handleCancelTask;
});

function createMockRes() {
  return {
    statusCode: 200,
    body: undefined,
    status(code) {
      this.statusCode = code;
      return this;
    },
    json(payload) {
      this.body = payload;
      return this;
    },
  };
}

describe("presign handlers", () => {
  beforeEach(() => {
    storageMocks.makeKey.mockClear();
    storageMocks.getPresignedPutUrl.mockClear();
    storageMocks.getPresignedGetUrl.mockClear();
  });

  it("rejects unsupported MIME types", async () => {
    const req = { body: { contentType: "image/gif", fileSize: 123 } };
    const res = createMockRes();
    await handlePresignUpload(req, res);
    expect(res.statusCode).toBe(400);
    expect(res.body.error).toBe("Unsupported content type");
  });

  it("rejects files over the size limit", async () => {
    const req = { body: { contentType: "image/png", fileSize: 6_000_000 } };
    const res = createMockRes();
    await handlePresignUpload(req, res);
    expect(res.statusCode).toBe(413);
    expect(res.body.error).toBe("File too large");
  });

  it("returns a presigned URL and key for valid uploads", async () => {
    const req = { body: { contentType: "image/png", fileSize: 1024 } };
    const res = createMockRes();
    await handlePresignUpload(req, res);
    expect(res.statusCode).toBe(200);
    expect(res.body.url).toMatch(/minio\.local/);
    expect(res.body.key).toMatch(/^uploads\/demo-user\//);
    expect(storageMocks.makeKey).toHaveBeenCalledWith({ userId: "demo-user", extension: "png" });
  });

  it("blocks download attempts for other users", async () => {
    const req = { body: { key: "uploads/other-user/file" } };
    const res = createMockRes();
    await handlePresignDownload(req, res);
    expect(res.statusCode).toBe(403);
    expect(res.body.error).toBe("Forbidden");
  });
});

describe("task handlers", () => {
  beforeEach(() => {
    serviceMocks.createTask.mockClear();
    serviceMocks.getTaskWithLatestRun.mockClear();
    serviceMocks.markCancelRequested.mockClear();
  });

  it("creates new tasks and publishes to the queue", async () => {
    const req = { body: { type: "noop", payload: { foo: "bar" } } };
    const res = createMockRes();
    await handleCreateTask(req, res);
    expect(res.statusCode).toBe(201);
    expect(res.body.created).toBe(true);
    expect(serviceMocks.createTask).toHaveBeenCalledWith({ type: "noop", payload: { foo: "bar" } });
  });

  it("returns existing task when idempotency key matches", async () => {
    const req = { body: { type: "noop", payload: {}, idempotencyKey: "existing" } };
    const res = createMockRes();
    await handleCreateTask(req, res);
    expect(res.statusCode).toBe(200);
    expect(res.body.created).toBe(false);
    expect(res.body.taskId).toBe("01EXISTINGTASK00000000000000");
  });

  it("returns task details", async () => {
    const req = { params: { id: "01TASKTASKTASKTASKTASK0000" } };
    const res = createMockRes();
    await handleGetTask(req, res);
    expect(res.statusCode).toBe(200);
    expect(res.body.id).toBe("01TASKTASKTASKTASKTASK0000");
  });

  it("returns 404 for missing tasks", async () => {
    const req = { params: { id: "01MISSINGTASK000000000000000" } };
    const res = createMockRes();
    await handleGetTask(req, res);
    expect(res.statusCode).toBe(404);
  });

  it("flags cancel requests", async () => {
    const req = { params: { id: "01TASKTASKTASKTASKTASK0000" } };
    const res = createMockRes();
    await handleCancelTask(req, res);
    expect(res.statusCode).toBe(200);
    expect(res.body.cancelRequested).toBe(true);
    expect(serviceMocks.markCancelRequested).toHaveBeenCalled();
  });
});
