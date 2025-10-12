export default {
  connect: async () => ({
    createChannel: async () => ({
      assertExchange: async () => {},
      assertQueue: async () => {},
      bindQueue: async () => {},
      publish: () => {},
      prefetch: async () => {},
      consume: async (_queue, handler) => {
        handler(null);
      },
      close: async () => {},
    }),
    close: async () => {},
  }),
};
