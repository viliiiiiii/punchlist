export function getAuthenticatedUserId(req) {
  // TODO: replace with real auth integration
  return (req?.user && (req.user.id || req.user.sub)) || "demo-user";
}
