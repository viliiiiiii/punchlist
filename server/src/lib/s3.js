import { S3Client } from "@aws-sdk/client-s3";

const region = process.env.AWS_REGION || "REGION_HERE";
export const bucket = process.env.S3_BUCKET || "";

export const s3 = new S3Client({
  region,
});

if (!bucket) {
  console.warn("S3_BUCKET environment variable is not set. Presign routes will fail until it is configured.");
}
