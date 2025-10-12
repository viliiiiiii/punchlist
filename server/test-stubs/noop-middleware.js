export default function noopMiddleware() {
  return (_req, _res, next) => next();
}
