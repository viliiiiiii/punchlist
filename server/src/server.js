import "dotenv/config";
import express from "express";
import cors from "cors";
import rateLimit from "express-rate-limit";
import presignRouter from "./routes/presign.js";
import tasksRouter from "./routes/tasks.js";

const app = express();

app.use(
  cors({
    origin: process.env.ALLOWED_ORIGIN || "https://YOUR_FRONTEND_ORIGIN",
    methods: ["GET", "POST", "PUT"],
    exposedHeaders: ["ETag"],
  })
);

app.use(express.json({ limit: "1mb" }));

const globalLimiter = rateLimit({
  windowMs: 60_000,
  limit: 60,
  standardHeaders: "draft-7",
  legacyHeaders: false,
});

app.use(globalLimiter);

app.use(presignRouter);
app.use(tasksRouter);

app.get("/uploads/*", (_req, res) => {
  res.redirect(301, "/");
});

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
    console.log(`Server listening on port ${port}`);
  });
}

export { app };
