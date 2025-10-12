import "dotenv/config";
import express from "express";
import cors from "cors";
import presignRouter from "./routes/presign.js";

const app = express();

app.use(
  cors({
    origin: process.env.ALLOWED_ORIGIN || "https://YOUR_ORIGIN_HERE",
    methods: ["GET", "POST", "PUT"],
    exposedHeaders: ["ETag"],
  })
);

app.use(express.json());
app.use(presignRouter);

// eslint-disable-next-line no-unused-vars
app.use((err, _req, res, _next) => {
  console.error(err);
  if (err?.status) {
    return res.status(err.status).json({ error: err.message || "Request failed" });
  }
  return res.status(500).json({ error: "Internal Server Error" });
});

const port = Number.parseInt(process.env.PORT || "3000", 10);

if (process.env.NODE_ENV !== "test") {
  app.listen(port, () => {
    console.log(`Presign server listening on port ${port}`);
  });
}

export { app };
