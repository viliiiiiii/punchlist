import request from "supertest";
import { describe, expect, it, vi } from "vitest";

vi.mock("@aws-sdk/s3-request-presigner", () => ({
  getSignedUrl: vi.fn(async () => "https://example.com/presigned"),
}));

process.env.AWS_REGION = process.env.AWS_REGION || "us-east-1";
process.env.S3_BUCKET = "test-bucket";
process.env.ALLOWED_IMAGE_TYPES = "image/jpeg,image/png";
process.env.MAX_UPLOAD_BYTES = "5242880";

const { app } = await import("../src/server.js");

describe("presign upload", () => {
  it("rejects unsupported content type", async () => {
    const response = await request(app)
      .post("/presign/upload")
      .send({ contentType: "image/gif", fileSize: 123 });

    expect(response.status).toBe(400);
    expect(response.body.error).toBe("Unsupported content type");
  });

  it("rejects files that exceed the size limit", async () => {
    const response = await request(app)
      .post("/presign/upload")
      .send({ contentType: "image/png", fileSize: 6_000_000 });

    expect(response.status).toBe(413);
    expect(response.body.error).toBe("File too large");
  });

  it("returns a URL and key for valid requests", async () => {
    const response = await request(app)
      .post("/presign/upload")
      .send({ contentType: "image/png", fileSize: 1024 });

    expect(response.status).toBe(200);
    expect(response.body).toHaveProperty("url", "https://example.com/presigned");
    expect(response.body.key).toMatch(/^uploads\/demo-user\//);
  });
});

describe("presign download", () => {
  it("rejects access to another user's key", async () => {
    const response = await request(app)
      .post("/presign/download")
      .send({ key: "uploads/other-user/file" });

    expect(response.status).toBe(403);
    expect(response.body.error).toBe("Forbidden");
  });
});
