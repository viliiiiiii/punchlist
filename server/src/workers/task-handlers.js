const handlers = {
  noop: async () => {},
  "image.process": async (payload) => {
    console.log("image.process stub", payload);
  },
  "email.send": async (payload) => {
    console.log("email.send stub", payload);
  },
};

export function getHandler(type) {
  return handlers[type] || null;
}

export function registerHandler(type, handler) {
  handlers[type] = handler;
}
